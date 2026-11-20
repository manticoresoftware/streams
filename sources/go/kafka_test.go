package main

import (
	"context"
	"errors"
	"reflect"
	"testing"

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
