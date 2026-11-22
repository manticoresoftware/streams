package main

import (
	"bytes"
	"encoding/json"
	"math"
	"strconv"
	"strings"
)

// json-smart parses decimal/exponent tokens as Java double. FormatFloat's
// shortest-roundtrip representation is normalized to Double.toString:
// fixed notation for 0.001 <= |n| < 1e7 and uppercase E otherwise.
func javaNumberText(raw string) (string, bool) {
	if !strings.ContainsAny(raw, ".eE") {
		return raw, true
	}
	number, err := strconv.ParseFloat(raw, 64)
	if err != nil || math.IsInf(number, 0) || math.IsNaN(number) {
		return "", false
	}
	absolute := math.Abs(number)
	if absolute != 0 && (absolute < 1e-3 || absolute >= 1e7) {
		formatted := strconv.FormatFloat(number, 'e', -1, 64)
		mantissa, exponent, _ := strings.Cut(formatted, "e")
		if !strings.Contains(mantissa, ".") {
			mantissa += ".0"
		}
		power, err := strconv.Atoi(exponent)
		if err != nil {
			return "", false
		}
		return mantissa + "E" + strconv.Itoa(power), true
	}
	formatted := strconv.FormatFloat(number, 'f', -1, 64)
	if !strings.Contains(formatted, ".") {
		formatted += ".0"
	}
	return formatted, true
}

// Jayway's JSON parser retains object key order when a wildcard selects an
// object. Go's map[string]any does not, so use the original JSON for these
// selections instead of depending on randomized Go map iteration.
func rawMappedField(input json.RawMessage, key string) (json.RawMessage, bool) {
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(input, &fields); err != nil || fields == nil {
		return nil, false
	}
	value, ok := fields[key]
	return value, ok
}

func quoteMappedString(value string) string {
	var b bytes.Buffer
	encoder := json.NewEncoder(&b)
	encoder.SetEscapeHTML(false)
	_ = encoder.Encode(value)
	return strings.TrimSuffix(b.String(), "\n")
}

// Preserve the first appearance of each key and the last duplicate value,
// matching Java's JSON-smart object representation used by Jayway.
type orderedRawField struct {
	name string
	value json.RawMessage
}
func orderedRawObject(input json.RawMessage) ([]orderedRawField, bool) {
	decoder := json.NewDecoder(bytes.NewReader(input))
	if token, err := decoder.Token(); err != nil || token != json.Delim('{') {
		return nil, false
	}
	fields := make([]orderedRawField, 0)
	positions := make(map[string]int)
	for decoder.More() {
		name, err := decoder.Token()
		if err != nil { return nil, false }
		key, ok := name.(string)
		if !ok { return nil, false }
		var child json.RawMessage
		if err := decoder.Decode(&child); err != nil { return nil, false }
		if at, seen := positions[key]; seen {
			fields[at].value = child
		} else {
			positions[key] = len(fields)
			fields = append(fields, orderedRawField{name: key, value: child})
		}
	}
	if token, err := decoder.Token(); err != nil || token != json.Delim('}') { return nil, false }
	return fields, true
}
func rawMappedObject(input json.RawMessage, javaMap bool) (string, bool) {
	fields, ok := orderedRawObject(input)
	if !ok { return "", false }
	parts := make([]string, 0, len(fields))
	for _, field := range fields {
		var value string
		if javaMap { value, ok = rawMappedText(field.value) } else { value, ok = rawMappedJSON(field.value) }
		if !ok { return "", false }
		if javaMap {
			parts = append(parts, field.name+"="+value)
		} else {
			parts = append(parts, quoteMappedString(field.name)+":"+value)
		}
	}
	separator := ","
	if javaMap { separator = ", " }
	return "{" + strings.Join(parts, separator) + "}", true
}

// JSONArray.toString uses compact JSON; JSONObject in a Jayway wildcard uses
// Java Map.toString, which has unquoted keys/values and comma-space separators.
func rawMappedJSON(input json.RawMessage) (string, bool) {
	input = bytes.TrimSpace(input)
	if len(input) == 0 {
		return "", false
	}
	switch input[0] {
	case '{':
		return rawMappedObject(input, false)
	case '[':
		var elements []json.RawMessage
		if err := json.Unmarshal(input, &elements); err != nil {
			return "", false
		}
		parts := make([]string, 0, len(elements))
		for _, item := range elements {
			value, ok := rawMappedJSON(item)
			if !ok {
				return "", false
			}
			parts = append(parts, value)
		}
		return "[" + strings.Join(parts, ",") + "]", true
	case '"':
		var text string
		if json.Unmarshal(input, &text) != nil {
			return "", false
		}
		return quoteMappedString(text), true
	default:
		if input[0] == '-' || input[0] >= '0' && input[0] <= '9' {
			return javaNumberText(string(input))
		}
		return string(input), true
	}
}

