# Notifications API Documentation

## Overview

The Notifications API provides endpoints for managing user notifications in the PiStat agricultural management system. The system supports multiple notification types including tractor tasks, frost warnings, irrigation alerts, and nutrient diagnosis requests.

## Base URL

```
/api/notifications
```

## Authentication

All notification endpoints require authentication using Laravel Sanctum. Include the bearer token in the Authorization header:

```
Authorization: Bearer {token}
```

## Endpoints

### 1. Get User Notifications

Retrieve all unread notifications for the authenticated user.

**Endpoint:** `GET /api/notifications`

**Headers:**
- `Authorization: Bearer {token}` (required)
- `Accept: application/json`

**Response:**
```json
{
  "data": [
    {
      "id": "123e4567-e89b-12d3-a456-426614174000",
      "data": {
        "title": "وظیفه ای برای تراکتور تراکتور-1 ایجاد شد.",
        "message": "عملیات شخم زدن باید از ساعت 08:00 تا 12:00 در تاریخ 1403/01/15 اجرا شود. محل اجرای عملیات قطعه A می باشد.",
        "task_id": 123,
        "color": "info"
      },
      "created_at": "2024/01/15 08:30:00"
    }
  ]
}
```

**Response Fields:**
- `id` (string): Unique notification identifier (UUID)
- `data` (object): Notification payload containing:
  - `title` (string): Notification title
  - `message` (string): Notification message
  - Additional fields vary by notification type
- `created_at` (string): Creation timestamp in Y/m/d H:i:s format

**Status Codes:**
- `200 OK`: Successfully retrieved notifications
- `401 Unauthorized`: Authentication required

---

### 2. Mark Notification as Read

Mark a specific notification as read.

**Endpoint:** `POST /api/notifications/{id}/mark_as_read`

**Parameters:**
- `id` (string, required): The UUID of the notification to mark as read

**Headers:**
- `Authorization: Bearer {token}` (required)
- `Accept: application/json`

**Response:**
```json
{
  "message": "Notification marked as read."
}
```

**Status Codes:**
- `200 OK`: Notification successfully marked as read
- `401 Unauthorized`: Authentication required
- `404 Not Found`: Notification not found or doesn't belong to user

---

### 3. Mark All Notifications as Read

Mark all unread notifications for the authenticated user as read.

**Endpoint:** `POST /api/notifications/mark_all_as_read`

**Headers:**
- `Authorization: Bearer {token}` (required)
- `Accept: application/json`

**Response:**
```json
{
  "message": "All notifications marked as read."
}
```

**Status Codes:**
- `200 OK`: All notifications successfully marked as read
- `401 Unauthorized`: Authentication required

## Notification Types

The system supports various notification types with different data structures:

### 1. Tractor Task Created

**Type:** `App\Notifications\TractorTaskCreated`

**Data Structure:**
```json
{
  "title": "وظیفه ای برای تراکتور {tractor_name} ایجاد شد.",
  "message": "عملیات {operation_name} باید از ساعت {start_time} تا {end_time} در تاریخ {task_date} اجرا شود. محل اجرای عملیات {taskable_name} می باشد.",
  "task_id": 123,
  "color": "info"
}
```

**Delivery Channels:**
- Database (for farm admins)
- Firebase Cloud Messaging (for farm admins)
- SMS via Kavenegar (for drivers)

### 2. Frost Warning

**Type:** `App\Notifications\FrostNotification`

**Data Structure:**
```json
{
  "temperature": -2.5,
  "date": "2024-01-15",
  "days": 3,
  "message": "تا 3 روز آینده احتمال سرمازدگی در باغ شما وجود دارد. اقدامات لازم را انجام دهید."
}
```

**Delivery Channels:**
- Database
- Firebase Cloud Messaging

### 3. Radiative Frost Warning

**Type:** `App\Notifications\RadiativeFrostNotification`

**Data Structure:**
```json
{
  "average_temp": 2.1,
  "dew_point": 1.8,
  "date": "2024-01-15",
  "message": "احتمال سرمازدگی تشعشعی در تاریخ 2024-01-15 وجود دارد. اقدامات لازم را انجام دهید."
}
```

### 4. Nutrient Diagnosis Request

**Type:** `App\Notifications\NewNutrientDiagnosisRequest`

**Data Structure:**
```json
{
  "request_id": 456,
  "message": "New Nutrient Diagnosis request received",
  "user_name": "John Doe"
}
```

### 5. Irrigation Notification

**Type:** `App\Notifications\IrrigationNotification`

**Data Structure:**
```json
{
  "message": "عملیات آبیاری در تاریخ 2024-01-15 ساعت 08:00 در قطعه A شروع شد و در ساعت 12:00 به پایان رسید."
}
```

### 6. Tractor Inactivity

**Type:** `App\Notifications\TractorInactivityNotification`

**Data Structure:**
```json
{
  "message": "تراکتور با نام تراکتور-1 بیش از 2 ساعت در تاریخ 2024-01-15 متوقف بوده است. لطفا دلیل آن را بررسی کنید."
}
```

## Database Schema

The notifications are stored in the `notifications` table with the following structure:

```sql
CREATE TABLE notifications (
    id UUID PRIMARY KEY,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id BIGINT NOT NULL,
    data TEXT NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

## Error Handling

### Common Error Responses

**401 Unauthorized:**
```json
{
  "message": "Unauthenticated."
}
```

**404 Not Found:**
```json
{
  "message": "No query results for model [App\\Models\\User] {id}"
}
```

**422 Validation Error:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["The field is required."]
  }
}
```

## Rate Limiting

The API implements rate limiting to prevent abuse. Standard rate limits apply as configured in the Laravel application.

## Localization

Notification messages support Persian (Farsi) localization. The system uses Laravel's localization features with language files located in:
- `lang/fa/notifications.php`
- `lang/fa.json`

## Examples

### JavaScript/Fetch Example

```javascript
// Get notifications
const response = await fetch('/api/notifications', {
  headers: {
    'Authorization': 'Bearer ' + token,
    'Accept': 'application/json'
  }
});
const notifications = await response.json();

// Mark notification as read
await fetch(`/api/notifications/${notificationId}/mark_as_read`, {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + token,
    'Accept': 'application/json'
  }
});

// Mark all as read
await fetch('/api/notifications/mark_all_as_read', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + token,
    'Accept': 'application/json'
  }
});
```

### cURL Examples

```bash
# Get notifications
curl -X GET "https://api.example.com/api/notifications" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Mark notification as read
curl -X POST "https://api.example.com/api/notifications/{id}/mark_as_read" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Mark all as read
curl -X POST "https://api.example.com/api/notifications/mark_all_as_read" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

## Testing

The notification system includes comprehensive test coverage in `tests/Feature/Controllers/NotificationControllerTest.php`. Tests cover:

- Retrieving unread notifications
- Marking individual notifications as read
- Marking all notifications as read
- Error handling for non-existent notifications
- Authorization checks
- User isolation (users can only access their own notifications)

## Related Documentation

- [Authentication API](./auth.md)
- [Tractor Tasks API](./tractor-tasks.md)
- [Farm Management API](./farms.md)
- [User Management API](./users.md)
