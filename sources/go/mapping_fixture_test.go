package main

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"os"
	"testing"
	"path"
)

// The repository's Kafka fixture has 43,403 public documents. Check how often
// the Go mapper still needs Java on realistic input without logging document
// contents. This audit can be removed once every fallback is eliminated.
func TestFixtureMappingFallbacks(t *testing.T) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Skip("requires Java compatibility oracle")
	}

	file, err := os.Open("../../dev-environment/kafka/test_data.tar.gz")
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()
	compressed, err := gzip.NewReader(file)
	if err != nil {
		t.Fatal(err)
	}
	defer compressed.Close()
	archive := tar.NewReader(compressed)

	var records io.Reader
	for {
		header, err := archive.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		if path.Base(header.Name) == "test_data.json" {
			records = archive
			break
		}
	}
	if records == nil {
		t.Fatal("test_data.json is missing from the Kafka fixture")
	}

	m := newMapper([]string{
		"whole_document => json",
		"text.status.retweeted_status.user.url&&text.comment.status.user.url&&text.comment.status.retweeted_status.user.url&&text.comment.reply_comment.user.url&&text.comment.reply_comment.text&&text.comment.status.statusurl&&text.status.statusurl&&text.comment.user.url&&text.comment.reply_comment.status.user.url&&text.comment.source => url",
		"text.comment.status.source => source",
		"text.comment.status.text => text",
		"text.comment.text => title",
		"text.status.text => statustext",
	}, map[string]string{
		"json": "json", "url": "url", "source": "text",
		"text": "text", "title": "text", "statustext": "text",
	}, "0011")
	defer m.reference.close()

	scanner := bufio.NewScanner(records)
	scanner.Buffer(make([]byte, 64*1024), 16*1024*1024)
	count := 0
	var fallbackOffsets []int
	for scanner.Scan() {
		count++
		before := m.referenceCalls.Load()
		if _, _, err := m.transform(scanner.Text()); err != nil {
			t.Fatalf("mapping fixture record %d: %v", count, err)
		}
		if m.referenceCalls.Load() != before {
			fallbackOffsets = append(fallbackOffsets, count)
		}
	}
	if err := scanner.Err(); err != nil {
		t.Fatal(err)
	}
	if count != 43403 {
		t.Fatalf("fixture contains %d records, expected 43403", count)
	}
	const maxOffsets = 20
	shown := fallbackOffsets
	if len(shown) > maxOffsets {
		shown = shown[:maxOffsets]
	}
	t.Log(fmt.Sprintf("%d Kafka fixture records; %d Java fallback calls; first record numbers: %v",
		count, m.referenceCalls.Load(), shown))
}
