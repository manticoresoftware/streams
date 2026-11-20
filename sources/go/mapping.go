package main

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync/atomic"
	"unicode"
	"unicode/utf8"

	"github.com/ohler55/ojg/jp"
)

type mappingRule struct {
	target   string
	isURL    bool
	hasType  bool
	paths    []mappingPath
}

type mappingPath struct {
	advanced jp.Expr
	multi bool
	indefiniteAsScalar bool
	keys        []string
	wildcard    int
	placeholder string
	from        []string
}

type mapper struct {
	rules                  []mappingRule
	output                 string
	reference              *referenceProcess
	referenceCalls         atomic.Uint64
	needsReference         bool
	passThroughWithoutRule bool
}

var simpleKey = regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
// Jayway returns collections for filter, slice and union selectors. When the
// rule lacks [*], Java tries to assign them to String and skips that value.
var javaCollectionSelector = regexp.MustCompile(`\[\?\(|\[(?:[0-9]*:[0-9]*|[0-9]+(?:,[0-9]+)+)\]`)

// Jayway accepts Unicode letters in unquoted JSONPath keys, even though
// the simple compile-time mapping grammar uses ASCII keys. Keep JSONPath
// metacharacters on the compatibility path.
func nativeDynamicPathSegment(value string) bool {
	if value == "" {
		return false
	}
	for _, r := range value {
		if r == '_' || r == '-' || unicode.IsLetter(r) || unicode.IsNumber(r) || unicode.IsMark(r) {
			continue
		}
		return false
	}
	return true
}

// Compile the common mapping grammar once. Custom JSONPath expressions use the
// installed Jayway implementation so filters, functions and quoted keys keep
// their original behavior.
func compileMappingPath(text string) (mappingPath, bool) {
	p := mappingPath{wildcard: -1}
	open := strings.IndexByte(text, '{')
	if open >= 0 {
		end := strings.IndexByte(text[open:], '}')
		if end <= 1 {
			return p, false
		}
		token := text[open : open+end+1]
		p.from = strings.Split(token[1:len(token)-1], ".")
		for _, k := range p.from {
			if !simpleKey.MatchString(k) {
				return p, false
			}
		}
		p.placeholder = token
		text = strings.ReplaceAll(text, token, "{}")
	}
	p.keys = strings.Split(text, ".")
	for i, k := range p.keys {
		if strings.HasSuffix(k, "[*]") {
			if p.wildcard >= 0 {
				return p, false
			}
			k = strings.TrimSuffix(k, "[*]")
			p.keys[i], p.wildcard = k, i
		}
		if !simpleKey.MatchString(k) && !(k == "{}" && p.placeholder != "") {
			return p, false
		}
	}
	return p, true
}

// JsonTransformer asks Jayway for String even when ".." returns a
// JSONArray. Java catches the resulting ClassCastException and leaves that
// selection empty. The subset below is safe to skip without Java or an
// actual recursive search. Other descendant syntaxes keep the fallback.
func compileSkippedDescendantPath(input string) (mappingPath, bool) {
	p := mappingPath{wildcard: -1, indefiniteAsScalar: true}
	if strings.ContainsAny(input, "[]{}") {
		return p, false
	}
	if strings.HasPrefix(input, ".") && !strings.HasPrefix(input, "..") {
		p.keys = strings.Split(input, ".")
		if len(p.keys) == 2 && simpleKey.MatchString(p.keys[1]) {
			return p, true
		}
		return p, false
	}
	if strings.Count(input, "..") != 1 || strings.Contains(input, "...") ||
		strings.HasPrefix(input, "..") || strings.HasSuffix(input, "..") {
		return p, false
	}
	p.keys = strings.Split(input, ".")
	for i, key := range p.keys {
		if key == "" && i > 0 && i < len(p.keys)-1 {
			continue
		}
		if !simpleKey.MatchString(key) {
			return p, false
		}
	}
	return p, true
}