func rawMappedText(input json.RawMessage) (string, bool) {
	input = bytes.TrimSpace(input)
	if len(input) == 0 {
		return "", false
	}
	switch input[0] {
	case '{':
		return rawMappedObject(input, true)
	case '[':
		return rawMappedJSON(input)
	case '"':
		var text string
		if json.Unmarshal(input, &text) != nil {
			return "", false
		}
		return text, true
	default:
		return rawMappedJSON(input)
	}
}


// Java formats object-valued wildcard matches using their original key order.
// Search the raw source for an equivalent subtree, but reject ambiguous
// matches with different Java string representations rather than guessing.
func javaWildcardRawText(value any, raw string) (string, bool) {
	if text, ok := javaWildcardText(value); ok {
		return text, true
	}
	switch value.(type) {
	case map[string]any, []any:
	default:
		return "", false
	}
	normalized, err := json.Marshal(value)
	if err != nil {
		return "", false
	}
	var chosen string
	found, ambiguous := false, false
	var walk func(json.RawMessage)
	walk = func(node json.RawMessage) {
		if ambiguous {
			return
		}
		node = bytes.TrimSpace(node)
		if len(node) == 0 {
			return
		}
		decoder := json.NewDecoder(bytes.NewReader(node))
		decoder.UseNumber()
		var parsed any
		if decoder.Decode(&parsed) == nil {
			normalizeJavaJSONNumbers(parsed)
			if candidate, err := json.Marshal(parsed); err == nil && bytes.Equal(candidate, normalized) {
				text, ok := rawMappedText(node)
				if !ok {
					ambiguous = true
					return
				}
				if found && text != chosen {
					ambiguous = true
					return
				}
				found, chosen = true, text
			}
		}
		switch node[0] {
		case '{':
			fields, ok := orderedRawObject(node)
			if !ok {
				ambiguous = true
				return
			}
			for _, field := range fields {
				walk(field.value)
			}
		case '[':
			var items []json.RawMessage
			if json.Unmarshal(node, &items) != nil {
				ambiguous = true
				return
			}
			for _, item := range items {
				walk(item)
			}
		}
	}
	walk(json.RawMessage(raw))
	return chosen, found && !ambiguous
}

// The multi-wildcard subset needs only dotted field lookups and [*] expansion.
// Walk RawMessages instead of maps to preserve Java's field order when a
// selected value is a nested object.
type rawWildcardStep struct {
	key string
	expand bool
}

func compileRawWildcardSteps(path string) ([]rawWildcardStep, bool) {
	parts := strings.Split(path, ".")
	steps := make([]rawWildcardStep, 0, len(parts))
	wildcards := 0
	for _, part := range parts {
		expand := strings.HasSuffix(part, "[*]")
		if expand {
			part = strings.TrimSuffix(part, "[*]")
			wildcards++
		}
		if !simpleKey.MatchString(part) {
			return nil, false
		}
		steps = append(steps, rawWildcardStep{key:part, expand:expand})
	}
	return steps, wildcards > 1
}

// Jayway's [*] selector also iterates values of JSON objects. Read those
// directly from the source document to retain their ordering and exact text.
// A duplicated object key is left to the Java bridge because JSON parsers
// differ in which occurrence they retain. The final boolean signals that case.
func rawWildcardValues(input json.RawMessage) ([]json.RawMessage, bool, bool) {
	input = bytes.TrimSpace(input)
	if len(input) == 0 { return nil, false, false }
	if input[0] == '[' {
		var values []json.RawMessage
		if err := json.Unmarshal(input, &values); err != nil { return nil, false, false }
		return values, true, false
	}
	if input[0] != '{' { return nil, false, false }
	fields, ok := orderedRawObject(input)
	if !ok { return nil, false, false }
	values := make([]json.RawMessage, 0, len(fields))
	for _, field := range fields { values = append(values, field.value) }
	return values, true, false
}

func selectRawNestedWildcards(raw string, steps []rawWildcardStep) (string, bool, bool) {
	nodes := []json.RawMessage{json.RawMessage(raw)}
	for _, step := range steps {
		var next []json.RawMessage
		for _, node := range nodes {
			value, exists := rawMappedField(node, step.key)
			if !exists {
				continue
			}
			if step.expand {
				elements, ok, incompatible := rawWildcardValues(value)
				if incompatible {
					return "", false, false
				}
				if !ok {
					continue
				}
				next = append(next, elements...)
			} else {
				next = append(next, value)
			}
		}
		nodes = next
	}
	var result strings.Builder
	for _, node := range nodes {
		value, ok := rawMappedText(node)
		if !ok {
			return "", false, false
		}
		result.WriteString(value)
		result.WriteByte('\n')
	}
	return result.String(), true, true
}


