# Irrigation API Documentation

This documentation covers the irrigation-related endpoints in the API.

## Base URL
`/api`

## Authentication
All endpoints require authentication using Laravel Sanctum. Include the authentication token in the request header:
```
Authorization: Bearer <your-token>
```

## Date and Time Formats
- All dates in requests and responses use Jalali (Persian) calendar format
- Date format: `1402/09/15` (YYYY/MM/DD)
- Time format: `18:00` (HH:mm in 24-hour format)

## Endpoints

### Filter Irrigation Reports
Retrieve filtered irrigation reports for a farm.

- **URL**: `/farms/{farm}/irrigations/reports`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Request Body**:
  ```json
  {
    "from_date": "1402/09/15",     // Optional, Jalali date format: YYYY/MM/DD
    "to_date": "1402/12/29",       // Optional, Jalali date format: YYYY/MM/DD
    "field_id": 1,                 // Optional, integer
    "labour_id": 1,                // Optional, integer
    "valve_id": 1                  // Optional, integer
  }
  ```
- **Response**:
  ```json
  {
    "data": [
      {
        "date": "1402/09/15",
        "total_duration": "02:00",
        "total_volume": 1000.50,
        "irrigation_count": 1
      }
    ]
  }
  ```

### Get Field Irrigations
Retrieve irrigations for a specific field.

- **URL**: `/fields/{field}/irrigations`
- **Method**: `GET`
- **URL Parameters**:
  - `field`: Field ID (integer)
- **Query Parameters**:
  - `date`: Filter by date (optional, Jalali format: YYYY/MM/DD)
- **Response**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "labour": {
          "id": 1,
          "name": "John Doe"
        },
        "date": "1402/09/15",
        "start_time": "18:00",
        "end_time": "20:00",
        "valves": [
          {
            "id": 1,
            "name": "Valve 1",
            "status": "open",
            "opened_at": "18:00",
            "closed_at": "20:00"
          }
        ],
        "fields": [
          {
            "id": 1,
            "name": "Field 1"
          }
        ],
        "created_by": {
          "id": 1,
          "name": "creator_username"
        },
        "note": "Regular irrigation",
        "status": "completed",
        "duration": "02:00",
        "can": {
          "delete": true,
          "update": true
        }
      }
    ]
  }
  ```

### Get Field Irrigation Report
Retrieve irrigation report for a specific field.

- **URL**: `/fields/{field}/irrigations/report`
- **Method**: `GET`
- **URL Parameters**:
  - `field`: Field ID (integer)
- **Query Parameters**:
  - `date`: Filter by date (optional, Jalali format: YYYY/MM/DD)
- **Response**:
  ```json
  {
    "data": {
      "date": "1402/09/15",
      "total_duration": "02:00",
      "total_volume": 1000.50,
      "irrigation_count": 1
    }
  }
  ```

### Farm Irrigation Resource Endpoints

#### List Farm Irrigations
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `GET`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Response**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "labour": {
          "id": 1,
          "name": "John Doe"
        },
        "date": "1402/09/15",
        "start_time": "18:00",
        "end_time": "20:00",
        "valves": [],
        "fields": [],
        "created_by": {
          "id": 1,
          "name": "creator_username"
        },
        "note": "Regular irrigation",
        "status": "completed",
        "duration": "02:00",
        "can": {
          "delete": true,
          "update": true
        }
      }
    ],
    "links": {
      "first": "...",
      "last": "...",
      "prev": null,
      "next": "..."
    },
    "meta": {
      "current_page": 1,
      "per_page": 10
    }
  }
  ```

#### Create Farm Irrigation
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "date": "1402/09/15",         // Required, Jalali date format: YYYY/MM/DD
    "start_time": "18:00",        // Required, time format: HH:mm
    "end_time": "20:00",          // Required, time format: HH:mm
    "fields": [1, 2],             // Required, array of field IDs
    "valves": [1, 2],             // Required, array of valve IDs
    "note": "Regular irrigation"   // Optional, string
  }
  ```
- **Response**: Returns the created irrigation object with HTTP 201

#### Show Single Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `GET`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: Returns the irrigation object with related labour, valves, creator, and fields

#### Update Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `PUT/PATCH`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "date": "1402/09/15",         // Required, Jalali date format: YYYY/MM/DD
    "start_time": "18:00",        // Required, time format: HH:mm
    "end_time": "20:00",          // Required, time format: HH:mm
    "fields": [1, 2],             // Required, array of field IDs
    "valves": [1, 2],             // Required, array of valve IDs
    "note": "Updated notes"       // Optional, string
  }
  ```
- **Response**: Returns the updated irrigation object

#### Delete Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `DELETE`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: HTTP 204 No Content

## Error Responses
All endpoints may return the following error responses:

```json
{
  "message": "Validation error",
  "errors": {
    "field_name": [
      "Error message"
    ]
  },
  "status": 422
}
```

```json
{
  "message": "Unauthorized",
  "status": 401
}
```

```json
{
  "message": "Resource not found",
  "status": 404
}
```

## Notes
- All dates follow Jalali (Persian) calendar format (YYYY/MM/DD)
- Times are in 24-hour format (HH:mm)
- The API uses Laravel's API resource conventions for consistent request/response handling
- Responses include permission checks through the "can" attribute
- List endpoints use pagination with 10 items per page by default
- All relationships (labour, valves, fields) are properly loaded when viewing single irrigation records
