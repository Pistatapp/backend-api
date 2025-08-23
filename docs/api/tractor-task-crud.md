# Tractor Task API Documentation

This document provides comprehensive documentation for the Tractor Task CRUD (Create, Read, Update, Delete) operations API endpoints.

## Base URL
```
/api
```

## Authentication
All endpoints require authentication using Laravel Sanctum. Include the Bearer token in the Authorization header:
```
Authorization: Bearer {your-token}
```

## Overview

Tractor tasks represent scheduled operations that tractors perform on various agricultural areas (fields, farms, plots, or rows). The API supports polymorphic relationships, allowing tasks to be associated with different types of agricultural areas.

### Taskable Types
- **Field** (`field`): Agricultural fields
- **Farm** (`farm`): Farms
- **Plot** (`plot`): Plots within fields
- **Row** (`row`): Rows within fields

### Task Statuses
- `pending`: Task is scheduled but not started
- `started`: Task is currently in progress
- `finished`: Task has been completed

---

## Endpoints

### 1. List Tractor Tasks

Retrieve a list of tasks for a specific tractor.

**Endpoint:** `GET /tractors/{tractor}/tractor_tasks`

**Parameters:**
- `tractor` (integer, required): The ID of the tractor
- `date` (string, optional): Filter tasks by specific date (Jalali format: YYYY/MM/DD)

**Example Request:**
```bash
curl -X GET "https://api.pistatapp.com/api/tractors/1/tractor_tasks" \
  -H "Authorization: Bearer {token}"
```

**Example Request with Date Filter:**
```bash
curl -X GET "https://api.pistatapp.com/api/tractors/1/tractor_tasks?date=1403/12/07" \
  -H "Authorization: Bearer {token}"
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "operation": {
        "id": 1,
        "name": "سم پاشی"
      },
      "taskable": {
        "id": 1,
        "name": "Field A"
      },
      "date": "1403/12/07",
      "start_time": "08:00",
      "end_time": "12:00",
      "status": "pending",
      "data": {
        "consumed_water": 100,
        "consumed_fertilizer": 50,
        "consumed_poison": 25,
        "operation_area": 5.5,
        "workers_count": 3
      },
      "created_by": {
        "id": 1,
        "name": "John Doe"
      },
      "created_at": "1403/12/07 10:30:00"
    }
  ],
  "links": {
    "first": "https://api.pistatapp.com/api/tractors/1/tractor_tasks?page=1",
    "last": "https://api.pistatapp.com/api/tractor_tasks?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

---

### 2. Create Tractor Task

Create a new task for a tractor.

**Endpoint:** `POST /tractors/{tractor}/tractor_tasks`

**Parameters:**
- `tractor` (integer, required): The ID of the tractor

**Request Body:**
```json
{
  "operation_id": 1,
  "taskable_type": "field",
  "taskable_id": 1,
  "date": "1403/12/07",
  "start_time": "08:00",
  "end_time": "12:00",
  "description": "Optional task description"
}
```

**Field Descriptions:**
- `operation_id` (integer, required): ID of the operation to be performed
- `taskable_type` (string, required): Type of agricultural area. Options: `field`, `farm`, `plot`, `row`
- `taskable_id` (integer, required): ID of the specific agricultural area
- `date` (string, required): Task date in Jalali format (YYYY/MM/DD)
- `start_time` (string, required): Start time in 24-hour format (HH:MM)
- `end_time` (string, required): End time in 24-hour format (HH:MM)
- `description` (string, optional): Additional description for the task

**Example Request:**
```bash
curl -X POST "https://api.pistatapp.com/api/tractors/1/tractor_tasks" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "operation_id": 1,
    "taskable_type": "field",
    "taskable_id": 1,
    "date": "1403/12/07",
    "start_time": "08:00",
    "end_time": "12:00",
    "description": "Spraying pesticides on Field A"
  }'
