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
	var a, b any
	if err := json.Unmarshal([]byte(got), &a); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(want), &b); err != nil {
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
	}{
		{"rename wildcard", `{"items":[{"name":"Ann","keep":1},{"name":"Bob","keep":2}],"meta":3}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected": "text"}, "1010"},
		{"scalar wildcard", `{"items":[1,true,null,"hello"]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000"},
		{"nested wildcard objects", `{"items":[{"id":"one"},{"id":"two"}]}`,
			[]string{"items[*] => selected"}, map[string]string{"selected": "text"}, "1000"},
		{"original wildcard", `{"items":[{"name":"Ann"},{"name":"Bob"}]}`,
			[]string{"items[*].name => selected"}, map[string]string{"selected": "text"}, "0100"},
		{"rename filtered path", `{"groups":[{"people":[{"name":"Eve","active":true},{"name":"Bob","active":false}]}]}`,
			[]string{"groups[*].people[?(@.active == true)].name => selected"}, map[string]string{"selected": "text"}, "1010"},
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
			if m.reference.cmd != nil {
				t.Fatal("native mapping unexpectedly started Java")
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
