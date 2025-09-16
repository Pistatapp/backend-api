# GPS Metrics System - Comprehensive Test Suite

This document describes the comprehensive test suite for the GPS metrics system, covering all components, services, controllers, events, and integration scenarios.

## Overview

The GPS metrics system test suite provides complete coverage of:
- Data parsing and validation
- Metrics calculation and persistence
- Real-time processing and aggregation
- Task scoping and area detection
- Caching and state management
- HTTP endpoints and error handling
- Event broadcasting
- Integration scenarios and edge cases

## Test Structure

### 1. ParseDataService Tests (`tests/Feature/Services/ParseDataServiceTest.php`)

Tests the data parsing service that converts raw GPS data into structured reports.

**Coverage:**
- Valid GPS data parsing
- NMEA coordinate conversion to decimal degrees
- Timezone offset application (+3:30)
- Data filtering (today-only reports)
- Chronological sorting
- Invalid data format handling
- Malformed JSON handling
- Speed and status determination
- Direction parsing
- Empty data handling

**Key Test Cases:**
- `it_parses_valid_gps_data_correctly()`
- `it_converts_nmea_coordinates_to_decimal_degrees()`
- `it_applies_timezone_offset_correctly()`
- `it_filters_out_non_today_data()`
- `it_sorts_reports_by_datetime()`
- `it_handles_invalid_data_format()`
- `it_handles_malformed_json()`

### 2. ReportProcessingService Tests (`tests/Feature/Services/ReportProcessingServiceTest.php`)

Tests the core processing service that calculates metrics and manages persistence.

**Coverage:**
- Metrics calculation (distance, time, speed)
- Movement/stoppage state transitions
- Persistence strategy (first, moving, first stopped)
- Working hours filtering
- Task area filtering
- Out-of-order report handling
- Max speed tracking
- Multiple stoppage segments
- Cache integration

**Key Test Cases:**
- `it_processes_reports_and_calculates_metrics()`
- `it_persists_reports_correctly()`
- `it_handles_stopped_to_moving_transitions()`
- `it_handles_moving_to_stopped_transitions()`
- `it_handles_stopped_to_stopped_transitions()`
- `it_handles_moving_to_moving_transitions()`
- `it_respects_working_hours_filtering()`
- `it_respects_task_zone_filtering()`

### 3. LiveReportService Tests (`tests/Feature/Services/LiveReportServiceTest.php`)

Tests the live report generation service that orchestrates the entire processing flow.

**Coverage:**
- End-to-end processing flow
- Daily report creation and updates
- Task integration
- Efficiency calculations
- Average speed calculations
- Empty reports handling
- Points inclusion
- Cross-midnight working hours
- Multiple batches per day

**Key Test Cases:**
- `it_generates_live_report_without_task()`
- `it_creates_daily_report_when_none_exists()`
- `it_updates_existing_daily_report()`
- `it_generates_live_report_with_task()`
- `it_calculates_efficiency_correctly()`
- `it_calculates_average_speed_correctly()`
- `it_handles_cross_midnight_working_hours()`

### 4. DailyReportService Tests (`tests/Feature/Services/DailyReportServiceTest.php`)

Tests the daily aggregation service that manages cumulative metrics.

**Coverage:**
- Daily report creation and retrieval
- Data updates and accumulation
- Efficiency calculations
- Average speed calculations
- Multiple updates handling
- Different expected work times
- Edge cases (negative values, large numbers)

**Key Test Cases:**
- `it_creates_daily_report_when_none_exists()`
- `it_returns_existing_daily_report()`
- `it_updates_daily_report_with_new_data()`
- `it_calculates_efficiency_correctly()`
- `it_calculates_average_speed_correctly()`
- `it_handles_multiple_updates()`
- `it_handles_very_high_efficiency()`

### 5. TractorTaskService Tests (`tests/Feature/Services/TractorTaskServiceTest.php`)

Tests the task service that manages task scoping and area detection.

**Coverage:**
- Current task retrieval
- Task filtering by date and status
- Task area coordinate extraction
- Field and plot task handling
- Backward compatibility
- Error handling for missing data

**Key Test Cases:**
- `it_returns_null_when_no_current_task()`
- `it_returns_current_task_for_today()`
- `it_ignores_tasks_for_other_dates()`
- `it_ignores_tasks_with_non_started_status()`
- `it_returns_coordinates_for_field_task()`
- `it_returns_coordinates_for_plot_task()`
- `it_handles_task_without_coordinates()`

### 6. CacheService Tests (`tests/Feature/Services/CacheServiceTest.php`)

Tests the caching service that manages state persistence across requests.

**Coverage:**
- Previous report caching
- Latest stored report caching
- Validated state management
- Pending reports queue
- Consecutive count tracking
- Device-specific cache isolation
- Cache expiration handling

**Key Test Cases:**
- `it_stores_and_retrieves_previous_report()`
- `it_stores_and_retrieves_latest_stored_report()`
- `it_stores_and_retrieves_validated_state()`
- `it_manages_pending_reports()`
- `it_manages_consecutive_count()`
- `it_uses_device_specific_cache_keys()`
- `it_handles_cache_expiration()`

