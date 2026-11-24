package main

import (
	"compress/gzip"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCorpusWalkerRecordBoundaries(t *testing.T) {
	path := filepath.Join(t.TempDir(), "records.gz")
	f, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	z := gzip.NewWriter(f)
	// A blank payload is not silently removed; CR belongs to the payload, not
	// the LF framing. The final record need not end in a physical newline.
	parts := []string{"{\"a\":1}\n", "\n", "null\r\n", strings.Repeat("x", corpusMaxRecord+1) + "\n", "{\"last\":true}"}
	for _, p := range parts {
		if _, err = z.Write([]byte(p)); err != nil {
			t.Fatal(err)
		}
	}
	if z.Close() != nil || f.Close() != nil {
		t.Fatal("close gzip")
	}
	var n int
	count, err := walkCorpus(path, func(i int, b []byte, over bool) error {
		n++
		if i != n {
			t.Fatal("line index")
		}
		if i == 4 {
			if !over || b != nil {
				t.Fatal("oversize retained")
			}
		} else if over || string(b) != parts[i-1] {
			t.Fatal("payload bytes changed")
		}
		return nil
	})
	if err != nil || count != len(parts) || n != len(parts) {
		t.Fatal("record count")
	}
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	b[len(b)-8] ^= 1
	if os.WriteFile(path, b, 0600) != nil {
		t.Fatal("corrupt gzip")
	}
	if _, err = walkCorpus(path, func(int, []byte, bool) error { return nil }); err == nil {
		t.Fatal("corrupted gzip passed integrity")
	}
}
