package validate

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const maxIngressBodyBytes = 8 << 20

var legacyHeaderPattern = regexp.MustCompile(`^\+[A-Za-z][A-Za-z0-9_]*:[A-Za-z0-9._-]+$`)

// IngressItem represents exactly one wire item. Invalid items intentionally
// remain in this structure so the HTTP layer can durably quarantine them.
type IngressItem struct {
	Index              int
	RawPayload         string
	Point              *GpsPoint
	DeviceRecordedAt   string
	DeviceTimestampRaw string
	EventID            string
	ErrorReason        string
}

type Ingress struct {
	Items []IngressItem
}

// DecodeIngress supports normalized JSON, legacy Hooshnics envelopes, glued
// objects without commas, modem noise, CRLF/NUL and the historical \: escape.
// It does not reject a whole batch because one item is malformed.
func DecodeIngress(r io.Reader) (*Ingress, error) {
	raw, err := io.ReadAll(io.LimitReader(r, maxIngressBodyBytes+1))
	if err != nil {
		return nil, err
	}
	if len(raw) > maxIngressBodyBytes {
		return nil, fmt.Errorf("request body exceeds %d bytes", maxIngressBodyBytes)
	}
	raw = bytes.TrimSpace(bytes.ReplaceAll(raw, []byte{0}, nil))
	if len(raw) == 0 {
		return &Ingress{Items: []IngressItem{{Index: 0, RawPayload: "", ErrorReason: "Empty request body"}}}, nil
	}

	if items, ok := decodeFormalIngress(raw); ok {
		if len(items) > 0 {
			return &Ingress{Items: items}, nil
		}
	}
	if decoded, ok := decodeIngressJSON(raw); ok {
		if items := decodeIngressTopLevel(decoded, string(raw)); len(items) > 0 {
			return &Ingress{Items: items}, nil
		}
	}

	fragments := extractIngressObjects(string(raw))
	items := make([]IngressItem, 0, len(fragments))
	for index, fragment := range fragments {
		decoded, ok := decodeIngressJSON([]byte(fragment))
		if !ok {
			items = append(items, IngressItem{Index: index, RawPayload: fragment, ErrorReason: "Invalid JSON object"})
			continue
		}
		items = append(items, decodeIngressObject(decoded, fragment, index)...)
	}
	if len(items) == 0 {
		items = append(items, IngressItem{Index: 0, RawPayload: string(raw), ErrorReason: "No GPS object could be extracted"})
	}
	return &Ingress{Items: items}, nil
}

// decodeFormalIngress keeps each json.RawMessage untouched for payload hash
// and forensic storage. The interface{} fallback below is only for damaged
// or noisy bodies that cannot be unmarshaled as one formal JSON document.
func decodeFormalIngress(raw []byte) ([]IngressItem, bool) {
	repaired := []byte(strings.ReplaceAll(string(raw), `\:`, ":"))
	var object map[string]json.RawMessage
	if json.Unmarshal(repaired, &object) == nil {
		if data, exists := object["data"]; exists {
			var collection []json.RawMessage
			if json.Unmarshal(data, &collection) == nil {
				return decodeIngressRawCollection(collection), true
			}
		}
		var decoded any
		if json.Unmarshal(repaired, &decoded) == nil {
			return decodeIngressObject(decoded, string(raw), 0), true
		}
	}

	var collection []json.RawMessage
	if json.Unmarshal(repaired, &collection) == nil {
		return decodeIngressRawCollection(collection), true
	}
	return nil, false
}

func decodeIngressRawCollection(collection []json.RawMessage) []IngressItem {
	items := make([]IngressItem, 0, len(collection))
	for index, rawItem := range collection {
		decoded, ok := decodeIngressJSON(rawItem)
		if !ok {
			items = append(items, IngressItem{Index: index, RawPayload: string(rawItem), ErrorReason: "Invalid JSON object"})
			continue
		}
		items = append(items, decodeIngressObject(decoded, string(rawItem), index)...)
	}
	return items
}

