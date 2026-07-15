package httpserver

import (
	"encoding/json"
	"io"
	"net/http"

	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/pipeline"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

var (
	successBody   = []byte(`{"success":true}`)
	forbiddenBody = []byte(`{"message":"Forbidden."}`)
)

type IngestEnqueuer interface {
	Enqueue(batch pipeline.IngestBatch) bool
}

type Server struct {
	allowlist Allowlist
	pipeline  IngestEnqueuer
	metrics   *metrics.Collector
	healthFn  func() bool
}

func New(allowlist map[string]struct{}, pipe IngestEnqueuer, collector *metrics.Collector, healthFn func() bool) *Server {
	return &Server{
		allowlist: NewAllowlist(allowlist),
		pipeline:  pipe,
		metrics:   collector,
		healthFn:  healthFn,
	}
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/api/gps/reports", s.handleGPSReports)
	mux.HandleFunc("/healthz", s.handleHealthz)
	mux.Handle("/metrics", s.metrics.Handler())
	return mux
}

func (s *Server) handleGPSReports(w http.ResponseWriter, r *http.Request) {
	s.metrics.IngestRequestsTotal.Add(1)

	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		return
	}

	if !s.allowlist.Allowed(ClientIP(r)) {
		s.metrics.IngestRejectedTotal.Add(1)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write(forbiddenBody)
		return
	}

	req, err := validate.DecodeAndValidate(io.LimitReader(r.Body, 1<<20))
	if err != nil {
		s.metrics.IngestRejectedTotal.Add(1)
		writeValidationError(w, err)
		return
	}

	if !s.pipeline.Enqueue(pipeline.IngestBatch{Points: req.Data}) {
		s.metrics.IngestRejectedTotal.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	s.metrics.IngestAcceptedTotal.Add(1)
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(successBody)
}

func (s *Server) handleHealthz(w http.ResponseWriter, r *http.Request) {
	if s.healthFn != nil && !s.healthFn() {
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = w.Write([]byte("unhealthy"))
		return
	}
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte("ok"))
}

func writeValidationError(w http.ResponseWriter, err error) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusUnprocessableEntity)

	validationErr, ok := err.(*validate.ValidationError)
	if !ok {
		_ = json.NewEncoder(w).Encode(map[string]string{"message": err.Error()})
		return
	}

	_ = json.NewEncoder(w).Encode(map[string]any{
		"message": validationErr.Message,
		"errors":  validationErr.Errors,
	})
}
