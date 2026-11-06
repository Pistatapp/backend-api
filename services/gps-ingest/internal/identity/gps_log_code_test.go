package identity

import (
	"testing"
	"time"
)

func TestBuildAndParseGPSLogCode(t *testing.T) {
	timeUTC := time.Date(2026, 11, 6, 0, 27, 42, 987000000, time.FixedZone("test", 3*60*60+30*60))
	code, err := BuildGPSLogCode("861045089210903", timeUTC)
	if err != nil {
		t.Fatal(err)
	}
	if code != "01-861045089210903-20261105205742" {
		t.Fatalf("unexpected code: %s", code)
	}
	imei, parsed, err := ParseGPSLogCode(code)
	if err != nil {
		t.Fatal(err)
	}
	if imei != "861045089210903" || !parsed.Equal(time.Date(2026, 11, 5, 20, 57, 42, 0, time.UTC)) {
		t.Fatalf("unexpected parse: %s %s", imei, parsed)
	}
}

func TestBuildRejectsMissingOrInvalidDeviceTime(t *testing.T) {
	if _, err := BuildGPSLogCode("861045089210903", time.Time{}); err == nil {
		t.Fatal("expected missing timestamp error")
	}
	if _, err := BuildGPSLogCode("not-an-imei", time.Now().UTC()); err == nil {
		t.Fatal("expected invalid IMEI error")
	}
}
