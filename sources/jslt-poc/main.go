package main

import (
	"encoding/json"
	"fmt"
	"os"
	"reflect"
	"strconv"

	"github.com/dop251/goja"
	"github.com/ohler55/ojg/jp"
)

func main() {
	bundle, err := os.ReadFile("jslt-bundle.js")
	if err != nil {
		panic(err)
	}
	tests := []struct {
		name, expression, input, expected string
	}{
		{
			"variables and comprehensions",
			"let idparts = split(.id, \"-\")\n{\"id\": join([for ($idparts) \"x\" * size(.)], \"-\"), * : .}",
			"{\"id\":\"abc-xy\",\"keep\":1}", "{\"id\":\"xxx-xx\",\"keep\":1}",
		},
		{
			"date parsing",
			"{\"time\": round(parse-time(.published, \"yyyy-MM-dd'T'HH:mm:ssX\") * 1000)}",
			"{\"published\":\"2026-11-20T12:00:00Z\"}", "{\"time\":1795176000000}",
		},
		{
			"URL parsing",
			"{\"url\": parse-url(.link)}",
			"{\"link\":\"https://example.com/a?x=1\"}", "",
		},
	}
	failures := 0
	for _, tc := range tests {
		vm := goja.New()
		if _, err := vm.RunString(string(bundle)); err != nil {
			fmt.Fprintf(os.Stderr, "%s: loading bundle: %v\n", tc.name, err)
			failures++
			continue
		}
		script := fmt.Sprintf("(function(){const e = JSLT.compile(%s, 'test.jslt'); return JSON.stringify(JSLT.toJS(e.applyInput(JSLT.fromJS(JSON.parse(%s)))));})()",
			strconv.Quote(tc.expression), strconv.Quote(tc.input))
		result, err := vm.RunString(script)
		if err != nil {
			fmt.Fprintf(os.Stderr, "%s: %v\n", tc.name, err)
			failures++
			continue
		}
		output := result.String()
		fmt.Printf("%s: %s\n", tc.name, output)
		if tc.expected == "" {
			continue
		}
		var got, want any
		if err := json.Unmarshal([]byte(output), &got); err != nil {
			panic(err)
		}
		if err := json.Unmarshal([]byte(tc.expected), &want); err != nil {
			panic(err)
		}
		if !reflect.DeepEqual(got, want) {
			fmt.Fprintf(os.Stderr, "%s: got %s; expected %s\n", tc.name, output, tc.expected)
			failures++
		}
	}
	// Check the two advanced Jayway queries currently delegated to Java.
	document := map[string]any{
		"user.name": "reader",
		"groups": []any{map[string]any{
			"people": []any{
				map[string]any{"name": "Eve", "active": true},
				map[string]any{"name": "Bob", "active": false},
			},
		}},
	}
	for _, tc := range []struct {
		path string
		want []any
	}{
		{"$.groups[*].people[?(@.active == true)].name", []any{"Eve"}},
		{"$['user.name']", []any{"reader"}},
		{"$.groups[*].people[*].name", []any{"Eve", "Bob"}},
	} {
		expr, err := jp.ParseString(tc.path)
		if err != nil {
			fmt.Fprintf(os.Stderr, "JSONPath parse %s: %v\n", tc.path, err)
			failures++
			continue
		}
		found := expr.Get(document)
		fmt.Printf("JSONPath %s: %v\n", tc.path, found)
		if !reflect.DeepEqual(found, tc.want) {
			fmt.Fprintf(os.Stderr, "JSONPath %s: got %v, want %v\n", tc.path, found, tc.want)
			failures++
		}
	}
	if failures > 0 {
		os.Exit(1)
	}
}
