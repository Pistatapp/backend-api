# Notification Testing System - Frontend & Android Integration Guide

## Overview

This document provides integration documentation for frontend and Android developers working with the PiStat notification testing system. The system allows testing of Firebase notifications, database notifications, and WebSocket broadcasting functionality through dedicated API endpoints.

## Table of Contents

1. [System Overview](#system-overview)
2. [API Endpoints](#api-endpoints)
3. [WebSocket Integration](#websocket-integration)
4. [Firebase Integration](#firebase-integration)
5. [Frontend Implementation](#frontend-implementation)
6. [Android Implementation](#android-implementation)
7. [Usage Examples](#usage-examples)
8. [Error Handling](#error-handling)
9. [Testing Guide](#testing-guide)
10. [Troubleshooting](#troubleshooting)

## System Overview

The notification testing system provides three main notification channels that frontend and Android applications can integrate with:

### Available Notification Channels

1. **Firebase Cloud Messaging (FCM)**
   - Push notifications for mobile devices
   - Real-time delivery to Android/iOS apps
   - Custom data payloads supported

2. **Database Notifications**
   - Server-stored notifications
   - Retrieved via API endpoints
   - Persistent notification history

3. **WebSocket Broadcasting**
   - Real-time WebSocket connections
   - Instant message delivery
   - Both private and public channels

### Integration Points

```
Frontend/Android App
         │
         ▼
┌─────────────────────────────────────┐
│           API Endpoints             │
│  ┌─────────────┐  ┌─────────────────┐ │
│  │ Test APIs   │  │ Notification    │ │
│  │             │  │ Management APIs │ │
│  └─────────────┘  └─────────────────┘ │
└─────────────────────────────────────┘
         │                    │
         ▼                    ▼
┌─────────────────┐  ┌─────────────────┐
│ Firebase FCM    │  │ WebSocket       │
│ Push Service    │  │ Real-time       │
└─────────────────┘  └─────────────────┘
```

## API Endpoints

### Base URL
All endpoints are prefixed with `/api/test/`

### Authentication
All endpoints require Laravel Sanctum authentication:
```
Authorization: Bearer {your-token}
```

### Endpoints

#### 1. Test All Notification Types
**POST** `/api/test/notifications`

Tests all notification systems with a single request.

**Request Body:**
```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content",
    "test_types": ["firebase", "database", "websocket_private", "websocket_public"]
}
```

**Parameters:**
- `title` (required, string, max 255): Notification title
- `content` (required, string, max 1000): Notification content
- `test_types` (optional, array): Types to test. Defaults to all if not provided.

**Response:**
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

#### 2. Test Individual Notification Types
**POST** `/api/test/notifications/{type}`

Tests a specific notification type.

**Parameters:**
- `type`: One of `firebase`, `database`, `websocket-private`, `websocket-public`

**Request Body:**
```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content"
}
```

**Response Examples:**

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

**WebSocket Tests:**
```json
{
    "status": "success",
    "message": "Private WebSocket event broadcasted successfully",
    "user_id": 1,
    "channel": "user.1"
}
```

## WebSocket Integration

### Available WebSocket Channels

The system provides two types of WebSocket channels:

1. **Private Channels** (`user.{user_id}`)
   - User-specific notifications
   - Requires authentication
   - Only accessible by the authenticated user

2. **Public Channels** (`test-channel`)
   - General notifications
   - No authentication required
   - Accessible by all connected clients

### WebSocket Connection Details

- **Protocol**: WebSocket (ws/wss)
- **Authentication**: Bearer token for private channels
- **Event Name**: `test.notification`
- **Data Format**: JSON with structured notification data

## Firebase Integration

### Firebase Cloud Messaging (FCM)

The system sends push notifications through Firebase Cloud Messaging with the following structure:

#### FCM Message Format
```json
{
    "title": "Notification Title",
    "body": "Notification Content",
    "data": {
        "type": "test",
        "timestamp": "2024-01-01T12:00:00.000000Z",
        "user_id": 1,
        "notification_id": "test-1234567890"
    }
}
```

#### FCM Data Payload
- **title**: Notification title (string)
- **body**: Notification content (string)
- **data**: Custom data object with:
  - `type`: Notification type identifier
  - `timestamp`: ISO 8601 timestamp
  - `user_id`: User identifier
  - `notification_id`: Unique notification identifier

## Database Notifications

### Notification Data Structure

Database notifications are stored with the following structure:

```json
{
    "title": "Test Notification Title",
    "content": "This is a test notification content",
    "type": "test",
    "timestamp": "2024-01-01T12:00:00.000000Z",
    "message": "This is a test notification: ..."
}
```

### Notification Management APIs

The system provides standard notification management endpoints:

- **GET** `/api/notifications` - Retrieve user notifications
- **POST** `/api/notifications/{id}/mark_as_read` - Mark notification as read
- **POST** `/api/notifications/mark_all_as_read` - Mark all notifications as read

## Frontend Implementation

### WebSocket Integration with Laravel Echo

#### Installation
```bash
npm install laravel-echo pusher-js
```

#### Configuration
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

#### Listening to WebSocket Channels

**Private Channel (User-specific notifications):**
```javascript
// Listen to user-specific notifications
Echo.private(`user.${userId}`)
    .listen('test.notification', (e) => {
        console.log('Private notification received:', e);
        showNotification(e.title, e.content);
    });
```

**Public Channel (General notifications):**
```javascript
// Listen to public notifications
Echo.channel('test-channel')
    .listen('test.notification', (e) => {
        console.log('Public notification received:', e);
        showNotification(e.title, e.content);
    });
```

#### WebSocket Event Data Structure
```javascript
{
    title: "Test Notification Title",
    content: "This is a test notification content",
    type: "private|public",
    timestamp: "2024-01-01T12:00:00.000000Z",
    user_id: 1,
    message: "This is a test WebSocket broadcast: ..."
}
```

### API Integration

#### Testing Notifications
```javascript
// Test all notification types
const testAllNotifications = async (title, content) => {
    const response = await fetch('/api/test/notifications', {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + authToken,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            title: title,
            content: content,
            test_types: ['firebase', 'database', 'websocket_private', 'websocket_public']
        })
    });
    
    return await response.json();
};

// Test specific notification type
const testNotificationType = async (type, title, content) => {
    const response = await fetch(`/api/test/notifications/${type}`, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + authToken,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            title: title,
            content: content
        })
    });
    
    return await response.json();
};
```

#### Managing Database Notifications
```javascript
// Get user notifications
const getNotifications = async () => {
    const response = await fetch('/api/notifications', {
        headers: {
            'Authorization': 'Bearer ' + authToken,
        }
    });
    
    return await response.json();
};

// Mark notification as read
const markAsRead = async (notificationId) => {
    const response = await fetch(`/api/notifications/${notificationId}/mark_as_read`, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + authToken,
        }
    });
    
    return await response.json();
};
```

## Android Implementation

### Firebase Cloud Messaging Setup

#### 1. Add Firebase to your Android project
```gradle
// app/build.gradle
implementation 'com.google.firebase:firebase-messaging:23.4.0'
implementation 'com.google.firebase:firebase-analytics:21.5.0'
```

#### 2. Create Firebase Messaging Service
```java
public class MyFirebaseMessagingService extends FirebaseMessagingService {
    
    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        super.onMessageReceived(remoteMessage);
        
        // Handle notification data
        Map<String, String> data = remoteMessage.getData();
        String title = data.get("title");
        String content = data.get("body");
        String type = data.get("type");
        String timestamp = data.get("timestamp");
        String userId = data.get("user_id");
        
        // Process the notification
        handleNotification(title, content, type, timestamp, userId);
    }
    
    private void handleNotification(String title, String content, String type, 
                                   String timestamp, String userId) {
        // Create and show notification
        createNotificationChannel();
        showNotification(title, content);
        
        // Handle custom data based on type
        if ("test".equals(type)) {
            // Handle test notification
            Log.d("FCM", "Test notification received: " + title);
        }
    }
    
    private void showNotification(String title, String content) {
        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, "test_channel")
                .setSmallIcon(R.drawable.ic_notification)
                .setContentTitle(title)
                .setContentText(content)
                .setPriority(NotificationCompat.PRIORITY_DEFAULT);
        
        NotificationManagerCompat notificationManager = NotificationManagerCompat.from(this);
        notificationManager.notify(1, builder.build());
    }
    
    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            CharSequence name = "Test Notifications";
            String description = "Channel for test notifications";
            int importance = NotificationManager.IMPORTANCE_DEFAULT;
            NotificationChannel channel = new NotificationChannel("test_channel", name, importance);
            channel.setDescription(description);
            
            NotificationManager notificationManager = getSystemService(NotificationManager.class);
            notificationManager.createNotificationChannel(channel);
        }
    }
}
```

#### 3. Register FCM Token
```java
public class MainActivity extends AppCompatActivity {
    
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        // Get FCM token
        FirebaseMessaging.getInstance().getToken()
            .addOnCompleteListener(new OnCompleteListener<String>() {
                @Override
                public void onComplete(@NonNull Task<String> task) {
                    if (!task.isSuccessful()) {
                        Log.w("FCM", "Fetching FCM registration token failed", task.getException());
                        return;
                    }
                    
                    // Get new FCM registration token
                    String token = task.getResult();
                    Log.d("FCM", "FCM Registration Token: " + token);
                    
                    // Send token to server
                    sendTokenToServer(token);
                }
            });
    }
    
    private void sendTokenToServer(String token) {
        // Send FCM token to your server
        // This should be done when user logs in or token refreshes
    }
}
```

### WebSocket Integration for Android

#### 1. Add WebSocket dependency
```gradle
// app/build.gradle
implementation 'com.squareup.okhttp3:okhttp:4.12.0'
```

#### 2. WebSocket Client Implementation
```java
public class WebSocketClient {
    private WebSocket webSocket;
    private OkHttpClient client;
    private String authToken;
    
    public WebSocketClient(String authToken) {
        this.authToken = authToken;
        this.client = new OkHttpClient();
    }
    
    public void connectToPrivateChannel(String userId) {
        String url = "ws://your-domain.com:8080/app/your-app-key?protocol=7&client=js&version=8.0.0&flash=false";
        
        Request request = new Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer " + authToken)
                .build();
        
        webSocket = client.newWebSocket(request, new WebSocketListener() {
            @Override
            public void onOpen(WebSocket webSocket, Response response) {
                Log.d("WebSocket", "Connected to private channel");
                subscribeToChannel("private-user." + userId);
            }
            
            @Override
            public void onMessage(WebSocket webSocket, String text) {
                handleMessage(text);
            }
            
            @Override
            public void onFailure(WebSocket webSocket, Throwable t, Response response) {
                Log.e("WebSocket", "Connection failed", t);
            }
        });
    }
    
    public void connectToPublicChannel() {
        String url = "ws://your-domain.com:8080/app/your-app-key?protocol=7&client=js&version=8.0.0&flash=false";
        
        Request request = new Request.Builder()
                .url(url)
                .build();
        
        webSocket = client.newWebSocket(request, new WebSocketListener() {
            @Override
            public void onOpen(WebSocket webSocket, Response response) {
                Log.d("WebSocket", "Connected to public channel");
                subscribeToChannel("test-channel");
            }
            
            @Override
            public void onMessage(WebSocket webSocket, String text) {
                handleMessage(text);
            }
            
            @Override
            public void onFailure(WebSocket webSocket, Throwable t, Response response) {
                Log.e("WebSocket", "Connection failed", t);
            }
        });
    }
    
    private void subscribeToChannel(String channel) {
        String subscribeMessage = "{\"event\":\"pusher:subscribe\",\"data\":{\"channel\":\"" + channel + "\"}}";
        webSocket.send(subscribeMessage);
    }
    
    private void handleMessage(String message) {
        try {
            JSONObject json = new JSONObject(message);
            String event = json.getString("event");
            
            if ("test.notification".equals(event)) {
                JSONObject data = json.getJSONObject("data");
                String title = data.getString("title");
                String content = data.getString("content");
                String type = data.getString("type");
                
                // Handle the notification
                showNotification(title, content);
            }
        } catch (JSONException e) {
            Log.e("WebSocket", "Error parsing message", e);
        }
    }
    
    private void showNotification(String title, String content) {
        // Show notification or update UI
        Log.d("WebSocket", "Received notification: " + title + " - " + content);
    }
    
    public void disconnect() {
        if (webSocket != null) {
            webSocket.close(1000, "Disconnecting");
        }
    }
}
```

### API Integration for Android

#### 1. Add HTTP client dependency
```gradle
// app/build.gradle
implementation 'com.squareup.retrofit2:retrofit:2.9.0'
implementation 'com.squareup.retrofit2:converter-gson:2.9.0'
implementation 'com.squareup.okhttp3:logging-interceptor:4.12.0'
```

#### 2. API Service Interface
```java
public interface NotificationApiService {
    
    @POST("test/notifications")
    Call<TestResponse> testAllNotifications(@Body TestRequest request);
    
    @POST("test/notifications/{type}")
    Call<TestResponse> testNotificationType(@Path("type") String type, @Body TestRequest request);
    
    @GET("notifications")
    Call<List<Notification>> getNotifications();
    
    @POST("notifications/{id}/mark_as_read")
    Call<ResponseBody> markAsRead(@Path("id") String id);
    
    @POST("notifications/mark_all_as_read")
    Call<ResponseBody> markAllAsRead();
}
```

#### 3. Data Models
```java
public class TestRequest {
    private String title;
    private String content;
    private List<String> testTypes;
    
    // Constructors, getters, setters
}

public class TestResponse {
    private String message;
    private int userId;
    private TestData testData;
    private Map<String, TestResult> results;
    
    // Constructors, getters, setters
}

public class TestData {
    private String title;
    private String content;
    
    // Constructors, getters, setters
}

public class TestResult {
    private String status;
    private String message;
    
    // Constructors, getters, setters
}
```

#### 4. Usage Example
```java
public class NotificationManager {
    private NotificationApiService apiService;
    private String authToken;
    
    public NotificationManager(String authToken) {
        this.authToken = authToken;
        setupApiService();
    }
    
    private void setupApiService() {
        OkHttpClient client = new OkHttpClient.Builder()
                .addInterceptor(new AuthInterceptor(authToken))
                .build();
        
        Retrofit retrofit = new Retrofit.Builder()
                .baseUrl("https://your-domain.com/api/")
                .client(client)
                .addConverterFactory(GsonConverterFactory.create())
                .build();
        
        apiService = retrofit.create(NotificationApiService.class);
    }
    
    public void testAllNotifications(String title, String content) {
        TestRequest request = new TestRequest(title, content, 
            Arrays.asList("firebase", "database", "websocket_private", "websocket_public"));
        
        apiService.testAllNotifications(request).enqueue(new Callback<TestResponse>() {
            @Override
            public void onResponse(Call<TestResponse> call, Response<TestResponse> response) {
                if (response.isSuccessful()) {
                    TestResponse result = response.body();
                    Log.d("API", "Test completed: " + result.getMessage());
                }
            }
            
            @Override
            public void onFailure(Call<TestResponse> call, Throwable t) {
                Log.e("API", "Test failed", t);
            }
        });
    }
}
```

## Usage Examples

### Frontend Testing Examples

#### 1. Test All Notification Types
```javascript
const testAll = async () => {
    const result = await testAllNotifications("System Test", "Testing all systems");
    console.log("Test results:", result.results);
};
```

#### 2. Test Specific Notification Type
```javascript
const testFirebase = async () => {
    const result = await testNotificationType("firebase", "Firebase Test", "Testing FCM");
    console.log("Firebase test:", result);
};
```

#### 3. WebSocket Testing
```javascript
const testWebSocket = () => {
    // Listen for notifications
    Echo.channel('test-channel')
        .listen('test.notification', (e) => {
            console.log('Notification received:', e);
        });
    
    // Send test notification
    testNotificationType("websocket-public", "WebSocket Test", "Testing WebSocket");
};
```

### Android Testing Examples

#### 1. Test Notifications
```java
NotificationManager notificationManager = new NotificationManager(authToken);
notificationManager.testAllNotifications("Android Test", "Testing from Android app");
```

#### 2. WebSocket Connection
```java
WebSocketClient wsClient = new WebSocketClient(authToken);
wsClient.connectToPublicChannel(); // or connectToPrivateChannel(userId)
```

## Error Handling

### Common Error Responses

#### Firebase Errors
```json
{
    "status": "error",
    "message": "Firebase notification failed: Invalid FCM token"
}
```

#### Database Errors
```json
{
    "status": "error",
    "message": "Database notification failed: Connection timeout"
}
```

#### WebSocket Errors
```json
{
    "status": "error",
    "message": "Private WebSocket broadcast failed: Channel authorization failed"
}
```

## Testing Guide

### Frontend Testing Checklist

Before testing notifications, verify:

1. **Authentication**: Ensure user has valid authentication token
2. **Firebase**: Verify FCM token is registered for the user
3. **WebSocket**: Ensure WebSocket server is accessible
4. **API Access**: Confirm API endpoints are reachable

### Step-by-Step Testing

#### Step 1: Test Database Notifications
```javascript
const testDatabase = async () => {
    const result = await testNotificationType("database", "DB Test", "Testing database notifications");
    console.log("Database test result:", result);
};
```

#### Step 2: Test Firebase Notifications
```javascript
const testFirebase = async () => {
    const result = await testNotificationType("firebase", "FCM Test", "Testing Firebase notifications");
    console.log("Firebase test result:", result);
};
```

#### Step 3: Test WebSocket Broadcasting
```javascript
const testWebSocket = async () => {
    // Set up WebSocket listener first
    Echo.channel('test-channel')
        .listen('test.notification', (e) => {
            console.log('✅ WebSocket notification received:', e);
        });
    
    // Send test notification
    const result = await testNotificationType("websocket-public", "WS Test", "Testing WebSocket broadcasting");
    console.log("WebSocket test result:", result);
};
```

#### Step 4: Test All Types Together
```javascript
const testAll = async () => {
    const result = await testAllNotifications("Full Test", "Testing all notification types");
    console.log("All tests result:", result);
};
```

### Android Testing Checklist

1. **FCM Setup**: Verify Firebase project is configured
2. **Token Registration**: Ensure FCM token is sent to server
3. **WebSocket**: Confirm WebSocket connection can be established
4. **API Integration**: Test API calls work correctly

### Android Testing Steps

#### Step 1: Test Firebase Notifications
```java
// Ensure FCM token is registered
FirebaseMessaging.getInstance().getToken()
    .addOnCompleteListener(task -> {
        if (task.isSuccessful()) {
            String token = task.getResult();
            Log.d("FCM", "Token: " + token);
            // Send token to server
        }
    });

// Test notification
NotificationManager notificationManager = new NotificationManager(authToken);
notificationManager.testAllNotifications("Android Test", "Testing from Android");
```

#### Step 2: Test WebSocket Connection
```java
WebSocketClient wsClient = new WebSocketClient(authToken);
wsClient.connectToPublicChannel();
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Firebase Notifications Not Working

**Symptoms:**
- Firebase test returns error
- No push notifications received on device

**Solutions:**
- Verify FCM token is correctly registered
- Check Firebase project configuration
- Ensure app has notification permissions
- Verify Firebase service is properly initialized

#### 2. WebSocket Connection Issues

**Symptoms:**
- WebSocket tests fail
- Frontend/Android can't connect to WebSocket server

**Solutions:**
- Check WebSocket server URL and port
- Verify authentication token is valid
- Check network connectivity
- Ensure WebSocket server is running

#### 3. API Authentication Issues

**Symptoms:**
- All API calls return 401 Unauthorized
- Token validation fails

**Solutions:**
- Verify authentication token is valid and not expired
- Check token format (Bearer token)
- Ensure user is properly authenticated
- Verify API endpoint URLs

#### 4. Database Notifications Not Retrieved

**Symptoms:**
- Database test succeeds but notifications not visible
- Notification count doesn't update

**Solutions:**
- Check API endpoint for retrieving notifications
- Verify authentication for notification endpoints
- Ensure proper error handling in API calls
- Check notification data format

### Frontend Debugging

```javascript
// Test WebSocket connection
const testWebSocketConnection = () => {
    Echo.channel('test-channel')
        .listen('test.notification', (e) => {
            console.log('✅ WebSocket working:', e);
        })
        .error((error) => {
            console.error('❌ WebSocket error:', error);
        });
};

// Test private channel
const testPrivateChannel = (userId) => {
    Echo.private(`user.${userId}`)
        .listen('test.notification', (e) => {
            console.log('✅ Private channel working:', e);
        })
        .error((error) => {
            console.error('❌ Private channel error:', error);
        });
};

// Debug API calls
const debugApiCall = async (url, options) => {
    try {
        console.log('Making API call to:', url);
        const response = await fetch(url, options);
        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);
        return data;
    } catch (error) {
        console.error('API call failed:', error);
        throw error;
    }
};
```

### Android Debugging

```java
// Debug WebSocket connection
private void debugWebSocketConnection() {
    WebSocketClient wsClient = new WebSocketClient(authToken) {
        @Override
        public void onOpen(WebSocket webSocket, Response response) {
            Log.d("WebSocket", "✅ Connection successful");
        }
        
        @Override
        public void onFailure(WebSocket webSocket, Throwable t, Response response) {
            Log.e("WebSocket", "❌ Connection failed: " + t.getMessage());
        }
    };
    
    wsClient.connectToPublicChannel();
}

// Debug API calls
private void debugApiCall(String endpoint, RequestBody body) {
    Log.d("API", "Making call to: " + endpoint);
    // Add logging interceptor to Retrofit client
}
```

## Security Considerations

### 1. Authentication
- All API endpoints require valid authentication tokens
- Private WebSocket channels verify user ownership
- Public channels are limited to testing purposes

### 2. Data Validation
- All input is validated and sanitized
- Maximum length limits on title and content
- Proper error handling prevents information leakage

### 3. Environment Separation
- Use different Firebase projects for testing vs production
- Separate WebSocket channels for different environments
- Test endpoints should be disabled in production

## Performance Considerations

### 1. WebSocket Connections
- Implement connection pooling for multiple channels
- Handle connection drops gracefully
- Monitor connection limits

### 2. API Calls
- Implement proper caching for notification data
- Use pagination for large notification lists
- Handle network timeouts appropriately

### 3. Firebase Integration
- Implement token refresh handling
- Monitor FCM delivery reports
- Handle notification permissions properly

## Conclusion

The notification testing system provides comprehensive integration capabilities for frontend and Android applications. With proper implementation of the provided examples, developers can effectively test and integrate Firebase notifications, database notifications, and WebSocket broadcasting functionality.

For additional support or questions, refer to the Firebase documentation, Laravel Echo documentation, or consult the application's existing notification implementations for reference patterns.