func newMapper(rules []string, fields map[string]string, output string) *mapper {
	cfg, _ := json.Marshal(struct {
		Rules  []string          `json:"rules"`
		Fields map[string]string `json:"fields"`
		Output string            `json:"output"`
	}{rules, fields, output})
	m := &mapper{output: output, reference: javaReference("map", string(cfg)), passThroughWithoutRule: rules == nil}
	for _, rule := range rules {
		parts := strings.Split(rule, "=>")
		if len(parts) != 2 || strings.TrimSpace(parts[1]) == "" {
			continue
		}
		target := strings.TrimSpace(parts[1])
		_, hasType := fields[target]
		r := mappingRule{target: target, isURL: fields[target] == "url", hasType: hasType}
		for _, input := range strings.Split(strings.TrimSpace(parts[0]), "&&") {
			p, ok := compileMappingPath(input)
			if !ok && !strings.ContainsAny(input, "{}") &&
				!strings.Contains(input, "..") &&
				(fields[target] != "url" || (!strings.Contains(input, "[*]") && !strings.Contains(input, "[?("))) {
				// Evaluate advanced paths in Go, preserving the mapping rules as
				// configured in the Java worker (relative paths use $. prefix).
				path := strings.TrimSpace(input)
				if strings.HasPrefix(path, "[") {
					path = "$" + path
				} else {
					path = "$." + path
				}
				if expression, err := jp.ParseString(path); err == nil {
					p.advanced, p.multi, ok = expression, strings.Contains(input, "[*]"), true
					p.indefiniteAsScalar = !p.multi && javaCollectionSelector.MatchString(input)
				}
			}
			// "$." + ".name" is "$..name": an indefinite Jayway
			// descendant selection even when ojg successfully parses it.
			if fields[target] != "url" && (!ok || strings.HasPrefix(input, ".")) {
				if recursive, skipped := compileSkippedDescendantPath(input); skipped {
					p, ok = recursive, true
				}
			}
			if !ok {
				m.needsReference = true
			}
			r.paths = append(r.paths, p)
		}
		m.rules = append(m.rules, r)
	}
	return m
}

func (m *mapper) requireNativeSupport() error {
	if os.Getenv("STREAMS_NATIVE_ONLY") == "1" && m.needsReference {
		return fmt.Errorf("configured JSON mapping requires the Java compatibility bridge; use the hybrid worker image")
	}
	return nil
}

func (m *mapper) referenceMap(raw string) (any, string, error) {
	m.referenceCalls.Add(1)
	if os.Getenv("STREAMS_NATIVE_ONLY") == "1" {
		return nil, "", fmt.Errorf("document requires unsupported Java mapping compatibility; use the hybrid worker image")
	}
	data, err := m.reference.call(raw)
	if err != nil {
		return nil, "", err
	}
	var pair struct {
		Mapped json.RawMessage `json:"mapped"`
		Output string          `json:"output"`
	}
	if err := json.Unmarshal([]byte(data), &pair); err != nil {
		return nil, "", err
	}
	return pair.Mapped, pair.Output, nil
}

func lookupMappedPath(value any, keys []string) (any, bool) {
	for _, key := range keys {
		obj, ok := value.(map[string]any)
		if !ok {
			return nil, false
		}
		value, ok = obj[key]
		if !ok {
			return nil, false
		}
	}
	return value, true
}

func sourceCopy(obj map[string]any) map[string]any {
	out := make(map[string]any, len(obj))
	for k, v := range obj {
		out[k] = sourceCopyValue(v)
	}
	return out
}

func sourceCopyValue(v any) any {
	switch value := v.(type) {
	case map[string]any:
		return sourceCopy(value)
	case []any:
		items := make([]any, len(value))
		for i, item := range value {
			items[i] = sourceCopyValue(item)
		}
		return items
	default:
		return value
	}
}

func deleteMappedPath(obj map[string]any, keys []string) {
	parent, _ := lookupMappedPath(obj, keys[:len(keys)-1])
	if p, ok := parent.(map[string]any); ok {
		delete(p, keys[len(keys)-1])
	}
}

// Delete only the selected nodes, including fields inside matching arrays.
func deleteMappedSelection(obj map[string]any, path mappingPath, keys []string) error {
	if path.advanced != nil {
		_, err := path.advanced.Remove(obj)
		return err
	}
	if path.wildcard < 0 {
		deleteMappedPath(obj, keys)
		return nil
	}
	expr := jp.R()
	for i, key := range keys {
		expr = expr.C(key)
		if i == path.wildcard {
			expr = expr.W()
		}
	}
	_, err := expr.Remove(obj)
	return err
}

// Original-field output deep-merges each selection, keeping the first
// leaf if several mappings select the same key.
func recordOriginal(obj map[string]any, keys []string, value string) {
	for _, key := range keys[:len(keys)-1] {
		child, exists := obj[key]
		if !exists {
			child = map[string]any{}
			obj[key] = child
		}
		var ok bool
		obj, ok = child.(map[string]any)
		if !ok {
			return
		}
	}
	last := keys[len(keys)-1]
	if _, exists := obj[last]; !exists {
		obj[last] = value
	}
}

