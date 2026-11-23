package main

import (
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// Fallback telemetry must expose a bounded reason label but never reveal
// customer JSON, field names or transform rule expressions.
func TestMappingFallbackPrometheusMetric(t *testing.T) {
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	metrics := newMetrics(config{
		label: "audit", group: "audit", inputTopic: "fixture",
	}, time.Unix(1000, 0))
	mapper := newMapper([]string{"before => before", "connection.{kind!bang}.code => selected"},
		map[string]string{"before": "text", "selected": "text"}, "1000")
	defer mapper.reference.close()
	mapper.fallbackAudit.setObserver(metrics.recordMappingFallback)
	_, _, err := mapper.transform(`{"before":"yes"}`)
	if err == nil {
		t.Fatal("invalid mapping unexpectedly succeeded in native mode")
	}
	if mapper.referenceCalls.Load() != 1 || mapper.reference.cmd != nil {
		t.Fatalf("strict mapper should count, not launch, Java fallback")
	}
	recorder := httptest.NewRecorder()
	metrics.handler().ServeHTTP(recorder, httptest.NewRequest("GET", "/metrics", nil))
	if recorder.Code != 200 {
		t.Fatalf("Prometheus endpoint: %d: %s", recorder.Code, recorder.Body.String())
	}
	var found bool
	for _, line := range strings.Split(recorder.Body.String(), "\n") {
		if strings.HasPrefix(line, "worker_mapping_java_fallback_total{") &&
			strings.Contains(line, `reason="unsupported_rule"`) &&
			strings.HasSuffix(line, " 1") {
			found = true
		}
		if strings.Contains(line, "kind!bang") || strings.Contains(line, "connection.") {
			t.Fatal("Prometheus metric leaked the transform rule")
		}
	}
	if !found {
		t.Fatalf("expected one unsupported-rule Java fallback in Prometheus metrics: %s", recorder.Body.String())
	}
}
