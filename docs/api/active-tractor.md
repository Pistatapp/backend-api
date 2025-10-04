# Active Tractor API Documentation

This document provides comprehensive documentation for the Active Tractor API endpoints. These endpoints are designed to manage and retrieve real-time information about active tractors, their performance metrics, GPS tracking data, and operational details.

## Authentication

All endpoints require authentication using Laravel Sanctum. Include the authentication token in the request headers:

```http
Authorization: Bearer {your-token}
```

## Base URL

```
https://your-domain.com/api
```

---

## Endpoints Overview

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/farms/{farm}/tractors/active` | List active tractors for a farm |
| GET | `/farms/{farm}/tractors/working` | List working tractors for a farm |
| GET | `/tractors/{tractor}/path` | Get tractor GPS path for a specific date |
| GET | `/tractors/{tractor}/performance` | Get tractor performance metrics for a specific date |
| GET | `/tractors/{tractor}/timings` | Get tractor working timings for a specific date |
| GET | `/tractors/{tractor}/current-task` | Get current active task for a tractor |
| GET | `/tractors/{tractor}/weekly-efficiency-chart` | Get weekly efficiency chart data |

---

## 1. List Active Tractors for a Farm

Retrieves all active tractors for a specific farm that have both GPS devices and drivers assigned.

### Endpoint
```http
GET /api/farms/{farm}/tractors/active
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| farm | integer | ID of the farm to query | Yes |

### Response

**Success (200 OK)**
```json
{
    "data": [
        {
            "id": 1,
            "name": "Tractor-001",
            "gps_device": {
                "id": 1,
                "imei": "863070043386100"
            },
            "driver": {
                "id": 1,
                "name": "John Doe",
                "mobile": "09123456789"
            },
            "status": 1,
            "start_working_time": "08:00:00"
        }
    ]
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | Unique tractor identifier |
| name | string | Tractor name |
| gps_device.id | integer | GPS device ID |
| gps_device.imei | string | GPS device IMEI number |
| driver.id | integer | Driver ID |
| driver.name | string | Driver full name |
| driver.mobile | string | Driver mobile number |
| status | integer | Current tractor status (1=active, 0=inactive) |
| start_working_time | string | Daily start working time (H:i:s format) |

---

## 2. List Working Tractors for a Farm

Retrieves all working tractors for a specific farm where `is_working = true`. This endpoint focuses on tractors that are currently in a working state, regardless of their GPS device or driver assignment status.

### Endpoint
```http
GET /api/farms/{farm}/tractors/working
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| farm | integer | ID of the farm to query | Yes |

### Response

**Success (200 OK)**
```json
{
    "data": [
        {
            "id": 1,
            "name": "Tractor-001",
            "gps_device": {
                "id": 1,
                "imei": "863070043386100"
            },
            "driver": {
                "id": 1,
                "name": "John Doe",
                "mobile": "09123456789"
            },
            "status": 1,
            "start_working_time": "08:00:00"
        },
        {
            "id": 2,
            "name": "Tractor-002",
            "gps_device": {
                "id": 2,
                "imei": "863070043386101"
            },
            "driver": {
                "id": 2,
                "name": "Jane Smith",
                "mobile": "09123456790"
            },
            "status": 1,
            "start_working_time": "09:15:00"
        }
    ]
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | Unique tractor identifier |
| name | string | Tractor name |
| gps_device.id | integer | GPS device ID |
| gps_device.imei | string | GPS device IMEI number |
| driver.id | integer | Driver ID |
| driver.name | string | Driver full name |
| driver.mobile | string | Driver mobile number |
| status | integer | Current tractor status (1=active, 0=inactive) |
| start_working_time | string | Daily start working time (H:i:s format) |

### Key Differences from Active Tractors

| Feature | Active Tractors | Working Tractors |
|---------|----------------|-----------------|
| **Filter Criteria** | Has GPS device AND driver | `is_working = true` |
| **Use Case** | Tractors ready for operation | Tractors currently working |
| **Status Focus** | Equipment readiness | Operational state |

---

## 3. Get Tractor GPS Path

Retrieves the GPS path coordinates for a specific tractor on a given date with streaming support for large datasets.

### Endpoint
```http
GET /api/tractors/{tractor}/path
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| tractor | integer | ID of the tractor | Yes |
| date | string | Date in Shamsi format (Y/m/d) | Yes |
| stream | boolean | Enable streaming for large datasets (default: true) | No |

### Example Request
```http
GET /api/tractors/1/path?date=1403/09/15&stream=true
```

### Response