```

**Response (201 Created):**
```json
{
  "data": {
    "id": 1,
    "operation": {
      "id": 1,
      "name": "سم پاشی"
    },
    "taskable": {
      "id": 1,
      "name": "Field A"
    },
    "date": "1403/12/07",
    "start_time": "08:00",
    "end_time": "12:00",
    "status": "pending",
    "data": {
      "consumed_water": null,
      "consumed_fertilizer": null,
      "consumed_poison": null,
      "operation_area": null,
      "workers_count": null
    },
    "created_by": {
      "id": 1,
      "name": "John Doe"
    },
    "created_at": "1403/12/07 10:30:00"
  }
}
```

**Validation Errors (422 Unprocessable Entity):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "operation_id": ["The operation id field is required."],
    "taskable_type": ["The selected taskable type is invalid."],
    "taskable_id": ["The selected taskable does not exist."],
    "date": ["The date field is required."],
    "start_time": ["The start time field is required."],
    "end_time": ["The end time must be after the start time."]
  }
}
```

---

### 3. Get Single Tractor Task

Retrieve details of a specific tractor task.

**Endpoint:** `GET /tractor_tasks/{tractor_task}`

**Parameters:**
- `tractor_task` (integer, required): The ID of the tractor task

**Example Request:**
```bash
curl -X GET "https://api.pistatapp.com/api/tractor_tasks/1" \
  -H "Authorization: Bearer {token}"
```

**Response (200 OK):**
```json
{
  "data": {
    "id": 1,
    "operation": {
      "id": 1,
      "name": "سم پاشی"
    },
    "taskable": {
      "id": 1,
      "name": "Field A"
    },
    "date": "1403/12/07",
    "start_time": "08:00",
    "end_time": "12:00",
    "status": "started",
    "data": {
      "consumed_water": 100,
      "consumed_fertilizer": 50,
      "consumed_poison": 25,
      "operation_area": 5.5,
      "workers_count": 3
    },
    "created_by": {
      "id": 1,
      "name": "John Doe"
    },
    "created_at": "1403/12/07 10:30:00"
  }
}
```

**Response (404 Not Found):**
```json
{
  "message": "No query results for model [App\\Models\\TractorTask] 999"
}
```

---

### 4. Update Tractor Task

Update an existing tractor task.

**Endpoint:** `PUT /tractor_tasks/{tractor_task}`

**Parameters:**
- `tractor_task` (integer, required): The ID of the tractor task

**Request Body:**
```json
{
  "operation_id": 2,
  "taskable_type": "plot",
  "taskable_id": 3,
  "date": "1403/12/08",
  "start_time": "09:00",
  "end_time": "13:00",
  "data": {
    "consumed_water": 150,
    "consumed_fertilizer": 75,
    "consumed_poison": 30,
    "operation_area": 6.2,
    "workers_count": 4
  }
}
```

**Field Descriptions:**
- `operation_id` (integer, required): ID of the operation to be performed
- `taskable_type` (string, required): Type of agricultural area. Options: `field`, `farm`, `plot`, `row`
- `taskable_id` (integer, required): ID of the specific agricultural area
- `date` (string, required): Task date in Jalali format (YYYY/MM/DD)
- `start_time` (string, required): Start time in 24-hour format (HH:MM)
- `end_time` (string, required): End time in 24-hour format (HH:MM)
- `data` (object, optional): Task execution data
  - `consumed_water` (number, optional): Amount of water consumed
  - `consumed_fertilizer` (number, optional): Amount of fertilizer consumed
  - `consumed_poison` (number, optional): Amount of pesticide consumed
  - `operation_area` (number, optional): Area covered by the operation
  - `workers_count` (integer, optional): Number of workers involved

**Example Request:**
```bash
curl -X PUT "https://api.pistatapp.com/api/tractor_tasks/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "operation_id": 2,
    "taskable_type": "plot",
    "taskable_id": 3,
    "date": "1403/12/08",
    "start_time": "09:00",
    "end_time": "13:00",
    "data": {
      "consumed_water": 150,
      "consumed_fertilizer": 75,
      "consumed_poison": 30,
      "operation_area": 6.2,
      "workers_count": 4
    }
  }'
```

