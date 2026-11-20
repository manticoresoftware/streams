package main

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// Report only a differing field path and value types, never production rule
// text or output values. The optional audit runs outside public CI.
func jsltDifferencePath(got, want any, path string) string {
	if equivalentJSLTValue(got, want) {
		return ""
	}
	switch a := got.(type) {
	case map[string]any:
		b, ok := want.(map[string]any)
		if !ok {
			break
		}
		keys := make([]string, 0, len(a)+len(b))
		seen := make(map[string]bool)
		for key := range a {
			keys = append(keys, key)
			seen[key] = true
		}
		for key := range b {
			if !seen[key] {
				keys = append(keys, key)
			}
		}
		sort.Strings(keys)
		for _, key := range keys {
			v, gotOK := a[key]
			w, wantOK := b[key]
			if !gotOK || !wantOK {
				return path + "." + key + " (missing field)"
			}
			if diff := jsltDifferencePath(v, w, path+"."+key); diff != "" {
				return diff
			}
		}
	case []any:
		b, ok := want.([]any)
		if !ok {
			break
		}
		if len(a) != len(b) {
			return fmt.Sprintf("%s (array length %d vs %d)", path, len(a), len(b))
		}
		for i := range a {
			if diff := jsltDifferencePath(a[i], b[i], fmt.Sprintf("%s[%d]", path, i)); diff != "" {
				return diff
			}
		}
	}
	return fmt.Sprintf("%s (%T vs %T)", path, got, want)
}

// This optional test reads customer expressions only from a local file.
// Neither the expressions nor any customer documents are committed to GitHub.
func TestPrivateJSLTCompilation(t *testing.T) {
	path := os.Getenv("STREAMS_PRIVATE_JSLT_DUMP")
	if path == "" {
		t.Skip("local-only test; set STREAMS_PRIVATE_JSLT_DUMP")
	}
	if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
		t.Fatal("Java reference classpath required")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	re := regexp.MustCompile("(?m)^[ \t]*id:[ \t]*([0-9]+)\\r?\\n[ \t]*name:[ \t]*([^\\r\\n]+)\\r?\\n[ \t]*jslt:[ \t]*([^\\r\\n]+)")
	rows := re.FindAllStringSubmatch(string(data), -1)
	if len(rows) != 6 {
		t.Fatalf("expected 6 saved process rules, found %d", len(rows))
	}
	fixtures := map[string][]json.RawMessage{}
	if path := os.Getenv("STREAMS_PRIVATE_JSLT_FIXTURES"); path != "" {
		b, err := os.ReadFile(path)
		if err != nil { t.Fatal(err) }
		if err := json.Unmarshal(b, &fixtures); err != nil { t.Fatal(err) }
	}
	for _, row := range rows {
		name := row[1] + "-" + row[2]
		// MySQL vertical output renders escaped newlines and quotes.
		expression := strings.ReplaceAll(strings.ReplaceAll(row[3], "\\n", "\n"), "\\\"", "\"")
		t.Run(name, func(t *testing.T) {
			if _, err := newJSLT(expression); err != nil {
				t.Errorf("Goja compilation rejected %s: %v", name, err)
			}
			cmd := exec.Command("java", "-cp", os.Getenv("STREAMS_COMPAT_CLASSPATH"), "StreamsCompat", "jslt")
			stdin, err := cmd.StdinPipe()
			if err != nil {
				t.Fatal(err)
			}
			stdout, err := cmd.StdoutPipe()
			if err != nil {
				t.Fatal(err)
			}
			if err := cmd.Start(); err != nil {
				t.Fatal(err)
			}
			if err := json.NewEncoder(stdin).Encode(expression); err != nil {
				t.Fatal(err)
			}
			var reply struct {
				Data string
				Error string
			}
			if err := json.NewDecoder(stdout).Decode(&reply); err != nil {
				t.Errorf("Java response: %v", err)
			} else if reply.Error != "" {
				t.Errorf("Java expression rejected %s: %s", name, reply.Error)
			}
			stdin.Close()
			if err := cmd.Wait(); err != nil {
				t.Errorf("Java reference ended: %v", err)
			}
			if len(fixtures[row[2]]) > 0 {
				native, err := newJSLT(expression)
				if err != nil {
					t.Fatal(err)
				}
				reference := javaReference("jslt", expression)
				defer reference.close()
				for i, doc := range fixtures[row[2]] {
					got, goErr := native(string(doc))
					want, javaErr := reference.call(string(doc))
					if (goErr != nil) != (javaErr != nil) {
						t.Errorf("synthetic input %d has different error behavior: Goja=%v Java=%v", i, goErr, javaErr)
						continue
					}
					if goErr != nil {
						// Both engines rejected this synthetic input.
						continue
					}
					var a, b map[string]any
					goJSON := json.NewDecoder(strings.NewReader(got))
					javaJSON := json.NewDecoder(strings.NewReader(want))
					goJSON.UseNumber()
					javaJSON.UseNumber()
					if goJSON.Decode(&a) != nil || javaJSON.Decode(&b) != nil {
						t.Errorf("synthetic input %d produced invalid JSON", i)
						continue
					}
					switch row[2] {
					case "video-processing":
						delete(a, "Inserted"); delete(b, "Inserted")
					case "boards-processing":
						for _, field := range []string{"Inserted", "Updated", "InsertedTs"} {
							delete(a, field); delete(b, field)
						}
					}
					if !equivalentJSLTValue(a, b) {
						t.Errorf("synthetic input %d produced different output at %s", i, jsltDifferencePath(a, b, "$"))
					}
				}
			}
		})
	}
	fmt.Println("Checked all six configured JSLT expressions locally without production documents")
}
