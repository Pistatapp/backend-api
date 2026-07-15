package validate

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"time"
)

type Directions struct {
	EW int `json:"ew"`
	NS int `json:"ns"`
}

type GpsPoint struct {
	IMEI       string     `json:"imei"`
	Coordinate [2]float64 `json:"coordinate"`
	DateTime   string     `json:"date_time"`
	Speed      int        `json:"speed"`
	Status     int        `json:"status"`
	Directions Directions `json:"directions"`
}

type Request struct {
	Data []GpsPoint `json:"data"`
}

type FieldErrors map[string][]string

type ValidationError struct {
	Message string
	Errors  FieldErrors
}

func (e *ValidationError) Error() string {
	return e.Message
}

func DecodeAndValidate(r io.Reader) (*Request, error) {
	var raw struct {
		Data []json.RawMessage `json:"data"`
	}

	dec := json.NewDecoder(r)
	dec.UseNumber()
	if err := dec.Decode(&raw); err != nil {
		return nil, &ValidationError{
			Message: "The given data was invalid.",
			Errors: FieldErrors{
				"data": {"The data field is required."},
			},
		}
	}

	filtered := make([]json.RawMessage, 0, len(raw.Data))
	for _, item := range raw.Data {
		if len(item) == 0 || string(item) == "{}" || string(item) == "null" {
			continue
		}
		filtered = append(filtered, item)
	}

	if len(filtered) == 0 {
		return nil, &ValidationError{
			Message: "The given data was invalid.",
			Errors: FieldErrors{
				"data": {"The data field is required."},
			},
		}
	}

	points := make([]GpsPoint, 0, len(filtered))
	errs := make(FieldErrors)

	for i, item := range filtered {
		point, itemErrs := validatePoint(item, i)
		if len(itemErrs) > 0 {
			for k, v := range itemErrs {
				errs[k] = append(errs[k], v...)
			}
			continue
		}
		points = append(points, point)
	}

	if len(errs) > 0 {
		return nil, &ValidationError{
			Message: "The given data was invalid.",
			Errors:  errs,
		}
	}

	return &Request{Data: points}, nil
}

func validatePoint(raw json.RawMessage, index int) (GpsPoint, FieldErrors) {
	var item map[string]json.RawMessage
	errs := make(FieldErrors)

	if err := json.Unmarshal(raw, &item); err != nil {
		errs[fmt.Sprintf("data.%d", index)] = []string{"The data item must be a valid object."}
		return GpsPoint{}, errs
	}

	prefix := fmt.Sprintf("data.%d", index)

	imei, ok := stringField(item, "imei")
	if !ok || imei == "" {
		errs[prefix+".imei"] = []string{"The imei field is required."}
	} else if len(imei) > 20 {
		errs[prefix+".imei"] = []string{"The imei field must not be greater than 20 characters."}
	}

	coord, coordErrs := parseCoordinate(item["coordinate"], prefix+".coordinate")
	for k, v := range coordErrs {
		errs[k] = append(errs[k], v...)
	}

	dateTime, ok := stringField(item, "date_time")
	if !ok || dateTime == "" {
		errs[prefix+".date_time"] = []string{"The date_time field is required."}
	} else if _, err := time.Parse("2006-01-02 15:04:05", dateTime); err != nil {
		if _, err2 := time.Parse(time.RFC3339, dateTime); err2 != nil {
			errs[prefix+".date_time"] = []string{"The date_time field must be a valid date."}
		}
	}

	speed, speedOK := intField(item, "speed")
	if !speedOK {
		errs[prefix+".speed"] = []string{"The speed field is required."}
	} else if speed < 0 {
		errs[prefix+".speed"] = []string{"The speed field must be at least 0."}
	}

	status, statusOK := intField(item, "status")
	if !statusOK {
		errs[prefix+".status"] = []string{"The status field is required."}
	} else if status != 0 && status != 1 {
		errs[prefix+".status"] = []string{"The selected status is invalid."}
	}

	directions, dirErrs := parseDirections(item["directions"], prefix+".directions")
	for k, v := range dirErrs {
		errs[k] = append(errs[k], v...)
	}

	if len(errs) > 0 {
		return GpsPoint{}, errs
	}

	return GpsPoint{
		IMEI:       imei,
		Coordinate: coord,
		DateTime:   normalizeDateTime(dateTime),
		Speed:      speed,
		Status:     status,
		Directions: directions,
	}, nil
}

