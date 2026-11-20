package main

import (
	"crypto/rand"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math/big"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/dop251/goja"
)

//go:embed jslt_polyfill.js
var jsltPolyfill string

const defaultJSLTBundle = "/usr/src/myapp/jslt-bundle.js"

type jsltEngine struct {
	mu    sync.Mutex
	vm    *goja.Runtime
	apply goja.Callable
}

// jslt-js v0.1.8 is Apache-2.0; patch only quoted Java date literals.
// The user's original JSLT expression is never rewritten.
func patchDateLiterals(bundle string) (string, error) {
	const target = "    const ch = fmt[i];\n    if (/[A-Za-z]/.test(ch)) {"
	const patched = "    const ch = fmt[i];\n" +
		"    if (ch === \"'\") {\n" +
		"      i++;\n" +
		"      if (fmt[i] === \"'\") { tokens.push({type:'lit', ch:\"'\"}); i++; continue; }\n" +
		"      let closed = false;\n" +
		"      while (i < fmt.length) {\n" +
		"        if (fmt[i] === \"'\") {\n" +
		"          if (fmt[i + 1] === \"'\") { tokens.push({type:'lit', ch:\"'\"}); i += 2; continue; }\n" +
		"          i++; closed = true; break;\n" +
		"        }\n" +
		"        tokens.push({type:'lit', ch:fmt[i++]});\n" +
		"      }\n" +
		"      if (!closed) throw new JsltException('Unterminated quoted date literal');\n" +
		"      continue;\n" +
		"    }\n" +
		"    if (/[A-Za-z]/.test(ch)) {"
	if strings.Count(bundle, target) != 1 {
		return "", fmt.Errorf("unexpected jslt-js bundle layout; cannot patch date literals")
	}
	return strings.Replace(bundle, target, patched, 1), nil
}

var fixedTZ = regexp.MustCompile("^([+-])(\\d\\d):(\\d\\d)$")

func jsltTimezone(zone string) (*time.Location, error) {
	switch zone {
	case "", "Z", "GMT", "UTC":
		return time.UTC, nil
	}
	if matches := fixedTZ.FindStringSubmatch(zone); matches != nil {
		hours, _ := strconv.Atoi(matches[2])
		minutes, _ := strconv.Atoi(matches[3])
		if hours > 23 || minutes > 59 {
			return nil, fmt.Errorf("invalid timezone %q", zone)
		}
		offset := hours*3600 + minutes*60
		if matches[1] == "-" {
			offset = -offset
		}
		return time.FixedZone(zone, offset), nil
	}
	return time.LoadLocation(zone)
}

func installJSLTHost(vm *goja.Runtime) error {
	if err := vm.Set("__streamsURLParts", func(raw string) map[string]string {
		u, err := url.Parse(raw)
		if err != nil || u.Scheme == "" || u.Hostname() == "" {
			return map[string]string{"error": fmt.Sprintf("invalid absolute URL %q", raw)}
		}
		username, password := "", ""
		if u.User != nil {
			username = u.User.Username()
			password, _ = u.User.Password()
		}
		path := u.EscapedPath()
		if path == "" {
			path = "/"
		}
		search, fragment := "", ""
		if u.RawQuery != "" || u.ForceQuery {
			search = "?" + u.RawQuery
		}
		if strings.Contains(raw, "#") {
			fragment = "#" + u.EscapedFragment()
		}
		return map[string]string{
			"hostname": u.Hostname(), "port": u.Port(),
			"pathname": path, "protocol": u.Scheme + ":",
			"search": search, "hash": fragment,
			"username": username, "password": password,
		}
	}); err != nil {
		return err
	}
	if err := vm.Set("__streamsDateParts", func(ms float64, zone string) map[string]string {
		location, err := jsltTimezone(zone)
		if err != nil {
			return map[string]string{"error": err.Error()}
		}
		at := time.UnixMilli(int64(ms)).In(location)
		return map[string]string{
			"year": at.Format("2006"),
			"month": at.Format("01"),
			"day": at.Format("02"),
			"hour": at.Format("15"),
			"minute": at.Format("04"),
			"second": at.Format("05"),
			"fractionalSecond": fmt.Sprintf("%03d", at.Nanosecond()/1e6),
			"timeZoneName": at.Format("MST"),
		}
	}); err != nil {
		return err
	}
	_, err := vm.RunString(jsltPolyfill)
	return err
}

// Java/Jackson preserves integer JSON nodes beyond the 53-bit JS Number limit.
// jslt-js already supports BigIntegerNode via BigInt, but its readTree(JSON text)
// passes through JSON.parse and loses those digits. Quote only unsafe integer
// tokens (never strings), then restore them as BigInts in JSON.parse's reviver.
var maxSafeJSLTInteger = big.NewInt(9007199254740991)

