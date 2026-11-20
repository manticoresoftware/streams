package main

import (
	"context"
	"errors"
	"fmt"
	"log"
	"math"
	"strings"
	"sync"
	"time"

	"github.com/twmb/franz-go/pkg/kerr"
	"github.com/twmb/franz-go/pkg/kgo"
	"github.com/twmb/franz-go/pkg/kmsg"
)

func kafkaNames(s string) []string {
	var names []string
	for _, v := range strings.Split(s, ",") {
		if v = strings.TrimSpace(v); v != "" {
			names = append(names, v)
		}
	}
	return names
}

// Group callbacks run on the client's goroutine. A Range assignment replaces
// the member's entire previous assignment.
type partitions struct {
	mu    sync.Mutex
	owned map[string][]int32
}

func (a *partitions) replace(owned map[string][]int32) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.owned = make(map[string][]int32, len(owned))
	for topic, ids := range owned {
		a.owned[topic] = append([]int32(nil), ids...)
	}
}

func (a *partitions) endOffsetsRequest() *kmsg.ListOffsetsRequest {
	a.mu.Lock()
	defer a.mu.Unlock()
	req := kmsg.NewPtrListOffsetsRequest()
	for topic, ids := range a.owned {
		t := kmsg.NewListOffsetsRequestTopic()
		t.Topic = topic
		for _, id := range ids {
			p := kmsg.NewListOffsetsRequestTopicPartition()
			p.Partition, p.Timestamp = id, -1
			t.Partitions = append(t.Partitions, p)
		}
		req.Topics = append(req.Topics, t)
	}
	return req
}

func consumerLag(ctx context.Context, consumer *kgo.Client, owned *partitions) (lag, offset int64, err error) {
	req := owned.endOffsetsRequest()
	if len(req.Topics) == 0 {
		return 0, 0, nil
	}
	end, err := req.RequestWith(ctx, consumer)
	if err != nil {
		return 0, 0, err
	}
	polled, committed := consumer.UncommittedOffsets(), consumer.CommittedOffsets()
	for _, t := range end.Topics {
		for _, p := range t.Partitions {
			if err := kerr.ErrorForCode(p.ErrorCode); err != nil {
				return 0, 0, err
			}
			pos, ok := polled[t.Topic][p.Partition]
			if !ok {
				pos, ok = committed[t.Topic][p.Partition]
			}
			// A new group starts at the high watermark (the Java default).
			position := p.Offset
			if ok && pos.Offset >= 0 {
				position = pos.Offset
			}
			if position < p.Offset {
				lag += p.Offset - position
			}
			offset += position
		}
	}
	return lag, offset, nil
}

func ensureInputTopics(ctx context.Context, client *kgo.Client, topics []string) error {
	meta := kmsg.NewPtrMetadataRequest()
	for _, topic := range topics {
		meta.Topics = append(meta.Topics, kmsg.MetadataRequestTopic{Topic: kmsg.StringPtr(topic)})
	}
	meta.AllowAutoTopicCreation = false
	described, err := meta.RequestWith(ctx, client)
	if err != nil {
		return err
	}
	create := kmsg.NewPtrCreateTopicsRequest()
	for _, topic := range described.Topics {
		err := kerr.ErrorForCode(topic.ErrorCode)
		if err == nil {
			continue
		}
		if !errors.Is(err, kerr.UnknownTopicOrPartition) || topic.Topic == nil {
			return fmt.Errorf("describe input topic: %w", err)
		}
		create.Topics = append(create.Topics, kmsg.CreateTopicsRequestTopic{
			Topic: *topic.Topic, NumPartitions: 4, ReplicationFactor: 1,
		})
	}
	if len(create.Topics) == 0 {
		return nil
	}
	created, err := create.RequestWith(ctx, client)
	if err != nil {
		return err
	}
	for _, t := range created.Topics {
		if err := kerr.ErrorForCode(t.ErrorCode); err != nil && !errors.Is(err, kerr.TopicAlreadyExists) {
			return fmt.Errorf("create input topic %q: %w", t.Topic, err)
		}
	}
	return nil
}

// Keep the saved Kafka settings intact while applying franz-go's wire limits.
func kafkaFetchOptions(c config) []kgo.Opt {
	const maxBrokerRead = 1 << 30
	maxBytes := min(c.fetchMaxBytes, maxBrokerRead)
	// Allow response headers and batches larger than the requested fetch size.
	readBytes := max(int64(100<<20), min(2*int64(maxBytes), int64(maxBrokerRead)))
	waitMS := max(10, min(c.fetchMaxWaitMs, math.MaxInt32))
	return []kgo.Opt{
		kgo.FetchMinBytes(int32(min(c.fetchMinBytes, math.MaxInt32))),
		kgo.FetchMaxBytes(int32(maxBytes)),
		kgo.BrokerMaxReadBytes(int32(readBytes)),
		kgo.FetchMaxWait(time.Duration(waitMS) * time.Millisecond),
	}
}

