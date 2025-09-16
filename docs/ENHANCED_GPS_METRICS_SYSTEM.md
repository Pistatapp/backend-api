# Enhanced GPS Metrics Calculation System

## Overview

The GPS metrics calculation system has been enhanced to create **dual records** for each tractor's daily activity:

1. **Task-Specific Records**: Metrics calculated only for time spent within assigned task zones
2. **Daily Summary Records**: Metrics calculated for the entire working day (tractor_task_id = NULL)

## Key Changes

### 1. Enhanced GpsMetricsCalculationService

**New Methods:**
- `updateBothRecords(array $data)`: Updates both task-specific and daily summary records
- `fetchOrCreateDailyRecord()`: Creates/fetches daily summary record (tractor_task_id = NULL)

**Behavior:**
- When a tractor has a task assigned: Creates both task-specific and daily summary records
- When a tractor has no task: Creates only daily summary record
- Both records accumulate metrics independently

### 2. Enhanced ReportProcessingService

**New Processing Logic:**
- `processReportsForScope(bool $isTaskScope)`: Processes reports for specific scope
- `shouldCountReportForScope(array $report, bool $isTaskScope)`: Scope-specific filtering
- `resetMetrics()`: Resets metrics between scope processing

**Dual Processing:**
- **Task Scope**: Only counts reports within task zone boundaries
- **Daily Scope**: Counts all reports within working hours
- Same GPS data processed twice with different filtering criteria

### 3. Enhanced ProcessGpsReportsJob

**Updated Methods:**
- `updateGpsMetricsCalculations()`: Now handles both record types
- `broadcastReportReceived()`: Uses daily data for broadcasting
- `broadcastZoneStatus()`: Uses task data for zone-specific metrics

## Database Schema

### gps_metrics_calculations Table

```sql
-- Example records for a tractor with task ID 123 on 2024-01-15
INSERT INTO gps_metrics_calculations VALUES 
-- Task-specific record
(1, 1, 123, '2024-01-15', 5.2, 1800, 2, 900, 10.4, 20, 22.5),

-- Daily summary record  
(2, 1, NULL, '2024-01-15', 15.5, 3600, 3, 1800, 15.5, 25, 45.0);
```

**Key Fields:**
- `tractor_id`: Links to tractor
- `tractor_task_id`: NULL for daily summary, task ID for task-specific
- `date`: Date of activity
- `traveled_distance`: Total distance in km
- `work_duration`: Moving time in seconds
- `stoppage_count`: Number of stoppage segments
- `stoppage_duration`: Total stopped time in seconds
- `average_speed`: Calculated average speed
- `max_speed`: Maximum speed recorded
- `efficiency`: Efficiency percentage

## Processing Flow

```
GPS Data → ParseDataService → ProcessGpsReportsJob
    ↓
ReportProcessingService.process()
    ↓
├── processReportsForScope(true)  → Task-specific metrics
└── processReportsForScope(false)  → Daily summary metrics
    ↓
GpsMetricsCalculationService.updateBothRecords()
    ↓
├── Task-specific record (tractor_task_id = task_id)
└── Daily summary record (tractor_task_id = NULL)
```

## Use Cases

### 1. Tractor with Single Task
- **Task Record**: Metrics only for time spent in task zone
- **Daily Record**: Metrics for entire working day
- **Example**: Tractor works 8 hours, 6 hours in task zone, 2 hours outside

### 2. Tractor with Multiple Tasks
- **Multiple Task Records**: One record per task with zone-specific metrics
- **Single Daily Record**: Combined metrics for all tasks and non-task work
- **Example**: Tractor has 3 tasks in one day, each gets its own record

### 3. Tractor without Tasks
- **No Task Records**: No task-specific records created
- **Daily Record**: Metrics for entire working day
- **Example**: Tractor works 8 hours without assigned tasks

## Benefits

1. **Granular Analysis**: Analyze performance per task vs. overall daily performance
2. **Task Efficiency**: Compare time spent in task zones vs. total working time
3. **Resource Planning**: Understand how tractors spend time between tasks
4. **Historical Data**: Maintain both task-specific and daily historical records
5. **Reporting Flexibility**: Generate reports at task level or daily level

## API Endpoints

The existing API endpoints will return data from both record types:

- **Task-specific metrics**: Query with `tractor_task_id` filter
- **Daily summary metrics**: Query with `tractor_task_id = NULL`

## Migration Notes

- Existing functionality remains unchanged
- New records are created alongside existing ones
- No data migration required
- Backward compatibility maintained

## Testing

Run the test script to verify the enhanced system:

```bash
php test_enhanced_gps_system.php
```

This will demonstrate:
- Dual record creation
- Proper tractor_task_id assignment
- Independent metrics accumulation
- Handling of tractors with/without tasks

## Configuration

No additional configuration required. The system automatically:
- Detects if tractor has assigned tasks
- Creates appropriate record types
- Maintains existing caching and performance optimizations