**Success (200 OK)**
```json
{
    "data": [
        {
            "latitude": 34.884065,
            "longitude": 50.599625,
            "speed": 20.5,
            "status": 1,
            "is_starting_point": true,
            "is_ending_point": false,
            "is_stopped": false,
            "stoppage_time": "00:00:00",
            "date_time": "1403/09/15 08:30:15"
        },
        {
            "latitude": 34.884125,
            "longitude": 50.599675,
            "speed": 25.0,
            "status": 1,
            "is_starting_point": false,
            "is_ending_point": false,
            "is_stopped": false,
            "stoppage_time": "00:00:00",
            "date_time": "1403/09/15 08:30:30"
        }
    ]
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| latitude | number | GPS latitude coordinate |
| longitude | number | GPS longitude coordinate |
| speed | number | Speed in km/h |
| status | integer | GPS status (1=active, 0=inactive) |
| is_starting_point | boolean | Whether this is the starting point |
| is_ending_point | boolean | Whether this is the ending point |
| is_stopped | boolean | Whether the tractor is currently stopped |
| stoppage_time | string | Accumulated stoppage time (H:i:s format) |
| date_time | string | Timestamp in Shamsi format (Y/m/d H:i:s) |

---

## 3. Get Tractor Performance

Retrieves comprehensive performance metrics for a specific tractor on a given date, including efficiency calculations.

### Endpoint
```http
GET /api/tractors/{tractor}/performance
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| tractor | integer | ID of the tractor | Yes |
| date | string | Date in Shamsi format (Y/m/d) | Yes |

### Example Request
```http
GET /api/tractors/1/performance?date=1403/09/15
```

### Response

**Success (200 OK)**
```json
{
    "data": {
        "id": 1,
        "name": "Tractor-001",
        "speed": 25,
        "status": 1,
        "traveled_distance": "15.50",
        "work_duration": "08:30:00",
        "stoppage_count": 3,
        "stoppage_duration": "00:45:00",
        "efficiencies": {
            "total": "85.50",
            "task-based": "88.75"
        },
        "driver": {
            "id": 1,
            "name": "John Doe",
            "mobile": "09123456789"
        }
    }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | Tractor ID |
| name | string | Tractor name |
| speed | integer | Average speed in km/h |
| status | integer | Current status |
| traveled_distance | string | Total distance traveled (formatted to 2 decimals) |
| work_duration | string | Total work duration (H:i:s format) |
| stoppage_count | integer | Number of stoppages |
| stoppage_duration | string | Total stoppage duration (H:i:s format) |
| efficiencies.total | string | Daily efficiency percentage (formatted to 2 decimals) |
| efficiencies.task-based | string | Task-based efficiency percentage (formatted to 2 decimals) |
| driver.id | integer | Driver ID |
| driver.name | string | Driver name |
| driver.mobile | string | Driver mobile number |

### Efficiency Calculation

- **Total Efficiency**: Calculated from daily metrics (where `tractor_task_id` is null)
- **Task-based Efficiency**: 
  - If single task: Uses that task's efficiency
  - If multiple tasks: Calculates average efficiency of all tasks
  - If no tasks: Returns null

---

## 4. Get Tractor Timings

Retrieves working time information for a specific tractor on a given date.

### Endpoint
```http
GET /api/tractors/{tractor}/timings
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| tractor | integer | ID of the tractor | Yes |
| date | string | Date in Shamsi format (Y/m/d) | Yes |

### Example Request
```http
GET /api/tractors/1/timings?date=1403/09/15
```

### Response

**Success (200 OK)**
```json
{
    "start_working_time": "08:00:00",
    "end_working_time": "16:30:00",
    "on_time": "08:15:00"
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| start_working_time | string | Actual start working time (H:i:s format) |
| end_working_time | string | Actual end working time (H:i:s format) |
| on_time | string | Time when tractor was turned on (H:i:s format) |

---

## 5. Get Current Task

Retrieves the currently active task for a specific tractor.

### Endpoint
```http
GET /api/tractors/{tractor}/current-task
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| tractor | integer | ID of the tractor | Yes |

### Example Request
```http
GET /api/tractors/1/current-task
```

### Response

**Success (200 OK)**
```json
{
    "data": {
        "id": 1,
        "status": "started",
        "operation": {
            "id": 1,
            "name": "Plowing"
        },
        "taskable": {
            "id": 1,
            "name": "Field A"
        },
        "date": "1403/09/15",
        "start_time": "08:00",
        "end_time": "16:00",
        "description": "Plowing operation for Field A"
    }
}
```

**No Active Task (200 OK)**
```json
null
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | Task ID |
| status | string | Task status (started, completed, etc.) |
| operation.id | integer | Operation ID |
| operation.name | string | Operation name |
| taskable.id | integer | Target entity ID (field, plot, etc.) |
| taskable.name | string | Target entity name |
| date | string | Task date (Y/m/d format) |
| start_time | string | Planned start time (H:i format) |
| end_time | string | Planned end time (H:i format) |
| description | string | Task description |

---

## 6. Get Weekly Efficiency Chart

Retrieves efficiency chart data for the last 7 days for both total and task-based metrics.

### Endpoint
```http
GET /api/tractors/{tractor}/weekly-efficiency-chart
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| tractor | integer | ID of the tractor | Yes |

### Example Request
```http
GET /api/tractors/1/weekly-efficiency-chart
```

### Response

