package main

import "sync"

// The audit records only fallback reasons and rule/path positions; never raw
// Kafka documents, original customer mapping expressions, or selected values.
type mappingFallbackReason string

const (
	mappingFallbackUnsupportedRule mappingFallbackReason = "unsupported_rule"
	mappingFallbackURL             mappingFallbackReason = "url_compatibility"
	mappingFallbackValue           mappingFallbackReason = "value_conversion"
	mappingFallbackRename          mappingFallbackReason = "rename_delete"
)

// Indices refer to the original transform rule and its "&&" component.
type mappingFallbackIssue struct {
	Reason    mappingFallbackReason `json:"reason"`
	RuleIndex int                   `json:"rule_index"`
	PathIndex int                   `json:"path_index"`
}

type mappingFallbackSnapshot struct {
	Counts        map[mappingFallbackReason]uint64 `json:"counts"`
	Samples       []mappingFallbackIssue           `json:"samples"`
	CompileIssues []mappingFallbackIssue           `json:"compile_issues"`
}

// Counts are unbounded, but retain only a bounded set of samples in long-
// running workers; the audit cannot grow with the number of Kafka messages.
type mappingFallbackAudit struct {
	mu            sync.Mutex
	counts        map[mappingFallbackReason]uint64
	samples       []mappingFallbackIssue
	compileIssues []mappingFallbackIssue
}

const mappingFallbackSampleLimit = 32

func (a *mappingFallbackAudit) addCompileIssue(ruleIndex, pathIndex int) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.compileIssues = append(a.compileIssues, mappingFallbackIssue{
		Reason: mappingFallbackUnsupportedRule, RuleIndex: ruleIndex, PathIndex: pathIndex,
	})
}

func (a *mappingFallbackAudit) firstCompileIssue() mappingFallbackIssue {
	a.mu.Lock()
	defer a.mu.Unlock()
	if len(a.compileIssues) == 0 {
		return mappingFallbackIssue{Reason: mappingFallbackUnsupportedRule, RuleIndex: -1, PathIndex: -1}
	}
	return a.compileIssues[0]
}

func (a *mappingFallbackAudit) record(reason mappingFallbackReason, ruleIndex, pathIndex int) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.counts == nil {
		a.counts = make(map[mappingFallbackReason]uint64)
	}
	a.counts[reason]++
	if len(a.samples) < mappingFallbackSampleLimit {
		a.samples = append(a.samples, mappingFallbackIssue{
			Reason: reason, RuleIndex: ruleIndex, PathIndex: pathIndex,
		})
	}
}

func (a *mappingFallbackAudit) snapshot() mappingFallbackSnapshot {
	a.mu.Lock()
	defer a.mu.Unlock()
	counts := make(map[mappingFallbackReason]uint64, len(a.counts))
	for reason, count := range a.counts {
		counts[reason] = count
	}
	samples := append([]mappingFallbackIssue{}, a.samples...)
	issues := append([]mappingFallbackIssue{}, a.compileIssues...)
	return mappingFallbackSnapshot{Counts: counts, Samples: samples, CompileIssues: issues}
}

func (m *mapper) fallbackMap(raw string, reason mappingFallbackReason, ruleIndex, pathIndex int) (any, string, error) {
	m.fallbackAudit.record(reason, ruleIndex, pathIndex)
	return m.referenceMap(raw)
}
