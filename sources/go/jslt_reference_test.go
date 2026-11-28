package main

// Test-only Java reference used by differential regression tests.

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/signal"
	"sync"
	"syscall"

)

// referenceProcess starts lazily and reuses the same interpreter and compiled
// configuration. Each request and reply is a JSON line, including embedded newlines.
type referenceProcess struct {
	mu      sync.Mutex
	command func(context.Context) *exec.Cmd
	stop    context.CancelFunc
	config  string
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	encode  *json.Encoder
	decode  *json.Decoder
}

func javaReference(mode, config string) *referenceProcess {
	return &referenceProcess{config: config, command: func(ctx context.Context) *exec.Cmd {
		cp := os.Getenv("STREAMS_COMPAT_CLASSPATH")
		if cp == "" {
			cp = "/usr/src/myapp/compat:/usr/src/myapp/KafkaHandler.jar"
		}
		return exec.CommandContext(ctx, "java", "-cp", cp, "StreamsCompat", mode)
	}}
}

func (p *referenceProcess) close() {
	if p.cmd != nil {
		p.stop()
		p.stdin.Close()
		p.cmd.Wait()
		p.cmd = nil
	}
}

func (p *referenceProcess) exchange(value string) (string, error) {
	var reply struct {
		Data  string `json:"data"`
		Error string `json:"error"`
	}
	if err := p.encode.Encode(value); err != nil {
		p.close()
		return "", fmt.Errorf("write transform request: %w", err)
	}
	if err := p.decode.Decode(&reply); err != nil {
		p.close()
		return "", fmt.Errorf("read transform response: %w", err)
	}
	if reply.Error != "" {
		return "", fmt.Errorf("transform: %s", reply.Error)
	}
	return reply.Data, nil
}

func (p *referenceProcess) call(value string) (string, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.cmd == nil {
		ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		cmd := p.command(ctx)
		cmd.Stderr = os.Stderr
		in, err := cmd.StdinPipe()
		if err != nil {
			cancel()
			return "", err
		}
		out, err := cmd.StdoutPipe()
		if err != nil {
			in.Close()
			cancel()
			return "", err
		}
		if err = cmd.Start(); err != nil {
			cancel()
			return "", err
		}
		p.cmd, p.stdin, p.stop = cmd, in, cancel
		p.encode, p.decode = json.NewEncoder(in), json.NewDecoder(out)
		if _, err := p.exchange(p.config); err != nil {
			p.close()
			return "", err
		}
	}
	return p.exchange(value)
}

