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
Retrieve filtered irrigation reports for a farm with advanced filtering capabilities including date range, multiple plots, valves, and labour filtering. This endpoint supports multiple filtering scenarios to generate comprehensive irrigation reports with aggregated daily data and accumulated totals.

- **URL**: `/farms/{farm}/irrigations/filter-reports`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)

#### Request Parameters

| Parameter  | Type    | Required | Description                                    |
|------------|---------|----------|------------------------------------------------|
| plot_ids   | array   | Yes      | Array of plot IDs to generate reports for     |
| from_date  | string  | Yes      | Start date in Jalali format (YYYY/MM/DD)      |
| to_date    | string  | Yes      | End date in Jalali format (YYYY/MM/DD)        |
| labour_id  | integer | No       | Filter by specific labour personnel            |
| valves     | array   | No       | Array of valve IDs to filter by               |

**Note**: Only finished irrigations verified by admin are included in the reports. Volume values are returned in cubic meters (m³).

#### Filtering Scenarios

##### 1. Basic Date Range Report (No Valve Filtering)
Get irrigation reports for all valves in one or more plots within a date range.

**Request Body**:
```json
{
  "plot_ids": [1, 2],
  "from_date": "1402/09/15",
  "to_date": "1402/09/17"
}
```

**Response**:
```json
{
  "data": {
    "irrigations": [
      {
        "date": "1402/09/15",
        "total_duration": "04:30:00",
        "total_volume": 2.25,
        "total_volume_per_hectare": 0.90,
        "total_count": 3
      },
      {
        "date": "1402/09/16",
        "total_duration": "02:00:00",
        "total_volume": 0.80,
        "total_volume_per_hectare": 0.32,
        "total_count": 1
      },
      {
        "date": "1402/09/17",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      }
    ],
    "accumulated": {
      "total_duration": "06:30:00",
      "total_volume": 3.05,
      "total_volume_per_hectare": 1.22,
      "total_count": 4
    }
  }
}
```

##### 2. Valve-Specific Report
Get irrigation reports filtered by specific valves.

**Request Body**:
```json
{
  "plot_ids": [1],
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "valves": [1, 3, 5]
}
```

**Response**:
```json
{
  "data": {
    "irrigations": [
      {
        "date": "1402/09/15",
        "total_duration": "03:00:00",
        "total_volume": 1.35,
        "total_volume_per_hectare": 0.54,
        "total_count": 2
      },
      {
        "date": "1402/09/16",
        "total_duration": "01:30:00",
        "total_volume": 0.68,
        "total_volume_per_hectare": 0.27,
        "total_count": 1
      },
      {
        "date": "1402/09/17",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      }
    ],
    "accumulated": {
      "total_duration": "04:30:00",
      "total_volume": 2.03,
      "total_volume_per_hectare": 0.81,
      "total_count": 3
    }
  }
}
```

##### 3. Labour-Specific Report
Get irrigation reports filtered by specific labour personnel.

**Request Body**:
```json
{
  "plot_ids": [1],
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "labour_id": 2
}
```

**Response**:
```json
{
  "data": {
    "irrigations": [
      {
        "date": "1402/09/15",
        "total_duration": "02:30:00",
        "total_volume": 1.13,
        "total_volume_per_hectare": 0.45,
        "total_count": 2
      },
      {
        "date": "1402/09/16",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      },
      {
        "date": "1402/09/17",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      }
    ],
    "accumulated": {
      "total_duration": "02:30:00",
      "total_volume": 1.13,
      "total_volume_per_hectare": 0.45,
      "total_count": 2
    }
  }
}
```

##### 4. Combined Filtering (Valves + Labour)
Get irrigation reports filtered by both specific valves and labour personnel.

**Request Body**:
```json
{
  "plot_ids": [1],
  "from_date": "1402/09/15",
  "to_date": "1402/09/17",
  "labour_id": 2,
  "valves": [1, 3]
}
```

**Response**:
```json
{
  "data": {
    "irrigations": [
      {
        "date": "1402/09/15",
        "total_duration": "01:30:00",
        "total_volume": 0.68,
        "total_volume_per_hectare": 0.27,
        "total_count": 1
      },
      {
        "date": "1402/09/16",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      },
      {
        "date": "1402/09/17",
        "total_duration": "00:00:00",
        "total_volume": 0,
        "total_volume_per_hectare": 0,
        "total_count": 0
      }
    ],
    "accumulated": {
      "total_duration": "01:30:00",
      "total_volume": 0.68,
      "total_volume_per_hectare": 0.27,
      "total_count": 1
    }
  }
}
```

