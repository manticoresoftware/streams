package main

import (
	"encoding/json"
	"os"
	"testing"
)

// Optional offline check of private mapping configurations against the original
// Java mapper. All input documents are synthetic and remain on the local machine;
// only profile indices and aggregate counts are logged or written.
func TestLocalMappingProfileParity(t *testing.T) {
	input := os.Getenv("STREAMS_MAPPING_PARITY_INPUT")
	if input == "" {
		t.Skip("set STREAMS_MAPPING_PARITY_INPUT to run local Java/Go comparisons")
	}
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Fatal("original Java oracle classpath is required")
	}
	t.Setenv("STREAMS_NATIVE_ONLY", "0")
	data, err := os.ReadFile(input)
	if err != nil {
		t.Fatal("cannot read local parity input")
	}
	var profiles []struct {
		Rules     []string          `json:"rules"`
		Fields    map[string]string `json:"fields"`
		Output    string            `json:"output"`
		Documents []string          `json:"documents"`
	}
	if json.Unmarshal(data, &profiles) != nil || len(profiles) == 0 {
		t.Fatal("invalid or empty local parity input")
	}
	type summary struct {
		Index         int `json:"profile_index"`
		Documents     int `json:"documents"`
		JavaErrors    int `json:"java_errors"`
		JavaFallbacks int `json:"java_fallbacks"`
		Mismatches    int `json:"mismatches"`
	}
	report := struct {
		SchemaVersion int       `json:"schema_version"`
		Scope         string    `json:"scope"`
		Profiles      []summary `json:"profiles"`
	}{SchemaVersion: 1, Scope: "local-synthetic-java-go-parity"}
	for i, profile := range profiles {
		item := summary{Index: i}
		if len(profile.Documents) == 0 {
			t.Errorf("profile %d has no synthetic inputs", i)
			report.Profiles = append(report.Profiles, item)
			continue
		}
		m := newMapper(profile.Rules, profile.Fields, profile.Output)
		if m.needsReference {
			item.JavaFallbacks++
		} else {
			for docIndex, raw := range profile.Documents {
				before := m.referenceCalls.Load()
				gotMapped, gotOutput, gotErr := m.transform(raw)
				if m.referenceCalls.Load() != before {
					item.JavaFallbacks++
				}
				javaMapped, javaOutput, javaErr := m.referenceMap(raw)
				item.Documents++
				if javaErr != nil {
					item.JavaErrors++
				}
				same := (gotErr == nil) == (javaErr == nil)
				if same && gotErr == nil {
					gotDigest, e1 := mappingGoldenDigest(gotMapped, gotOutput)
					javaDigest, e2 := mappingGoldenDigest(javaMapped, javaOutput)
					same = e1 == nil && e2 == nil && gotDigest == javaDigest
				}
				if !same {
					item.Mismatches++
					t.Errorf("profile %d / synthetic input %d: Java/Go mismatch (payload omitted)", i, docIndex)
				}
			}
		}
		m.reference.close()
		if item.JavaFallbacks != 0 {
			t.Errorf("profile %d: %d Java fallbacks", i, item.JavaFallbacks)
		}
		report.Profiles = append(report.Profiles, item)
	}
	if path := os.Getenv("STREAMS_MAPPING_PARITY_OUTPUT"); path != "" {
		encoded, err := json.MarshalIndent(report, "", "  ")
		if err != nil {
			t.Fatal("cannot encode parity report")
		}
		if err := os.WriteFile(path, append(encoded, '\n'), 0600); err != nil {
			t.Fatal("cannot write parity report")
		}
	}
	t.Logf("compared %d local mapping profiles against original Java; no source expressions or documents logged", len(profiles))
}
