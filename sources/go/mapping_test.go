package main

import (
	"encoding/json"
	"context"
	"os/exec"
	"os"
	"reflect"
	"strings"
	"testing"
	"unicode"
	"unicode/utf8"
)

func assertSameJSON(t *testing.T, got, want string) {
	t.Helper()
	if !json.Valid([]byte(got)) || !json.Valid([]byte(want)) {
		t.Fatalf("invalid JSON: got %s; want %s", got, want)
	}
	var a, b any
	gotDecoder := json.NewDecoder(strings.NewReader(got))
	wantDecoder := json.NewDecoder(strings.NewReader(want))
	gotDecoder.UseNumber()
	wantDecoder.UseNumber()
	if err := gotDecoder.Decode(&a); err != nil {
		t.Fatal(err)
	}
	if err := wantDecoder.Decode(&b); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(a, b) {
		t.Fatalf("got %s; want %s", got, want)
	}
}

func TestNativeMappingAndOutput(t *testing.T) {
	raw := `{"kind":"http","nested":{"http":{"code":"200"}},"ids":[{"v":"One"},{"v":"Two"}],"numeric":72,"empty":null}`
	fields := map[string]string{"id": "text", "code": "int", "numeric": "int", "all": "json", "empty": "text"}
	rules := []string{"kind&&ids[*].v => id", "nested.{kind}.code => code", "numeric => numeric", "empty => empty", "whole_document => all"}
	m := newMapper(rules, fields, "0011")
	v, out, err := m.transform(raw)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(v)
	assertSameJSON(t, string(b), `{"id":"http\nOne\nTwo\n","code":"200","empty":"null","all":`+raw+`}`)
	assertSameJSON(t, out, raw)
	if m.reference.cmd != nil {
		t.Fatal("ordinary mapping unexpectedly started a Java process")
	}

	raw = `{"a":{"b":"one","c":"two"},"keep":4}`
	for mode, want := range map[string]string{
		"1000": `{"name":"one"}`, "0100": `{"a":{"b":"one"}}`, "0010": raw,
		"1010": `{"a":{"c":"two"},"keep":4,"name":"one"}`,
	} {
		m = newMapper([]string{"a.b => name"}, map[string]string{"name": "text"}, mode)
		_, out, err := m.transform(raw)
		if err != nil {
			t.Fatal(err)
		}
		assertSameJSON(t, out, want)
	}
}

func TestURLHashGolden(t *testing.T) {
	m := newMapper([]string{"link => url"}, map[string]string{"url": "url"}, "1000")
	raw := `{"link":"Has URL to https://manticoresearch.com/section/?uri=123#anchor"}`
	_, out, err := m.transform(raw)
	if err != nil {
		t.Fatal(err)
	}
	assertSameJSON(t, out, `{
		"url_query":"18C3F9B988D0508B07E3DC6605D9D531",
		"url_host_path":"4D236D9A2D102C5FE6AD1C50DA4BEC50 ED0BD936751E99C169B7F662E46CE192 FA873ECD0A4D123077DD9156A2A0A18F",
		"url_anchor":"47AE9EC4C0978A1293D1030E30034B8A"
	}`)
}

func TestNativeLowercase(t *testing.T) {
	raw := `{"TITLE":"Новости MANTICORE — 漢字 ❤️"}`
	out, err := lowerBatch(raw)
	if err != nil || out != `{"title":"новости manticore — 漢字 ❤️"}` {
		t.Fatalf("lowerBatch = %q, %v", out, err)
	}
}

// Java's default console encoding can be ASCII under LC_ALL=C. Verify that
// the UTF-8 reference protocol preserves customer strings in that environment.
func TestJavaBridgeUnicodeProtocol(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires compiled StreamsCompat and the Java worker JAR")
	}
	t.Setenv("LC_ALL", "C")
	ref := javaReference("jslt", `{"Text":.Text,"Id":.Id}`)
	defer ref.close()
	input := `{"Text":"Unicode café 漢字","Id":"пост-1"}`
	got, err := ref.call(input)
	if err != nil {
		t.Fatal(err)
	}
	assertSameJSON(t, got, input)
}

