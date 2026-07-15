package main

import (
	"context"
	"database/sql"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/device"
	httpserver "github.com/pistat-hamgit/gps-ingest/internal/http"
	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/pipeline"
	"github.com/pistat-hamgit/gps-ingest/internal/storage"
	"github.com/redis/go-redis/v9"
)

func main() {
	cfg := config.Load()
	collector := metrics.NewCollector()

	gpsDB, err := sql.Open("mysql", cfg.GPSDSN())
	if err != nil {
		log.Fatalf("open gps db: %v", err)
	}
	gpsDB.SetMaxOpenConns(cfg.GPSDBPoolMax)
	gpsDB.SetMaxIdleConns(cfg.GPSDBPoolMax / 2)
	gpsDB.SetConnMaxLifetime(30 * time.Minute)

	mainDB, err := sql.Open("mysql", cfg.MainDSN())
	if err != nil {
		log.Fatalf("open main db: %v", err)
	}
	mainDB.SetMaxOpenConns(cfg.MainDBPoolMax)
	mainDB.SetMaxIdleConns(cfg.MainDBPoolMax / 2)
	mainDB.SetConnMaxLifetime(30 * time.Minute)

	redisClient := redis.NewClient(&redis.Options{
		Addr:     cfg.RedisAddr,
		Password: cfg.RedisPassword,
		DB:       cfg.RedisDB,
	})

	resolver := device.NewResolver(
		redisClient,
		mainDB,
		device.NewL1Cache(cfg.L1CacheTTL),
		cfg.DeviceCacheTTL,
		collector,
	)
	writer := storage.NewWriter(gpsDB)
	broadcaster := broadcast.NewClient(cfg)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	pipe := pipeline.New(cfg, collector, resolver, writer, broadcaster, mainDB, redisClient)
	pipe.Start(ctx)

	healthFn := func() bool {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		if err := pipe.Ping(ctx); err != nil {
			return false
		}
		depth := float64(pipe.IngestChannelDepth())
		capacity := float64(pipe.IngestChannelCapacity())
		if capacity == 0 {
			return true
		}
		return depth/capacity < cfg.HealthChannelMaxDepthPct
	}

	server := httpserver.New(cfg.AllowedIPs, pipe, collector, healthFn)
	httpServer := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           server.Handler(),
		ReadTimeout:       5 * time.Second,
		ReadHeaderTimeout: 2 * time.Second,
		WriteTimeout:      5 * time.Second,
		MaxHeaderBytes:    1 << 20,
	}

	go func() {
		log.Printf("gps-ingest listening on %s", cfg.HTTPAddr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("http server: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	cancel()
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer shutdownCancel()
	_ = httpServer.Shutdown(shutdownCtx)
	pipe.Stop()

	_ = gpsDB.Close()
	_ = mainDB.Close()
	_ = redisClient.Close()
}
