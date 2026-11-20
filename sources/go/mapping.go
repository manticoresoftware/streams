package main

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/url"
	"regexp"
	"strings"
	"unicode/utf8"
)

type mappingRule struct {
	target string
	isURL  bool
	paths  []mappingPath
}

type mappingPath struct {
	keys        []string
	wildcard    int
	placeholder string
	from        []string
}

type mapper struct {
	rules                  []mappingRule
	output                 string
	reference              *referenceProcess
	needsReference         bool
	passThroughWithoutRule bool
}

var simpleKey = regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)

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
		r := mappingRule{target: target, isURL: fields[target] == "url"}
		for _, input := range strings.Split(strings.TrimSpace(parts[0]), "&&") {
			p, ok := compileMappingPath(input)
			if !ok || (fields[target] == "" && input != "whole_document") ||
				(output[0] == '1' && output[2] == '1' && p.wildcard >= 0) {
				m.needsReference = true
			}
			r.paths = append(r.paths, p)
		}
		m.rules = append(m.rules, r)
	}
	return m
}

func (m *mapper) referenceMap(raw string) (any, string, error) {
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
		if child, ok := v.(map[string]any); ok {
			v = sourceCopy(child)
		}
		out[k] = v
	}
	return out
}

func deleteMappedPath(obj map[string]any, keys []string) {
	parent, _ := lookupMappedPath(obj, keys[:len(keys)-1])
	if p, ok := parent.(map[string]any); ok {
		delete(p, keys[len(keys)-1])
	}
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
// in document order, including a trailing newline. Unexpected wildcard types
// go to Jayway to retain Java collection-to-string conversion.
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
	v, _ := lookupMappedPath(root, keys[:wildcard+1])
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
		switch v := v.(type) {
		case nil:
			s.WriteString("null")
		case string:
			s.WriteString(v)
		case bool:
			fmt.Fprint(&s, v)
		default:
			return "", false, false
		}
		s.WriteByte('\n')
	}
	return s.String(), true, true
}

func (m *mapper) transform(raw string) (any, string, error) {
	if m.needsReference || !json.Valid([]byte(raw)) {
		return m.referenceMap(raw)
	}
	if m.passThroughWithoutRule {
		return json.RawMessage(raw), "null", nil
	}
	var doc map[string]any
	decoder := json.NewDecoder(strings.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&doc); err != nil || doc == nil {
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
				if !ok || !simpleKey.MatchString(key) {
					return m.referenceMap(raw)
				}
				keys = append([]string(nil), keys...)
				for i := range keys {
					if keys[i] == "{}" {
						keys[i] = key
					}
				}
			}
			if rule.isURL {
				v, exists := lookupMappedPath(doc, keys)
				if path.wildcard >= 0 {
					return m.referenceMap(raw)
				}
				if exists && v != nil {
					s, ok := v.(string)
					if !ok || !mapURLs(s, rule.target, mapped) {
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
			text, found, compatible := selectMappedValue(doc, keys, path.wildcard)
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
				deleteMappedPath(out, keys)
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

func mapURLs(text, target string, mapped map[string]any) bool {
	for _, at := range linkPattern.FindAllStringSubmatchIndex(text, -1) {
		raw := text[at[4]:at[5]]
		u, err := url.Parse(raw)
		if err != nil || strings.ContainsAny(raw, "{}") || strings.Count(raw, "#") > 1 ||
			(u.Host != "" && !dnsName.MatchString(u.Hostname())) {
			// java.net.URI and Guava's retry escaping differ for malformed hosts
			// and escapes. Keep their exact behavior for these inputs.
			return false
		}
		query, err := url.PathUnescape(u.RawQuery)
		if err != nil || !utf8.ValidString(query) || !utf8.ValidString(u.Path) || !utf8.ValidString(u.Fragment) {
			return false
		}
		if host := u.Hostname(); host != "" {
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
		for _, part := range strings.Split(u.Path, "/") {
			if part != "" {
				path += "/" + part
				appendURLHash(mapped, target+"_host_path", path)
			}
		}
		if u.RawQuery != "" || u.ForceQuery {
			appendURLHash(mapped, target+"_query", strings.ReplaceAll(query, "&", " "))
		}
		if strings.Contains(raw, "#") {
			appendURLHash(mapped, target+"_anchor", u.Fragment)
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