func TestStrictNativeMapper(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	unsupported := newMapper([]string{"items...name => selected"}, map[string]string{"selected": "text"}, "1000")
	if err := unsupported.requireNativeSupport(); err == nil || !strings.Contains(err.Error(), "hybrid worker") {
		t.Fatalf("unsupported JSONPath was not rejected at startup: %v", err)
	}
	defer unsupported.reference.close()
	if unsupported.reference.cmd != nil {
		t.Fatal("strict native mode started Java while validating configuration")
	}
	mapper := newMapper([]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000")
	defer mapper.reference.close()
	if err := mapper.requireNativeSupport(); err != nil {
		t.Fatal(err)
	}
	if _, _, err := mapper.transform(`{"kind":{"nested":"unexpected"},"connection":{}}`); err == nil ||
		!strings.Contains(err.Error(), "hybrid worker") {
		t.Fatalf("runtime Java fallback was not clearly rejected: %v", err)
	}
	if mapper.reference.cmd != nil {
		t.Fatal("strict native mode unexpectedly started Java")
	}
	ordinary := newMapper([]string{"title => title"}, map[string]string{"title": "text"}, "1000")
	defer ordinary.reference.close()
	if err := ordinary.requireNativeSupport(); err != nil {
		t.Fatal(err)
	}
	_, output, err := ordinary.transform(`{"title":"ok"}`)
	if err != nil {
		t.Fatal(err)
	}
	assertSameJSON(t, output, `{"title":"ok"}`)
}

func TestReferenceTransforms(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires compiled StreamsCompat and the Java worker JAR")
	}
	fn, err := newJSLT(`let idparts = split(.id, "-")
{"id": join([for ($idparts) "x" * size(.)], "-"), * : .}`)
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct{ in, want string }{
		{`{"id":"abc-x","keep":1}`, `{"id":"xxx-x","keep":1}`},
		{`{"id":"a-bb","keep":null}`, `{"id":"x-xx","keep":null}`},
	} {
		got, err := fn(tc.in)
		if err != nil {
			t.Fatal(err)
		}
		assertSameJSON(t, got, tc.want)
	}
	complex := newMapper([]string{"groups[*].people[?(@.active == true)].name => selected", "['user.name'] => name"},
		map[string]string{"selected": "text", "name": "text"}, "1000")
	if complex.needsReference {
		t.Fatal("advanced paths should use native JSONPath")
	}
	_, got, err := complex.transform(`{"user.name":"reader","groups":[{"people":[{"name":"Eve","active":true},{"name":"Bob","active":false}]}]}`)
	if complex.reference.cmd != nil {
		t.Fatal("advanced JSONPath unexpectedly started Java")
	}
	defer complex.reference.close()
	if err != nil {
		t.Fatal(err)
	}
	assertSameJSON(t, got, `{"name":"reader","selected":"Eve\n"}`)
	links := newMapper([]string{"body => url"}, map[string]string{"url": "url"}, "1000")
	defer links.reference.close()
	// Compare the output and the document sent to CALL PQ with the
	// original worker, rather than only checking the selected fields.
	for _, tc := range []struct {
		name   string
		raw    string
		rules  []string
		fields map[string]string
		mode   string
		needsJava bool
	}{
		{"non-string URL abort 1000", `{"before":"first","link":123,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1000", false},
		{"undeclared field abort 1000", `{"before":"first","value":"ignored","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "1000", false},
		{"non-string URL abort 0100", `{"before":"first","link":123,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "0100", false},
		{"undeclared field abort 0100", `{"before":"first","value":"ignored","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "0100", false},
		{"non-string URL abort 1010", `{"before":"first","link":123,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1010", false},
		{"undeclared field abort 1010", `{"before":"first","value":"ignored","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "1010", false},
		{"non-string URL abort 0010", `{"before":"first","link":123,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "0010", false},
		{"undeclared field abort 0010", `{"before":"first","value":"ignored","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "0010", false},
		{"non-string URL abort 0011", `{"before":"first","link":123,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "0011", false},
		{"undeclared field abort 0011", `{"before":"first","value":"ignored","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "0011", false},
		{"boolean URL abort", `{"before":"first","link":true,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1000", false},
		{"object URL abort", `{"before":"first","link":{"uri":"https://example.com"},"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1010", false},
		{"array URL abort", `{"before":"first","link":["https://example.com"],"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "0100", false},
		{"null URL continues", `{"before":"first","link":null,"after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1010", false},
		{"URL missing continues", `{"before":"first","after":"last"}`,
			[]string{"before => before", "link => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1000", false},
		{"undeclared field before any mapping", `{"value":"ignored","after":"last"}`,
			[]string{"value => absent", "after => after"}, map[string]string{"after":"text"}, "1000", false},
		{"undeclared field absent in document", `{"before":"first","after":"last"}`,
			[]string{"before => before", "value => absent", "after => after"}, map[string]string{"before":"text","after":"text"}, "1000", false},
		{"whole document without declared type", `{"value":"hello"}`,
			[]string{"whole_document => whole"}, map[string]string{}, "1000", false},
		{"field with empty declared type", `{"value":"hello"}`,
			[]string{"value => chosen"}, map[string]string{"chosen":""}, "1000", false},
		{"null dynamic selector 1000", `{"before":"first","kind":null,"connection":{"null":{"code":"ignored"}},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1000", false},
		{"null dynamic selector 0100", `{"before":"first","kind":null,"connection":{"null":{"code":"ignored"}},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0100", false},
		{"null dynamic selector 1010", `{"before":"first","kind":null,"connection":{"null":{"code":"ignored"}},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
		{"null dynamic selector 0010", `{"before":"first","kind":null,"connection":{"null":{"code":"ignored"}},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0010", false},
		{"Han dynamic selector 1000", `{"kind":"漢","connection":{"漢":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"Han dynamic selector 0100", `{"kind":"漢","connection":{"漢":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"Han dynamic selector 1010", `{"kind":"漢","connection":{"漢":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"Cyrillic dynamic selector 1000", `{"kind":"Русский","connection":{"Русский":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"Cyrillic dynamic selector 0100", `{"kind":"Русский","connection":{"Русский":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"Cyrillic dynamic selector 1010", `{"kind":"Русский","connection":{"Русский":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"accented dynamic selector 1000", `{"kind":"école","connection":{"école":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"accented dynamic selector 0100", `{"kind":"école","connection":{"école":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"accented dynamic selector 1010", `{"kind":"école","connection":{"école":{"code":"unicode"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dotted Unicode dynamic selector 1000", `{"kind":"漢.字","connection":{"漢":{"字":{"code":"unicode"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dotted Unicode dynamic selector 0100", `{"kind":"漢.字","connection":{"漢":{"字":{"code":"unicode"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"dotted Unicode dynamic selector 1010", `{"kind":"漢.字","connection":{"漢":{"字":{"code":"unicode"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"descendant path skip 1000", `{"before":"first","items":[{"name":"One"},{"name":"Two"}],"after":"last"}`,
			[]string{"before => before", "items..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1000", false},
		{"descendant path skip 0100", `{"before":"first","items":[{"name":"One"},{"name":"Two"}],"after":"last"}`,
			[]string{"before => before", "items..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0100", false},
		{"descendant path skip 1010", `{"before":"first","items":[{"name":"One"},{"name":"Two"}],"after":"last"}`,
			[]string{"before => before", "items..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
		{"descendant path skip 0010", `{"before":"first","items":[{"name":"One"},{"name":"Two"}],"after":"last"}`,
			[]string{"before => before", "items..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0010", false},
		{"descendant nested path", `{"items":{"nested":[{"name":"One"}]},"after":"last"}`,
			[]string{"items.nested..name => selected", "after => after"}, map[string]string{"selected":"text","after":"text"}, "0100", false},
		{"descendant missing path", `{"other":4,"after":"last"}`,
			[]string{"items..name => selected", "after => after"}, map[string]string{"selected":"text","after":"text"}, "0100", false},
		{"leading descendant path", `{"name":"Root","items":[{"name":"One"}],"after":"last"}`,
			[]string{".name => selected", "after => after"}, map[string]string{"selected":"text","after":"text"}, "1010", false},
		{"leading descendant path original", `{"name":"Root","items":[{"name":"One"}]}`,
			[]string{".name => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"URL wildcard abort output", `{"value":"first","items":[{"url":"https://example.com/a"}],"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1000", false},
		{"URL wildcard abort original", `{"value":"first","items":[],"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "0100", false},
		{"URL wildcard abort rename", `{"value":"first","items":[{"url":"https://example.com/a"}],"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1010", false},
		{"URL wildcard abort raw", `{"value":"first","items":[{"url":"https://example.com/a"}],"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "0010", false},
		{"URL wildcard abort null field", `{"value":"first","items":null,"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1000", false},
		{"URL wildcard abort non-array", `{"value":"first","items":{},"other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1000", false},
		{"URL wildcard missing continues", `{"value":"first","other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1000", false},
		{"URL wildcard missing original", `{"value":"first","other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "0100", false},
		{"URL wildcard missing rename", `{"value":"first","other":"last"}`,
			[]string{"value => title", "items[*].url => link", "other => extra"}, map[string]string{"title":"text","link":"url","extra":"text"}, "1010", false},
		{"filter without wildcard", `{"items":[{"name":"One","active":true},{"name":"Two","active":false}]}`,
			[]string{"items[?(@.active == true)].name => chosen"}, map[string]string{"chosen":"text"}, "1000", false},
		{"filter without wildcard original", `{"items":[{"name":"One","active":true},{"name":"Two","active":false}]}`,
			[]string{"items[?(@.active == true)].name => chosen"}, map[string]string{"chosen":"text"}, "0100", false},
		{"filter without wildcard rename", `{"items":[{"name":"One","active":true},{"name":"Two","active":false}]}`,
			[]string{"items[?(@.active == true)].name => chosen"}, map[string]string{"chosen":"text"}, "1010", false},
		{"slice without wildcard", `{"items":[{"name":"One"},{"name":"Two"}]}`,
			[]string{"items[0:1].name => chosen"}, map[string]string{"chosen":"text"}, "1000", false},
		{"slice without wildcard original", `{"items":[{"name":"One"},{"name":"Two"}]}`,
			[]string{"items[0:1].name => chosen"}, map[string]string{"chosen":"text"}, "0100", false},
		{"multiple indices without wildcard", `{"items":[{"name":"One"},{"name":"Two"}]}`,
			[]string{"items[0,1].name => chosen"}, map[string]string{"chosen":"text"}, "1000", false},
		{"indexed original field", `{"items":[{"name":"Alice"},{"name":"Bob"}]}`,
			[]string{"items[0].name => chosen"}, map[string]string{"chosen":"text"}, "0100", false},
		{"quoted-key original field", `{"user.name":"Alice","keep":1}`,
			[]string{"['user.name'] => chosen"}, map[string]string{"chosen":"text"}, "0100", false},
		{"filtered original field", `{"items":[{"people":[{"name":"Alice","active":true},{"name":"Bob","active":false}]}]}`,
			[]string{"items[*].people[?(@.active == true)].name => chosen"}, map[string]string{"chosen":"text"}, "0100", false},
		{"indexed rename field", `{"items":[{"name":"Alice","keep":1},{"name":"Bob"}]}`,
			[]string{"items[0].name => chosen"}, map[string]string{"chosen":"text"}, "1010", false},
		{"quoted-key rename field", `{"user.name":"Alice","keep":1}`,
			[]string{"['user.name'] => chosen"}, map[string]string{"chosen":"text"}, "1010", false},
		{"indexed URL path", `{"links":[{"url":"https://example.com/a"},{"url":"https://other.example.com/b"}]}`,
			[]string{"links[0].url => link"}, map[string]string{"link": "url"}, "1000", false},
		{"second indexed URL path", `{"links":[{"url":"https://example.com/a"},{"url":"https://other.example.com/b?x=1"}]}`,
			[]string{"links[1].url => link"}, map[string]string{"link": "url"}, "1000", false},
		{"missing indexed URL path", `{"links":[{"url":"https://example.com/a"}]}`,
			[]string{"links[10].url => link"}, map[string]string{"link": "url"}, "1000", false},
		{"indexed URL passthrough mode", `{"links":[{"url":"https://example.com/a"}]}`,
			[]string{"links[0].url => link"}, map[string]string{"link": "url"}, "0010", false},
		{"rename wildcard", `{"items":[{"name":"Ann","keep":1},{"name":"Bob","keep":2}],"meta":3}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected": "text"}, "1010", false},
		{"scalar wildcard", `{"items":[1,true,null,"hello"]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"nested wildcard objects", `{"items":[{"id":"one"},{"id":"two"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard arrays", `{"items":[[1,2],["a","b"]]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard objects containing arrays", `{"items":[{"list":[1,2]},{"list":[3,null]}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard empty array", `{"items":[]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"missing wildcard", `{"other":"value"}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"multi-key wildcard preserves order", `{"items":[{"id":"one","tag":"alpha"},{"tag":"beta","id":"two"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"nested multi-key map", `{"items":[{"properties":{"first":"one","second":"two"}}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard arrays of objects", `{"items":[[{"first":"one","second":"two"}]]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard maps keep unicode", `{"items":[{"text":"café"},{"text":"漢字"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard maps escape HTML consistently", `{"items":[{"html":"<b>&"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard decimals use Go", `{"items":[{"n":1e10}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"rename multi-key wildcard", `{"items":[{"id":"one","tag":"alpha"},{"tag":"beta","id":"two"}],"meta":3}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1010", false},
		{"original multi-key wildcard", `{"items":[{"id":"one","tag":"alpha"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "0100", false},
		{"decimal wildcard", `{"items":[{"n":1.0},{"n":3.50},{"n":0.001}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"scientific wildcard", `{"items":[{"n":0.0009},{"n":1e10},{"n":2147483647.0}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"signed zero wildcard", `{"items":[{"n":-0.0},{"n":0.0},{"n":-5.00}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"decimal normal fields", `{"items":[{"value":1.5},{"value":3.50},{"value":2.5e3}]}`,
			[]string{"items[*].value => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"decimal original fields", `{"items":[{"value":1.5},{"value":3.50},{"value":2.5e3}]}`,
			[]string{"items[*].value => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"decimal rename fields", `{"items":[{"value":1.5},{"value":3.50},{"value":2.5e3}]}`,
			[]string{"items[*].value => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested decimal array", `{"items":[{"n":[1.5,3.50,0.0009]}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"numeric dynamic selector", `{"kind":12,"connection":{"12":{"code":"yes"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"boolean dynamic selector", `{"kind":true,"connection":{"true":{"code":"yes"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"dotted dynamic selector", `{"kind":"a.b","connection":{"a":{"b":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1010", false},
		{"original dotted dynamic selector", `{"kind":"a.b","connection":{"a":{"b":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "0100", false},
		{"original wildcard", `{"items":[{"name":"Ann"},{"name":"Bob"}]}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected": "text"}, "0100", false},
		{"rename filtered path", `{"groups":[{"people":[{"name":"Eve","active":true},{"name":"Bob","active":false}]}]}`,
			[]string{"groups[*].people[?(@.active == true)].name => selected"}, map[string]string{"selected": "text"}, "1010", false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := newMapper(tc.rules, tc.fields, tc.mode)
			defer m.reference.close()
			if m.needsReference {
				t.Fatal("mapping still requires the Java fallback")
			}
			gotMapped, got, err := m.transform(tc.raw)
			if err != nil {
				t.Fatal(err)
			}
			if (m.reference.cmd != nil) != tc.needsJava {
				t.Fatalf("unexpected Java fallback: started=%t, expected=%t", m.reference.cmd != nil, tc.needsJava)
			}
			wantMapped, want, err := m.referenceMap(tc.raw)
			if err != nil {
				t.Fatal(err)
			}
			encoded, err := json.Marshal(gotMapped)
			if err != nil {
				t.Fatal(err)
			}
			wantEncoded, err := json.Marshal(wantMapped)
			if err != nil {
				t.Fatal(err)
			}
			assertSameJSON(t, string(encoded), string(wantEncoded))
			assertSameJSON(t, got, want)
		})
	}

	for _, link := range []string{
		"http://example.com:abc/path#fragment", "http://example.com:-1/path",
		"http://example.com:abc/path?x=a+b#fragment", "http://example.com:abc",
		"http://example.com:2147483647/path", "http://example.com:2147483648/path",
		"http://example.com:999999999999999/path?x=1", "http://example.com:65536/path",
		"http://example.com:/path",
		"http://foo.12345/path", "http://bad_host.example.com/", "http://a..b.com/x",
		"http://example.com/p?q=%23tag#first#second",
		"http://example.com/%BD%E3%BD%E3%CE%D2%B6%E9%C2%E4%C1%CB/",
		"http://tieba.baidu.com/f?kw=%C1%F5%C7%AB", "www.example.com/path?x=a%26b#",
	} {
		doc, _ := json.Marshal(map[string]string{"body": link})
		links.reference.close() // Ensure the mapper does not silently start Java.
		_, native, err := links.transform(string(doc))
		if links.reference.cmd != nil {
			t.Fatalf("native URL mapping started the Java fallback for %q", link)
		}
		if err != nil {
			t.Fatal(err)
		}
		_, reference, err := links.referenceMap(string(doc))
		if err != nil {
			t.Fatal(err)
		}
		assertSameJSON(t, native, reference)
	}
}

// Match mb_strtolower in the Java image, including contextual and multi-rune
// mappings. Only exceptional batches need the persistent PHP process.
var mbLower = &referenceProcess{command: func(ctx context.Context) *exec.Cmd {
	return exec.CommandContext(ctx, "php", "-r", `
fgets(STDIN);
echo "{\"data\":\"\"}\n";
while (($line = fgets(STDIN)) !== false) {
    try {
        $text = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $reply = ['data' => mb_strtolower($text, 'UTF-8')];
    } catch (Throwable $e) {
        $reply = ['error' => $e->getMessage()];
    }
    echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}`)
}}


func TestPHPUnicodeParity(t *testing.T) {
	if os.Getenv("STREAMS_TEST_MBSTRING") != "1" {
		t.Skip("requires PHP 8.5 mbstring")
	}
	for _, pair := range [][2]string{
		{"İ ΟΣ ΟΣΑ", "i̇ ος οσα"}, {"\U00016ea0", "\U00016ebb"},
		{"A" + strings.Repeat("\u0301", 80) + "Σ", ""}, // mbstring chunk-boundary behavior is the reference.
	} {
		got, err := lowerBatch(pair[0])
		if err != nil {
			t.Fatal(err)
		}
		want, err := mbLower.call(pair[0])
		if err != nil {
			t.Fatal(err)
		}
		if got != want || pair[1] != "" && got != pair[1] {
			t.Fatalf("lower(%q) = %q, php %q", pair[0], got, want)
		}
	}
	// Exercise every scalar that uses Go's Unicode tables against mbstring.
	// This catches table changes when either runtime is upgraded.
	var chunk strings.Builder
	check := func() {
		input := chunk.String()
		got, err := lowerBatch(input)
		if err != nil {
			t.Fatal(err)
		}
		want, err := mbLower.call(input)
		if err != nil {
			t.Fatal(err)
		}
		if got != want {
			t.Fatal("simple Unicode lowercase differs from PHP")
		}
		chunk.Reset()
	}
	for r := rune(0); r <= unicode.MaxRune; r++ {
		if utf8.ValidRune(r) {
			chunk.WriteRune(r)
		}
		if chunk.Len() >= 32768 {
			check()
		}
	}
	if chunk.Len() > 0 {
		check()
	}
}
