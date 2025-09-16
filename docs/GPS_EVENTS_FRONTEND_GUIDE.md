# GPS Events Frontend Integration Guide

This guide explains the real-time GPS events that are broadcast to the frontend for tractor monitoring and task management.

## Event Overview

The system broadcasts four main types of events:

1. **TractorZoneStatus** - Real-time zone monitoring
2. **TractorTaskStatusChanged** - Task status updates
3. **ReportReceived** - GPS metrics and location data
4. **TractorStatus** - Tractor working status

---

## 1. TractorZoneStatus Event

**Event Name:** `tractor.zone.status`  
**Channel:** `tractor.{tractor_id}` (Private Channel)

### Purpose
Provides real-time information about whether a tractor is currently working inside or outside its assigned task zone.

### When It's Triggered
- Every time GPS reports are processed
- When a tractor enters or exits a task zone
- When a tractor has no assigned task

### Event Data Structure

```javascript
{
  "tractor_id": 123,
  "device_id": 456,
  "is_in_task_zone": true,
  "task_id": 789,
  "task_name": "Field Irrigation - North Section",
  "work_duration_in_zone": "02:15:30",
  "timestamp": "2024-01-24T10:30:45.000Z"
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `tractor_id` | number | Unique identifier of the tractor |
| `device_id` | number | Unique identifier of the GPS device |
| `is_in_task_zone` | boolean | `true` if tractor is inside assigned task zone, `false` if outside |
| `task_id` | number\|null | ID of the current task (null if no task assigned) |
| `task_name` | string\|null | Human-readable name of the current task (null if no task) |
| `work_duration_in_zone` | string\|null | Total work time in zone formatted as "HH:MM:SS" (null if outside zone) |
| `timestamp` | string | ISO 8601 timestamp when the event was generated |

### Frontend Usage Examples

```javascript
// Listen for zone status events
Echo.private('tractor.123')
  .listen('tractor.zone.status', (e) => {
    if (e.is_in_task_zone) {
      console.log(`Tractor is working in zone: ${e.task_name}`);
      console.log(`Work duration: ${e.work_duration_in_zone}`);
    } else {
      console.log('Tractor is outside assigned zone');
    }
  });
