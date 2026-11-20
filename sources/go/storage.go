package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"time"
)

type point struct {
	Name  string  `json:"metric_name"`
	Stamp int64   `json:"scraptime"`
	Tag   string  `json:"tag"`
	Value float64 `json:"value"`
}

func (m *metrics) collect(now time.Time, includeTotal bool) []point {
	m.mu.Lock()
	defer m.mu.Unlock()
	var points []point
	add := func(name, tag string, value float64) {
		points = append(points, point{m.c.label + name, now.Unix(), tag, value})
	}
	if m.haveLag {
		m.trimWindow(now.Unix())
		sum := 0
		for _, s := range m.window {
			sum += s.docs
		}
		add("_worker_processed_per_sec", m.instance, float64(sum)/m.c.measureWindow.Seconds())
		add("_consumer_lag", "0", float64(m.backlog))
	}
	if includeTotal {
		add("_handler_processed_docs", "0", float64(m.processedDocs))
		m.processedDocs = 0
	}
	if m.matchedRules > 0 {
		add("_handler_matched_docs", "0", float64(m.matchedRules))
		m.matchedRules = 0
	}
	for rule, count := range m.perRule {
		add("_handler_processed_rules", rule, float64(count))
	}
	clear(m.perRule)
	return points
}

func sendPoints(ctx context.Context, base string, points []point) error {
	if len(points) == 0 {
		return nil
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	for _, p := range points {
		entry := map[string]any{"insert": map[string]any{"table": "metrics", "doc": p}}
		if err := enc.Encode(entry); err != nil {
			return err
		}
	}
	raw, err := post(ctx, base+"/bulk", "application/x-ndjson", buf.Bytes())
	if err != nil {
		return err
	}
	if len(bytes.TrimSpace(raw)) == 0 {
		return nil
	}
	var reply struct{ Errors bool }
	if err = json.Unmarshal(raw, &reply); err != nil {
		return err
	}
	if reply.Errors {
		return fmt.Errorf("metrics bulk insert reported errors")
	}
	return nil
}

func ensureMetricsTable(ctx context.Context, base string) error {
	rows, err := queryAt(ctx, base, 4*time.Second, "SHOW TABLES LIKE 'metrics'")
	if err != nil || len(rows) > 0 {
		return err
	}
	_, err = queryAt(ctx, base, 4*time.Second, "CREATE TABLE IF NOT EXISTS metrics (metric_name text, scraptime bigint, tag bigint, value float) engine='columnar'")
	return err
}

// Metrics storage is best effort and must never delay joining Kafka.
func (m *metrics) storageLoop(ctx context.Context) {
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()
	var tableReady bool
	var nextRules, nextTotal time.Time
	for {
		select {
		case <-ctx.Done():
			return
		case now := <-t.C:
			writeCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
			includeTotal := !now.Before(nextTotal)
			points := m.collect(now, includeTotal)
			if includeTotal {
				nextTotal = now.Add(m.c.measureWindow)
			}
			if !now.Before(nextRules) {
				nextRules = now.Add(time.Minute)
				rows, err := queryAt(writeCtx, m.c.searchURL, 4*time.Second, "SELECT count(*) AS cnt FROM pq")
				if err == nil && len(rows) > 0 {
					var count float64
					if _, err := fmt.Sscan(fmt.Sprint(rows[0]["cnt"]), &count); err == nil {
						m.ruleCount.Set(count)
						points = append(points, point{m.c.label + "_manticore_rules", now.Unix(), "0", count})
					}
				}
			}
			var err error
			if !tableReady {
				err = ensureMetricsTable(writeCtx, m.c.storageURL)
			}
			for len(points) > 0 && err == nil {
				n := min(len(points), 5000)
				err = sendPoints(writeCtx, m.c.storageURL, points[:n])
				points = points[n:]
			}
			cancel()
			tableReady = err == nil
			if err != nil && ctx.Err() == nil {
				slog.Warn("could not send worker metrics", "error", err)
			}
		}
	}
}
