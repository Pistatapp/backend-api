package ledger

import (
	"bytes"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Status string

const (
	Persisted          Status = "PERSISTED"
	RetryPending       Status = "RETRY_PENDING"
	QuarantinedWithRaw Status = "QUARANTINED_WITH_RAW"
	DLQReplayable      Status = "DLQ_REPLAYABLE"
)

type Event struct {
	EventID           string `json:"event_id"`
	TraceID           string `json:"trace_id,omitempty"`
	IMEI              string `json:"imei,omitempty"`
	DeviceRecordedAt  string `json:"device_recorded_at,omitempty"`
	GatewayReceivedAt string `json:"gateway_received_at,omitempty"`
	PayloadHash       string `json:"payload_hash"`
	RawPayload        string `json:"raw_payload"`
	RawReference      string `json:"raw_reference,omitempty"`
	BatchIndex        int    `json:"batch_index"`
	Status            Status `json:"processing_status"`
	ErrorReason       string `json:"error_reason,omitempty"`
	Attempts          int    `json:"retry_count"`
}

// Store writes the event ledger before the HTTP handler returns success. The
// append-only spool is a last-resort local durable queue when MySQL is down;
// it is never used to hide a failed write.
type Store struct {
	db         *sql.DB
	spoolPath  string
	spoolMutex sync.Mutex
}

func NewStore(db *sql.DB, spoolPath string) *Store {
	return &Store{db: db, spoolPath: spoolPath}
}

// PersistRawBody synchronously appends the exact HTTP body before parsing. The
// returned reference is copied into every item ledger row, which connects the
// parsed event back to the original wire batch.
func (s *Store) PersistRawBody(raw []byte) (string, error) {
	reference := fmt.Sprintf("gps-body:%x", sha256.Sum256(raw))
	record := struct {
		Operation    string `json:"operation"`
		At           string `json:"at"`
		RawReference string `json:"raw_reference"`
		RawBody      string `json:"raw_body_base64"`
	}{
		Operation: "body", At: time.Now().UTC().Format(time.RFC3339Nano),
		RawReference: reference, RawBody: base64.StdEncoding.EncodeToString(raw),
	}
	return reference, s.appendRecord(record)
}

func (s *Store) RecordPending(ctx context.Context, events []Event) error {
	if len(events) == 0 {
		return nil
	}
	if s.db != nil {
		tx, err := s.db.BeginTx(ctx, nil)
		if err == nil {
			const query = `
				INSERT INTO gps_ingest_events
				(event_id, trace_id, imei, device_recorded_at, gateway_received_at,
				 payload_hash, raw_payload, raw_reference, batch_index, status, error_reason, attempts, created_at, updated_at)
				VALUES (?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, NOW(), NOW())
				ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)`
			for _, event := range events {
				if _, err = tx.ExecContext(ctx, query,
					event.EventID, event.TraceID, event.IMEI, event.DeviceRecordedAt,
					event.GatewayReceivedAt, event.PayloadHash, event.RawPayload,
					event.RawReference, event.BatchIndex, string(event.Status), event.ErrorReason, event.Attempts,
				); err != nil {
					_ = tx.Rollback()
					break
				}
			}
			if err == nil {
				err = tx.Commit()
			}
			if err == nil {
				return nil
			}
		}
	}

	if err := s.appendSpool("pending", events); err != nil {
		return fmt.Errorf("record pending ledger: %w", err)
	}
	return nil
}

func (s *Store) Mark(ctx context.Context, eventID string, status Status, reason string) error {
	if eventID == "" {
		return fmt.Errorf("cannot mark ledger event with empty event_id")
	}
	if s.db != nil {
		result, err := s.db.ExecContext(ctx, `
			UPDATE gps_ingest_events
			SET status = ?, error_reason = NULLIF(?, ''),
			    attempts = CASE WHEN ? = ? THEN attempts ELSE attempts + 1 END,
			    persisted_at = CASE WHEN ? = ? THEN COALESCE(persisted_at, NOW()) ELSE persisted_at END,
			    updated_at = NOW()
			WHERE event_id = ?`, string(status), reason, string(status), string(Persisted), string(status), string(Persisted), eventID)
		if err == nil {
			if affected, affectedErr := result.RowsAffected(); affectedErr == nil && affected > 0 {
				return nil
			}
			var exists int
			if queryErr := s.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM gps_ingest_events WHERE event_id = ?`, eventID).Scan(&exists); queryErr == nil && exists > 0 {
				return nil
			}
		}
	}

	attempts := 0
	if status != Persisted {
		attempts = 1
	}
	event := Event{EventID: eventID, Status: status, ErrorReason: reason, Attempts: attempts, GatewayReceivedAt: time.Now().Format("2006-01-02 15:04:05")}
	if err := s.appendSpool("status", []Event{event}); err != nil {
		return fmt.Errorf("mark ledger event %s: %w", eventID, err)
	}
	return nil
}

func (s *Store) Pending(ctx context.Context, limit int) ([]Event, error) {
	if limit <= 0 || limit > 1000 {
		limit = 1000
	}
	result := make([]Event, 0)
	var dbErr error
	if s.db != nil {
		rows, err := s.db.QueryContext(ctx, `
			SELECT event_id, COALESCE(trace_id,''), COALESCE(imei,''),
			       COALESCE(DATE_FORMAT(device_recorded_at, '%Y-%m-%d %H:%i:%s'), ''),
			       COALESCE(DATE_FORMAT(gateway_received_at, '%Y-%m-%d %H:%i:%s'), ''),
			       payload_hash, raw_payload, COALESCE(raw_reference,''), batch_index, status, COALESCE(error_reason,''), attempts
			FROM gps_ingest_events
			WHERE status = ?
			ORDER BY id ASC
			LIMIT ?`, string(RetryPending), limit)
		if err != nil {
			dbErr = err
		} else {
			for rows.Next() {
				var event Event
				var status string
				if err := rows.Scan(&event.EventID, &event.TraceID, &event.IMEI, &event.DeviceRecordedAt,
					&event.GatewayReceivedAt, &event.PayloadHash, &event.RawPayload, &event.RawReference,
					&event.BatchIndex, &status, &event.ErrorReason, &event.Attempts); err != nil {
					_ = rows.Close()
					return nil, err
				}
				event.Status = Status(status)
				result = append(result, event)
			}
			dbErr = rows.Err()
			_ = rows.Close()
		}
	}

	spoolEvents, spoolErr := s.readSpoolPending()
	if spoolErr != nil && dbErr != nil {
		return nil, fmt.Errorf("pending ledger unavailable: db=%v spool=%w", dbErr, spoolErr)
	}
	result = mergePendingEvents(result, spoolEvents, limit)
	if dbErr != nil && len(result) == 0 {
		return nil, dbErr
	}
	return result, nil
}

func (s *Store) readSpoolPending() ([]Event, error) {
	s.spoolMutex.Lock()
	defer s.spoolMutex.Unlock()
	if s.spoolPath == "" {
		return nil, nil
	}
	data, err := os.ReadFile(s.spoolPath)
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	type spoolRecord struct {
		Operation string `json:"operation"`
		Event     Event  `json:"event"`
	}
	latest := make(map[string]Event)
	for _, line := range bytes.Split(data, []byte{'\n'}) {
		if len(bytes.TrimSpace(line)) == 0 {
			continue
		}
		var record spoolRecord
		if json.Unmarshal(line, &record) != nil || record.Event.EventID == "" {
			continue
		}
		if record.Operation == "pending" {
			latest[record.Event.EventID] = record.Event
			continue
		}
		if record.Operation == "status" {
			event := latest[record.Event.EventID]
			event.EventID = record.Event.EventID
			event.Status = record.Event.Status
			event.ErrorReason = record.Event.ErrorReason
			event.Attempts++
			latest[record.Event.EventID] = event
		}
	}
	result := make([]Event, 0, len(latest))
	for _, event := range latest {
		if event.Status == RetryPending {
			result = append(result, event)
		}
	}
	return result, nil
}

func mergePendingEvents(databaseEvents, spoolEvents []Event, limit int) []Event {
	merged := make(map[string]Event, len(databaseEvents)+len(spoolEvents))
	for _, event := range databaseEvents {
		merged[event.EventID] = event
	}
	for _, event := range spoolEvents {
		if existing, ok := merged[event.EventID]; !ok || existing.RawPayload == "" {
			merged[event.EventID] = event
		}
	}
	result := make([]Event, 0, len(merged))
	for _, event := range merged {
		if event.Status == RetryPending {
			result = append(result, event)
		}
		if len(result) >= limit {
			break
		}
	}
	return result
}

func (s *Store) appendSpool(operation string, events []Event) error {
	for _, event := range events {
		line, marshalErr := json.Marshal(struct {
			Operation string `json:"operation"`
			At        string `json:"at"`
			Event     Event  `json:"event"`
		}{operation, time.Now().UTC().Format(time.RFC3339Nano), event})
		if marshalErr != nil {
			return marshalErr
		}
		if err := s.appendLine(line); err != nil {
			return err
		}
	}
	return nil
}

func (s *Store) appendRecord(record any) error {
	s.spoolMutex.Lock()
	defer s.spoolMutex.Unlock()
	if s.spoolPath == "" {
		return fmt.Errorf("durable ledger spool path is empty")
	}
	if err := os.MkdirAll(filepath.Dir(s.spoolPath), 0o750); err != nil {
		return err
	}
	file, err := os.OpenFile(s.spoolPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o640)
	if err != nil {
		return err
	}
	defer file.Close()
	line, err := json.Marshal(record)
	if err != nil {
		return err
	}
	if _, err := file.Write(append(line, '\n')); err != nil {
		return err
	}
	if err := file.Sync(); err != nil {
		return err
	}
	return nil
}

func (s *Store) appendLine(line []byte) error {
	s.spoolMutex.Lock()
	defer s.spoolMutex.Unlock()
	if s.spoolPath == "" {
		return fmt.Errorf("durable ledger spool path is empty")
	}
	if err := os.MkdirAll(filepath.Dir(s.spoolPath), 0o750); err != nil {
		return err
	}
	file, err := os.OpenFile(s.spoolPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o640)
	if err != nil {
		return err
	}
	defer file.Close()
	if _, err := file.Write(append(line, '\n')); err != nil {
		return err
	}
	return file.Sync()
}
