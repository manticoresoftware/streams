package main

import (
	"encoding/json"
	"os"
	"testing"
)

// Jayway terminal functions have distinct type/error behavior. Compare both
// the mapped/PQ document and Kafka output against the actual legacy worker.
func TestLegacyTerminalFunctionParity(t *testing.T) {
	cases := []struct {
		name, raw, path, fieldType string
	}{
		{"first string", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.first()", "text"},
		{"last string", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.last()", "text"},
		{"first numeric", `{"before":"first","items":[1,2,3],"after":"last"}`, "items.first()", "text"},
		{"first nonarray abort", `{"before":"first","items":"hello","after":"last"}`, "items.first()", "text"},
		{"first empty abort", `{"before":"first","items":[],"after":"last"}`, "items.first()", "text"},
		{"last mixed", `{"before":"first","items":[null,true,"x"],"after":"last"}`, "items.last()", "text"},
		{"keys object", `{"before":"first","items":{"a":1,"b":2},"after":"last"}`, "items.keys()", "text"},
		{"keys array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.keys()", "text"},
		{"keys nil", `{"before":"first","items":null,"after":"last"}`, "items.keys()", "text"},
		{"concat scalar", `{"before":"first","items":"hello","after":"last"}`, "items.concat(\"!\")", "text"},
		{"concat array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.concat(\"!\")", "text"},
		{"concat numbers", `{"before":"first","items":[1,2,3],"after":"last"}`, "items.concat(\"!\")", "text"},
		{"URL first", `{"before":"first","items":["https://example.org/a","https://example.net/b"],"after":"last"}`, "items.first()", "url"},
	}
	for _, tc := range cases {
		for _, mode := range []string{"1000", "0100", "1010", "0010"} {
			t.Run(tc.name+"/"+mode, func(t *testing.T) {
				m := newMapper([]string{"before => before", tc.path+" => selected", "after => after"},
					map[string]string{"before":"text","selected":tc.fieldType,"after":"text"}, mode)
				defer m.reference.close()
				if m.needsReference {
					t.Fatalf("function path %q still needs Java at startup",tc.path)
				}
				mapped, output, goErr := m.transform(tc.raw)
				if m.referenceCalls.Load() != 0 {
					t.Fatalf("function path %q invoked Java in Go transform",tc.path)
				}
				if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" || os.Getenv("STREAMS_NATIVE_ONLY") == "1" {
					if goErr != nil { t.Fatal(goErr) }
					return
				}
				wantMapped,wantOutput,javaErr:=m.referenceMap(tc.raw)
				if (goErr==nil)!=(javaErr==nil) {
					t.Fatalf("Go error %v; Java error %v",goErr,javaErr)
				}
				if goErr!=nil {return}
				got,err:=json.Marshal(mapped)
				if err!=nil {t.Fatal(err)}
				want,err:=json.Marshal(wantMapped)
				if err!=nil {t.Fatal(err)}
				if !sameFixtureJSON(string(got),string(want)) || !sameFixtureJSON(output,wantOutput) {
					t.Fatalf("Go mapped=%s output=%q; Java mapped=%s output=%q",got,output,want,wantOutput)
				}
			})
		}
	}
}