**Success (200 OK)**
```json
{
    "data": {
        "total_efficiencies": [
            {
                "efficiency": "85.50",
                "date": "1403/09/09"
            },
            {
                "efficiency": "88.25",
                "date": "1403/09/10"
            },
            {
                "efficiency": "82.75",
                "date": "1403/09/11"
            },
            {
                "efficiency": "90.00",
                "date": "1403/09/12"
            },
            {
                "efficiency": "87.50",
                "date": "1403/09/13"
            },
            {
                "efficiency": "89.25",
                "date": "1403/09/14"
            },
            {
                "efficiency": "85.50",
                "date": "1403/09/15"
            }
        ],
        "task_based_efficiencies": [
            {
                "efficiency": "88.75",
                "date": "1403/09/09"
            },
            {
                "efficiency": "91.00",
                "date": "1403/09/10"
            },
            {
                "efficiency": "85.25",
                "date": "1403/09/11"
            },
            {
                "efficiency": "92.50",
                "date": "1403/09/12"
            },
            {
                "efficiency": "89.75",
                "date": "1403/09/13"
            },
            {
                "efficiency": "91.50",
                "date": "1403/09/14"
            },
            {
                "efficiency": "88.75",
                "date": "1403/09/15"
            }
        ]
    }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| total_efficiencies | array | Array of daily total efficiency data |
| total_efficiencies[].efficiency | string | Daily efficiency percentage (formatted to 2 decimals) |
| total_efficiencies[].date | string | Date in Shamsi format (Y/m/d) |
| task_based_efficiencies | array | Array of daily task-based efficiency data |
| task_based_efficiencies[].efficiency | string | Task-based efficiency percentage (formatted to 2 decimals) |
| task_based_efficiencies[].date | string | Date in Shamsi format (Y/m/d) |

### Chart Data Logic

- **Date Range**: Always returns the last 7 days from today
- **Total Efficiency**: Uses daily metrics (where `tractor_task_id` is null)
- **Task-based Efficiency**: 
  - Single task: Uses that task's efficiency
  - Multiple tasks: Calculates average efficiency
  - No tasks: Returns "0.00"
- **Date Format**: All dates are in Shamsi (Persian) calendar format

---

## Error Responses

### 400 Bad Request
```json
{
    "message": "Invalid request parameters"
}
```

### 401 Unauthorized
```json
{
    "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
    "message": "This action is unauthorized."
}
```

### 404 Not Found
```json
{
    "message": "Tractor not found"
}
```

### 422 Unprocessable Entity
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "date": [
            "The date field is required."
        ]
    }
}
```

### 500 Internal Server Error
```json
{
    "message": "Server Error"
}
```

---

## Data Formats

### Date Format
- **Input**: Shamsi (Persian) calendar format: `Y/m/d` (e.g., `1403/09/15`)
- **Output**: Shamsi (Persian) calendar format: `Y/m/d` (e.g., `1403/09/15`)

### Time Format
- **Input**: 24-hour format: `H:i` (e.g., `08:30`)
- **Output**: 24-hour format: `H:i:s` (e.g., `08:30:00`)

### Number Format
- **Efficiency**: Formatted to 2 decimal places (e.g., `85.50`)
- **Distance**: Formatted to 2 decimal places (e.g., `15.50`)

---

## Rate Limiting

All endpoints are subject to rate limiting:
- **Default**: 60 requests per minute per user
- **Headers**: Rate limit information is included in response headers

---

## Notes

1. **Authentication**: All endpoints require valid authentication tokens
2. **Authorization**: Users can only access tractors from farms they have permission to view
3. **Real-time Data**: GPS and performance data is updated in real-time
4. **Streaming**: Path endpoint supports streaming for large datasets to improve performance
5. **Efficiency Calculations**: Based on GPS metrics and task completion data
6. **Date Handling**: All dates use the Shamsi (Persian) calendar system
7. **Performance**: Large datasets are optimized with streaming and pagination
8. **Error Handling**: Comprehensive error responses with detailed messages
9. **Data Validation**: All input parameters are validated before processing
10. **Caching**: Some endpoints may use caching for improved performance

---

## Example Usage

### JavaScript/Fetch Example
```javascript
// Get active tractors for a farm
const response = await fetch('/api/farms/1/tractors/active', {
    headers: {
        'Authorization': 'Bearer your-token',
        'Content-Type': 'application/json'
    }
});
const data = await response.json();

// Get working tractors for a farm
const workingResponse = await fetch('/api/farms/1/tractors/working', {
    headers: {
        'Authorization': 'Bearer your-token',
        'Content-Type': 'application/json'
    }
});
const workingData = await workingResponse.json();

// Get tractor performance
const performanceResponse = await fetch('/api/tractors/1/performance?date=1403/09/15', {
    headers: {
        'Authorization': 'Bearer your-token',
        'Content-Type': 'application/json'
    }
});
const performanceData = await performanceResponse.json();
```

### cURL Example
```bash
# Get working tractors for a farm
curl -X GET "https://your-domain.com/api/farms/1/tractors/working" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json"

# Get weekly efficiency chart
curl -X GET "https://your-domain.com/api/tractors/1/weekly-efficiency-chart" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json"
```
