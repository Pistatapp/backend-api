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
- [Labour Types](#labour-types)
- [Validation Rules](#validation-rules)
- [Error Responses](#error-responses)
- [Examples](#examples)

## Overview

The Labour API allows farm managers to create, read, update, and delete labour records in the farm management system. Labourers can be categorized into three types:
- **Daily labourers** (`daily_labourer`): Workers paid on a daily basis
- **Project labourers** (`project_labourer`): Workers hired for specific projects with defined start and end dates
- **Permanent labourers** (`permanent_labourer`): Full-time employees with monthly salaries

Each type has specific fields and validation rules that apply to them.

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
- `search` (string, optional): Filter labourers by first name or last name
- `page` (integer, optional): Page number for pagination (default: 1)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "team": {
        "id": 3,
        "name": "Harvesting Team",
        "farm_id": 1,
        "supervisor": {
          "id": 2,
          "fname": "Ahmad",
          "lname": "Rezaei"
        },
        "created_at": "1402/08/15 10:30:00"
      },
      "type": "permanent_labourer",
      "fname": "John",
      "lname": "Doe",
      "national_id": "5380108717",
      "mobile": "09123456789",
      "position": "Worker",
      "project_start_date": null,
      "project_end_date": null,
      "work_type": "Full-time",
      "work_days": 5,
      "work_hours": 8,
      "start_work_time": "08:00",
      "end_work_time": "16:00",
      "salary": null,
      "daily_salary": null,
      "monthly_salary": 4000,
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
    "team": {
      "id": 3,
      "name": "Harvesting Team",
      "farm_id": 1,
      "supervisor": {
        "id": 2,
        "fname": "Ahmad",
        "lname": "Rezaei"
      },
      "created_at": "1402/08/15 10:30:00"
    },
    "type": "permanent_labourer",
    "fname": "John",
    "lname": "Doe",
    "national_id": "5380108717",
    "mobile": "09123456789",
    "position": "Worker",
    "project_start_date": null,
    "project_end_date": null,
    "work_type": "Full-time",
    "work_days": 5,
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "salary": null,
    "daily_salary": null,
    "monthly_salary": 4000,
    "created_at": "1402/08/15 10:00:00"
  }
}
```

### Create a New Labour

Creates a new labourer record associated with a farm.

**Endpoint:** `POST /api/farms/{farm_id}/labours`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Request Body:**

#### For Permanent Labourer:
```json
{
  "team_id": 3,
  "type": "permanent_labourer",
  "fname": "John",
  "lname": "Doe",
  "national_id": "5380108717",
  "mobile": "09123456789",
  "position": "Worker",
  "work_type": "Full-time",
  "work_days": 5,
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "monthly_salary": 4000
}
```

#### For Daily Labourer:
```json
{
  "team_id": 3,
  "type": "daily_labourer",
  "fname": "Ali",
  "lname": "Ahmadi",
  "national_id": "1234567890",
  "mobile": "09987654321",
  "position": "Field Worker",
  "work_type": "Seasonal",
  "work_days": 6,
  "work_hours": 10,
  "start_work_time": "06:00",
  "end_work_time": "16:00",
  "daily_salary": 300
}
```

#### For Project Labourer:
```json
{
  "team_id": 3,
  "type": "project_labourer",
  "fname": "Hassan",
  "lname": "Karimi",
  "national_id": "9876543210",
  "mobile": "09111222333",
  "position": "Project Manager",
  "work_type": "Management",
  "project_start_date": "1402/09/01",
  "project_end_date": "1402/12/30",
  "salary": 15000
}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "team": null,
    "type": "permanent_labourer",
    "fname": "John",
    "lname": "Doe",
    "national_id": "5380108717",
    "mobile": "09123456789",
    "position": "Worker",
    "project_start_date": null,
    "project_end_date": null,
    "work_type": "Full-time",
    "work_days": 5,
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "salary": null,
    "daily_salary": null,
    "monthly_salary": 4000,
    "created_at": "1402/08/15 10:00:00"
  }
}
```

### Update an Existing Labour

Updates an existing labourer's information.

**Endpoint:** `PUT /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Request Body:**
Same structure as the Create endpoint. All fields are required, but you can send the current values for fields you don't want to change.

