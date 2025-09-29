# Tractor Task Status Changed Event

## Overview
The `TractorTaskStatusChanged` event is broadcast when a tractor task's status changes. This event provides real-time updates about task progress, including status transitions from pending to started to finished.

## Event Details

### Broadcast Channel
- **Channel Name**: `tractor.tasks.{task_id}` (Private Channel)
- **Event Name**: `tractor.task.status_changed`
- **Channel Type**: Private Channel

### Event Data Structure
When this event is received, the payload will contain:

```json
{
  "task_id": 456,
  "tractor_id": 123,
  "status": "started",
  "operation": {
    "id": 789,
    "name": "Plowing Operation"
  },
  "taskable": {
    "id": 101,
    "name": "Field A"
  },
  "start_time": "2024-01-15T08:00:00Z",
  "end_time": "2024-01-15T17:00:00Z",
  "created_at": "2024-01-15T08:00:00.000Z"
}
```

### Data Fields

| Field | Type | Description |
|-------|------|-------------|
| `task_id` | integer | The unique identifier of the task |
| `tractor_id` | integer | The unique identifier of the tractor performing the task |
| `status` | string | The current status of the task (`pending`, `started`, `finished`) |
| `operation` | object | Information about the operation being performed |
| `operation.id` | integer | The unique identifier of the operation |
| `operation.name` | string | The name of the operation |
| `taskable` | object | Information about the entity being worked on (field, area, etc.) |
| `taskable.id` | integer | The unique identifier of the taskable entity |
| `taskable.name` | string | The name of the taskable entity |
| `start_time` | string | The scheduled start time of the task (ISO 8601 format) |
| `end_time` | string | The scheduled end time of the task (ISO 8601 format) |
| `created_at` | string | The timestamp when the event was created (ISO 8601 format) |

## Frontend Implementation

### WebSocket Connection
Connect to the WebSocket server and listen for the event:

```javascript
// Using Laravel Echo (recommended)
Echo.private('tractor.tasks.' + taskId)
    .listen('tractor.task.status_changed', (e) => {
        console.log('Task status changed:', e);
        // Handle the status change
        updateTaskStatus(e);
    });

// Using native WebSocket with authentication
const socket = new WebSocket('ws://your-server:port');
// Note: Private channels require authentication
```

### React Example
```jsx
import { useEffect, useState } from 'react';
import Echo from 'laravel-echo';

function TaskStatusComponent({ taskId }) {
    const [taskStatus, setTaskStatus] = useState(null);

    useEffect(() => {
        const echo = new Echo({
            broadcaster: 'pusher',
            key: 'your-pusher-key',
            cluster: 'your-cluster',
            encrypted: true,
            auth: {
                headers: {
                    Authorization: 'Bearer ' + localStorage.getItem('token')
                }
            }
        });

        echo.private('tractor.tasks.' + taskId)
            .listen('tractor.task.status_changed', (e) => {
                setTaskStatus(e);
            });

        return () => {
            echo.leave('tractor.tasks.' + taskId);
        };
    }, [taskId]);

    if (!taskStatus) return <div>Loading...</div>;

    return (
        <div className="task-status">
            <h3>Task #{taskStatus.task_id}</h3>
            <p>Status: <span className={`status-${taskStatus.status}`}>
                {taskStatus.status.charAt(0).toUpperCase() + taskStatus.status.slice(1)}
            </span></p>
            <p>Operation: {taskStatus.operation.name}</p>
            <p>Field: {taskStatus.taskable.name}</p>
            <p>Start Time: {new Date(taskStatus.start_time).toLocaleString()}</p>
            <p>End Time: {new Date(taskStatus.end_time).toLocaleString()}</p>
        </div>
    );
}
```

### Vue.js Example
```vue
<template>
    <div class="task-status" v-if="taskStatus">
        <h3>Task #{{ taskStatus.task_id }}</h3>
        <p>Status: <span :class="`status-${taskStatus.status}`">
            {{ capitalize(taskStatus.status) }}
        </span></p>
        <p>Operation: {{ taskStatus.operation.name }}</p>
        <p>Field: {{ taskStatus.taskable.name }}</p>
        <p>Start Time: {{ formatDate(taskStatus.start_time) }}</p>
        <p>End Time: {{ formatDate(taskStatus.end_time) }}</p>
    </div>
</template>

<script>
import Echo from 'laravel-echo';

export default {
    props: ['taskId'],
    data() {
        return {
            taskStatus: null
        };
    },
    methods: {
        capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        },
        formatDate(dateString) {
            return new Date(dateString).toLocaleString();
        }
    },
    mounted() {
        this.echo = new Echo({
            broadcaster: 'pusher',
            key: 'your-pusher-key',
            cluster: 'your-cluster',
            encrypted: true,
            auth: {
                headers: {
                    Authorization: 'Bearer ' + localStorage.getItem('token')
                }
            }
        });

        this.echo.private('tractor.tasks.' + this.taskId)
            .listen('tractor.task.status_changed', (e) => {
                this.taskStatus = e;
            });
    },
    beforeDestroy() {
        this.echo.leave('tractor.tasks.' + this.taskId);
    }
};
</script>

<style scoped>
.status-pending { color: orange; }
.status-started { color: blue; }
.status-finished { color: green; }
</style>
```