#### Response Fields

**Irrigations Array:**
| Field                    | Type   | Description                                    |
|--------------------------|--------|------------------------------------------------|
| date                     | string | Date in Jalali format (YYYY/MM/DD)            |
| total_duration           | string | Total irrigation duration (HH:MM:SS)          |
| total_volume             | number | Total water volume in cubic meters (m³)       |
| total_volume_per_hectare | number | Total volume per hectare in cubic meters (m³) |
| total_count              | number | Number of irrigation sessions on this date    |

**Accumulated Object:**
| Field                    | Type   | Description                                    |
|--------------------------|--------|------------------------------------------------|
| total_duration           | string | Total duration across all dates (HH:MM:SS)    |
| total_volume             | number | Total volume across all dates (m³)            |
| total_volume_per_hectare | number | Total volume per hectare across all dates (m³)|
| total_count              | number | Total number of irrigation sessions           |

### Get Plot Irrigation Statistics
Retrieve irrigation statistics for a specific plot including latest successful irrigation and 30-day aggregated data.

- **URL**: `/plots/{plot}/irrigation-statistics`
- **Method**: `GET`
- **URL Parameters**:
  - `plot`: Plot ID (integer)
- **Response**:
  ```json
  {
    "data": {
      "plot_name": "Plot 1",
      "latest_successful_irrigation": {
        "id": 5,
        "start_date": "1402/09/20",
        "end_date": "1402/09/20",
        "start_time": "18:00",
        "end_time": "20:00"
      },
      "successful_irrigations_count_last_30_days": 12,
      "area_covered_duration_last_30_days": "24:00:00",
      "total_volume_last_30_days": 1250.75,
      "total_volume_per_hectare_last_30_days": 500.30
    }
  }
  ```

#### Response Fields
| Field                                    | Type   | Description                                    |
|------------------------------------------|--------|------------------------------------------------|
| plot_name                                | string | Name of the plot                               |
| latest_successful_irrigation             | object | Latest finished irrigation details (null if none) |
| latest_successful_irrigation.id          | integer| Irrigation ID                                  |
| latest_successful_irrigation.start_date | string | Start date in Jalali format (YYYY/MM/DD)      |
| latest_successful_irrigation.end_date    | string | End date in Jalali format (YYYY/MM/DD) or null|
| latest_successful_irrigation.start_time  | string | Start time (HH:mm)                            |
| latest_successful_irrigation.end_time   | string | End time (HH:mm)                              |
| successful_irrigations_count_last_30_days| integer| Count of finished irrigations in last 30 days |
| area_covered_duration_last_30_days      | string | Total duration in last 30 days (HH:MM:SS)     |
| total_volume_last_30_days                | number | Total volume in liters for last 30 days       |
| total_volume_per_hectare_last_30_days    | number | Total volume per hectare in liters            |

### Get Irrigation Statistics for Plot
Retrieve detailed irrigation statistics for a specific plot within an irrigation context. This endpoint provides comprehensive information about the plot, its valves, irrigation metrics, and the latest successful irrigation.

- **URL**: `/irrigations/{irrigation}/plots/{plot}`
- **Method**: `GET`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
  - `plot`: Plot ID (integer)

#### Response
```json
{
  "data": {
    "id": 1,
    "name": "Plot 1",
    "area": 2500.50,
    "tree_count": 150,
    "latest_successful_irrigation": {
      "id": 5,
      "date": "1402/09/20"
    },
    "total_valve_count": 3,
    "total_dripper_count": 1500,
    "dripper_flow_rate": 4.5,
    "irrigation_area": 2.5,
    "irrigation_duration": "02:30:00",
    "total_irrigation_area": 1.25,
    "irrigation_area_per_hectare": 0.50
  }
}
```

#### Response Fields

