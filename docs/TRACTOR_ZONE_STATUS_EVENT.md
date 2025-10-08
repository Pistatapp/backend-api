# Tractor Zone Status Event

## Overview
The `TractorZoneStatus` event is broadcast when a tractor's GPS location changes in relation to work zones. This event provides real-time updates about whether a tractor is within a task zone.

## Event Details

### Broadcast Channel
- **Channel Name**: `tractor.{tractor_id}` (Private Channel)
- **Event Name**: `tractor.zone.status`
- **Channel Type**: Private Channel

### Event Data Structure
When this event is received, the payload will contain:

```json
{
  "tractor_id": 123,
  "device_id": 456,
  "is_in_task_zone": true,
  "task_id": 789,
  "timestamp": "2024-01-15T10:30:00.000Z"
}
```

### Data Fields

| Field | Type | Description |
|-------|------|-------------|
| `tractor_id` | integer | The unique identifier of the tractor |
| `device_id` | integer | The unique identifier of the GPS device |
| `is_in_task_zone` | boolean | Whether the tractor is currently within a task zone |
| `task_id` | integer | The ID of the task zone (null if not in a zone) |
| `timestamp` | string | The timestamp when the zone status was determined (ISO 8601 format) |

## Frontend Implementation

### WebSocket Connection
Connect to the WebSocket server and listen for the event:

```javascript
// Using Laravel Echo (recommended)
Echo.private('tractor.' + tractorId)
    .listen('tractor.zone.status', (e) => {
        console.log('Zone status changed:', e);
        // Handle the zone status change
        updateZoneStatus(e);
    });

// Using native WebSocket with authentication
const socket = new WebSocket('ws://your-server:port');
// Note: Private channels require authentication
```

### React Example
```jsx
import { useEffect, useState } from 'react';
import Echo from 'laravel-echo';

function TractorZoneComponent({ tractorId }) {
    const [zoneStatus, setZoneStatus] = useState(null);

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

        echo.private('tractor.' + tractorId)
            .listen('tractor.zone.status', (e) => {
                setZoneStatus(e);
            });

        return () => {
            echo.leave('tractor.' + tractorId);
        };
    }, [tractorId]);

    if (!zoneStatus) return <div>Loading zone status...</div>;

    return (
        <div className="zone-status">
            <h3>Tractor #{zoneStatus.tractor_id} Zone Status</h3>
            <div className={`zone-indicator ${zoneStatus.is_in_task_zone ? 'in-zone' : 'out-of-zone'}`}>
                {zoneStatus.is_in_task_zone ? 'In Task Zone' : 'Out of Task Zone'}
            </div>
            
            {zoneStatus.is_in_task_zone && (
                <div className="zone-details">
                    <p><strong>Task ID:</strong> {zoneStatus.task_id}</p>
                </div>
            )}
            
            <p><strong>Last Updated:</strong> {new Date(zoneStatus.timestamp).toLocaleString()}</p>
        </div>
    );
}
```

### Vue.js Example
```vue
<template>
    <div class="zone-status" v-if="zoneStatus">
        <h3>Tractor #{{ zoneStatus.tractor_id }} Zone Status</h3>
        <div :class="`zone-indicator ${zoneStatus.is_in_task_zone ? 'in-zone' : 'out-of-zone'}`">
            {{ zoneStatus.is_in_task_zone ? 'In Task Zone' : 'Out of Task Zone' }}
        </div>
        
        <div v-if="zoneStatus.is_in_task_zone" class="zone-details">
            <p><strong>Task ID:</strong> {{ zoneStatus.task_id }}</p>
        </div>
        
        <p><strong>Last Updated:</strong> {{ formatDate(zoneStatus.timestamp) }}</p>
    </div>
</template>

<script>
import Echo from 'laravel-echo';

export default {
    props: ['tractorId'],
    data() {
        return {
            zoneStatus: null
        };
    },
    methods: {
        formatDuration(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            return `${hours}h ${minutes}m ${secs}s`;
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

        this.echo.private('tractor.' + this.tractorId)
            .listen('tractor.zone.status', (e) => {
                this.zoneStatus = e;
            });
    },
    beforeDestroy() {
        this.echo.leave('tractor.' + this.tractorId);
    }
};
</script>

<style scoped>
.zone-indicator {
    padding: 10px;
    border-radius: 5px;
    font-weight: bold;
    text-align: center;
    margin: 10px 0;
}

.in-zone {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.out-of-zone {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.zone-details {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin: 10px 0;
}
</style>
```

