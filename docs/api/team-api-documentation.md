# Team API Documentation

This document provides comprehensive API documentation for managing teams within the farm management system.

## Table of Contents
- [Overview](#overview)
- [Authentication](#authentication)
- [Base URL](#base-url)
- [API Endpoints](#api-endpoints)
  - [List All Teams for a Farm](#list-all-teams-for-a-farm)
  - [Get a Specific Team](#get-a-specific-team)
  - [Create a New Team](#create-a-new-team)
  - [Update an Existing Team](#update-an-existing-team)
  - [Delete a Team](#delete-a-team)
- [Data Models](#data-models)
- [Validation Rules](#validation-rules)
- [Error Responses](#error-responses)
- [Examples](#examples)

## Overview

The Team API allows farm managers to create, read, update, and delete team records in the farm management system. Teams are organizational units that group labourers together under a supervisor for efficient farm operations management.

Key features:
- **Team Management**: Create and manage teams within farms
- **Labour Assignment**: Assign multiple labourers to teams
- **Supervisor Assignment**: Assign a supervisor (labour) to lead each team
- **Search Functionality**: Search teams by name
- **Labour Count**: Track the number of labourers in each team

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

### List All Teams for a Farm

Retrieves a paginated list of all teams associated with a specific farm.

**Endpoint:** `GET /api/farms/{farm_id}/teams`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Query Parameters:**
- `search` (string, optional): Filter teams by name
- `page` (integer, optional): Page number for pagination (default: 1)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Harvesting Team",
      "farm_id": 1,
      "supervisor": {
        "id": 5,
        "fname": "Ahmad",
        "lname": "Rezaei"
      },
      "labours_count": 8,
      "created_at": "1402/08/15 10:30:00"
    },
    {
      "id": 2,
      "name": "Irrigation Team",
      "farm_id": 1,
      "supervisor": {
        "id": 3,
        "fname": "Hassan",
        "lname": "Karimi"
      },
      "labours_count": 5,
      "created_at": "1402/08/10 14:20:00"
    }
  ],
  "links": {
    "first": "https://api.pistatapp.ir/api/farms/1/teams?page=1",
    "last": "https://api.pistatapp.ir/api/farms/1/teams?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://api.pistatapp.ir/api/farms/1/teams",
    "per_page": 10,
    "to": 2,
    "total": 2
  }
}
```

### Get a Specific Team

Retrieves details of a specific team with its labourers and supervisor information.

**Endpoint:** `GET /api/teams/{team_id}`

**URL Parameters:**
- `team_id` (integer, required): The ID of the team

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Harvesting Team",
    "farm_id": 1,
    "supervisor": {
      "id": 5,
      "fname": "Ahmad",
      "lname": "Rezaei"
    },
    "labours_count": 3,
    "labours": [
      {
        "id": 1,
        "team": null,
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
        "monthly_salary": 4000,
        "created_at": "1402/08/15 10:00:00"
      },
      {
        "id": 2,
        "team": null,
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
        "daily_salary": 300,
        "created_at": "1402/08/12 09:30:00"
      }
    ],
    "created_at": "1402/08/15 10:30:00"
  }
}
```

### Create a New Team

Creates a new team record associated with a farm.

**Endpoint:** `POST /api/farms/{farm_id}/teams`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Request Body:**
```json
{
  "name": "Harvesting Team",
  "supervisor_id": 5,
  "labours": [1, 2, 3, 4]
}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Harvesting Team",
    "farm_id": 1,
    "supervisor": null,
    "created_at": "1402/08/15 10:30:00"
  }
}
```

### Update an Existing Team

Updates an existing team's information.

**Endpoint:** `PUT /api/teams/{team_id}`

**URL Parameters:**
- `team_id` (integer, required): The ID of the team

**Request Body:**
```json
{
  "name": "Updated Harvesting Team",
  "supervisor_id": 7,
  "labours": [1, 2, 5, 6, 8]
}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Updated Harvesting Team",
    "farm_id": 1,
    "supervisor": {
      "id": 7,
      "fname": "Mohammad",
      "lname": "Hosseini"
    },
    "created_at": "1402/08/15 10:30:00"
  }
}
```

### Delete a Team

Removes a team from the system.

**Endpoint:** `DELETE /api/teams/{team_id}`

**URL Parameters:**
- `team_id` (integer, required): The ID of the team

**Response:**
- Status: 204 No Content

## Data Models

### Team Model

| Field         | Type      | Description                                    | Required |
|---------------|-----------|------------------------------------------------|----------|
| id            | integer   | Unique identifier                              | Auto     |
| name          | string    | Team name                                      | Yes      |
| farm_id       | integer   | Associated farm ID                             | Auto     |
| supervisor_id | integer   | ID of the labour who supervises this team     | No       |
| supervisor    | object    | Supervisor information (when loaded)           | No       |
| labours_count | integer   | Number of labourers in the team (when counted) | Auto     |
| labours       | array     | Array of labour objects (when loaded)         | No       |
| created_at    | timestamp | Record creation time (Shamsi format)          | Auto     |

### Supervisor Object (when loaded)

| Field | Type   | Description           |
|-------|--------|-----------------------|
| id    | integer| Labour ID             |
| fname | string | Supervisor first name |
| lname | string | Supervisor last name  |

### Labour Object (when loaded)

The labour objects follow the same structure as defined in the [Labour API Documentation](labour-api-documentation.md).

## Validation Rules

### Create Team
- `name`: Required, string, max 255 characters
- `supervisor_id`: Optional, integer, must exist in labours table
- `labours`: Optional, array of labour IDs
- `labours.*`: Each labour ID must be an integer and exist in labours table

### Update Team
- `name`: Required, string, max 255 characters
- `supervisor_id`: Optional, integer, must exist in labours table
- `labours`: Optional, array of labour IDs
- `labours.*`: Each labour ID must be an integer and exist in labours table

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ],
    "supervisor_id": [
      "The selected supervisor id is invalid."
    ],
    "labours.0": [
      "The selected labours.0 is invalid."
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
  "message": "No query results for model [App\\Models\\Team] {id}"
}
```

### Forbidden (403)
```json
{
  "message": "This action is unauthorized."
}
```

## Examples

### Search Teams
```bash
GET /api/farms/1/teams?search=Harvest
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Harvesting Team",
      "farm_id": 1,
      "supervisor": {
        "id": 5,
        "fname": "Ahmad",
        "lname": "Rezaei"
      },
      "labours_count": 8,
      "created_at": "1402/08/15 10:30:00"
    }
  ]
}
```

### Create Team with Supervisor and Labours
```bash
POST /api/farms/1/teams
Content-Type: application/json

{
  "name": "Irrigation Team",
  "supervisor_id": 3,
  "labours": [1, 2, 4, 7]
}
```

### Create Team without Supervisor
```bash
POST /api/farms/1/teams
Content-Type: application/json

{
  "name": "Maintenance Team",
  "labours": [5, 6]
}
```

### Update Team - Change Supervisor and Add Labours
```bash
PUT /api/teams/1
Content-Type: application/json

{
  "name": "Advanced Harvesting Team",
  "supervisor_id": 8,
  "labours": [1, 2, 3, 9, 10]
}
```

### Update Team - Remove Supervisor
```bash
PUT /api/teams/1
Content-Type: application/json

{
  "name": "Self-Managed Team",
  "supervisor_id": null,
  "labours": [1, 2, 3]
}
```

### Delete Team
```bash
DELETE /api/teams/1
```

## Relationships and Business Logic

### Team-Labour Relationship
- Teams have a many-to-many relationship with labourers
- When creating or updating a team, you can assign multiple labourers using the `labours` array
- The system uses a pivot table (`labour_team`) to manage this relationship
- Labourers can belong to multiple teams

### Supervisor Assignment
- A supervisor is a labour who leads the team
- The `supervisor_id` field references a labour ID
- Supervisors are optional - teams can exist without supervisors
- A labour can be both a team member and a supervisor of different teams

### Farm Association
- Teams belong to a specific farm
- When creating a team, it's automatically associated with the farm specified in the URL
- Teams cannot be moved between farms after creation

### Cascade Deletion
- When a farm is deleted, all its teams are automatically deleted
- When a team is deleted, the labour-team relationships are automatically removed
- Deleting a labour that is a supervisor will set the team's `supervisor_id` to null

## Pagination

The list endpoint uses simple pagination with the following default settings:
- **Per Page**: 10 teams per page
- **Search Mode**: When using the `search` parameter, pagination is disabled and all matching results are returned

## Notes

- All dates follow Jalali (Persian) calendar format (YYYY/MM/DD HH:mm:ss)
- The API uses Laravel's API resource conventions for consistent request/response handling
- Team names should be descriptive and unique within a farm for better organization
- The `labours_count` field is automatically calculated when teams are loaded with count
- Labour details are only loaded when explicitly requesting a single team
- The `jdate()` function is used to format timestamps in Shamsi calendar format
- When updating teams, the `labours` array completely replaces the existing labour assignments
- Supervisor information is loaded automatically in list views but labour details are only loaded in single team views 
