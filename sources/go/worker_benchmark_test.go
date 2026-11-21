package main

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"testing"
)

// The results are observed by the external CI runner. Do not benchmark
// constructing or compiling the transformer on each document.
var benchmarkMappedSink any
var benchmarkOutputSink string

// Use the same public Kafka dataset and mapping rules as the CI parity audit.
// Sample regularly across the *whole* archive to avoid measuring only the
// beginning of the feed. Each implementation gets the exact same documents.
func loadWorkerBenchmarkFixture(b *testing.B) []string {
	b.Helper()
	file, err := os.Open("../../dev-environment/kafka/test_data.tar.gz")
	if err != nil {
		b.Fatal(err)
	}
	defer file.Close()
	zipped, err := gzip.NewReader(file)
	if err != nil {
		b.Fatal(err)
	}
	defer zipped.Close()

	archive := tar.NewReader(zipped)
	for {
		header, err := archive.Next()
		if errors.Is(err, io.EOF) {
			b.Fatal("test_data.json is missing from the Kafka fixture")
		}
		if err != nil {
			b.Fatal(err)
		}
		if path.Base(header.Name) == "test_data.json" {
			break
		}
	}
	scanner := bufio.NewScanner(archive)
	scanner.Buffer(make([]byte, 64*1024), 16*1024*1024)
	const stride = 43
	var docs []string
	count := 0
	for scanner.Scan() {
		count++
		if count%stride == 1 {
			docs = append(docs, scanner.Text())
		}
	}
	if err := scanner.Err(); err != nil {
		b.Fatal(err)
	}
	if count != 43403 || len(docs) < 1000 {
		b.Fatalf("unexpected fixture: %d records, %d selected", count, len(docs))
	}
	return docs
}

// BenchmarkWorkerFixtureMapping compares native Go mapping against the warmed
// persistent Java compatibility bridge. The Java figure includes IPC and
// JSON-line protocol overhead; neither figure includes Kafka or Manticore.
func BenchmarkWorkerFixtureMapping(b *testing.B) {
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		b.Skip("requires the Java compatibility oracle")
	}
	docs := loadWorkerBenchmarkFixture(b)
	for _, tc := range []struct {
		name string
		java bool
	}{{"Go_native", false}, {"Java_warm_bridge", true}} {
		b.Run(tc.name, func(b *testing.B) {
			m := newFixtureMapper()
			defer m.reference.close()
			b.ReportAllocs()

			var mapped any
			var output string
			var err error
			// Exclude the Java process start and configuration compilation.
			if tc.java {
				mapped, output, err = m.referenceMap(docs[0])
			} else {
				mapped, output, err = m.transform(docs[0])
			}
			if err != nil {
				b.Fatal(err)
			}
			b.ResetTimer()
			for i := 0; i < b.N; i++ {
				if tc.java {
					mapped, output, err = m.referenceMap(docs[i%len(docs)])
				} else {
					mapped, output, err = m.transform(docs[i%len(docs)])
				}
				if err != nil {
					b.Fatal(err)
				}
			}
			b.StopTimer()
			if !tc.java && m.referenceCalls.Load() != 0 {
				b.Fatal("fixture mapping unexpectedly required Java")
			}
			benchmarkMappedSink, benchmarkOutputSink = mapped, output
		})
	}
}

// This synthetic JSLT combines tested Unicode, HTML cleanup, URL parsing and
// integer passthrough. No private customer expressions or messages are used.
func benchmarkJSLTDocuments() []string {
	docs := make([]string, 32)
	for i := range docs {
		value, _ := json.Marshal(map[string]any{
			"Text": fmt.Sprintf("Unicode café 漢字 <b>post %d</b>", i),
			"url":  fmt.Sprintf("https://example.org/article/%d?q=hello%%20world", i),
			"id":   i + 1,
		})
		docs[i] = string(value)
	}
	return docs
}

// BenchmarkWorkerJSLT measures a compiled Goja program versus a warmed
// persistent Java JSLT interpreter using identical synthetic documents.
// It does not measure startup, Kafka delivery, or real customer expressions.
func BenchmarkWorkerJSLT(b *testing.B) {
	if os.Getenv("STREAMS_JSLT_BUNDLE") == "" || os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		b.Skip("requires the pinned JSLT bundle and Java compatibility oracle")
	}
	const expression = `def strip(s) replace($s,"(<([^>]+)>)","")
{"Text":strip(.Text),"Url":parse-url(.url).pathname,"Id":.id}`
	docs := benchmarkJSLTDocuments()

	for _, tc := range []struct {
		name string
		java bool
	}{{"Go_goja", false}, {"Java_warm_bridge", true}} {
		b.Run(tc.name, func(b *testing.B) {
			b.ReportAllocs()
			var transform func(string) (string, error)
			if tc.java {
				reference := javaReference("jslt", expression)
				defer reference.close()
				transform = reference.call
			} else {
				var err error
				transform, err = newJSLT(expression)
				if err != nil {
					b.Fatal(err)
				}
			}
			output, err := transform(docs[0])
			if err != nil {
				b.Fatal(err)
			}
			if !json.Valid([]byte(output)) {
				b.Fatalf("JSLT returned invalid JSON: %s", output)
			}
			b.ResetTimer()
			for i := 0; i < b.N; i++ {
				output, err = transform(docs[i%len(docs)])
				if err != nil {
					b.Fatal(err)
				}
			}
			b.StopTimer()
			benchmarkOutputSink = output
		})
	}
}
