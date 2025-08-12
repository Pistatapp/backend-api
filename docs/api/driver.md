# Driver API

This document describes the endpoints for managing drivers within farms, along with the utility endpoint for listing available (unassigned) drivers. All endpoints require authentication.

---

## Table of Contents
- [Authentication](#authentication)
- [List Drivers](#list-drivers)
- [Create Driver](#create-driver)
- [Get Driver](#get-driver)
- [Update Driver](#update-driver)
- [Delete Driver](#delete-driver)
- [List Available Drivers](#list-available-drivers)
- [Errors](#errors)
- [Notes](#notes)

---

## Authentication

- Required: Yes (Sanctum)
- Base path: `/api`

Access to farm resources is limited to users who are members of the respective farm.

---

## List Drivers

List drivers for a specific farm, paginated.

```http
GET /api/farms/{farm}/drivers
```

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Response
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "mobile": "09123456789",
      "employee_code": "1234567",
      "created_at": "2024-06-01 10:15:00",
      "can": {
        "delete": true
      }
    }
  ],
  "links": {
    "first": "string (url)",
    "last": "string (url)",
    "prev": "string (url)",
    "next": "string (url)"
  },
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 100
  }
}
```

---

## Create Driver

Create a new driver within a farm. A 7-digit `employee_code` is generated automatically.

```http
POST /api/farms/{farm}/drivers
```

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Request Body
| Field   | Type   | Required | Rules                                                |
|---------|--------|----------|------------------------------------------------------|
| name    | string | Yes      | max:255                                              |
| mobile  | string | Yes      | `ir_mobile`, unique in `drivers.mobile`              |

#### Example
```json
{
  "name": "John Doe",
  "mobile": "09123456789"
}
```

### Response
```json
{
  "data": {
    "id": 10,
    "name": "John Doe",
    "mobile": "09123456789",
    "employee_code": "7654321",
    "created_at": "2024-06-01 10:20:00",
    "can": {
      "delete": true
    }
  }
}
```

---

## Get Driver

Retrieve a specific driver by ID. Includes the assigned tractor when present.

```http
GET /api/drivers/{driver}
```

### Path Parameters
- `driver` (integer, required): The ID of the driver.

### Response
```json
{
  "data": {
    "id": 10,
    "tractor": {
      "id": 3,
      "name": "Tractor A"
    },
    "name": "John Doe",
    "mobile": "09123456789",
    "employee_code": "7654321",
    "created_at": "2024-06-01 10:20:00",
    "can": {
      "delete": false
    }
  }
}
```

---

## Update Driver

Update an existing driver.

```http
PUT /api/drivers/{driver}
```

You may also use `PATCH`.

### Path Parameters
- `driver` (integer, required): The ID of the driver.

### Request Body
| Field   | Type   | Required | Rules                                                              |
|---------|--------|----------|--------------------------------------------------------------------|
| name    | string | Yes      | max:255                                                            |
| mobile  | string | Yes      | `ir_mobile`, unique in `drivers.mobile` except the current driver  |

#### Example
```json
{
  "name": "Johnathan Doe",
  "mobile": "09120000000"
}
```

### Response
```json
{
  "data": {
    "id": 10,
    "name": "Johnathan Doe",
    "mobile": "09120000000",
    "employee_code": "7654321",
    "created_at": "2024-06-01 10:20:00",
    "can": {
      "delete": true
    }
  }
}
```

---

## Delete Driver

Delete a driver.

```http
DELETE /api/drivers/{driver}
```

### Path Parameters
- `driver` (integer, required): The ID of the driver.

### Response
- **204 No Content** on success.

Note: The `can.delete` flag in responses indicates whether the driver currently has no assigned tractor (recommended prerequisite for deletion).

---

## List Available Drivers

List drivers in a farm who are not assigned to any tractor.

```http
GET /api/farms/{farm}/drivers/available
```

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Response
```json
{
  "data": [
    {
      "id": 1,
      "name": "Jane Smith",
      "mobile": "09121111111",
      "employee_code": "1234567"
    }
  ]
}
```

For assignment of drivers to tractors, see the Tractor GPS Device and Driver Assignment documentation (`docs/api/tractor-gps-driver-assignment.md`).

---

## Errors

- **401 Unauthorized**: If the user is not authenticated.
- **403 Forbidden**: If the user does not have access to the specified farm or driver.
- **404 Not Found**: If the specified farm or driver does not exist.
- **422 Unprocessable Entity**: If validation fails (e.g., missing fields, invalid `ir_mobile`, or non-unique `mobile`).

---

## Notes

- Mobile numbers must be in valid Iranian format (`ir_mobile`).
- The `employee_code` is a 7-digit code generated by the system during creation.
- List endpoints are paginated with a default page size of 25.
- Shallow nesting is used: while listing/creation are under `/farms/{farm}/drivers`, single-driver actions use `/drivers/{driver}`.


