# Warning System API Documentation

## Overview
The warning system API provides endpoints to manage and configure various types of warnings for farm monitoring and management.

## Authentication
All endpoints require authentication using Laravel Sanctum. Include your authentication token in the request header:
```
Authorization: Bearer your-token-here
```

## Endpoints

### List Warnings

Retrieves all warnings for a specific section of the farm management system.

**GET** `/api/v1/warnings`

#### Query Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| related-to | string | Yes | The section to get warnings for (e.g., "garden", "tractors") |

#### Response
```json
{
    "data": [
        {
            "key": "warning_key",
            "setting_message": "Formatted setting message",
            "enabled": boolean,
            "parameters": {
                "param_name": "value"
            },
            "setting_message_parameters": ["available_parameters"]
        }
    ]
}
```

#### Error Responses
- `400 Bad Request` - Missing related-to parameter
- `401 Unauthorized` - Invalid or missing authentication token

### Update Warning Settings

Creates or updates settings for a specific warning.

**POST** `/api/v1/warnings`

#### Request Body
```json
{
    "key": "warning_key",
    "enabled": boolean,
    "parameters": {
        "param_name": "value"
    }
}
```

#### Response
```json
{
    "message": "Warning settings updated successfully",
    "warning": {
        "id": number,
        "farm_id": number,
        "key": "string",
        "enabled": boolean,
        "parameters": {
            "param_name": "value"
        }
    }
}
```

#### Error Responses
- `401 Unauthorized` - Invalid or missing authentication token
- `422 Unprocessable Entity` - Invalid parameters or warning key

## Available Warning Types

### Frost Warning
Used for garden frost alerts.

**Parameters:**
- `days`: Number of days before frost event for warning

**Example Request:**
```json
{
    "key": "frost_warning",
    "enabled": true,
    "parameters": {
        "days": "3"
    }
}
```

### Tractor Maintenance Warning
Used for tractor maintenance scheduling.

**Parameters:**
- `hours`: Number of operation hours before maintenance is required

**Example Request:**
```json
{
    "key": "tractor_maintenance",
    "enabled": true,
    "parameters": {
        "hours": "100"
    }
}
```

## Error Handling

All error responses follow this format:
```json
{
    "message": "Error message description",
    "errors": {
        "field_name": [
            "Validation error message"
        ]
    }
}
```

Common HTTP Status Codes:
- `200 OK` - Request successful
- `400 Bad Request` - Missing required parameters
- `401 Unauthorized` - Authentication failed
- `422 Unprocessable Entity` - Validation errors
