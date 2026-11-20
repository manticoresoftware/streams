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

func rawMappedObject(input json.RawMessage, javaMap bool) (string, bool) {
	decoder := json.NewDecoder(bytes.NewReader(input))
	if _, err := decoder.Token(); err != nil {
		return "", false
	}
	var parts []string
	for decoder.More() {
		name, err := decoder.Token()
		if err != nil {
			return "", false
		}
		key, ok := name.(string)
		if !ok {
			return "", false
		}
		var child json.RawMessage
		if err := decoder.Decode(&child); err != nil {
			return "", false
		}
		var value string
		if javaMap {
			value, ok = rawMappedText(child)
		} else {
			value, ok = rawMappedJSON(child)
		}
		if !ok {
			return "", false
		}
		if javaMap {
			parts = append(parts, key+"="+value)
		} else {
			parts = append(parts, quoteMappedString(key)+":"+value)
		}
	}
	separator := ","
	if javaMap {
		separator = ", "
	}
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
	var items []json.RawMessage
	if err := json.Unmarshal(current, &items); err != nil || items == nil {
		return "", false, false
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
