package main

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

func TestAuditWorkerMapping(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	t.Setenv("OUTPUT_DOCS", "0011")
	t.Setenv("MANTICORE_FIELDS", "text=before|text=selected")
	t.Setenv("TRANSFORM_RULES", "before => before|connection.{private!key}.code => selected")

	var output bytes.Buffer
	if err := auditWorkerMapping(&output); err != nil {
		t.Fatal(err)
	}
	var report mappingConfigAuditReport
	if err := json.Unmarshal(output.Bytes(), &report); err != nil {
		t.Fatal(err)
	}
	if report.SchemaVersion != 1 || report.Scope != "static-compilation-only" || len(report.Profiles) != 1 {
		t.Fatalf("unexpected report metadata: %s", output.String())
	}
	profile := report.Profiles[0]
	if profile.ProfileIndex != 0 || profile.OutputMode != "0011" || profile.RuleCount != 2 ||
		profile.StaticNative || profile.RuntimeParityKnown || len(profile.Unsupported) != 1 ||
		profile.Unsupported[0].RuleIndex != 1 || profile.Unsupported[0].PathIndex != 0 {
		t.Fatalf("unexpected static audit result: %s", output.String())
	}
	if strings.Contains(output.String(), "private!key") || strings.Contains(output.String(), "connection.") ||
		strings.Contains(output.String(), "selected") {
		t.Fatal("audit leaked rule text or field names")
	}

	t.Setenv("TRANSFORM_RULES", "before => before")
	output.Reset()
	report = mappingConfigAuditReport{}
	if err := auditWorkerMapping(&output); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(output.Bytes(), &report); err != nil {
		t.Fatal(err)
	}
	if !report.Profiles[0].StaticNative || len(report.Profiles[0].Unsupported) != 0 ||
		report.Profiles[0].RuntimeParityKnown {
		t.Fatalf("native configuration not recognized: %s", output.String())
	}
}

func TestAuditWorkerMappingRedactsConfigErrors(t *testing.T) {
	t.Setenv("MANTICORE_FIELDS", "secret-production-field")
	var output bytes.Buffer
	err := auditWorkerMapping(&output)
	if err == nil {
		t.Fatal("expected invalid worker configuration")
	}
	if strings.Contains(err.Error(), "secret-production-field") || output.Len() != 0 {
		t.Fatal("audit leaked an invalid configuration value")
	}
}