| Field                        | Type    | Description                                                                    |
|------------------------------|---------|--------------------------------------------------------------------------------|
| id                           | integer | Plot ID                                                                         |
| name                         | string  | Plot name                                                                       |
| area                         | number  | Plot area in square meters (calculated from coordinates)                       |
| tree_count                   | integer | Total number of trees in the plot                                               |
| latest_successful_irrigation | object  | Latest finished irrigation for this plot (null if none exists)                 |
| latest_successful_irrigation.id | integer | Irrigation ID                                                                 |
| latest_successful_irrigation.date | string | Start date in Jalali format (YYYY/MM/DD)                                      |
| total_valve_count            | integer | Total number of valves in the plot                                             |
| total_dripper_count         | integer | Sum of dripper counts from valves used in this irrigation for this plot        |
| dripper_flow_rate            | number  | Average dripper flow rate (liters/hour) for valves in this irrigation           |
| irrigation_area              | number  | Total irrigation area in hectares (sum of irrigation_area from valves)        |
| irrigation_duration         | string  | Time passed since the start of irrigation (HH:MM:SS format)                    |
| total_irrigation_area        | number  | Total irrigation volume in cubic meters (m³)                                  |
| irrigation_area_per_hectare  | number  | Irrigation volume per hectare in cubic meters per hectare (m³/ha)              |

#### Notes
- The endpoint verifies that the plot belongs to the specified irrigation. If not, it returns a 404 error.
- **irrigation_duration**: Calculated as the time difference between the irrigation start time and the current time (or end time if the irrigation is finished).
- **total_irrigation_area**: Represents the total water volume delivered during the irrigation, calculated based on valve specifications and duration. Values are in cubic meters (m³).
- **irrigation_area_per_hectare**: Calculated by dividing the total irrigation volume by the irrigation area in hectares. This metric helps understand water distribution efficiency.
- **dripper_flow_rate**: This is the average flow rate across all valves used in this irrigation for the specified plot.
- **total_dripper_count**: Only includes drippers from valves that are part of this specific irrigation and belong to the specified plot.
- If the plot has no coordinates, the `area` field will be 0.
- If there are no valves in the irrigation for this plot, `total_dripper_count`, `dripper_flow_rate`, and `irrigation_area` will be 0.

#### Error Responses

**404 Not Found** - Plot does not belong to this irrigation:
```json
{
  "message": "Plot does not belong to this irrigation."
}
```

**404 Not Found** - Irrigation or Plot not found:
```json
{
  "message": "No query results for model [App\\Models\\Irrigation] {id}"
}
```

### Get Irrigation Messages
Retrieve irrigation messages for finished irrigations of the day that have not been verified by admin.

- **URL**: `/farms/{farm}/irrigation-messages`
- **Method**: `GET`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Response**:
  ```json
  {
    "data": [
      {
        "irrigation_id": 1,
        "date": "1402/09/15",
        "plots_names": ["Plot 1", "Plot 2"],
        "valves_names": ["Valve 1", "Valve 2"],
        "duration": "02:00:00",
        "irrigation_per_hectare": 500.25,
        "total_volume": 1000.50
      }
    ]
  }
  ```

#### Response Fields
| Field                    | Type   | Description                                    |
|--------------------------|--------|------------------------------------------------|
| irrigation_id            | integer| Irrigation ID                                  |
| date                     | string | Date in Jalali format (YYYY/MM/DD)            |
| plots_names              | array  | Array of plot names                            |
| valves_names             | array  | Array of valve names                           |
| duration                 | string | Irrigation duration (HH:MM:SS)                |
| irrigation_per_hectare   | number | Volume per hectare in liters                   |
| total_volume             | number | Total volume in liters                         |

### Farm Irrigation Resource Endpoints

#### List Farm Irrigations
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `GET`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Query Parameters**:
  - `date`: Filter by date (optional, Jalali format: YYYY/MM/DD, defaults to today)
  - `status`: Filter by status (optional, e.g., 'finished', 'pending', 'all' - defaults to 'all')
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
        "start_date": "1402/09/15",
        "end_date": "1402/09/15",
        "start_time": "18:00",
        "end_time": "20:00",
        "pump": {
          "id": 1,
          "name": "Pump 1"
        },
        "valves": [
          {
            "id": 1,
            "name": "Valve 1",
            "status": "opened",
            "opened_at": "18:00",
            "closed_at": "20:00"
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
        "is_verified_by_admin": true,
        "duration": "02:00:00",
        "plots_count": 2,
        "trees_count": 150,
        "area_covered": 2.5,
        "total_volume": 1000.50,
        "can": {
          "delete": true,
          "update": true,
          "verify": false
        }
      }
    ]
  }
  ```
  
**Note**: This endpoint returns irrigations active on the specified date (or today if not specified). An irrigation is considered active if its start_date is on or before the target date, and either its end_date is on or after the target date, or end_date is null and start_date matches the target date.

#### Create Farm Irrigation
- **URL**: `/farms/{farm}/irrigations`
- **Method**: `POST`
- **URL Parameters**:
  - `farm`: Farm ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "pump_id": 1,                  // Required, integer
    "start_date": "1402/09/15",    // Required, Jalali date format: YYYY/MM/DD
    "end_date": "1402/09/15",      // Optional, Jalali date format: YYYY/MM/DD (null for single-day)
    "start_time": "18:00",         // Required, time format: HH:mm
    "end_time": "20:00",           // Required, time format: HH:mm
    "plots": [1, 2],               // Required, array of plot IDs
    "valves": [1, 2],              // Required, array of valve IDs
    "note": "Regular irrigation"   // Optional, string (max 500 characters)
  }
  ```
