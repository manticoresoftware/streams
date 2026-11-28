package main

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
)

// All expressions and inputs here are invented; no private configuration is used.
func TestJSLTSyntheticParseTimePrefix(t *testing.T) {
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" || os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires JSLT bundle and Java oracle")
	}
	for _, tc := range []struct{ name, expr, input string }{
		{"SQL suffix", `parse-time(.stamp,"yyyy-MM-dd HH:mm:ss","bad")`, `{"stamp":"2031-07-09 14:23:45.678Z"}`},
		{"ISO suffix", `parse-time(.stamp,"yyyy-MM-dd'T'HH:mm:ss","bad")`, `{"stamp":"2031-07-09T14:23:45Z"}`},
		{"SQL no fallback", `parse-time(.stamp,"yyyy-MM-dd HH:mm:ss")`, `{"stamp":"2031-07-09 14:23:45 extra"}`},
		{"round trip", `format-time(round(parse-time(.stamp,"yyyy-MM-dd'T'HH:mm:ss")),"yyyy-MM-dd HH:mm:ss")`, `{"stamp":"2031-07-09T14:23:45.456Z"}`},
		{"unparseable", `parse-time(.stamp,"yyyy-MM-dd HH:mm:ss","bad")`, `{"stamp":"not-a-date"}`},
		{"exact", `parse-time(.stamp,"yyyy-MM-dd HH:mm:ss","bad")`, `{"stamp":"2031-07-09 14:23:45"}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			ref := javaReference("jslt", tc.expr)
			defer ref.close()
			want, err := ref.call(tc.input)
			if err != nil {
				t.Fatal(err)
			}
			fn, err := newJSLT(tc.expr)
			if err != nil {
				t.Fatal(err)
			}
			got, err := fn(tc.input)
			if err != nil {
				t.Fatal(err)
			}
			var a, b any
			da := json.NewDecoder(strings.NewReader(got))
			da.UseNumber()
			db := json.NewDecoder(strings.NewReader(want))
			db.UseNumber()
			if da.Decode(&a) != nil || db.Decode(&b) != nil || !equivalentJSLTValue(a, b) {
				t.Fatalf("Java=%s Go=%s", want, got)
			}
		})
	}
}
