package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/twmb/franz-go/pkg/kerr"
	"github.com/twmb/franz-go/pkg/kfake"
	"github.com/twmb/franz-go/pkg/kgo"
	"github.com/twmb/franz-go/pkg/kmsg"
)

type rebalanceFixture struct {
	ctx     context.Context
	broker  *kfake.Cluster
	sender  *kgo.Client
	config  config
}

func newRebalanceFixture(t *testing.T) *rebalanceFixture {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Second)
	t.Cleanup(cancel)
	broker := kfake.MustCluster(kfake.NumBrokers(1), kfake.SeedTopics(2, "in"), kfake.SeedTopics(1, "out"))
	t.Cleanup(broker.Close)
	sender, err := kgo.NewClient(kgo.SeedBrokers(broker.ListenAddrs()...), kgo.RecordPartitioner(kgo.ManualPartitioner()))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(sender.Close)
	c, err := readConfig()
	if err != nil {
		t.Fatal(err)
	}
	c.inputHost, c.outputHost = broker.ListenAddrs()[0], broker.ListenAddrs()[0]
	c.group, c.inputTopic, c.outputTopic = "rebalance", "in", "out"
	c.batchSize, c.maxBatchSize, c.maxPollRecords = 1, 1, 1
	c.inactivityThreshold = 0
	c.output, c.jslt = "1000", ""
	c.rules, c.fields = []string{"id=>id"}, map[string]string{"id": "text"}
	f := &rebalanceFixture{ctx: ctx, broker: broker, sender: sender, config: c}

	// Establish a checkpoint on each partition before starting the actual
	// worker, whose configured reset policy is AtEnd for a new group.
	f.send(t, 0, "checkpoint-0")
	f.send(t, 1, "checkpoint-1")
	bootstrap, err := kgo.NewClient(
		kgo.SeedBrokers(broker.ListenAddrs()...), kgo.ConsumeTopics(c.inputTopic),
		kgo.ConsumerGroup(c.group), kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
		kgo.DisableAutoCommit(), kgo.BlockRebalanceOnPoll(),
	)
	if err != nil {
		t.Fatal(err)
	}
	defer bootstrap.CloseAllowingRebalance()
	var records []*kgo.Record
	for len(records) < 2 {
		got := bootstrap.PollRecords(ctx, 2-len(records))
		if errs := got.Errors(); len(errs) != 0 {
			t.Fatalf("bootstrap poll: %v", errs)
		}
		records = append(records, got.Records()...)
	}
	if err := bootstrap.CommitRecords(ctx, records...); err != nil {
		t.Fatalf("bootstrap commit: %v", err)
	}
	f.offsets(t, 1, 1)
	return f
}

func (f *rebalanceFixture) send(t *testing.T, partition int32, id string) {
	t.Helper()
	r := &kgo.Record{Topic: f.config.inputTopic, Partition: partition, Value: []byte(fmt.Sprintf(`{"id":%q}`, id))}
	if err := f.sender.ProduceSync(f.ctx, r).FirstErr(); err != nil {
		t.Fatalf("produce %s: %v", id, err)
	}
}

func (f *rebalanceFixture) members(t *testing.T, n int) *kfake.GroupInfo {
	t.Helper()
	info, err := f.broker.WaitGroupInfo(f.ctx, f.config.group, func(info *kfake.GroupInfo) bool {
		return info != nil && info.State == "Stable" && len(info.Members) == n && info.NumAssigned() == 2
	})
	if err != nil {
		t.Fatalf("waiting for %d members: %v (group: %+v)", n, err, info)
	}
	return info
}

func (f *rebalanceFixture) offsets(t *testing.T, p0, p1 int64) {
	t.Helper()
	info, err := f.broker.WaitGroupInfo(f.ctx, f.config.group, func(info *kfake.GroupInfo) bool {
		return info != nil && info.Commits[f.config.inputTopic][0].Offset == p0 &&
			info.Commits[f.config.inputTopic][1].Offset == p1
	})
	if err != nil {
		t.Fatalf("waiting for offsets [%d,%d]: %v (group: %+v)", p0, p1, err, info)
	}
}