func decodeIngressTopLevel(decoded any, raw string) []IngressItem {
	switch value := decoded.(type) {
	case map[string]any:
		return decodeIngressObject(value, raw, 0)
	case []any:
		items := make([]IngressItem, 0, len(value))
		for index, item := range value {
			items = append(items, decodeIngressObject(item, compactIngressJSON(item), index)...)
		}
		return items
	default:
		return nil
	}
}

func decodeIngressObject(decoded any, raw string, index int) []IngressItem {
	item, ok := decoded.(map[string]any)
	if !ok {
		return []IngressItem{{Index: index, RawPayload: raw, ErrorReason: "GPS item must be an object"}}
	}

	if data, exists := item["data"]; exists {
		switch value := data.(type) {
		case string:
			point, deviceAt, rawAt, reason := parseLegacyIngress(value)
			if reason != "" {
				return []IngressItem{{Index: index, RawPayload: raw, ErrorReason: reason}}
			}
			return []IngressItem{{
				Index: index, RawPayload: raw, Point: &point,
				DeviceRecordedAt: deviceAt, DeviceTimestampRaw: rawAt,
			}}
		case []any:
			result := make([]IngressItem, 0, len(value))
			for nestedIndex, nested := range value {
				result = append(result, decodeIngressObject(nested, compactIngressJSON(nested), index+nestedIndex)...)
			}
			return result
		}
	}

	point, deviceAt, rawAt, eventID, reason := parseNormalizedIngress(item, index)
	if reason != "" {
		return []IngressItem{{Index: index, RawPayload: raw, ErrorReason: reason}}
	}
	return []IngressItem{{
		Index: index, RawPayload: raw, Point: &point,
		DeviceRecordedAt: deviceAt, DeviceTimestampRaw: rawAt, EventID: eventID,
	}}
}

func parseNormalizedIngress(item map[string]any, index int) (GpsPoint, string, string, string, string) {
	var zero GpsPoint
	prefix := fmt.Sprintf("data.%d", index)
	imei, ok := item["imei"].(string)
	if !ok || strings.TrimSpace(imei) == "" {
		return zero, "", "", "", prefix + ".imei: The imei field is required."
	}
	imei = strings.TrimSpace(imei)
	if len(imei) > 20 {
		return zero, "", "", "", prefix + ".imei: The imei field must not be greater than 20 characters."
	}
	coordinate, ok := parseIngressCoordinate(item["coordinate"])
	if !ok || coordinate[0] < -90 || coordinate[0] > 90 || coordinate[1] < -180 || coordinate[1] > 180 {
		return zero, "", "", "", prefix + ".coordinate: The coordinate must be two valid numbers in range."
	}
	dateTime, ok := item["date_time"].(string)
	if !ok || strings.TrimSpace(dateTime) == "" {
		return zero, "", "", "", prefix + ".date_time: The date_time field is required."
	}
	storedDateTime, deviceAt, err := normalizeIngressDateTime(dateTime)
	if err != nil {
		return zero, "", "", "", prefix + ".date_time: The date_time field must be a valid date."
	}
	speed, ok := ingressInt(item["speed"])
	if !ok || speed < 0 {
		return zero, "", "", "", prefix + ".speed: The speed field must be a non-negative integer."
	}
	status, ok := ingressInt(item["status"])
	if !ok || (status != 0 && status != 1) {
		return zero, "", "", "", prefix + ".status: The status field must be 0 or 1."
	}
	directions, ok := parseIngressDirections(item["directions"])
	if !ok {
		return zero, "", "", "", prefix + ".directions: The directions field must contain ew and ns."
	}
	eventID, _ := item["event_id"].(string)
	if strings.TrimSpace(eventID) == "" {
		eventID, _ = item["eventId"].(string)
	}
	rawAt := firstIngressString(item["device_recorded_at_raw"], item["device_timestamp_raw"])
	return GpsPoint{
		IMEI: imei, Coordinate: coordinate, DateTime: storedDateTime,
		Speed: speed, Status: status, Directions: directions,
	}, deviceAt, rawAt, strings.TrimSpace(eventID), ""
}

