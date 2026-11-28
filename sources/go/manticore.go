package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"
)

func post(ctx context.Context, endpoint, contentType string, payload []byte) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(payload))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", contentType)
	req.Header.Set("Accept", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		return nil, fmt.Errorf("%s: %s", resp.Status, detail)
	}
	return io.ReadAll(resp.Body)
}

func queryManticore(ctx context.Context, c config, sql string) ([]map[string]any, error) {
	return queryAt(ctx, c.searchURL, c.queryTimeout, sql)
}

func queryAt(ctx context.Context, base string, timeout time.Duration, sql string) ([]map[string]any, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	raw, err := post(ctx, base+"/cli_json", "text/plain", []byte(sql))
	if err != nil {
		return nil, err
	}
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 {
		return nil, nil
	}
	type result struct {
		Error string
		Data  []map[string]any
	}
	var results []result
	decoder := json.NewDecoder(bytes.NewReader(raw))
	// Rule IDs are int64; float64 cannot represent all of them exactly.
	decoder.UseNumber()
	if raw[0] == '{' {
		var single result
		err = decoder.Decode(&single)
		results = []result{single}
	} else {
		err = decoder.Decode(&results)
	}
	if err != nil {
		return nil, err
	}
	for _, r := range results {
		if r.Error != "" {
			return nil, errors.New(r.Error)
		}
	}
	if len(results) == 0 {
		return nil, nil
	}
	return results[0].Data, nil
}

func waitForPQ(ctx context.Context, c config) error {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
	defer cancel()
	var lastErr error
	for {
		rows, err := queryManticore(ctx, c, "SHOW TABLES LIKE 'pq'")
		if err == nil && len(rows) > 0 {
			return nil
		}
		lastErr = err
		select {
		case <-ctx.Done():
			return fmt.Errorf("waiting for pq: %w (last query error: %v)", ctx.Err(), lastErr)
		case <-time.After(time.Second):
		}
	}
}
