# Active Tractor API Documentation

## List Active Tractors for a Farm

Lists all active tractors for a specific farm that have both GPS devices and drivers assigned.

```http
GET /api/farms/{farm_id}/tractors/active
```

### Parameters

| Parameter | Type    | Description                     |
|-----------|---------|--------------------------------|
| farm_id   | integer | ID of the farm to query        |

### Response

```json
{
    "data": [
        {
            "id": "integer",
            "name": "string",
            "gps_device": {
                "id": "integer",
                "imei": "string"
            },
            "driver": {
                "id": "integer",
                "name": "string",
                "mobile": "string"
            },
            "status": "boolean"
        }
    ]
}
```

## Get Tractor Reports

Retrieves detailed reports for a specific tractor including GPS coordinates with Kalman filter smoothing applied.

```http
GET /api/tractors/{tractor_id}/reports
```

### Parameters

| Parameter  | Type    | Description                                  |
|------------|---------|----------------------------------------------|
| tractor_id | integer | ID of the tractor                           |
| date       | string  | Date in Y/m/d format (required)             |

### Response

```json
{
    "data": {
        "id": "integer",
        "name": "string",
        "speed": "number",
        "status": "string",
        "start_working_time": "string (H:i:s)",
        "traveled_distance": "number (formatted to 2 decimals)",
        "work_duration": "string (H:i:s)",
        "stoppage_count": "integer",
        "stoppage_duration": "string (H:i:s)",
        "efficiency": "number (formatted to 2 decimals)",
        "points": [
            {
                "latitude": "number",
                "longitude": "number",
                "speed": "number",
                "status": "integer",
                "is_starting_point": "boolean",
                "is_ending_point": "boolean",
                "is_stopped": "boolean"
            }
        ],
        "current_task": {
            "id": "integer",
            "operation": {
                "id": "integer",
                "name": "string"
            },
            "field": {
                "id": "integer",
                "name": "string"
            },
            "date": "string (Y/m/d)",
            "start_time": "string (H:i)",
            "end_time": "string (H:i)",
            "status": "string"
        }
    }
}
```

## Get Tractor Path

Retrieves the path coordinates for a specific tractor on a given date.

```http
GET /api/tractors/{tractor_id}/path
```

### Parameters

| Parameter  | Type    | Description                                  |
|------------|---------|----------------------------------------------|
| tractor_id | integer | ID of the tractor                           |
| date       | string  | Date in Y/m/d format (required)             |

### Response

```json
{
    "data": [
        {
            "id": "integer",
            "latitude": "number",
            "longitude": "number",
            "speed": "number",
            "status": "integer",
            "is_starting_point": "boolean",
            "is_ending_point": "boolean",
            "is_stopped": "boolean",
            "stoppage_time": "string (H:i:s)",
            "date_time": "string (Y/m/d H:i:s)"
        }
    ]
}
```

## Get Tractor Details

Retrieves detailed information about a tractor including efficiency data for the last seven days.

```http
GET /api/tractors/{tractor_id}/details
```

### Parameters

| Parameter  | Type    | Description                                  |
|------------|---------|----------------------------------------------|
| tractor_id | integer | ID of the tractor                           |
| date       | string  | Date in Y/m/d format (required)             |

### Response

```json
{
    "data": {
        "id": "integer",
        "name": "string",
        "speed": "number",
        "status": "integer",
        "start_working_time": "string (H:i:s)",
        "traveled_distance": "number (formatted to 2 decimals)",
        "work_duration": "string (H:i:s)",
        "stoppage_count": "integer",
        "stoppage_duration": "string (H:i:s)",
        "efficiency": "number (formatted to 2 decimals)",
        "current_task": {
            "id": "integer",
            "operation": {
                "id": "integer",
                "name": "string"
            },
            "field": {
                "id": "integer",
                "name": "string"
            },
            "date": "string (Y/m/d)",
            "start_time": "string (H:i)",
            "end_time": "string (H:i)",
            "status": "string"
        },
        "last_seven_days_efficiency": [
            {
                "date": "string (Y/m/d)",
                "efficiency": "number (formatted to 2 decimals)"
            }
        ]
    }
}
```

## Tractor Management Endpoints

### List All Tractors

Lists all tractors for a farm with their drivers and GPS devices.

```http
GET /api/farms/{farm_id}/tractors
```

### Parameters

| Parameter | Type    | Description              |
|-----------|---------|--------------------------|
| farm_id   | integer | ID of the farm to query |

### Response

```json
{
    "data": [
        {
            "id": "integer",
            "farm_id": "integer",
            "name": "string",
            "start_work_time": "string (H:i)",
            "end_work_time": "string (H:i)",
            "expected_daily_work_time": "integer",
            "expected_monthly_work_time": "integer",
            "expected_yearly_work_time": "integer",
            "driver": {
                "id": "integer",
                "name": "string",
                "mobile": "string",
                "employee_code": "string"
            },
            "gps_device": {
                "id": "integer",
                "name": "string",
                "imei": "string"
            },
            "created_at": "string (Y-m-d H:i:s)",
            "can": {
                "add_driver": "boolean",
                "add_gps_device": "boolean"
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
        "current_page": "integer",
        "per_page": "integer",
        "total": "integer"
    }
}
```

