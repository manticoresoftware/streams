package main

// Opt-in, local-only corpus audit. Reports never contain rules or documents.
import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math/rand"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

// Preserve number tokens and duplicate values (including their relative order)
// while ignoring only object-key order. Invalid JSON is compared verbatim.
func strictCorpusJSON(raw string) string {
	d := json.NewDecoder(strings.NewReader(raw))
	d.UseNumber()
	s, err := corpusJSONValue(d)
	if err != nil {
		return "raw:" + raw
	}
	if _, err = d.Token(); err != io.EOF {
		return "raw:" + raw
	}
	return "json:" + s
}
func corpusJSONValue(d *json.Decoder) (string, error) {
	tok, err := d.Token()
	if err != nil {
		return "", err
	}
	switch v := tok.(type) {
	case json.Delim:
		switch v {
		case '{':
			type entry struct{ k, v string }
			var es []entry
			for d.More() {
				k, e := d.Token()
				if e != nil {
					return "", e
				}
				s, e := corpusJSONValue(d)
				if e != nil {
					return "", e
				}
				key, ok := k.(string)
				if !ok {
					return "", fmt.Errorf("key")
				}
				es = append(es, entry{key, s})
			}
			if _, e := d.Token(); e != nil {
				return "", e
			}
			sort.SliceStable(es, func(i, j int) bool { return es[i].k < es[j].k })
			var b strings.Builder
			b.WriteByte('{')
			for _, e := range es {
				b.WriteString(strconv.Quote(e.k))
				b.WriteByte(':')
				b.WriteString(e.v)
				b.WriteByte(',')
			}
			b.WriteByte('}')
			return b.String(), nil
		case '[':
			var b strings.Builder
			b.WriteByte('[')
			for d.More() {
				s, e := corpusJSONValue(d)
				if e != nil {
					return "", e
				}
				b.WriteString(s)
				b.WriteByte(',')
			}
			if _, e := d.Token(); e != nil {
				return "", e
			}
			b.WriteByte(']')
			return b.String(), nil
		}
	case json.Number:
		return "n:" + v.String(), nil
	case string:
		return "s:" + strconv.Quote(v), nil
	case nil:
		return "null", nil
	case bool:
		return strconv.FormatBool(v), nil
	}
	return "", fmt.Errorf("token")
}
func TestStrictCorpusComparison(t *testing.T) {
	for _, tc := range []struct {
		a, b string
		same bool
	}{
		{`{"a":1,"b":[2,3]}`, `{"b":[2,3],"a":1}`, true},
		{`{"a":1,"a":2}`, `{"a":2}`, false},
		{`{"a":1,"a":2}`, `{"a":2,"a":1}`, false},
		{`1 trailing`, `1`, false}, {`9007199254740993`, `9007199254740992`, false},
		{`[1,2]`, `[2,1]`, false}, {`1e2`, `100`, false},
	} {
		if (strictCorpusJSON(tc.a) == strictCorpusJSON(tc.b)) != tc.same {
			t.Fatal("comparison contract")
		}
	}
}

const corpusMaxRecord = 16 << 20

// Drain oversized lines without retaining them; a partial final line is a record.
func walkCorpus(path string, fn func(int, []byte, bool) error) (int, error) {
	f, e := os.Open(path)
	if e != nil {
		return 0, e
	}
	defer f.Close()
	g, e := gzip.NewReader(f)
	if e != nil {
		return 0, e
	}
	defer g.Close()
	r := bufio.NewReaderSize(g, 64<<10)
	idx := 0
	for {
		var record []byte
		over := false
		read := false
		for {
			b, err := r.ReadSlice('\n')
			if len(b) > 0 {
				read = true
			}
			if !over {
				if len(record)+len(b) > corpusMaxRecord {
					record = nil
					over = true
				} else {
					record = append(record, b...)
				}
			}
			if err == bufio.ErrBufferFull {
				continue
			}
			if err != nil && err != io.EOF {
				return idx, fmt.Errorf("gzip_read")
			}
			if !read {
				return idx, nil
			}
			idx++
			if e := fn(idx, record, over); e != nil {
				return idx, e
			}
			if err == io.EOF {
				return idx, nil
			}
			break
		}
	}
}