**Response:**
```json
{
  "data": {
    "id": 1,
    "team": {
      "id": 3,
      "name": "Harvesting Team",
      "farm_id": 1,
      "supervisor": {
        "id": 2,
        "fname": "Ahmad",
        "lname": "Rezaei"
      },
      "created_at": "1402/08/15 10:30:00"
    },
    "type": "permanent_labourer",
    "fname": "John",
    "lname": "Smith",
    "national_id": "5380108717",
    "mobile": "09123456789",
    "position": "Senior Worker",
    "project_start_date": null,
    "project_end_date": null,
    "work_type": "Full-time",
    "work_days": 5,
    "work_hours": 8,
    "start_work_time": "08:00",
    "end_work_time": "16:00",
    "salary": null,
    "daily_salary": null,
    "monthly_salary": 4500,
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

| Field              | Type      | Description                                               | Required |
|--------------------|-----------|-----------------------------------------------------------|----------|
| id                 | integer   | Unique identifier                                         | Auto     |
| team               | object    | Team information (when loaded)                            | No       |
| type               | string    | Type of labourer (daily_labourer, project_labourer, permanent_labourer) | Yes |
| fname              | string    | First name                                                | Yes      |
| lname              | string    | Last name                                                 | Yes      |
| national_id        | string    | Iranian national ID (unique)                              | Yes      |
| mobile             | string    | Iranian mobile number (unique)                            | Yes      |
| position           | string    | Job position                                              | Yes      |
| project_start_date | date      | Start date for project labourers (Shamsi date format)    | Conditional |
| project_end_date   | date      | End date for project labourers (Shamsi date format)      | Conditional |
| work_type          | string    | Type of work                                              | Yes      |
| work_days          | integer   | Number of working days per week (1-7)                    | Conditional |
| work_hours         | integer   | Hours of work per day (1-24)                             | Conditional |
| start_work_time    | time      | Daily start time (HH:mm format)                          | Conditional |
| end_work_time      | time      | Daily end time (HH:mm format)                            | Conditional |
| salary             | integer   | Total salary for project labourers                       | Conditional |
| daily_salary       | integer   | Daily rate for daily labourers                           | Conditional |
| monthly_salary     | integer   | Monthly salary for permanent labourers                   | Conditional |
| is_working         | boolean   | Whether the labourer is currently working                 | Auto     |
| created_at         | timestamp | Record creation time (Shamsi format)                     | Auto     |

### Team Model (when loaded)

| Field      | Type      | Description                    |
|------------|-----------|--------------------------------|
| id         | integer   | Unique identifier              |
| name       | string    | Team name                      |
| farm_id    | integer   | Associated farm ID             |
| supervisor | object    | Supervisor information         |
| created_at | timestamp | Team creation time             |

## Labour Types

### 1. Daily Labourer (`daily_labourer`)
Workers paid on a daily basis.

**Required Fields:**
- `work_days` (1-7)
- `work_hours` (1-24)
- `start_work_time` (HH:mm)
- `end_work_time` (HH:mm)
- `daily_salary` (integer)

**Prohibited Fields:**
- `project_start_date`
- `project_end_date`
- `salary`
- `monthly_salary`

### 2. Project Labourer (`project_labourer`)
Workers hired for specific projects with defined start and end dates.

**Required Fields:**
- `project_start_date` (Shamsi date: YYYY/MM/DD)
- `project_end_date` (Shamsi date: YYYY/MM/DD)
- `salary` (integer)

**Prohibited Fields:**
- `work_days`
- `work_hours`
- `start_work_time`
- `end_work_time`
- `daily_salary`
- `monthly_salary`

### 3. Permanent Labourer (`permanent_labourer`)
Full-time employees with monthly salaries.

**Required Fields:**
- `work_days` (1-7)
- `work_hours` (1-24)
- `start_work_time` (HH:mm)
- `end_work_time` (HH:mm)
- `monthly_salary` (integer)

**Prohibited Fields:**
- `project_start_date`
- `project_end_date`
- `salary`
- `daily_salary`

## Validation Rules

### Common Fields (All Types)
- `team_id`: Optional, must exist in teams table
- `type`: Required, must be one of: daily_labourer, project_labourer, permanent_labourer
- `fname`: Required, string, max 255 characters
- `lname`: Required, string, max 255 characters
- `national_id`: Required, must be valid Iranian national ID, unique
- `mobile`: Required, must be valid Iranian mobile number, unique
- `position`: Required, string, max 255 characters
- `work_type`: Required, string, max 255 characters

### Type-Specific Validation

#### Project Labourer
- `project_start_date`: Required, valid Shamsi date, must be before or equal to project_end_date
- `project_end_date`: Required, valid Shamsi date, must be after or equal to project_start_date
- `salary`: Required, integer

#### Daily Labourer
- `work_days`: Required, integer between 1 and 7
- `work_hours`: Required, integer between 1 and 24
- `start_work_time`: Required, time format H:i (hours:minutes)
- `end_work_time`: Required, time format H:i (hours:minutes)
- `daily_salary`: Required, integer

#### Permanent Labourer
- `work_days`: Required, integer between 1 and 7
- `work_hours`: Required, integer between 1 and 24
- `start_work_time`: Required, time format H:i (hours:minutes)
- `end_work_time`: Required, time format H:i (hours:minutes)
- `monthly_salary`: Required, integer

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "fname": [
      "The fname field is required."
    ],
    "national_id": [
      "The national id has already been taken."
    ],
    "mobile": [
      "The mobile field must be a valid Iranian mobile number."
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

### Create Daily Labourer
```bash
POST /api/farms/1/labours
Content-Type: application/json

{
  "type": "daily_labourer",
  "fname": "Ali",
  "lname": "Ahmadi",
  "national_id": "1234567890",
  "mobile": "09987654321",
  "position": "Field Worker",
  "work_type": "Seasonal",
  "work_days": 6,
  "work_hours": 10,
  "start_work_time": "06:00",
  "end_work_time": "16:00",
  "daily_salary": 300
}
```

### Update Labour
```bash
PUT /api/labours/1
Content-Type: application/json

{
  "type": "permanent_labourer",
  "fname": "John",
  "lname": "Smith",
  "national_id": "5380108717",
  "mobile": "09123456789",
  "position": "Senior Worker",
  "work_type": "Full-time",
  "work_days": 5,
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "monthly_salary": 4500
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
- National ID validation follows Iranian national ID format
- Mobile number validation follows Iranian mobile number format (starting with 09)
- When creating a labour, if `team_id` is provided, the labour will be assigned to that team
- The `jdate()` function is used to format timestamps in Shamsi calendar format 
