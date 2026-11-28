package main

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path"
	"reflect"
	"strings"
	"testing"
)

// Guard the JVM-free runtime against mapping fallback regressions on the
// repository's 43,403 public Kafka documents. Also compare sampled mapped and
// output JSON with Java without logging document contents.
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

	m := newFixtureMapper()
	defer closeMappingOracle(m)

	scanner := bufio.NewScanner(records)
	scanner.Buffer(make([]byte, 64*1024), 16*1024*1024)
	count, compared := 0, 0
	var fallbackOffsets []int
	for scanner.Scan() {
		count++
		raw := scanner.Text()
		before := mappingUnsupportedCount(m)
		mapped, output, err := m.transform(raw)
		if err != nil {
			t.Fatalf("mapping fixture record %d: %v", count, err)
		}
		if mappingUnsupportedCount(m) != before {
			fallbackOffsets = append(fallbackOffsets, count)
		}
		// Compare *every* document in the small-batch benchmark corpus with
		// Java. The remaining 42k fixture documents are sampled at fixed
		// intervals to keep the CI differential suite bounded.
		if count <= 1000 || count%173 == 0 || count == 43403 {
			wantMapped, wantOutput, err := m.referenceMap(raw)
			if err != nil {
				t.Fatalf("Java reference for fixture record %d: %v", count, err)
			}
			got, err := json.Marshal(mapped)
			if err != nil {
				t.Fatal(err)
			}
			want, err := json.Marshal(wantMapped)
			if err != nil {
				t.Fatal(err)
			}
			if !sameFixtureJSON(string(got), string(want)) || !sameFixtureJSON(output, wantOutput) {
				// The fixture is public, but do not print raw Kafka records in CI logs.
				t.Fatalf("Java/Go mapping differs for fixture record %d", count)
			}
			compared++
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
	if len(fallbackOffsets) != 0 {
		t.Fatalf("%d of %d Kafka fixture records required Java fallback; first record numbers: %v",
			len(fallbackOffsets), count, shown)
	}
	t.Logf("%d Kafka fixture records; zero native Java fallbacks; %d Java output comparisons",
		count, compared)
}

// Compare decoded JSON so insignificant object-key order does not cause false
// positives, while json.Number preserves large integer precision and format.
func sameFixtureJSON(a, b string) bool {
	parse := func(s string) (any, error) {
		decoder := json.NewDecoder(strings.NewReader(s))
		decoder.UseNumber()
		var value any
		if err := decoder.Decode(&value); err != nil {
			return nil, err
		}
		return value, nil
	}
	left, leftErr := parse(a)
	right, rightErr := parse(b)
	return leftErr == nil && rightErr == nil && reflect.DeepEqual(left, right)
}

// Keep the mapping configuration identical for the fixture parity test and
// isolated throughput benchmarks. Both represent the repository's E2E rules.
func newFixtureMapper() *mapper {
	return newMapper([]string{
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
}
