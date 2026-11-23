package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// This corpus is entirely synthetic. Reports contain only scenario labels,
// document shapes, fallback reason codes and rule indices, never payloads.
type mappingAuditScenario struct {
	name, shape, input, path, fieldType string
}

type mappingAuditResult struct {
	Scenario      string                 `json:"scenario"`
	Shape         string                 `json:"shape"`
	OutputMode    string                 `json:"output_mode"`
	Classification string                `json:"classification"`
	Reasons       []mappingFallbackIssue `json:"reasons,omitempty"`
	CompileIssues []mappingFallbackIssue `json:"compile_issues,omitempty"`
	Equivalent    bool                   `json:"equivalent"`
	GoError       bool                   `json:"go_error"`
	JavaError     bool                   `json:"java_error"`
}

type mappingAuditTotals struct {
	Cases          int            `json:"cases"`
	Native         int            `json:"native"`
	JavaFallback   int            `json:"java_fallback"`
	Mismatches     int            `json:"mismatches"`
	FallbackReason map[string]int `json:"fallback_reasons"`
}

type mappingAuditReport struct {
	SchemaVersion int                  `json:"schema_version"`
	Source        string               `json:"source"`
	Totals        mappingAuditTotals   `json:"totals"`
	Results       []mappingAuditResult `json:"results"`
}

type mappingGoldenOutcome struct {
	Scenario string `json:"scenario"`
	Mode string `json:"mode"`
	JavaError bool `json:"java_error"`
	Digest string `json:"digest,omitempty"`
}

type mappingGoldenSnapshot struct {
	SchemaVersion int `json:"schema_version"`
	Oracle string `json:"oracle"`
	Cases []mappingGoldenOutcome `json:"cases"`
}

// Canonicalize valid JSON objects for order-independent comparisons; preserve
// invalid/legacy output verbatim, including empty strings. UseNumber keeps
// large integers and decimal tokens exact.
func canonicalGoldenJSON(raw string) string {
	decoder := json.NewDecoder(strings.NewReader(raw))
	decoder.UseNumber()
	var value any
	if decoder.Decode(&value) != nil {
		return raw
	}
	reencoded, err := json.Marshal(value)
	if err != nil {
		return raw
	}
	return string(reencoded)
}

func mappingGoldenDigest(mapped any, output string) (string, error) {
	encoded, err := json.Marshal(mapped)
	if err != nil {
		return "", err
	}
	payload := canonicalGoldenJSON(string(encoded)) + "\n" + canonicalGoldenJSON(output)
	hash := sha256.Sum256([]byte(payload))
	return hex.EncodeToString(hash[:]), nil
}


func TestMappingFallbackAuditInstrumentation(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	m := newMapper([]string{
		"before => before",
		"connection.{kind!bang}.code => selected",
	}, map[string]string{"before": "text", "selected": "text"}, "1000")
	defer m.reference.close()
	if !m.needsReference {
		t.Fatal("expected a statically unsupported mapping rule")
	}
	_, _, err := m.transform(`{"before":"yes"}`)
	if err == nil {
		t.Fatal("strict native mode must reject the unsupported rule")
	}
	if m.reference.cmd != nil {
		t.Fatal("audit started Java in strict native mode")
	}
	summary := m.fallbackAudit.snapshot()
	if summary.Counts[mappingFallbackUnsupportedRule] != 1 ||
		len(summary.CompileIssues) != 1 ||
		summary.CompileIssues[0].RuleIndex != 1 ||
		summary.CompileIssues[0].PathIndex != 0 {
		t.Fatalf("incorrect fallback attribution: %+v", summary)
	}
	for i := 0; i < mappingFallbackSampleLimit+5; i++ {
		m.fallbackAudit.record(mappingFallbackValue, 0, 0)
	}
	summary = m.fallbackAudit.snapshot()
	if len(summary.Samples) != mappingFallbackSampleLimit ||
		summary.Counts[mappingFallbackValue] != uint64(mappingFallbackSampleLimit+5) {
		t.Fatalf("fallback samples are not bounded: %+v", summary)
	}

	native := newMapper([]string{"before => before"}, map[string]string{"before": "text"}, "1000")
	defer native.reference.close()
	if native.requireNativeSupport() != nil {
		t.Fatal("valid rule needs Java")
	}
	if _, _, err := native.transform(`{"before":"yes"}`); err != nil {
		t.Fatal(err)
	}
	if len(native.fallbackAudit.snapshot().Samples) != 0 {
		t.Fatal("native mapping falsely recorded a fallback")
	}
}

// The legacy Java transformer reads only the first three OUTPUT_DOCS bits;
// the fourth bit must not change mapping or Kafka output semantics.
var legacyMappingOutputModes = []string{"1000", "0100", "1010", "0010", "1001", "0101", "1011", "0011"}

