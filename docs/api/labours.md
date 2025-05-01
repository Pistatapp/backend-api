# Labour API Documentation

This document outlines the available API endpoints for managing labourers within the farm management system.

## Table of Contents
- [Overview](#overview)
- [API Endpoints](#api-endpoints)
  - [List All Labours for a Farm](#list-all-labours-for-a-farm)
  - [Get a Specific Labour](#get-a-specific-labour)
  - [Create a New Labour](#create-a-new-labour)
  - [Update an Existing Labour](#update-an-existing-labour)
  - [Delete a Labour](#delete-a-labour)
- [Data Models](#data-models)

## Overview

The Labour API allows farm managers to create, read, update, and delete labour records in the farm management system. Labourers can be categorized into three types:
- Daily labourers
- Project labourers
- Permanent labourers

Each type has specific fields and validation rules that apply to them.

## API Endpoints

### List All Labours for a Farm

Retrieves a paginated list of all labourers associated with a specific farm.

**Endpoint:** `GET /api/farms/{farm_id}/labours`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Query Parameters:**
- `search` (string, optional): Filter labourers by first name or last name
- `page` (integer, optional): Page number for pagination

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "team_id": 3,
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
      "created_at": "2025-04-27T10:00:00.000000Z",
      "updated_at": "2025-04-27T10:00:00.000000Z"
    }
    // More labourers...
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
    "links": [
      {"url": null, "label": "&laquo; Previous", "active": false},
      {"url": "http://example.com/api/farms/1/labours?page=1", "label": "1", "active": true},
      {"url": null, "label": "Next &raquo;", "active": false}
    ],
    "path": "http://example.com/api/farms/1/labours",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

### Get a Specific Labour

Retrieves details of a specific labourer.

**Endpoint:** `GET /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Response:**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "team_id": 3,
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
    "created_at": "2025-04-27T10:00:00.000000Z",
    "updated_at": "2025-04-27T10:00:00.000000Z",
    "team": {
      "id": 3,
      "name": "Harvesting Team",
      // Other team details...
    }
  }
}
```

### Create a New Labour

Creates a new labourer record associated with a farm.

**Endpoint:** `POST /api/farms/{farm_id}/labours`

**URL Parameters:**
- `farm_id` (integer, required): The ID of the farm

**Request Body:**
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

**Validation Rules:**
- `team_id` (optional): Integer, must exist in teams table
- `type` (required): String, must be one of: daily_labourer, project_labourer, permanent_labourer
- `fname` (required): String, max 255 characters
- `lname` (required): String, max 255 characters
- `national_id` (required): Must be a valid Iranian national ID, unique in labours table
- `mobile` (required): Must be a valid Iranian mobile number, unique in labours table
- `position` (required): String, max 255 characters
- `work_type` (required): String, max 255 characters

**Type-Specific Fields:**
- For project_labourer:
  - `project_start_date` (required): Valid Shamsi date, before or equal to project_end_date
  - `project_end_date` (required): Valid Shamsi date, after or equal to project_start_date
  - `salary` (required): Integer

- For daily_labourer:
  - `work_days` (required): Integer between 1 and 7
  - `work_hours` (required): Integer between 1 and 24
  - `start_work_time` (required): Time format H:i (hours:minutes)
  - `end_work_time` (required): Time format H:i (hours:minutes)
  - `daily_salary` (required): Integer

- For permanent_labourer:
  - `work_days` (required): Integer between 1 and 7
  - `work_hours` (required): Integer between 1 and 24
  - `start_work_time` (required): Time format H:i (hours:minutes)
  - `end_work_time` (required): Time format H:i (hours:minutes)
  - `monthly_salary` (required): Integer

**Response:**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "team_id": 3,
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
    "created_at": "2025-04-27T10:00:00.000000Z",
    "updated_at": "2025-04-27T10:00:00.000000Z"
  }
}
```

### Update an Existing Labour

Updates an existing labourer's information.

**Endpoint:** `PUT /api/labours/{labour_id}`

**URL Parameters:**
- `labour_id` (integer, required): The ID of the labourer

**Request Body:**
Same structure as the Create endpoint, with all fields being optional for updates.

**Response:**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "team_id": 3,
    "type": "permanent_labourer",
    "fname": "John",
    "lname": "Smith", // Updated last name
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
    "monthly_salary": 4500, // Updated salary
    "created_at": "2025-04-27T10:00:00.000000Z",
    "updated_at": "2025-04-27T11:00:00.000000Z"
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

| Field              | Type      | Description                                               |
|--------------------|-----------|-----------------------------------------------------------|
| id                 | integer   | Unique identifier                                         |
| farm_id            | integer   | The farm this labourer is associated with                 |
| team_id            | integer   | Optional team assignment                                  |
| type               | string    | Type of labourer (daily, project, or permanent)           |
| fname              | string    | First name                                                |
| lname              | string    | Last name                                                 |
| national_id        | string    | Iranian national ID                                       |
| mobile             | string    | Contact mobile number                                     |
| position           | string    | Job position                                              |
| project_start_date | date      | Start date for project labourers (Shamsi date)            |
| project_end_date   | date      | End date for project labourers (Shamsi date)              |
| work_type          | string    | Type of work                                              |
| work_days          | integer   | Number of working days per week for daily and permanent   |
| work_hours         | integer   | Hours of work per day for daily and permanent             |
| start_work_time    | time      | Daily start time for daily and permanent                  |
| end_work_time      | time      | Daily end time for daily and permanent                    |
| salary             | integer   | Total salary for project labourers                        |
| daily_salary       | integer   | Daily rate for daily labourers                            |
| monthly_salary     | integer   | Monthly salary for permanent labourers                    |
| created_at         | timestamp | Record creation time                                      |
| updated_at         | timestamp | Record last update time                                   |
