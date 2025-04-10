# Phonology Guide Files API

This document outlines the API endpoints for managing phonology guide files in the system.

## Base URL

```
/api/phonology_guide_files
```

## Authentication

All endpoints require authentication using a valid API token. The token should be included in the `Authorization` header as a Bearer token.

```
Authorization: Bearer <your-token>
```

## Endpoints

### List Phonology Guide Files

Retrieves a list of phonology guide files for a specific model.

```http
GET /api/phonology_guide_files
```

#### Query Parameters

| Parameter   | Type   | Required | Description                                    |
|------------|--------|----------|------------------------------------------------|
| model_type | string | Yes      | The type of model (e.g., 'pest')              |
| model_id   | mixed  | Yes      | The ID of the model                           |

#### Response

```json
{
    "data": [
        {
            "id": 1,
            "name": "Guide Name",
            "created_by": 1,
            "file": "file_url",
            "created_at": "2024-04-10T12:00:00Z"
        }
    ]
}
```

### Upload Phonology Guide File

Upload a new phonology guide file for a specific model.

```http
POST /api/phonology_guide_files
```

#### Authorization

Requires `root` role.

#### Request Body

| Parameter   | Type   | Required | Description                                    |
|------------|--------|----------|------------------------------------------------|
| name       | string | Yes      | Name of the guide file                        |
| file       | file   | Yes      | The guide file (max: 10MB)                    |
| model_type | string | Yes      | The type of model (e.g., 'pest')              |
| model_id   | mixed  | Yes      | The ID of the model                           |

#### Response

```json
{
    "data": {
        "id": 1,
        "name": "Guide Name",
        "created_by": 1,
        "file": "file_url",
        "created_at": "2024-04-10T12:00:00Z"
    }
}
```

### Delete Phonology Guide File

Delete a specific phonology guide file.

```http
DELETE /api/phonology_guide_files/{id}
```

#### Authorization

Requires `root` role and must be the creator of the file.

#### Query Parameters

| Parameter   | Type   | Required | Description                                    |
|------------|--------|----------|------------------------------------------------|
| model_type | string | Yes      | The type of model (e.g., 'pest')              |
| model_id   | mixed  | Yes      | The ID of the model                           |

#### Response

```http
204 No Content
```

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
    "message": "Resource not found."
}
```

### 422 Unprocessable Entity
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "field": [
            "Error message"
        ]
    }
}
```
