package broadcast

import (
	"fmt"

	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/jalali"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
	pusher "github.com/pusher/pusher-http-go/v5"
)

type Client struct {
	client *pusher.Client
}

func NewClient(cfg config.Config) *Client {
	host := cfg.ReverbHost
	if cfg.ReverbPort != "" {
		host = host + ":" + cfg.ReverbPort
	}

	return &Client{
		client: &pusher.Client{
			AppID:   cfg.ReverbAppID,
			Key:     cfg.ReverbKey,
			Secret:  cfg.ReverbSecret,
			Host:    host,
			Secure:  cfg.ReverbScheme == "https",
			Cluster: "mt1",
		},
	}
}

type Job struct {
	DeviceID  int64
	TractorID int64
	LastPoint validate.GpsPoint
}

func (c *Client) Send(job Job) error {
	if c.client == nil || c.client.AppID == "" || c.client.Key == "" || c.client.Secret == "" {
		return fmt.Errorf("reverb client is not configured")
	}

	statusPayload := map[string]any{
		"tractor": job.TractorID,
		"status":  job.LastPoint.Status,
	}
	if err := c.client.Trigger("tractor.status", "tractor.status.changed", statusPayload); err != nil {
		return fmt.Errorf("broadcast tractor status: %w", err)
	}

	jalaliDate, err := jalali.FormatDateTime(job.LastPoint.DateTime)
	if err != nil {
		return fmt.Errorf("format jalali date: %w", err)
	}

	reportPayload := []map[string]any{{
		"latitude":          job.LastPoint.Coordinate[0],
		"longitude":         job.LastPoint.Coordinate[1],
		"speed":             job.LastPoint.Speed,
		"status":            job.LastPoint.Status,
		"directions":        job.LastPoint.Directions,
		"is_starting_point": false,
		"is_ending_point":   false,
		"is_stopped":        false,
		"stoppage_time":     "00:00:00",
		"date_time":         jalaliDate,
	}}

	channel := fmt.Sprintf("private-gps_devices.%d", job.DeviceID)
	if err := c.client.Trigger(channel, "report-received", reportPayload); err != nil {
		return fmt.Errorf("broadcast report received: %w", err)
	}

	return nil
}
