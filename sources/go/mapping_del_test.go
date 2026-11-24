package main

import (
	"encoding/json"
	"os"
	"testing"
)

func TestLegacyDELWholeDocumentAndOutputModes(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("Java differential")
	}
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	input := "{\"text\":\"a\\n\x7fb\",\"nested\":[{\"v\":\"c\\t\x7fd\"}]}"
	for _, mode := range []string{"0011", "1000", "0100", "1010"} {
		t.Run(mode, func(t *testing.T) {
			rules := []string{"text => selected", "nested[*].v => values", "whole_document => source"}
			fields := map[string]string{"selected": "text", "values": "text", "source": "json"}
			m := newMapper(rules, fields, mode)
			defer m.reference.close()
			mapped, out, e := m.transform(input)
			if e != nil {
				t.Fatal(e)
			}
			cfg, _ := json.Marshal(map[string]any{"rules": rules, "fields": fields, "output": mode})
			o := quietCorpusOracle("map", string(cfg))
			defer o.close()
			j, e := o.call(input)
			if e != nil {
				t.Fatal(e)
			}
			var pair struct {
				Mapped json.RawMessage `json:"mapped"`
				Output string          `json:"output"`
			}
			if json.Unmarshal([]byte(j), &pair) != nil {
				t.Fatal("protocol")
			}
			b, _ := json.Marshal(mapped)
			if strictCorpusJSON(string(b)) != strictCorpusJSON(string(pair.Mapped)) {
				t.Error("mapped differs")
			}
			if strictCorpusJSON(out) != strictCorpusJSON(pair.Output) {
				t.Error("Kafka output differs")
			}
			if m.referenceCalls.Load() != 0 || len(m.fallbackAudit.snapshot().Counts) > 0 {
				t.Error("fallback")
			}
		})
	}
}

func TestLegacyLiteralDELMapping(t *testing.T) {
	for _, tc := range []struct{ name, input, want string }{
		{"literal", "{\"text\":\"a\x7fb\"}", "a\x7fb"},
		{"escaped", `{"text":"a\u007fb"}`, "a\x7fb"},
		{"escaped_slow_path", "{\"text\":\"a\\n\x7fb\"}", "a\nb"},
		{"key", "{\"te\x7fxt\":\"ab\"}", ""},
		{"escaped_key", "{\"te\\u0078\x7ft\":\"ab\"}", "ab"},
		{"before_escape", "{\"text\":\"a\x7fb\\n\"}", "ab\n"},
		{"escaped_del_in_slow_path", "{\"text\":\"a\\u007f\x7fb\"}", "a\x7fb"},
		{"unrelated_escaped_token", "{\"text\":\"a\x7fb\",\"other\":\"c\\nd\"}", "a\x7fb"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			t.Setenv("STREAMS_NATIVE_ONLY", "1")
			m := newMapper([]string{"text => selected"}, map[string]string{"selected": "text"}, "0011")
			defer m.reference.close()
			mapped, output, err := m.transform(tc.input)
			if err != nil {
				t.Fatal("synthetic mapping failed")
			}
			b, _ := json.Marshal(mapped)
			var doc map[string]string
			json.Unmarshal(b, &doc)
			if doc["selected"] != tc.want {
				t.Errorf("literal DEL JSONPath contract differs")
			}
			if strictCorpusJSON(output) != strictCorpusJSON(tc.input) {
				t.Error("Kafka output changed")
			}
			if m.referenceCalls.Load() != 0 {
				t.Error("fallback")
			}
			if os.Getenv("STREAMS_COMPAT_CLASSPATH") != "" {
				cfg, _ := json.Marshal(map[string]any{"rules": []string{"text => selected"}, "fields": map[string]string{"selected": "text"}, "output": "0011"})
				o := quietCorpusOracle("map", string(cfg))
				defer o.close()
				reply, e := o.call(tc.input)
				if e != nil {
					t.Fatal("Java oracle failed")
				}
				var pair struct {
					Mapped json.RawMessage `json:"mapped"`
					Output string          `json:"output"`
				}
				if json.Unmarshal([]byte(reply), &pair) != nil {
					t.Fatal("oracle protocol")
				}
				if strictCorpusJSON(string(b)) != strictCorpusJSON(string(pair.Mapped)) {
					t.Error("Java mapped mismatch")
				}
				if strictCorpusJSON(output) != strictCorpusJSON(pair.Output) {
					t.Error("Java output mismatch")
				}
			}
		})
	}
}
