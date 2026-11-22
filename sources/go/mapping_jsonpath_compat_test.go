package main

import (
    "encoding/json"
    "os"
    "testing"
)

// Characterize nontrivial legacy Jayway paths before migrating their behavior.
// Run this probe against the real Java mapper; replace with assertions once
// native semantics are implemented.
func TestLegacyJSONPathCharacterization(t *testing.T) {
    if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
        t.Skip("requires compiled original Java mapper")
    }
    raw := `{"before":"first","after":"last","items":[{"name":"Ann","active":true},{"name":"Bob","active":false}],"groups":{"one":{"name":"N","active":true}},"name":"root","kind":"foo","id":"bar","user":{"foo":{"bar":{"name":"nested"}}},"user.name":"Dot"}`
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
    }
    for _, mode := range []string{"1000", "0100", "1010"} {
        for _, tc := range cases {
            t.Run(mode+"/"+tc.name, func(t *testing.T) {
                m := newMapper([]string{"before => before", tc.path+" => selected", "after => after"},
                   map[string]string{"before":"text","selected":"text","after":"text"}, mode)
                defer m.reference.close()
                mapped, out, err := m.referenceMap(raw)
                if err != nil {
                    t.Logf("JAVA ERROR: path=%q: %v",tc.path,err)
                    return
                }
                encoded, _ := json.Marshal(mapped)
                t.Logf("path=%q: mapped=%s, output=%s",tc.path,encoded,out)
            })
        }
    }
}
