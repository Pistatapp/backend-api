# Labour API Documentation

This document provides comprehensive API documentation for managing labourers within the farm management system.

## Table of Contents
- [Overview](#overview)
- [Authentication](#authentication)
- [Base URL](#base-url)
- [API Endpoints](#api-endpoints)
  - [List All Labours for a Farm](#list-all-labours-for-a-farm)
  - [Get a Specific Labour](#get-a-specific-labour)
  - [Create a New Labour](#create-a-new-labour)
  - [Update an Existing Labour](#update-an-existing-labour)
  - [Delete a Labour](#delete-a-labour)
- [Data Models](#data-models)
- [Work Types](#work-types)
- [Validation Rules](#validation-rules)
- [User Account Creation](#user-account-creation)
- [Error Responses](#error-responses)
- [Examples](#examples)

## Overview

The Labour API allows farm managers to create, read, update, and delete labour records in the farm management system. Labourers are categorized into two work types:
- **Administrative** (`administrative`): Full-time employees with fixed schedules and work hours
- **Shift-based** (`shift_based`): Workers who work in shifts with hourly wages

When a labour record is created, a user account is automatically created for them with the appropriate role assigned based on their work type.

## Authentication

All API endpoints require authentication. Include the authentication token in the request headers:

```
Authorization: Bearer {your-token}
```

## Base URL

```
https://api.pistatapp.ir/api
```

## API Endpoints

### List All Labours for a Farm

Retrieves a paginated list of all labourers associated with a specific farm.

**Endpoint:** `GET /api/farms/{farm_id}/labours`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Query Parameters:**
- `search` (string, optional): Filter labourers by name
- `page` (integer, optional): Page number for pagination (default: 1)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "teams": [
        {
          "id": 3,
          "name": "Harvesting Team",
          "farm_id": 1,
          "supervisor": {
            "id": 2,
            "name": "Ahmad Rezaei"
          },
          "created_at": "1402/08/15 10:30:00"
        }
      ],
      "name": "John Doe",
      "personnel_number": "EMP001",
      "mobile": "09123456789",
      "work_type": "administrative",
      "work_days": [1, 2, 3, 4, 5],
      "work_hours": 8,
      "start_work_time": "08:00",
      "end_work_time": "16:00",
      "hourly_wage": 50000,
      "overtime_hourly_wage": 75000,
      "image": "http://example.com/storage/labours/abc123.jpg",
      "is_working": false,
      "created_at": "1402/08/15 10:00:00"
    }
  ],
  "links": {
    "first": "http://example.com/api/farms/1/labours?page=1",
    "last": "http://example.com/api/farms/1/labours?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://example.com/api/farms/1/labours",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

### Get a Specific Labour

Retrieves details of a specific labourer with their team information.

**Endpoint:** `GET /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Response:**
```json
{
  "data": {
    "id": 1,
    "teams": [
      {
        "id": 3,
        "name": "Harvesting Team",
        "farm_id": 1,
        "supervisor": {
          "id": 2,
          "name": "Ahmad Rezaei"
        },
        "created_at": "1402/08/15 10:30:00"
      }
    ],
    "name": "John Doe",
    "personnel_number": "EMP001",
    "mobile": "09123456789",
    "work_type": "administrative",
    "work_days": [1, 2, 3, 4, 5],
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "hourly_wage": 50000,
    "overtime_hourly_wage": 75000,
    "image": "http://example.com/storage/labours/abc123.jpg",
    "is_working": false,
    "created_at": "1402/08/15 10:00:00"
  }
}
```

### Create a New Labour

Creates a new labourer record associated with a farm. A user account will be automatically created for the labour based on their mobile number.

**Endpoint:** `POST /api/farms/{farm_id}/labours`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Request Body:**

#### For Administrative Labour:
```json
{
  "team_id": 3,
  "name": "John Doe",
  "personnel_number": "EMP001",
  "mobile": "09123456789",
  "work_type": "administrative",
  "work_days": [1, 2, 3, 4, 5],
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "hourly_wage": 50000,
  "overtime_hourly_wage": 75000,
  "image": null
}
```

#### For Shift-based Labour:
```json
{
  "team_id": 3,
  "name": "Ali Ahmadi",
  "personnel_number": null,
  "mobile": "09987654321",
  "work_type": "shift_based",
  "hourly_wage": 40000,
  "overtime_hourly_wage": 60000,
  "image": null
}
```

**Note:** The `image` field should be sent as a multipart/form-data file upload, not in JSON format.

**Response:**
```json
{
  "data": {
    "id": 1,
    "teams": [
      {
        "id": 3,
        "name": "Harvesting Team",
        "farm_id": 1,
        "created_at": "1402/08/15 10:30:00"
      }
    ],
    "name": "John Doe",
    "personnel_number": "EMP001",
    "mobile": "09123456789",
    "work_type": "administrative",
    "work_days": [1, 2, 3, 4, 5],
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "hourly_wage": 50000,
    "overtime_hourly_wage": 75000,
    "image": "http://example.com/storage/labours/abc123.jpg",
    "is_working": false,
    "created_at": "1402/08/15 10:00:00"
  }
}
```

### Update an Existing Labour

Updates an existing labourer's information. If the work_type changes, the user's role will be automatically updated.

**Endpoint:** `PUT /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Request Body:**
Same structure as the Create endpoint. All required fields must be included, but you can send the current values for fields you don't want to change.

**Note:** The `image` field should be sent as a multipart/form-data file upload if you want to update it.

**Response:**
```json
{
  "data": {
    "id": 1,
    "teams": [
      {
        "id": 3,
        "name": "Harvesting Team",
        "farm_id": 1,
        "created_at": "1402/08/15 10:30:00"
      }
    ],
    "name": "John Smith",
    "personnel_number": "EMP001",
    "mobile": "09123456789",
    "work_type": "administrative",
    "work_days": [1, 2, 3, 4, 5],
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "hourly_wage": 55000,
    "overtime_hourly_wage": 80000,
    "image": "http://example.com/storage/labours/xyz789.jpg",
    "is_working": false,
    "created_at": "1402/08/15 10:00:00"
  }
}
```

### Delete a Labour

Removes a labourer from the system.

**Endpoint:** `DELETE /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Response:**
- Status: 204 No Content

## Data Models

### Labour Model

| Field                | Type      | Description                                               | Required |
|----------------------|-----------|-----------------------------------------------------------|----------|
| id                   | integer   | Unique identifier                                         | Auto     |
| teams                | array     | Array of team objects (when loaded)                       | No       |
| name                 | string    | Full name of the labourer                                 | Yes      |
| personnel_number     | string    | Personnel/employee number (unique)                        | No       |
| mobile               | string    | Iranian mobile number (unique)                            | Yes      |
| work_type            | string    | Type of work: `administrative` or `shift_based`           | Yes      |
| work_days            | array     | Array of day numbers (1=Saturday, 7=Friday) for administrative workers | Conditional |
| work_hours           | numeric   | Hours of work per day (1-24) for administrative workers   | Conditional |
| start_work_time      | time      | Daily start time (HH:mm format) for administrative workers | Conditional |
| end_work_time        | time      | Daily end time (HH:mm format) for administrative workers  | Conditional |
| hourly_wage          | integer   | Hourly wage rate (must be > 0)                           | Yes      |
| overtime_hourly_wage | integer   | Overtime hourly wage rate (must be > 0)                  | Yes      |
| image                | string    | URL to the labourer's profile image                       | No       |
| is_working           | boolean   | Whether the labourer is currently working                 | Auto     |
| created_at           | timestamp | Record creation time (Shamsi format)                     | Auto     |

### Team Model (when loaded)

| Field      | Type      | Description                    |
|------------|-----------|--------------------------------|
| id         | integer   | Unique identifier              |
| name       | string    | Team name                      |
| farm_id    | integer   | Associated farm ID             |
| supervisor | object    | Supervisor information         |
| created_at | timestamp | Team creation time             |

## Work Types

### 1. Administrative (`administrative`)
Full-time employees with fixed schedules and work hours. These workers are assigned the `employee` role in the system.

**Required Fields:**
- `work_days` (array of integers, 1-7)
- `work_hours` (numeric, 1-24)
- `start_work_time` (HH:mm format)
- `end_work_time` (HH:mm format, must be after start_work_time)
- `hourly_wage` (integer, minimum 1)
- `overtime_hourly_wage` (integer, minimum 1)

**Prohibited Fields:**
- None (all other fields can be included)

### 2. Shift-based (`shift_based`)
Workers who work in shifts with hourly wages. These workers are assigned the `labour` role in the system.

**Required Fields:**
- `hourly_wage` (integer, minimum 1)
- `overtime_hourly_wage` (integer, minimum 1)

**Prohibited Fields:**
- `work_days`
- `work_hours`
- `start_work_time`
- `end_work_time`

## Validation Rules

### Common Fields (All Work Types)
- `team_id`: Optional, integer, must exist in teams table
- `name`: Required, string, max 255 characters
- `personnel_number`: Optional, string, max 255 characters, unique
- `mobile`: Required, must be valid Iranian mobile number (ir_mobile), unique
- `work_type`: Required, must be one of: `administrative`, `shift_based`
- `hourly_wage`: Required, integer, minimum 1
- `overtime_hourly_wage`: Required, integer, minimum 1
- `image`: Optional, image file, maximum size 1MB (1024 KB)

### Work Type-Specific Validation

#### Administrative Workers
- `work_days`: Required, array of integers (1-7), where 1 = Saturday, 7 = Friday
- `work_hours`: Required, numeric, between 1 and 24
- `start_work_time`: Required, time format H:i (hours:minutes)
- `end_work_time`: Required, time format H:i (hours:minutes), must be after `start_work_time`

#### Shift-based Workers
- `work_days`: Prohibited (must not be included)
- `work_hours`: Prohibited (must not be included)
- `start_work_time`: Prohibited (must not be included)
- `end_work_time`: Prohibited (must not be included)

## User Account Creation

When a labour record is created, the system automatically:

1. **Creates a user account** using the labour's mobile number
2. **Generates a username** in the format: `labour_{mobile_number}` (country code and leading zero removed)
3. **Assigns a role** based on work_type:
   - `administrative` → `employee` role
   - `shift_based` → `labour` role
4. **Creates a user profile** with the labour's name split into first_name and last_name
5. **Links the user** to the labour record via `user_id`

If a user account with the same mobile number already exists, the system will:
- Use the existing user account
- Sync the role based on the current work_type
- Link the user to the labour record

When updating a labour's work_type, the associated user's role is automatically updated to match the new work_type.

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ],
    "mobile": [
      "The mobile has already been taken."
    ],
    "hourly_wage": [
      "The hourly wage must be at least 1."
    ],
    "work_days": [
      "The work days field is required when work type is administrative."
    ]
  }
}
```

### Unauthorized (401)
```json
{
  "message": "Unauthenticated."
}
```

### Not Found (404)
```json
{
  "message": "No query results for model [App\\Models\\Labour] {id}"
}
```

### Forbidden (403)
```json
{
  "message": "This action is unauthorized."
}
```

## Examples

### Search Labours
```bash
GET /api/farms/1/labours?search=John
```

### Create Administrative Labour
```bash
POST /api/farms/1/labours
Content-Type: multipart/form-data

{
  "team_id": 3,
  "name": "John Doe",
  "personnel_number": "EMP001",
  "mobile": "09123456789",
  "work_type": "administrative",
  "work_days": [1, 2, 3, 4, 5],
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "hourly_wage": 50000,
  "overtime_hourly_wage": 75000,
  "image": <file>
}
```

### Create Shift-based Labour
```bash
POST /api/farms/1/labours
Content-Type: multipart/form-data

{
  "team_id": 3,
  "name": "Ali Ahmadi",
  "mobile": "09987654321",
  "work_type": "shift_based",
  "hourly_wage": 40000,
  "overtime_hourly_wage": 60000
}
```

### Update Labour
```bash
PUT /api/labours/1
Content-Type: multipart/form-data

{
  "team_id": 3,
  "name": "John Smith",
  "personnel_number": "EMP001",
  "mobile": "09123456789",
  "work_type": "administrative",
  "work_days": [1, 2, 3, 4, 5],
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "hourly_wage": 55000,
  "overtime_hourly_wage": 80000,
  "image": <file>
}
```

### Delete Labour
```bash
DELETE /api/labours/1
```

## Notes

- All dates follow Jalali (Persian) calendar format (YYYY/MM/DD)
- Times are in 24-hour format (HH:mm)
- The API uses Laravel's API resource conventions for consistent request/response handling
- List endpoints use simple pagination
- Team relationships are loaded when viewing single labour records
- The `is_working` field is automatically managed by the system
- Mobile number validation follows Iranian mobile number format (starting with 09)
- When creating a labour, if `team_id` is provided, the labour will be assigned to that team
- The `jdate()` function is used to format timestamps in Shamsi calendar format
- Image uploads are stored in the `storage/app/public/labours/` directory
- User accounts are automatically created when a labour is created, using the mobile number as the identifier
- Roles are automatically assigned based on work_type: `administrative` → `employee`, `shift_based` → `labour`
- When updating work_type, the associated user's role is automatically synchronized