// compileRawDescendantWildcard handles the Jayway "array[*]..field" grammar.
// The generic Go JSONPath evaluator does not visit every nested match here,
// so evaluate these paths from source JSON to preserve all results and order.
func compileRawDescendantWildcard(path string) ([]rawWildcardStep, []string, bool) {
	prefix, suffix, ok := strings.Cut(path, "..")
	if !ok || strings.Contains(suffix, "..") || !strings.HasSuffix(prefix, "[*]") || suffix == "" {
		return nil, nil, false
	}
	parts := strings.Split(prefix, ".")
	steps := make([]rawWildcardStep, 0, len(parts))
	for _, part := range parts {
		expand := strings.HasSuffix(part, "[*]")
		if expand {
			part = strings.TrimSuffix(part, "[*]")
		}
		if !nativeDynamicPathSegment(part) {
			return nil, nil, false
		}
		steps = append(steps, rawWildcardStep{key:part, expand:expand})
	}
	keys := strings.Split(suffix, ".")
	for _, key := range keys {
		if !nativeDynamicPathSegment(key) {
			return nil, nil, false
		}
	}
	return steps, keys, true
}

// selectRawDescendantWildcard applies Jayway's recursive search in document
// order. Unlike a Go map, orderedRawObject preserves the order of source keys.
func selectRawDescendantWildcard(raw string, steps []rawWildcardStep, keys []string) (string, bool, bool) {
	nodes := []json.RawMessage{json.RawMessage(raw)}
	for _, step := range steps {
		var next []json.RawMessage
		for _, node := range nodes {
			value, exists := rawMappedField(node, step.key)
			if !exists {
				continue
			}
			if step.expand {
				items, ok, incompatible := rawWildcardValues(value)
				if incompatible {
					return "", false, false
				}
				if ok {
					next = append(next, items...)
				}
			} else {
				next = append(next, value)
			}
		}
		nodes = next
	}
	var output strings.Builder
	var visit func(json.RawMessage, int) bool
	visit = func(node json.RawMessage, depth int) bool {
		if depth > 256 {
			// Avoid unbounded recursion for unexpected input depth.
			return false
		}
		node = bytes.TrimSpace(node)
		if len(node) == 0 {
			return false
		}
		switch node[0] {
		case '{':
			fields, ok := orderedRawObject(node)
			if !ok {
				return false
			}
			for _, field := range fields {
				if field.name == keys[0] {
					selected := field.value
					found := true
					for _, key := range keys[1:] {
						selected, found = rawMappedField(selected, key)
						if !found {
							break
						}
					}
					if found {
						text, ok := rawMappedText(selected)
						if !ok {
							return false
						}
						output.WriteString(text)
						output.WriteByte('\n')
					}
				}
				if !visit(field.value, depth+1) {
					return false
				}
			}
		case '[':
			var items []json.RawMessage
			if json.Unmarshal(node, &items) != nil {
				return false
			}
			for _, item := range items {
				if !visit(item, depth+1) {
					return false
				}
			}
		}
		return true
	}
	for _, node := range nodes {
		if !visit(node, 0) {
			return "", false, false
		}
	}
	return output.String(), true, true
}

// Recover the exact ordered map/list string produced by Jayway for a simple
// wildcard path. Unsupported types retain the Java compatibility fallback.
func selectRawWildcard(raw string, keys []string, wildcard int) (string, bool, bool) {
	if wildcard < 0 || wildcard >= len(keys) {
		return "", false, false
	}
	current := json.RawMessage(raw)
	for _, key := range keys[:wildcard+1] {
		value, ok := rawMappedField(current, key)
		if !ok {
			return "", false, true
		}
		current = value
	}
	items, ok, incompatible := rawWildcardValues(current)
	if incompatible {
		// Duplicate keys have parser-dependent ordering; leave the oracle
		// fallback available for this ambiguous case.
		return "", false, false
	}
	if !ok {
		// Jayway does not expand a primitive or null as a collection.
		// There is no selected value, but no Java process is needed.
		return "", false, true
	}
	var result strings.Builder
	for _, item := range items {
		selected := item
		found := true
		for _, key := range keys[wildcard+1:] {
			value, ok := rawMappedField(selected, key)
			if !ok {
				found = false
				break
			}
			selected = value
		}
		if !found {
			continue
		}
		value, ok := rawMappedText(selected)
		if !ok {
			return "", false, false
		}
		result.WriteString(value)
		result.WriteByte('\n')
	}
	return result.String(), true, true
}
