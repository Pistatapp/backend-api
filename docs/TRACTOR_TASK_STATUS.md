# Tractor Task Status System

## Overview

The tractor task status system intelligently tracks the progress of assigned tasks based on GPS data, time ranges, and tractor presence in designated zones. The system uses four status values to represent the lifecycle of a task.

---

## Status Values

### 1. `not_started`

**Meaning:** The task has been created but its scheduled start time has not yet arrived.

**Conditions:**
- Current time is before the task's `start_time`
- Initial default status for newly created tasks

**Example:**
```
Task scheduled: 08:00 - 12:00
Current time: 07:30
Status: not_started
```

---

### 2. `in_progress`

**Meaning:** The task is currently active and the tractor has entered the designated work zone.

**Conditions:**
- Current time is within the task's time range (`start_time` to `end_time`)
- Tractor has entered the task zone at least once during the task period
- GPS data confirms tractor presence in the assigned field/plot

**Transition from `not_started`:**
- Happens automatically when the tractor enters the task zone during the scheduled time

**Example:**
```
Task scheduled: 08:00 - 12:00
Current time: 10:00
Tractor location: Inside task zone
Status: in_progress
```

---

### 3. `done`

**Meaning:** The task has been completed successfully.

**Conditions:**
- Task end time has passed
- Tractor was present in the task zone for **at least 30%** of the total task duration

**Calculation Example:**
```
Task scheduled: 08:00 - 12:00 (4 hours = 14,400 seconds)
Minimum required presence: 30% of 14,400 = 4,320 seconds (1 hour 12 minutes)
Actual presence: 7,200 seconds (2 hours)
Result: 50% presence ≥ 30% threshold → Status: done
```

**Business Logic:**
The 30% threshold ensures that the tractor spent meaningful time working in the designated area, accounting for brief exits due to refueling, adjustments, or other operational needs.

---

### 4. `not_done`

**Meaning:** The task was not completed or the tractor failed to perform adequate work.

**Conditions (any of the following):**
1. **Never Entered:** Task time ended but tractor never entered the task zone
2. **Insufficient Presence:** Tractor was in the zone but for less than 30% of the total task duration

**Calculation Example - Insufficient Presence:**
```
Task scheduled: 08:00 - 12:00 (4 hours = 14,400 seconds)
Minimum required: 30% of 14,400 = 4,320 seconds
Actual presence: 3,600 seconds (1 hour)
Result: 25% presence < 30% threshold → Status: not_done
```

**Calculation Example - Never Entered:**
```
Task scheduled: 08:00 - 12:00
Current time: 12:30 (task ended)
Tractor location: Never entered the task zone
Status: not_done
```

---

## Status Transition Flow

```
not_started → in_progress → done
     ↓             ↓
  not_done    not_done
```

### Detailed Transitions:

1. **Creation**
   - Initial status: `not_started`

2. **Task Start Time Arrives**
   - If tractor enters zone → `in_progress`
   - If tractor doesn't enter zone → remains `not_started`

3. **Task End Time Arrives**
   - From `not_started` (never entered) → `not_done`
   - From `in_progress`:
     - If presence ≥ 30% → `done`
     - If presence < 30% → `not_done`

---

## Task Identification Algorithm

The system uses an intelligent time-based algorithm to identify the current active task:

### Key Features:
- **Time-Range Matching:** Matches GPS report timestamp with task time ranges
- **Priority System:** Prioritizes tasks already `in_progress` over `not_started` tasks
- **Midnight Crossing:** Handles tasks that span across midnight (e.g., 22:00 - 02:00)
- **Multi-Day Support:** Checks both current day and previous day tasks for midnight crossings
- **Exclusion Logic:** Automatically excludes `done` and `not_done` tasks from consideration

### Example:
```
Current Time: 10:30
Available Tasks:
  - Task A: 08:00-12:00 (not_started)
  - Task B: 09:00-11:00 (in_progress)
  - Task C: 13:00-15:00 (not_started)
  
Result: Task B is selected (prioritized due to in_progress status)
```

---

## Event Broadcasting

Status changes trigger the `TractorTaskStatusChanged` event, which broadcasts:
- Task ID
- Old status
- New status
- Timestamp of change
- Tractor information

This enables real-time notifications and logging of task progress.

---

## Related Services

- **TractorTaskStatusService:** Manages status updates and transitions
- **TractorTaskService:** Identifies current active tasks based on GPS data
- **ProcessGpsReportsJob:** Processes GPS data and triggers status updates
- **GpsMetricsCalculationService:** Calculates work duration and presence percentages

---

## Configuration

The 30% threshold is defined as a constant in `TractorTaskStatusService`:

```php
private const MINIMUM_PRESENCE_PERCENTAGE = 30;
```

To adjust this threshold, modify the constant value in:
`app/Services/TractorTaskStatusService.php`

---

## Testing

Comprehensive test coverage ensures reliability:
- **32 passing tests** covering all scenarios
- Status transition logic
- Presence percentage calculations
- Midnight crossing tasks
- Edge cases and boundary conditions

Test files:
- `tests/Feature/Services/TractorTaskStatusServiceTest.php`
- `tests/Unit/Services/TractorTaskServiceTest.php`

