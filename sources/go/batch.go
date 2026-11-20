package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"strconv"
	"strings"
	"time"
)

type queryRows func(context.Context, string) ([]map[string]any, error)

type batchMetrics interface {
	observe(string, time.Duration)
	processed(context.Context, int, int, int, map[string]int, time.Duration)
}

type batchProcessor struct {
	config  config
	mapDoc  func(string) (any, string, error)
	jslt    func(string) (string, error)
	query   queryRows
	publish func(context.Context, []string) error
	metrics batchMetrics
}

type batchDocument struct {
	raw, output string
	mapped      any
}

type ruleMatch struct {
	id             json.Number
	tag            any
	hasTag         bool
	external       any
	hasExternal    bool
	query, filters string
	highlight      string
	hasHighlight   bool
}

func (p *batchProcessor) process(ctx context.Context, records []string) error {
	if len(records) == 0 {
		return nil
	}
	start := time.Now()
	docs := make([]batchDocument, 0, len(records))
	for _, raw := range records {
		mapped, output, err := p.mapDoc(raw)
		if err != nil {
			return fmt.Errorf("map input document %d: %w", len(docs)+1, err)
		}
		docs = append(docs, batchDocument{raw: raw, output: output, mapped: mapped})
	}
	p.metrics.observe("mapping", time.Since(start))
	return p.processChunk(ctx, docs, start)
}

func compactJSON(value any) (string, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	// Search operates on text in these objects, including URL query parameters.
	enc.SetEscapeHTML(false)
	if err := enc.Encode(value); err != nil {
		return "", err
	}
	return strings.TrimSuffix(buf.String(), "\n"), nil
}

var sqlEscaper = strings.NewReplacer("\\", "\\\\", "'", "\\'")

func quoteSQL(value string) string {
	return "'" + sqlEscaper.Replace(value) + "'"
}

func splitQueryError(err error) bool {
	s := strings.ToLower(err.Error())
	return strings.Contains(s, "packet for query is too large") || strings.Contains(s, "syntax error") || strings.Contains(s, "errors:")
}

func (p *batchProcessor) percolate(ctx context.Context, docs []batchDocument) ([]map[string]any, error) {
	mapped := make([]any, len(docs))
	for i := range docs {
		mapped[i] = docs[i].mapped
	}
	encoded, err := compactJSON(mapped)
	if err != nil {
		return nil, err
	}
	lower, err := lowerBatch(encoded)
	if err != nil {
		return nil, err
	}
	cmd := "CALL PQ ('pq', (" + quoteSQL(lower) + "), 1 as docs, 1 as query)"
	for attempt := 1; ; attempt++ {
		start := time.Now()
		rows, err := p.query(ctx, cmd)
		p.metrics.observe("query", time.Since(start))
		if err == nil || attempt == 3 || splitQueryError(err) || ctx.Err() != nil {
			return rows, err
		}
		delay := time.Second
		if strings.Contains(strings.ToLower(err.Error()), "unknown local index") {
			delay = 30 * time.Second
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(delay):
		}
	}
}

func (p *batchProcessor) processChunk(ctx context.Context, docs []batchDocument, start time.Time) error {
	rows, err := p.percolate(ctx, docs)
	if err != nil {
		if len(docs) > 1 && ctx.Err() == nil && splitQueryError(err) {
			// CALL PQ numbers documents relative to this request. Process each
			// slice independently so matches keep referring to the correct input.
			mid := len(docs) / 2
			if err := p.processChunk(ctx, docs[:mid], start); err != nil {
				return err
			}
			return p.processChunk(ctx, docs[mid:], time.Now())
		}
		return fmt.Errorf("percolate %d documents: %w", len(docs), err)
	}

	matches := make([][]ruleMatch, len(docs))
	for _, row := range rows {
		if err := p.collectMatches(ctx, docs, row, matches); err != nil {
			return err
		}
	}
	var outgoing []string
	matched, sent := 0, 0
	rules := make(map[string]int)
	flush := func() error {
		if len(outgoing) == 0 {
			return nil
		}
		at := time.Now()
		err := p.publish(ctx, outgoing)
		p.metrics.observe("produce", time.Since(at))
		if err == nil {
			sent += len(outgoing)
		}
		outgoing = outgoing[:0]
		return err
	}
	emit := func(value string) error {
		if p.jslt != nil {
			var err error
			value, err = p.jslt(value)
			if err != nil {
				return fmt.Errorf("transform output with JSLT: %w", err)
			}
		}
		if len(value) >= p.config.maxMessage {
			if p.config.skipExceeded {
				return nil
			}
			return fmt.Errorf("output message size %d exceeds configured limit %d", len(value), p.config.maxMessage)
		}
		outgoing = append(outgoing, value)
		if len(outgoing) == 128 {
			return flush()
		}
		return nil
	}
	for i, hits := range matches {
		if len(hits) == 0 {
			continue
		}
		matched++
		for _, hit := range hits {
			rules[hit.id.String()]++
			if hit.hasHighlight {
				if err := emit(hit.highlight); err != nil {
					return err
				}
			}
		}
		output := docs[i].output
		if p.config.output[3] == '1' {
			output, err = withMatchingQueries(output, hits)
			if err != nil {
				return err
			}
		}
		if err := emit(output); err != nil {
			return err
		}
	}
	if err := flush(); err != nil {
		return err
	}
	elapsed := time.Since(start)
	p.metrics.observe("batch", elapsed)
	p.metrics.processed(ctx, len(docs), matched, sent, rules, elapsed)
	return nil
}

