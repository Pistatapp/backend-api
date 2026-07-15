package storage

import (
	"context"
	"database/sql"
	"os"
	"testing"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func TestInsertIgnoreBatchDeduplicatesRows(t *testing.T) {
	dsn := os.Getenv("GPS_TEST_DSN")
	if dsn == "" {
		t.Skip("GPS_TEST_DSN not set")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := db.PingContext(ctx); err != nil {
		t.Skipf("mysql not available: %v", err)
	}

	writer := NewWriter(db)
	imei := "863070046120282"
	dateTime := "2099-01-15 10:00:00"

	row := Row{
		TractorID:  1,
		Coordinate: "[35.937893,50.065403]",
		Speed:      0,
		Status:     0,
		Directions: `{"ew":3,"ns":1}`,
		IMEI:       imei,
		DateTime:   dateTime,
	}

	if err := writer.InsertIgnoreBatch(ctx, []Row{row}); err != nil {
		t.Fatalf("first insert failed: %v", err)
	}
	if err := writer.InsertIgnoreBatch(ctx, []Row{row}); err != nil {
		t.Fatalf("duplicate insert failed: %v", err)
	}

	var count int
	err = db.QueryRowContext(ctx, `
		SELECT COUNT(*) FROM gps_data WHERE imei = ? AND date_time = ?
	`, imei, dateTime).Scan(&count)
	if err != nil {
		t.Fatalf("count query failed: %v", err)
	}
	if count != 1 {
		t.Fatalf("expected 1 row after duplicate insert, got %d", count)
	}

	_, _ = db.ExecContext(ctx, `DELETE FROM gps_data WHERE imei = ? AND date_time = ?`, imei, dateTime)
}