type corpusProfile struct {
	Topic     string            `json:"topic"`
	Rules     []string          `json:"rules"`
	Fields    map[string]string `json:"fields"`
	Output    string            `json:"output"`
	JSLT      string            `json:"jslt"`
	Pipelines int               `json:"pipeline_count"`
}
type corpusIndex struct {
	Lines          int   `json:"lines"`
	Indices        []int `json:"indices"`
	Integrity      bool  `json:"gzip_integrity"`
	ArchiveBytes   int64 `json:"archive_bytes"`
	ArchiveMtimeNS int64 `json:"archive_mtime_ns"`
}
type corpusResult struct {
	Topic           string                  `json:"topic"`
	Total           int                     `json:"total_lines"`
	Selected        int                     `json:"selected"`
	Tested          int                     `json:"tested"`
	Oversize        int                     `json:"oversize_selected"`
	InvalidUTF8     int                     `json:"invalid_utf8_selected"`
	JavaErrors      int                     `json:"java_errors"`
	GoErrors        int                     `json:"go_errors"`
	Mismatches      map[string]int          `json:"mismatches_by_category"`
	Fallback        mappingFallbackSnapshot `json:"fallback_audit"`
	ReferenceCalls  uint64                  `json:"reference_calls"`
	Coverage        float64                 `json:"coverage_percent"`
	Integrity       bool                    `json:"gzip_integrity"`
	Seconds         float64                 `json:"seconds"`
	JSLTGoCompile   bool                    `json:"jslt_go_compile"`
	JSLTJavaCompile bool                    `json:"jslt_java_compile"`
	Status          string                  `json:"status"`
}

func privateCorpusWrite(path string, v any) error {
	b, e := json.MarshalIndent(v, "", "  ")
	if e != nil {
		return e
	}
	return os.WriteFile(path, append(b, '\n'), 0600)
}

// Own process, entirely independent of mapper.reference and referenceCalls.
// Java's legacy logger may contain input, so stderr is discarded before start.
func quietCorpusOracle(mode, config string) *referenceProcess {
	return &referenceProcess{config: config, command: func(ctx context.Context) *exec.Cmd {
		c := exec.CommandContext(ctx, "sh", "-c", `exec java -cp "$STREAMS_COMPAT_CLASSPATH" StreamsCompat "$1" 2>/dev/null`, "oracle", mode)
		return c
	}}
}
func corpusJavaCompiles(expression string) bool {
	cmd := exec.Command("sh", "-c", `exec java -cp "$STREAMS_COMPAT_CLASSPATH" StreamsCompat jslt 2>/dev/null`)
	in, err := cmd.StdinPipe()
	if err != nil {
		return false
	}
	out, err := cmd.StdoutPipe()
	if err != nil {
		in.Close()
		return false
	}
	if cmd.Start() != nil {
		in.Close()
		return false
	}
	defer cmd.Wait()
	defer in.Close()
	if json.NewEncoder(in).Encode(expression) != nil {
		return false
	}
	var reply struct {
		Data  string `json:"data"`
		Error string `json:"error"`
	}
	return json.NewDecoder(out).Decode(&reply) == nil && reply.Error == ""
}

