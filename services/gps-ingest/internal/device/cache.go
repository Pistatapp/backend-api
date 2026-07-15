package device

import (
	"time"
)

type Mapping struct {
	TractorID int64 `json:"tractor_id"`
	DeviceID  int64 `json:"device_id"`
}

type CacheEntry struct {
	Value    Mapping
	ExpireAt time.Time
}

type L1Cache struct {
	ttl time.Duration
}

func NewL1Cache(ttl time.Duration) *L1Cache {
	return &L1Cache{ttl: ttl}
}

func (c *L1Cache) TTL() time.Duration {
	return c.ttl
}