```

---

## 2. TractorTaskStatusChanged Event

**Event Name:** `tractor.task.status_changed`  
**Channel:** `tractor.tasks.{task_id}` (Private Channel)

### Purpose
Notifies when a tractor task status changes (pending → started → finished).

### When It's Triggered
- When a task is assigned to a tractor
- When a task is started
- When a task is completed/finished

### Event Data Structure

```javascript
{
  "task_id": 789,
  "tractor_id": 123,
  "status": "started",
  "operation": {
    "id": 101,
    "name": "Irrigation"
  },
  "taskable": {
    "id": 202,
    "name": "North Field"
  },
  "start_time": "08:00:00",
  "end_time": "17:00:00",
  "created_at": "2024-01-24T08:00:00.000Z"
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `task_id` | number | Unique identifier of the task |
| `tractor_id` | number | Unique identifier of the assigned tractor |
| `status` | string | Task status: `"pending"`, `"started"`, or `"finished"` |
| `operation` | object | Information about the operation type |
| `operation.id` | number | Unique identifier of the operation |
| `operation.name` | string | Name of the operation (e.g., "Irrigation", "Plowing") |
| `taskable` | object | Information about the work area |
| `taskable.id` | number | Unique identifier of the work area |
| `taskable.name` | string | Name of the work area (e.g., "North Field", "Plot A") |
| `start_time` | string | Scheduled start time in "HH:MM:SS" format |
| `end_time` | string | Scheduled end time in "HH:MM:SS" format |
| `created_at` | string | ISO 8601 timestamp when the event was generated |

### Frontend Usage Examples

```javascript
// Listen for task status changes
Echo.private('tractor.tasks.789')
  .listen('tractor.task.status_changed', (e) => {
    switch(e.status) {
      case 'pending':
        console.log(`Task "${e.taskable.name}" is pending`);
        break;
      case 'started':
        console.log(`Task "${e.taskable.name}" has started`);
        break;
      case 'finished':
        console.log(`Task "${e.taskable.name}" is completed`);
        break;
    }
  });
```

---

## 3. ReportReceived Event

**Event Name:** `report-received`  
**Channel:** `gps_devices.{device_id}` (Private Channel)

### Purpose
Provides GPS coordinate points data after processing GPS reports. This event focuses on location tracking and movement visualization.

### When It's Triggered
- After GPS reports are processed
- When new GPS coordinate points are available
- Typically every few minutes during active work periods

### Event Data Structure

```javascript
{
  "points": [
    {
      "latitude": 34.885,
      "longitude": 50.585,
      "speed": 12,
      "status": 1,
      "directions": {"ew": 90, "ns": 0},
      "is_starting_point": true,
      "is_ending_point": false,
      "is_stopped": false,
      "stoppage_time": "00:00:00",
      "date_time": "1403/11/04 10:30:45"
    },
    {
      "latitude": 34.886,
      "longitude": 50.586,
      "speed": 15,
      "status": 1,
      "directions": {"ew": 95, "ns": 5},
      "is_starting_point": false,
      "is_ending_point": false,
      "is_stopped": false,
      "stoppage_time": "00:00:00",
      "date_time": "1403/11/04 10:31:15"
    }
  ]
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `points` | array | Array of GPS coordinate points |
| `points[].latitude` | number | GPS latitude coordinate |
| `points[].longitude` | number | GPS longitude coordinate |
| `points[].speed` | number | Speed at this point in km/h |
| `points[].status` | number | GPS status (1 = valid, 0 = invalid) |
| `points[].directions` | object | Direction information |
| `points[].directions.ew` | number | East-West direction (0-360 degrees) |
| `points[].directions.ns` | number | North-South direction (0-360 degrees) |
| `points[].is_starting_point` | boolean | Whether this is a work starting point |
| `points[].is_ending_point` | boolean | Whether this is a work ending point |
| `points[].is_stopped` | boolean | Whether the tractor was stopped at this point |
| `points[].stoppage_time` | string | Accumulated stoppage time at this point in "HH:MM:SS" format |
| `points[].date_time` | string | Persian date/time when this point was recorded |

### Frontend Usage Examples

```javascript
// Listen for GPS reports
Echo.private('gps_devices.456')
  .listen('report-received', (e) => {
    // Update map with new points
    e.points.forEach(point => {
      if (point.status === 1) { // Only valid GPS points
        addMapMarker(point.latitude, point.longitude, point.speed);
        
        // Draw path between points
        if (point.is_starting_point) {
          startNewPath(point.latitude, point.longitude);
        } else {
          extendPath(point.latitude, point.longitude);
        }
        
        // Update speed indicator
        updateSpeedIndicator(point.speed);
        
        // Handle stoppage visualization
        if (point.is_stopped) {
          showStoppageIndicator(point.latitude, point.longitude, point.stoppage_time);
        }
      }
    });
  });
```

---

## 4. TractorStatus Event

**Event Name:** `tractor.status.changed`  
**Channel:** `tractor.status` (Public Channel)

### Purpose
Notifies when a tractor's working status changes (on/off).

### When It's Triggered
- When a tractor is turned on
- When a tractor is turned off
- When tractor activity is detected/stopped

### Event Data Structure

```javascript
{
  "tractor": 123,
  "status": 1
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `tractor` | number | Unique identifier of the tractor |
| `status` | number | Working status: `1` = working/on, `0` = stopped/off |

### Frontend Usage Examples

```javascript
// Listen for tractor status changes
Echo.channel('tractor.status')
  .listen('tractor.status.changed', (e) => {
    const statusElement = document.getElementById(`tractor-${e.tractor}-status`);
    if (e.status === 1) {
      statusElement.textContent = 'Working';
      statusElement.className = 'status-working';
    } else {
      statusElement.textContent = 'Stopped';
      statusElement.className = 'status-stopped';
    }
  });
```

---

## Channel Authentication

### Private Channels
- `tractor.{tractor_id}` - Requires authentication for the specific tractor
- `tractor.tasks.{task_id}` - Requires authentication for the specific task
- `gps_devices.{device_id}` - Requires authentication for the specific GPS device

### Public Channels
- `tractor.status` - No authentication required

### Authentication Setup
```javascript
// Configure Echo with authentication
window.Echo = new Echo({
  broadcaster: 'pusher',
  key: 'your-pusher-key',
  cluster: 'your-cluster',
  authEndpoint: '/broadcasting/auth',
  auth: {
    headers: {
      Authorization: 'Bearer ' + yourAuthToken
    }
  }
});
```

---

## Error Handling

### Common Issues

1. **Authentication Failures**
   ```javascript
   Echo.connector.pusher.connection.bind('error', (error) => {
     console.error('Connection error:', error);
   });
   ```

2. **Channel Subscription Failures**
   ```javascript
   Echo.private('tractor.123')
     .error((error) => {
       console.error('Channel subscription failed:', error);
     });
   ```

3. **Event Processing Errors**
   ```javascript
   Echo.private('tractor.123')
     .listen('tractor.zone.status', (e) => {
       try {
         // Process event data
       } catch (error) {
         console.error('Event processing error:', error);
       }
     });
   ```

---

## Best Practices

### 1. Event Handling
- Always validate event data before processing
- Handle null/undefined values gracefully
- Use try-catch blocks for event processing

### 2. Performance
- Unsubscribe from channels when components unmount
- Debounce rapid events if needed
- Cache frequently accessed data

### 3. User Experience
- Show loading states during connection setup
- Display connection status to users
- Provide fallback mechanisms for offline scenarios

### 4. Data Formatting
- Use the provided formatted strings (distances, durations)
- Convert timestamps to local time zones
- Format numbers appropriately for display

---

## Example Integration

```javascript
class TractorMonitor {
  constructor(tractorId, deviceId) {
    this.tractorId = tractorId;
    this.deviceId = deviceId;
    this.setupEventListeners();
  }

  setupEventListeners() {
    // Zone status monitoring
    Echo.private(`tractor.${this.tractorId}`)
      .listen('tractor.zone.status', this.handleZoneStatus.bind(this));

    // GPS reports
    Echo.private(`gps_devices.${this.deviceId}`)
      .listen('report-received', this.handleReport.bind(this));

    // Tractor status
    Echo.channel('tractor.status')
      .listen('tractor.status.changed', this.handleStatusChange.bind(this));
  }

  handleZoneStatus(event) {
    const zoneIndicator = document.getElementById('zone-status');
    if (event.is_in_task_zone) {
      zoneIndicator.textContent = `In Zone: ${event.task_name}`;
      zoneIndicator.className = 'zone-indicator in-zone';
    } else {
      zoneIndicator.textContent = 'Outside Zone';
      zoneIndicator.className = 'zone-indicator out-of-zone';
    }
  }

  handleReport(event) {
    // Update map with new GPS points
    this.updateMap(event.points);
  }

  handleStatusChange(event) {
    if (event.tractor === this.tractorId) {
      const statusElement = document.getElementById('tractor-status');
      statusElement.textContent = event.status ? 'Working' : 'Stopped';
    }
  }
}

// Initialize monitoring for tractor ID 123 with device ID 456
const monitor = new TractorMonitor(123, 456);
```
