package main

// Opt-in real-broker integration tests. PQ is stubbed at the SQL boundary
// to make the 128-output flush/error paths deterministic. No production hooks.
import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/twmb/franz-go/pkg/kerr"
	"github.com/twmb/franz-go/pkg/kgo"
	"github.com/twmb/franz-go/pkg/kmsg"
)

type realKafkaFixture struct {
	brokers, input, output, group string
	publisher                     *kgo.Client
	deadline                      context.Context
}

func newRealKafkaFixture(t *testing.T, label string, count int) *realKafkaFixture {
	t.Helper()
	brokers := os.Getenv("STREAMS_REAL_KAFKA_BROKERS")
	if brokers == "" {
		t.Skip("set STREAMS_REAL_KAFKA_BROKERS for real-broker integration tests")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 70*time.Second)
	t.Cleanup(cancel)
	suffix := fmt.Sprintf("%s-%d", label, time.Now().UnixNano())
	f := &realKafkaFixture{brokers: brokers, input: "ci-input-" + suffix, output: "ci-output-" + suffix, group: "ci-consumer-" + suffix, deadline: ctx}
	admin, err := kgo.NewClient(kgo.SeedBrokers(brokers))
	if err != nil { t.Fatal(err) }
	defer admin.Close()
	req := kmsg.NewPtrCreateTopicsRequest()
	for _, topic := range []string{f.input, f.output} {
		req.Topics = append(req.Topics, kmsg.CreateTopicsRequestTopic{Topic: topic, NumPartitions: 1, ReplicationFactor: 1})
	}
	resp, err := req.RequestWith(ctx, admin)
	if err != nil { t.Fatalf("create isolated Kafka topics: %v", err) }
	for _, topic := range resp.Topics {
		if err := kerr.ErrorForCode(topic.ErrorCode); err != nil && !errors.Is(err, kerr.TopicAlreadyExists) {
			t.Fatalf("create isolated Kafka topic: %v", err)
		}
	}
	f.publisher, err = kgo.NewClient(kgo.SeedBrokers(brokers))
	if err != nil { t.Fatal(err) }
	t.Cleanup(f.publisher.Close)
	records := make([]*kgo.Record, count)
	for i := 0; i < count; i++ {
		records[i] = &kgo.Record{Topic: f.input, Partition: 0, Value: []byte(fmt.Sprintf(`{"id":%d}`, i))}
	}
	if err := f.publisher.ProduceSync(ctx, records...).FirstErr(); err != nil {
		t.Fatalf("seed isolated input topic: %v", err)
	}
	return f
}

func (f *realKafkaFixture) readBatch(t *testing.T, n int) (*kgo.Client, []*kgo.Record) {
	t.Helper()
	consumer, err := kgo.NewClient(
		kgo.SeedBrokers(f.brokers), kgo.ConsumeTopics(f.input),
		kgo.ConsumerGroup(f.group), kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
		kgo.DisableAutoCommit(), kgo.BlockRebalanceOnPoll(),
	)
	if err != nil { t.Fatal(err) }
	rows := make([]*kgo.Record, 0, n)
	for len(rows) < n {
		got := consumer.PollRecords(f.deadline, n-len(rows))
		if errs := got.Errors(); len(errs) > 0 {
			consumer.CloseAllowingRebalance()
			t.Fatalf("read real Kafka input: %v", errs)
		}
		rows = append(rows, got.Records()...)
		if f.deadline.Err() != nil {
			consumer.CloseAllowingRebalance()
			t.Fatalf("timed out reading real Kafka input: %v (received %d/%d)", f.deadline.Err(), len(rows), n)
		}
	}
	for i, r := range rows {
		if r.Offset != int64(i) || r.Partition != 0 || r.Topic != f.input {
			consumer.CloseAllowingRebalance()
			t.Fatalf("unexpected Kafka input partition/offset at %d: %s/%d/%d", i, r.Topic, r.Partition, r.Offset)
		}
	}
	return consumer, rows
}

