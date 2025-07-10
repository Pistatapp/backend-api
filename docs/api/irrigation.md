# Irrigation API Documentation

This documentation covers the irrigation-related endpoints in the API.

## Base URL
`/api`

## Authentication
All endpoints require authentication using Laravel Sanctum. Include the authentication token in the request header:
```
Authorization: Bearer <your-token>
```

## Date and Time Formats
- All dates in requests and responses use Jalali (Persian) calendar format
- Date format: `1402/09/15` (YYYY/MM/DD)
- Time format: `18:00` (HH:mm in 24-hour format)

## Endpoints

### Filter Irrigation Reports
Retrieve filtered irrigation reports for a farm with advanced filtering capabilities including date range, plot, valves, and labour filtering. This endpoint supports multiple filtering scenarios to generate comprehensive irrigation reports.

- **URL**: `/farms/{farm}/irrigations/reports`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)

#### Request Parameters

| Parameter  | Type    | Required | Description                                    |
|------------|---------|----------|------------------------------------------------|
| plot_id    | integer | Yes      | ID of the plot to generate reports for        |
| from_date  | string  | Yes      | Start date in Jalali format (YYYY/MM/DD)      |
| to_date    | string  | Yes      | End date in Jalali format (YYYY/MM/DD)        |
| labour_id  | integer | No       | Filter by specific labour personnel            |
| valves     | array   | No       | Array of valve IDs to filter by               |

#### Filtering Scenarios

##### 1. Basic Date Range Report (No Valve Filtering)
Get irrigation reports for all valves in a plot within a date range.

**Request Body**:
```json
{
  "plot_id": 1,
  "from_date": "1402/09/15",
  "to_date": "1402/09/17"
}
```

**Response**:
```json
{
  "data": [
    {
      "date": "1402/09/15",
      "total_duration": "04:30:00",
      "total_volume": 2250.75,
      "total_volume_per_hectare": 900.30,
      "irrigation_count": 3
    },
    {
      "date": "1402/09/16",
      "total_duration": "02:00:00",
      "total_volume": 800.00,
      "total_volume_per_hectare": 320.00,
      "irrigation_count": 1
    },
    {
      "date": "1402/09/17",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    }
  ]
}
```

##### 2. Valve-Specific Report
Get irrigation reports filtered by specific valves.

**Request Body**:
```json
{
  "plot_id": 1,
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "valves": [1, 3, 5]
}
```

**Response**:
```json
{
  "data": [
    {
      "date": "1402/09/15",
      "total_duration": "03:00:00",
      "total_volume": 1350.00,
      "total_volume_per_hectare": 540.00,
      "irrigation_count": 2
    },
    {
      "date": "1402/09/16",
      "total_duration": "01:30:00",
      "total_volume": 675.00,
      "total_volume_per_hectare": 270.00,
      "irrigation_count": 1
    },
    {
      "date": "1402/09/17",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    }
  ]
}
```

##### 3. Labour-Specific Report
Get irrigation reports filtered by specific labour personnel.

**Request Body**:
```json
{
  "plot_id": 1,
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "labour_id": 2
}
```

**Response**:
```json
{
  "data": [
    {
      "date": "1402/09/15",
      "total_duration": "02:30:00",
      "total_volume": 1125.50,
      "total_volume_per_hectare": 450.20,
      "irrigation_count": 2
    },
    {
      "date": "1402/09/16",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    },
    {
      "date": "1402/09/17",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    }
  ]
}
```

##### 4. Combined Filtering (Valves + Labour)
Get irrigation reports filtered by both specific valves and labour personnel.

**Request Body**:
```json
{
  "plot_id": 1,
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "labour_id": 2,
  "valves": [1, 3]
}
```

**Response**:
```json
{
  "data": [
    {
      "date": "1402/09/15",
      "total_duration": "01:30:00",
      "total_volume": 675.00,
      "total_volume_per_hectare": 270.00,
      "irrigation_count": 1
    },
    {
      "date": "1402/09/16",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    },
    {
      "date": "1402/09/17",
      "total_duration": "00:00:00",
      "total_volume": 0,
      "total_volume_per_hectare": 0,
      "irrigation_count": 0
    }
  ]
}
```

#### Response Fields

