package validate

import (
	"strings"
	"testing"
)

func TestDecodeAndValidateAcceptsValidPayload(t *testing.T) {
	body := `{
		"data": [{
			"coordinate": [35.937893, 50.065403],
			"speed": 0,
			"status": 0,
			"directions": {"ew": 3, "ns": 1},
			"date_time": "2026-02-25 18:49:45",
			"imei": "863070046120282"
		}]
	}`

	req, err := DecodeAndValidate(strings.NewReader(body))
	if err != nil {
		t.Fatalf("expected valid payload, got error: %v", err)
	}
	if len(req.Data) != 1 {
		t.Fatalf("expected 1 point, got %d", len(req.Data))
	}
	if req.Data[0].IMEI != "863070046120282" {
		t.Fatalf("unexpected imei: %s", req.Data[0].IMEI)
	}
}

func TestDecodeAndValidateFiltersEmptyObjects(t *testing.T) {
	body := `{
		"data": [
			{
				"coordinate": [35.937893, 50.065403],
				"speed": 0,
				"status": 0,
				"directions": {"ew": 3, "ns": 1},
				"date_time": "2026-02-25 18:49:45",
				"imei": "863070046120282"
			},
			{}
		]
	}`

	req, err := DecodeAndValidate(strings.NewReader(body))
	if err != nil {
		t.Fatalf("expected valid payload, got error: %v", err)
	}
	if len(req.Data) != 1 {
		t.Fatalf("expected filtered data length 1, got %d", len(req.Data))
	}
}

func TestDecodeAndValidateRejectsEmptyDataArray(t *testing.T) {
	_, err := DecodeAndValidate(strings.NewReader(`{"data":[]}`))
	if err == nil {
		t.Fatal("expected validation error")
	}
}

func TestDecodeAndValidateRejectsInvalidStatus(t *testing.T) {
	body := `{
		"data": [{
			"coordinate": [35.937893, 50.065403],
			"speed": 0,
			"status": 2,
			"directions": {"ew": 3, "ns": 1},
			"date_time": "2026-02-25 18:49:45",
			"imei": "863070046120282"
		}]
	}`

	_, err := DecodeAndValidate(strings.NewReader(body))
	if err == nil {
		t.Fatal("expected validation error for invalid status")
	}
}

func TestDecodeAndValidateRejectsCoordinateOutOfBounds(t *testing.T) {
	body := `{
		"data": [{
			"coordinate": [95.0, 50.065403],
			"speed": 0,
			"status": 0,
			"directions": {"ew": 3, "ns": 1},
			"date_time": "2026-02-25 18:49:45",
			"imei": "863070046120282"
		}]
	}`

	_, err := DecodeAndValidate(strings.NewReader(body))
	if err == nil {
		t.Fatal("expected validation error for latitude")
	}
}