func parseLegacyIngress(value string) (GpsPoint, string, string, string) {
	var zero GpsPoint
	value = strings.TrimRight(strings.TrimSpace(strings.ReplaceAll(value, `\:`, ":")), ".")
	fields := strings.Split(value, ",")
	if len(fields) < 12 {
		return zero, "", "", "Legacy Hooshnic item has fewer than 12 fields"
	}
	if !legacyHeaderPattern.MatchString(strings.TrimSpace(fields[0])) {
		return zero, "", "", "Invalid legacy Hooshnic header"
	}
	lat, ok := parseIngressNMEA(fields[1], 90)
	if !ok {
		return zero, "", "", "Invalid NMEA latitude"
	}
	lon, ok := parseIngressNMEA(fields[2], 180)
	if !ok {
		return zero, "", "", "Invalid NMEA longitude"
	}
	dateRaw, timeRaw := strings.TrimSpace(fields[4]), strings.TrimSpace(fields[5])
	if !sixIngressDigits(dateRaw) || !sixIngressDigits(timeRaw) {
		return zero, "", "", "Invalid legacy device date/time"
	}
	imei := strings.TrimSpace(fields[11])
	if len(imei) < 15 || len(imei) > 20 || !allIngressDigits(imei) {
		return zero, "", "", "Invalid legacy IMEI"
	}
	deviceTime, err := time.ParseInLocation("060102150405", dateRaw+timeRaw, time.UTC)
	if err != nil {
		return zero, "", "", "Invalid legacy device date/time"
	}
	speed, ok := parseIngressDecimalInt(fields[6])
	if !ok {
		return zero, "", "", "Invalid legacy speed"
	}
	status, ok := parseIngressDecimalInt(fields[8])
	if !ok || (status != 0 && status != 1) {
		return zero, "", "", "Invalid legacy status"
	}
	ew, ok := parseIngressDecimalInt(fields[9])
	if !ok {
		return zero, "", "", "Invalid legacy east/west direction"
	}
	ns, ok := parseIngressDecimalInt(fields[10])
	if !ok {
		return zero, "", "", "Invalid legacy north/south direction"
	}
	local := deviceTime.In(ingressApplicationLocation())
	return GpsPoint{
		IMEI: imei, Coordinate: [2]float64{lat, lon},
		DateTime: local.Format("2006-01-02 15:04:05"),
		Speed:    speed, Status: status, Directions: Directions{EW: ew, NS: ns},
	}, deviceTime.UTC().Format("2006-01-02 15:04:05"), dateRaw + timeRaw, ""
}

func parseIngressCoordinate(value any) ([2]float64, bool) {
	var result [2]float64
	values, ok := value.([]any)
	if !ok || len(values) != 2 {
		return result, false
	}
	lat, ok1 := ingressFloat(values[0])
	lon, ok2 := ingressFloat(values[1])
	if !ok1 || !ok2 || math.IsNaN(lat) || math.IsNaN(lon) || math.IsInf(lat, 0) || math.IsInf(lon, 0) {
		return result, false
	}
	return [2]float64{lat, lon}, true
}

func parseIngressDirections(value any) (Directions, bool) {
	obj, ok := value.(map[string]any)
	if !ok {
		return Directions{}, false
	}
	ew, ok1 := ingressInt(obj["ew"])
	ns, ok2 := ingressInt(obj["ns"])
	return Directions{EW: ew, NS: ns}, ok1 && ok2
}

