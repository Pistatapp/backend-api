# Task-Specific GPS Metrics Testing

This document describes the comprehensive test suite for task-specific GPS metrics calculation, covering zone status events, precise metrics calculation, and multiple task scenarios.

## Overview

The `TaskSpecificGpsMetricsTest` provides complete coverage of:
- Zone status event broadcasting (inside/outside task zones)
- Precise metrics calculation for assigned tasks
- Multiple non-overlapping task assignments
- Task transition handling
- Daily task efficiency averaging
- Work duration formatting in events

## Test Structure

### 1. Zone Status Event Tests

#### `it_raises_tractor_zone_status_event_when_tractor_is_inside_task_zone()`
Tests that the `TractorZoneStatus` event is properly dispatched when a tractor is working inside its assigned task zone.

**Coverage:**
- Event dispatch verification
- Zone status detection (`is_in_task_zone = true`)
- Task information inclusion (`task_id`, `task_name`)
- Work duration formatting in H:i:s format
- Device association

**Key Assertions:**
- Event is dispatched with correct zone status
- Work duration is included when inside zone
- Task information is properly attached

#### `it_raises_tractor_zone_status_event_when_tractor_is_outside_task_zone()`
Tests that the event correctly identifies when a tractor is outside its assigned task zone.

**Coverage:**
- Outside zone detection (`is_in_task_zone = false`)
- Null work duration when outside zone
- Task information still included for context
- Proper event data structure

#### `it_raises_tractor_zone_status_event_when_tractor_has_no_task()`
Tests event behavior when no task is assigned to the tractor.

**Coverage:**
- No task scenario handling
- Null task information
- Proper zone status indication
- Event structure validation

### 2. Precise Metrics Calculation Tests

#### `it_calculates_metrics_precisely_when_tractor_has_assigned_task()`
Tests that GPS metrics are calculated accurately when a tractor has an assigned task, ensuring only reports within the task zone are counted.

**Coverage:**
- Zone-based filtering of GPS reports
- Separate processing of inside vs outside zone reports
- Task-specific efficiency calculation
- Distance and time tracking within zones
- Max speed calculation from zone-specific reports

**Key Test Data:**
- Reports inside task zone (should be counted)
- Reports outside task zone (should be ignored)
- Expected efficiency calculation: `work_duration / (expected_work_time * 3600) * 100`

**Assertions:**
- Only inside-zone reports contribute to metrics
- Efficiency calculated using task-specific expected work time
- Distance and time tracked accurately
- Max speed reflects zone-specific movement

### 3. Multiple Task Scenarios

#### `it_calculates_metrics_correctly_for_multiple_non_overlapping_tasks()`
Tests the system's ability to handle multiple tasks assigned to the same tractor on the same day, ensuring each task gets separate metrics tracking.

**Coverage:**
- Multiple field assignments
- Separate metrics calculation per task
- Independent efficiency calculations
- Task-specific zone detection
- Non-overlapping task processing

**Test Setup:**
- Two different fields with distinct coordinates
- Two separate tasks with different expected work times
- GPS reports for each task in their respective zones

**Assertions:**
- Each task has its own `GpsMetricsCalculation` record
- Metrics are calculated independently
- Different efficiency values based on task-specific expected work times
- Separate max speed tracking per task

#### `it_handles_task_transition_correctly()`
Tests the system's ability to handle transitions between different tasks throughout the day.

**Coverage:**
- Task completion and new task assignment
- Separate metrics for each task
- Event broadcasting for each task
- Task status transitions
- Independent zone detection per task

**Test Flow:**
1. Create and process first task
2. Complete first task
3. Create and process second task
4. Verify separate metrics and events

### 4. Daily Efficiency Calculation

#### `it_calculates_daily_task_efficiency_average_correctly()`
Tests the calculation of daily average efficiency across multiple tasks.

**Coverage:**
- Multiple tasks on same day
- Individual task efficiency calculation
- Daily average computation
- Task-specific expected work times

**Formula Validation:**
- Individual task efficiency: `work_duration / (expected_work_time * 3600) * 100`
- Daily average: `(task1_efficiency + task2_efficiency + ...) / task_count`

### 5. Event Data Formatting

#### `it_handles_work_duration_formatting_correctly_in_zone_status_event()`
Tests that work duration is properly formatted in H:i:s format for zone status events.

