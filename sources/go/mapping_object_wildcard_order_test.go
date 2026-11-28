package main

import (
	"encoding/json"
	"os"
	"testing"
)

// Advanced wildcards over JSON objects must use source key order. Go map
// iteration would otherwise produce different output between invocations.
func TestAdvancedObjectWildcardOrderParity(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires original Java mapper")
	}
	input := `{"groups":{"third":{"active":true,"name":"Third","profile":{"z":1,"a":2}},"first":{"active":true,"name":"First","profile":{"a":3,"z":4}},"second":{"active":true,"name":"Second","profile":{"k":5,"j":6}}}}`
	for _, rule := range []string{
		"groups[*][?(@.active == true)].name => selected",
		"groups[*][?(@.active == true)].profile => selected",
	} {
		for _, mode := range []string{"1000", "0100", "1010", "0010"} {
			t.Run(rule+"/"+mode, func(t *testing.T) {
				m := newMapper([]string{rule}, map[string]string{"selected":"text"}, mode)
				defer closeMappingOracle(m)
				if m.hasUnsupportedRule { t.Fatal("native compilation failed") }
				wantMapped, wantOutput, err := m.referenceMap(input)
				if err != nil { t.Fatal(err) }
				oracle, err := json.Marshal(wantMapped)
				if err != nil { t.Fatal(err) }
				var expected map[string]any
				if json.Unmarshal(oracle, &expected) != nil { t.Fatal("bad reference") }
				if expected["selected"] == nil || expected["selected"] == "" {
					t.Fatalf("Java did not produce a selection for %q: %s", rule, oracle)
				}
				for run := 0; run < 12; run++ {
					previous := mappingUnsupportedCount(m)
					mapped, output, err := m.transform(input)
					if err != nil { t.Fatal(err) }
					if mappingUnsupportedCount(m) != previous { t.Fatal("runtime Java fallback") }
					got, err := json.Marshal(mapped)
					if err != nil { t.Fatal(err) }
					if !sameFixtureJSON(string(got), string(oracle)) ||
						!sameFixtureJSON(output, wantOutput) {
						t.Fatalf("run %d: Go mapped=%s output=%q; Java mapped=%s output=%q",
							run, got, output, oracle, wantOutput)
					}
				}
			})
		}
	}
}
