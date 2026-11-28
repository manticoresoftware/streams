package main

import (
 "encoding/json"
 "fmt"
 "io"
 "sort"
 "strconv"
 "strings"
 "testing"
)

// Compare JSON values without rounding large numbers or losing duplicate keys.
// Isolated surrogate escapes are compared verbatim.
// Preserve number tokens and duplicate values (including their relative order)
// while ignoring only object-key order. Invalid JSON is compared verbatim.
func strictCorpusJSON(raw string) string {
	// encoding/json replaces isolated UTF-16 surrogates with U+FFFD.
	// Valid high/low pairs decode losslessly; only isolated code units must
	// retain raw spelling to prevent false equivalence.
	for i := 0; i+5 < len(raw); i++ {
		if raw[i] != '\\' {
			continue
		}
		if raw[i+1] == '\\' {
			i++
			continue
		}
		if raw[i+1] == 'u' {
			if code, err := strconv.ParseUint(raw[i+2:i+6], 16, 16); err == nil && code >= 0xd800 && code <= 0xdfff {
				if code <= 0xdbff && i+11 < len(raw) && raw[i+6:i+8] == `\u` {
					low, e := strconv.ParseUint(raw[i+8:i+12], 16, 16)
					if e == nil && low >= 0xdc00 && low <= 0xdfff {
						i += 11
						continue
					}
				}
				return "raw:" + raw
			}
		}
	}
	d := json.NewDecoder(strings.NewReader(raw))
	d.UseNumber()
	s, err := corpusJSONValue(d)
	if err != nil {
		return "raw:" + raw
	}
	if _, err = d.Token(); err != io.EOF {
		return "raw:" + raw
	}
	return "json:" + s
}
func corpusJSONValue(d *json.Decoder) (string, error) {
	tok, err := d.Token()
	if err != nil {
		return "", err
	}
	switch v := tok.(type) {
	case json.Delim:
		switch v {
		case '{':
			type entry struct{ k, v string }
			var es []entry
			for d.More() {
				k, e := d.Token()
				if e != nil {
					return "", e
				}
				s, e := corpusJSONValue(d)
				if e != nil {
					return "", e
				}
				key, ok := k.(string)
				if !ok {
					return "", fmt.Errorf("key")
				}
				es = append(es, entry{key, s})
			}
			if _, e := d.Token(); e != nil {
				return "", e
			}
			sort.SliceStable(es, func(i, j int) bool { return es[i].k < es[j].k })
			var b strings.Builder
			b.WriteByte('{')
			for _, e := range es {
				b.WriteString(strconv.Quote(e.k))
				b.WriteByte(':')
				b.WriteString(e.v)
				b.WriteByte(',')
			}
			b.WriteByte('}')
			return b.String(), nil
		case '[':
			var b strings.Builder
			b.WriteByte('[')
			for d.More() {
				s, e := corpusJSONValue(d)
				if e != nil {
					return "", e
				}
				b.WriteString(s)
				b.WriteByte(',')
			}
			if _, e := d.Token(); e != nil {
				return "", e
			}
			b.WriteByte(']')
			return b.String(), nil
		}
	case json.Number:
		return "n:" + v.String(), nil
	case string:
		return "s:" + strconv.Quote(v), nil
	case nil:
		return "null", nil
	case bool:
		return strconv.FormatBool(v), nil
	}
	return "", fmt.Errorf("token")
}
func TestStrictCorpusComparison(t *testing.T) {
	for _, tc := range []struct {
		a, b string
		same bool
	}{
		{`{"a":1,"b":[2,3]}`, `{"b":[2,3],"a":1}`, true},
		{`{"a":1,"a":2}`, `{"a":2}`, false},
		{`{"a":1,"a":2}`, `{"a":2,"a":1}`, false},
		{`1 trailing`, `1`, false},
		{`"\ud83d\ude00"`, `"😀"`, true},
		{`"\ud800"`, `"\ud801"`, false},
		{`{"\ud800":1}`, `{"\ufffd":1}`, false}, {`9007199254740993`, `9007199254740992`, false},
		{`[1,2]`, `[2,1]`, false}, {`1e2`, `100`, false},
	} {
		if (strictCorpusJSON(tc.a) == strictCorpusJSON(tc.b)) != tc.same {
			t.Fatal("comparison contract")
		}
	}
}