func (f *realKafkaFixture) processor(t *testing.T, count int, expression string, failPublish bool) *batchProcessor {
	t.Helper()
	transform, err := newJSLT(expression)
	if err != nil { t.Fatalf("compile native JSLT: %v", err) }
	indices := make([]string, count)
	for i := range indices { indices[i] = strconv.Itoa(i+1) }
	return &batchProcessor{
		config: config{output: "0010", maxMessage: 1 << 20},
		metrics: &observedBatch{},
		mapDoc: func(raw string) (any, string, error) { return json.RawMessage(raw), raw, nil },
		jslt: transform,
		query: func(_ context.Context, sql string) ([]map[string]any, error) {
			if !strings.HasPrefix(sql, "CALL PQ") {
				return nil, fmt.Errorf("unexpected query (expected mock PQ)")
			}
			return []map[string]any{{"id": "1", "query": "synthetic", "filters": "", "tags": "{}", "documents": strings.Join(indices, ",")}}, nil
		},
		publish: func(ctx context.Context, docs []string) error {
			if failPublish { return errors.New("injected producer failure") }
			records := make([]*kgo.Record, len(docs))
			for i, doc := range docs { records[i] = &kgo.Record{Topic: f.output, Value: []byte(doc)} }
			return f.publisher.ProduceSync(ctx, records...).FirstErr()
		},
	}
}

func (f *realKafkaFixture) send(consumer *kgo.Client, batch []*kgo.Record, p *batchProcessor, rejectCommit bool) error {
	return deliverBatch(f.deadline, p.config, batch, p.process, func(ctx context.Context, records ...*kgo.Record) error {
		if rejectCommit { return errors.New("injected crash after output ack, before input offset commit") }
		return consumer.CommitRecords(ctx, records...)
	})
}

func (f *realKafkaFixture) outputIDs(t *testing.T, want int) map[int]int {
	t.Helper()
	reader, err := kgo.NewClient(kgo.SeedBrokers(f.brokers), kgo.ConsumePartitions(map[string]map[int32]kgo.Offset{
		f.output: {0: kgo.NewOffset().AtStart()},
	}))
	if err != nil { t.Fatal(err) }
	defer reader.Close()
	counts := make(map[int]int)
	total := 0
	for total < want {
		got := reader.PollRecords(f.deadline, want-total)
		if errs := got.Errors(); len(errs) > 0 { t.Fatalf("read output topic: %v", errs) }
		for _, record := range got.Records() {
			var value struct { ID int `json:"id"` }
			if err := json.Unmarshal(record.Value, &value); err != nil {
				t.Fatalf("output JSON invalid: %v", err)
			}
			counts[value.ID]++
			total++
		}
		if f.deadline.Err() != nil { t.Fatalf("output count: got %d, want %d: %v", total, want, f.deadline.Err()) }
	}
	// Detect unexpected extra records, not just the expected count.
	tailCtx, cancel := context.WithTimeout(f.deadline, 250*time.Millisecond)
	defer cancel()
	extra := reader.PollRecords(tailCtx, 1)
	if len(extra.Records()) != 0 { t.Fatalf("unexpected additional Kafka output after %d records", want) }
	return counts
}

func expectRealOutput(t *testing.T, counts map[int]int, first, last, copies int) {
	t.Helper()
	if len(counts) != last-first { t.Fatalf("unique output IDs: got %d, want %d", len(counts), last-first) }
	for i := first; i < last; i++ {
		if counts[i] != copies { t.Fatalf("output id %d: got %d copies, want %d", i, counts[i], copies) }
	}
}

func (f *realKafkaFixture) assertCommitted(t *testing.T) {
	t.Helper()
	// An empty poll on an idle topic ends with context.DeadlineExceeded: it is
	// expected, not a Kafka failure. Check the broker-fetched group offset
	// *and* that a new member of the same group cannot replay prior records.
	assigned := make(chan struct{})
	consumer, err := kgo.NewClient(
		kgo.SeedBrokers(f.brokers), kgo.ConsumerGroup(f.group),
		kgo.ConsumeTopics(f.input), kgo.DisableAutoCommit(),
		kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
		kgo.OnPartitionsAssigned(func(_ context.Context, _ *kgo.Client, parts map[string][]int32) {
			if len(parts[f.input]) > 0 {
				select { case <-assigned: default: close(assigned) }
			}
		}),
	)
	if err != nil { t.Fatal(err) }
	defer consumer.Close()

	until, cancel := context.WithTimeout(f.deadline, 8*time.Second)
	defer cancel()
	const wantOffset = int64(140)
	var lastOffset int64 = -1
	for until.Err() == nil {
		// PollRecords uses the supplied context as its idle-poll deadline.
		// Passing the overall 8s deadline to every poll would consume the
		// entire budget before inspecting assignment or committed offsets.
		pollCtx, stopPoll := context.WithTimeout(until, 250*time.Millisecond)
		fetched := consumer.PollRecords(pollCtx, 1)
		stopPoll()
		for _, fetchErr := range fetched.Errors() {
			if errors.Is(fetchErr.Err, context.DeadlineExceeded) || errors.Is(fetchErr.Err, context.Canceled) {
				continue
			}
			t.Fatalf("committed-offset poll failed: %v", fetchErr.Err)
		}
		if fetched.NumRecords() != 0 {
			t.Fatalf("previously committed input replayed: %d records", fetched.NumRecords())
		}
		select {
		case <-assigned:
			// CommittedOffsets reflects the actual offset fetched when joining
			// the Kafka consumer group, not simply an empty result from Poll.
			if offset, ok := consumer.CommittedOffsets()[f.input][0]; ok {
				lastOffset = offset.Offset
				if lastOffset == wantOffset { return }
			}
		default:
		}
	}
	t.Fatalf("group %s did not commit offset %d (last observed %d): %v", f.group, wantOffset, lastOffset, until.Err())
}