### 7. GpsReportController Tests (`tests/Feature/Controllers/GpsReportControllerTest.php`)

Tests the HTTP controller that handles GPS data ingestion.

**Coverage:**
- Valid data processing
- Event dispatching
- Error handling
- Invalid device IMEI handling
- Malformed data handling
- Multiple reports processing
- Different speed/status values
- Cross-day data filtering
- Large request bodies
- Concurrent requests
- HTTP method validation

**Key Test Cases:**
- `it_processes_valid_gps_data_successfully()`
- `it_dispatches_report_received_event()`
- `it_dispatches_tractor_status_event()`
- `it_handles_invalid_device_imei()`
- `it_handles_malformed_json()`
- `it_handles_multiple_reports_in_single_request()`
- `it_handles_large_request_body()`
- `it_logs_errored_data()`

### 8. GPS Events Tests (`tests/Feature/Events/GpsEventsTest.php`)

Tests the event broadcasting system for real-time updates.

**Coverage:**
- ReportReceived event broadcasting
- TractorStatus event broadcasting
- Data formatting for broadcast
- Channel configuration
- Event data structure
- Null value handling
- Large number handling
- Decimal precision

**Key Test Cases:**
- `report_received_event_has_correct_broadcast_name()`
- `report_received_event_broadcasts_on_correct_channel()`
- `report_received_event_broadcasts_correct_data()`
- `tractor_status_event_has_correct_broadcast_name()`
- `tractor_status_event_broadcasts_on_correct_channel()`
- `tractor_status_event_updates_tractor_model()`

### 9. Integration Tests (`tests/Feature/Integration/GpsMetricsIntegrationTest.php`)

Tests complete end-to-end scenarios and complex use cases.

**Coverage:**
- Complete workday scenarios
- Task-scoped processing
- Cross-midnight working hours
- Multiple batches per day
- Start/end point detection
- Efficiency calculations
- Average speed calculations
- Cache persistence across requests
- Error recovery
- Concurrent device requests

**Key Test Cases:**
- `it_processes_complete_workday_scenario()`
- `it_handles_task_scoped_processing()`
- `it_handles_cross_midnight_working_hours()`
- `it_handles_multiple_batches_same_day()`
- `it_handles_start_end_point_detection()`
- `it_handles_efficiency_calculations()`
- `it_handles_cache_persistence_across_requests()`

## Running the Tests

### Run All GPS Metrics Tests
```bash
php artisan test --filter=GpsMetrics
```

### Run Specific Test Categories
```bash
# ParseDataService tests
php artisan test tests/Feature/Services/ParseDataServiceTest.php

# ReportProcessingService tests
php artisan test tests/Feature/Services/ReportProcessingServiceTest.php

# LiveReportService tests
php artisan test tests/Feature/Services/LiveReportServiceTest.php

# DailyReportService tests
php artisan test tests/Feature/Services/DailyReportServiceTest.php

# TractorTaskService tests
php artisan test tests/Feature/Services/TractorTaskServiceTest.php

# CacheService tests
php artisan test tests/Feature/Services/CacheServiceTest.php

# Controller tests
php artisan test tests/Feature/Controllers/GpsReportControllerTest.php

# Events tests
php artisan test tests/Feature/Events/GpsEventsTest.php

# Integration tests
php artisan test tests/Feature/Integration/GpsMetricsIntegrationTest.php
```

### Run with Coverage
```bash
php artisan test --coverage --filter=GpsMetrics
```

## Test Data Patterns

### GPS Data Format
The tests use the standard GPS data format:
```
+Hooshnic:V1.03,{lat},{lon},{alt},{date},{time},{speed},{status},{direction},{ew_dir},{ns_dir},{imei}
```

### Sample Test Data
```php
$jsonData = [
    ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386100'],
    ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,005,000,1,090,0,863070043386100']
];
```

## Test Environment Setup

Each test class includes:
- Database refresh for isolation
- Cache clearing for clean state
- Event faking for testing
- Carbon time mocking for consistent timestamps
- Factory-created test data

## Assertions and Validations

The tests validate:
- Data structure and types
- Business logic correctness
- Error handling
- Performance characteristics
- Integration points
- Event dispatching
- Cache behavior
- Database persistence

## Edge Cases Covered

- Out-of-order reports
- Invalid data formats
- Missing device IMEIs
- Cross-midnight working hours
- Task areas without coordinates
- Concurrent requests
- Large data volumes
- Error recovery scenarios
- Cache expiration
- Null and empty values

## Performance Considerations

Tests include scenarios for:
- Large request bodies (100+ reports)
- Concurrent device requests
- Multiple batches per day
- Cache persistence across requests
- Memory usage with large datasets

## Maintenance Notes

- Tests use factories for consistent data creation
- Time is mocked using Carbon for predictable results
- Events are faked to avoid side effects
- Database is refreshed between tests for isolation
- Cache is cleared to prevent test interference

This comprehensive test suite ensures the GPS metrics system is robust, reliable, and maintainable across all scenarios and edge cases.
