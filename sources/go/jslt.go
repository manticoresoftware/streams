package main

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"sync"
	"unicode"
)

// referenceProcess starts lazily and reuses the same interpreter and compiled
// configuration. Each request and reply is a JSON line, including embedded newlines.
type referenceProcess struct {
	mu      sync.Mutex
	command func() *exec.Cmd
	config  string
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	encode  *json.Encoder
	decode  *json.Decoder
}

func javaReference(mode, config string) *referenceProcess {
	return &referenceProcess{config: config, command: func() *exec.Cmd {
		cp := os.Getenv("STREAMS_COMPAT_CLASSPATH")
		if cp == "" {
			cp = "/usr/src/myapp/compat:/usr/src/myapp/KafkaHandler.jar"
		}
		return exec.Command("java", "-cp", cp, "StreamsCompat", mode)
	}}
}

func (p *referenceProcess) close() {
	if p.cmd != nil {
		p.stdin.Close()
		p.cmd.Process.Kill()
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
		cmd := p.command()
		cmd.Stderr = os.Stderr
		in, err := cmd.StdinPipe()
		if err != nil {
			return "", err
		}
		out, err := cmd.StdoutPipe()
		if err != nil {
			in.Close()
			return "", err
		}
		if err = cmd.Start(); err != nil {
			return "", err
		}
		p.cmd, p.stdin = cmd, in
		p.encode, p.decode = json.NewEncoder(in), json.NewDecoder(out)
		if _, err := p.exchange(p.config); err != nil {
			p.close()
			return "", err
		}
	}
	return p.exchange(value)
}

func newJSLT(config string) (func(string) (string, error), error) {
	if strings.TrimSpace(config) == "" {
		return nil, nil
	}
	return javaReference("jslt", config).call, nil
}

// Match mb_strtolower in the Java image, including contextual and multi-rune
// mappings. Only exceptional batches need the persistent PHP process.
var mbLower = &referenceProcess{command: func() *exec.Cmd {
	return exec.Command("php", "-r", `
fgets(STDIN);
echo "{\"data\":\"\"}\n";
while (($line = fgets(STDIN)) !== false) {
    try {
        $text = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $reply = ['data' => mb_strtolower($text, 'UTF-8')];
    } catch (Throwable $e) {
        $reply = ['error' => $e->getMessage()];
    }
    echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}`)
}}

// These are the differences between Go's simple Unicode 15 lowercase tables
// and PHP 8.5 mbstring (plus contextual Greek sigma). Defer non-ASCII to PHP
// if the toolchain changes tables until the parity test has been rerun.
func requiresMBLower(r rune) bool {
	if r < 0x80 {
		return false
	}
	return unicode.Version != "15.0.0" || r == 'İ' || r == 'Σ' || r == 0x1c89 ||
		(r >= 0xa7cb && r <= 0xa7dc) || (r >= 0x10d50 && r <= 0x10d65) ||
		(r >= 0x16ea0 && r <= 0x16eb8)
}

func lowerBatch(text string) (string, error) {
	for _, r := range text {
		if requiresMBLower(r) {
			return mbLower.call(text)
		}
	}
	return strings.ToLower(text), nil
}
