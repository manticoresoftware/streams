package main

import (
	"os"
	"testing"
)

func TestJSLTParseTimePrefixNative(t *testing.T) {
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" {
		t.Skip("pinned bundle required")
	}
	fn, err := newJSLT(`format-time(parse-time(.stamp,"yyyy-MM-dd HH:mm:ss"),"yyyy-MM-dd HH:mm:ss")`)
	if err != nil {
		t.Fatal(err)
	}
	for _, source := range []string{`{"stamp":"2031-07-09 14:23:45"}`, `{"stamp":"2031-07-09 14:23:45.678Z"}`, `{"stamp":"2031-07-09 14:23:45 extra"}`} {
		got, err := fn(source)
		if err != nil || got != `"2031-07-09 14:23:45"` {
			t.Fatal("Java frozen prefix parsing contract differs")
		}
	}
	if _, err = fn(`{"stamp":"invalid"}`); err == nil {
		t.Fatal("invalid date accepted")
	}
	if _, err = patchJSLTParseTimePrefix("unknown bundle"); err == nil {
		t.Fatal("unexpected bundle accepted")
	}
}