func syntheticMappingCases() []mappingAuditScenario {
	return []mappingAuditScenario{
		{"dotted field", "object", `{"before":"yes","item":{"name":"Ann"},"after":"end"}`, "item.name", "text"},
		{"indexed field", "array", `{"items":[{"name":"Ann"},{"name":"Bob"}]}`, "items[0].name", "text"},
		{"wildcard strings", "array", `{"items":[{"name":"Ann"},{"name":"Bob"}]}`, "items[*].name", "text"},
		{"wildcard object", "object", `{"items":{"first":{"name":"Ann"},"last":{"name":"Bob"}}}`, "items[*].name", "text"},
		{"recursive objects", "nested_array", `{"items":[{"profile":{"a":1,"b":2}},{"nested":{"profile":{"c":3}}}]}`, "items[*]..profile", "text"},
		{"filtered object values", "array", `{"items":[{"active":true,"profile":{"first":"Ann","last":"A"}},{"active":false,"profile":{"first":"Bob"}}]}`, "items[*][?(@.active == true)].profile", "text"},
		{"slice", "array", `{"items":[{"name":"Ann"},{"name":"Bob"}]}`, "items[0:2].name", "text"},
		{"union", "array", `{"items":[{"name":"Ann","active":true}]}`, "items[0]['name','active']", "text"},
		{"quoted special key", "object", `{"user...name":"Ann"}`, "['user...name']", "text"},
		{"quoted wildcard key", "object", `{"item[*]":["Ann","Bob"]}`, "['item[*]']", "text"},
		{"dynamic string selector", "nested_object", `{"kind":"http","connection":{"http":{"code":"ok"}}}`, "connection.{kind}.code", "text"},
		{"dynamic object selector", "nested_object", `{"kind":{"a":"b"},"connection":{"{a=b}":{"code":"ok"}}}`, "connection.{kind}.code", "text"},
		{"whole document", "object", `{"items":[1,2.5e1]}`, "whole_document", "json"},
		{"first array element", "array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.first()", "text"},
		{"last array element", "array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.last()", "text"},
		{"first numeric element", "array", `{"before":"first","nums":[1,2,3],"after":"last"}`, "nums.first()", "text"},
		{"first non-array", "scalar_field", `{"before":"first","items":"hello","after":"last"}`, "items.first()", "text"},
		{"first empty array", "array", `{"before":"first","items":[],"after":"last"}`, "items.first()", "text"},
		{"keys of object", "object", `{"before":"first","items":{"a":1,"b":2},"after":"last"}`, "items.keys()", "text"},
		{"keys of array", "array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.keys()", "text"},
		{"concat scalar", "scalar_field", `{"before":"first","items":"hello","after":"last"}`, "items.concat(\"!\")", "text"},
		{"concat string array", "array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.concat(\"!\")", "text"},
		{"length of scalar", "scalar_field", `{"before":"first","items":"hello","after":"last"}`, "items.length()", "text"},
		{"length of object", "object", `{"before":"first","items":{"a":1,"b":2},"after":"last"}`, "items.length()", "text"},
		{"sum numeric array", "array", `{"before":"first","nums":[1,2,3],"after":"last"}`, "nums.sum()", "text"},
		{"min string array abort", "array", `{"before":"first","items":["red","blue"],"after":"last"}`, "items.min()", "text"},
		{"avg floating array", "array", `{"before":"first","nums":[1.5,2.5],"after":"last"}`, "nums.avg()", "text"},
		{"stddev mixed array", "array", `{"before":"first","nums":[1,null,"x"],"after":"last"}`, "nums.stddev()", "text"},
		{"max empty array abort", "array", `{"before":"first","nums":[],"after":"last"}`, "nums.max()", "text"},
		{"URL numeric function", "array", `{"before":"first","nums":[1,2,3],"after":"last"}`, "nums.sum()", "url"},
		{"URL first element", "array", `{"before":"first","items":["https://example.org/a","https://example.net/b"],"after":"last"}`, "items.first()", "url"},

		{"computed index", "array", `{"before":"yes","items":[{"name":"Ann"}],"after":"end"}`, "items[(@.length-1)].name", "text"},
		{"malformed path", "array", `{"before":"yes","items":[{"name":"Ann"}],"after":"end"}`, "items...name", "text"},
		{"unsupported dynamic key", "object", `{"before":"first","kind with spaces":"http","connection":{"http":{"code":"ok"}},"after":"last"}`, "connection.{kind with spaces}.code", "text"},
		{"unrecognized path syntax", "array", `{"before":"first","items":[{"name":"Ann"}],"after":"last"}`, "items[??].name", "text"},
		{"URL ordinary", "object", `{"link":"https://example.org/a?q=1#part"}`, "link", "url"},
		{"URL bad percent", "object", `{"link":"https://example.org/%QQ"}`, "link", "url"},
		{"URL unusual authority", "object", `{"link":"https://a..b.example.org:bad/path"}`, "link", "url"},
		{"URL collection", "array", `{"links":[{"url":"https://example.org/a"}]}`, "links[*].url", "url"},
		{"URL filter", "array", `{"links":[{"active":true,"url":"https://example.org/a"}]}`, "links[?(@.active == true)].url", "url"},
		{"duplicate object keys", "array", `{"items":[{"name":"old","name":"new"}]}`, "items[*].name", "text"},
		{"scalar root", "scalar", `42`, "missing", "text"},
		{"trailing comma", "malformed_json", `{"item":{"name":"Ann"},}`, "item.name", "text"},
	}
}