**Response (200 OK):**
```json
{
  "data": {
    "id": 1,
    "operation": {
      "id": 2,
      "name": "کوددهی"
    },
    "taskable": {
      "id": 3,
      "name": "Plot B"
    },
    "date": "1403/12/08",
    "start_time": "09:00",
    "end_time": "13:00",
    "status": "pending",
    "data": {
      "consumed_water": 150,
      "consumed_fertilizer": 75,
      "consumed_poison": 30,
      "operation_area": 6.2,
      "workers_count": 4
    },
    "created_by": {
      "id": 1,
      "name": "John Doe"
    },
    "created_at": "1403/12/07 10:30:00"
  }
}
```

---

### 5. Update Task Data

Partially update the data attributes of a tractor task.

**Endpoint:** `PATCH /tractor_tasks/{tractor_task}/data`

**Parameters:**
- `tractor_task` (integer, required): The ID of the tractor task

**Request Body:**
```json
{
  "consumed_water": 200,
  "consumed_fertilizer": 100,
  "consumed_poison": 40,
  "operation_area": 7.5,
  "workers_count": 5
}
```

**Field Descriptions:**
- `consumed_water` (number, optional): Amount of water consumed
- `consumed_fertilizer` (number, optional): Amount of fertilizer consumed
- `consumed_poison` (number, optional): Amount of pesticide consumed
- `operation_area` (number, optional): Area covered by the operation
- `workers_count` (integer, optional): Number of workers involved

**Example Request:**
```bash
curl -X PATCH "https://api.pistatapp.com/api/tractor_tasks/1/data" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "consumed_water": 200,
    "consumed_fertilizer": 100,
    "consumed_poison": 40,
    "operation_area": 7.5,
    "workers_count": 5
  }'
```

**Response (200 OK):**
```json
{
  "data": {
    "id": 1,
    "operation": {
      "id": 1,
      "name": "سم پاشی"
    },
    "taskable": {
      "id": 1,
      "name": "Field A"
    },
    "date": "1403/12/07",
    "start_time": "08:00",
    "end_time": "12:00",
    "status": "started",
    "data": {
      "consumed_water": 200,
      "consumed_fertilizer": 100,
      "consumed_poison": 40,
      "operation_area": 7.5,
      "workers_count": 5
    },
    "created_by": {
      "id": 1,
      "name": "John Doe"
    },
    "created_at": "1403/12/07 10:30:00"
  }
}
```

---

### 6. Delete Tractor Task

Delete a tractor task.

**Endpoint:** `DELETE /tractor_tasks/{tractor_task}`

**Parameters:**
- `tractor_task` (integer, required): The ID of the tractor task

**Example Request:**
```bash
curl -X DELETE "https://api.pistatapp.com/api/tractor_tasks/1" \
  -H "Authorization: Bearer {token}"
```

**Response (204 No Content):**
No response body

**Response (404 Not Found):**
```json
{
  "message": "No query results for model [App\\Models\\TractorTask] 999"
}
```

---

### 7. Filter Tractor Reports

Filter tractor reports by various criteria.

**Endpoint:** `POST /tractors/filter_reports`

**Request Body:**
```json
{
  "tractor_id": 1,
  "date": "1403/12/07",
  "period": "month",
  "month": "1404/01/01",
  "year": "1404",
  "operation": 1
}
```

**Field Descriptions:**
- `tractor_id` (integer, required): ID of the tractor
- `date` (string, optional): Specific date in Jalali format (YYYY/MM/DD)
- `period` (string, optional): Time period filter. Options: `month`, `year`, `specific_month`, `persian_year`
- `month` (string, optional): Specific month in Jalali format (YYYY/MM/DD) - required when period is `specific_month`
- `year` (string, optional): Persian year (YYYY) - required when period is `persian_year`
- `operation` (integer, optional): Filter by operation ID

