package main

import (
	"encoding/json"
	"regexp"
	"strconv"
	"strings"

	"github.com/ohler55/ojg/jp"
)

// Jayway's terminal path functions are evaluated after selecting their input.
// They are not fields literally named "first()" or "concat(...)".
var legacyTerminalFunction = regexp.MustCompile(`^(.+)\.(first|last|keys|concat|sum|min|max|avg|stddev|index|append|size)\((.*)\)$`)

// The parser accepts only a deliberately unambiguous grammar here. Unsupported
// expressions remain available to the Java oracle until proven equivalent.
func compileLegacyTerminalFunction(input string) (mappingPath, bool) {
	p := mappingPath{wildcard: -1}
	if strings.ContainsAny(input, "{}") || strings.Contains(input, "[*]") {
		return p, false
	}
	matched := legacyTerminalFunction.FindStringSubmatch(input)
	if matched == nil {
		return p, false
	}
	prefix, name, arguments := matched[1], matched[2], matched[3]
	if prefix == "" {
		return p, false
	}
	var args []string
	switch name {
	case "concat":
		if err := json.Unmarshal([]byte("["+arguments+"]"), &args); err != nil {
			return p, false
		}
	case "index":
		// Invalid and missing indexes are Java runtime errors, not an
		// unsupported static configuration. Defer validation until read.
		args = []string{strings.TrimSpace(arguments)}
	case "append":
		// Jayway returns a collection for array/object receivers and the
		// original receiver value for scalar inputs. We need only its type
		// because legacy String assignments reject collections/numbers.
		if !json.Valid([]byte("["+arguments+"]")) {
			args = []string{"__invalid__"}
		}
	default:
		if strings.TrimSpace(arguments) != "" {
			return p, false
		}
	}
	selector := "$." + prefix
	if strings.HasPrefix(prefix, "[") {
		selector = "$" + prefix
	}
	expr, err := jp.ParseString(selector)
	if err != nil || !expr.Normal() {
		// Filtered/recursive function prefixes have distinct Jayway
		// collection semantics that need their own differential tests.
		return p, false
	}
	p.keys = strings.Split(input, ".")
	p.advanced = expr
	p.terminalFunction = name
	p.terminalArgs = args
	return p, true
}

// Return the Java JSONPath function value, whether the prefix exists, and
// whether Jayway would abort the whole mapping loop. A non-String value is
// deliberately returned unchanged; text mapping skips it, while URL mapping
// aborts on its failed String cast.
func selectLegacyTerminalFunction(root map[string]any, path mappingPath) (value any, found, abort bool) {
	nodes := path.advanced.Get(root)
	if len(nodes) == 0 {
		return nil, false, false
	}
	if len(nodes) != 1 {
		return nil, false, true
	}
	node := nodes[0]
	switch path.terminalFunction {
	case "size":
		switch node.(type) {
		case []any, map[string]any:
			// Java's collection size is a Number, which cannot be cast to
			// String in legacy mapping; the numeric magnitude is unobserved.
			return json.Number("0"), true, false
		default:
			// On scalar/null receivers Java returns null.
			return nil, true, false
		}
	case "index":
		if len(path.terminalArgs) != 1 {
			return nil, false, true
		}
		at, err := strconv.Atoi(path.terminalArgs[0])
		if err != nil {
			return nil, false, true
		}
		items, ok := node.([]any)
		if !ok || at < -1 || at >= len(items) || len(items) == 0 {
			return nil, false, true
		}
		if at == -1 {
			at = len(items)-1
		}
		return items[at], true, false
	case "append":
		if len(path.terminalArgs) > 0 {
			return nil, false, true
		}
		// append() preserves scalar receivers. Arrays/objects remain
		// collections even when arguments append new values: the caller
		// only observes whether Java can cast the result to String.
		return node, true, false
	case "first", "last":
		items, ok := node.([]any)
		if !ok || len(items) == 0 {
			return nil, false, true
		}
		if path.terminalFunction == "first" {
			return items[0], true, false
		}
		return items[len(items)-1], true, false
	case "keys":
		if _, isObject := node.(map[string]any); isObject {
			// Jayway returns a collection. Java's String assignment catches
			// ClassCastException and the mapping loop continues.
			return []any{}, true, false
		}
		return nil, true, false
	case "sum", "min", "max", "avg", "stddev":
		values, ok := node.([]any)
		if !ok {
			return nil, false, true
		}
		var hasNumber bool
		for _, item := range values {
			switch item.(type) {
			case json.Number, int, int64, float64:
				hasNumber = true
			}
		}
		if !hasNumber {
			// Jayway rejects empty arrays or arrays with no numeric values.
			return nil, false, true
		}
		// The actual numeric value is never exposed by the old worker:
		// assigning its Number to String is caught for text fields and
		// aborts the current document for URL fields.
		return json.Number("0"), true, false
	case "concat":
		var b strings.Builder
		if values, ok := node.([]any); ok {
			for _, entry := range values {
				if text, ok := entry.(string); ok {
					b.WriteString(text)
				}
			}
		}
		for _, argument := range path.terminalArgs {
			b.WriteString(argument)
		}
		return b.String(), true, false
	}
	return nil, false, true
}
