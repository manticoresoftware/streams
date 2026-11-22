package main

import (
	"encoding/json"
	"regexp"
	"strings"

	"github.com/ohler55/ojg/jp"
)

// Jayway's terminal path functions are evaluated after selecting their input.
// They are not fields literally named "first()" or "concat(...)".
var legacyTerminalFunction = regexp.MustCompile(`^(.+)\.(first|last|keys|concat)\((.*)\)$`)

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
	if name == "concat" {
		if err := json.Unmarshal([]byte("["+arguments+"]"), &args); err != nil {
			return p, false
		}
	} else if strings.TrimSpace(arguments) != "" {
		return p, false
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