| Field                    | Type   | Description                                    |
|--------------------------|--------|------------------------------------------------|
| date                     | string | Date in Jalali format (YYYY/MM/DD)            |
| total_duration           | string | Total irrigation duration (HH:MM:SS)          |
| total_volume             | number | Total water volume in liters                   |
| total_volume_per_hectare | number | Total volume per hectare in liters            |
| irrigation_count         | number | Number of irrigation sessions on this date    |

### Get Plot Irrigations
Retrieve irrigations for a specific plot with optional filtering by date and status.

- **URL**: `/plots/{plot}/irrigations`
- **Method**: `GET`
- **URL Parameters**:
  - `plot`: Plot ID (integer)
- **Query Parameters**:
  - `date`: Filter by date (optional, Jalali format: YYYY/MM/DD)
  - `status`: Filter by status (optional, e.g., 'finished', 'pending')
- **Response**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "labour": {
          "id": 1,
          "name": "John Doe"
        },
        "date": "1402/09/15",
        "start_time": "18:00",
        "end_time": "20:00",
        "valves": [
          {
            "id": 1,
            "name": "Valve 1",
            "dripper_count": 100,
            "dripper_flow_rate": 2.5,
            "irrigation_area": 1.5
          }
        ],
        "plots": [
          {
            "id": 1,
            "name": "Plot 1"
          }
        ],
        "created_by": {
          "id": 1,
          "name": "creator_username"
        },
        "note": "Regular irrigation",
        "status": "finished",
        "duration": "02:00:00",
        "can": {
          "delete": true,
          "update": true
        }
      }
    ]
  }
  ```

### Get Plot Irrigation Report
Retrieve irrigation report for a specific plot with calculated totals including volume and duration.

- **URL**: `/plots/{plot}/irrigations/report`
- **Method**: `GET`
- **URL Parameters**:
  - `plot`: Plot ID (integer)
- **Query Parameters**:
  - `date`: Filter by date (optional, Jalali format: YYYY/MM/DD, defaults to today)
- **Response**:
  ```json
  {
    "data": {
      "date": "1402/09/15",
      "total_duration": "02:00:00",
      "total_volume": 1000.50,
      "total_volume_per_hectare": 500.25,
      "total_count": 1
    }
  }
  ```

### Farm Irrigation Resource Endpoints

#### List Farm Irrigations
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `GET`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Response**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "labour": {
          "id": 1,
          "name": "John Doe"
        },
        "date": "1402/09/15",
        "start_time": "18:00",
        "end_time": "20:00",
        "valves": [
          {
            "id": 1,
            "name": "Valve 1",
            "dripper_count": 100,
            "dripper_flow_rate": 2.5,
            "irrigation_area": 1.5
          }
        ],
        "plots": [
          {
            "id": 1,
            "name": "Plot 1"
          }
        ],
        "created_by": {
          "id": 1,
          "name": "creator_username"
        },
        "note": "Regular irrigation",
        "status": "finished",
        "duration": "02:00:00",
        "can": {
          "delete": true,
          "update": true
        }
      }
    ],
    "links": {
      "first": "...",
      "last": "...",
      "prev": null,
      "next": "..."
    },
    "meta": {
      "current_page": 1,
      "per_page": 10
    }
  }
  ```

#### Create Farm Irrigation
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "pump_id": 1,                  // Optional, integer
    "date": "1402/09/15",         // Required, Jalali date format: YYYY/MM/DD
    "start_time": "18:00",        // Required, time format: HH:mm
    "end_time": "20:00",          // Required, time format: HH:mm
    "plots": [1, 2],              // Required, array of plot IDs
    "valves": [1, 2],             // Required, array of valve IDs
    "note": "Regular irrigation"   // Optional, string
  }
  ```
- **Response**: Returns the created irrigation object with HTTP 201

#### Show Single Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `GET`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: Returns the irrigation object with related labour, valves, creator, plots, and pump

#### Update Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `PUT/PATCH`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "pump_id": 1,                  // Optional, integer
    "date": "1402/09/15",         // Required, Jalali date format: YYYY/MM/DD
    "start_time": "18:00",        // Required, time format: HH:mm
    "end_time": "20:00",          // Required, time format: HH:mm
    "plots": [1, 2],              // Required, array of plot IDs
    "valves": [1, 2],             // Required, array of valve IDs
    "note": "Updated notes"       // Optional, string
  }
  ```
- **Response**: Returns the updated irrigation object

#### Delete Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `DELETE`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: HTTP 204 No Content

