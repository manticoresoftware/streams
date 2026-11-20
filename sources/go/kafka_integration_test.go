package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/twmb/franz-go/pkg/kfake"
	"github.com/twmb/franz-go/pkg/kgo"
	"github.com/twmb/franz-go/pkg/kmsg"
)

// A sparse stream must emit its first result without waiting for the normal
// 10-second partial-batch flush, even when input and output are separate brokers.
func TestKafkaFirstOutputAndCommittedOffset(t *testing.T) {
	input := kfake.MustCluster(kfake.NumBrokers(1))
	defer input.Close()
	output := kfake.MustCluster(kfake.NumBrokers(1), kfake.SeedTopics(1, "out"))
	defer output.Close()
	var queries atomic.Int32
	search := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		queries.Add(1)
		cmd, err := io.ReadAll(r.Body)
		if err != nil || r.URL.Path != "/cli_json" || !strings.Contains(string(cmd), `[{"id":"hello"}]`) {
			t.Errorf("unexpected query: %s, %v", cmd, err)
		}
		fmt.Fprint(w, `[{"data":[{"id":1,"documents":"1","tags":"{}","query":"hello"}]}]`)
	}))
	defer search.Close()

	c, err := readConfig()
	if err != nil {
		t.Fatal(err)
	}
	c.inputHost, c.outputHost = input.ListenAddrs()[0], output.ListenAddrs()[0]
	c.group, c.inputTopic, c.outputTopic = "worker", "in", "out"
	c.maxBatchSize, c.batchSize = 100, 50
	c.searchURL, c.output, c.jslt = search.URL, "1000", ""
	c.rules, c.fields = []string{"id=>id"}, map[string]string{"id": "text"}
	m := newMetrics(c, time.Now())

	// A fresh group starts at the end of the topic. Wait for its first Fetch
	// before inserting the record, so the reset offset is deterministic.
	var once sync.Once
	fetching := make(chan struct{})
	input.ControlKey(int16(kmsg.Fetch), func(kmsg.Request) (kmsg.Response, error, bool) {
		once.Do(func() { close(fetching) })
		return nil, nil, false
	})
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	startWorker := func(m *metrics) func() {
		workerCtx, stop := context.WithCancel(ctx)
		done := make(chan struct{})
		go func() {
			defer close(done)
			if err := runWorker(workerCtx, c, m); err != nil && !errors.Is(err, context.Canceled) {
				t.Errorf("worker: %v", err)
			}
		}()
		return func() {
			stop()
			select {
			case <-done:
			case <-time.After(5 * time.Second):
				t.Fatal("worker did not stop")
			}
		}
	}
	stopWorker := startWorker(m)
	defer stopWorker()
	select {
	case <-fetching:
	case <-ctx.Done():
		t.Fatal(ctx.Err())
	}
	group := input.GroupInfo(c.group)
	if m.isReady() || group == nil || group.NumAssigned() != 4 {
		t.Fatalf("unexpected assignment: %+v", group)
	}
	sender, err := kgo.NewClient(kgo.SeedBrokers(c.inputHost), kgo.RecordPartitioner(kgo.ManualPartitioner()))
	if err != nil {
		t.Fatal(err)
	}
	defer sender.Close()
	record := &kgo.Record{Topic: c.inputTopic, Value: []byte(`{"id":"Hello"}`)}
	if err := sender.ProduceSync(ctx, record).FirstErr(); err != nil {
		t.Fatal(err)
	}
	reader, err := kgo.NewClient(kgo.SeedBrokers(c.outputHost), kgo.ConsumePartitions(map[string]map[int32]kgo.Offset{
		c.outputTopic: {0: kgo.NewOffset().AtStart()},
	}))
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	// Both broker and input are ready. Search may still be starting.
	checkHeld := func(m *metrics, expectedQueries int32, committed int64) {
		t.Helper()
		// Wait for the poll to return before checking the gated batch.
		for {
			families, err := m.registry.Gather()
			if err != nil {
				t.Fatal(err)
			}
			polled := false
			for _, f := range families {
				if f.GetName() == "worker_kafka_consume_time" && f.GetMetric()[0].GetHistogram().GetSampleCount() > 0 {
					polled = true
				}
			}
			if polled {
				break
			}
			select {
			case <-ctx.Done():
				t.Fatal(ctx.Err())
			case <-time.After(10 * time.Millisecond):
			}
		}
		short, cancel := context.WithTimeout(ctx, 100*time.Millisecond)
		got := reader.PollRecords(short, 1)
		cancel()
		if got.NumRecords() != 0 || queries.Load() != expectedQueries {
			t.Fatal("processed input before pq was ready")
		}
		group := input.GroupInfo(c.group)
		if group == nil || group.Commits[c.inputTopic][record.Partition].Offset != committed {
			t.Fatalf("input offset advanced before pq was ready: %+v", group)
		}
	}
	checkHeld(m, 0, 0)
	m.markPQReady()
	if !m.isReady() {
		t.Fatal("worker not ready after assignment and pq startup")
	}
	expectOutput := func() {
		t.Helper()
		early, stop := context.WithTimeout(ctx, 8*time.Second)
		defer stop()
		got := reader.PollRecords(early, 1)
		if errs := got.Errors(); len(errs) > 0 {
			t.Fatalf("waiting for first output: %v", errs)
		}
		if records := got.Records(); len(records) != 1 || string(records[0].Value) != `{"id":"Hello"}` {
			t.Fatalf("unexpected output: %+v", records)
		}
	}
	expectOutput()
	_, err = input.WaitGroupInfo(ctx, c.group, func(info *kfake.GroupInfo) bool {
		return info != nil && info.Commits[c.inputTopic][record.Partition].Offset == record.Offset+1
	})
	if err != nil {
		t.Fatalf("confirm input offset: %v", err)
	}

	// Cancel a new replica while its next record is held. An existing group
	// must resume from its previous checkpoint and produce the held result.
	stopWorker()
	record = &kgo.Record{Topic: c.inputTopic, Partition: record.Partition, Value: []byte(`{"id":"Hello"}`)}
	if err := sender.ProduceSync(ctx, record).FirstErr(); err != nil {
		t.Fatal(err)
	}
	waiting := newMetrics(c, time.Now())
	stopWaiting := startWorker(waiting)
	defer stopWaiting()
	checkHeld(waiting, 1, record.Offset)
	stopWaiting()
	resumed := newMetrics(c, time.Now())
	resumed.markPQReady()
	stopResumed := startWorker(resumed)
	defer stopResumed()
	expectOutput()
	_, err = input.WaitGroupInfo(ctx, c.group, func(info *kfake.GroupInfo) bool {
		return info != nil && info.Commits[c.inputTopic][record.Partition].Offset == record.Offset+1
	})
	if err != nil {
		t.Fatalf("confirm replayed input offset: %v", err)
	}
}
