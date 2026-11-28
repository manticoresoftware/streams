package main

import (
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"time"
)

type config struct {
	label          string
	processingName string
	inputHost      string
	outputHost     string
	inputTopic     string
	outputTopic    string
	group          string

	output string
	jslt   string
	rules  []string
	fields map[string]string

	scalerHost   string
	searchURL    string
	storageURL   string
	logLevel     string
	skipExceeded bool
	suspend      bool

	batchSize           int
	maxBatchSize        int
	maxMessage          int
	fetchMinBytes       int
	fetchMaxWaitMs      int
	fetchMaxBytes       int
	maxPollRecords      int
	inactivityThreshold int
	minThreads          int
	maxThreads          int
	queryTimeout        time.Duration
	measureWindow       time.Duration
}

func readConfig() (config, error) {
	env := func(name, fallback string) string {
		if value := os.Getenv(name); value != "" {
			return value
		}
		return fallback
	}
	var err error
	number := func(name string, fallback, minimum int) int {
		raw := env(name, strconv.Itoa(fallback))
		value, parseErr := strconv.Atoi(raw)
		if err == nil && (parseErr != nil || value < minimum) {
			err = fmt.Errorf("%s must be an integer >= %d, got %q", name, minimum, raw)
		}
		return value
	}
	c := config{
		label: os.Getenv("LABEL"), processingName: os.Getenv("PROCESSING_NAME"),
		inputHost: env("KAFKA_INPUT_HOST", "localhost:29092"), outputHost: env("KAFKA_OUTPUT_HOST", "localhost:29092"),
		inputTopic: env("KAFKA_INPUT_TOPIC", "my-docs"), outputTopic: env("KAFKA_OUTPUT_TOPIC", "my-results"),
		group: env("KAFKA_GROUP_NAME", "streams-manticore"), output: env("OUTPUT_DOCS", "1000"), jslt: os.Getenv("JSLT_CONFIG"),
		scalerHost: env("SCALER_HOST", "localhost:8808"), logLevel: env("LOG_LEVEL", "INFO"),
		rules: []string{}, fields: make(map[string]string), maxBatchSize: number("MAX_BATCH_SIZE", 5000, 1),
		minThreads: number("MIN_THREADS", 1, 0), maxThreads: number("MAX_THREADS", 3, 1),
		maxMessage: number("MAX_MESSAGE_SIZE", 998000, 1), fetchMinBytes: number("KAFKA_FETCH_MIN_BYTES", 1, 0),
		fetchMaxWaitMs: number("KAFKA_FETCH_MAX_WAIT_MS", 500, 0), fetchMaxBytes: number("KAFKA_FETCH_MAX_BYTES", 1048576, 1),
		maxPollRecords: number("KAFKA_MAX_POLL_RECORDS", 500, 1), inactivityThreshold: number("INACTIVITY_THRESHOLD", 180, 0),
		queryTimeout:  time.Duration(number("MAX_MANTICORE_QUERY_TIMEOUT", 300, 1)) * time.Second,
		measureWindow: time.Duration(number("PROCESSED_MEASURE_TIME", 10, 1)) * time.Second,
		suspend:       os.Getenv("SUSPEND") == "1",
	}
	skip := env("SKIP_EXCEEDED_MESSAGES", "true")
	c.skipExceeded = skip == "1" || strings.EqualFold(skip, "true")
	c.searchURL = httpAddress(env("MANTICORE_HOST", "localhost:9306"), number("MANTICORE_HTTP_PORT", 9308, 1))
	c.storageURL = httpAddress(env("METRICS_STORAGE_HOST", "localhost"), number("METRICS_STORAGE_PORT", 19308, 1))
	if err != nil {
		return c, err
	}
	c.batchSize = max(1, c.maxBatchSize/2)
	switch c.output {
	case "1000", "0100", "0010", "1010", "1001", "0101", "0011", "1011":
	default:
		c.output = "0011"
	}
	if rules := os.Getenv("TRANSFORM_RULES"); rules != "" {
		c.rules = strings.Split(rules, "|")
	}
	for _, field := range strings.Split(os.Getenv("MANTICORE_FIELDS"), "|") {
		if field == "" {
			continue
		}
		kind, name, ok := strings.Cut(field, "=")
		if !ok || name == "" {
			return c, fmt.Errorf("invalid MANTICORE_FIELDS entry %q", field)
		}
		c.fields[name] = kind
	}
	return c, nil
}

// MANTICORE_HOST can include the SQL port. The worker talks to the HTTP port.
func httpAddress(host string, port int) string {
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	return "http://" + net.JoinHostPort(strings.Trim(host, "[]"), strconv.Itoa(port))
}
