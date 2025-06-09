# Tractor GPS Device and Driver Assignment API

This document describes the API endpoints for managing the assignment of GPS devices and drivers to tractors within a farm.

---

## Table of Contents
- [Get Available GPS Devices](#get-available-gps-devices)
- [Get Available Tractors](#get-available-tractors)
- [Get Available Drivers](#get-available-drivers)
- [Assign Driver and GPS Device to Tractor](#assign-driver-and-gps-device-to-tractor)

---

## Get Available GPS Devices

**GET** `/farms/{farm}/gps-devices/available`

Returns a list of GPS devices owned by the authenticated user that are not currently assigned to any tractor in the specified farm.

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Authentication
- Required: Yes (Sanctum)

### Response
```
{
  "data": [
    {
      "id": 1,
      "name": "Device 1",
      "imei": "123456789012345"
    },
    ...
  ]
}
```

---

## Get Available Tractors

**GET** `/farms/{farm}/tractors/available`

Returns a list of tractors in the specified farm that do not have a GPS device assigned.

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Authentication
- Required: Yes (Sanctum)

### Response
```
{
  "data": [
    {
      "id": 1,
      "name": "Tractor 1",
      "driver": {
        "id": 2,
        "name": "John Doe",
        "mobile": "09123456789",
        "employee_code": "1234567"
      }
    },
    ...
  ]
}
```

---

## Get Available Drivers

**GET** `/farms/{farm}/drivers/available`

Returns a list of drivers in the specified farm who are not currently assigned to any tractor.

### Path Parameters
- `farm` (integer, required): The ID of the farm.

### Authentication
- Required: Yes (Sanctum)

### Response
```
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "mobile": "09123456789",
      "employee_code": "1234567"
    },
    ...
  ]
}
```

---

## Assign Driver and GPS Device to Tractor

**POST** `/tractors/{tractor}/assignments`

Assigns a driver and a GPS device to a tractor. This will replace any existing assignments for the tractor.

### Path Parameters
- `tractor` (integer, required): The ID of the tractor.

### Authentication
- Required: Yes (Sanctum)

### Request Body
| Field         | Type    | Required | Description                       |
|-------------- |---------|----------|-----------------------------------|
| driver_id     | integer | Yes      | ID of the driver to assign        |
| gps_device_id | integer | Yes      | ID of the GPS device to assign    |

#### Example
```
{
  "driver_id": 2,
  "gps_device_id": 5
}
```

### Validation
- `driver_id` must exist in the `drivers` table.
- `gps_device_id` must exist in the `gps_devices` table.

### Response
- **204 No Content** on success (no response body).
- **422 Unprocessable Entity** if validation fails.

---

## Error Responses

- **401 Unauthorized**: If the user is not authenticated.
- **403 Forbidden**: If the user does not have permission to assign drivers or devices.
- **404 Not Found**: If the specified farm, tractor, driver, or device does not exist.
- **422 Unprocessable Entity**: If the request validation fails.

---

## Notes
- Assigning a new driver or GPS device will unassign any previous driver or device from the tractor.
- All endpoints require authentication via Sanctum. 