## Mobile App Implementation

### React Native Example
```javascript
import Pusher from 'pusher-js/react-native';

class TaskStatusService {
    constructor(taskId, authToken) {
        this.taskId = taskId;
        this.pusher = new Pusher('your-pusher-key', {
            cluster: 'your-cluster',
            encrypted: true,
            auth: {
                headers: {
                    Authorization: 'Bearer ' + authToken
                }
            }
        });
        
        this.channel = this.pusher.subscribe('private-tractor.tasks.' + taskId);
        this.channel.bind('tractor.task.status_changed', this.handleStatusChange.bind(this));
    }

    handleStatusChange(data) {
        // Update your app state
        console.log('Task status changed:', data);
        // Emit to your state management (Redux, Context, etc.)
        this.onStatusChange?.(data);
    }

    disconnect() {
        this.pusher.unsubscribe('private-tractor.tasks.' + this.taskId);
    }
}

// Usage
const taskService = new TaskStatusService(taskId, authToken);
taskService.onStatusChange = (data) => {
    // Handle status change in your component
};
```

### Flutter Example
```dart
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';
import 'dart:convert';

class TaskStatusService {
  late PusherChannelsFlutter pusher;
  final String taskId;
  final String authToken;

  TaskStatusService(this.taskId, this.authToken);

  Future<void> initialize() async {
    await PusherChannelsFlutter.init(
      apiKey: 'your-pusher-key',
      cluster: 'your-cluster',
      encrypted: true,
    );

    pusher = PusherChannelsFlutter.getInstance();
    
    await pusher.subscribe(
      channelName: 'private-tractor.tasks.$taskId',
      onEvent: (event) {
        if (event.eventName == 'tractor.task.status_changed') {
          final data = jsonDecode(event.data);
          _handleStatusChange(data);
        }
      },
    );
  }

  void _handleStatusChange(Map<String, dynamic> data) {
    // Update your app state
    print('Task status changed: $data');
    onStatusChange?.call(data);
  }

  void disconnect() {
    pusher.unsubscribe(channelName: 'private-tractor.tasks.$taskId');
  }

  Function(Map<String, dynamic>)? onStatusChange;
}
```

## Status Values

| Status | Description | UI Indication |
|--------|-------------|---------------|
| `pending` | Task is scheduled but not started | Orange indicator, "Pending" text |
| `started` | Task is currently in progress | Blue indicator, "In Progress" text |
| `finished` | Task has been completed | Green indicator, "Completed" text |

## Authentication Requirements

Since this event uses a private channel, you must provide authentication:

### Web Frontend
```javascript
// Include auth headers when initializing Echo
const echo = new Echo({
    broadcaster: 'pusher',
    key: 'your-pusher-key',
    cluster: 'your-cluster',
    encrypted: true,
    auth: {
        headers: {
            Authorization: 'Bearer ' + authToken
        }
    }
});
```

### Mobile Apps
```javascript
// React Native
const pusher = new Pusher('your-pusher-key', {
    cluster: 'your-cluster',
    encrypted: true,
    auth: {
        headers: {
            Authorization: 'Bearer ' + authToken
        }
    }
});
```

## Best Practices

1. **Authentication**: Always provide proper authentication for private channels
2. **Task-Specific Subscriptions**: Only subscribe to channels for tasks the user has access to
3. **Status Transitions**: Handle all possible status transitions in your UI
4. **Time Formatting**: Use proper date formatting for start_time and end_time
5. **Error Handling**: Implement proper error handling for authentication failures
6. **Memory Management**: Unsubscribe from channels when components unmount

## Troubleshooting

### Common Issues
1. **Authentication Failed**: Check that the auth token is valid and properly formatted
2. **Channel Access Denied**: Verify the user has access to the specific task
3. **Events Not Received**: Ensure the task ID is correct and the channel name is properly formatted

### Debug Tips
```javascript
// Enable debug logging
Echo.private('tractor.tasks.' + taskId)
    .listen('tractor.task.status_changed', (e) => {
        console.log('Raw event data:', e);
        console.log('Task ID:', e.task_id);
        console.log('Status:', e.status);
        console.log('Operation:', e.operation);
    });
```
