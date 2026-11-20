package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestConfigFromPipeline(t *testing.T) {
	t.Setenv("KAFKA_INPUT_TOPIC", "news,articles")
	t.Setenv("MANTICORE_HOST", "[::1]:9306")
	t.Setenv("MANTICORE_HTTP_PORT", "19308")
	t.Setenv("MAX_BATCH_SIZE", "1")
	t.Setenv("TRANSFORM_RULES", "article.title=>title|article.links[*]=>urls")
	t.Setenv("MANTICORE_FIELDS", "text=title|url=urls")
	t.Setenv("PROCESSED_MEASURE_TIME", "60")
	t.Setenv("OUTPUT_DOCS", "0011")
	c, err := readConfig()
	if err != nil {
		t.Fatal(err)
	}
	if c.batchSize != 1 || c.inputTopic != "news,articles" || c.fields["urls"] != "url" || len(c.rules) != 2 || c.measureWindow != time.Minute || c.searchURL != "http://[::1]:19308" || c.output != "0011" {
		t.Fatalf("unexpected configuration: %+v", c)
	}
	t.Setenv("MAX_BATCH_SIZE", "invalid")
	if _, err := readConfig(); err == nil {
		t.Fatal("accepted invalid batch size")
	}
}

func TestCLIResponse(t *testing.T) {
	for _, tc := range []struct {
		body    string
		wantErr bool
		wantID  string
	}{
		{`[{"data":[{"id":9223372036854775807}]}]`, false, "9223372036854775807"},
		{`{"data":[{"id":534}]}`, false, "534"},
		{`[{"data":[]},{"error":"unknown table"}]`, true, ""},
		{`[]`, false, ""},
	} {
		ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path != "/cli_json" || r.Header.Get("Content-Type") != "text/plain" {
				t.Error("invalid Manticore request")
			}
			io.WriteString(w, tc.body)
		}))
		rows, err := queryAt(context.Background(), ts.URL, time.Second, "CALL PQ")
		ts.Close()
		if (err != nil) != tc.wantErr {
			t.Fatalf("body %s error %v", tc.body, err)
		}
		if tc.wantID != "" && (len(rows) != 1 || rows[0]["id"].(json.Number).String() != tc.wantID) {
			t.Fatalf("lost id: %v", rows)
		}
	}
}

func TestScalerAndHealth(t *testing.T) {
	var requests atomic.Int32
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		if r.FormValue("lag") != "17" || r.FormValue("label") != "m1" || r.FormValue("batchSize") != "10" {
			t.Error("unexpected scaler form")
		}
		io.WriteString(w, `{"batchSize":15}`)
	}))
	defer ts.Close()
	c := config{label: "m1", group: "group", inputTopic: "input", minThreads: 1, maxThreads: 3, batchSize: 10, maxBatchSize: 12, scalerHost: strings.TrimPrefix(ts.URL, "http://"), measureWindow: time.Minute}
	m := newMetrics(c, time.Now())
	status := func(path string) int {
		rec := httptest.NewRecorder()
		m.handler().ServeHTTP(rec, httptest.NewRequest("GET", path, nil))
		return rec.Code
	}
	if status("/live") != 200 || status("/ready") != 503 {
		t.Fatal("invalid initial health")
	}
	m.assigned(true)
	if status("/ready") != 200 {
		t.Fatal("assigned worker must be ready")
	}
	m.assigned(false)
	if status("/ready") != 503 {
		t.Fatal("revoked assignment must clear ready")
	}
	m.lag(context.Background(), 17, 4, 10)
	m.updateScaler(context.Background())
	m.updateScaler(context.Background())
	if requests.Load() != 1 {
		t.Fatal("stale lag triggered a second scaling decision")
	}
	if size := m.lag(context.Background(), 17, 5, 10); size != 12 {
		t.Fatalf("returned batch size %d", size)
	}
	m.processed(context.Background(), 7, 2, 3, map[string]int{"123": 2}, time.Second)
	values := map[string]float64{}
	for _, p := range m.collect(time.Now(), true) {
		values[p.Name] = p.Value
	}
	if values["m1_handler_processed_docs"] != 7 || values["m1_handler_processed_rules"] != 2 || values["m1_consumer_lag"] != 17 {
		t.Fatalf("unexpected metrics: %v", values)
	}
}

func TestPHPScalerBatchSize(t *testing.T) {
	for _, tc := range []struct {
		reply string
		want  int
	}{
		{`{"batchSize":"5000"}`, 5000}, {`{"batchSize":2.75}`, 2},
		{`{"batchSize":0}`, 948}, {`{}`, 948},
	} {
		ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { io.WriteString(w, tc.reply) }))
		m := newMetrics(config{batchSize: 948, maxBatchSize: 5000, scalerHost: strings.TrimPrefix(ts.URL, "http://")}, time.Now())
		m.lag(context.Background(), 42, 5, 948)
		m.updateScaler(context.Background())
		ts.Close()
		if m.target != tc.want {
			t.Errorf("%s: batch size %d, want %d", tc.reply, m.target, tc.want)
		}
	}
}
