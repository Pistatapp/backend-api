package httpserver

import "testing"

func TestAllowlistAllowed(t *testing.T) {
	allowlist := NewAllowlist(map[string]struct{}{
		"94.101.187.206": {},
		"127.0.0.1":      {},
	})

	if !allowlist.Allowed("127.0.0.1") {
		t.Fatal("expected localhost to be allowed")
	}
	if allowlist.Allowed("203.0.113.10") {
		t.Fatal("expected unknown IP to be rejected")
	}
}
