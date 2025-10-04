# Attach User to Farm with Custom Role API

## Overview

This API endpoint allows attaching users to farms with custom roles and specific permissions. Users can specify exactly which permissions to assign to the custom role.

## Endpoint

```
POST /api/farms/{farm}/attach_user
```

## Authentication

Requires authentication. The authenticated user must have permission to attach users to farms.

## Parameters

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | The ID of the farm to attach the user to |

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | integer | Yes | The ID of the user to attach to the farm |
| `role` | string | Yes | Must be 'custom-role' |
| `permissions` | array | Yes | Array of permission names to assign to the user |

### Available Permissions

The system includes 70+ permissions covering various farm management activities:

**Farm Management:**
- `draw-new-farm` - Create new farms
- `view-farms` - View farm listings
- `view-farm-details` - View detailed farm information
- `edit-farm` - Edit farm properties
- `delete-farm` - Delete farms

**Field & Plot Management:**
- `draw-field-plot-row-tree` - Create fields, plots, rows, and trees
- `draw-and-view-feature-details` - Manage farm features

**Reporting & Monitoring:**
- `submit-feature-report` - Submit reports on farm features
- `view-feature-reports` - View submitted reports
- `view-farm-statistics` - Access farm statistics
- `view-my-farm-alerts` - View farm alerts

**Equipment Management:**
- `define-tractor` - Define tractors
- `view-tractors-and-details` - View tractor information
- `assign-tractor-task` - Assign tasks to tractors

**Irrigation Management:**
- `define-irrigation-program` - Create irrigation programs
- `view-irrigation-status-on-map` - Monitor irrigation status
- `view-irrigation-reports` - Access irrigation reports

**Weather & Alerts:**
- `view-24h-weather` - View 24-hour weather data
- `view-14day-weather` - View 14-day weather forecast
- `view-gardening-operation-alerts` - View operation alerts

*[See full permissions list in `/public/json/permissions.json`]*

## Request Example

```json
POST /api/farms/123/attach_user
Content-Type: application/json
Authorization: Bearer {token}

{
    "user_id": 456,
    "role": "custom-role",
    "permissions": [
        "view-farms",
        "edit-farm",
        "submit-feature-report",
        "view-irrigation-status-on-map"
    ]
}
```

## Response Examples

### Success Response

```json
{
    "message": "User attached to farm successfully."
}
```

**Status Code:** `200 OK`

### Validation Error Response

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "permissions": [
            "The permissions field is required when role is custom-role."
        ]
    }
}
```

**Status Code:** `422 Unprocessable Entity`

### Invalid Permissions Error

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "permissions.0": [
            "The selected permissions.0 is invalid."
        ]
    }
}
```

**Status Code:** `422 Unprocessable Entity`

## Error Responses

### 401 Unauthorized
```json
{
    "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
    "message": "This action is unauthorized."
}
```

### 404 Not Found
```json
{
    "message": "No query results for model [App\\Models\\Farm] {farm_id}"
}
```

## Validation Rules

### user_id
- **Required:** Yes
- **Type:** Integer
- **Description:** The ID of the user to attach to the farm

### role
- **Required:** Yes
- **Type:** String
- **Value:** Must be 'custom-role'

### permissions
- **Required:** Yes
- **Type:** Array of strings
- **Description:** List of permission names to assign to the user
- **Example:** `["view-farms", "edit-farm", "delete-farm"]`

## How It Works

When using this endpoint with `role: "custom-role"`:
1. The `permissions` field is **required**
2. All specified permissions are validated
3. The user is assigned the `custom-role` role
4. The specified permissions are assigned to the user
5. The user is attached to the farm

### Authorization
- Only users with `admin` or `super-admin` roles can attach users
- Cannot attach users with `super-admin` or `root` roles
- The requesting user must have access to the target farm

## Notes

- Custom roles provide maximum flexibility for permission management
- All permissions are validated to ensure they exist
- Users can be attached to multiple farms with different custom roles and permissions
