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
| related-to | string | Yes | The section to get warnings for (e.g., "farm", "tractors", "irrigation", "pests", "crop_types") |

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

| Warning Key | Setting Message |
|-------------|-----------------|
| `tractor_stoppage` | "Warn me if a tractor stops for more than :hours hours." |
| `tractor_inactivity` | "Warn me if no data is received from a tractor for more than :days days." |
| `irrigation_start_end` | "Warn me at the start and end of irrigation." |
| `frost_warning` | "Warn me :days days before a potential frost event." |
| `radiative_frost_warning` | "Warn me about radiative frost risk." |
| `oil_spray_warning` | "Warn me if chilling requirement from :start_date to :end_date is less than :hours hours." |
| `pest_degree_day_warning` | "Warn me if degree days for :pest pest from :start_date to :end_date is less than :degree_days." |
| `crop_type_degree_day_warning` | "Warn me if degree days for :crop_type crop_type from :start_date to :end_date is less than :degree_days." |

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