func TestMappingFallbackAudit(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires original Java mapping implementation as oracle")
	}
	cases := syntheticMappingCases()
	report := mappingAuditReport{
		SchemaVersion: 1,
		Source: "synthetic-java-vs-go",
		Totals: mappingAuditTotals{FallbackReason: make(map[string]int)},
	}
	golden := mappingGoldenSnapshot{SchemaVersion: 1, Oracle: "streams-legacy-java-2026", Cases: make([]mappingGoldenOutcome, 0, len(cases)*len(legacyMappingOutputModes))}
	for _, tc := range cases {
		for _, mode := range legacyMappingOutputModes {
			fields := map[string]string{"before":"text", "selected":tc.fieldType, "after":"text"}
			rules := []string{"before => before", tc.path+" => selected", "after => after"}
			m := newMapper(rules, fields, mode)
			mapped, output, goErr := m.transform(tc.input)
			metrics := m.fallbackAudit.snapshot()
			wantMapped, wantOutput, javaErr := m.referenceMap(tc.input)
			m.reference.close()
			goldenCase := mappingGoldenOutcome{Scenario: tc.name, Mode: mode, JavaError: javaErr != nil}
			if javaErr == nil {
				var digestErr error
				goldenCase.Digest, digestErr = mappingGoldenDigest(wantMapped, wantOutput)
				if digestErr != nil { t.Fatal(digestErr) }
			}
			golden.Cases = append(golden.Cases, goldenCase)
			equivalent := (goErr == nil) == (javaErr == nil)
			if equivalent && goErr == nil {
				gotJSON, firstErr := json.Marshal(mapped)
				wantJSON, secondErr := json.Marshal(wantMapped)
				equivalent = firstErr == nil && secondErr == nil &&
					sameFixtureJSON(string(gotJSON), string(wantJSON))
				if equivalent {
					if json.Valid([]byte(output)) && json.Valid([]byte(wantOutput)) {
						equivalent = sameFixtureJSON(output, wantOutput)
					} else {
						equivalent = output == wantOutput
					}
				}
			}
			item := mappingAuditResult{
				Scenario: tc.name, Shape: tc.shape, OutputMode: mode,
				Reasons: metrics.Samples, CompileIssues: metrics.CompileIssues,
				Equivalent: equivalent, GoError: goErr != nil, JavaError: javaErr != nil,
				Classification: "native",
			}
			report.Totals.Cases++
			if len(metrics.Samples) != 0 {
				item.Classification = "java_fallback"
				report.Totals.JavaFallback++
				for reason, count := range metrics.Counts {
					report.Totals.FallbackReason[string(reason)] += int(count)
				}
			} else {
				report.Totals.Native++
			}
			if !equivalent {
				report.Totals.Mismatches++
			}
			report.Results = append(report.Results, item)
		}
	}
	if output := os.Getenv("STREAMS_MAPPING_AUDIT_OUTPUT"); output != "" {
		if err := os.MkdirAll(filepath.Dir(output), 0755); err != nil {
			t.Fatal(err)
		}
		document, err := json.MarshalIndent(report, "", "  ")
		if err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(output, append(document, '\n'), 0644); err != nil {
			t.Fatal(err)
		}
	}
	if path := os.Getenv("STREAMS_MAPPING_GOLDEN_OUTPUT"); path != "" {
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil { t.Fatal(err) }
		data, err := json.MarshalIndent(golden, "", "  ")
		if err != nil { t.Fatal(err) }
		if err := os.WriteFile(path, append(data, '\n'), 0644); err != nil { t.Fatal(err) }
	}
	t.Logf("synthetic fallback audit: %d cases, %d native, %d Java fallbacks, %d mismatches; reasons=%v",
		report.Totals.Cases, report.Totals.Native, report.Totals.JavaFallback,
		report.Totals.Mismatches, report.Totals.FallbackReason)
	if report.Totals.Mismatches != 0 {
		t.Fatalf("Go and Java disagree in %d synthetic audit cases; inspect the audit artifact", report.Totals.Mismatches)
	}
	if report.Totals.JavaFallback != 0 {
		t.Fatalf("%d synthetic audit cases still require the Java bridge; inspect the audit artifact", report.Totals.JavaFallback)
	}
}
