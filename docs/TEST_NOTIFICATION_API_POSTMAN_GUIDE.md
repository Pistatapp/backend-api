# Test Notification API - Postman Collection Guide

This guide explains how to use the Postman collection for testing the notification API endpoints in the PiStat application.

## Overview

The Test Notification API provides endpoints to test different types of notifications:
- **Firebase Push Notifications**: Mobile push notifications via Firebase Cloud Messaging
- **Database Notifications**: Stored notifications in the database
- **WebSocket Private**: Real-time notifications on private user channels
- **WebSocket Public**: Real-time notifications on public channels

## Collection Structure

The Postman collection is organized into the following folders:

### 1. Authentication
- **Send Token**: Send verification code to phone number
- **Verify Token**: Verify code and get authentication token

### 2. Test Notifications
- **Test All Notification Types**: Test all notification types at once
- **Test Firebase Notification Only**: Test only Firebase notifications
- **Test Database Notification Only**: Test only database notifications
- **Test WebSocket Private Only**: Test only private WebSocket
- **Test WebSocket Public Only**: Test only public WebSocket

### 3. Single Notification Type Tests
- **Test Single Firebase Notification**: Individual Firebase test endpoint
- **Test Single Database Notification**: Individual database test endpoint
- **Test Single Private WebSocket**: Individual private WebSocket test endpoint
- **Test Single Public WebSocket**: Individual public WebSocket test endpoint

### 4. Notification Management
- **Get All Notifications**: Retrieve user notifications
- **Mark Notification as Read**: Mark specific notification as read
- **Mark All Notifications as Read**: Mark all notifications as read

### 5. Error Testing
- **Test Invalid Notification Type**: Test error handling
- **Test Missing Required Fields**: Test validation errors
- **Test Without Authentication**: Test authentication errors

## Setup Instructions

### 1. Environment Variables

Set up the following environment variables in Postman:

```json
{
  "base_url": "http://localhost:8000",
  "auth_token": "",
  "notification_id": ""
}
```

### 2. Authentication Flow

1. **Send Token**:
   ```json
   POST /api/auth/send
   {
     "phone": "09123456789"
   }
   ```

2. **Verify Token**:
   ```json
   POST /api/auth/verify
   {
     "phone": "09123456789",
     "token": "123456"
   }
   ```

3. Copy the `access_token` from the response and set it as the `auth_token` environment variable.

## API Endpoints

### Test All Notifications

**Endpoint**: `POST /api/test/notifications`

**Headers**:
- `Content-Type: application/json`
- `Accept: application/json`
- `Authorization: Bearer {token}`

**Request Body**:
```json
{
  "title": "Test Notification",
  "content": "This is a test notification to verify all notification channels are working properly.",
  "test_types": [
    "firebase",
    "database",
    "websocket_private",
    "websocket_public"
  ]
}
```

**Response**:
```json
{
  "message": "Notification tests completed",
  "user_id": 1,
  "test_data": {
    "title": "Test Notification",
    "content": "This is a test notification..."
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

**Endpoint**: `POST /api/test/notifications/{type}`

**Available Types**:
- `firebase`
- `database`
- `websocket-private`
- `websocket-public`

**Request Body**:
```json
{
  "title": "Single Firebase Test",
  "content": "Testing individual Firebase notification endpoint."
}
```

**Response**:
```json
{
  "status": "success",
  "message": "Firebase notification sent successfully",
  "user_id": 1,
  "fcm_token_exists": true
}
```

## Request Parameters

### Required Parameters

| Parameter | Type | Description | Validation |
|-----------|------|-------------|------------|
| `title` | string | Notification title | Required, max 255 characters |
| `content` | string | Notification content | Required, max 1000 characters |

### Optional Parameters

| Parameter | Type | Description | Default | Validation |
|-----------|------|-------------|---------|------------|
| `test_types` | array | Array of notification types to test | `["firebase", "database", "websocket_private", "websocket_public"]` | Each item must be one of: `firebase`, `database`, `websocket_private`, `websocket_public` |

## Notification Types Explained

### Firebase Notifications
- **Purpose**: Mobile push notifications
- **Channel**: Firebase Cloud Messaging (FCM)
- **Requirements**: User must have a valid FCM token
- **Data**: Includes title, body, and custom data payload

### Database Notifications
- **Purpose**: Stored notifications in database
- **Channel**: Laravel's database notification system
- **Storage**: Stored in `notifications` table
- **Retrieval**: Can be fetched via `/api/notifications` endpoint

### WebSocket Private
- **Purpose**: Real-time notifications for specific user
- **Channel**: `user.{user_id}` (private channel)
- **Broadcasting**: Uses Laravel Broadcasting
- **Event**: `TestEvent` with type 'private'

### WebSocket Public
- **Purpose**: Real-time notifications for all users
- **Channel**: `test-channel` (public channel)
- **Broadcasting**: Uses Laravel Broadcasting
- **Event**: `TestEvent` with type 'public'

## Testing Scenarios

### 1. Basic Functionality Test
```json
{
  "title": "Basic Test",
  "content": "Testing basic notification functionality",
  "test_types": ["firebase", "database"]
}
```

### 2. WebSocket Only Test
```json
{
  "title": "WebSocket Test",
  "content": "Testing WebSocket broadcasting",
  "test_types": ["websocket_private", "websocket_public"]
}
```

### 3. Single Type Test
```json
{
  "title": "Firebase Only",
  "content": "Testing only Firebase notifications"
}
```

## Error Responses

### Validation Errors (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."]
  }
}
```

