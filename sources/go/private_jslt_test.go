package main

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"reflect"
	"strings"
	"testing"
)

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
					if goErr != nil || javaErr != nil {
						t.Errorf("synthetic input %d failed: Goja=%v Java=%v", i, goErr, javaErr)
						continue
					}
					var a, b map[string]any
					if json.Unmarshal([]byte(got), &a) != nil || json.Unmarshal([]byte(want), &b) != nil {
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
					if !reflect.DeepEqual(a, b) {
						t.Errorf("synthetic input %d produced different output", i)
					}
				}
			}
		})
	}
	fmt.Println("Checked all six configured JSLT expressions locally without production documents")
}