// Java only accepts a String for definite paths. A wildcard joins the values
// in document order, including a trailing newline.
func javaWildcardText(value any) (string, bool) {
	switch v := value.(type) {
	case nil:
		return "null", true
	case string:
		return v, true
	case bool, int, int64:
		return fmt.Sprint(v), true
	case json.Number:
		return javaNumberText(v.String())
	default:
		// Read collections from the original JSON to retain key order.
		return "", false
	}
}

func selectMappedValue(root map[string]any, keys []string, wildcard int) (text string, found, compatible bool) {
	if wildcard < 0 {
		v, exists := lookupMappedPath(root, keys)
		if !exists {
			return "", false, true
		}
		if v == nil {
			return "null", true, true
		}
		s, ok := v.(string)
		return s, ok, true
	}
	v, exists := lookupMappedPath(root, keys[:wildcard+1])
	if !exists {
		return "", false, true
	}
	items, isArray := v.([]any)
	if !isArray {
		return "", false, false
	}
	var s strings.Builder
	for _, item := range items {
		v, ok := lookupMappedPath(item, keys[wildcard+1:])
		if !ok {
			continue
		}
		text, ok := javaWildcardText(v)
		if !ok {
			return "", false, false
		}
		s.WriteString(text)
		s.WriteByte('\n')
	}
	return s.String(), true, true
}

// selectAdvancedMappedValue reproduces the worker's Jayway-to-string mapping.
// A wildcard returns a newline-delimited list, while a definite path must
// return a string (or null). Unsupported result types use the Java oracle.
func selectAdvancedMappedValue(root map[string]any, path mappingPath) (string, bool, bool) {
	if path.indefiniteAsScalar {
		// Without [*] the legacy mapper catches ClassCastException from
		// Jayway's JSONArray result. It does not add a mapped value.
		return "", false, true
	}
	values := path.advanced.Get(root)
	if path.multi {
		var b strings.Builder
		for _, v := range values {
			text, ok := javaWildcardText(v)
			if !ok {
				return "", false, false
			}
			b.WriteString(text)
			b.WriteByte('\n')
		}
		return b.String(), true, true
	}
	if len(values) == 0 {
		return "", false, true
	}
	if len(values) != 1 {
		return "", false, false
	}
	if values[0] == nil {
		return "null", true, true
	}
	v, ok := values[0].(string)
	return v, ok, true
}

// JsonTransformer catches ClassCastException/NullPointerException around the
// entire mapping loop. On failure it returns the fields mapped *before* the
// exception; outputDocs is the original input in no-transform modes, the
// partial selection in original-field mode, or still empty in new-only mode.
func (m *mapper) abortMapping(raw string, mapped, output map[string]any) (any, string, error) {
	if m.output[2] == '1' {
		return mapped, raw, nil
	}
	encoded, err := json.Marshal(output)
	return mapped, string(encoded), err
}

