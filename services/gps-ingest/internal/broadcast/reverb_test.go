package broadcast

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

func TestSendPreservesExistingReverbContractAgainstIsolatedMock(t *testing.T) {
	var mu sync.Mutex
	var paths []string
	var bodies []map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var payload map[string]any
		if err := json.Unmarshal(body, &payload); err != nil {
			t.Errorf("invalid pusher payload: %v", err)
		}
		mu.Lock()
		paths = append(paths, r.URL.Path)
		bodies = append(bodies, payload)
		mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{}`))
	}))
	defer server.Close()

	cfg := config.Config{
		ReverbAppID:  "test-app",
		ReverbKey:    "test-key",
		ReverbSecret: "test-secret",
		ReverbHost:   strings.TrimPrefix(server.URL, "http://"),
		ReverbScheme: "http",
	}
	client := NewClient(cfg)
	err := client.Send(Job{
		DeviceID: 24, TractorID: 34,
		LastPoint: validate.GpsPoint{
			Coordinate: [2]float64{35.940486, 50.059037},
			DateTime:   "2026-06-20 18:23:27",
			Speed: 12, Status: 1,
			Directions: validate.Directions{EW: 3, NS: 1},
		},
	})
	if err != nil {
		t.Fatalf("isolated Reverb mock rejected existing contract: %v", err)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(paths) != 2 {
		t.Fatalf("expected status and report publishes, got %d", len(paths))
	}
	if paths[0] != "/apps/test-app/events" || paths[1] != "/apps/test-app/events" {
		t.Fatalf("unexpected Pusher endpoint paths: %#v", paths)
	}
	joined := string(mustJSON(bodies))
	for _, want := range []string{"tractor.status.changed", "report-received", "private-gps_devices.24", "latitude", "longitude"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("mock payloads lost existing contract field %q: %s", want, joined)
		}
	}
}

func TestRetryDelayIsBounded(t *testing.T) {
	if got := RetryDelay(1); got != 1*time.Second {
		t.Fatalf("first retry delay = %s", got)
	}
	if got := RetryDelay(20); got != 5*time.Minute {
		t.Fatalf("retry delay must be bounded, got %s", got)
	}
}

func mustJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}