## Mobile App Implementation

### React Native Example
```javascript
import Pusher from 'pusher-js/react-native';

class TractorZoneService {
    constructor(tractorId, authToken) {
        this.tractorId = tractorId;
        this.pusher = new Pusher('your-pusher-key', {
            cluster: 'your-cluster',
            encrypted: true,
            auth: {
                headers: {
                    Authorization: 'Bearer ' + authToken
                }
            }
        });
        
        this.channel = this.pusher.subscribe('private-tractor.' + tractorId);
        this.channel.bind('tractor.zone.status', this.handleZoneStatus.bind(this));
    }

    handleZoneStatus(data) {
        // Update your app state
        console.log('Zone status changed:', data);
        // Emit to your state management (Redux, Context, etc.)
        this.onZoneStatusChange?.(data);
    }

    formatDuration(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return `${hours}h ${minutes}m ${secs}s`;
    }

    disconnect() {
        this.pusher.unsubscribe('private-tractor.' + this.tractorId);
    }
}

// Usage
const zoneService = new TractorZoneService(tractorId, authToken);
zoneService.onZoneStatusChange = (data) => {
    // Handle zone status change in your component
    console.log('Tractor is in zone:', data.is_in_task_zone);
    if (data.is_in_task_zone) {
        console.log('Task ID:', data.task_id);
    }
};
```

### Flutter Example
```dart
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';
import 'dart:convert';

class TractorZoneService {
  late PusherChannelsFlutter pusher;
  final String tractorId;
  final String authToken;

  TractorZoneService(this.tractorId, this.authToken);

  Future<void> initialize() async {
    await PusherChannelsFlutter.init(
      apiKey: 'your-pusher-key',
      cluster: 'your-cluster',
      encrypted: true,
    );

    pusher = PusherChannelsFlutter.getInstance();
    
    await pusher.subscribe(
      channelName: 'private-tractor.$tractorId',
      onEvent: (event) {
        if (event.eventName == 'tractor.zone.status') {
          final data = jsonDecode(event.data);
          _handleZoneStatus(data);
        }
      },
    );
  }

  void _handleZoneStatus(Map<String, dynamic> data) {
    // Update your app state
    print('Zone status changed: $data');
    onZoneStatusChange?.call(data);
  }

  String formatDuration(int seconds) {
    final hours = seconds ~/ 3600;
    final minutes = (seconds % 3600) ~/ 60;
    final secs = seconds % 60;
    return '${hours}h ${minutes}m ${secs}s';
  }

  void disconnect() {
    pusher.unsubscribe(channelName: 'private-tractor.$tractorId');
  }

  Function(Map<String, dynamic>)? onZoneStatusChange;
}
```

## Zone Status Values

| Field | Value | Description | UI Indication |
|-------|-------|-------------|---------------|
| `is_in_task_zone` | `true` | Tractor is within a task zone | Green indicator, "In Zone" text |
| `is_in_task_zone` | `false` | Tractor is outside task zones | Red indicator, "Out of Zone" text |

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
2. **Tractor-Specific Subscriptions**: Only subscribe to channels for tractors the user has access to
3. **Zone Transitions**: Handle transitions between zones smoothly in your UI
4. **Real-time Updates**: Update the UI immediately when zone status changes
5. **Memory Management**: Unsubscribe from channels when components unmount

## Use Cases

1. **Field Monitoring**: Track which tractors are working in specific fields
2. **Zone Detection**: Monitor when tractors enter or leave assigned task zones
3. **Task Progress**: Show real-time progress of field operations
4. **Fleet Management**: Monitor multiple tractors across different zones
5. **Compliance**: Ensure tractors are working in designated areas

## Troubleshooting

### Common Issues
1. **Authentication Failed**: Check that the auth token is valid and properly formatted
2. **Channel Access Denied**: Verify the user has access to the specific tractor
3. **Events Not Received**: Ensure the tractor ID is correct and the channel name is properly formatted

### Debug Tips
```javascript
// Enable debug logging
Echo.private('tractor.' + tractorId)
    .listen('tractor.zone.status', (e) => {
        console.log('Raw event data:', e);
        console.log('Tractor ID:', e.tractor_id);
        console.log('In Zone:', e.is_in_task_zone);
        console.log('Task ID:', e.task_id);
    });
```