func (m *mapper) transform(raw string) (any, string, error) {
	if m.needsReference {
		return m.referenceMap(raw)
	}
	if m.passThroughWithoutRule {
		return json.RawMessage(raw), "null", nil
	}
	var doc map[string]any
	decoder := json.NewDecoder(strings.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&doc); err != nil || doc == nil || strings.TrimSpace(raw[decoder.InputOffset():]) != "" {
		return m.referenceMap(raw)
	}
	mapped := map[string]any{}
	out := map[string]any{}
	rename := m.output[0] == '1' && m.output[2] == '1'
	original := m.output[1] == '1'
	if rename {
		out = sourceCopy(doc)
	}
	for _, rule := range m.rules {
		if _, exists := mapped[rule.target]; exists {
			continue
		}
		last := []string{""}
		var value string
		for _, path := range rule.paths {
			keys := path.keys
			if len(keys) == 1 && keys[0] == "whole_document" {
				mapped[rule.target] = doc
				continue
			}
			if path.placeholder != "" {
				v, exists := lookupMappedPath(doc, path.from)
				if !exists {
					continue
				}
				key, ok := v.(string)
				if !ok {
					// Java substitutes the string form of scalar selector values.
					switch v := v.(type) {
					case json.Number, bool:
						key = fmt.Sprint(v)
					case nil:
						// In Java, null.toString() aborts the mapping loop.
						return m.abortMapping(raw, mapped, out)
					default:
						return m.referenceMap(raw)
					}
				}
				// A dot in the substituted value is another path segment in
				// Jayway, not a literal key containing a dot.
				segments := strings.Split(key, ".")
				for _, segment := range segments {
					if !nativeDynamicPathSegment(segment) {
						return m.referenceMap(raw)
					}
				}
				resolved := strings.ReplaceAll(strings.Join(keys, "."), "{}", key)
				keys = strings.Split(resolved, ".")
			}
			// In Java, checkFieldType(...).equals("url") throws for a
			// missing field type, even when the selected JSON path is absent.
			// Preserve the already mapped fields without launching Java.
			if !rule.hasType {
				return m.abortMapping(raw, mapped, out)
			}
			if rule.isURL {
				var v any
				var exists bool
				if path.advanced != nil {
					// URL mapping requires a definite JSONPath that resolves
					// to one string. Indefinite paths keep Java semantics.
					values := path.advanced.Get(doc)
					if len(values) > 1 {
						return m.referenceMap(raw)
					}
					if len(values) == 1 {
						v, exists = values[0], true
					}
				} else {
					v, exists = lookupMappedPath(doc, keys)
				}
				if path.wildcard >= 0 {
					// The old URL branch reads a Jayway JSONArray into String,
					// causing ClassCastException. Unlike text rules, that
					// exception aborts the entire mapping loop. Missing paths
					// throw PathNotFoundException and processing continues.
					if _, present := lookupMappedPath(doc, keys[:path.wildcard+1]); present {
						if m.output[2] == '1' {
							return mapped, raw, nil
						}
						b, err := json.Marshal(out)
						return mapped, string(b), err
						}
					value = ""
					continue
				}
				if exists && v != nil {
					s, ok := v.(string)
					if !ok {
						// Java attempts to assign the JSONPath value to String.
						// A non-string aborts the current document's mapping loop.
						return m.abortMapping(raw, mapped, out)
					}
					if !mapURLs(s, rule.target, mapped) {
						return m.referenceMap(raw)
					}
				}
				value = ""
				continue
			}
			last = keys
			if original && path.wildcard >= 0 {
				last = append([]string(nil), keys...)
				last[path.wildcard] += "[*]"
			}
			var text string
			var found, compatible bool
			if path.indefiniteAsScalar {
				// Jayway produces an array that Java cannot assign to String.
				text, found, compatible = "", false, true
			} else if path.advanced != nil {
				text, found, compatible = selectAdvancedMappedValue(doc, path)
			} else {
				text, found, compatible = selectMappedValue(doc, keys, path.wildcard)
			}
			if !compatible && path.advanced == nil && path.wildcard >= 0 && path.placeholder == "" {
				text, found, compatible = selectRawWildcard(raw, keys, path.wildcard)
			}
			if !compatible {
				return m.referenceMap(raw)
			}
			if !found {
				continue
			}
			if value != "" {
				value += "\n"
			}
			value += text
			if rename {
				if err := deleteMappedSelection(out, path, keys); err != nil {
					return m.referenceMap(raw)
				}
			}
		}
		if rename {
			out[rule.target] = value
		} else if original {
			recordOriginal(out, last, value)
		}
		if value != "" {
			mapped[rule.target] = value
		}
	}
	if !rename && m.output[0] == '1' {
		out = mapped
	} else if !rename && m.output[2] == '1' {
		return mapped, raw, nil
	}
	b, err := json.Marshal(out)
	return mapped, string(b), err
}

// Java's Pattern uses ASCII Alnum/word classes and case-insensitive ASCII
// scheme matching. Its first group starts after the separator.
var linkPattern = regexp.MustCompile(`(^|[^\w])((([Hh][Tt]|[Ff])[Tt][Pp][Ss]?://|[Ww][Ww][Ww]\.)[\w-]+\.[[:alnum:].,%_=?&#\+()*$~@!:/{};'-]*)`)
var dnsName = regexp.MustCompile(`^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+([a-zA-Z]([a-zA-Z0-9-]*[a-zA-Z0-9])?)\.?$`)