func TestLocalStreamingMappingParity(t *testing.T) {
	profilesPath := os.Getenv("STREAMS_CORPUS_PROFILES")
	if profilesPath == "" {
		t.Skip("local corpus only")
	}
	dir := os.Getenv("STREAMS_CORPUS_DIR")
	outDir := os.Getenv("STREAMS_CORPUS_RESULTS")
	if dir == "" || outDir == "" || os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Fatal("corpus prerequisites missing")
	}
	t.Setenv("STREAMS_NATIVE_ONLY", "1")
	b, e := os.ReadFile(profilesPath)
	if e != nil {
		t.Fatal("read profiles")
	}
	var profiles []corpusProfile
	if json.Unmarshal(b, &profiles) != nil || len(profiles) != 12 {
		t.Fatal("expected 12 profiles")
	}
	found := map[string]bool{}
	pipelines := 0
	for _, p := range profiles {
		if found[p.Topic] || filepath.Base(p.Topic) != p.Topic || len(p.Output) != 4 {
			t.Fatal("ambiguous profile")
		}
		found[p.Topic] = true
		pipelines += p.Pipelines
		if _, e := os.Stat(filepath.Join(dir, p.Topic+".jsonl.gz")); e != nil {
			t.Fatal("missing archive")
		}
	}
	archives, e := filepath.Glob(filepath.Join(dir, "*.jsonl.gz"))
	if e != nil || len(archives) != len(profiles) || pipelines != 283 {
		t.Fatal("snapshot/archive cardinality")
	}
	limit := 10000
	if s := os.Getenv("STREAMS_CORPUS_LIMIT"); s != "" {
		limit, e = strconv.Atoi(s)
		if e != nil || limit < 1 {
			t.Fatal("limit")
		}
	}
	reportName := os.Getenv("STREAMS_CORPUS_REPORT")
	if reportName == "" {
		reportName = "stream-parity.json"
	}
	failures, e := os.OpenFile(filepath.Join(outDir, "failures-private.jsonl"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0600)
	if e != nil {
		t.Fatal("private failures")
	}
	defer failures.Close()
	failureEncoder := json.NewEncoder(failures)
	var results []corpusResult
	for pi, p := range profiles {
		if startText := os.Getenv("STREAMS_CORPUS_START_INDEX"); startText != "" {
			start, err := strconv.Atoi(startText)
			if err != nil || start < 0 || start >= len(profiles) {
				t.Fatal("start index")
			}
			if pi < start {
				continue
			}
		}
		start := time.Now()
		path := filepath.Join(dir, p.Topic+".jsonl.gz")
		stat, err := os.Stat(path)
		if err != nil {
			t.Fatal("archive stat")
		}
		indexPath := filepath.Join(outDir, fmt.Sprintf("sample-index-%02d.json", pi))
		var index corpusIndex
		cached := false
		if saved, err := os.ReadFile(indexPath); err == nil {
			if json.Unmarshal(saved, &index) != nil {
				t.Fatal("invalid index")
			}
			cached = index.ArchiveBytes == stat.Size() && index.ArchiveMtimeNS == stat.ModTime().UnixNano()
		}
		if !cached {
			rng := rand.New(rand.NewSource(20261124 + int64(pi)))
			reservoir := make([]int, 0, 10000)
			n, err := walkCorpus(path, func(i int, _ []byte, _ bool) error {
				if len(reservoir) < 10000 {
					reservoir = append(reservoir, i)
				} else if j := rng.Intn(i); j < 10000 {
					reservoir[j] = i
				}
				return nil
			})
			index = corpusIndex{Lines: n, Indices: reservoir, Integrity: err == nil, ArchiveBytes: stat.Size(), ArchiveMtimeNS: stat.ModTime().UnixNano()}
			if privateCorpusWrite(indexPath, index) != nil {
				t.Fatal("write index")
			}
		}
		selectIndices := append([]int(nil), index.Indices...)
		if len(selectIndices) > limit {
			selectIndices = selectIndices[:limit]
		}
		selected := map[int]bool{}
		for _, i := range selectIndices {
			selected[i] = true
		}
		// Supplement the uniform reservoir with deterministic position strata.
		// No Kafka timestamp metadata is available in this raw-payload export.
		for stratum := 0; stratum < 20; stratum++ {
			lo := index.Lines*stratum/20 + 1
			hi := index.Lines * (stratum + 1) / 20
			if hi >= lo {
				selected[(lo+hi)/2] = true
			}
		}
		for _, i := range []int{1, index.Lines / 2, index.Lines} {
			if i > 0 {
				selected[i] = true
			}
		}
		// A private optional category->indices manifest can add rare original records.
		if rarePath := os.Getenv("STREAMS_CORPUS_RARE"); rarePath != "" {
			var rare map[string]map[string][]int
			if b, e := os.ReadFile(rarePath); e == nil && json.Unmarshal(b, &rare) == nil {
				for _, indices := range rare[p.Topic] {
					for _, i := range indices {
						if i > 0 && i <= index.Lines {
							selected[i] = true
						}
					}
				}
			}
		}
		item := corpusResult{Topic: p.Topic, Total: index.Lines, Selected: len(selected), Integrity: index.Integrity, Mismatches: map[string]int{}}
		native := newMapper(p.Rules, p.Fields, p.Output)
		if native.requireNativeSupport() != nil {
			item.Mismatches["unsupported_rule"]++
		}
		config, _ := json.Marshal(struct {
			Rules  []string          `json:"rules"`
			Fields map[string]string `json:"fields"`
			Output string            `json:"output"`
		}{p.Rules, p.Fields, p.Output})
		oracle := quietCorpusOracle("map", string(config))
		// Compile both engines without applying production pre-PQ records as post-PQ input.
		if p.JSLT != "" {
			_, err := newJSLT(p.JSLT)
			item.JSLTGoCompile = err == nil
			item.JSLTJavaCompile = corpusJavaCompiles(p.JSLT)
		}
		readLines, readErr := walkCorpus(path, func(i int, raw []byte, over bool) error {
			if !selected[i] {
				return nil
			}
			if over {
				item.Oversize++
				return nil
			}
			if !utf8.Valid(raw) {
				item.InvalidUTF8++
				return nil
			}
			// Remove the physical LF delimiter only; never decode/reencode Kafka payload.
			raw = bytes.TrimSuffix(raw, []byte{'\n'})
			source := string(raw)
			gm, goOut, ge := native.transform(source)
			jd, je := oracle.call(source)
			item.Tested++
			if ge != nil {
				item.GoErrors++
			}
			if je != nil {
				item.JavaErrors++
			}
			category := ""
			if ge != nil || je != nil {
				if (ge == nil) != (je == nil) {
					category = "error_vs_success"
				} else {
					category = "error_semantics_unverified"
				}
			} else {
				var pair struct {
					Mapped string `json:"mapped_raw"`
					Output string `json:"output"`
				}
				if json.Unmarshal([]byte(jd), &pair) != nil {
					category = "oracle_protocol"
				} else {
					gb, err := json.Marshal(gm)
					if err != nil {
						category = "go_serialize"
					} else {
						a := strictCorpusJSON(string(gb)) == strictCorpusJSON(pair.Mapped)
						b := strictCorpusJSON(goOut) == strictCorpusJSON(pair.Output)
						if !a && !b {
							category = "mapped_and_output"
						} else if !a {
							category = "mapped"
						} else if !b {
							category = "output"
						}
					}
				}
			}
			if category != "" {
				item.Mismatches[category]++
				hash := sha256.Sum256(raw)
				if err := failureEncoder.Encode(map[string]any{"topic": p.Topic, "line": i, "sha256": hex.EncodeToString(hash[:]), "category": category, "go_error": ge != nil, "java_error": je != nil}); err != nil {
					return fmt.Errorf("failure_write")
				}
			}
			return nil
		})
		if readLines != index.Lines || item.Tested+item.Oversize+item.InvalidUTF8 != item.Selected {
			item.Mismatches["selection_or_source_changed"]++
		}
		oracle.close()
		native.reference.close()
		item.ReferenceCalls = native.referenceCalls.Load()
		item.Fallback = native.fallbackAudit.snapshot()
		if readErr != nil {
			item.Integrity = false
			item.Mismatches["read_error"]++
		}
		item.Coverage = 100 * float64(item.Tested) / float64(item.Total)
		item.Seconds = time.Since(start).Seconds()
		if p.JSLT != "" && (!item.JSLTGoCompile || !item.JSLTJavaCompile) {
			item.Mismatches["jslt_compilation"]++
		}
		item.Status = "PASS"
		if !item.Integrity || item.Oversize > 0 || item.InvalidUTF8 > 0 {
			item.Status = "INCONCLUSIVE"
		}
		if len(item.Mismatches) > 0 || item.ReferenceCalls > 0 || len(item.Fallback.Counts) > 0 {
			item.Status = "FAIL"
		}
		results = append(results, item)
		if privateCorpusWrite(filepath.Join(outDir, reportName), results) != nil {
			t.Fatal("write report")
		}
		t.Logf("topic_index=%d total=%d tested=%d reference_calls=%d mismatches=%d status=%s", pi, item.Total, item.Tested, item.ReferenceCalls, len(item.Mismatches), item.Status)
	}
	for _, r := range results {
		if r.Status != "PASS" {
			t.Errorf("local corpus result is %s (payload omitted)", r.Status)
		}
	}
}
