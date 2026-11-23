package main

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/url"
	"runtime"
	rmetrics "runtime/metrics"
	"strconv"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promhttp"
)

type rateSample struct {
	second int64
	docs   int
}
type metrics struct {
	c                                   config
	start                               time.Time
	instance                            string
	firstAssignment, firstReady         sync.Once
	hasAssignment                       atomic.Bool
	pqReady                             chan struct{}
	registry                            *prometheus.Registry
	latency                             map[string]prometheus.Histogram
	input, matched, sent, cpu           prometheus.Histogram
	ram, kafkaLag, batchSize, ruleCount prometheus.Gauge
	offset                              prometheus.Counter
	fallbackTotal                       *prometheus.CounterVec
	mu                                  sync.Mutex
	position, backlog                   int64
	haveLag                             bool
	freshLag                            bool
	target                              int
	lastBatch                           time.Duration
	window                              []rateSample
	processedDocs, matchedRules         int
	perRule                             map[string]int
}

func newMetrics(c config, start time.Time) *metrics {
	m := &metrics{c: c, start: start, pqReady: make(chan struct{}), instance: strconv.FormatInt(start.UnixMilli(), 10), registry: prometheus.NewRegistry(),
		target: c.batchSize, perRule: make(map[string]int), latency: make(map[string]prometheus.Histogram)}
	labels := prometheus.Labels{"instance": m.instance, "pipeline": c.label, "consumer_group": c.group}
	kafkaLabels := prometheus.Labels{"topic": c.inputTopic, "consumer_group": c.group}
	hist := func(name, help string) prometheus.Histogram {
		h := prometheus.NewHistogram(prometheus.HistogramOpts{Name: name, Help: help, ConstLabels: labels})
		m.registry.MustRegister(h)
		return h
	}
	gauge := func(name, help string, l prometheus.Labels) prometheus.Gauge {
		g := prometheus.NewGauge(prometheus.GaugeOpts{Name: name, Help: help, ConstLabels: l})
		m.registry.MustRegister(g)
		return g
	}
	for stage, name := range map[string]string{"mapping": "worker_mapping_time", "query": "worker_query_time", "produce": "worker_kafka_produce_time", "consume": "worker_kafka_consume_time", "batch": "worker_batch_handling_time"} {
		m.latency[stage] = hist(name, "Seconds spent in the "+stage+" stage.")
	}
	m.input = hist("worker_processed_docs_count", "Documents processed per batch.")
	m.matched = hist("worker_matched_docs_count", "Matched documents per batch.")
	m.sent = hist("worker_send_committed", "Output records acknowledged per batch.")
	m.cpu = hist("worker_consumption_cpu", "Worker CPU usage as a percentage.")
	m.ram = gauge("worker_consumption_ram", "Bytes occupied by objects in the worker heap.", labels)
	m.batchSize = gauge("worker_batch_size", "Target batch size.", labels)
	m.batchSize.Set(float64(c.batchSize))
	m.ruleCount = gauge("worker_rules_count", "Number of rules in the pipeline.", prometheus.Labels{
		"instance": m.instance, "pipeline": c.label, "consumer_group": c.group, "processing_label": c.processingName})
	m.kafkaLag = gauge("kafka_lag", "Kafka lag.", kafkaLabels)
	m.offset = prometheus.NewCounter(prometheus.CounterOpts{Name: "kafka_offset_total", Help: "Kafka offset.", ConstLabels: kafkaLabels})
	m.registry.MustRegister(m.offset)
	m.fallbackTotal = prometheus.NewCounterVec(prometheus.CounterOpts{
		Name: "worker_mapping_java_fallback_total",
		Help: "Documents that required Java mapping compatibility, by reason (no payload or rule labels).",
		ConstLabels: labels,
	}, []string{"reason"})
	m.registry.MustRegister(m.fallbackTotal)
	for _, reason := range []mappingFallbackReason{
		mappingFallbackUnsupportedRule, mappingFallbackURL,
		mappingFallbackValue, mappingFallbackRename,
	} {
		m.fallbackTotal.WithLabelValues(string(reason)).Add(0)
	}
	return m
}

func (m *metrics) recordMappingFallback(reason mappingFallbackReason) {
	m.fallbackTotal.WithLabelValues(string(reason)).Inc()
}

