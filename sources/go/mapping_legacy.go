package main

import (
	"encoding/json"
	"fmt"
	"io"
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

// parseLegacyMappingDocument preserves the original worker's parser behavior
// without starting Java for malformed documents or non-object JSON roots.
// JsonTransformer returns a mapping error when JsonIterator cannot produce
// outputDocs; for JSON-smart's recoverable syntax in new/original-only modes,
// it leaves an empty object or accepts single-quoted JSON object keys.
func parseLegacyMappingDocument(raw, mode string) (map[string]any, any, string, error) {
	fail := func() (map[string]any, any, string, error) {
		return nil, nil, "", fmt.Errorf("legacy JSON mapping failed to parse input document")
	}
	parse := func(s string) (any, bool) {
		decoder := json.NewDecoder(strings.NewReader(s))
		decoder.UseNumber()
		var root any
		if err := decoder.Decode(&root); err != nil {
			return nil, false
		}
		var extra any
		if err := decoder.Decode(&extra); err != io.EOF {
			return nil, false
		}
		return root, true
	}
	if root, ok := parse(raw); ok {
		if root == nil {
			return fail()
		}
		if doc, ok := root.(map[string]any); ok {
			return doc, root, raw, nil
		}
		// JsonIterator.asMap() and JsonPath.put/delete() fail for scalar and
		// array roots in the no-transform/rename modes, but the original
		// worker's new-only and original-field modes can process them.
		if mode[2] == '1' {
			return fail()
		}
		return map[string]any{}, root, raw, nil
	}
	if mode[2] == '1' {
		return fail()
	}
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" {
		if raw == "" {
			return fail()
		}
		return map[string]any{}, map[string]any{}, "{}", nil
	}
	// JSON-smart accepts single-quoted strings. JsonIterator, used by the
	// whole_document mapping, does not accept the original invalid JSON.
	if single, ok := normalizeSingleQuotedJSON(raw); ok {
		if root, valid := parse(single); valid {
			if doc, isObject := root.(map[string]any); isObject {
				return doc, root, single, nil
			}
		}
	}
	// A trailing comma or extra characters after a complete JSON value
	// causes Jayway's DocumentContext to skip the mapping in these modes,
	// leaving an empty source document for subsequent missing-path logic.
	if strings.Contains(raw, ",}") || strings.Contains(raw, ",]") {
		return map[string]any{}, map[string]any{}, "{}", nil
	}
	var first any
	decoder := json.NewDecoder(strings.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&first); err == nil {
		return map[string]any{}, map[string]any{}, "{}", nil
	}
	return fail()
}

// Convert only the unambiguous single-quote JSON string grammar. Keep
// double-quoted strings and their escapes unchanged; do not guess at other
// Java JSON-smart extensions.
func normalizeSingleQuotedJSON(raw string) (string, bool) {
	if !strings.Contains(raw, "'") {
		return "", false
	}
	var out strings.Builder
	inSingle, inDouble, escaped := false, false, false
	for i := 0; i < len(raw); i++ {
		c := raw[i]
		if escaped {
			if inSingle && c == '\'' {
				out.WriteByte('\'')
			} else {
				out.WriteByte('\\')
				out.WriteByte(c)
			}
			escaped = false
			continue
		}
		if (inSingle || inDouble) && c == '\\' {
			escaped = true
			continue
		}
		if inSingle {
			if c == '\'' {
				inSingle = false
				out.WriteByte('"')
			} else if c == '"' {
				out.WriteString(`\"`)
			} else {
				out.WriteByte(c)
			}
		} else if inDouble {
			out.WriteByte(c)
			if c == '"' {
				inDouble = false
			}
		} else {
			switch c {
			case '\'':
				inSingle = true
				out.WriteByte('"')
			case '"':
				inDouble = true
				out.WriteByte(c)
			default:
				out.WriteByte(c)
			}
		}
	}
	if inSingle || inDouble || escaped {
		return "", false
	}
	return out.String(), true
}
