package storage

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"

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
		TractorID:  tractorID,
		Coordinate: coordinate,
		Speed:      point.Speed,
		Status:     point.Status,
		Directions: directions,
		IMEI:       point.IMEI,
		DateTime:   point.DateTime,
	}, nil
}

func (w *Writer) InsertIgnoreBatch(ctx context.Context, rows []Row) error {
	if len(rows) == 0 {
		return nil
	}

	const cols = 7
	placeholders := make([]string, 0, len(rows))
	args := make([]any, 0, len(rows)*cols)

	for _, row := range rows {
		placeholders = append(placeholders, "(?, ?, ?, ?, ?, ?, ?)")
		args = append(args,
			row.TractorID,
			row.Coordinate,
			row.Speed,
			row.Status,
			row.Directions,
			row.IMEI,
			row.DateTime,
		)
	}

	query := `
		INSERT IGNORE INTO gps_data
			(tractor_id, coordinate, speed, status, directions, imei, date_time)
		VALUES ` + strings.Join(placeholders, ",")

	tx, err := w.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer tx.Rollback()

	if _, err := tx.ExecContext(ctx, query, args...); err != nil {
		return fmt.Errorf("insert batch: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit batch: %w", err)
	}

	return nil
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