### Create Tractor

Creates a new tractor for a farm.

```http
POST /api/farms/{farm_id}/tractors
```

#### Request Body

| Parameter               | Type    | Description                                    |
|------------------------|---------|------------------------------------------------|
| name                   | string  | Name of the tractor                           |
| start_work_time       | string  | Daily start time (H:i format)                 |
| end_work_time         | string  | Daily end time (H:i format)                   |
| expected_daily_work_time | integer | Expected work hours per day (0-24)           |
| expected_monthly_work_time | integer | Expected work hours per month (0-744)        |
| expected_yearly_work_time | integer | Expected work hours per year (0-8760)        |

### Get Tractor Details

Get detailed information about a specific tractor.

```http
GET /api/tractors/{tractor_id}
```

### Update Tractor

Update a tractor's information.

```http
PUT /api/tractors/{tractor_id}
```

#### Request Body
Same as Create Tractor endpoint.

### Delete Tractor

Delete a tractor.

```http
DELETE /api/tractors/{tractor_id}
```

### Driver Management

#### Get Driver

Get the driver assigned to a tractor.

```http
GET /api/tractors/{tractor_id}/driver
```

#### Assign Driver

Assign a new driver to a tractor.

```http
POST /api/tractors/{tractor_id}/driver
```

##### Request Body

| Parameter | Type   | Description                          |
|-----------|--------|--------------------------------------|
| name      | string | Driver's name                        |
| mobile    | string | Driver's mobile number (ir_mobile format) |

#### Update Driver

Update driver information.

```http
PUT /api/tractors/{tractor_id}/driver
```

##### Request Body
Same as Assign Driver endpoint.

#### Remove Driver

Remove the assigned driver from a tractor.

```http
DELETE /api/tractors/{tractor_id}/driver
```

### GPS Device Management

#### List Available Devices

Get a list of available GPS devices that can be assigned to a tractor.

```http
GET /api/tractors/{tractor_id}/devices
```

#### Response

```json
{
    "data": [
        {
            "id": "integer",
            "name": "string",
            "imei": "string"
        }
    ]
}
```

#### Assign Device

Assign a GPS device to a tractor.

```http
POST /api/tractors/{tractor_id}/assign_device/{gps_device_id}
```

#### Unassign Device

Remove a GPS device from a tractor.

```http
POST /api/tractors/{tractor_id}/unassign_device/{gps_device_id}
```

### Report Management

#### List Tractor Reports

Get a paginated list of reports for a specific tractor.

```http
GET /api/tractors/{tractor_id}/tractor_reports
```

#### Create Report

Create a new report for a tractor.

```http
POST /api/tractors/{tractor_id}/tractor_reports
```

##### Request Body

| Parameter    | Type    | Description                                |
|-------------|---------|-------------------------------------------|
| date        | string  | Report date (Y/m/d format)                |
| start_time  | string  | Start time (H:i format)                   |
| end_time    | string  | End time (H:i format)                     |
| operation_id| integer | ID of the operation performed             |
| field_id    | integer | ID of the field where work was performed  |
| description | string  | Optional description of the work          |

#### Filter Reports

Filter tractor reports by various criteria.

```http
POST /api/tractors/filter_reports
```

##### Request Body

| Parameter  | Type    | Description                                           |
|------------|---------|-------------------------------------------------------|
| tractor_id | integer | ID of the tractor                                    |
| date       | string  | Specific date (Y-m-d format)                         |
| period     | string  | Optional: month, year, specific_month, persian_year  |
| month      | string  | Required if period is specific_month                 |
| year       | string  | Required if period is persian_year                   |
| operation  | integer | Optional: Filter by operation ID                     |

## Error Responses

### Validation Error

```http
422 Unprocessable Entity
```

Returned when:
- Required parameters are missing
- Date format is invalid (must be Y/m/d)
- Invalid tractor or farm IDs

```json
{
    "errors": {
        "date": [
            "The date field is required."
        ]
    }
}
```

### Authorization Error

```http
403 Forbidden
```

Returned when the user doesn't have permission to access the requested resource.

### Not Found Error

```http
404 Not Found
```

Returned when the requested tractor, farm, or related resources don't exist.

### Notes

- All date parameters must be provided in Y/m/d format
- GPS coordinates are processed through a Kalman filter for noise reduction to provide smoother paths
- Efficiency values are reported as percentages with 2 decimal places
- Response times may vary based on the amount of GPS data being processed
- All timestamps are returned in 24-hour format
- Duration fields (work_duration, stoppage_duration) are formatted as H:i:s
- The API requires authentication and proper authorization
- Responses are paginated where applicable
- All time-based fields use 24-hour format
- Dates should be provided in the specified format (Y/m/d)
- Pagination is implemented on list endpoints with 25 items per page by default
- All endpoints require proper authentication
- Some endpoints may require specific permissions or roles
- Mobile numbers must be in valid Iranian format
- Times are in H:i format (e.g., "08:00")
- Work time expectations are in hours
