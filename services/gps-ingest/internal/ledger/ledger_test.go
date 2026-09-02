package ledger

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestStoreSpoolIsDurableWhenDatabaseUnavailable(t *testing.T) {
	path := t.TempDir() + "/gps-ingest-ledger.ndjson"
	store := NewStore(nil, path)
	ref, err := store.PersistRawBody([]byte("raw-body\x00"))
	if err != nil || ref == "" {
		t.Fatalf("raw body was not durably spooled: ref=%s err=%v", ref, err)
	}
	event := Event{
		EventID: "evt-1", TraceID: "trace-1", IMEI: "867717034662222",
		DeviceRecordedAt: "2026-06-20 14:53:27", GatewayReceivedAt: "2026-06-20 14:54:00",
		PayloadHash: "hash", RawPayload: `{"data":"raw"}`, RawReference: "gps-ingest:evt-1",
		BatchIndex: 2, Status: RetryPending,
	}
	if err := store.RecordPending(context.Background(), []Event{event}); err != nil {
		t.Fatal(err)
	}
	if err := store.Mark(context.Background(), event.EventID, QuarantinedWithRaw, "bad item"); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	content := string(data)
	if !strings.Contains(content, `"operation":"pending"`) || !strings.Contains(content, `"operation":"status"`) {
		t.Fatalf("spool does not contain durable transitions: %s", content)
	}
}

func TestPendingReadsSpoolAfterDatabaseFailure(t *testing.T) {
	path := t.TempDir() + "/gps-ingest-ledger.ndjson"
	store := NewStore(nil, path)
	event := Event{EventID: "evt-retry", Status: RetryPending, RawPayload: `{"imei":"867994064030931"}`}
	if err := store.RecordPending(context.Background(), []Event{event}); err != nil {
		t.Fatal(err)
	}
	pending, err := store.Pending(context.Background(), 10)
	if err != nil || len(pending) != 1 || pending[0].EventID != event.EventID {
		t.Fatalf("pending spool recovery failed: err=%v events=%+v", err, pending)
	}
	if err := store.Mark(context.Background(), event.EventID, Persisted, ""); err != nil {
		t.Fatal(err)
	}
	pending, err = store.Pending(context.Background(), 10)
	if err != nil || len(pending) != 0 {
		t.Fatalf("persisted event remained pending: err=%v events=%+v", err, pending)
	}
}
