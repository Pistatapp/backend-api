package httpserver

import (
	"net"
	"net/http"
	"strings"
)

// Allowlist provides O(1) IP allowlist checks for GPS ingest.
type Allowlist map[string]struct{}

func NewAllowlist(ips map[string]struct{}) Allowlist {
	return Allowlist(ips)
}

func (a Allowlist) Allowed(ip string) bool {
	_, ok := a[ip]
	return ok
}

func ClientIP(r *http.Request) string {
	if ip := strings.TrimSpace(r.Header.Get("X-Real-IP")); ip != "" {
		return ip
	}
	if forwarded := strings.TrimSpace(r.Header.Get("X-Forwarded-For")); forwarded != "" {
		parts := strings.Split(forwarded, ",")
		return strings.TrimSpace(parts[0])
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}
