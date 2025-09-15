# Test Notifications API

This API provides endpoints to test Firebase notifications, database notifications, and WebSocket broadcasting functionality.

## Endpoints

### Test All Notification Types

**POST** `/api/test/notifications`

Test all notification types (Firebase, database, WebSocket private, WebSocket public) with a single request.

#### Request Body

```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content",
    "test_types": ["firebase", "database", "websocket_private", "websocket_public"]
}
```

#### Parameters

- `title` (required): The notification title (max 255 characters)
- `content` (required): The notification content (max 1000 characters)
- `test_types` (optional): Array of test types to run. Defaults to all types if not provided.
  - `firebase`: Test Firebase Cloud Messaging
  - `database`: Test database notifications
  - `websocket_private`: Test private WebSocket channel
  - `websocket_public`: Test public WebSocket channel

#### Response

```json
{
    "message": "Notification tests completed",
    "user_id": 1,
    "test_data": {
        "title": "Test Notification Title",
        "content": "This is a test notification content"
    },
    "results": {
        "firebase": {
            "status": "success",
            "message": "Firebase notification sent successfully"
        },
        "database": {
            "status": "success",
            "message": "Database notification created successfully"
        },
        "websocket_private": {
            "status": "success",
            "message": "Private WebSocket event broadcasted successfully",
            "channel": "user.1"
        },
        "websocket_public": {
            "status": "success",
            "message": "Public WebSocket event broadcasted successfully",
            "channel": "test-channel"
        }
    }
}
```

### Test Single Notification Type

**POST** `/api/test/notifications/{type}`

Test a specific notification type.

#### Parameters

- `type`: The notification type to test (`firebase`, `database`, `websocket-private`, `websocket-public`)

#### Request Body

```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content"
}
```

#### Response Examples

**Firebase Test:**
```json
{
    "status": "success",
    "message": "Firebase notification sent successfully",
    "user_id": 1,
    "fcm_token_exists": true
}
```

**Database Test:**
```json
{
    "status": "success",
    "message": "Database notification created successfully",
    "user_id": 1,
    "notification_count": 5
}
```

**WebSocket Private Test:**
```json
{
    "status": "success",
    "message": "Private WebSocket event broadcasted successfully",
    "user_id": 1,
    "channel": "user.1"
}
```

**WebSocket Public Test:**
```json
{
    "status": "success",
    "message": "Public WebSocket event broadcasted successfully",
    "user_id": 1,
    "channel": "test-channel"
}
```

## Authentication

All endpoints require authentication using Laravel Sanctum. Include the bearer token in the Authorization header:

```
Authorization: Bearer {your-token}
```

## WebSocket Channels

### Private Channel
- **Channel Name**: `user.{user_id}`
- **Event Name**: `test.notification`
- **Authorization**: User must be authenticated and match the user ID

### Public Channel
- **Channel Name**: `test-channel`
- **Event Name**: `test.notification`
- **Authorization**: Public channel (no authentication required)

## WebSocket Event Data

The WebSocket events will contain the following data:

```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content",
    "type": "private|public",
    "timestamp": "2024-01-01T12:00:00.000000Z",
    "user_id": 1,
    "message": "This is a test WebSocket broadcast: This is a test notification content"
}
```

## Error Handling

If any test fails, the response will include error details:

```json
{
    "status": "error",
    "message": "Firebase notification failed: Invalid FCM token"
}
```

## Usage Examples

### Test All Types
```bash
curl -X POST "https://your-domain.com/api/test/notifications" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "System Test",
    "content": "Testing all notification systems"
  }'
```

### Test Only Firebase
```bash
curl -X POST "https://your-domain.com/api/test/notifications/firebase" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Firebase Test",
    "content": "Testing Firebase notifications only"
  }'
```

### Test Only WebSocket Public
```bash
curl -X POST "https://your-domain.com/api/test/notifications/websocket-public" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "WebSocket Test",
    "content": "Testing public WebSocket channel"
  }'
```
