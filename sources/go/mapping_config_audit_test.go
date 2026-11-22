package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// Optional offline audit for real rule configurations. No input documents and
// no Java process are required. Neither expressions nor field names are
// written to the result, only the indices of unsupported rules.
func TestMappingConfigurationAudit(t *testing.T) {
	input := os.Getenv("STREAMS_MAPPING_CONFIG_AUDIT_INPUT")
	if input == "" {
		t.Skip("set STREAMS_MAPPING_CONFIG_AUDIT_INPUT to audit local rules")
	}
	document, err := os.ReadFile(input)
	if err != nil {
		t.Fatal(err)
	}
	var configs []struct {
		Rules  []string          `json:"rules"`
		Fields map[string]string `json:"fields"`
		Output string            `json:"output"`
	}
	if err := json.Unmarshal(document, &configs); err != nil {
		t.Fatal(err)
	}
	if len(configs) == 0 {
		t.Fatal("configuration audit has no rule sets")
	}
	type entry struct {
		ProfileIndex       int                    `json:"profile_index"`
		OutputMode         string                 `json:"output_mode"`
		RuleCount          int                    `json:"rule_count"`
		StaticNative       bool                   `json:"static_native"`
		Unsupported        []mappingFallbackIssue `json:"unsupported,omitempty"`
		RuntimeParityKnown bool                   `json:"runtime_parity_known"`
	}
	report := struct {
		SchemaVersion int     `json:"schema_version"`
		Scope         string  `json:"scope"`
		Profiles      []entry `json:"profiles"`
	}{SchemaVersion: 1, Scope: "static-compilation-only"}
	var unsupported int
	for i, c := range configs {
		if c.Output == "" {
			c.Output = "1000"
		}
		if c.Fields == nil {
			c.Fields = map[string]string{}
		}
		m := newMapper(c.Rules, c.Fields, c.Output)
		summary := m.fallbackAudit.snapshot()
		m.reference.close()
		item := entry{
			ProfileIndex: i, OutputMode: c.Output, RuleCount: len(c.Rules),
			StaticNative: !m.needsReference,
			Unsupported: summary.CompileIssues,
			RuntimeParityKnown: false,
		}
		unsupported += len(item.Unsupported)
		report.Profiles = append(report.Profiles, item)
	}
	if output := os.Getenv("STREAMS_MAPPING_CONFIG_AUDIT_OUTPUT"); output != "" {
		if err := os.MkdirAll(filepath.Dir(output), 0755); err != nil {
			t.Fatal(err)
		}
		data, err := json.MarshalIndent(report, "", "  ")
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(output, append(data, '\n'), 0644); err != nil {
			t.Fatal(err)
		}
	}
	t.Logf("analyzed %d rule profiles; %d statically unsupported paths (runtime parity not tested)",
		len(configs), unsupported)
}
