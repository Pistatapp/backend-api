package identity

import (
	"fmt"
	"regexp"
	"strings"
	"time"
)

const (
	IStartekVendorCode = "01"
	GPSLogCodeLayout   = "20060102150405"
)

var imeiPattern = regexp.MustCompile(`^[0-9]{14,20}$`)

// BuildGPSLogCode returns the owner-defined logical identity. It deliberately
// accepts only a real device timestamp; callers must not pass gateway receive
// time as a fallback.
func BuildGPSLogCode(imei string, deviceEventUTC time.Time) (string, error) {
	imei = strings.TrimSpace(imei)
	if !imeiPattern.MatchString(imei) {
		return "", fmt.Errorf("invalid iStartek IMEI")
	}
	if deviceEventUTC.IsZero() {
		return "", fmt.Errorf("device event UTC is required")
	}
	return fmt.Sprintf("%s-%s-%s", IStartekVendorCode, imei, deviceEventUTC.UTC().Truncate(time.Second).Format(GPSLogCodeLayout)), nil
}

// ParseGPSLogCode validates and extracts the stable UTC identity without
// normalizing or inventing a different identifier.
func ParseGPSLogCode(code string) (imei string, deviceEventUTC time.Time, err error) {
	parts := strings.Split(strings.TrimSpace(code), "-")
	if len(parts) != 3 || parts[0] != IStartekVendorCode || !imeiPattern.MatchString(parts[1]) || len(parts[2]) != len(GPSLogCodeLayout) {
		return "", time.Time{}, fmt.Errorf("invalid iStartek gps_log_code")
	}
	t, parseErr := time.ParseInLocation(GPSLogCodeLayout, parts[2], time.UTC)
	if parseErr != nil {
		return "", time.Time{}, fmt.Errorf("invalid iStartek gps_log_code UTC: %w", parseErr)
	}
	return parts[1], t.UTC(), nil
}
