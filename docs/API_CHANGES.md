# API Changes Documentation

This document outlines all API endpoints, events, parameters, and response changes for frontend developers.

## Table of Contents
1. [Removed Endpoints](#removed-endpoints)
2. [Modified Endpoints](#modified-endpoints)
3. [Removed Events](#removed-events)
4. [Modified Events](#modified-events)
5. [Response Structure Changes](#response-structure-changes)
6. [Added Parameters](#added-parameters)
7. [Removed Parameters](#removed-parameters)

---

## Removed Endpoints

### 1. `GET /api/tractors/{tractor}/timings`

**Status:** ❌ Removed

**Description:** Previously returned tractor working timings (start time, end time, on time) for a specific date.

**Previous Request:**
```
GET /api/tractors/{tractor}/timings?date=1403/09/15
```

**Previous Response:**
```json
{
  "start_working_time": "08:00:00",
  "end_working_time": "17:00:00",
  "on_time": "08:00:00"
}
```

**Alternative:** Use `start_working_time` from `/farms/{farm}/tractors/active` endpoint instead.

---

### 2. `GET /api/tractors/{tractor}/current-task`

**Status:** ❌ Removed

**Description:** Previously returned the currently active task for a specific tractor.

**Previous Request:**
```
GET /api/tractors/{tractor}/current-task
```

**Previous Response:**
```json
{
  "data": {
    "id": 1,
    "status": "in_progress",
    "operation": {
      "id": 1,
      "name": "Plowing"
    },
    "taskable": {
      "id": 1
    }
  }
}
```

Or if no current task:
```json
{
  "data": []
}
```

**Alternative:** Use `/tractors/{tractor}/tractor_tasks?date={today}` and filter tasks where `is_current === true` (see Modified Endpoints).

---

### 3. `GET /api/tractors/{tractor}/tasks`

**Status:** ❌ Removed

**Description:** Previously returned tasks for a specific tractor on a chosen date.

**Previous Request:**
```
GET /api/tractors/{tractor}/tasks?date=1403/09/15
```

**Previous Response:**
```json
{
  "data": [
    {
      "id": 1,
      "operation": {...},
      "taskable": {...},
      "date": "1403/09/15",
      "start_time": "08:00:00",
      "end_time": "12:00:00",
      "status": "in_progress"
    }
  ]
}
```

**Alternative:** Use `GET /api/tractors/{tractor}/tractor_tasks?date={shamsi_date}` instead (see Modified Endpoints).

---

## Modified Endpoints

### 1. `GET /api/tractors/{tractor}/tractor_tasks`

**Status:** ✅ Modified

**Changes:**
- **Parameter Requirement:** The `date` query parameter is now **required** (was previously optional)
- **Pagination:** Removed pagination - now returns all tasks for the specified date
- **New Field:** Added `is_current` field to indicate if task is currently active

**Request:**
```
GET /api/tractors/{tractor}/tractor_tasks?date=1403/09/15
```

**Required Parameters:**
- `date` (string, required): Shamsi date format (e.g., "1403/09/15")

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "operation": {
        "id": 1,
        "name": "Plowing"
      },
      "taskable": {
        "id": 1,
        "name": "Field A",
        "coordinates": [...]
      },
      "date": "1403/09/15",
      "start_time": "08:00:00",
      "end_time": "12:00:00",
      "status": "in_progress",
      "is_current": true,  // ← NEW FIELD
      "created_at": "1403/09/10",
      "can": {
        "update": true,
        "delete": false
      }
    }
  ]
}
```

**Breaking Changes:**
- ❌ `date` parameter is now required (was optional before)
- ❌ No pagination metadata returned (previously returned `links`, `meta` for pagination)
- ✅ Added `is_current` field to indicate if task is currently active

---

### 2. `GET /api/farms/{farm}/tractors/active`

**Status:** ✅ Modified

**Changes:** Response structure updated - removed `end_working_time` and `on_time` fields

**Request:**
```
GET /api/farms/{farm}/tractors/active
```

**Previous Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Tractor 1",
      "status": true,
      "start_working_time": "08:00:00",
      "end_working_time": "17:00:00",
      "on_time": "08:00:00"
    }
  ]
}
```

**New Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Tractor 1",
      "status": true,
      "start_working_time": "08:00:00"  // Only this field remains
    }
  ]
}
```

**Breaking Changes:**
- ❌ Removed `end_working_time` field
- ❌ Removed `on_time` field
- ✅ `start_working_time` remains (defaults to `"00:00:00"` if no data available)

---

## Removed Events

### 1. `TractorZoneStatus` Event

**Status:** ❌ Removed

**Channel:** `private-tractor.{tractor_id}`

**Event Name:** `tractor.zone.status`

**Previous Broadcast Data:**
```json
{
  "tractor_id": 1,
  "gps_device_id": 1,
  "is_in_task_zone": true,
  "task_id": 5,
  "task_name": "Plowing"
}
```

**Alternative:** Zone status information is now included in the `TractorTaskStatusChanged` event (see Modified Events).

---

## Modified Events

### 1. `TractorTaskStatusChanged` Event

**Status:** ✅ Modified

**Channel:** `private-tractor.{tractor_id}`

**Event Name:** `tractor.task.status.changed`

**Previous Broadcast Data:**
```json
{
  "task_id": 1,
  "tractor_id": 1,
  "status": "in_progress",
  "operation": {
    "id": 1,
    "name": "Plowing"
  },
  "taskable": {
    "id": 1,
    "name": "Field A"
  },
  "start_time": "08:00:00",
  "end_time": "12:00:00",
  "work_duration": 3600
}
```

**New Broadcast Data:**
```json
{
  "task_id": 1,
  "tractor_id": 1,
  "status": "in_progress",
  "is_in_task_zone": true  // ← NEW FIELD (replaces removed TractorZoneStatus event)
}
```

**Breaking Changes:**
- ❌ Removed `operation` object
- ❌ Removed `taskable` object
- ❌ Removed `start_time` field
- ❌ Removed `end_time` field
- ❌ Removed `work_duration` field
- ✅ Added `is_in_task_zone` field (boolean, nullable)

**Usage Notes:**
- Zone status is now included directly in status change events
- Use `is_in_task_zone` field instead of listening to separate `TractorZoneStatus` events
- `is_in_task_zone` values:
  - `true` when entering zone (status becomes `in_progress`)
  - `false` when exiting zone (status becomes `stopped`)
  - `null` for other status changes
- To get full task details, fetch task separately using `task_id` from the event

---

## Response Structure Changes

### 1. `TractorTaskResource`

**Added Fields:**
- ✅ `is_current` (boolean): Indicates if the current time falls within the task's date and start/end time
  - Handles midnight crossing (e.g., tasks from 22:00 to 02:00)
  - Returns `true` if current time is between start_time and end_time on the task date

**Example:**
```json
{
  "id": 1,
  "date": "1403/09/15",
  "start_time": "08:00:00",
  "end_time": "17:00:00",
  "status": "in_progress",
  "is_current": true  // ← NEW FIELD
}
```

---

### 2. `ActiveTractorResource`

**Removed Fields:**
- ❌ `end_working_time` (string)
- ❌ `on_time` (string)

**Modified Fields:**
- ✅ `start_working_time` (string): Still available, defaults to `"00:00:00"` if no data found

**Example:**
```json
{
  "id": 1,
  "name": "Tractor 1",
  "status": true,
  "start_working_time": "08:00:00"  // Only this field remains
}
```

---

## Added Parameters

### 1. `GET /api/tractors/{tractor}/tractor_tasks`

**New Required Parameter:**
- `date` (string, required): Shamsi date format
  - **Type:** Query parameter
  - **Format:** Shamsi date (e.g., "1403/09/15")
  - **Required:** Yes (was previously optional)

---

## Removed Parameters

### 1. `GET /api/tractors/{tractor}/tractor_tasks`

**Removed Optional Parameter:**
- ❌ `page` (integer): Pagination parameter - no longer supported

**Note:** The endpoint now returns all tasks for the specified date without pagination.

---

## Summary

### Endpoints
- **Removed:** 3 endpoints
- **Modified:** 2 endpoints

### Events
- **Removed:** 1 event (`TractorZoneStatus`)
- **Modified:** 1 event (`TractorTaskStatusChanged`)

### Response Fields
- **Added:** 1 field (`is_current` in task responses)
- **Removed:** 3 fields (`end_working_time`, `on_time` in active tractors, and multiple fields in `TractorTaskStatusChanged` event)

### Parameters
- **Added:** 1 required parameter (`date` in tasks endpoint)
- **Removed:** 1 optional parameter (`page` for pagination)

---

## Migration Guide

### For Clients Using Removed Endpoints:

1. **Replace `/tractors/{tractor}/timings`:**
   - Use `start_working_time` from `/farms/{farm}/tractors/active` endpoint
   - Note: `end_working_time` and `on_time` are no longer available

2. **Replace `/tractors/{tractor}/current-task`:**
   - Use `/tractors/{tractor}/tractor_tasks?date={today}` 
   - Filter tasks where `is_current === true`

3. **Replace `/tractors/{tractor}/tasks`:**
   - Use `/tractors/{tractor}/tractor_tasks?date={shamsi_date}`
   - **Important:** `date` parameter is now required

### For Clients Using Modified Events:

1. **Replace `TractorZoneStatus` event listeners:**
   - Listen to `TractorTaskStatusChanged` event instead
   - Check `is_in_task_zone` field in the event payload

2. **Update `TractorTaskStatusChanged` event handlers:**
   - Remove dependencies on `operation`, `taskable`, `start_time`, `end_time`, `work_duration` fields
   - Use `is_in_task_zone` field for zone status information
   - Fetch full task details separately if needed using `task_id`

---

**Last Updated:** Based on latest API changes
**Version:** API v1
