package main

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"testing"
	"time"
)

type observedBatch struct {
	docs, matched, sent int
	rules               map[string]int
}

func (*observedBatch) observe(string, time.Duration) {}
func (o *observedBatch) processed(_ context.Context, docs, matched, sent int, rules map[string]int, _ time.Duration) {
	o.docs += docs
	o.matched += matched
	o.sent += sent
	o.rules = rules
}

func TestOutputMatchesAndSnippets(t *testing.T) {
	const first = `{"id":"FIRST","text":"Word's & Place"}`
	const second = `{"id":"SECOND","large":9007199254740993}`
	stats := &observedBatch{}
	var output, commands []string
	p := batchProcessor{
		config: config{output: "0011", maxMessage: 1 << 20}, metrics: stats,
		mapDoc: func(raw string) (any, string, error) { return json.RawMessage(raw), raw, nil },
		query: func(_ context.Context, sql string) ([]map[string]any, error) {
			commands = append(commands, sql)
			switch {
			case strings.HasPrefix(sql, "CALL PQ"):
				return []map[string]any{
					{"id": json.Number("9007199254740999"), "documents": "2,1", "query": "word's", "filters": "date>0", "tags": `{"tag":"T","externalquery":"original","highlighting":true}`},
					{"id": json.Number("2"), "documents": "2", "query": "second", "filters": "f", "tags": ""},
				}, nil
			case strings.HasPrefix(sql, "CALL SNIPPETS"):
				return []map[string]any{{"snippet": `{"h":"second"}`}, {"snippet": `{"h":"first"}`}}, nil
			}
			t.Fatalf("unexpected command %s", sql)
			return nil, nil
		},
		publish: func(_ context.Context, docs []string) error { output = append(output, docs...); return nil },
	}
	if err := p.process(context.Background(), []string{first, second}); err != nil {
		t.Fatal(err)
	}
	if len(commands) != 2 || !strings.Contains(commands[0], "word\\'s & place") || strings.Contains(commands[0], "FIRST") {
		t.Fatalf("unexpected commands: %v", commands)
	}
	if len(output) != 4 || output[0] != `{"h":"first"}` || output[2] != `{"h":"second"}` {
		t.Fatalf("unexpected output: %s", output)
	}
	var doc struct {
		Large   json.Number
		Matches []map[string]json.RawMessage `json:"matching_queries"`
	}
	if err := json.Unmarshal([]byte(output[3]), &doc); err != nil {
		t.Fatal(err)
	}
	if doc.Large != "9007199254740993" || len(doc.Matches) != 2 ||
		string(doc.Matches[0]["queryid"]) != "9007199254740999" || string(doc.Matches[0]["query"]) != `"original"` ||
		doc.Matches[0]["filters"] != nil || string(doc.Matches[1]["filters"]) != `"f"` {
		t.Fatalf("unexpected match information: %s", output[3])
	}
	if stats.docs != 2 || stats.matched != 2 || stats.sent != 4 || stats.rules["9007199254740999"] != 2 {
		t.Fatalf("unexpected metrics: %+v", stats)
	}
}

func TestSplitQueryPreservesOrderAndDocumentNumbers(t *testing.T) {
	var output []string
	p := batchProcessor{config: config{output: "0010", maxMessage: 100}, metrics: &observedBatch{},
		mapDoc: func(s string) (any, string, error) { return s, s, nil },
		query: func(_ context.Context, sql string) ([]map[string]any, error) {
			if strings.Contains(sql, `","`) {
				return nil, errors.New("Packet for query is too large")
			}
			return []map[string]any{{"id": "1", "documents": "1", "tags": "{}"}}, nil
		},
		publish: func(_ context.Context, docs []string) error { output = append(output, docs...); return nil },
		jslt:    func(s string) (string, error) { return strings.ToUpper(s), nil },
	}
	if err := p.process(context.Background(), []string{"one", "two", "three"}); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(output, []string{"ONE", "TWO", "THREE"}) {
		t.Fatalf("output: %v", output)
	}
}

func TestQueryFailureInterruptsDelivery(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	p := batchProcessor{config: config{output: "0010", maxMessage: 100}, metrics: &observedBatch{},
		mapDoc: func(s string) (any, string, error) { return s, s, nil },
		query: func(_ context.Context, sql string) ([]map[string]any, error) {
			cancel()
			return nil, errors.New("server offline")
		},
		publish: func(context.Context, []string) error { t.Fatal("published failed input"); return nil },
	}
	defer cancel()
	if err := p.process(ctx, []string{"one"}); err == nil {
		t.Fatal("query failure was lost")
	}
}

// Expected values come from JsonIterator.deserialize(tags).asMap().get("highlighting").toBoolean().
func TestLegacyHighlightingTags(t *testing.T) {
	for _, tc := range []struct {
		input string
		want  bool
	}{
		{`true`, true}, {`false`, false}, {`null`, false},
		{`0`, false}, {`-1`, true}, {`-0.0`, false}, {`0.01`, true}, {`1e-400`, true},
		{`0.1e-400`, false}, {`1.0e400`, true}, {`9223372036854775807`, true},
		{`""`, false}, {`" \t\r\n"`, false}, {`"false"`, false}, {`"False"`, true},
		{`" false "`, true}, {`"0"`, true}, {`"true"`, true}, {`"\u00a0"`, true},
		{`[]`, false}, {`[false]`, true}, {`{}`, false}, {`{"x":0}`, true},
	} {
		_, got, err := decodeRule(map[string]any{"id": "7", "tags": `{"highlighting":` + tc.input + `}`})
		if err != nil || got != tc.want {
			t.Errorf("highlighting %s: got %v, %v; want %v", tc.input, got, err, tc.want)
		}
	}
	_, _, err := decodeRule(map[string]any{"id": "7", "tags": `{"highlighting":9223372036854775808}`})
	if err == nil {
		t.Error("expected overflowing integer tag to fail as in jsoniter")
	}
}
