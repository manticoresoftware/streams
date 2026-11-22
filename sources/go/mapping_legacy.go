package main

import (
	"encoding/json"
	"strings"
)

// json-smart renders arrays as compact JSON, while map toString() renders
// ordered key=value pairs. Java's JsonTransformer inserts this text literally
// into JSONPath before evaluating the selector.
func javaDynamicSelector(raw string, source []string, value any) (string, bool) {
	switch v := value.(type) {
	case nil:
		return "", false
	case string:
		return v, true
	case bool:
		if v {
			return "true", true
		}
		return "false", true
	case json.Number:
		return javaNumberText(v.String())
	case map[string]any, []any:
		var node json.RawMessage = json.RawMessage(raw)
		for _, key := range source {
			var found bool
			node, found = rawMappedField(node, key)
			if !found {
				return "", false
			}
		}
		if _, array := value.([]any); array {
			return rawMappedJSON(node)
		}
		return rawMappedText(node)
	default:
		return "", false
	}
}

func dynamicSelectorIsUnion(selector string) bool {
	// A multi-element array becomes a Jayway union. As a String, its
	// JSONArray result is rejected even when one element matched.
	return strings.HasPrefix(selector, "[") && strings.HasSuffix(selector, "]") &&
		strings.Contains(selector, ",")
}

func dynamicSelectorIsInvalid(selector string) bool {
	if selector == "[]" || strings.HasPrefix(selector, "[null") ||
		strings.HasPrefix(selector, "[{") {
		return true
	}
	if strings.ContainsAny(selector, " \t\n\r") {
		return true
	}
	// An empty selector is an indefinite descendant lookup, not a field
	// literally named "". Handle it as a skipped selection.
	return false
}
