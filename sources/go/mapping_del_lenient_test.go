package main

import (
	"encoding/json"
	"os"
	"testing"
)

func TestLegacyLenientDELParity(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("Java differential")
	}
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	for _, raw := range []string{"{\"text\":\"a\\n\x7fb\",}", "{'text':'a\\n\x7fb'}", "{\"text\":\"a\\n\x7fb\"} trailing"} {
		m := newMapper([]string{"text => selected"}, map[string]string{"selected": "text"}, "1000")
		mapped, out, e := m.transform(raw)
		if e != nil {
			t.Fatal("native failed")
		}
		cfg, _ := json.Marshal(map[string]any{"rules": []string{"text => selected"}, "fields": map[string]string{"selected": "text"}, "output": "1000"})
		o := quietCorpusOracle("map", string(cfg))
		j, e := o.call(raw)
		o.close()
		m.reference.close()
		if e != nil {
			t.Fatal("oracle failed")
		}
		var pair struct {
			Mapped json.RawMessage `json:"mapped"`
			Output string          `json:"output"`
		}
		json.Unmarshal([]byte(j), &pair)
		b, _ := json.Marshal(mapped)
		if strictCorpusJSON(string(b)) != strictCorpusJSON(string(pair.Mapped)) || strictCorpusJSON(out) != strictCorpusJSON(pair.Output) {
			t.Error("lenient DEL parity mismatch")
		}
	}
}
