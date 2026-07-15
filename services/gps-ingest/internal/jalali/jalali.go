package jalali

import (
	"fmt"
	"time"
)

// FormatDateTime mirrors Laravel jdate($dateTime)->format('Y/m/d H:i:s').
func FormatDateTime(value string) (string, error) {
	t, err := time.ParseInLocation("2006-01-02 15:04:05", value, time.Local)
	if err != nil {
		return "", fmt.Errorf("parse datetime: %w", err)
	}

	year, month, day := toJalali(t.Year(), int(t.Month()), t.Day())
	return fmt.Sprintf("%04d/%02d/%02d %02d:%02d:%02d",
		year, month, day,
		t.Hour(), t.Minute(), t.Second(),
	), nil
}

func toJalali(gy, gm, gd int) (int, int, int) {
	gdm := []int{0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334}
	var jy int
	if gy > 1600 {
		jy = 979
		gy -= 1600
	} else {
		jy = 0
		gy -= 621
	}

	gy2 := gy
	if gm > 2 {
		gy2++
	}

	days := 365*gy + (gy2+3)/4 - (gy2+99)/100 + (gy2+399)/400 - 80 + gd + gdm[gm-1]
	jy += 33 * (days / 12053)
	days %= 12053
	jy += 4 * (days / 1461)
	days %= 1461

	if days > 365 {
		jy += (days - 1) / 365
		days = (days - 1) % 365
	}

	var jm int
	var jd int
	if days < 186 {
		jm = 1 + days/31
		jd = 1 + days%31
	} else {
		jm = 7 + (days-186)/30
		jd = 1 + (days-186)%30
	}

	return jy, jm, jd
}