### Authentication Errors (401)
```json
{
  "message": "Unauthenticated."
}
```

### Invalid Type Errors (400)
```json
{
  "error": "Invalid test type"
}
```

## WebSocket Testing

To test WebSocket functionality, you'll need to:

1. **Connect to WebSocket server** (usually `ws://localhost:6001`)
2. **Subscribe to channels**:
   - Private: `user.{user_id}`
   - Public: `test-channel`
3. **Listen for events**: `test.notification`

### WebSocket Event Structure
```json
{
  "title": "Test Notification",
  "content": "This is a test WebSocket broadcast: ...",
  "type": "private",
  "timestamp": "2024-01-24T10:00:00.000000Z",
  "user_id": 1,
  "message": "This is a test WebSocket broadcast: ..."
}
```

## Best Practices

### 1. Authentication
- Always include the `Authorization: Bearer {token}` header
- Refresh tokens when they expire
- Use environment variables for tokens

### 2. Testing Order
1. Start with authentication
2. Test individual notification types
3. Test all types together
4. Test error scenarios

### 3. Environment Setup
- Use different environments for different stages (dev, staging, production)
- Keep sensitive data in environment variables
- Use descriptive variable names

### 4. Response Validation
- Check response status codes
- Validate response structure
- Test both success and error scenarios

## Troubleshooting

### Common Issues

1. **401 Unauthorized**
   - Check if authentication token is valid
   - Ensure token is included in Authorization header
   - Verify token format: `Bearer {token}`

2. **422 Validation Error**
   - Check required fields are provided
   - Verify field lengths (title: max 255, content: max 1000)
   - Ensure test_types array contains valid values

3. **Firebase Notification Failures**
   - Check if user has FCM token
   - Verify Firebase configuration
   - Check Firebase service account credentials

4. **WebSocket Connection Issues**
   - Verify WebSocket server is running
   - Check WebSocket URL and port
   - Ensure proper channel subscription

### Debug Tips

1. **Enable Request/Response Logging**
   - Use Postman Console to see detailed logs
   - Check network tab for request details

2. **Test Individual Components**
   - Test each notification type separately
   - Use single notification endpoints for debugging

3. **Check Server Logs**
   - Monitor Laravel logs for errors
   - Check WebSocket server logs
   - Verify Firebase logs

## Collection Import

To import the collection:

1. Open Postman
2. Click "Import" button
3. Select the `Test_Notification_API_Collection.postman_collection.json` file
4. Set up environment variables
5. Start testing!

## Additional Resources

- [Laravel Notifications Documentation](https://laravel.com/docs/notifications)
- [Laravel Broadcasting Documentation](https://laravel.com/docs/broadcasting)
- [Firebase Cloud Messaging Documentation](https://firebase.google.com/docs/cloud-messaging)
- [Postman Documentation](https://learning.postman.com/docs/)
