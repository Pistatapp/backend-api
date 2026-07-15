package httpserver

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/pipeline"
)

type stubEnqueuer struct{}

func (stubEnqueuer) Enqueue(batch pipeline.IngestBatch) bool {
	_ = batch
	return true
}

func TestHandleGPSReportsAcceptsValidPayload(t *testing.T) {
	server := New(map[string]struct{}{"127.0.0.1": {}}, stubEnqueuer{}, metrics.NewCollector(), func() bool { return true })

	body := `{"data":[{"coordinate":[35.937893,50.065403],"speed":0,"status":0,"directions":{"ew":3,"ns":1},"date_time":"2026-02-25 18:49:45","imei":"863070046120282"}]}`
	req := httptest.NewRequest(http.MethodPost, "/api/gps/reports", strings.NewReader(body))
	req.RemoteAddr = "127.0.0.1:12345"
	rr := httptest.NewRecorder()

	server.handleGPSReports(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rr.Code, rr.Body.String())
	}
}

func TestHandleGPSReportsRejectsForbiddenIP(t *testing.T) {
	server := New(map[string]struct{}{"94.101.187.206": {}}, stubEnqueuer{}, metrics.NewCollector(), nil)
	body := `{"data":[{"coordinate":[35.937893,50.065403],"speed":0,"status":0,"directions":{"ew":3,"ns":1},"date_time":"2026-02-25 18:49:45","imei":"863070046120282"}]}`
	req := httptest.NewRequest(http.MethodPost, "/api/gps/reports", strings.NewReader(body))
	req.RemoteAddr = "203.0.113.10:12345"
	rr := httptest.NewRecorder()

	server.handleGPSReports(rr, req)

	if rr.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d", rr.Code)
	}
}

func BenchmarkHandleGPSReports(b *testing.B) {
	server := New(map[string]struct{}{"127.0.0.1": {}}, stubEnqueuer{}, metrics.NewCollector(), func() bool { return true })
	body := `{"data":[{"coordinate":[35.937893,50.065403],"speed":0,"status":0,"directions":{"ew":3,"ns":1},"date_time":"2026-02-25 18:49:45","imei":"863070046120282"}]}`

	b.ReportAllocs()
	b.ResetTimer()

	for i := 0; i < b.N; i++ {
		req := httptest.NewRequest(http.MethodPost, "/api/gps/reports", strings.NewReader(body))
		req.RemoteAddr = "127.0.0.1:12345"
		rr := httptest.NewRecorder()
		server.handleGPSReports(rr, req)
	}
}
