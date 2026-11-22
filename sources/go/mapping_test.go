package main

import (
	"encoding/json"
	"context"
	"os/exec"
	"os"
	"reflect"
	"strconv"
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

// Object-valued wildcards must produce a result without starting Java.
func TestNativeObjectWildcardValues(t *testing.T) {
	m := newMapper([]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "1000")
	defer m.reference.close()
	_, out, err := m.transform(`{"items":{"first":{"name":"One"},"second":{"name":"Two"}}}`)
	if err != nil {
		t.Fatal(err)
	}
	if m.reference.cmd != nil {
		t.Fatal("object wildcard unexpectedly started Java")
	}
	assertSameJSON(t, out, `{"selected":"One\nTwo\n"}`)
}

// Object wildcards must deduplicate keys natively, including nested values.
func TestStrictNativeNestedDuplicateKeys(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	for _, tc := range []struct{ path, doc string }{
		{"items[*]", `{"items":[{"name":"Old","name":"New"}]}`},
		{"items[*]", `{"items":[{"outer":{"name":"Old","name":"New"}}]}`},
		{"items[*]", `{"items":[[{"name":"Old","name":"New"}]]}`},
		{"groups[*].people[*]", `{"groups":[{"people":[{"name":"Old","name":"New"}]}]}`},
		{"items[*].name", `{"items":{"first":{"name":"Old"},"first":{"name":"New"}}}`},
	} {
		m := newMapper([]string{tc.path+" => selected"}, map[string]string{"selected":"text"}, "1000")
		_, output, err := m.transform(tc.doc)
		if err != nil { t.Fatalf("%s: %v", tc.path, err) }
		if !json.Valid([]byte(output)) { t.Fatalf("%s: invalid output %s", tc.path, output) }
		if m.reference.cmd != nil { t.Fatal("native-only mapping started Java") }
	}
}

// Decimal selector values must use Java Double.toString, not the raw JSON
// number token: Jayway resolves 1e1 as the dotted path 10.0.
func TestNativeExponentDynamicSelectors(t *testing.T) {
	for _, tc := range []struct{ value, connection string }{
		{"1e1", `{"10":{"0":{"code":"yes"}}}`},
		{"1e-3", `{"0":{"001":{"code":"yes"}}}`},
		{"2.5e1", `{"25":{"0":{"code":"yes"}}}`},
	} {
		m := newMapper([]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000")
		input := `{"kind":` + tc.value + `,"connection":` + tc.connection + `}`
		_, out, err := m.transform(input)
		if err != nil {
			t.Fatal(err)
		}
		if m.reference.cmd != nil {
			t.Fatalf("selector %s unexpectedly started Java", tc.value)
		}
		assertSameJSON(t, out, `{"selected":"yes"}`)
	}
}

// JsonIterator preserves numeric tokens in passthrough mode, while
// Jayway normalizes them when the source is rewritten in rename mode.
func TestNativeJavaNumberSerialization(t *testing.T) {
	input := `{"label":"ok","number":2.5e1,"values":[1e-3,1.0,9007199254740993]}`
	for _,tc := range []struct{ mode string; fields []string }{
		{"0010", []string{`"number":2.5e1`,`"values":[1e-3,1.0,9007199254740993]`}},
		{"1010", []string{`"number":25.0`,`"values":[0.001,1.0,9007199254740993]`}},
	} {
		m := newMapper([]string{"label => selected"},map[string]string{"selected":"text"},tc.mode)
		_,output,err := m.transform(input)
		if err != nil { t.Fatal(err) }
		for _,expected := range tc.fields {
			if !strings.Contains(output,expected) {
				t.Fatalf("mode %s: output %s lacks numeric form %s",tc.mode,output,expected)
			}
		}
		if m.reference.cmd != nil {
			t.Fatalf("mode %s unexpectedly started Java",tc.mode)
		}
	}
	m := newMapper([]string{"whole_document => json"}, map[string]string{"json": "json"}, "1000")
	mapped, output, err := m.transform(input)
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := json.Marshal(mapped)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(encoded), `"number":25.0`) ||
		!strings.Contains(output, `"number":2.5e1`) {
		t.Fatalf("whole_document numeric forms: mapped=%s output=%s", encoded, output)
	}
	if m.reference.cmd != nil {
		t.Fatal("whole_document unexpectedly started Java")
	}
}

// Scalar fields cannot be expanded by [*]; they must not require a JRE
// even when the native worker processes a document with unexpected types.
func TestNativeScalarWildcardInputs(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	for _, mode := range []string{"1000", "0100", "1010", "0010"} {
		for _, raw := range []string{
			`{"items":null}`, `{"items":42}`,
			`{"items":true}`, `{"items":"hello"}`,
		} {
			m := newMapper([]string{"items[*] => selected"}, map[string]string{"selected": "text"}, mode)
			if err := m.requireNativeSupport(); err != nil {
				t.Fatal(err)
			}
			if _, _, err := m.transform(raw); err != nil {
				t.Fatalf("mode %s: %v", mode, err)
			}
			if m.reference.cmd != nil {
				t.Fatalf("mode %s unexpectedly started Java", mode)
			}
		}
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
	_, output, err := mapper.transform(`{"kind":{"nested":"unexpected"},"connection":{}}`)
	if err != nil {
		t.Fatalf("native object-valued dynamic selector failed: %v", err)
	}
	assertSameJSON(t, output, `{}`)
	if mapper.reference.cmd != nil {
		t.Fatal("strict native mode unexpectedly started Java")
	}
	ordinary := newMapper([]string{"title => title"}, map[string]string{"title": "text"}, "1000")
	defer ordinary.reference.close()
	if err := ordinary.requireNativeSupport(); err != nil {
		t.Fatal(err)
	}
	_, output, err = ordinary.transform(`{"title":"ok"}`)
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
		{"whole document exponent 1000", `{"number":2.5e1,"values":[1e-3,1.0,9007199254740993]}`,
			[]string{"whole_document => json"}, map[string]string{"json":"json"}, "1000", false},
		{"whole document exponent 0010", `{"number":2.5e1,"values":[1e-3,1.0,9007199254740993]}`,
			[]string{"whole_document => json"}, map[string]string{"json":"json"}, "0010", false},
		{"whole document exponent 1010", `{"number":2.5e1,"values":[1e-3,1.0,9007199254740993]}`,
			[]string{"whole_document => json"}, map[string]string{"json":"json"}, "1010", false},
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
		{"leading nested descendant original", `{"before":"first","item":{"name":"One"},"after":"last"}`,
			[]string{"before => before", ".item.name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0100", false},
		{"leading nested descendant rename", `{"before":"first","item":{"name":"One"},"after":"last"}`,
			[]string{"before => before", ".item.name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
		{"leading nested descendant new", `{"before":"first","item":{"name":"One"},"after":"last"}`,
			[]string{"before => before", ".item.name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1000", false},
		{"leading nested descendant absent", `{"before":"first","after":"last"}`,
			[]string{"before => before", ".item.name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1000", false},
		{"nested prefix descendant original", `{"before":"first","a":{"b":{"name":"One"}},"after":"last"}`,
			[]string{"before => before", "a.b..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0100", false},
		{"nested prefix descendant rename", `{"before":"first","a":{"b":{"name":"One"}},"after":"last"}`,
			[]string{"before => before", "a.b..name => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
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
		{"nested wildcard strings", `{"groups":[{"people":[{"name":"Ann"},{"name":"Bob"}]},{"people":[{"name":"Chad"}]}],"other":"ok"}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested wildcard original mode", `{"groups":[{"people":[{"name":"Ann"},{"name":"Bob"}]},{"people":[{"name":"Chad"}]}],"other":"ok"}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"nested wildcard rename mode", `{"groups":[{"people":[{"name":"Ann","keep":1},{"name":"Bob","keep":2}]}],"other":"ok"}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested wildcard map field order", `{"groups":[{"people":[{"id":1,"name":"Ann"},{"name":"Bob","id":2}]}]}`,
			[]string{"groups[*].people[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested wildcard ordered map original", `{"groups":[{"people":[{"id":1,"name":"Ann"},{"name":"Bob","id":2}]}]}`,
			[]string{"groups[*].people[*] => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"nested wildcard ordered map rename", `{"groups":[{"people":[{"id":1,"name":"Ann"},{"name":"Bob","id":2}]}]}`,
			[]string{"groups[*].people[*] => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested wildcard object in object", `{"groups":[{"people":[{"details":{"x":1,"y":2}},{"details":{"y":3,"x":4}}]}]}`,
			[]string{"groups[*].people[*].details => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested wildcard arrays", `{"groups":[{"people":[{"value":[1,2]},{"value":[3.5,4]}]}]}`,
			[]string{"groups[*].people[*].value => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested wildcard array original", `{"groups":[{"people":[{"value":[1,2]},{"value":[3.5,4]}]}]}`,
			[]string{"groups[*].people[*].value => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"nested wildcard array rename", `{"groups":[{"people":[{"value":[1,2]},{"value":[3.5,4]}]}]}`,
			[]string{"groups[*].people[*].value => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested wildcard sparse", `{"groups":[{"other":1},{"people":[{"name":"ok"}]},{"people":null}]}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested wildcard missing root", `{"other":1}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"nested wildcard null root", `{"groups":null,"other":1}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested wildcard empty root", `{"groups":[],"other":1}`,
			[]string{"groups[*].people[*] => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"triple wildcard map", `{"groups":[{"people":[{"aliases":[{"x":1},{"y":2}]}]}]}`,
			[]string{"groups[*].people[*].aliases[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"triple wildcard strings", `{"groups":[{"people":[{"aliases":["one","two"]}]}]}`,
			[]string{"groups[*].people[*].aliases[*] => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"triple wildcard rename", `{"groups":[{"people":[{"aliases":["one","two"]}]}]}`,
			[]string{"groups[*].people[*].aliases[*] => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"URL filter array", `{"before":"yes","links":[{"url":"https://example.com/a","active":true}],"after":"yes"}`,
			[]string{"before => before", "links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1010", false},
		{"URL filter empty array", `{"before":"yes","links":[],"after":"yes"}`,
			[]string{"before => before", "links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL filter null", `{"before":"yes","links":null,"after":"yes"}`,
			[]string{"before => before", "links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL filter object", `{"before":"yes","links":{},"after":"yes"}`,
			[]string{"before => before", "links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL filter missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL slice array", `{"before":"yes","links":[{"url":"https://example.com/a","active":true}],"after":"yes"}`,
			[]string{"before => before", "links[0:1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1010", false},
		{"URL slice empty array", `{"before":"yes","links":[],"after":"yes"}`,
			[]string{"before => before", "links[0:1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL slice null", `{"before":"yes","links":null,"after":"yes"}`,
			[]string{"before => before", "links[0:1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL slice object", `{"before":"yes","links":{},"after":"yes"}`,
			[]string{"before => before", "links[0:1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL slice missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "links[0:1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL union array", `{"before":"yes","links":[{"url":"https://example.com/a","active":true}],"after":"yes"}`,
			[]string{"before => before", "links[0,1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1010", false},
		{"URL union null", `{"before":"yes","links":null,"after":"yes"}`,
			[]string{"before => before", "links[0,1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL union missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "links[0,1].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[*].url nested present", `{"before":"yes","groups":[{"links":[{"url":"https://example.com/a"}]}],"after":"yes"}`,
			[]string{"before => before", "groups[*].links[*].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "0100", false},
		{"URL groups[*].links[*].url nested empty", `{"before":"yes","groups":[],"after":"yes"}`,
			[]string{"before => before", "groups[*].links[*].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[*].url nested null", `{"before":"yes","groups":null,"after":"yes"}`,
			[]string{"before => before", "groups[*].links[*].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[*].url nested missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "groups[*].links[*].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[?(@.active == true)].url nested present", `{"before":"yes","groups":[{"links":[{"url":"https://example.com/a"}]}],"after":"yes"}`,
			[]string{"before => before", "groups[*].links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "0100", false},
		{"URL groups[*].links[?(@.active == true)].url nested empty", `{"before":"yes","groups":[],"after":"yes"}`,
			[]string{"before => before", "groups[*].links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[?(@.active == true)].url nested null", `{"before":"yes","groups":null,"after":"yes"}`,
			[]string{"before => before", "groups[*].links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups[*].links[?(@.active == true)].url nested missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "groups[*].links[?(@.active == true)].url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups..url nested present", `{"before":"yes","groups":[{"links":[{"url":"https://example.com/a"}]}],"after":"yes"}`,
			[]string{"before => before", "groups..url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "0100", false},
		{"URL groups..url nested empty", `{"before":"yes","groups":[],"after":"yes"}`,
			[]string{"before => before", "groups..url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups..url nested null", `{"before":"yes","groups":null,"after":"yes"}`,
			[]string{"before => before", "groups..url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
		{"URL groups..url nested missing", `{"before":"yes","after":"yes"}`,
			[]string{"before => before", "groups..url => link", "after => after"}, map[string]string{"before":"text","link":"url","after":"text"}, "1000", false},
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
		{"object wildcard scalar values", `{"items":{"first":"One","second":"Two"}}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"object wildcard object values", `{"items":{"first":{"name":"One"},"second":{"name":"Two"}}}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"object wildcard reversed fields", `{"items":{"second":{"name":"Two"},"first":{"name":"One"}}}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"array wildcard object with duplicate keys stays native", `{"items":[{"name":"Old","name":"New"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"array wildcard object with nested duplicate keys stays native", `{"items":[{"outer":{"name":"Old","name":"New"}}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"array wildcard array containing duplicate keys stays native", `{"items":[[{"name":"Old","name":"New"}]]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"nested wildcard object with duplicate keys stays native", `{"groups":[{"people":[{"name":"Old","name":"New"}]}]}`,
			[]string{"groups[*].people[*] => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"wildcard duplicate retains first position with last value", `{"items":[{"id":"Old","name":"A","id":"New"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"object wildcard duplicate retains position", `{"items":{"first":"Old","second":"B","first":"New"}}`,
			[]string{"items[*] => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"Unicode descendant skip", `{"entry":{"название":"A"},"after":"last"}`,
			[]string{"entry..название => chosen", "after => after"}, map[string]string{"chosen":"text","after":"text"}, "1000", false},
		{"Unicode URL collection abort", `{"before":"first","ссылки":["https://example.com"],"after":"last"}`,
			[]string{"before => before", "ссылки[*] => url", "after => after"}, map[string]string{"before":"text","url":"url","after":"text"}, "1000", false},
		{"duplicate object field with selected leaf stays native", `{"items":[{"name":"Old","name":"New"}]}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"object wildcard duplicate key stays native", `{"items":{"first":{"name":"Old"},"first":{"name":"New"}}}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"object wildcard original mode", `{"items":{"first":{"name":"One"},"second":{"name":"Two"}}}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"object wildcard rename mode", `{"items":{"first":{"name":"One"},"second":{"name":"Two"}}}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"nested object wildcard at root", `{"groups":{"first":{"people":[{"name":"Ann"}]},"second":{"people":[{"name":"Bob"}]}}}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"nested object wildcard inside array", `{"groups":[{"people":{"first":{"name":"Ann"},"second":{"name":"Bob"}}}]}`,
			[]string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}, "1000", false},
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
		{"exponent dynamic selector", `{"kind":1e1,"connection":{"10":{"0":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"negative exponent dynamic selector", `{"kind":1e-3,"connection":{"0":{"001":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "0100", false},
		{"fractional exponent dynamic selector", `{"kind":2.5e1,"connection":{"25":{"0":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1010", false},
		{"boolean dynamic selector", `{"kind":true,"connection":{"true":{"code":"yes"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1000", false},
		{"dotted dynamic selector", `{"kind":"a.b","connection":{"a":{"b":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "1010", false},
		{"original dotted dynamic selector", `{"kind":"a.b","connection":{"a":{"b":{"code":"yes"}}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected": "text"}, "0100", false},
		{"dynamic selector single string array", `{"kind":["http"],"connection":{"http":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dynamic selector single string array original", `{"kind":["http"],"connection":{"http":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"dynamic selector single string array rename", `{"kind":["http"],"connection":{"http":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dynamic selector multiple string array", `{"kind":["http","mysql"],"connection":{"http":{"code":"YES"},"mysql":{"code":"NO"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dynamic selector multiple string array original", `{"kind":["http","mysql"],"connection":{"http":{"code":"YES"},"mysql":{"code":"NO"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"dynamic selector map field", `{"kind":{"type":"x"},"connection":{"{type=x}":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dynamic selector map field original", `{"kind":{"type":"x"},"connection":{"{type=x}":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "0100", false},
		{"dynamic selector empty map", `{"kind":{},"connection":{"{}":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dynamic selector map nested", `{"kind":{"a":{"b":"x"}},"connection":{"{a={b=x}}":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dynamic selector numeric index", `{"kind":[0],"connection":[{"code":"YES"}]}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dynamic selector bracket string", `{"kind":"[\"a.b\"]","connection":{"a.b":{"code":"YES"}}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dynamic selector string index", `{"kind":"a[0]","connection":{"a":[{"code":"YES"}]}}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1010", false},
		{"dynamic selector wildcard", `{"kind":"[*]","connection":[{"code":"YES"}]}`,
			[]string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}, "1000", false},
		{"dynamic selector empty array abort", `{"before":"first","kind":[],"connection":{},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "0100", false},
		{"dynamic selector null array abort", `{"before":"first","kind":[null],"connection":{},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
		{"dynamic selector spaces abort", `{"before":"first","kind":"key with spaces","connection":{},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1010", false},
		{"dynamic selector map comma abort", `{"before":"first","kind":{"a":"x","b":"y"},"connection":{},"after":"last"}`,
			[]string{"before => before", "connection.{kind}.code => selected", "after => after"}, map[string]string{"before":"text","selected":"text","after":"text"}, "1000", false},
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


 // Differentially test the inputs most likely to differ across JSON parsers:
 // missing and mistyped fields, non-object roots, and malformed JSON. This is
 // intentionally a synthetic corpus, not a copy of production Kafka records.
 func TestMappingInputShapeParity(t *testing.T) {
 	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
 		t.Skip("requires compiled StreamsCompat and the Java worker JAR")
 	}
 	inputs := []struct{ name, raw string }{
 		{"empty object", `{}`},
 		{"empty input", ""},
 		{"whitespace only", " \t "},
 		{"truncated object", `{"items":`},
 		{"trailing comma", `{"items":[1,]}`},
 		{"single quotes", `{'items':['One']}`},
 		{"invalid suffix", `{"items":["A"]} junk`},
 		{"null root", `null`},
 		{"number root", `42`},
 		{"string root", `"hello"`},
 		{"boolean root", `true`},
 		{"empty array root", `[]`},
 		{"object array root", `[{"items":["A"]}]`},
 		{"null field", `{"items":null}`},
 		{"number field", `{"items":42}`},
 		{"boolean field", `{"items":true}`},
 		{"string field", `{"items":"hello"}`},
 		{"empty array field", `{"items":[]}`},
 		{"mixed array field", `{"items":["One",5,null,{},["Two"]]}`},
 		{"object field", `{"items":{"first":"One","second":"Two"}}`},
 		{"nested object field", `{"items":[{"name":"One"},{"name":"Two"}]}`},
 		{"duplicate object field", `{"items":{"name":"Old","name":"New"}}`},
 		{"object with exponent", `{"items":{"n":1.5e1}}`},
 		{"nested null", `{"groups":[{"people":null}]}`},
 		{"nested mixed", `{"groups":[{"people":[{"name":"Ann"},null,{"other":"x"}]},null]}`},
 		{"dynamic null", `{"kind":null,"connection":{}}`},
 		{"dynamic object", `{"kind":{"nested":"key"},"connection":{}}`},
 		{"dynamic array", `{"kind":["first"],"connection":{}}`},
 		{"dynamic exponent", `{"kind":2.5e1,"connection":{"25":{"0":{"code":"yes"}}}}`},
 	}
 	ruleSets := []struct { name string; rules []string; fields map[string]string }{
 		{"single wildcard", []string{"items[*] => selected"}, map[string]string{"selected":"text"}},
 		{"wildcard descendant", []string{"items[*].name => selected"}, map[string]string{"selected":"text"}},
 		{"nested wildcards", []string{"groups[*].people[*].name => selected"}, map[string]string{"selected":"text"}},
 		{"dynamic field", []string{"connection.{kind}.code => selected"}, map[string]string{"selected":"text"}},
 	}
 	for _, rules := range ruleSets {
 		for _, mode := range []string{"1000","0100","1010","0010"} {
 			t.Run(rules.name+"/"+mode,func(t *testing.T) {
 				m := newMapper(rules.rules,rules.fields,mode)
 				defer m.reference.close()
 				fallbackNames := make([]string, 0)
 				for _,tc := range inputs {
 					t.Run(tc.name,func(t *testing.T) {
 						before := m.referenceCalls.Load()
 						mapped,output,err := m.transform(tc.raw)
 						if m.referenceCalls.Load() > before {
 							fallbackNames = append(fallbackNames, tc.name)
 						}
 						wantMapped,wantOutput,referenceErr := m.referenceMap(tc.raw)
 						if (err != nil)!=(referenceErr != nil) {
 							t.Fatalf("native error %v, Java error %v",err,referenceErr)
 						}
 						if err != nil {
 							return
 						}
 						gotJSON,err := json.Marshal(mapped)
 						if err != nil { t.Fatal(err) }
 						wantJSON,err := json.Marshal(wantMapped)
 						if err != nil { t.Fatal(err) }
 						outputsEqual := output == wantOutput
 						if json.Valid([]byte(output)) && json.Valid([]byte(wantOutput)) {
 							outputsEqual = sameFixtureJSON(output,wantOutput)
 						}
 						if !sameFixtureJSON(string(gotJSON),string(wantJSON)) || !outputsEqual {
 							t.Fatalf("Java/Go mismatch on synthetic %s: Go mapped=%s output=%q; Java mapped=%s output=%q",tc.name,string(gotJSON),output,string(wantJSON),wantOutput)
 						}
 					})
 				}
 				if len(fallbackNames) > 0 {
					t.Fatalf("%d/%d inputs still required Java fallback: %v", len(fallbackNames), len(inputs), fallbackNames)
				}
				t.Logf("%d/%d input shapes handled natively with Java output/error parity",len(inputs),len(inputs))
 			})
 		}
 	}
 }
 

 // Malformed documents must follow the Java behavior without starting the
// compatibility bridge: strict input errors remain errors, while JSON-smart's
// lenient forms and non-object roots are handled in the supported modes.
func TestStrictNativeMalformedDocuments(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	for _, mode := range []string{"1000", "0100", "1010", "0010", "0011"} {
		for _, tc := range []struct {
			raw string
			fails bool
		}{
			{"", true}, {"null", true}, {`{"truncated":`, true},
			{" \t \n", mode[2] == '1'},
			{`{"title":1,}`, mode[2] == '1'},
			{`{'title':'ok'}`, mode[2] == '1'},
			{`{"title":"ok"} trailing`, mode[2] == '1'},
			{`42`, mode[2] == '1'},
			{`"hello"`, mode[2] == '1'},
			{`true`, mode[2] == '1'},
			{`[{"title":"ok"}]`, mode[2] == '1'},
		} {
			t.Run(mode+"/"+strconv.Quote(tc.raw),func(t *testing.T) {
				m := newMapper([]string{"title => title"}, map[string]string{"title":"text"}, mode)
				defer m.reference.close()
				_, _, err := m.transform(tc.raw)
				if (err != nil) != tc.fails {
					t.Fatalf("mode %s: expected error=%t, got %v", mode, tc.fails, err)
				}
				if m.reference.cmd != nil || m.referenceCalls.Load() != 0 {
					t.Fatal("native-only mapping launched Java")
				}
			})
		}
	}
}

// Literal Unicode JSONPath keys are common in imported customer documents.
// Compare both the mapped/PQ payload and Kafka output with the legacy Java
// transformer without starting Java on the actual native transform.
func TestUnicodeLiteralMappingParity(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires the Java compatibility oracle")
	}
	cases := []struct {
		name, input, rule, fieldType string
	}{
		{"Cyrillic nested", `{"имя":{"название":"Привет"}}`, "имя.название => selected", "text"},
		{"accented key", `{"café":"lait"}`, "café => selected", "text"},
		{"Han nested", `{"漢":{"字":"値"}}`, "漢.字 => selected", "text"},
		{"Cyrillic wildcard", `{"пункты":[{"имя":"А"},{"имя":"Б"}]}`, "пункты[*].имя => selected", "text"},
		{"Unicode selector source", `{"тип":"http","connection":{"http":{"code":"ok"}}}`, "connection.{тип}.code => selected", "text"},
		{"Unicode URL field", `{"ссылка":"https://example.com/path"}`, "ссылка => selected", "url"},
	}
	for _,tc := range cases {
		for _,mode := range []string{"1000","0100","1010","0010"} {
			t.Run(tc.name+"/"+mode,func(t *testing.T) {
				m := newMapper([]string{tc.rule},map[string]string{"selected":tc.fieldType},mode)
				defer m.reference.close()
				if m.needsReference {
					t.Fatal("Unicode literal key incorrectly requires Java")
				}
				gotMapped,gotOutput,err := m.transform(tc.input)
				if err != nil { t.Fatal(err) }
				if m.reference.cmd != nil {
					t.Fatal("native Unicode mapping started Java")
				}
				wantMapped,wantOutput,err := m.referenceMap(tc.input)
				if err != nil { t.Fatal(err) }
				got,err := json.Marshal(gotMapped)
				if err != nil { t.Fatal(err) }
				want,err := json.Marshal(wantMapped)
				if err != nil { t.Fatal(err) }
				if !sameFixtureJSON(string(got),string(want)) || !sameFixtureJSON(gotOutput,wantOutput) {
					t.Fatalf("Unicode literal mapping differs: Go mapped=%s output=%q; Java mapped=%s output=%q",got,gotOutput,want,wantOutput)
				}
			})
		}
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
