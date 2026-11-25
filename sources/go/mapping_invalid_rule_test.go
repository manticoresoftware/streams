package main

import (
	"encoding/json"
	"os"
	"testing"
)

// Some invalid Jayway rules abort the entire mapping loop but preserve
// previously mapped fields. Do not delegate these known errors to Java.
func TestMalformedJaywayRuleParity(t *testing.T) {
	cases := []struct {
		name, input, rule string
	}{
		{"invalid dynamic key", `{"before":"first","kind with spaces":"http","connection":{"http":{"code":"ok"}},"after":"last"}`, "connection.{kind with spaces}.code => selected"},
		{"invalid predicate", `{"before":"first","items":[{"name":"Ann"}],"after":"last"}`, "items[??].name => selected"},
	}
	for _, tc := range cases {
		for _, mode := range []string{"1000", "0100", "1010", "0010"} {
			t.Run(tc.name+"/"+mode, func(t *testing.T) {
				m := newMapper([]string{"before => before", tc.rule, "after => after"},
					map[string]string{"before":"text","selected":"text","after":"text"}, mode)
				defer closeMappingOracle(m)
				if m.hasUnsupportedRule {
					t.Fatalf("known invalid Jayway path still needs Java: %s",tc.name)
				}
				mapped, output, err := m.transform(tc.input)
				if err != nil { t.Fatal(err) }
				if mappingUnsupportedCount(m) != 0 {
					t.Fatalf("Java fallback was invoked for %s",tc.name)
				}
				got, err := json.Marshal(mapped)
				if err != nil { t.Fatal(err) }
				if !sameFixtureJSON(string(got), `{"before":"first"}`) {
					t.Fatalf("expected partial mapping: %s",got)
				}
				switch mode {
				case "1000":
					if !sameFixtureJSON(output,`{}`) {t.Fatalf("output %q",output)}
				case "0100":
					if !sameFixtureJSON(output,`{"before":"first"}`) {t.Fatalf("output %q",output)}
				default:
					if !sameFixtureJSON(output,tc.input) {t.Fatalf("output %q",output)}
				}
				if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" || os.Getenv("STREAMS_NATIVE_ONLY") == "1" {
					return
				}
				refMapped, refOutput, err := m.referenceMap(tc.input)
				if err != nil { t.Fatal(err) }
				expected, err := json.Marshal(refMapped)
				if err != nil { t.Fatal(err) }
				if !sameFixtureJSON(string(got), string(expected)) || !sameFixtureJSON(output,refOutput) {
					t.Fatalf("Go mapped=%s output=%q; Java mapped=%s output=%q",got,output,expected,refOutput)
				}
			})
		}
	}
}