type rebalanceWorker struct {
	cancel  context.CancelFunc
	done    chan struct{}
	err     error // read only after done is closed
	queries atomic.Int32
}

func (w *rebalanceWorker) wait(t *testing.T) error {
	t.Helper()
	select {
	case <-w.done:
		return w.err
	case <-time.After(8 * time.Second):
		t.Fatal("worker did not stop")
		return nil
	}
}

func (w *rebalanceWorker) stop(t *testing.T) {
	t.Helper()
	w.cancel()
	if err := w.wait(t); err != nil && !errors.Is(err, context.Canceled) {
		t.Fatalf("worker stopped with error: %v", err)
	}
}

func (f *rebalanceFixture) start(t *testing.T, beforeQuery func(context.Context)) *rebalanceWorker {
	t.Helper()
	ctx, cancel := context.WithCancel(f.ctx)
	w := &rebalanceWorker{cancel: cancel, done: make(chan struct{})}
	search := httptest.NewServer(http.HandlerFunc(func(resp http.ResponseWriter, req *http.Request) {
		w.queries.Add(1)
		body, err := io.ReadAll(req.Body)
		if err != nil || req.URL.Path != "/cli_json" || !strings.HasPrefix(string(body), "CALL PQ") {
			t.Errorf("unexpected PQ request: %q, %v", body, err)
		}
		if beforeQuery != nil {
			beforeQuery(req.Context())
		}
		fmt.Fprint(resp, `[{"data":[{"id":1,"documents":"1","tags":"{}","query":"test"}]}]`)
	}))
	t.Cleanup(search.Close)
	t.Cleanup(func() {
		cancel()
		w.wait(t)
	})
	c := f.config
	c.searchURL = search.URL
	m := newMetrics(c, time.Now())
	m.markPQReady()
	go func() {
		defer close(w.done)
		w.err = runWorker(ctx, c, m)
	}()
	return w
}

func (f *rebalanceFixture) expectOutput(t *testing.T, want map[string]int) {
	t.Helper()
	reader, err := kgo.NewClient(kgo.SeedBrokers(f.broker.ListenAddrs()...), kgo.ConsumePartitions(map[string]map[int32]kgo.Offset{
		f.config.outputTopic: {0: kgo.NewOffset().AtStart()},
	}))
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	total := 0
	for _, n := range want {
		total += n
	}
	got := make(map[string]int)
	for n := 0; n < total; {
		fetch := reader.PollRecords(f.ctx, total-n)
		if errs := fetch.Errors(); len(errs) != 0 {
			t.Fatalf("read output: %v (received %v)", errs, got)
		}
		for _, r := range fetch.Records() {
			var doc struct {
				ID string `json:"id"`
			}
			if err := json.Unmarshal(r.Value, &doc); err != nil {
				t.Fatal(err)
			}
			got[doc.ID]++
			n++
		}
	}
	if len(got) != len(want) {
		t.Fatalf("output IDs: got %v, want %v", got, want)
	}
	for id, count := range want {
		if got[id] != count {
			t.Fatalf("output IDs: got %v, want %v", got, want)
		}
	}
	tail, cancel := context.WithTimeout(f.ctx, 300*time.Millisecond)
	defer cancel()
	fetch := reader.PollRecords(tail, 1)
	for _, e := range fetch.Errors() {
		if !errors.Is(e.Err, context.DeadlineExceeded) {
			t.Fatalf("read output tail: %v", e.Err)
		}
	}
	if fetch.NumRecords() != 0 {
		t.Fatal("unexpected extra output")
	}
}

