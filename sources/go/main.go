package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

func serveWorker(ctx context.Context) error {
	start := time.Now()
	c, err := readConfig()
	if err != nil {
		return err
	}
	level := slog.LevelInfo
	switch strings.ToUpper(c.logLevel) {
	case "DEBUG", "TRACE":
		level = slog.LevelDebug
	case "WARN":
		level = slog.LevelWarn
	case "ERROR":
		level = slog.LevelError
	}
	slog.SetDefault(slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: level})))
	ctx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)
	m := newMetrics(c, start)
	listener, err := net.Listen("tcp", ":8081")
	if err != nil {
		return fmt.Errorf("worker health listener: %w", err)
	}
	srv := &http.Server{Handler: m.handler(), ReadHeaderTimeout: 5 * time.Second}
	defer srv.Close()
	go func() {
		if err := srv.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
			slog.Error("health server stopped", "error", err)
			cancel(err)
		}
	}()
	slog.Info("worker HTTP listener started", "elapsed", time.Since(start))
	if c.suspend {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(24 * time.Hour):
			return errors.New("suspension expired")
		}
	}
	// Kafka can join the group while the colocated searchd initializes its PQ
	// table. The first batch waits at the delivery boundary before doing work.
	go func() {
		if err := waitForPQ(ctx, c); err != nil {
			cancel(err)
			return
		}
		slog.Info("Manticore pq table ready", "elapsed", time.Since(start))
		m.markPQReady()
		go m.scaleLoop(ctx)
		m.storageLoop(ctx)
	}()
	err = runWorker(ctx, c, m)
	if cause := context.Cause(ctx); cause != nil {
		return cause
	}
	return err
}

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--audit-mapping" {
		if err := auditWorkerMapping(os.Stdout); err != nil {
			slog.Error("mapping configuration audit failed", "error", err)
			os.Exit(1)
		}
		return
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	if err := serveWorker(ctx); err != nil && ctx.Err() == nil {
		slog.Error("worker exited", "error", err)
		os.Exit(1)
	}
}