- **Response**: Returns the created irrigation object with HTTP 201

#### Show Single Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `GET`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**:
  ```json
  {
    "data": {
      "id": 1,
      "labour": {
        "id": 1,
        "name": "John Doe"
      },
      "start_date": "1402/09/15",
      "end_date": "1402/09/15",
      "start_time": "18:00",
      "end_time": "20:00",
      "pump": {
        "id": 1,
        "name": "Pump 1"
      },
      "valves": [
        {
          "id": 1,
          "name": "Valve 1",
          "status": "opened",
          "opened_at": "18:00",
          "closed_at": "20:00"
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
      "is_verified_by_admin": false,
      "duration": "02:00:00",
      "plots_count": 2,
      "trees_count": 150,
      "area_covered": 2.5,
      "total_volume": 1000.50,
      "can": {
        "delete": true,
        "update": true,
        "verify": true
      }
    }
  }
  ```

#### Response Fields
| Field        | Type   | Description                                    |
|--------------|--------|------------------------------------------------|
| id           | integer| Irrigation ID                                  |
| labour       | object | Labour information (if loaded)                 |
| start_date   | string | Start date in Jalali format (YYYY/MM/DD)      |
| end_date     | string | End date in Jalali format (YYYY/MM/DD) or null|
| start_time   | string | Start time (HH:mm)                            |
| end_time     | string | End time (HH:mm)                              |
| pump         | object | Pump information (if loaded)                   |
| valves       | array  | Array of valves with pivot data                |
| plots        | array  | Array of plots                                 |
| created_by   | object | Creator user information (if loaded)           |
| note         | string | Irrigation notes                               |
| status       | string | Irrigation status (pending, in-progress, finished)|
| is_verified_by_admin | boolean | Whether a farm admin has verified the irrigation |
| duration     | string | Duration in HH:MM:SS format                    |
| plots_count  | integer| Number of plots (if counted)                  |
| trees_count  | integer| Total number of trees in plots (if counted)  |
| area_covered | number | Total irrigation area in hectares             |
| total_volume | number | Total volume in liters (only for in-progress/finished)|
| can          | object | Permission flags (`delete`, `update`, `verify`) |

#### Update Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `PUT/PATCH`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Request Body**:
  ```json
  {
    "labour_id": 1,                // Required, integer
    "pump_id": 1,                  // Required, integer
    "start_date": "1402/09/15",    // Required, Jalali date format: YYYY/MM/DD
    "end_date": "1402/09/15",      // Optional, Jalali date format: YYYY/MM/DD (null for single-day)
    "start_time": "18:00",         // Required, time format: HH:mm
    "end_time": "20:00",           // Required, time format: HH:mm
    "plots": [1, 2],               // Required, array of plot IDs
    "valves": [1, 2],              // Required, array of valve IDs
    "note": "Updated notes"        // Optional, string (max 500 characters)
  }
  ```
- **Response**: Returns the updated irrigation object
- **Note**: Cannot update if irrigation is already verified by admin. For finished irrigations, only farm admin can update. For pending irrigations, creator or farm admin can update.

#### Verify Irrigation
- **URL**: `/irrigations/{irrigation}/verify`
- **Method**: `PATCH`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: Returns the updated irrigation object with `is_verified_by_admin` set to `true`
- **Notes**:
  - Only farm administrators can verify irrigations
  - Attempting to verify an irrigation that is already verified returns HTTP 422 with `{"message": "Irrigation already verified."}`
  - Verification does not modify other irrigation attributes

#### Delete Irrigation
- **URL**: `/irrigations/{irrigation}`
- **Method**: `DELETE`
- **URL Parameters**:
  - `irrigation`: Irrigation ID (integer)