func protectJSLTIntegers(input string) (string, map[string]string, error) {
	var output strings.Builder
	output.Grow(len(input))
	var replacements map[string]string
	var nonce string
	inString, escaped := false, false
	for i := 0; i < len(input); {
		c := input[i]
		if inString {
			output.WriteByte(c)
			if escaped {
				escaped = false
			} else if c == '\\' {
				escaped = true
			} else if c == '"' {
				inString = false
			}
			i++
			continue
		}
		if c == '"' {
			inString = true
			output.WriteByte(c)
			i++
			continue
		}
		if c != '-' && (c < '0' || c > '9') {
			output.WriteByte(c)
			i++
			continue
		}
		end := i + 1
		for end < len(input) {
			n := input[end]
			if n < '0' || n > '9' {
				if n != '.' && n != 'e' && n != 'E' && n != '+' && n != '-' {
					break
				}
			}
			end++
		}
		text := input[i:end]
		// Floating-point numbers continue to use JS Number, like the old
		// engine. Preserve only integral tokens that would lose precision.
		if !strings.ContainsAny(text, ".eE+") {
			integer, ok := new(big.Int).SetString(text, 10)
			if ok && (integer.Cmp(maxSafeJSLTInteger) > 0 ||
				integer.Cmp(new(big.Int).Neg(maxSafeJSLTInteger)) < 0) {
				if replacements == nil {
					seed := make([]byte, 16)
					if _, err := rand.Read(seed); err != nil {
						return "", nil, fmt.Errorf("generate JSLT number marker: %w", err)
					}
					nonce = hex.EncodeToString(seed)
					replacements = make(map[string]string)
				}
				marker := "\x00streams-int-" + nonce + "-" + strconv.Itoa(len(replacements))
				replacements[marker] = text
				quoted, _ := json.Marshal(marker) // JSON escapes NUL as \\u0000, not \\x00.
				output.Write(quoted)
				i = end
				continue
			}
		}
		output.WriteString(text)
		i = end
	}
	return output.String(), replacements, nil
}

func newJSLT(config string) (func(string) (string, error), error) {
	if strings.TrimSpace(config) == "" {
		return nil, nil
	}
	bundlePath := os.Getenv("STREAMS_JSLT_BUNDLE")
	if bundlePath == "" {
		bundlePath = defaultJSLTBundle
	}
	b, err := os.ReadFile(bundlePath)
	if err != nil {
		return nil, fmt.Errorf("read JSLT runtime: %w", err)
	}
	bundle, err := patchDateLiterals(string(b))
	if err != nil {
		return nil, err
	}
	vm := goja.New()
	if err := installJSLTHost(vm); err != nil {
		return nil, fmt.Errorf("install JSLT host API: %w", err)
	}
	if _, err := vm.RunString(bundle); err != nil {
		return nil, fmt.Errorf("load JSLT runtime: %w", err)
	}
	if err := vm.Set("__streamsJSLTSource", config); err != nil {
		return nil, err
	}
	if _, err := vm.RunString("globalThis.__streamsCompiledJSLT = JSLT.compile(__streamsJSLTSource, 'streams.jslt');"); err != nil {
		return nil, fmt.Errorf("compile JSLT: %w", err)
	}
	fn, err := vm.RunString(`(function(input, integers) {
		const parsed = JSON.parse(input, function(_key, value) {
			return typeof value === "string" && Object.prototype.hasOwnProperty.call(integers, value)
				? BigInt(integers[value]) : value;
		});
		const result = __streamsCompiledJSLT.applyInput(JSLT.fromJS(parsed));
		return result == null ? "null" : result.toString();
	})`)
	if err != nil {
		return nil, err
	}
	apply, ok := goja.AssertFunction(fn)
	if !ok {
		return nil, fmt.Errorf("JSLT application function is not callable")
	}
	engine := &jsltEngine{vm: vm, apply: apply}
	return engine.transform, nil
}

func (p *jsltEngine) transform(input string) (string, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	prepared, replacements, err := protectJSLTIntegers(input)
	if err != nil {
		return "", err
	}
	if replacements == nil {
		replacements = map[string]string{}
	}
	value, err := p.apply(goja.Undefined(), p.vm.ToValue(prepared), p.vm.ToValue(replacements))
	if err != nil {
		return "", fmt.Errorf("apply JSLT: %w", err)
	}
	if goja.IsNull(value) || goja.IsUndefined(value) {
		return "null", nil
	}
	encoded := value.String()
	if !json.Valid([]byte(encoded)) {
		return "", fmt.Errorf("JSLT produced invalid JSON")
	}
	return encoded, nil
}
