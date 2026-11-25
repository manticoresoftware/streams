package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
	"sync"
)

// Java exists only as an independent oracle in Go tests. The production
// worker contains no Java runtime and never starts a compatibility subprocess.
var mappingOracleMu sync.Mutex
var mappingOracles = make(map[*mapper]*referenceProcess)

func mappingOracle(m *mapper) *referenceProcess {
	mappingOracleMu.Lock()
	defer mappingOracleMu.Unlock()
	o := mappingOracles[m]
	if o == nil {
		o = javaReference("map", m.configJSON)
		mappingOracles[m] = o
	}
	return o
}

func (m *mapper) referenceMap(raw string) (any, string, error) {
	reply, err := mappingOracle(m).call(raw)
	if err != nil { return nil, "", err }
	var pair struct {
		Mapped json.RawMessage `json:"mapped"`
		Output string `json:"output"`
	}
	if err := json.Unmarshal([]byte(reply), &pair); err != nil {
		return nil, "", fmt.Errorf("decode original Java mapper output: %w", err)
	}
	return pair.Mapped, pair.Output, nil
}

func closeMappingOracle(m *mapper) {
	mappingOracleMu.Lock()
	o := mappingOracles[m]
	delete(mappingOracles, m)
	mappingOracleMu.Unlock()
	if o != nil { o.close() }
}

func mappingOracleStarted(m *mapper) bool {
	mappingOracleMu.Lock()
	defer mappingOracleMu.Unlock()
	o := mappingOracles[m]
	return o != nil && o.cmd != nil
}

func mappingUnsupportedCount(m *mapper) uint64 {
	var total uint64
	for _, count := range m.audit.snapshot().Counts { total += count }
	return total
}

// Keep the original Java stdout protocol separate from customer input/logs.
func quietCorpusOracle(mode, config string) *referenceProcess {
	return &referenceProcess{config: config, command: func(ctx context.Context) *exec.Cmd {
		return exec.CommandContext(ctx, "sh", "-c",
			`exec java -cp "$STREAMS_COMPAT_CLASSPATH" StreamsCompat "$1" 2>/dev/null`, "oracle", mode)
	}}
}
