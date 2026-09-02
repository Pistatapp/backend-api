package httpserver

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/ledger"
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
	ledger    *ledger.Store
}

func New(allowlist map[string]struct{}, pipe IngestEnqueuer, collector *metrics.Collector, healthFn func() bool, stores ...*ledger.Store) *Server {
	var eventLedger *ledger.Store
	if len(stores) > 0 {
		eventLedger = stores[0]
	}
	return &Server{
		allowlist: NewAllowlist(allowlist),
		pipeline:  pipe,
		metrics:   collector,
		healthFn:  healthFn,
		ledger:    eventLedger,
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

	rawBody, readErr := io.ReadAll(io.LimitReader(r.Body, 8<<20+1))
	var rawReference string
	if s.ledger != nil {
		var persistErr error
		rawReference, persistErr = s.ledger.PersistRawBody(rawBody)
		if persistErr != nil {
			s.metrics.LedgerErrorsTotal.Add(1)
			s.metrics.IngestRejectedTotal.Add(1)
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
	}
	if readErr != nil || len(rawBody) > 8<<20 {
		s.metrics.IngestRejectedTotal.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}
	ingress, err := validate.DecodeIngress(bytes.NewReader(rawBody))
	if err != nil {
		s.metrics.IngestRejectedTotal.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	traceID := strings.TrimSpace(r.Header.Get("X-Trace-Id"))
	if traceID == "" {
		traceID = requestTraceID(ingress)
	}
	receivedAt := normalizeReceivedAt(r.Header.Get("X-Gateway-Received-At"))
	if receivedAt == "" {
		receivedAt = time.Now().Format("2006-01-02 15:04:05")
	}

	validPoints := make([]validate.GpsPoint, 0, len(ingress.Items))
	validEvents := make([]ledger.Event, 0, len(ingress.Items))
	allEvents := make([]ledger.Event, 0, len(ingress.Items))
	for _, item := range ingress.Items {
		event := eventFromItem(item, traceID, receivedAt)
		if rawReference != "" {
			event.RawReference = rawReference + "#item=" + fmt.Sprint(item.Index)
		}
		if item.Point == nil {
			event.Status = ledger.QuarantinedWithRaw
			if event.ErrorReason == "" {
				event.ErrorReason = "Invalid GPS item"
			}
			s.metrics.QuarantinedItemsTotal.Add(1)
		} else {
			event.Status = ledger.RetryPending
			validPoints = append(validPoints, *item.Point)
			validEvents = append(validEvents, event)
		}
		allEvents = append(allEvents, event)
	}

	if s.ledger == nil {
		// Test doubles may omit persistence. Production construction always
		// supplies a ledger; refusing a real request without it is safer than
		// acknowledging an untracked event.
		if len(allEvents) == 0 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
	} else if err := s.ledger.RecordPending(r.Context(), allEvents); err != nil {
		s.metrics.LedgerErrorsTotal.Add(1)
		s.metrics.IngestRejectedTotal.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	if len(validPoints) == 0 {
		// Invalid input is explicitly durable in QUARANTINED_WITH_RAW before
		// success, so no malformed item disappears and the old response shape
		// remains unchanged.
		s.metrics.IngestAcceptedTotal.Add(1)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(successBody)
		return
	}

	if !s.pipeline.Enqueue(pipeline.IngestBatch{Points: validPoints, Events: validEvents}) {
		s.metrics.IngestRejectedTotal.Add(1)
		// Events remain RETRY_PENDING in the durable ledger; a retry from the
		// Gateway or the recovery scanner can safely enqueue them later.
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	s.metrics.IngestAcceptedTotal.Add(1)
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(successBody)
}

func eventFromItem(item validate.IngressItem, traceID, receivedAt string) ledger.Event {
	payloadHash := fmt.Sprintf("%x", sha256.Sum256([]byte(item.RawPayload)))
	imei, deviceAt := "", item.DeviceRecordedAt
	if item.Point != nil {
		imei = item.Point.IMEI
		if deviceAt == "" {
			deviceAt = item.Point.DateTime
		}
	}
	eventID := strings.TrimSpace(item.EventID)
	if eventID == "" {
		eventID = fmt.Sprintf("%x", sha256.Sum256([]byte(imei+"|"+deviceAt+"|"+payloadHash+"|"+fmt.Sprint(item.Index))))
	}
	if len(eventID) > 64 {
		eventID = eventID[:64]
	}
	return ledger.Event{
		EventID: eventID, TraceID: traceID, IMEI: imei,
		DeviceRecordedAt: deviceAt, GatewayReceivedAt: receivedAt,
		PayloadHash: payloadHash, RawPayload: item.RawPayload,
		RawReference: "gps-ingest:" + eventID,
		BatchIndex:   item.Index, ErrorReason: item.ErrorReason,
	}
}

func requestTraceID(ingress *validate.Ingress) string {
	h := sha256.New()
	for _, item := range ingress.Items {
		_, _ = io.WriteString(h, item.RawPayload)
	}
	return fmt.Sprintf("ingress-%x", h.Sum(nil)[:16])
}

func normalizeReceivedAt(value string) string {
	value = strings.TrimSpace(value)
	if value == "" {
		return ""
	}
	if t, err := time.Parse(time.RFC3339, value); err == nil {
		return t.In(time.Local).Format("2006-01-02 15:04:05")
	}
	if t, err := time.Parse("2006-01-02 15:04:05", value); err == nil {
		return t.Format("2006-01-02 15:04:05")
	}
	return ""
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
