# Farm Report Management API

This API provides comprehensive farm report management with support for multiple reportables and hierarchical data storage.

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Endpoints](#endpoints)
  - [Create Farm Reports](#create-farm-reports)
  - [Update Farm Reports](#update-farm-reports)
  - [List Farm Reports](#list-farm-reports)
  - [Get Single Farm Report](#get-single-farm-report)
  - [Delete Farm Report](#delete-farm-report)
  - [Verify Farm Report](#verify-farm-report)
  - [Filter Farm Reports](#filter-farm-reports)
- [Data Models](#data-models)
- [Examples](#examples)
- [Error Handling](#error-handling)

## Overview

The Farm Report Management API allows users to create, update, and manage farm reports with support for:

- **Multiple Reportables**: Create/update reports for multiple entities (farm, field, plot, row, tree) in a single request
- **Hierarchical Storage**: Automatically create reports for all sub-items when `include_sub_items` is enabled
- **Flexible Filtering**: Filter reports by various criteria
- **Bulk Operations**: Efficient handling of multiple reports

## Authentication

All endpoints require authentication. Include the authentication token in the request headers:

```http
Authorization: Bearer {your-token}
```

## Endpoints

### Create Farm Reports

Create new farm reports with support for multiple reportables and hierarchical sub-items.

**Endpoint:** `POST /api/farms/{farm}/farm_reports`

**Request Body:**
```json
{
  "date": "1404/01/12",
  "operation_id": 1,
  "labour_id": 1,
  "description": "Fertilizer application",
  "value": 100.5,
  "reportables": [
    {
      "type": "field",
      "id": 1
    },
    {
      "type": "farm", 
      "id": 1
    }
  ],
  "include_sub_items": false
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `date` | string | Yes | Report date (Jalali format: YYYY/MM/DD) |
| `operation_id` | integer | Yes | ID of the operation |
| `labour_id` | integer | Yes | ID of the labour |
| `description` | string | Yes | Report description |
| `value` | number | Yes | Report value |
| `reportables` | array | Yes | Array of reportable objects |
| `reportables[].type` | string | Yes | Reportable type: `farm`, `field`, `plot`, `row`, `tree` |
| `reportables[].id` | integer | Yes | Reportable ID |
| `include_sub_items` | boolean | No | Include sub-items in hierarchical storage (default: false) |

**Response:**
```json
{
  "data": {
    "id": 1,
    "date": "2025-04-01",
    "operation": {
      "id": 1,
      "name": "Fertilizer Application"
    },
    "labour": {
      "id": 1,
      "name": "John Doe"
    },
    "description": "Fertilizer application",
    "value": 100.5,
    "reportable": {
      "id": 1,
      "type": "field",
      "name": "Field A"
    },
    "verified": false,
    "created_at": "2025-04-01T10:00:00Z"
  }
}
```

### Update Farm Reports

Update existing farm reports with support for multiple reportables and hierarchical sub-items.

**Endpoint:** `PUT /api/farm_reports/{farm_report}`

**Request Body:**
```json
{
  "date": "1404/01/12",
  "operation_id": 1,
  "labour_id": 1,
  "description": "Updated fertilizer application",
  "value": 150.0,
  "reportables": [
    {
      "type": "field",
      "id": 1
    }
  ],
  "include_sub_items": true,
  "verified": true
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `date` | string | Yes | Report date (Jalali format: YYYY/MM/DD) |
| `operation_id` | integer | Yes | ID of the operation |
| `labour_id` | integer | Yes | ID of the labour |
| `description` | string | Yes | Report description |
| `value` | number | Yes | Report value |
| `reportables` | array | Yes | Array of reportable objects |
| `reportables[].type` | string | Yes | Reportable type: `farm`, `field`, `plot`, `row`, `tree` |
| `reportables[].id` | integer | Yes | Reportable ID |
| `include_sub_items` | boolean | No | Include sub-items in hierarchical storage (default: false) |
| `verified` | boolean | No | Report verification status |

**Response:**
```json
{
  "data": {
    "id": 1,
    "date": "2025-04-01",
    "operation": {
      "id": 1,
      "name": "Fertilizer Application"
    },
    "labour": {
      "id": 1,
      "name": "John Doe"
    },
    "description": "Updated fertilizer application",
    "value": 150.0,
    "reportable": {
      "id": 1,
      "type": "field",
      "name": "Field A"
    },
    "verified": true,
    "created_at": "2025-04-01T10:00:00Z",
    "updated_at": "2025-04-01T11:00:00Z"
  }
}
```

### List Farm Reports

Get paginated list of farm reports.

**Endpoint:** `GET /api/farms/{farm}/farm_reports`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: 15) |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "date": "2025-04-01",
      "operation": {
        "id": 1,
        "name": "Fertilizer Application"
      },
      "labour": {
        "id": 1,
        "name": "John Doe"
      },
      "description": "Fertilizer application",
      "value": 100.5,
      "reportable": {
        "id": 1,
        "type": "field",
        "name": "Field A"
      },
      "verified": false,
      "created_at": "2025-04-01T10:00:00Z"
    }
  ],
  "links": {
    "first": "http://api.example.com/api/farms/1/farm_reports?page=1",
    "last": "http://api.example.com/api/farms/1/farm_reports?page=10",
    "prev": null,
    "next": "http://api.example.com/api/farms/1/farm_reports?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 10,
    "per_page": 15,
    "to": 15,
    "total": 150
  }
}
```

### Get Single Farm Report

Get a specific farm report by ID.

**Endpoint:** `GET /api/farm_reports/{farm_report}`

**Response:**
```json
{
  "data": {
    "id": 1,
    "date": "2025-04-01",
    "operation": {
      "id": 1,
      "name": "Fertilizer Application"
    },
    "labour": {
      "id": 1,
      "name": "John Doe"
    },
    "description": "Fertilizer application",
    "value": 100.5,
    "reportable": {
      "id": 1,
      "type": "field",
      "name": "Field A"
    },
    "verified": false,
    "created_at": "2025-04-01T10:00:00Z"
  }
}
```

### Delete Farm Report

Delete a specific farm report.

**Endpoint:** `DELETE /api/farm_reports/{farm_report}`

**Response:** `204 No Content`

### Verify Farm Report

Mark a farm report as verified.

**Endpoint:** `PATCH /api/farm_reports/{farm_report}/verify`

**Response:**
```json
{
  "data": {
    "id": 1,
    "date": "2025-04-01",
    "operation": {
      "id": 1,
      "name": "Fertilizer Application"
    },
    "labour": {
      "id": 1,
      "name": "John Doe"
    },
    "description": "Fertilizer application",
    "value": 100.5,
    "reportable": {
      "id": 1,
      "type": "field",
      "name": "Field A"
    },
    "verified": true,
    "created_at": "2025-04-01T10:00:00Z"
  }
}
```

### Filter Farm Reports

Filter farm reports based on multiple criteria.

**Endpoint:** `POST /api/farms/{farm}/farm_reports/filter`

**Request Body:**
```json
{
  "filters": {
    "reportable_type": "field",
    "reportable_id": [1, 2, 3],
    "operation_ids": [1, 2],
    "labour_ids": [1, 2, 3],
    "date_range": {
      "from": "1403/01/01",
      "to": "1403/12/29"
    }
  }
}
```

**Filter Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `reportable_type` | string | Filter by reportable type |
| `reportable_id` | array | Filter by specific reportable IDs |
| `operation_ids` | array | Filter by operation IDs |
| `labour_ids` | array | Filter by labour IDs |
| `date_range` | object | Filter by date range |
| `date_range.from` | string | Start date (Jalali format) |
| `date_range.to` | string | End date (Jalali format) |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "date": "2025-04-01",
      "operation": {
        "id": 1,
        "name": "Fertilizer Application"
      },
      "labour": {
        "id": 1,
        "name": "John Doe"
      },
      "description": "Fertilizer application",
      "value": 100.5,
      "reportable": {
        "id": 1,
        "type": "field",
        "name": "Field A"
      },
      "verified": false,
      "created_at": "2025-04-01T10:00:00Z"
    }
  ]
}
```

## Data Models

### Farm Report Object

```json
{
  "id": 1,
  "date": "2025-04-01",
  "operation": {
    "id": 1,
    "name": "Fertilizer Application"
  },
  "labour": {
    "id": 1,
    "name": "John Doe"
  },
  "description": "Fertilizer application",
  "value": 100.5,
  "reportable": {
    "id": 1,
    "type": "field",
    "name": "Field A"
  },
  "verified": false,
  "created_at": "2025-04-01T10:00:00Z",
  "updated_at": "2025-04-01T11:00:00Z"
}
```

### Reportable Types

| Type | Description | Hierarchy Level |
|------|-------------|-----------------|
| `farm` | Farm entity | 1 (Top level) |
| `field` | Field within farm | 2 |
| `plot` | Plot within field | 3 |
| `row` | Row within plot/field | 4 |
| `tree` | Tree within row | 5 (Leaf level) |

## Examples

### Example 1: Create Report for Multiple Fields

```bash
curl -X POST "https://api.example.com/api/farms/1/farm_reports" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "1404/01/12",
    "operation_id": 1,
    "labour_id": 1,
    "description": "Fertilizer application for multiple fields",
    "value": 200.0,
    "reportables": [
      {"type": "field", "id": 1},
      {"type": "field", "id": 2},
      {"type": "field", "id": 3}
    ]
  }'
```

### Example 2: Create Report with Hierarchical Sub-items

```bash
curl -X POST "https://api.example.com/api/farms/1/farm_reports" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "1404/01/12",
    "operation_id": 1,
    "labour_id": 1,
    "description": "Pest control for entire farm",
    "value": 500.0,
    "reportables": [
      {"type": "farm", "id": 1}
    ],
    "include_sub_items": true
  }'
```

This will create reports for:
- The farm itself
- All fields in the farm
- All plots in those fields
- All rows in those plots
- All trees in those rows

### Example 3: Update Report with Hierarchical Sub-items

```bash
curl -X PUT "https://api.example.com/api/farm_reports/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "1404/01/12",
    "operation_id": 1,
    "labour_id": 1,
    "description": "Updated pest control for entire field",
    "value": 300.0,
    "reportables": [
      {"type": "field", "id": 1}
    ],
    "include_sub_items": true,
    "verified": true
  }'
```

### Example 4: Filter Reports by Date Range

```bash
curl -X POST "https://api.example.com/api/farms/1/farm_reports/filter" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "filters": {
      "date_range": {
        "from": "1403/01/01",
        "to": "1403/12/29"
      },
      "reportable_type": "field"
    }
  }'
```

## Error Handling

### Validation Errors (422 Unprocessable Entity)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "date": ["The date field is required."],
    "reportables": ["The reportables field is required."],
    "reportables.0.type": ["The reportables.0.type field is required."]
  }
}
```

### Not Found (404 Not Found)

```json
{
  "message": "No query results for model [App\\Models\\FarmReport] 999"
}
```

### Unauthorized (401 Unauthorized)

```json
{
  "message": "Unauthenticated."
}
```

### Forbidden (403 Forbidden)

```json
{
  "message": "This action is unauthorized."
}
```

## Status Codes

| Code | Description |
|------|-------------|
| 200 | OK - Request successful |
| 201 | Created - Resource created successfully |
| 204 | No Content - Resource deleted successfully |
| 401 | Unauthorized - Authentication required |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource not found |
| 422 | Unprocessable Entity - Validation errors |
| 500 | Internal Server Error - Server error |

## Rate Limiting

API requests are rate limited. Check response headers for rate limit information:

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1640995200
```

## Pagination

List endpoints support pagination with the following query parameters:

- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 15, max: 100)

Pagination information is included in the response:

```json
{
  "data": [...],
  "links": {
    "first": "http://api.example.com/api/farms/1/farm_reports?page=1",
    "last": "http://api.example.com/api/farms/1/farm_reports?page=10",
    "prev": null,
    "next": "http://api.example.com/api/farms/1/farm_reports?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 10,
    "per_page": 15,
    "to": 15,
    "total": 150
  }
}
```
