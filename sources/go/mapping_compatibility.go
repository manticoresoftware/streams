package main

import (
	"fmt"
	"sync"
)

// Only fixed reason codes and rule/path indices are recorded. Neither JSON
// documents nor private mapping rule text appear in errors or audit reports.
type mappingCompatibilityReason string

const (
	mappingCompatUnsupportedRule mappingCompatibilityReason = "unsupported_rule"
	mappingCompatURL             mappingCompatibilityReason = "url_compatibility"
	mappingCompatValue           mappingCompatibilityReason = "value_conversion"
	mappingCompatRename          mappingCompatibilityReason = "rename_delete"
)

type mappingCompatibilityIssue struct {
	Reason    mappingCompatibilityReason `json:"reason"`
	RuleIndex int                        `json:"rule_index"`
	PathIndex int                        `json:"path_index"`
}

type mappingCompatibilitySnapshot struct {
	Counts        map[mappingCompatibilityReason]uint64 `json:"counts"`
	Samples       []mappingCompatibilityIssue           `json:"samples"`
	CompileIssues []mappingCompatibilityIssue           `json:"compile_issues"`
}

type mappingCompatibilityAudit struct {
	mu            sync.Mutex
	counts        map[mappingCompatibilityReason]uint64
	samples       []mappingCompatibilityIssue
	compileIssues []mappingCompatibilityIssue
}

const mappingCompatibilitySampleLimit = 32

func (a *mappingCompatibilityAudit) addCompileIssue(ruleIndex, pathIndex int) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.compileIssues = append(a.compileIssues, mappingCompatibilityIssue{
		Reason: mappingCompatUnsupportedRule, RuleIndex: ruleIndex, PathIndex: pathIndex,
	})
}

func (a *mappingCompatibilityAudit) firstCompileIssue() mappingCompatibilityIssue {
	a.mu.Lock()
	defer a.mu.Unlock()
	if len(a.compileIssues) == 0 {
		return mappingCompatibilityIssue{Reason: mappingCompatUnsupportedRule, RuleIndex: -1, PathIndex: -1}
	}
	return a.compileIssues[0]
}

func (a *mappingCompatibilityAudit) record(reason mappingCompatibilityReason, ruleIndex, pathIndex int) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.counts == nil {
		a.counts = make(map[mappingCompatibilityReason]uint64)
	}
	a.counts[reason]++
	if len(a.samples) < mappingCompatibilitySampleLimit {
		a.samples = append(a.samples, mappingCompatibilityIssue{
			Reason: reason, RuleIndex: ruleIndex, PathIndex: pathIndex,
		})
	}
}

func (a *mappingCompatibilityAudit) snapshot() mappingCompatibilitySnapshot {
	a.mu.Lock()
	defer a.mu.Unlock()
	counts := make(map[mappingCompatibilityReason]uint64, len(a.counts))
	for reason, count := range a.counts {
		counts[reason] = count
	}
	return mappingCompatibilitySnapshot{
		Counts: counts,
		Samples: append([]mappingCompatibilityIssue(nil), a.samples...),
		CompileIssues: append([]mappingCompatibilityIssue(nil), a.compileIssues...),
	}
}

func (m *mapper) unsupportedMapping(reason mappingCompatibilityReason, ruleIndex, pathIndex int) (any, string, error) {
	m.audit.record(reason, ruleIndex, pathIndex)
	return nil, "", fmt.Errorf("unsupported Go mapping value at rule %d path %d (%s)", ruleIndex, pathIndex, reason)
}
