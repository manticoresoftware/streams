package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"reflect"
	"strconv"
	"strings"
	"time"

	"github.com/dop251/goja"
	"github.com/ohler55/ojg/jp"
)

type parityCase struct {
	name       string
	expression string
	input      string
}

func patchJavaDateLiterals(bundle string) (string, error) {
	// The upstream JS port currently treats letters inside a quoted Java
	// SimpleDateFormat literal (such as 'T') as format tokens. Patch the
	// unminified, version-pinned bundle in this experiment only.
	const needle = "    const ch = fmt[i];\n    if (/[A-Za-z]/.test(ch)) {"
	const replacement = "    const ch = fmt[i];\n" +
		"    if (ch === \"'\") {\n" +
		"      i++;\n" +
		"      if (fmt[i] === \"'\") { tokens.push({type:'lit', ch:\"'\"}); i++; continue; }\n" +
		"      let closed = false;\n" +
		"      while (i < fmt.length) {\n" +
		"        if (fmt[i] === \"'\") {\n" +
		"          if (fmt[i + 1] === \"'\") { tokens.push({type:'lit', ch:\"'\"}); i += 2; continue; }\n" +
		"          i++; closed = true; break;\n" +
		"        }\n" +
		"        tokens.push({type:'lit', ch:fmt[i++]});\n" +
		"      }\n" +
		"      if (!closed) throw new JsltException('Unterminated quoted date literal');\n" +
		"      continue;\n" +
		"    }\n" +
		"    if (/[A-Za-z]/.test(ch)) {"
	if strings.Count(bundle, needle) != 1 {
		return "", fmt.Errorf("JSLT bundle tokenizer changed: cannot apply date literal compatibility patch")
	}
	return strings.Replace(bundle, needle, replacement, 1), nil
}

func newVM(bundle, polyfill string) (*goja.Runtime, error) {
	vm := goja.New()
	if err := vm.Set("__streamsURLParts", func(raw string) map[string]string {
		u, err := url.Parse(raw)
		if err != nil || u.Scheme == "" || u.Hostname() == "" {
			return map[string]string{"error": fmt.Sprintf("invalid absolute URL %q", raw)}
		}
		host := u.Hostname()
		user, pass := "", ""
		if u.User != nil {
			user = u.User.Username()
			pass, _ = u.User.Password()
		}
		path := u.EscapedPath()
		if path == "" {
			path = "/"
		}
		search, fragment := "", ""
		if u.RawQuery != "" || u.ForceQuery {
			search = "?" + u.RawQuery
		}
		if strings.Contains(raw, "#") {
			fragment = "#" + u.EscapedFragment()
		}
		return map[string]string{
			"hostname":host, "port":u.Port(), "pathname":path,
			"protocol":u.Scheme + ":", "search":search, "hash":fragment,
			"username":user, "password":pass,
		}
	}); err != nil {
		return nil, err
	}
	if err := vm.Set("__streamsDateParts", func(ms float64, zone string) map[string]string {
		location, err := time.LoadLocation(zone)
		if err != nil {
			return map[string]string{"error": err.Error()}
		}
		t := time.UnixMilli(int64(ms)).In(location)
		return map[string]string{
			"year":t.Format("2006"),"month":t.Format("01"),"day":t.Format("02"),
			"hour":t.Format("15"),"minute":t.Format("04"),"second":t.Format("05"),
			"fractionalSecond":fmt.Sprintf("%03d",t.Nanosecond()/1e6),
			"timeZoneName":t.Format("MST"),
		}
	}); err != nil {
		return nil, err
	}
	if _, err := vm.RunString(polyfill); err != nil {
		return nil, fmt.Errorf("polyfill: %w", err)
	}
	if _, err := vm.RunString(bundle); err != nil {
		return nil, fmt.Errorf("JSLT bundle: %w", err)
	}
	return vm, nil
}

func jsResult(vm *goja.Runtime, tc parityCase) (string, error) {
	script := fmt.Sprintf("(function(){const e = JSLT.compile(%s, 'synthetic.jslt'); return JSON.stringify(JSLT.toJS(e.applyInput(JSLT.fromJS(JSON.parse(%s)))));})()",
		strconv.Quote(tc.expression), strconv.Quote(tc.input))
	value, err := vm.RunString(script)
	if err != nil {
		return "", err
	}
	return value.String(), nil
}

