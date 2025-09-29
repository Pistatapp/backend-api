# Tractor Status Event

## Overview
The `TractorStatus` event is broadcast when a tractor's working status changes. This event provides real-time updates about whether a tractor is currently working or idle.

## Event Details

### Broadcast Channel
- **Channel Name**: `tractor.status`
- **Event Name**: `tractor.status.changed`
- **Channel Type**: Public Channel

### Event Data Structure
When this event is received, the payload will contain:

```json
{
  "tractor": 123,
  "status": 1
}
```

### Data Fields

| Field | Type | Description |
|-------|------|-------------|
| `tractor` | integer | The unique identifier of the tractor |
| `status` | integer | The working status of the tractor (0 = idle, 1 = working) |

## Frontend Implementation

### WebSocket Connection
Connect to the WebSocket server and listen for the event:

```javascript
// Using Laravel Echo (recommended)
Echo.channel('tractor.status')
    .listen('tractor.status.changed', (e) => {
        console.log('Tractor status changed:', e);
        // Handle the status change
        updateTractorStatus(e.tractor, e.status);
    });

// Using native WebSocket
const socket = new WebSocket('ws://your-server:port');
socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.event === 'tractor.status.changed') {
        const payload = JSON.parse(data.data);
        updateTractorStatus(payload.tractor, payload.status);
    }
};
```

### React Example
```jsx
import { useEffect, useState } from 'react';
import Echo from 'laravel-echo';

function TractorStatusComponent() {
    const [tractorStatus, setTractorStatus] = useState({});

    useEffect(() => {
        const echo = new Echo({
            broadcaster: 'pusher',
            key: 'your-pusher-key',
            cluster: 'your-cluster',
            encrypted: true
        });

        echo.channel('tractor.status')
            .listen('tractor.status.changed', (e) => {
                setTractorStatus(prev => ({
                    ...prev,
                    [e.tractor]: e.status
                }));
            });

        return () => {
            echo.leave('tractor.status');
        };
    }, []);

    return (
        <div>
            {Object.entries(tractorStatus).map(([tractorId, status]) => (
                <div key={tractorId}>
                    Tractor {tractorId}: {status ? 'Working' : 'Idle'}
                </div>
            ))}
        </div>
    );
}
```

### Vue.js Example
```vue
<template>
    <div>
        <div v-for="(status, tractorId) in tractorStatus" :key="tractorId">
            Tractor {{ tractorId }}: {{ status ? 'Working' : 'Idle' }}
        </div>
    </div>
</template>

<script>
import Echo from 'laravel-echo';

export default {
    data() {
        return {
            tractorStatus: {}
        };
    },
    mounted() {
        this.echo = new Echo({
            broadcaster: 'pusher',
            key: 'your-pusher-key',
            cluster: 'your-cluster',
            encrypted: true
        });

        this.echo.channel('tractor.status')
            .listen('tractor.status.changed', (e) => {
                this.$set(this.tractorStatus, e.tractor, e.status);
            });
    },
    beforeDestroy() {
        this.echo.leave('tractor.status');
    }
};
</script>
```

## Mobile App Implementation

### React Native Example
```javascript
import Pusher from 'pusher-js/react-native';

class TractorStatusService {
    constructor() {
        this.pusher = new Pusher('your-pusher-key', {
            cluster: 'your-cluster',
            encrypted: true
        });
        
        this.channel = this.pusher.subscribe('tractor.status');
        this.channel.bind('tractor.status.changed', this.handleStatusChange.bind(this));
    }

    handleStatusChange(data) {
        // Update your app state
        console.log('Tractor status changed:', data);
        // Emit to your state management (Redux, Context, etc.)
    }

    disconnect() {
        this.pusher.unsubscribe('tractor.status');
    }
}
```

### Flutter Example
```dart
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';

class TractorStatusService {
  late PusherChannelsFlutter pusher;

  Future<void> initialize() async {
    await PusherChannelsFlutter.init(
      apiKey: 'your-pusher-key',
      cluster: 'your-cluster',
      encrypted: true,
    );

    pusher = PusherChannelsFlutter.getInstance();
    
    await pusher.subscribe(
      channelName: 'tractor.status',
      onEvent: (event) {
        if (event.eventName == 'tractor.status.changed') {
          final data = jsonDecode(event.data);
          _handleStatusChange(data);
        }
      },
    );
  }

  void _handleStatusChange(Map<String, dynamic> data) {
    // Update your app state
    print('Tractor status changed: $data');
  }

  void disconnect() {
    pusher.unsubscribe(channelName: 'tractor.status');
  }
}
```

## Status Values

| Value | Description | UI Indication |
|-------|-------------|---------------|
| `0` | Idle/Not Working | Red indicator, "Idle" text |
| `1` | Working/Active | Green indicator, "Working" text |

## Best Practices

1. **Connection Management**: Always properly disconnect from channels when components unmount
2. **Error Handling**: Implement reconnection logic for network interruptions
3. **State Management**: Use your preferred state management solution to store tractor status
4. **UI Updates**: Update the UI immediately when receiving status changes
5. **Performance**: Consider debouncing rapid status changes if needed

## Troubleshooting

### Common Issues
1. **Connection Failed**: Check WebSocket server configuration and network connectivity
2. **Events Not Received**: Verify channel subscription and event name spelling
3. **Data Format**: Ensure you're parsing the JSON payload correctly

### Debug Tips
```javascript
// Enable debug logging
Echo.channel('tractor.status')
    .listen('tractor.status.changed', (e) => {
        console.log('Raw event data:', e);
        console.log('Tractor ID:', e.tractor);
        console.log('Status:', e.status);
    });
```
