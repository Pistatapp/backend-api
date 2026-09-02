package validate

import (
	"strings"
	"testing"
)

func TestDecodeIngressLegacySingleAndNMEA(t *testing.T) {
	raw := `[{"data":"+Hooshnic:V1.06,3556.42915,05003.5422,000,260620,145327,004,000,0,3,1,867717034662222"}]`
	ingress, err := DecodeIngress(strings.NewReader(raw))
	if err != nil || len(ingress.Items) != 1 || ingress.Items[0].Point == nil {
		t.Fatalf("unexpected decode: err=%v ingress=%+v", err, ingress)
	}
	point := ingress.Items[0].Point
	if point.Coordinate[0] != 35.940486 || point.Coordinate[1] != 50.059037 {
		t.Fatalf("unexpected NMEA conversion: %#v", point.Coordinate)
	}
	if ingress.Items[0].DeviceRecordedAt != "2026-06-20 14:53:27" || ingress.Items[0].DeviceTimestampRaw != "260620145327" {
		t.Fatalf("unexpected device timestamp: %s %s", ingress.Items[0].DeviceRecordedAt, ingress.Items[0].DeviceTimestampRaw)
	}
}

func TestDecodeIngressGluedNoiseCRLFNULAndHistoricalEscape(t *testing.T) {
	raw := "NOISE\r\n\x00[{\"data\":\"+Hooshnic\\:V1.06,3556.42915,05003.5422,000,260620,145327,004,000,0,3,1,867717034662222\"}{\"data\":\"+Hooshnic:V1.06,3556.50000,05003.6000,000,260620,145328,004,000,0,3,1,868064071065855\"}]......"
	ingress, err := DecodeIngress(strings.NewReader(raw))
	if err != nil || len(ingress.Items) != 2 {
		t.Fatalf("expected two glued items: err=%v items=%d", err, len(ingress.Items))
	}
	for _, item := range ingress.Items {
		if item.Point == nil || item.ErrorReason != "" {
			t.Fatalf("unexpected item: %+v", item)
		}
	}
	if ingress.Items[0].Point.IMEI == ingress.Items[1].Point.IMEI {
		t.Fatal("batch items must retain independent IMEIs")
	}
}

func TestDecodeIngressBadMiddleItemDoesNotDropFollowingItem(t *testing.T) {
	raw := `[{"data":"+Hooshnic:V1.06,3556.42915,05003.5422,000,260620,145327,004,000,0,3,1,867717034662222"}{"data":"bad"}{"data":"+Hooshnic:V1.06,3556.50000,05003.6000,000,260620,145328,004,000,0,3,1,868064071065855"}]`
	ingress, err := DecodeIngress(strings.NewReader(raw))
	if err != nil || len(ingress.Items) != 3 {
		t.Fatalf("expected three independent items: err=%v items=%d", err, len(ingress.Items))
	}
	if ingress.Items[1].Point != nil || ingress.Items[1].ErrorReason == "" {
		t.Fatalf("middle item was not quarantinable: %+v", ingress.Items[1])
	}
	if ingress.Items[2].Point == nil || ingress.Items[2].Point.IMEI != "868064071065855" {
		t.Fatalf("following valid item was dropped: %+v", ingress.Items[2])
	}
}

func TestDecodeIngressThousandItemsAndOutOfOrderTimestamps(t *testing.T) {
	var builder strings.Builder
	builder.WriteString("[")
	for i := 0; i < 1000; i++ {
		if i > 0 {
			builder.WriteString(",")
		}
		builder.WriteString(`{"imei":"867994064030931","coordinate":[35.9,50.0],"speed":0,"status":0,"directions":{"ew":3,"ns":1},"date_time":"2026-06-20 14:00:00"}`)
	}
	builder.WriteString("]")
	ingress, err := DecodeIngress(strings.NewReader(builder.String()))
	if err != nil || len(ingress.Items) != 1000 {
		t.Fatalf("expected 1000 items: err=%v items=%d", err, len(ingress.Items))
	}
	for i, item := range ingress.Items {
		if item.Index != i || item.Point == nil {
			t.Fatalf("item %d not valid/indexed: %+v", i, item)
		}
	}
}

func TestDecodeIngressNormalizedContractAndRFC3339(t *testing.T) {
	raw := `{"data":[{"event_id":"evt-1","imei":"868064071065855","coordinate":[35.940486,50.059037],"speed":12,"status":1,"directions":{"ew":3,"ns":1},"date_time":"2026-06-20T14:53:27Z"}]}`
	ingress, err := DecodeIngress(strings.NewReader(raw))
	if err != nil || len(ingress.Items) != 1 || ingress.Items[0].Point == nil {
		t.Fatalf("unexpected normalized decode: err=%v ingress=%+v", err, ingress)
	}
	if ingress.Items[0].EventID != "evt-1" || ingress.Items[0].Point.DateTime != "2026-06-20 18:23:27" {
		t.Fatalf("normalized metadata/contract mismatch: %+v", ingress.Items[0])
	}
}
