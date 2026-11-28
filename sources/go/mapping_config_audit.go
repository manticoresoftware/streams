package main

import (
	"encoding/json"
	"errors"
	"io"
)

// This is a static check of the rules actually configured for a worker.
// It neither reads Kafka documents nor starts the Java compatibility process.
type mappingConfigAuditProfile struct {
	ProfileIndex       int                    `json:"profile_index"`
	OutputMode         string                 `json:"output_mode"`
	RuleCount          int                    `json:"rule_count"`
	StaticNative       bool                   `json:"static_native"`
	Unsupported        []mappingCompatibilityIssue `json:"unsupported,omitempty"`
	RuntimeParityKnown bool                   `json:"runtime_parity_known"`
}

type mappingConfigAuditReport struct {
	SchemaVersion int                         `json:"schema_version"`
	Scope         string                      `json:"scope"`
	Profiles      []mappingConfigAuditProfile `json:"profiles"`
}

func auditMappingProfile(c config, index int) mappingConfigAuditProfile {
	if c.output == "" {
		c.output = "1000"
	}
	m := newMapper(c.rules, c.fields, c.output)
	return mappingConfigAuditProfile{
		ProfileIndex:       index,
		OutputMode:         c.output,
		RuleCount:          len(c.rules),
		StaticNative:       !m.hasUnsupportedRule,
		Unsupported:        m.audit.snapshot().CompileIssues,
		RuntimeParityKnown: false,
	}
}

func auditWorkerMapping(out io.Writer) error {
	c, err := readConfig()
	if err != nil {
		// Do not echo configuration values, rules, or field names.
		return errors.New("invalid worker configuration")
	}
	report := mappingConfigAuditReport{
		SchemaVersion: 1,
		Scope:         "static-compilation-only",
		Profiles:      []mappingConfigAuditProfile{auditMappingProfile(c, 0)},
	}
	return json.NewEncoder(out).Encode(report)
}
