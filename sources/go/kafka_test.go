package main

import (
	"context"
	"errors"
	"math"
	"reflect"
	"strconv"
	"testing"
	"time"

	"github.com/twmb/franz-go/pkg/kgo"
)

func TestInputOffsetsAfterSuccessfulDelivery(t *testing.T) {
	c := config{maxMessage: 4, skipExceeded: true}
	records := []*kgo.Record{
		{Topic: "a", Partition: 0, Offset: 5, Value: []byte("yes")},
		{Topic: "b", Partition: 1, Offset: 7, Value: []byte("long")},
	}
	success, committed := false, false
	process := func(_ context.Context, docs []string) error {
		if !reflect.DeepEqual(docs, []string{"yes"}) {
			t.Fatalf("unexpected input %v", docs)
		}
		if !success {
			return errors.New("no producer acknowledgement")
		}
		return nil
	}
	commit := func(_ context.Context, got ...*kgo.Record) error {
		if !success || !reflect.DeepEqual(got, records) {
			t.Fatalf("unsafe commit %v", got)
		}
		committed = true
		return nil
	}
	if err := deliverBatch(context.Background(), c, records, process, commit); err == nil || committed {
		t.Fatal("failed output was committed")
	}
	success = true
	if err := deliverBatch(context.Background(), c, records, process, commit); err != nil || !committed {
		t.Fatalf("commit after delivery: %v", err)
	}
	c.skipExceeded = false
	committed = false
	if err := deliverBatch(context.Background(), c, records, process, commit); err == nil || committed {
		t.Fatal("oversized input was committed")
	}
}

func TestKafkaFetchSettings(t *testing.T) {
	for _, wait := range []int{0, 1, 9, 10, 500} {
		t.Run("wait-"+strconv.Itoa(wait), func(t *testing.T) {
			t.Setenv("KAFKA_FETCH_MAX_WAIT_MS", strconv.Itoa(wait))
			c, err := readConfig()
			if err != nil || c.fetchMaxWaitMs != wait {
				t.Fatalf("read fetch wait: %d, %v", c.fetchMaxWaitMs, err)
			}
			cl, err := kgo.NewClient(kafkaFetchOptions(c)...)
			if err != nil {
				t.Fatal(err)
			}
			defer cl.Close()
			if got, want := cl.OptValue(kgo.FetchMaxWait), time.Duration(max(wait, 10))*time.Millisecond; got != want {
				t.Errorf("effective fetch wait = %v, want %v", got, want)
			}
		})
	}
	for _, want := range []struct{ requested, fetch, broker int }{
		{1 << 20, 1 << 20, 100 << 20},
		{128 << 20, 128 << 20, 256 << 20},
		{math.MaxInt32, 1 << 30, 1 << 30},
	} {
		t.Run("bytes-"+strconv.Itoa(want.requested), func(t *testing.T) {
			cl, err := kgo.NewClient(kafkaFetchOptions(config{fetchMaxBytes: want.requested, fetchMinBytes: math.MaxInt, fetchMaxWaitMs: math.MaxInt})...)
			if err != nil {
				t.Fatal(err)
			}
			defer cl.Close()
			if fetch, broker := cl.OptValue(kgo.FetchMaxBytes), cl.OptValue(kgo.BrokerMaxReadBytes); fetch != int32(want.fetch) || broker != int32(want.broker) {
				t.Fatalf("effective fetch/broker limits = %v/%v, want %d/%d", fetch, broker, want.fetch, want.broker)
			}
			if cl.OptValue(kgo.FetchMinBytes) != int32(math.MaxInt32) || cl.OptValue(kgo.FetchMaxWait) != time.Duration(math.MaxInt32)*time.Millisecond {
				t.Fatal("wire values overflowed")
			}
		})
	}
}
