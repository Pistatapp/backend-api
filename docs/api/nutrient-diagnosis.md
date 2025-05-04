# Nutrient Diagnosis API Documentation

## Overview
The Nutrient Diagnosis API provides endpoints for managing soil nutrient analysis requests in agricultural fields. It allows users to submit samples for analysis, track requests, and receive expert responses.

## Base URL
`/api/farms/{farm_id}/nutrient_diagnosis`

## Authentication
All endpoints require authentication using Laravel Sanctum. Include the authentication token in the request header:
```
Authorization: Bearer <your-token>
```

## Endpoints

### 1. List Nutrient Diagnosis Requests
```http
GET /api/farms/{farm_id}/nutrient_diagnosis
```

#### Access Control
- Regular users: Can view their own requests only
- Root users: Can view all requests for the farm

#### Response
Returns a paginated collection of requests with associated samples and user information.

#### Sample Response
```json
{
    "data": [
        {
            "id": 1,
            "user": {
                "id": 1,
                "name": "John Doe"
            },
            "farm_id": 1,
            "status": "pending",
            "response_description": null,
            "response_attachment": null,
            "samples": [
                {
                    "id": 1,
                    "field": {
                        "id": 1,
                        "name": "North Field"
                    },
                    "field_area": "1000.00",
                    "load_amount": "50.30",
                    "nitrogen": "12.50",
                    "phosphorus": "8.30",
                    "potassium": "15.70",
                    "calcium": "20.10",
                    "magnesium": "5.40",
                    "iron": "3.20",
                    "copper": "0.80",
                    "zinc": "1.20",
                    "boron": "0.50"
                }
            ],
            "created_at": "1404/01/01 12:00:00"
        }
    ]
}
```

### 2. Create Nutrient Diagnosis Request
```http
POST /api/farms/{farm_id}/nutrient_diagnosis
```

#### Access Control
Any authenticated user with access to the farm

#### Request Body
```json
{
    "samples": [
        {
            "field_id": 1,
            "field_area": 1000.00,
            "load_amount": 50.30,
            "nitrogen": 12.50,
            "phosphorus": 8.30,
            "potassium": 15.70,
            "calcium": 20.10,
            "magnesium": 5.40,
            "iron": 3.20,
            "copper": 0.80,
            "zinc": 1.20,
            "boron": 0.50
        }
    ]
}
```

#### Validation Rules
- At least one sample is required
- All nutrient values must be non-negative numbers
- Field must exist and belong to the farm
- All fields are required unless specified as optional

#### Response
Returns the created request with HTTP 201 status code.

### 3. Get Single Request
```http
GET /api/farms/{farm_id}/nutrient_diagnosis/{request_id}
```

#### Access Control
- Request creator
- Root users
- Users with access to the farm

#### Response
Returns detailed information about the specific request including samples and user information.

### 4. Send Response to Request
```http
POST /api/farms/{farm_id}/nutrient_diagnosis/{request_id}/response
```

#### Access Control
Root users only

#### Request Body
```json
{
    "description": "Analysis results and recommendations",
    "attachment": "<file>"
}
```

#### Notes
- Maximum file size: 10MB
- Changes request status to 'completed'
- Stores attachment in 'nutrient-diagnosis' directory
- Notifies the request creator automatically

### 5. Delete Request
```http
DELETE /api/farms/{farm_id}/nutrient_diagnosis/{request_id}
```

#### Access Control
- Root users: Can delete any request
- Regular users: Can only delete their own pending requests

#### Response
Returns 204 No Content on successful deletion

### 6. Export Nutrient Diagnosis Samples (Excel)

```http
GET /api/farms/{farm_id}/nutrient_diagnosis/export
```

#### Access Control
- Root users only

#### Description
Exports all compositional nutrient diagnosis samples for the specified farm as an Excel (.xlsx) file. The exported file includes all fields from the sample resource, as well as the username, mobile, and farm coordinates.

#### Response
- Returns an Excel file download (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)
- The file contains the following columns:
  - ID
  - Field ID
  - Field Name
  - Field Area
  - Load Amount
  - Nitrogen
  - Phosphorus
  - Potassium
  - Calcium
  - Magnesium
  - Iron
  - Copper
  - Zinc
  - Boron
  - Username
  - Mobile
  - Farm Coordinates
  - Farm Center

#### Sample Response Headers
```
HTTP/1.1 200 OK
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="nutrient_samples_{farm_id}.xlsx"
```

#### Notes
- Only users with the `root` role can access this endpoint.
- The file is generated dynamically and includes all samples for the farm.

## Status Values
- `pending`: Initial state when request is created
- `completed`: State after receiving response from root user

## Notifications
The system automatically sends notifications in the following cases:
1. To root users when a new request is created
2. To the request creator when a response is provided

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
        "field": ["The error message"]
    }
}
```