// java.net.URI decodes percent-escaped octets as UTF-8 with replacement.
// Go's URL parser returns the original bytes. Consume each malformed prefix
// once, as Java's decoder does; unusual restricted lead bytes use the oracle.
func decodedURIText(s string) (string, bool) {
	if utf8.ValidString(s) {
		return s, true
	}
	var out strings.Builder
	for len(s) > 0 {
		r, n := utf8.DecodeRuneInString(s)
		if r == utf8.RuneError && n == 1 {
			first := s[0]
			width := 1
			switch {
			case first >= 0xc2 && first <= 0xdf:
				width = 2
			case first >= 0xe0 && first <= 0xef:
				width = 3
			case first >= 0xf0 && first <= 0xf4:
				width = 4
			}
			for n < width && n < len(s) && s[n]&0xc0 == 0x80 {
				n++
			}
		}
		out.WriteRune(r)
		s = s[n:]
	}
	return out.String(), true
}

// URI falls back to Guava's URL fragment escaper when the matched text is
// malformed. These are the only unsafe characters allowed by linkPattern.
var escapeURIFragment = strings.NewReplacer("%", "%25", "#", "%23", "{", "%7B", "}", "%7D")
var nonDNSName = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)

// Go rejects nonnumeric URI ports at parse time. java.net.URI accepts the
// authority but returns a null host; its path, query, and fragment still hash.
// Parse those components under a neutral host and suppress hostname hashing.
func parseURIInvalidPort(raw string) (*url.URL, bool) {
	scheme, afterScheme, ok := strings.Cut(raw, "://")
	if !ok || scheme == "" {
		return nil, false
	}
	end := strings.IndexAny(afterScheme, "/?#")
	if end < 0 {
		end = len(afterScheme)
	}
	authority := afterScheme[:end]
	if !strings.Contains(authority, ":") || strings.ContainsAny(authority, "[]") {
		return nil, false
	}
	u, err := url.Parse(scheme + "://placeholder.invalid" + afterScheme[end:])
	return u, err == nil
}

func mapURLs(text, target string, mapped map[string]any) bool {
	for _, at := range linkPattern.FindAllStringSubmatchIndex(text, -1) {
		raw := text[at[4]:at[5]]
		u, err := url.Parse(raw)
		if strings.ContainsAny(raw, "{}") || strings.Count(raw, "#") > 1 || (err != nil && strings.Contains(err.Error(), "invalid URL escape")) {
			raw = escapeURIFragment.Replace(raw)
			u, err = url.Parse(raw)
		}
		noHost := false
		if err != nil && strings.Contains(err.Error(), "invalid port") {
			if candidate, ok := parseURIInvalidPort(raw); ok {
				u, err, noHost = candidate, nil, true
			}
		}
		if err != nil {
			return false
		}
		host := u.Hostname()
		if noHost {
			host = ""
		}
		if port := u.Port(); port != "" {
			if _, err := strconv.ParseUint(port, 10, 31); err != nil {
				// java.net.URI.getHost returns null when the port does
				// not fit a signed 32-bit integer. Path/query still hash.
				host = ""
			}
		}
		q, err := url.PathUnescape(u.RawQuery)
		query, validQuery := decodedURIText(q)
		pathText, validPath := decodedURIText(u.Path)
		fragment, validFragment := decodedURIText(u.Fragment)
		if err != nil || !validQuery || !validPath || !validFragment {
			return false
		}
		if host != "" && !dnsName.MatchString(host) && net.ParseIP(host) == nil {
			// URI keeps the raw authority but returns null from getHost for
			// underscores, empty labels, or numeric top-level domains.
			if !nonDNSName.MatchString(host) || strings.Count(host, ".") == 3 && strings.Trim(host, "0123456789.") == "" {
				return false
			}
			host = ""
		}
		if host != "" {
			labels := strings.Split(strings.TrimSuffix(host, "."), ".")
			suffix := ""
			for i := len(labels) - 1; i >= 0; i-- {
				if suffix != "" {
					suffix = "." + suffix
				}
				suffix = labels[i] + suffix
				appendURLHash(mapped, target+"_host_path", suffix)
			}
		}
		path := ""
		for _, part := range strings.Split(pathText, "/") {
			if part != "" {
				path += "/" + part
				appendURLHash(mapped, target+"_host_path", path)
			}
		}
		if u.RawQuery != "" || u.ForceQuery {
			appendURLHash(mapped, target+"_query", strings.ReplaceAll(query, "&", " "))
		}
		if strings.Contains(raw, "#") {
			appendURLHash(mapped, target+"_anchor", fragment)
		}
	}
	return true
}

func appendURLHash(doc map[string]any, key, value string) {
	sum := md5.Sum([]byte(value))
	hash := strings.ToUpper(hex.EncodeToString(sum[:]))
	if previous, ok := doc[key].(string); ok {
		hash = previous + " " + hash
	}
	doc[key] = hash
}