- **Response**: HTTP 204 No Content
- **Note**: Can only delete irrigations with "pending" status. Only creator or farm admin can delete.

### Pump Irrigation Report Endpoints

#### Generate Pump Irrigation Report
Generate irrigation report for a specific pump within a date range. Returns daily reports with hours and volume in cubic meters, plus accumulated totals.

- **URL**: `/pumps/{pump}/irrigation-reports`
- **Method**: `POST`
- **URL Parameters**:
  - `pump`: Pump ID (integer)
- **Request Body**:
  ```json
  {
    "start_date": "1402/09/15",   // Required, Jalali date format: YYYY/MM/DD
    "end_date": "1402/09/17"      // Required, Jalali date format: YYYY/MM/DD
  }
  ```
- **Response**:
  ```json
  {
    "data": {
      "irrigations": [
        {
          "date": "1402/09/15",
          "hours": 4.5,
          "volume": 2.25
        },
        {
          "date": "1402/09/16",
          "hours": 2.0,
          "volume": 0.80
        }
      ],
      "accumulated": {
        "hours": 6.5,
        "volume": 3.05
      }
    }
  }
  ```

#### Response Fields
| Field            | Type   | Description                                    |
|------------------|--------|------------------------------------------------|
| irrigations      | array  | Daily irrigation reports                       |
| irrigations[].date| string| Date in Jalali format (YYYY/MM/DD)            |
| irrigations[].hours| number| Total hours for this date                     |
| irrigations[].volume| number| Total volume in cubic meters (m³) for this date|
| accumulated      | object | Accumulated totals across all dates           |
| accumulated.hours| number| Total hours across all dates                  |
| accumulated.volume| number| Total volume in cubic meters (m³)            |

**Note**: Only finished irrigations verified by admin are included in the report. Dates with no irrigations are excluded from the response.

## Volume Calculations
The irrigation system calculates water volume based on valve specifications:
- **Formula**: `Volume = (dripper_count × dripper_flow_rate) × duration_in_hours`
- **Volume per hectare**: `Volume / irrigation_area`
- **Total volume**: Sum of all valve volumes for each irrigation
- **Unit Conversion**: Filter reports return volumes in cubic meters (m³), while individual irrigation resources return volumes in liters
- **Note**: All volumes are calculated in liters internally, then converted to m³ for aggregated reports

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
  - When `plot_ids` is specified: Only irrigations for those plots are included (supports multiple plots)
  - When `valves` is specified: Only irrigations using those valves are included
  - When `labour_id` is specified: Only irrigations performed by that labour are included
  - When multiple filters are specified: Only irrigations matching all criteria are included
- **Verification Requirement**: Only finished irrigations verified by admin (`is_verified_by_admin = true`) are included in reports
- **Date Range**: Always includes all dates in the specified range, showing zero values for days with no matching irrigations
- **Unit Conversion**: Volume values in filter reports are converted from liters to cubic meters (m³)
- **Accumulated Totals**: All reports include accumulated totals across the entire date range

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
    "plot_ids": [
      "The plot ids field is required.",
      "The plot ids must be an array.",
      "The plot ids must have at least 1 items."
    ],
    "plot_ids.0": [
      "The selected plot ids.0 is invalid."
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

### Pump Irrigation Report Validation Errors

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "start_date": [
      "The start date is required.",
      "The start date must be a valid Jalali date in Y/m/d format."
    ],
    "end_date": [
      "The end date is required.",
      "The end date must be a valid Jalali date in Y/m/d format.",
      "The end date must be after or equal to the start date."
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
- Reports support filtering by date range, multiple plots, labour, and specific valves
- Only finished irrigations verified by admin are included in report calculations
- Filter reports return volumes in cubic meters (m³), while individual irrigation resources return volumes in liters
- Irrigations can span multiple days (start_date and end_date can differ)
- Pump ID is now required when creating or updating irrigations
- Irrigation verification status (`is_verified_by_admin`) controls whether irrigations appear in reports
- Update operations are restricted: cannot update verified irrigations; finished irrigations can only be updated by farm admins
- Delete operations are restricted: only pending irrigations can be deleted, by creator or farm admin
- Valve pivot data includes status (opened/closed), opened_at, and closed_at timestamps
- Irrigation resources include additional fields: plots_count, trees_count, area_covered, and conditional total_volume