func parseCoordinate(raw json.RawMessage, prefix string) ([2]float64, FieldErrors) {
	errs := make(FieldErrors)
	var zero [2]float64

	if len(raw) == 0 {
		errs[prefix] = []string{"The coordinate field is required."}
		return zero, errs
	}

	var arr []json.RawMessage
	if err := json.Unmarshal(raw, &arr); err != nil || len(arr) != 2 {
		errs[prefix] = []string{"The coordinate field must contain 2 items."}
		return zero, errs
	}

	lat, latOK := numberToFloat(arr[0])
	lon, lonOK := numberToFloat(arr[1])
	if !latOK || !lonOK {
		errs[prefix+".0"] = []string{"The coordinate.0 field must be a number."}
		return zero, errs
	}
	if lat < -90 || lat > 90 {
		errs[prefix+".0"] = []string{"The coordinate.0 field must be between -90 and 90."}
	}
	if lon < -180 || lon > 180 {
		errs[prefix+".1"] = []string{"The coordinate.1 field must be between -180 and 180."}
	}
	if len(errs) > 0 {
		return zero, errs
	}

	return [2]float64{lat, lon}, nil
}

func parseDirections(raw json.RawMessage, prefix string) (Directions, FieldErrors) {
	errs := make(FieldErrors)
	var dirs Directions

	if len(raw) == 0 {
		errs[prefix] = []string{"The directions field is required."}
		return dirs, errs
	}

	var obj map[string]json.RawMessage
	if err := json.Unmarshal(raw, &obj); err != nil {
		errs[prefix] = []string{"The directions field must be an object."}
		return dirs, errs
	}

	ew, ewOK := intField(obj, "ew")
	ns, nsOK := intField(obj, "ns")
	if !ewOK {
		errs[prefix+".ew"] = []string{"The directions.ew field is required."}
	}
	if !nsOK {
		errs[prefix+".ns"] = []string{"The directions.ns field is required."}
	}
	if len(errs) > 0 {
		return dirs, errs
	}

	return Directions{EW: ew, NS: ns}, nil
}

func stringField(item map[string]json.RawMessage, key string) (string, bool) {
	raw, ok := item[key]
	if !ok {
		return "", false
	}
	var s string
	if err := json.Unmarshal(raw, &s); err != nil {
		return "", false
	}
	return s, true
}

func intField(item map[string]json.RawMessage, key string) (int, bool) {
	raw, ok := item[key]
	if !ok {
		return 0, false
	}
	var n json.Number
	if err := json.Unmarshal(raw, &n); err != nil {
		return 0, false
	}
	i64, err := n.Int64()
	if err != nil {
		f, err2 := n.Float64()
		if err2 != nil || math.Trunc(f) != f {
			return 0, false
		}
		return int(f), true
	}
	return int(i64), true
}

func numberToFloat(raw json.RawMessage) (float64, bool) {
	var n json.Number
	if err := json.Unmarshal(raw, &n); err != nil {
		return 0, false
	}
	f, err := n.Float64()
	return f, err == nil
}

func normalizeDateTime(value string) string {
	if t, err := time.Parse("2006-01-02 15:04:05", value); err == nil {
		return t.Format("2006-01-02 15:04:05")
	}
	if t, err := time.Parse(time.RFC3339, value); err == nil {
		return t.Format("2006-01-02 15:04:05")
	}
	return value
}