func (m *metrics) handler() http.Handler {
	mux := http.NewServeMux()
	mux.Handle("/metrics", promhttp.HandlerFor(m.registry, promhttp.HandlerOpts{}))
	mux.HandleFunc("/live", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusOK) })
	mux.HandleFunc("/ready", func(w http.ResponseWriter, _ *http.Request) {
		if !m.isReady() {
			http.Error(w, "waiting for Kafka assignment and Manticore", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	})
	return mux
}

func (m *metrics) assigned(assigned bool) {
	m.hasAssignment.Store(assigned)
	if assigned {
		m.firstAssignment.Do(func() { slog.Info("Kafka partitions assigned after " + time.Since(m.start).String()) })
		m.logFirstReady()
	}
}

func (m *metrics) markPQReady() {
	close(m.pqReady)
	m.logFirstReady()
}

func (m *metrics) waitForPQ(ctx context.Context) error {
	select {
	case <-m.pqReady:
	case <-ctx.Done():
	}
	return ctx.Err()
}

func (m *metrics) isReady() bool {
	select {
	case <-m.pqReady:
		return m.hasAssignment.Load()
	default:
		return false
	}
}

func (m *metrics) logFirstReady() {
	if m.isReady() {
		m.firstReady.Do(func() { slog.Info("Worker ready to process after " + time.Since(m.start).String()) })
	}
}

func (m *metrics) observe(stage string, elapsed time.Duration) {
	m.latency[stage].Observe(elapsed.Seconds())
}

func (m *metrics) processed(_ context.Context, docs, matches, sent int, rules map[string]int, elapsed time.Duration) {
	m.input.Observe(float64(docs))
	m.matched.Observe(float64(matches))
	m.sent.Observe(float64(sent))
	now := time.Now().Unix()
	m.mu.Lock()
	defer m.mu.Unlock()
	m.lastBatch = elapsed
	m.processedDocs += docs
	for rule, count := range rules {
		m.perRule[rule] += count
		m.matchedRules += count
	}
	if n := len(m.window); n > 0 && m.window[n-1].second == now {
		m.window[n-1].docs += docs
	} else {
		m.window = append(m.window, rateSample{now, docs})
	}
	m.trimWindow(now)
}

func (m *metrics) trimWindow(now int64) {
	cut := now - int64(m.c.measureWindow/time.Second)
	for len(m.window) > 0 && m.window[0].second <= cut {
		m.window = m.window[1:]
	}
}

func (m *metrics) lag(_ context.Context, lag, offset int64, _ int) int {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.backlog = lag
	m.haveLag = true
	m.freshLag = true
	m.kafkaLag.Set(float64(lag))
	if offset > m.position {
		m.offset.Add(float64(offset - m.position))
		m.position = offset
	}
	return m.target
}

func (m *metrics) updateScaler(ctx context.Context) {
	m.mu.Lock()
	if !m.freshLag {
		m.mu.Unlock()
		return
	}
	m.freshLag = false
	v := url.Values{"label": {m.c.label}, "lag": {strconv.FormatInt(m.backlog, 10)},
		"minThreads": {strconv.Itoa(m.c.minThreads)}, "maxThreads": {strconv.Itoa(m.c.maxThreads)},
		"maxBatchSize": {strconv.Itoa(m.c.maxBatchSize)}, "batchSize": {strconv.Itoa(m.target)},
		"lastQueryProcessingTime": {strconv.FormatFloat(m.lastBatch.Seconds(), 'f', 3, 64)}}
	m.mu.Unlock()
	ctx, cancel := context.WithTimeout(ctx, 4*time.Second)
	defer cancel()
	b, err := post(ctx, "http://"+m.c.scalerHost+"/", "application/x-www-form-urlencoded", []byte(v.Encode()))
	if err != nil {
		slog.Warn("scaler request failed", "error", err)
		return
	}
	var resp struct{ BatchSize *json.Number }
	if err = json.Unmarshal(b, &resp); err != nil {
		slog.Warn("invalid scaler response", "error", err)
		return
	}
	target := m.c.batchSize
	if resp.BatchSize != nil {
		// The PHP scaler can return the posted maximum as a numeric string
		// or a fractional number when applying its five-percent floor.
		size, err := resp.BatchSize.Float64()
		if err != nil || size < 1 {
			return
		}
		target = int(min(size, float64(m.c.maxBatchSize)))
	}
	m.mu.Lock()
	m.target = target
	m.mu.Unlock()
	m.batchSize.Set(float64(target))
}

func (m *metrics) scaleLoop(ctx context.Context) {
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()
	var prevCPU float64
	prev := time.Now()
	for {
		select {
		case <-ctx.Done():
			return
		case now := <-t.C:
			samples := []rmetrics.Sample{{Name: "/memory/classes/heap/objects:bytes"}}
			rmetrics.Read(samples)
			m.ram.Set(float64(samples[0].Value.Uint64()))
			var usage syscall.Rusage
			if err := syscall.Getrusage(syscall.RUSAGE_SELF, &usage); err == nil {
				total := float64(usage.Utime.Nano()+usage.Stime.Nano()) / 1e9
				m.cpu.Observe((total - prevCPU) * 100 / now.Sub(prev).Seconds() / float64(runtime.GOMAXPROCS(0)))
				prevCPU = total
				prev = now
			}
			m.updateScaler(ctx)
		}
	}
}
