# User Preferences API

This document outlines the endpoints available for managing user preferences in the system.

## Endpoints

### Get User Preferences

Retrieves the current preferences for the authenticated user.

```http
GET /api/preferences
```

#### Response

```json
{
    "data": {
        "preferences": {
            "language": "en",
            "theme": "light",
            "notifications_enabled": true,
            "working_environment": null
        }
    }
}
```

### Update User Preferences

Updates one or more preferences for the authenticated user.

```http
PUT /api/preferences
```

#### Request Body

```json
{
    "preferences": {
        "language": "fa",        // optional
        "theme": "dark",         // optional
        "notifications_enabled": false,  // optional
        "working_environment": 1        // optional
    }
}
```

#### Validation Rules
- `language`: Must be either "en" or "fa"
- `theme`: Must be either "light" or "dark"
- `notifications_enabled`: Must be a boolean
- `working_environment`: Must be null or a valid farm ID

#### Response

```json
{
    "message": "Preferences updated successfully",
    "data": {
        "preferences": {
            "language": "fa",
            "theme": "dark",
            "notifications_enabled": false,
            "working_environment": 1
        }
    }
}
```

### Reset User Preferences

Resets all preferences to their default values.

```http
DELETE /api/preferences
```

#### Response

```json
{
    "message": "Preferences reset to default values",
    "data": {
        "preferences": {
            "language": "en",
            "theme": "light",
            "notifications_enabled": true,
            "working_environment": null
        }
    }
}
```

## Error Responses

### Authentication Error

```json
{
    "message": "Unauthenticated."
}
```

Status Code: 401

### Validation Error

```json
{
    "message": "The selected language is invalid. Available options are: en, fa",
    "errors": {
        "preferences.language": [
            "The selected language is invalid. Available options are: en, fa"
        ]
    }
}
```

Status Code: 422

## Default Values

When a new user is created or preferences are reset, the following default values are applied:

```json
{
    "language": "en",
    "theme": "light",
    "notifications_enabled": true,
    "working_environment": null
}
```