func fieldText(row map[string]any, key string) string {
	if v := row[key]; v != nil {
		return fmt.Sprint(v)
	}
	return ""
}

func decodeRule(row map[string]any) (ruleMatch, bool, error) {
	id := fieldText(row, "id")
	if _, err := strconv.ParseInt(id, 10, 64); err != nil {
		if _, unsignedErr := strconv.ParseUint(id, 10, 64); unsignedErr != nil {
			return ruleMatch{}, false, fmt.Errorf("invalid rule id %q", id)
		}
	}
	r := ruleMatch{id: json.Number(id), query: fieldText(row, "query"), filters: fieldText(row, "filters")}
	var tags map[string]any
	tagText := fieldText(row, "tags")
	dec := json.NewDecoder(strings.NewReader(tagText))
	dec.UseNumber()
	if err := dec.Decode(&tags); err != nil || tags == nil {
		r.hasTag, r.tag = true, tagText
		return r, false, nil
	}
	r.tag, r.hasTag = tags["tag"]
	r.external, r.hasExternal = tags["externalquery"]
	highlight, _ := tags["highlighting"].(bool)
	return r, highlight, nil
}

func (p *batchProcessor) collectMatches(ctx context.Context, docs []batchDocument, row map[string]any, matches [][]ruleMatch) error {
	rule, highlight, err := decodeRule(row)
	if err != nil {
		return err
	}
	var indices []int
	for _, s := range strings.Split(fieldText(row, "documents"), ",") {
		idx, err := strconv.Atoi(strings.TrimSpace(s))
		if err != nil || idx < 1 || idx > len(docs) {
			return fmt.Errorf("invalid document number %q in Manticore reply", s)
		}
		indices = append(indices, idx-1)
	}

	var snippets []map[string]any
	if highlight {
		quoted := make([]string, len(indices))
		for i, idx := range indices {
			// The Java worker passes each original Kafka message as a JSON
			// string literal to SNIPPETS, not the mapped/lowercased document.
			doc, err := compactJSON(docs[idx].raw)
			if err != nil {
				return err
			}
			quoted[i] = quoteSQL(doc)
		}
		cmd := "CALL SNIPPETS((" + strings.Join(quoted, ", ") + "), 'pq', " + quoteSQL(rule.query) + ", 0 as limit)"
		snippets, err = p.query(ctx, cmd)
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			// Highlight failures should not discard the unhighlighted match.
			log.Printf("highlight rule %s: %v", rule.id, err)
		}
	}
	for i, idx := range indices {
		hit := rule
		if i < len(snippets) {
			hit.highlight, hit.hasHighlight = fieldText(snippets[i], "snippet"), true
		}
		matches[idx] = append(matches[idx], hit)
	}
	return nil
}

func withMatchingQueries(doc string, matches []ruleMatch) (string, error) {
	var obj map[string]json.RawMessage
	if err := json.Unmarshal([]byte(doc), &obj); err != nil || obj == nil {
		return "", fmt.Errorf("matching_queries requires a JSON object")
	}
	rules := make([]map[string]any, 0, len(matches))
	for _, hit := range matches {
		value := map[string]any{"queryid": hit.id}
		if hit.hasTag {
			value["tag"] = hit.tag
		}
		if hit.hasExternal {
			value["query"] = hit.external
		} else {
			value["query"], value["filters"] = hit.query, hit.filters
		}
		rules = append(rules, value)
	}
	encoded, err := compactJSON(rules)
	if err != nil {
		return "", err
	}
	obj["matching_queries"] = json.RawMessage(encoded)
	return compactJSON(obj)
}
