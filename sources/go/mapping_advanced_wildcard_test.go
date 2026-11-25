package main

import (
	"encoding/json"
	"os"
	"testing"
)

// Advanced wildcard selections of object/array values must preserve the
// original Java toString() representation, including source field order.
func TestAdvancedWildcardObjectParity(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires original Java mapper")
	}
	cases := []struct{ name, rule, input string }{
		{"filtered objects", "items[*][?(@.active == true)] => selected", `{"items":[{"name":"Ann","active":true},{"name":"Bob","active":false}],"keep":1}`},
		{"filtered object values", "items[*][?(@.active == true)].profile => selected", `{"items":[{"active":true,"profile":{"a":1,"b":"two"}},{"active":false,"profile":{"c":3}}]}`},
		{"filtered arrays", "items[*][?(@.active == true)].values => selected", `{"items":[{"active":true,"values":[1,2,3]},{"active":false,"values":[9]}]}`},
		{"recursive objects", "items[*]..profile => selected", `{"items":[{"profile":{"a":1,"b":2}},{"nested":{"profile":{"c":3}}}]}`},
		{"repeated equal objects", "items[*][?(@.active == true)].profile => selected", `{"items":[{"active":true,"profile":{"a":1,"b":2}},{"active":true,"profile":{"a":1,"b":2}}]}`},
	}
	for _, tc := range cases {
		for _, mode := range []string{"1000", "0100", "1010", "0010"} {
			t.Run(tc.name+"/"+mode, func(t *testing.T) {
				m := newMapper([]string{tc.rule}, map[string]string{"selected":"text"}, mode)
				defer closeMappingOracle(m)
				if m.hasUnsupportedRule { t.Fatal("Java needed at startup") }
				mapped, output, err := m.transform(tc.input)
				if err != nil { t.Fatal(err) }
				if mappingUnsupportedCount(m) != 0 { t.Fatal("Java fallback invoked") }
				wantMapped, wantOutput, err := m.referenceMap(tc.input)
				if err != nil { t.Fatal(err) }
				got, e1 := json.Marshal(mapped)
				want, e2 := json.Marshal(wantMapped)
				if e1 != nil || e2 != nil { t.Fatalf("marshal: %v / %v",e1,e2) }
				if !sameFixtureJSON(string(got), string(want)) ||
					!sameFixtureJSON(output, wantOutput) {
					t.Fatalf("Go mapped=%s output=%q; Java mapped=%s output=%q",got,output,want,wantOutput)
				}
			})
		}
	}
}
