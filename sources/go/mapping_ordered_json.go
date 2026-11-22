package main

import (
	"bytes"
	"encoding/json"

	"github.com/ohler55/ojg/jp"
)

// orderedJPObject implements jp.Keyed so advanced JSONPath selectors see
// object fields in source order, rather than nondeterministic Go map order.
type orderedJPObject struct {
	raw json.RawMessage
	keys []string
	values map[string]any
}

func (o *orderedJPObject) Keys() []string {
	return append([]string(nil), o.keys...)
}
func (o *orderedJPObject) ValueForKey(key string) (any, bool) {
	value, ok := o.values[key]
	return value, ok
}
func (o *orderedJPObject) SetValueForKey(key string, value any) {
	if _, present := o.values[key]; !present {
		o.keys = append(o.keys, key)
	}
	o.values[key] = value
}
func (o *orderedJPObject) RemoveValueForKey(key string) {
	delete(o.values, key)
	for i, name := range o.keys {
		if name == key {
			o.keys = append(o.keys[:i], o.keys[i+1:]...)
			break
		}
	}
}
func (o *orderedJPObject) MarshalJSON() ([]byte, error) {
	return o.raw, nil
}

type orderedJPArray struct {
	raw json.RawMessage
	values []any
}
func (a *orderedJPArray) Size() int { return len(a.values) }
func (a *orderedJPArray) ValueAtIndex(index int) any {
	if index < 0 || index >= len(a.values) { return nil }
	return a.values[index]
}
func (a *orderedJPArray) SetValueAtIndex(index int, value any) {
	if index >= 0 && index < len(a.values) { a.values[index] = value }
}
func (a *orderedJPArray) MarshalJSON() ([]byte, error) {
	return a.raw, nil
}

// Parse the same raw document into source-ordered containers for advanced
// selectors. Scalars remain ordinary values for filter comparisons.
// Refuse extremely deep inputs instead of risking unbounded recursion.
func decodeOrderedJP(raw json.RawMessage, depth int) (any, bool) {
	if depth > 256 { return nil, false }
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 { return nil, false }
	switch raw[0] {
	case '{':
		fields, ok := orderedRawObject(raw)
		if !ok { return nil, false }
		o := &orderedJPObject{raw:raw,values:make(map[string]any,len(fields))}
		for _, field := range fields {
			value, ok := decodeOrderedJP(field.value, depth+1)
			if !ok { return nil, false }
			o.keys = append(o.keys, field.name)
			o.values[field.name] = value
		}
		return o, true
	case '[':
		var items []json.RawMessage
		if json.Unmarshal(raw, &items) != nil { return nil, false }
		a := &orderedJPArray{raw:raw,values:make([]any,0,len(items))}
		for _, item := range items {
			value, ok := decodeOrderedJP(item,depth+1)
			if !ok { return nil, false }
			a.values = append(a.values, value)
		}
		return a, true
	default:
		decoder := json.NewDecoder(bytes.NewReader(raw))
		decoder.UseNumber()
		var value any
		if decoder.Decode(&value) != nil { return nil, false }
		return normalizeJavaJSONNumbers(value), true
	}
}

// OJG iterates a map's keys in random order for wildcard/filter/recursive
// expressions. Detect selectors applied to objects and use ordered containers
// instead. This does not change normal array-only filters or common paths.
func advancedNeedsOrderedJP(root map[string]any, expr jp.Expr) bool {
	for i, part := range expr {
		switch part.(type) {
		case jp.Wildcard, *jp.Filter, jp.Descent:
			for _, selected := range expr[:i].Get(root) {
				if _, isObject := selected.(map[string]any); isObject {
					return true
				}
			}
		}
	}
	return false
}
