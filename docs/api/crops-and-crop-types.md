# Crops & Crop Types API Documentation

This document describes the API endpoints for managing crops and crop types in the farm management system. It covers the latest changes including ownership, active filtering, search, pagination, and load estimation data.

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Base URL](#base-url)
- [Roles & Permissions](#roles--permissions)
- [Crops API](#crops-api)
  - [List Crops](#list-crops)
  - [Create Crop](#create-crop)
  - [Get Crop](#get-crop)
  - [Update Crop](#update-crop)
  - [Delete Crop](#delete-crop)
- [Crop Types API](#crop-types-api)
  - [List Crop Types](#list-crop-types)
  - [Create Crop Type](#create-crop-type)
  - [Get Crop Type](#get-crop-type)
  - [Update Crop Type](#update-crop-type)
  - [Delete Crop Type](#delete-crop-type)
- [Data Models](#data-models)
- [Load Estimation Data](#load-estimation-data)
- [Related Validations](#related-validations)
- [Error Responses](#error-responses)

---

## Overview

Crops and crop types form a hierarchical structure:
- **Crop**: Top-level category (e.g., "Apple", "Grape")
- **Crop Type**: Subcategory under a crop (e.g., "Red Delicious", "Gala" under Apple)

**Key features:**
- **Root users**: Create and manage global crops/crop types (visible to all)
- **Admin users**: Create their own crops/crop types (visible to them + global items)
- **`is_active`**: Crops and crop types can be deactivated; only active ones can be selected when creating farms or fields
- **`load_estimation_data`**: Crop types support a structured JSON for load estimation metrics

---

## Authentication

All endpoints require authentication. Include the Bearer token in the request header:

```http
Authorization: Bearer {your-token}
```

---

## Base URL

```
https://api.example.com/api
```

---

## Roles & Permissions

| Role | Create | View | Update | Delete |
|------|--------|------|--------|--------|
| **Root** | Global items | Global only | Global only | Not allowed |
| **Admin** | Own items | Global + own | Own only | Not allowed |
| **Other** | — | Global + accessible | — | — |

**Note:** The `delete` operation returns `403 Forbidden` for all users. Crops and crop types cannot be deleted via the API.

---

## Crops API

### List Crops

Retrieves crops with optional filtering and pagination.

```http
GET /api/crops
```

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `active` | 0 \| 1 | No | Filter by `is_active`. `1` = active only, `0` = inactive only. Omit to return all. |
| `search` | string | No | Search crops by name (case-insensitive, partial match). When present, returns **all** matches **without pagination**. |
| `page` | integer | No | Page number (used only when `search` is not provided). |

**Behavior:**
- **Without `search`**: Returns **paginated** results.
- **With `search`**: Returns **all** matching results (no pagination).

#### Example Requests

```http
GET /api/crops
GET /api/crops?active=1
GET /api/crops?active=0
GET /api/crops?search=apple
GET /api/crops?active=1&search=grape
GET /api/crops?page=2
```

#### Response (Paginated)

```json
{
  "data": [
    {
      "id": 1,
      "name": "Apple",
      "cold_requirement": 1200,
      "is_active": true,
      "created_at": "1403/08/19 10:00:00",
      "is_global": true,
      "is_owned": false,
      "creator": null,
      "crop_types": [],
      "can": {
        "update": true,
        "delete": false
      }
    }
  ],
  "links": {
    "first": "http://example.com/api/crops?page=1",
    "last": "http://example.com/api/crops?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://example.com/api/crops",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

#### Response (Search – No Pagination)

When `search` is provided, the response is a simple `data` array without `links` or `meta`:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Apple",
      "cold_requirement": 1200,
      "is_active": true,
      "created_at": "1403/08/19 10:00:00",
      "is_global": true,
      "is_owned": false,
      "creator": null,
      "can": {
        "update": true,
        "delete": false
      }
    }
  ]
}
```

---

### Create Crop

Creates a new crop. Root creates global crops; Admin creates user-owned crops.

```http
POST /api/crops
```

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Unique name, max 255 characters |
| `cold_requirement` | integer | No | Minimum cold hours, ≥ 0 |

#### Request Example

```json
{
  "name": "Apple",
  "cold_requirement": 1200
}
```

#### Response

```json
{
  "data": {
    "id": 1,
    "name": "Apple",
    "cold_requirement": 1200,
    "is_active": true,
    "created_at": "1403/08/19 10:00:00",
    "is_global": true,
    "is_owned": true,
    "creator": {
      "id": 1,
      "username": "admin",
      "mobile": "09123456789"
    },
    "can": {
      "update": true,
      "delete": false
    }
  }
}
```

---

### Get Crop

Retrieves a single crop with its crop types.

```http
GET /api/crops/{crop}
```

#### URL Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `crop` | integer | Crop ID |

#### Response

```json
{
  "data": {
    "id": 1,
    "name": "Apple",
    "cold_requirement": 1200,
    "is_active": true,
    "created_at": "1403/08/19 10:00:00",
    "is_global": true,
    "is_owned": false,
    "creator": null,
    "crop_types": [
      {
        "id": 1,
        "name": "Red Delicious",
        "standard_day_degree": 22.5,
        "is_active": true,
        "load_estimation_data": null,
        "created_at": "1403/08/19 10:30:00",
        "is_global": true,
        "is_owned": false,
        "can": {
          "update": true,
          "delete": false
        }
      }
    ],
    "can": {
      "update": true,
      "delete": false
    }
  }
}
```

---

### Update Crop

Updates an existing crop. Only root can update global crops; only admin can update their own.

```http
PUT /api/crops/{crop}
PATCH /api/crops/{crop}
```

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Unique name, max 255 characters (excluding current crop) |
| `cold_requirement` | integer | No | Minimum cold hours, ≥ 0 |
| `is_active` | boolean | No | Whether the crop is active. Default: `true` |

#### Request Example

```json
{
  "name": "Apple (Updated)",
  "cold_requirement": 1300,
  "is_active": false
}
```

---

### Delete Crop

Deletion is not supported. The API returns `403 Forbidden` for all users.

```http
DELETE /api/crops/{crop}
```

**Response:** `403 Forbidden`

---

## Crop Types API

### List Crop Types

Retrieves crop types for a specific crop with optional filtering and pagination.

```http
GET /api/crops/{crop}/crop_types
```

#### URL Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `crop` | integer | Crop ID |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `active` | 0 \| 1 | No | Filter by `is_active`. `1` = active only, `0` = inactive only. |
| `search` | string | No | Search crop types by name. When present, returns all matches without pagination. |
| `page` | integer | No | Page number (used only when `search` is not provided). |

**Behavior:**
- **Without `search`**: Paginated results.
- **With `search`**: All matching results, no pagination.

#### Example Requests

```http
GET /api/crops/1/crop_types
GET /api/crops/1/crop_types?active=1
GET /api/crops/1/crop_types?search=delicious
```

#### Response (Paginated)

```json
{
  "data": [
    {
      "id": 1,
      "name": "Red Delicious",
      "standard_day_degree": 22.5,
      "is_active": true,
      "load_estimation_data": {
        "excellent": {
          "fruit_cluster_weight": 10.5,
          "flower_bud_to_fruit_cluster_conversion": 0.8,
          "estimated_yield_conversion_factor": 1.2
        },
        "good": { "fruit_cluster_weight": null, "flower_bud_to_fruit_cluster_conversion": null, "estimated_yield_conversion_factor": null },
        "normal": { "fruit_cluster_weight": null, "flower_bud_to_fruit_cluster_conversion": null, "estimated_yield_conversion_factor": null },
        "bad": { "fruit_cluster_weight": null, "flower_bud_to_fruit_cluster_conversion": null, "estimated_yield_conversion_factor": null }
      },
      "phonology_guide_files": [],
      "created_at": "1403/08/19 10:30:00",
      "is_global": true,
      "is_owned": false,
      "creator": null,
      "can": {
        "update": true,
        "delete": false
      }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 15, "total": 1 }
}
```

---

### Create Crop Type

Creates a new crop type under a crop.

```http
POST /api/crops/{crop}/crop_types
```

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Unique name, max 255 characters |
| `standard_day_degree` | number | No | Standard day degree (default: 22.5) |
| `load_estimation_data` | object | No | See [Load Estimation Data](#load-estimation-data) |

#### Request Example

```json
{
  "name": "Red Delicious",
  "standard_day_degree": 22.5,
  "load_estimation_data": {
    "excellent": {
      "fruit_cluster_weight": 10.5,
      "flower_bud_to_fruit_cluster_conversion": 0.8,
      "estimated_yield_conversion_factor": 1.2
    },
    "good": {
      "fruit_cluster_weight": 8.0,
      "flower_bud_to_fruit_cluster_conversion": 0.7,
      "estimated_yield_conversion_factor": 1.0
    },
    "normal": {
      "fruit_cluster_weight": 6.0,
      "flower_bud_to_fruit_cluster_conversion": 0.6,
      "estimated_yield_conversion_factor": 0.9
    },
    "bad": {
      "fruit_cluster_weight": 4.0,
      "flower_bud_to_fruit_cluster_conversion": 0.5,
      "estimated_yield_conversion_factor": 0.8
    }
  }
}
```

---

### Get Crop Type

Retrieves a single crop type. Use the shallow route (crop type ID in path).

```http
GET /api/crop_types/{crop_type}
```

#### Response

Same structure as a single item in the list response above.

---

### Update Crop Type

Updates an existing crop type.

```http
PUT /api/crop_types/{crop_type}
PATCH /api/crop_types/{crop_type}
```

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Unique name, max 255 characters (excluding current crop type) |
| `standard_day_degree` | number | No | Standard day degree |
| `is_active` | boolean | No | Whether the crop type is active |
| `load_estimation_data` | object | No | See [Load Estimation Data](#load-estimation-data) |

---

### Delete Crop Type

Deletion is not supported. The API returns `403 Forbidden` for all users.

```http
DELETE /api/crop_types/{crop_type}
```

**Response:** `403 Forbidden`

---

## Data Models

### Crop Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique identifier |
| `name` | string | Crop name |
| `cold_requirement` | integer \| null | Cold requirement in hours |
| `is_active` | boolean | Whether the crop is active (default: `true`) |
| `created_at` | string | Creation date (Jalali format) |
| `is_global` | boolean | Whether created by root (global) |
| `is_owned` | boolean | Whether owned by current user |
| `creator` | object \| null | Creator user (`id`, `username`, `mobile`) if loaded |
| `crop_types` | array | Crop types when loaded |
| `can.update` | boolean | User can update |
| `can.delete` | boolean | User can delete (always `false`) |

### Crop Type Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique identifier |
| `name` | string | Crop type name |
| `standard_day_degree` | number | Standard day degree |
| `is_active` | boolean | Whether active (default: `true`) |
| `load_estimation_data` | object \| null | Load estimation metrics (see below) |
| `phonology_guide_files` | array | Phonology guide files when loaded |
| `created_at` | string | Creation date (Jalali format) |
| `is_global` | boolean | Whether created by root |
| `is_owned` | boolean | Whether owned by current user |
| `creator` | object \| null | Creator user if loaded |
| `can.update` | boolean | User can update |
| `can.delete` | boolean | User can delete (always `false`) |

---

## Load Estimation Data

`load_estimation_data` is an optional JSON object on crop types. It maps status keys to metric objects.

### Structure

```json
{
  "excellent": {
    "fruit_cluster_weight": 10.5,
    "flower_bud_to_fruit_cluster_conversion": 0.8,
    "estimated_yield_conversion_factor": 1.2
  },
  "good": {
    "fruit_cluster_weight": 8.0,
    "flower_bud_to_fruit_cluster_conversion": 0.7,
    "estimated_yield_conversion_factor": 1.0
  },
  "normal": {
    "fruit_cluster_weight": 6.0,
    "flower_bud_to_fruit_cluster_conversion": 0.6,
    "estimated_yield_conversion_factor": 0.9
  },
  "bad": {
    "fruit_cluster_weight": 4.0,
    "flower_bud_to_fruit_cluster_conversion": 0.5,
    "estimated_yield_conversion_factor": 0.8
  }
}
```

### Required Keys

- **Status keys:** `excellent`, `good`, `normal`, `bad`
- **Metric keys per status:**
  - `fruit_cluster_weight`
  - `flower_bud_to_fruit_cluster_conversion`
  - `estimated_yield_conversion_factor`

### Validation Rules

- Must be an object (or `null`/omit to skip).
- Must include exactly the four status keys: `excellent`, `good`, `normal`, `bad`.
- Each status must be an object with the three metric keys.
- Metric values must be `null` or numeric.
- Extra status keys are not allowed.

---

## Related Validations

### Farm Creation/Update

When creating or updating a farm (`POST /api/farms`, `PUT/PATCH /api/farms/{farm}`):

- `crop_id` must reference a crop that **exists** and has **`is_active` = true**.
- Inactive crops are rejected with a validation error.

### Field Creation/Update

When creating or updating a field (`POST /api/farms/{farm}/fields`, `PUT/PATCH /api/fields/{field}`):

- `crop_type_id` (if provided) must reference a crop type that **exists** and has **`is_active` = true**.
- Inactive crop types are rejected with a validation error.

**Frontend recommendation:** When building dropdowns for farms and fields, request crops/crop types with `?active=1` so only active options are shown.

---

## Error Responses

### 403 Forbidden

Returned when the user lacks permission for the action (e.g., delete, update another user's item).

```json
{
  "message": "This action is unauthorized."
}
```

### 404 Not Found

Returned when the resource does not exist or the user has no access.

```json
{
  "message": "No query results for model [App\\Models\\Crop] {id}"
}
```

### 422 Validation Error

Returned when request data fails validation.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name has already been taken."],
    "load_estimation_data": ["The load estimation data must contain the status: excellent."]
  }
}
```

### 400 Bad Request

Returned when deleting a crop type that has fields.

```json
{
  "message": "This crop type has fields."
}
```

---

## Route Summary

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/crops` | List crops |
| POST | `/api/crops` | Create crop |
| GET | `/api/crops/{crop}` | Get crop |
| PUT/PATCH | `/api/crops/{crop}` | Update crop |
| DELETE | `/api/crops/{crop}` | Delete crop (returns 403) |
| GET | `/api/crops/{crop}/crop_types` | List crop types |
| POST | `/api/crops/{crop}/crop_types` | Create crop type |
| GET | `/api/crop_types/{crop_type}` | Get crop type |
| PUT/PATCH | `/api/crop_types/{crop_type}` | Update crop type |
| DELETE | `/api/crop_types/{crop_type}` | Delete crop type (returns 403) |