func runWorker(ctx context.Context, c config, m *metrics) error {
	mapper := newMapper(c.rules, c.fields, c.output)
	jslt, err := newJSLT(c.jslt)
	if err != nil {
		return fmt.Errorf("compile JSLT: %w", err)
	}
	owned := &partitions{}
	topics := kafkaNames(c.inputTopic)
	revoked := func(_ context.Context, _ *kgo.Client, _ map[string][]int32) {
		owned.replace(nil)
		m.assigned(false)
	}
	groupOpts := []kgo.Opt{kgo.SeedBrokers(kafkaNames(c.inputHost)...),
		kgo.ConsumeTopics(topics...), kgo.ConsumerGroup(c.group),
		kgo.Balancers(kgo.RangeBalancer()), kgo.ConsumeResetOffset(kgo.NewOffset().AtEnd()),
		kgo.DisableAutoCommit(), kgo.BlockRebalanceOnPoll(), kgo.RebalanceTimeout(5 * time.Minute),
		kgo.OnPartitionsAssigned(func(_ context.Context, _ *kgo.Client, p map[string][]int32) {
			owned.replace(p)
			for _, ids := range p {
				if len(ids) > 0 {
					m.assigned(true)
					return
				}
			}
			m.assigned(false)
		}), kgo.OnPartitionsRevoked(revoked), kgo.OnPartitionsLost(revoked),
	}
	consumer, err := kgo.NewClient(append(groupOpts, kafkaFetchOptions(c)...)...)
	if err != nil {
		return err
	}
	defer consumer.CloseAllowingRebalance()
	if err := ensureInputTopics(ctx, consumer, topics); err != nil {
		return fmt.Errorf("prepare input topics: %w", err)
	}

	producer, err := kgo.NewClient(kgo.SeedBrokers(kafkaNames(c.outputHost)...),
		kgo.DefaultProduceTopic(c.outputTopic), kgo.RequiredAcks(kgo.AllISRAcks()),
		kgo.ProducerBatchMaxBytes(1000000), kgo.ProducerLinger(time.Millisecond), kgo.RecordRetries(2),
		kgo.RecordDeliveryTimeout(30*time.Second), kgo.AllowAutoTopicCreation())
	if err != nil {
		return err
	}
	defer producer.Close()
	p := &batchProcessor{
		config: c, mapDoc: mapper.transform, jslt: jslt, metrics: m,
		query: func(ctx context.Context, sql string) ([]map[string]any, error) { return queryManticore(ctx, c, sql) },
		publish: func(ctx context.Context, docs []string) error {
			records := make([]*kgo.Record, len(docs))
			for i, doc := range docs {
				records[i] = &kgo.Record{Value: []byte(doc)}
			}
			return producer.ProduceSync(ctx, records...).FirstErr()
		},
	}
	return consumeBatches(ctx, c, m, consumer, owned, p)
}

func consumeBatches(ctx context.Context, c config, m *metrics, consumer *kgo.Client, owned *partitions, p *batchProcessor) error {
	size := c.batchSize
	var pending []*kgo.Record
	var flushAt time.Time
	lastMessage := time.Now()
	nextLag := lastMessage.Add(5 * time.Second)
	firstBatch := true
	for ctx.Err() == nil {
		now := time.Now()
		if !now.Before(nextLag) {
			metricsCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
			lag, offset, err := consumerLag(metricsCtx, consumer, owned)
			if err == nil {
				if suggested := m.lag(metricsCtx, lag, offset, size); suggested > 0 {
					size = min(suggested, c.maxBatchSize)
				}
			} else if ctx.Err() == nil {
				log.Printf("Kafka lag: %v", err)
			}
			cancel()
			nextLag = time.Now().Add(5 * time.Second)
		}
		if len(pending) > 0 && (len(pending) >= size || !time.Now().Before(flushAt)) {
			if err := deliverBatch(ctx, c, pending, p.process, consumer.CommitRecords); err != nil {
				return err
			}
			pending = nil
			firstBatch = false
			consumer.AllowRebalance()
			continue
		}
		if len(pending) == 0 && c.inactivityThreshold > 0 && time.Since(lastMessage) >= time.Duration(c.inactivityThreshold)*time.Second {
			consumer.ForceRebalance()
			lastMessage = time.Now()
		}
		pollUntil := nextLag
		if len(pending) > 0 && flushAt.Before(pollUntil) {
			pollUntil = flushAt
		}
		pollCtx, cancel := context.WithDeadline(ctx, pollUntil)
		start := time.Now()
		fetched := consumer.PollRecords(pollCtx, min(c.maxPollRecords, size-len(pending)))
		cancel()
		m.observe("consume", time.Since(start))
		for _, e := range fetched.Errors() {
			if errors.Is(e.Err, context.DeadlineExceeded) || errors.Is(e.Err, context.Canceled) || kerr.IsRetriable(e.Err) {
				continue
			}
			return fmt.Errorf("poll %s partition %d: %w", e.Topic, e.Partition, e.Err)
		}
		if fetched.NumRecords() > 0 {
			lastMessage = time.Now()
			if len(pending) == 0 {
				flushAt = lastMessage.Add(10 * time.Second)
				// A new replica can process a sparse first poll immediately.
				if firstBatch {
					flushAt = lastMessage
				}
			}
			pending = append(pending, fetched.Records()...)
		}
		if len(pending) == 0 {
			consumer.AllowRebalance()
		}
	}
	return ctx.Err()
}

// Confirm output records before committing any input offset, including when
// the two topics are on different Kafka clusters. A failure leaves the batch
// eligible for replay; a crash between delivery and commit can duplicate it.
func deliverBatch(ctx context.Context, c config, records []*kgo.Record, process func(context.Context, []string) error, commit func(context.Context, ...*kgo.Record) error) error {
	docs := make([]string, 0, len(records))
	for _, r := range records {
		if len(r.Value) >= c.maxMessage {
			if c.skipExceeded {
				continue
			}
			return fmt.Errorf("input %s/%d/%d exceeds %d bytes", r.Topic, r.Partition, r.Offset, c.maxMessage)
		}
		docs = append(docs, string(r.Value))
	}
	if err := process(ctx, docs); err != nil {
		return err
	}
	if err := commit(ctx, records...); err != nil {
		return fmt.Errorf("commit input offsets: %w", err)
	}
	return nil
}
