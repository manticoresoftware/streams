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
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
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
			cancel()
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
	if err := waitForPQ(ctx, c); err != nil {
		return err
	}
	slog.Info("Manticore pq table ready", "elapsed", time.Since(start))
	go m.storageLoop(ctx)
	go m.scaleLoop(ctx)
	return runWorker(ctx, c, m)
}

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	if err := serveWorker(ctx); err != nil && ctx.Err() == nil {
		slog.Error("worker exited", "error", err)
		os.Exit(1)
	}
}
