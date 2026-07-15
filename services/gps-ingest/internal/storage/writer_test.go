package storage

import (
	"testing"

	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

func TestBuildRowEncodesJSONFields(t *testing.T) {
	point := validate.GpsPoint{
		IMEI:       "863070046120282",
		Coordinate: [2]float64{35.937893, 50.065403},
		DateTime:   "2026-02-25 18:49:45",
		Speed:      12,
		Status:     1,
		Directions: validate.Directions{EW: 3, NS: 1},
	}

	row, err := BuildRow(42, point)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if row.TractorID != 42 {
		t.Fatalf("unexpected tractor id: %d", row.TractorID)
	}
	if row.Coordinate != "[35.937893,50.065403]" {
		t.Fatalf("unexpected coordinate encoding: %s", row.Coordinate)
	}
	if row.Directions != `{"ew":3,"ns":1}` {
		t.Fatalf("unexpected directions encoding: %s", row.Directions)
	}
}
