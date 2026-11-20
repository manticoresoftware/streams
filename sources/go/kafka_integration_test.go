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
	search := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
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
	done := make(chan struct{})
	var workerErr error
	go func() { defer close(done); workerErr = runWorker(ctx, c, m) }()
	defer func() {
		cancel()
		select {
		case <-done:
			if workerErr != nil && !errors.Is(workerErr, context.Canceled) {
				t.Errorf("worker: %v", workerErr)
			}
		case <-time.After(5 * time.Second):
			t.Error("worker did not stop")
		}
	}()
	select {
	case <-fetching:
	case <-ctx.Done():
		t.Fatal(ctx.Err())
	case <-done:
		t.Fatalf("worker stopped: %v", workerErr)
	}
	group := input.GroupInfo(c.group)
	if !m.ready.Load() || group == nil || group.NumAssigned() != 4 {
		t.Fatalf("unexpected assignment: %+v", group)
	}
	sender, err := kgo.NewClient(kgo.SeedBrokers(c.inputHost))
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
	early, stop := context.WithTimeout(ctx, 8*time.Second)
	defer stop()
	got := reader.PollRecords(early, 1)
	if errs := got.Errors(); len(errs) > 0 {
		t.Fatalf("waiting for first output: %v", errs)
	}
	if records := got.Records(); len(records) != 1 || string(records[0].Value) != `{"id":"Hello"}` {
		t.Fatalf("unexpected output: %+v", records)
	}
	_, err = input.WaitGroupInfo(ctx, c.group, func(info *kfake.GroupInfo) bool {
		return info != nil && info.Commits[c.inputTopic][record.Partition].Offset == record.Offset+1
	})
	if err != nil {
		t.Fatalf("confirm input offset: %v", err)
	}
}