**Example Request:**
```bash
curl -X POST "https://api.pistatapp.com/api/tractors/filter_reports" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "tractor_id": 1,
    "period": "month"
  }'
```

**Response (200 OK):**
```json
{
  "data": {
    "reports": [
      {
        "operation_name": "سم پاشی",
        "filed_name": "Field A",
        "traveled_distance": 5.5,
        "min_speed": 0,
        "max_speed": 25,
        "avg_speed": 12.5,
        "work_duration": 14400,
        "stoppage_duration": 1800,
        "stoppage_count": 3,
        "consumed_water": 200,
        "consumed_fertilizer": 100,
        "consumed_poison": 40,
        "operation_area": 7.5,
        "workers_count": 5
      }
    ],
    "accumulated": {
      "traveled_distance": 5.5,
      "min_speed": 0,
      "max_speed": 25,
      "avg_speed": 12.5,
      "work_duration": 14400,
      "stoppage_duration": 1800,
      "stoppage_count": 3
    },
    "expectations": {
      "expected_daily_work": 28800,
      "total_work_duration": 14400,
      "total_efficiency": 50
    }
  }
}
```

---

## Error Responses

### Common HTTP Status Codes

- **200 OK**: Request successful
- **201 Created**: Resource created successfully
- **204 No Content**: Request successful, no content to return
- **400 Bad Request**: Invalid request syntax
- **401 Unauthorized**: Authentication required
- **403 Forbidden**: Insufficient permissions
- **404 Not Found**: Resource not found
- **422 Unprocessable Entity**: Validation errors
- **500 Internal Server Error**: Server error

### Error Response Format

```json
{
  "message": "Error description",
  "errors": {
    "field_name": [
      "Error message for this field"
    ]
  }
}
```

---

## Data Types

### Taskable Types
- `field`: Agricultural fields
- `farm`: Farms
- `plot`: Plots within fields
- `row`: Rows within fields

### Time Formats
- **Date**: Jalali calendar format (YYYY/MM/DD)
- **Time**: 24-hour format (HH:MM)
- **DateTime**: Jalali format with time (YYYY/MM/DD HH:MM:SS)

### Status Values
- `pending`: Task is scheduled but not started
- `started`: Task is currently in progress
- `finished`: Task has been completed

---

## Rate Limiting

API requests are subject to rate limiting. The current limits are:
- **Authenticated users**: 60 requests per minute
- **Unauthenticated users**: 30 requests per minute

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1640995200
```

---

## Pagination

List endpoints support pagination with the following parameters:
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 15, max: 100)

Pagination metadata is included in list responses:
```json
{
  "data": [...],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 75
  }
}
```

---

## Examples

### Complete Workflow Example

1. **Create a task:**
```bash
curl -X POST "https://api.pistatapp.com/api/tractors/1/tractor_tasks" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "operation_id": 1,
    "taskable_type": "field",
    "taskable_id": 1,
    "date": "1403/12/07",
    "start_time": "08:00",
    "end_time": "12:00"
  }'
```

2. **Update task data during execution:**
```bash
curl -X PATCH "https://api.pistatapp.com/api/tractor_tasks/1/data" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "consumed_water": 150,
    "operation_area": 5.5,
    "workers_count": 3
  }'
```

3. **Get task details:**
```bash
curl -X GET "https://api.pistatapp.com/api/tractor_tasks/1" \
  -H "Authorization: Bearer {token}"
```

4. **Filter reports:**
```bash
curl -X POST "https://api.pistatapp.com/api/tractors/filter_reports" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "tractor_id": 1,
    "date": "1403/12/07"
  }'
```

---

## Support

For API support and questions, please contact:
- Email: support@example.com
- Documentation: https://docs.pistatapp.com/api
- GitHub Issues: https://github.com/example/api/issues