## Volume Calculations
The irrigation system calculates water volume based on valve specifications:
- **Formula**: `Volume = (dripper_count × dripper_flow_rate) × duration_in_hours`
- **Volume per hectare**: `Volume / irrigation_area`
- **Total volume**: Sum of all valve volumes for each irrigation

## Report Types
The irrigation reporting system supports multiple report types through the filtering endpoint:

### 1. Date Range Reports
Shows daily totals for a specified date range across all valves in a plot.
- **Use Case**: General overview of irrigation activity over time
- **Filtering**: No valve or labour filtering applied
- **Output**: Daily aggregated totals for the entire plot

### 2. Valve-Specific Reports
Shows irrigation data broken down by individual valves or filtered by specific valves.
- **Use Case**: Analyze performance of specific irrigation zones or valves
- **Filtering**: Specify valve IDs in the `valves` array
- **Output**: Daily totals for only the specified valves

### 3. Labour-Specific Reports
Shows irrigation data filtered by specific labour personnel.
- **Use Case**: Track individual worker performance and irrigation assignments
- **Filtering**: Specify labour ID in the `labour_id` parameter
- **Output**: Daily totals for irrigations performed by the specified labour

### 4. Combined Reports
Supports filtering by multiple criteria simultaneously (valves + labour).
- **Use Case**: Detailed analysis of specific valve-labour combinations
- **Filtering**: Combine `valves` array and `labour_id` parameters
- **Output**: Daily totals for irrigations matching both criteria

### Report Calculation Logic
- **Volume Calculation**: Based on valve specifications (dripper_count × dripper_flow_rate × duration)
- **Duration Aggregation**: Sum of all irrigation durations for the day
- **Count Aggregation**: Total number of irrigation sessions
- **Filtering Logic**: 
  - When `valves` is specified: Only irrigations using those valves are included
  - When `labour_id` is specified: Only irrigations performed by that labour are included
  - When both are specified: Only irrigations matching both criteria are included
- **Date Range**: Always includes all dates in the specified range, showing zero values for days with no matching irrigations

### Advanced Report Features
The filtering endpoint provides several advanced features:

#### Empty Day Handling
- All dates in the specified range are included in the response
- Days with no matching irrigations show zero values for all metrics
- Ensures consistent data structure for frontend consumption

#### Multi-Valve Calculations
- When multiple valves are used in a single irrigation, volumes are calculated per valve
- Total volume is the sum of all valve volumes for each irrigation
- Volume per hectare accounts for different irrigation areas per valve

#### Status Filtering
- Only finished irrigations are included in calculations
- Pending or cancelled irrigations are excluded from reports
- Ensures accurate reporting of completed work only

## Error Responses
All endpoints may return the following error responses:

### Filter Reports Validation Errors
The filtering endpoint has specific validation requirements:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "plot_id": [
      "The plot id field is required.",
      "The selected plot id is invalid."
    ],
    "from_date": [
      "The from date field is required.",
      "The from date does not match the format Y/m/d."
    ],
    "to_date": [
      "The to date field is required.",
      "The to date must be a date after or equal to from date."
    ],
    "labour_id": [
      "The selected labour id is invalid."
    ],
    "valves": [
      "The valves must be an array.",
      "The valves must have at least 1 items."
    ],
    "valves.0": [
      "The selected valves.0 is invalid."
    ]
  },
  "status": 422
}
```

### Common Error Responses

```json
{
  "message": "Validation error",
  "errors": {
    "field_name": [
      "Error message"
    ]
  },
  "status": 422
}
```

```json
{
  "message": "Unauthorized",
  "status": 401
}
```

```json
{
  "message": "Resource not found",
  "status": 404
}
```

## Notes
- All dates follow Jalali (Persian) calendar format (YYYY/MM/DD)
- Times are in 24-hour format (HH:mm) for input, HH:MM:SS for output
- Duration calculations are automatically performed based on start_time and end_time
- Volume calculations require valve specifications (dripper_count, dripper_flow_rate, irrigation_area)
- The API uses Laravel's API resource conventions for consistent request/response handling
- Responses include permission checks through the "can" attribute
- List endpoints use pagination with 10 items per page by default
- All relationships (labour, valves, plots, pump) are properly loaded when viewing single irrigation records
- Reports support filtering by date range, labour, and specific valves
- Only finished irrigations are included in report calculations
