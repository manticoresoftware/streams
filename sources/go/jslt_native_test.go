package main

import (
	"bytes"
	"encoding/json"
	"os"
	"reflect"
	"strings"
	"sync"
	"testing"
)

// These are invented inputs and expressions covering the features of the six
// production JSLT configurations. Customer rules and messages are not checked in.
func TestNativeJSLTParity(t *testing.T) {
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" {
		t.Skip("CI and Docker provide the pinned jslt-js bundle")
	}
	tests := []struct {
		name, expression, input string
	}{
		{"functions", "def normalize(x) if ($x) uppercase($x) else \"missing\" {\"text\":normalize(.text)}", "{\"text\":\"hello\"}"},
		{"top-level let before def", "let object = .entry\ndef value(x) if ($x.title) $x.title else \"none\"\n{\"title\":value($object)}", "{\"entry\":{\"title\":\"present\"}}"},
		{"forward function reference", "def first(x) second($x)\ndef second(x) string($x)\n{\"value\":first(.id)}", "{\"id\":42}"},
		{"nested content", "let obj = if (.post) .post else .comment {\"id\":$obj.id,\"text\":if ($obj.body) $obj.body else \"\",\"matches\":.matching_queries}", "{\"post\":{\"id\":\"p1\",\"body\":\"body\"},\"matching_queries\":[{\"queryid\":7}]}"},
		{"ISO time", "{\"epoch\":round(parse-time(.when,\"yyyy-MM-dd'T'HH:mm:ssX\") * 1000)}", "{\"when\":\"2026-11-20T12:00:00Z\"}"},
		{"SQL time", "{\"epoch\":parse-time(.when,\"yyyy-MM-dd HH:mm:ss\")}", "{\"when\":\"2026-11-20 12:00:00\"}"},
		{"invalid time", "{\"epoch\":parse-time(.when,\"yyyy-MM-dd\", \"missing\")}", "{\"when\":\"invalid\"}"},
		{"format time", "{\"date\":format-time(1795176000,\"yyyy-MM-dd HH:mm:ss\")}", "{}"},
		{"URL", "{\"url\":parse-url(.url)}", "{\"url\":\"https://example.com/a?x=1#frag\"}"},
		{"capture", "{\"title\":capture(.text,\"(?<text>.{0,8})\").text}", "{\"text\":\"Long message\"}"},
		{"replace", "{\"plain\":replace(.html,\"<[^>]+>\",\"\")}", "{\"html\":\"<b>word</b>\"}"},
		{"dynamic attributes", "{\"attributes\":{for(.ratings) \"Rating@name=\" + .key : number(.value,3)}}", "{\"ratings\":{\"taste\":\"4.125\",\"service\":\"3.5\"}}"},
		{"CSV numeric IDs", "{\"ids\":[for(split(.providers,\",\")) let id = number(.) $id]}", "{\"providers\":\"513,511,3\"}"},
		{"nested get-key", "{\"blog\":get-key(.data,\"blog.id\", \"\")}", "{\"data\":{\"blog.id\":\"42\"}}"},
		{"Unicode strings", "{\"size\":size(.value),\"title\":lowercase(.value)}", "{\"value\":\"НОВОСТИ\"}"},
		{"null filtering", "{\"missing\":.none,\"empty\":{},\"present\":\"yes\"}", "{}"},
		{"large exact integer", "{\"id\":.id}", "{\"id\":9223372036854775807}"},
	}
	same := func(left, right string) bool {
		var a, b any
		la := json.NewDecoder(strings.NewReader(left))
		lb := json.NewDecoder(strings.NewReader(right))
		la.UseNumber()
		lb.UseNumber()
		if la.Decode(&a) != nil || lb.Decode(&b) != nil {
			return false
		}
		return reflect.DeepEqual(a,b)
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			fn, err := newJSLT(tc.expression)
			if err != nil {
				t.Fatal(err)
			}
			got, err := fn(tc.input)
			if err != nil {
				t.Fatal(err)
			}
			if !json.Valid([]byte(got)) {
				t.Fatalf("invalid JSLT result %q", got)
			}
			if os.Getenv("STREAMS_COMPAT_CLASSPATH") != "" {
				ref := javaReference("jslt", tc.expression)
				want, err := ref.call(tc.input)
				ref.close()
				if err != nil {
					t.Fatal(err)
				}
				if !same(got, want) {
					t.Errorf("Java=%s Goja=%s", want, got)
				}
			}
		})
	}
}

func TestNativeJSLTSafety(t *testing.T) {
	disabled, err := newJSLT(" \t ")
	if err != nil || disabled != nil {
		t.Fatalf("unexpected disabled result %v",err)
	}
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" {
		t.Skip("CI and Docker provide JSLT")
	}
	if _, err := newJSLT("this is not valid JSLT"); err == nil {
		t.Fatal("invalid JSLT should fail at configuration time")
	}
	fn, err := newJSLT("let x = .name {\"name\":uppercase($x)}")
	if err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	for i:=0;i<32;i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			got, err := fn("{\"name\":\"hello\"}")
			if err != nil || !bytes.Contains([]byte(got), []byte("HELLO")) {
				t.Errorf("concurrent JSLT got %q, %v", got, err)
			}
		}()
	}
	wg.Wait()
}