// Exercise the production poll/process/commit loop through 1 -> 2 -> 1
// replicas. Advance the transferred partition first, then commit on the
// old owner's retained partition: a stale offset map would rewind the
// transferred partition at that point.
func TestKafkaRebalanceDoesNotRewindOffsets(t *testing.T) {
	f := newRebalanceFixture(t)
	a := f.start(t, nil)
	first := f.members(t, 1).Members[0].MemberID
	f.send(t, 0, "initial-0")
	f.send(t, 1, "initial-1")
	f.offsets(t, 2, 2)

	b := f.start(t, nil)
	assignment := f.members(t, 2)
	var retained, transferred int32 = -1, -1
	for _, member := range assignment.Members {
		parts := member.Assignment[f.config.inputTopic]
		if len(parts) != 1 {
			t.Fatalf("unexpected assignment: %+v", assignment)
		}
		if member.MemberID == first {
			retained = parts[0]
		} else {
			transferred = parts[0]
		}
	}
	if retained < 0 || transferred < 0 || retained == transferred {
		t.Fatalf("old and new worker did not split ownership: %+v", assignment)
	}
	f.send(t, transferred, "transferred")
	expected := [2]int64{2, 2}
	expected[transferred] = 3
	f.offsets(t, expected[0], expected[1])
	f.send(t, retained, "retained")
	f.offsets(t, 3, 3)
	if a.queries.Load() != 3 || b.queries.Load() != 1 {
		t.Fatalf("unexpected processing by workers A=%d B=%d", a.queries.Load(), b.queries.Load())
	}

	b.stop(t)
	f.members(t, 1)
	f.send(t, 0, "returned-0")
	f.send(t, 1, "returned-1")
	f.offsets(t, 4, 4)
	a.stop(t)
	f.offsets(t, 4, 4)
	f.expectOutput(t, map[string]int{
		"initial-0": 1, "initial-1": 1, "transferred": 1, "retained": 1,
		"returned-0": 1, "returned-1": 1,
	})
}

// Remove a real group member in the protocol-level broker while its PQ
// request is in flight. Its replacement processes the replay and advances
// the offset beyond the old batch before that batch is allowed to complete.
func TestKafkaLostMembershipRejectsStaleCommit(t *testing.T) {
	f := newRebalanceFixture(t)
	entered, release := make(chan struct{}), make(chan struct{})
	a := f.start(t, func(ctx context.Context) {
		close(entered)
		select {
		case <-release:
		case <-ctx.Done():
		}
	})
	old := f.members(t, 1).Members[0].MemberID
	f.send(t, 0, "held")
	select {
	case <-entered:
	case <-f.ctx.Done():
		t.Fatal("old worker did not enter PQ")
	}
	f.offsets(t, 1, 1)

	// This is a broker-side membership removal, not a stubbed CommitRecords
	// error. Keep A blocked until another member has committed a newer offset.
	leave := kmsg.NewPtrLeaveGroupRequest()
	leave.Group, leave.MemberID = f.config.group, old
	member := kmsg.NewLeaveGroupRequestMember()
	member.MemberID = old
	leave.Members = append(leave.Members, member)
	resp, err := leave.RequestWith(f.ctx, f.sender)
	if err != nil {
		t.Fatal(err)
	}
	if err := kerr.ErrorForCode(resp.ErrorCode); err != nil {
		t.Fatal(err)
	}
	for _, m := range resp.Members {
		if err := kerr.ErrorForCode(m.ErrorCode); err != nil {
			t.Fatal(err)
		}
	}
	b := f.start(t, nil)
	info := f.members(t, 1)
	if info.Members[0].MemberID == old {
		t.Fatal("old member was not replaced")
	}
	f.send(t, 0, "newer")
	f.offsets(t, 3, 1)

	close(release)
	err = a.wait(t)
	if err == nil || !strings.Contains(err.Error(), "commit input offsets") ||
		(!errors.Is(err, kerr.UnknownMemberID) && !errors.Is(err, kerr.IllegalGeneration)) {
		t.Fatalf("stale commit must fail with a group membership error, got %v", err)
	}
	f.offsets(t, 3, 1)
	b.stop(t)
	f.offsets(t, 3, 1)
	// Output from A may already be acknowledged before Kafka rejects the
	// commit. Replay is at-least-once; the one duplicate is intentional.
	f.expectOutput(t, map[string]int{"held": 2, "newer": 1})
}