type referenceReply struct {
	Data string `json:"data"`
	Error string `json:"error"`
}

func javaResult(classpath string, tc parityCase) (string, error) {
	cmd := exec.Command("java", "-cp", classpath, "StreamsCompat", "jslt")
	stdin, err := cmd.StdinPipe()
	if err != nil { return "", err }
	stdout, err := cmd.StdoutPipe()
	if err != nil { return "", err }
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Start(); err != nil { return "", err }
	writer, reader := json.NewEncoder(stdin), json.NewDecoder(bufio.NewReader(stdout))
	var configured, replied referenceReply
	if err := writer.Encode(tc.expression); err != nil { stdin.Close(); cmd.Wait(); return "", err }
	if err := reader.Decode(&configured); err != nil { stdin.Close(); cmd.Wait(); return "", err }
	if configured.Error != "" { stdin.Close(); cmd.Wait(); return "", fmt.Errorf("Java compile: %s",configured.Error) }
	if err := writer.Encode(tc.input); err != nil { stdin.Close(); cmd.Wait(); return "", err }
	if err := reader.Decode(&replied); err != nil { stdin.Close(); cmd.Wait(); return "", fmt.Errorf("%w: %s",err,stderr.String()) }
	stdin.Close()
	if err := cmd.Wait(); err != nil { return "", fmt.Errorf("Java: %w: %s",err,stderr.String()) }
	if replied.Error != "" { return "", fmt.Errorf("Java transform: %s",replied.Error) }
	return replied.Data, nil
}

func sameJSON(a, b string) bool {
	var first, second any
	if json.Unmarshal([]byte(a),&first) != nil || json.Unmarshal([]byte(b),&second) != nil {
		return false
	}
	return reflect.DeepEqual(first,second)
}

func jsonPathSmoke() int {
	document := map[string]any{"groups":[]any{map[string]any{"people":[]any{
		map[string]any{"name":"Eve","active":true},
		map[string]any{"name":"Bob","active":false},
	}}}}
	expr, err := jp.ParseString("$.groups[*].people[?(@.active == true)].name")
	if err != nil { fmt.Printf("FAIL JSONPath parsing: %v\n",err);return 1 }
	if found := expr.Get(document); !reflect.DeepEqual(found, []any{"Eve"}) {
		fmt.Printf("FAIL JSONPath filter: %v\n",found)
		return 1
	}
	fmt.Println("PASS JSONPath filter")
	return 0
}

func main() {
	bundleBytes, err := os.ReadFile("jslt-bundle.js")
	if err != nil { panic(err) }
	bundle, err := patchJavaDateLiterals(string(bundleBytes))
	if err != nil { panic(err) }
	polyfill, err := os.ReadFile("polyfill.js")
	if err != nil { panic(err) }
	vm, err := newVM(bundle, string(polyfill))
	if err != nil { panic(err) }
	classpath := os.Getenv("JSLT_REFERENCE_CLASSPATH")
	failures, passed := 0, 0
	for _, tc := range syntheticCases() {
		goOutput, goErr := jsResult(vm, tc)
		if goErr != nil {
			fmt.Printf("FAIL %-30s Goja: %v\n",tc.name,goErr)
			failures++
			continue
		}
		if classpath == "" {
			fmt.Printf("GOJA %-30s %s\n",tc.name,goOutput)
			passed++
			continue
		}
		javaOutput, javaErr := javaResult(classpath, tc)
		if javaErr != nil {
			fmt.Printf("FAIL %-30s oracle: %v\n",tc.name,javaErr)
			failures++
		} else if !sameJSON(goOutput,javaOutput) {
			fmt.Printf("FAIL %-30s Java=%s Goja=%s\n",tc.name,javaOutput,goOutput)
			failures++
		} else {
			fmt.Printf("PASS %-30s\n",tc.name)
			passed++
		}
	}
	failures += jsonPathSmoke()
	fmt.Printf("Synthetic Java/JSLT-Goja parity: %d passed, %d failed (20 input cases; no production data)\n",passed,failures)
	if failures != 0 { os.Exit(1) }
}
