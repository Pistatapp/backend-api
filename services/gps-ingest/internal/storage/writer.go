package storage

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

type Row struct {
	TractorID  int64
	Coordinate string
	Speed      int
	Status     int
	Directions string
	IMEI       string
	DateTime   string
	// EventID links the durable GPS row back to the ingest ledger and provenance
	// metadata. The nullable column keeps legacy rows backward-compatible.
	EventID                string
	SourceProtocol         string
	EventTimeUTC           string
	EventTimeTehran        string
	EventDateJalali        string
	Timezone               string
	TimeProvenance         string
	OtherOriginalGPSFields map[string]any
	GatewayReceivedAtUTC   string
	BatchID                string
}

type Writer struct {
	db *sql.DB
}

func NewWriter(db *sql.DB) *Writer {
	return &Writer{db: db}
}

func BuildRow(tractorID int64, point validate.GpsPoint) (Row, error) {
	coordinate, err := marshalJSON(point.Coordinate[:])
	if err != nil {
		return Row{}, err
	}
	directions, err := marshalJSON(point.Directions)
	if err != nil {
		return Row{}, err
	}

	return Row{
		TractorID:              tractorID,
		Coordinate:             coordinate,
		Speed:                  point.Speed,
		Status:                 point.Status,
		Directions:             directions,
		IMEI:                   point.IMEI,
		DateTime:               point.DateTime,
		EventID:                point.EventID,
		SourceProtocol:         point.SourceProtocol,
		EventTimeUTC:           point.EventTimeUTC,
		EventTimeTehran:        point.EventTimeTehran,
		EventDateJalali:        point.EventDateJalali,
		Timezone:               point.Timezone,
		TimeProvenance:         point.TimeProvenance,
		OtherOriginalGPSFields: point.OtherOriginalGPSFields,
	}, nil
}

func (w *Writer) InsertBatch(ctx context.Context, rows []Row) error {
	return w.InsertBatchWithBroadcast(ctx, rows, nil)
}