**Coverage:**
- Duration formatting (seconds to H:i:s)
- Event data structure validation
- Time calculation accuracy
- Format consistency

**Expected Format:**
- Input: 9000 seconds (2.5 hours)
- Output: "02:30:00"

## Test Data Patterns

### GPS Report Structure
```php
[
    'coordinate' => [latitude, longitude],
    'speed' => speed_in_kmh,
    'status' => 1, // 1 = on, 0 = off
    'directions' => ['ew' => east_west_direction, 'ns' => north_south_direction],
    'is_starting_point' => false,
    'is_ending_point' => false,
    'is_stopped' => false,
    'stoppage_time' => 0,
    'date_time' => Carbon::parse('2024-01-24 10:00:00'),
    'imei' => '863070043386100',
]
```

### Field Coordinates (Polygon)
```php
'coordinates' => [
    [34.88, 50.58],   // Southwest corner
    [34.89, 50.58],   // Southeast corner
    [34.89, 50.59],   // Northeast corner
    [34.88, 50.59],   // Northwest corner
    [34.88, 50.58]    // Close polygon
]
```

### Task Configuration
```php
TractorTask::factory()->create([
    'tractor_id' => $tractor->id,
    'taskable_type' => 'App\Models\Field',
    'taskable_id' => $field->id,
    'date' => today(),
    'status' => 'started',
    'expected_work_time' => 4.0 // hours
])
```

## Running the Tests

### Run All Task-Specific GPS Metrics Tests
```bash
php artisan test tests/Feature/Services/TaskSpecificGpsMetricsTest.php
```

### Run Specific Test Methods
```bash
# Zone status event tests
php artisan test --filter="it_raises_tractor_zone_status_event"

# Metrics calculation tests
php artisan test --filter="it_calculates_metrics_precisely"

# Multiple task tests
php artisan test --filter="it_calculates_metrics_correctly_for_multiple"

# Task transition tests
php artisan test --filter="it_handles_task_transition"
```

### Run with Coverage
```bash
php artisan test --coverage tests/Feature/Services/TaskSpecificGpsMetricsTest.php
```

## Test Environment Setup

Each test includes:
- **Database Refresh**: Clean state for each test
- **Event Faking**: Prevents actual event broadcasting during tests
- **Factory Data**: Consistent test data creation
- **Carbon Time Mocking**: Predictable timestamps
- **Model Relationships**: Proper tractor-device-task associations

## Assertions and Validations

The tests validate:
- **Event Broadcasting**: Correct events dispatched with proper data
- **Zone Detection**: Accurate inside/outside zone determination
- **Metrics Calculation**: Precise efficiency and performance metrics
- **Task Separation**: Independent metrics for each task
- **Data Formatting**: Proper time and duration formatting
- **Database Persistence**: Correct data storage and retrieval

## Edge Cases Covered

- **No Task Assigned**: Proper handling when tractor has no active task
- **Outside Zone Movement**: Correct exclusion of outside-zone reports
- **Multiple Tasks**: Independent processing of multiple daily tasks
- **Task Transitions**: Proper handling of task changes
- **Zero Work Duration**: Correct efficiency calculation with no work
- **Format Edge Cases**: Proper time formatting for various durations

## Performance Considerations

Tests include scenarios for:
- **Multiple Task Processing**: Efficient handling of multiple tasks
- **Event Broadcasting**: Minimal overhead event dispatching
- **Database Operations**: Optimized queries and data persistence
- **Memory Usage**: Efficient test data management

## Integration Points

The tests verify integration with:
- **ProcessGpsReportsJob**: Main processing pipeline
- **TractorZoneStatus Event**: Real-time zone status broadcasting
- **GpsMetricsCalculation Model**: Database persistence
- **TractorTaskService**: Task and zone management
- **ReportProcessingService**: GPS data processing

## Maintenance Notes

- **Factory Usage**: Tests use factories for consistent data creation
- **Event Faking**: Events are faked to prevent side effects
- **Database Isolation**: Each test runs in isolation
- **Time Consistency**: Carbon is used for predictable timestamps
- **Relationship Loading**: Proper model relationship handling

This comprehensive test suite ensures the task-specific GPS metrics system is robust, reliable, and maintainable across all scenarios and edge cases.
