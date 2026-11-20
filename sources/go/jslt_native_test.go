package main

import (
	"bytes"
	"encoding/json"
	"math/big"
	"os"
	"reflect"
	"strings"
	"sync"
	"testing"
)

func TestProtectJSLTIntegers(t *testing.T) {
	input := `{"large":9223372036854775807,"negative":-9223372036854775808,"small":9007199254740991,"text":"9223372036854775807","float":1.795176e9,"escaped":"\"9223372036854775807"}`
	prepared, replacements, err := protectJSLTIntegers(input)
	if err != nil {
		t.Fatal(err)
	}
	if !json.Valid([]byte(prepared)) {
		t.Fatalf("protected input is not valid JSON: %s", prepared)
	}
	if len(replacements) != 2 {
		t.Fatalf("expected two preserved integers, got %d", len(replacements))
	}
	var obj map[string]any
	if err := json.Unmarshal([]byte(prepared), &obj); err != nil {
		t.Fatal(err)
	}
	for field, want := range map[string]string{
		"large": "9223372036854775807",
		"negative": "-9223372036854775808",
	} {
		marker, ok := obj[field].(string)
		if !ok || replacements[marker] != want {
			t.Errorf("%s was not preserved exactly", field)
		}
	}
	if obj["small"] != float64(9007199254740991) ||
		obj["text"] != "9223372036854775807" ||
		obj["float"] != float64(1795176000) ||
		obj["escaped"] != "\"9223372036854775807" {
		t.Fatalf("ordinary values were changed: %#v", obj)
	}
}

// Compare numeric JSON values mathematically (1.0 == 1e0), without
// rounding long integers through float64 as JavaScript JSON.parse would.
func equivalentJSLTValue(left, right any) bool {
	switch a := left.(type) {
	case json.Number:
		b, ok := right.(json.Number)
		if !ok { return false }
		x, ok := new(big.Rat).SetString(a.String())
		if !ok { return false }
		y, ok := new(big.Rat).SetString(b.String())
		return ok && x.Cmp(y) == 0
	case []any:
		b, ok := right.([]any)
		if !ok || len(a) != len(b) { return false }
		for i := range a {
			if !equivalentJSLTValue(a[i], b[i]) { return false }
		}
		return true
	case map[string]any:
		b, ok := right.(map[string]any)
		if !ok || len(a) != len(b) { return false }
		for key, value := range a {
			other, exists := b[key]
			if !exists || !equivalentJSLTValue(value, other) { return false }
		}
		return true
	default:
		return reflect.DeepEqual(left, right)
	}
}

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
		{"unicode passthrough", "{\"id\":.id,\"text\":.text,\"url\":.url}", "{\"id\":\"пост-1\",\"text\":\"Unicode café 漢字\",\"url\":\"https://example.org/ümlaut?q=x%20y\"}"},
		{"unicode join", "{\"id\":join([.blogid,.guid],\".\")}", "{\"blogid\":\"blog-1\",\"guid\":\"пост-1\"}"},
		{"unicode with large integer", "{\"Id\":if (.PostID) .PostID else \"\", \"Text\":if (.Text) .Text else \"\"}", "{\"PostID\":9223372036854775807,\"Text\":\"Unicode café 漢字\"}"},
		{"unicode with invalid date", "def getDate(t) if (parse-time($t,\"yyyy-MM-dd HH:mm:ss\",\"error\") != \"error\") format-time(round(parse-time($t,\"yyyy-MM-dd HH:mm:ss\")),\"yyyy-MM-dd HH:mm:ss\") else \"\" {\"Text\":if (.Text) .Text else \"\", \"Published\":getDate(.Published)}", "{\"Text\":\"Unicode café 漢字\",\"Published\":\"invalid-date\"}"},
		{"unicode after URL parse", "{\"Text\":if (.description.content) .description.content else \"\", \"Url\":parse-url(.url).pathname}", "{\"description\":{\"content\":\"Unicode café 漢字\"},\"url\":\"https://example.org/ümlaut?q=x%20y\"}"},
		{"unicode HTML stripping", "def strip(s) replace($s,\"(<([^>]+)>)\",\"\") {\"Text\":strip(.Text)}", "{\"Text\":\"Unicode café 漢字 <b>hello</b>\"}"},
		{"unicode through conditional", "{\"text\":if (.text) .text else \"\"}", "{\"text\":\"Unicode café 漢字\"}"},
		{"unicode from nested object", "let item = .post {\"id\":$item.id}", "{\"post\":{\"id\":\"пост-1\"}}"},
		{"escaped unicode passthrough", "{\"text\":.text}", "{\"text\":\"\\u00e9\\u6f22\\u5b57\"}"},
		{"null filtering", "{\"missing\":.none,\"empty\":{},\"present\":\"yes\"}", "{}"},
		{"large exact integer", "{\"id\":.id}", "{\"id\":9223372036854775807}"},
		{"large negative integer", "{\"id\":.id}", "{\"id\":-9223372036854775808}"},
		{"large integer in nested array", "{\"item\":.items[0].id}", "{\"items\":[{\"id\":9223372036854775807}]}"},
		{"large integer remains string", "{\"id\":.id}", "{\"id\":\"9223372036854775807\"}"},
	}
	same := func(left, right string) bool {
		var a, b any
		if !json.Valid([]byte(left)) || !json.Valid([]byte(right)) {
			return false
		}
		la := json.NewDecoder(strings.NewReader(left))
		lb := json.NewDecoder(strings.NewReader(right))
		la.UseNumber()
		lb.UseNumber()
		if la.Decode(&a) != nil || lb.Decode(&b) != nil {
			return false
		}
		return equivalentJSLTValue(a, b)
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