// InsertBatchWithBroadcast atomically commits historical GPS rows and their
// post-persistence Reverb outbox records. A failed outbox insert rolls back
// the GPS transaction, leaving the ledger event retryable instead of silently
// losing its live notification.
func (w *Writer) InsertBatchWithBroadcast(ctx context.Context, rows []Row, outboxRows []broadcast.OutboxRecord) error {
	if len(rows) == 0 {
		return nil
	}

	tx, err := w.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer tx.Rollback()

	for _, row := range rows {
		// The ledger prevents normal replays from being re-enqueued, while this
		// exact-row guard closes the crash window between gps_data COMMIT and
		// ledger=PERSISTED. Different coordinates at the same device timestamp
		// do not match and are therefore retained.
		var existingID int64
		existingErr := tx.QueryRowContext(ctx, `
			SELECT id FROM gps_data
			WHERE tractor_id = ? AND coordinate = ? AND speed = ? AND status = ?
			  AND directions = ? AND imei = ? AND date_time = ?
			LIMIT 1`, row.TractorID, row.Coordinate, row.Speed, row.Status,
			row.Directions, row.IMEI, row.DateTime).Scan(&existingID)
		if existingErr == nil {
			if err := w.insertIstartekProvenance(ctx, tx, row, existingID); err != nil {
				return err
			}
			continue
		}
		if existingErr != sql.ErrNoRows {
			return fmt.Errorf("check duplicate row: %w", existingErr)
		}
		result, err := tx.ExecContext(ctx, `
			INSERT INTO gps_data
				(tractor_id, coordinate, speed, status, directions, imei, date_time, event_id)
			VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''))`, row.TractorID, row.Coordinate, row.Speed,
			row.Status, row.Directions, row.IMEI, row.DateTime, row.EventID)
		if err != nil {
			return fmt.Errorf("insert batch: %w", err)
		}
		insertedID, err := result.LastInsertId()
		if err != nil {
			return fmt.Errorf("resolve inserted gps_data id: %w", err)
		}
		if err := w.insertIstartekProvenance(ctx, tx, row, insertedID); err != nil {
			return err
		}
	}

	for _, outboxRow := range outboxRows {
		if err := broadcast.NewStore(w.db).InsertTx(ctx, tx, outboxRow); err != nil {
			return fmt.Errorf("persist broadcast outbox: %w", err)
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit batch: %w", err)
	}

	return nil
}

func (w *Writer) insertIstartekProvenance(ctx context.Context, tx *sql.Tx, row Row, gpsDataID int64) error {
	if strings.ToLower(strings.TrimSpace(row.SourceProtocol)) != "istartek" && !strings.HasPrefix(strings.ToLower(strings.TrimSpace(row.EventID)), "ist-") {
		return nil
	}
	if row.EventID == "" || row.EventTimeUTC == "" || row.EventDateJalali == "" || row.Timezone == "" {
		return fmt.Errorf("istartek time contract is incomplete for gps_data_id=%d", gpsDataID)
	}

	eventUTC, err := parseGatewayUTC(row.EventTimeUTC)
	if err != nil {
		return fmt.Errorf("invalid istartek event_time_utc for event %s: %w", row.EventID, err)
	}
	location, err := time.LoadLocation(row.Timezone)
	if err != nil {
		return fmt.Errorf("invalid istartek timezone for event %s: %w", row.EventID, err)
	}
	eventTehran := eventUTC.In(location)
	clock := eventTehran.Format("15:04:05")
	providedClock := strings.TrimSpace(row.EventTimeTehran)
	if len(providedClock) >= 8 {
		providedClock = providedClock[len(providedClock)-8:]
	}
	if providedClock != "" && providedClock != clock {
		return fmt.Errorf("istartek Tehran clock conflicts with UTC contract for event %s", row.EventID)
	}

	otherFields := row.OtherOriginalGPSFields
	if otherFields == nil {
		otherFields = map[string]any{}
	}
	otherJSON, err := json.Marshal(otherFields)
	if err != nil {
		return fmt.Errorf("marshal istartek original fields for event %s: %w", row.EventID, err)
	}
	provenance := row.TimeProvenance
	if provenance == "" {
		provenance = "gateway_event_time_utc"
	}
	batchID := row.BatchID
	if batchID == "" {
		batchID = row.EventID
	}
	var received any
	if strings.TrimSpace(row.GatewayReceivedAtUTC) != "" {
		received, err = parseGatewayUTC(row.GatewayReceivedAtUTC)
		if err != nil {
			return fmt.Errorf("invalid gateway_received_at_utc for event %s: %w", row.EventID, err)
		}
		received = received.(time.Time).Format("2006-01-02 15:04:05.000000")
	}
	utcValue := eventUTC.Format("2006-01-02 15:04:05.000000")
	tehranValue := eventTehran.Format("2006-01-02 15:04:05.000000")
	common := []any{
		gpsDataID, row.DateTime, row.TractorID, row.IMEI, "istartek", utcValue,
		tehranValue, row.EventDateJalali, clock, row.Timezone, string(otherJSON),
		received, "gateway", provenance, "verified", batchID,
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO gps_event_time_provenance_events
			(event_id, gps_data_id, gps_date_time, tractor_id, imei, source_protocol,
			 event_time_utc, event_time_tehran, event_date_jalali, event_time_tehran_clock,
			 timezone, other_original_gps_fields, gateway_received_at_utc, time_source,
			 time_provenance, verification_status, batch_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6), NOW(6))
		ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)`,
		append([]any{row.EventID}, common...)...); err != nil {
		return fmt.Errorf("persist istartek event provenance audit %s: %w", row.EventID, err)
	}

	var canonicalEvent sql.NullString
	canonicalErr := tx.QueryRowContext(ctx,
		`SELECT event_id FROM gps_event_time_provenance WHERE gps_data_id = ? AND gps_date_time = ? LIMIT 1`,
		gpsDataID, row.DateTime).Scan(&canonicalEvent)
	if canonicalErr != nil && canonicalErr != sql.ErrNoRows {
		return fmt.Errorf("check istartek canonical provenance %s: %w", row.EventID, canonicalErr)
	}
	if canonicalErr == sql.ErrNoRows {
		canonicalValues := []any{
			gpsDataID, row.DateTime, row.TractorID, row.EventID, row.IMEI, "istartek",
			utcValue, tehranValue, row.EventDateJalali, row.Timezone, string(otherJSON),
			received, "gateway", provenance, "verified", batchID,
		}
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO gps_event_time_provenance
				(gps_data_id, gps_date_time, tractor_id, event_id, imei, source_protocol,
				 event_time_utc, event_time_tehran, event_date_jalali, timezone,
				 other_original_gps_fields, gateway_received_at_utc, time_source,
				 time_provenance, verification_status, batch_id, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6), NOW(6))`,
			canonicalValues...); err != nil {
			return fmt.Errorf("persist istartek canonical provenance %s: %w", row.EventID, err)
		}
	}
	return nil
}

func parseGatewayUTC(value string) (time.Time, error) {
	value = strings.TrimSpace(value)
	if parsed, err := time.Parse(time.RFC3339Nano, value); err == nil {
		return parsed.UTC(), nil
	}
	return time.ParseInLocation("2006-01-02 15:04:05", value, time.UTC)
}

// InsertIgnoreBatch is retained as a source-compatible name for callers and
// tests, but it deliberately performs a normal INSERT. INSERT IGNORE hid
// constraint/data errors and violated the ingest durability contract.
func (w *Writer) InsertIgnoreBatch(ctx context.Context, rows []Row) error {
	return w.InsertBatch(ctx, rows)
}

func (w *Writer) Ping(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	return w.db.PingContext(ctx)
}

func marshalJSON(v any) (string, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return "", err
	}
	return string(b), nil
}
