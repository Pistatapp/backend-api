package device

import (
	"encoding/json"
	"testing"
)

func TestMappingUnmarshalLaravelRedisPayload(t *testing.T) {
	var mapping Mapping
	err := json.Unmarshal([]byte(`{"tractor_id":42,"device_id":7}`), &mapping)
	if err != nil {
		t.Fatalf("unmarshal failed: %v", err)
	}
	if mapping.TractorID != 42 || mapping.DeviceID != 7 {
		t.Fatalf("unexpected mapping: %+v", mapping)
	}
}

func TestMappingMarshalMatchesLaravelFormat(t *testing.T) {
	payload, err := json.Marshal(Mapping{TractorID: 42, DeviceID: 7})
	if err != nil {
		t.Fatalf("marshal failed: %v", err)
	}

	expected := `{"tractor_id":42,"device_id":7}`
	if string(payload) != expected {
		t.Fatalf("expected %s got %s", expected, string(payload))
	}
}

func TestCachePayloadMatchesLaravelFormat(t *testing.T) {
	if got := CachePayload(42, 7); got != `{"tractor_id":42,"device_id":7}` {
		t.Fatalf("unexpected cache payload: %s", got)
	}
}
