package main

import (
    "encoding/json"
    "os"
    "testing"
)

// Java differential regression for extended JSONPath, including malformed
// rules, recursive wildcards, filters, functions and original/rename modes.
func TestLegacyJSONPathParity(t *testing.T) {
    if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
        t.Skip("requires compiled original Java mapper")
    }
    raw := `{"before":"first","after":"last","items":[{"name":"Ann","active":true},{"name":"Bob","active":false}],"groups":{"one":{"name":"N","active":true}},"name":"root","kind":"foo","id":"bar","user":{"foo":{"bar":{"name":"nested"}}},"user.name":"Dot","a, b":"Comma","user...name":"Dots","odd[(@key":"Odd","foo[*]":"literal"}
    cases := []struct{ name, path string }{
        {"malformed-triple-descendant", "items...name"},
        {"root-dollar", "$.name"},
        {"double-recursion-wildcard", "items[*]..name"},
        {"recursive-filter", "items..[?(@.active == true)].name"},
        {"dot-wildcard", "items.*.name"},
        {"whitespace-union", "items[0, 1].name"},
        {"simple-slice", "items[0:2].name"},
        {"simple-filter", "items[?(@.active == true)].name"},
        {"function-length", "items.length()"},
        {"function-property", "items.length"},
        {"function-field-length", "items[0].name.length()"},
        {"regex-filter", "items[?(@.name =~ /An.*/i)].name"},
        {"quoted-leading-dollar", "$['items'][0].name"},
        {"field-union", "items[0]['name','active']"},
        {"computed-index", "items[(@.length-1)].name"},
        {"numeric-filter", "items[?(@.active == true)].name"},
        {"multiple-placeholders", "user.{kind}.{id}.name"},
        {"descendant-after-filter", "items[?(@.active == true)]..name"},
        {"nested-filter-after-wildcard", "items[*][?(@.active == true)].name"},
        {"single-quoted-index", "items[0]['name']"},
        {"root-bracket-name", "['user.name']"},
        {"quoted key comma", "['a, b']"},
        {"quoted triple dots", "['user...name']"},
        {"quoted computed index", "['odd[(@key']"},
        {"quoted wildcard literal", "['foo[*]']"},
    }
    for _, mode := range []string{"1000", "0100", "1010"} {
        for _, tc := range cases {
            t.Run(mode+"/"+tc.name, func(t *testing.T) {
                m := newMapper([]string{"before => before", tc.path+" => selected", "after => after"},
                   map[string]string{"before":"text","selected":"text","after":"text"}, mode)
                defer m.reference.close()
                if m.needsReference { t.Fatalf("path %q still requires Java at startup", tc.path) }
                mapped, out, err := m.transform(raw)
                if m.referenceCalls.Load() != 0 { t.Fatalf("path %q fell back to Java", tc.path) }
                wantMapped, wantOutput, javaErr := m.referenceMap(raw)
                if (err != nil) != (javaErr != nil) {
                    t.Fatalf("Go err=%v, Java err=%v", err, javaErr)
                }
                if err != nil { return }
                encoded, e1 := json.Marshal(mapped)
                reference, e2 := json.Marshal(wantMapped)
                if e1 != nil || e2 != nil { t.Fatalf("encode mapped: %v / %v",e1,e2) }
                if !sameFixtureJSON(string(encoded), string(reference)) || !sameFixtureJSON(out, wantOutput) {
                    t.Fatalf("path=%q: Go mapped=%s out=%q; Java mapped=%s out=%q", tc.path, encoded,out,reference,wantOutput)
                }
            })
        }
    }
}