func TestRealKafkaDeliveryRecovery(t *testing.T) {
	if os.Getenv("STREAMS_REAL_KAFKA_BROKERS") == "" { t.Skip("requires isolated real Kafka broker") }
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" { t.Fatal("pinned JSLT bundle required") }
	const n = 140
	t.Run("normal delivery commits offsets", func(t *testing.T) {
		f := newRealKafkaFixture(t, "normal", n)
		consumer, batch := f.readBatch(t, n)
		if err := f.send(consumer, batch, f.processor(t, n, `.`, false), false); err != nil { t.Fatal(err) }
		consumer.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, n), 0, n, 1)
		f.assertCommitted(t)
	})
	t.Run("JSLT poison after first 128 acknowledged outputs replays batch", func(t *testing.T) {
		f := newRealKafkaFixture(t, "partial", n)
		consumer, batch := f.readBatch(t, n)
		p := f.processor(t, n, `if (.id == 130) error("injected JSLT failure") else .`, false)
		if err := f.send(consumer, batch, p, false); err == nil { t.Fatal("JSLT error unexpectedly committed") }
		consumer.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, 128), 0, 128, 1)
		next, replay := f.readBatch(t, n)
		if err := f.send(next, replay, f.processor(t, n, `.`, false), false); err != nil { t.Fatal(err) }
		next.CloseAllowingRebalance()
		counts := f.outputIDs(t, 128+n)
		if len(counts) != n { t.Fatalf("replay lost data: %d unique IDs", len(counts)) }
		for i := 0; i < n; i++ {
			want := 1
			if i < 128 { want = 2 }
			if counts[i] != want { t.Fatalf("id %d: got %d copies, want %d", i, counts[i], want) }
		}
		f.assertCommitted(t)
	})
	t.Run("producer failure blocks offset commit", func(t *testing.T) {
		f := newRealKafkaFixture(t, "producer", n)
		consumer, batch := f.readBatch(t, n)
		if err := f.send(consumer, batch, f.processor(t, n, `.`, true), false); err == nil { t.Fatal("injected producer failure unexpectedly committed") }
		consumer.CloseAllowingRebalance()
		next, replay := f.readBatch(t, n)
		if err := f.send(next, replay, f.processor(t, n, `.`, false), false); err != nil { t.Fatal(err) }
		next.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, n), 0, n, 1)
		f.assertCommitted(t)
	})
	t.Run("crash after output ack before commit causes replay", func(t *testing.T) {
		f := newRealKafkaFixture(t, "commit", n)
		consumer, batch := f.readBatch(t, n)
		if err := f.send(consumer, batch, f.processor(t, n, `.`, false), true); err == nil { t.Fatal("injected commit failure was ignored") }
		consumer.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, n), 0, n, 1)
		next, replay := f.readBatch(t, n)
		if err := f.send(next, replay, f.processor(t, n, `.`, false), false); err != nil { t.Fatal(err) }
		next.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, n*2), 0, n, 2)
		f.assertCommitted(t)
	})
	t.Run("permanent poison blocks progress until corrected", func(t *testing.T) {
		f := newRealKafkaFixture(t, "poison", n)
		for attempt := 0; attempt < 2; attempt++ {
			consumer, batch := f.readBatch(t, n)
			p := f.processor(t, n, `if (.id == 4) error("persistent poison") else .`, false)
			if err := f.send(consumer, batch, p, false); err == nil { t.Fatal("poison input unexpectedly succeeded") }
			consumer.CloseAllowingRebalance()
		}
		next, replay := f.readBatch(t, n)
		if err := f.send(next, replay, f.processor(t, n, `.`, false), false); err != nil { t.Fatal(err) }
		next.CloseAllowingRebalance()
		expectRealOutput(t, f.outputIDs(t, n), 0, n, 1)
		f.assertCommitted(t)
	})
}
