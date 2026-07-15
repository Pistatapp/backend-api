package config

import (
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	HTTPAddr string

	AllowedIPs map[string]struct{}

	RedisAddr     string
	RedisPassword string
	RedisDB       int

	GPSDBHost     string
	GPSDBPort     string
	GPSDBName     string
	GPSDBUser     string
	GPSDBPassword string
	GPSDBPoolMax  int

	MainDBHost     string
	MainDBPort     string
	MainDBName     string
	MainDBUser     string
	MainDBPassword string
	MainDBPoolMax  int

	IngestWorkers       int
	IngestChannelSize   int
	BatchFlushInterval  time.Duration
	BatchFlushSize      int
	BroadcastWorkers    int
	SideEffectWorkers   int
	BroadcastQueueSize  int
	SideEffectQueueSize int

	ReverbAppID  string
	ReverbKey    string
	ReverbSecret string
	ReverbHost   string
	ReverbPort   string
	ReverbScheme string

	DeviceCacheTTL time.Duration
	L1CacheTTL     time.Duration

	HealthChannelMaxDepthPct float64
}

func Load() Config {
	return Config{
		HTTPAddr: getenv("GPS_INGEST_HTTP_ADDR", ":8081"),

		AllowedIPs: parseIPAllowlist(getenv("GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS", "94.101.187.206,127.0.0.1")),

		RedisAddr:     getenv("REDIS_HOST", "127.0.0.1") + ":" + getenv("REDIS_PORT", "6379"),
		RedisPassword: getenv("REDIS_PASSWORD", ""),
		RedisDB:       getenvInt("REDIS_DB", 0),

		GPSDBHost:     getenv("DB_GPS_HOST", getenv("DB_HOST", "127.0.0.1")),
		GPSDBPort:     getenv("DB_GPS_PORT", getenv("DB_PORT", "3306")),
		GPSDBName:     getenv("DB_GPS_DATABASE", getenv("DB_DATABASE", "pistatapp")),
		GPSDBUser:     getenv("DB_GPS_USERNAME", getenv("DB_USERNAME", "root")),
		GPSDBPassword: getenv("DB_GPS_PASSWORD", getenv("DB_PASSWORD", "")),
		GPSDBPoolMax:  getenvInt("DB_GPS_POOL_MAX", 64),

		MainDBHost:     getenv("DB_HOST", "127.0.0.1"),
		MainDBPort:     getenv("DB_PORT", "3306"),
		MainDBName:     getenv("DB_DATABASE", "pistatapp"),
		MainDBUser:     getenv("DB_USERNAME", "root"),
		MainDBPassword: getenv("DB_PASSWORD", ""),
		MainDBPoolMax:  getenvInt("DB_MAIN_POOL_MAX", 16),

		IngestWorkers:       getenvInt("INGEST_WORKERS", 64),
		IngestChannelSize:   getenvInt("INGEST_CHANNEL_SIZE", 100000),
		BatchFlushInterval:  time.Duration(getenvInt("BATCH_FLUSH_INTERVAL_MS", 50)) * time.Millisecond,
		BatchFlushSize:      getenvInt("BATCH_FLUSH_SIZE", 1000),
		BroadcastWorkers:    getenvInt("BROADCAST_WORKERS", 32),
		SideEffectWorkers:   getenvInt("SIDE_EFFECT_WORKERS", 16),
		BroadcastQueueSize:  getenvInt("BROADCAST_QUEUE_SIZE", 50000),
		SideEffectQueueSize: getenvInt("SIDE_EFFECT_QUEUE_SIZE", 50000),

		ReverbAppID:  firstNonEmpty(os.Getenv("REVERB_APP_ID"), os.Getenv("PUSHER_APP_ID")),
		ReverbKey:    firstNonEmpty(os.Getenv("REVERB_APP_KEY"), os.Getenv("PUSHER_APP_KEY")),
		ReverbSecret: firstNonEmpty(os.Getenv("REVERB_APP_SECRET"), os.Getenv("PUSHER_APP_SECRET")),
		ReverbHost:   firstNonEmpty(os.Getenv("REVERB_HOST"), os.Getenv("PUSHER_HOST"), "127.0.0.1"),
		ReverbPort:   firstNonEmpty(os.Getenv("REVERB_PORT"), os.Getenv("PUSHER_PORT"), "8080"),
		ReverbScheme: firstNonEmpty(os.Getenv("REVERB_SCHEME"), os.Getenv("PUSHER_SCHEME"), "http"),

		DeviceCacheTTL: time.Duration(getenvInt("GPS_DEVICE_CACHE_TTL_SECONDS", 3600)) * time.Second,
		L1CacheTTL:     time.Duration(getenvInt("GPS_L1_CACHE_TTL_SECONDS", 300)) * time.Second,

		HealthChannelMaxDepthPct: float64(getenvInt("HEALTH_CHANNEL_MAX_DEPTH_PCT", 80)) / 100.0,
	}
}

func (c Config) GPSDSN() string {
	return c.GPSDBUser + ":" + c.GPSDBPassword + "@tcp(" + c.GPSDBHost + ":" + c.GPSDBPort + ")/" + c.GPSDBName + "?parseTime=true&charset=utf8mb4&loc=Local"
}

func (c Config) MainDSN() string {
	return c.MainDBUser + ":" + c.MainDBPassword + "@tcp(" + c.MainDBHost + ":" + c.MainDBPort + ")/" + c.MainDBName + "?parseTime=true&charset=utf8mb4&loc=Local"
}

func parseIPAllowlist(raw string) map[string]struct{} {
	allowed := make(map[string]struct{})
	for _, ip := range strings.Split(raw, ",") {
		ip = strings.TrimSpace(ip)
		if ip != "" {
			allowed[ip] = struct{}{}
		}
	}
	return allowed
}

func getenv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func getenvInt(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