func normalizeIngressDateTime(value string) (string, string, error) {
	value = strings.TrimSpace(value)
	if t, err := time.ParseInLocation("2006-01-02 15:04:05", value, ingressApplicationLocation()); err == nil {
		formatted := t.Format("2006-01-02 15:04:05")
		return formatted, formatted, nil
	}
	if t, err := time.Parse(time.RFC3339, value); err == nil {
		return t.In(ingressApplicationLocation()).Format("2006-01-02 15:04:05"), t.UTC().Format("2006-01-02 15:04:05"), nil
	}
	return "", "", fmt.Errorf("invalid date")
}

func ingressApplicationLocation() *time.Location {
	location, err := time.LoadLocation("Asia/Tehran")
	if err != nil {
		return time.Local
	}
	return location
}

func parseIngressNMEA(raw string, maxDegrees float64) (float64, bool) {
	value, err := strconv.ParseFloat(strings.TrimSpace(raw), 64)
	if err != nil || value < 0 {
		return 0, false
	}
	degrees := math.Floor(value / 100)
	minutes := value - degrees*100
	if minutes < 0 || minutes >= 60 || degrees > maxDegrees || (degrees == maxDegrees && minutes != 0) {
		return 0, false
	}
	return math.Round((degrees+minutes/60)*1e6) / 1e6, true
}

func parseIngressDecimalInt(raw string) (int, bool) {
	n, err := strconv.Atoi(strings.TrimSpace(raw))
	return n, err == nil
}

func ingressInt(value any) (int, bool) {
	switch n := value.(type) {
	case float64:
		if math.Trunc(n) != n || n > math.MaxInt || n < math.MinInt {
			return 0, false
		}
		return int(n), true
	case json.Number:
		v, err := strconv.Atoi(string(n))
		return v, err == nil
	case string:
		return parseIngressDecimalInt(n)
	default:
		return 0, false
	}
}

func ingressFloat(value any) (float64, bool) {
	switch n := value.(type) {
	case float64:
		return n, true
	case json.Number:
		v, err := n.Float64()
		return v, err == nil
	case string:
		v, err := strconv.ParseFloat(strings.TrimSpace(n), 64)
		return v, err == nil
	default:
		return 0, false
	}
}

func firstIngressString(values ...any) string {
	for _, value := range values {
		if s, ok := value.(string); ok && strings.TrimSpace(s) != "" {
			return strings.TrimSpace(s)
		}
	}
	return ""
}

func sixIngressDigits(value string) bool { return len(value) == 6 && allIngressDigits(value) }

func allIngressDigits(value string) bool {
	if value == "" {
		return false
	}
	for _, r := range value {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

func compactIngressJSON(value any) string {
	b, err := json.Marshal(value)
	if err != nil {
		return fmt.Sprint(value)
	}
	return string(b)
}

func decodeIngressJSON(raw []byte) (any, bool) {
	var decoded any
	decoder := json.NewDecoder(bytes.NewReader([]byte(strings.ReplaceAll(string(raw), `\:`, ":"))))
	decoder.UseNumber()
	if err := decoder.Decode(&decoded); err != nil {
		return nil, false
	}
	return decoded, true
}

func extractIngressObjects(payload string) []string {
	objects := make([]string, 0)
	start, depth := -1, 0
	inString, escaped := false, false
	for i := 0; i < len(payload); i++ {
		ch := payload[i]
		if inString {
			if escaped {
				escaped = false
			} else if ch == '\\' {
				escaped = true
			} else if ch == '"' {
				inString = false
			}
			continue
		}
		if depth > 0 && ch == '"' {
			inString = true
			continue
		}
		if ch == '{' {
			if depth == 0 {
				start = i
			}
			depth++
			continue
		}
		if ch == '}' && depth > 0 {
			depth--
			if depth == 0 && start >= 0 {
				objects = append(objects, payload[start:i+1])
				start = -1
			}
		}
	}
	if start >= 0 {
		objects = append(objects, payload[start:])
	}
	return objects
}
