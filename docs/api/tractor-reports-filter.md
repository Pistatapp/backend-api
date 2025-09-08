# Tractor Reports Filter API Documentation

This API endpoint allows you to filter and retrieve tractor reports based on various criteria.

## Endpoint

```http
POST /api/tractors/filter_reports
```

## Request Parameters

| Parameter  | Type    | Description                                           | Required |
|------------|---------|-------------------------------------------------------|----------|
| tractor_id | integer | ID of the tractor to filter reports for              | Yes      |
| date       | string  | Specific date in Y/m/d format                        | Yes*     |
| period     | string  | Type of period to filter by                          | No       |
| month      | string  | Required when period is 'specific_month'             | No       |
| year       | string  | Required when period is 'persian_year'               | No       |
| operation  | integer | Operation ID to filter by                            | No       |

*Note: Either 'date' or 'period' must be provided

### Period Options
- `month`: Current month
- `year`: Current year
- `specific_month`: A specific month (requires 'month' parameter)
- `persian_year`: A specific Persian year (requires 'year' parameter)

## Response

```json
{
    "data": {
        "reports": [
            {
                "date": "1403/10/15",
                "traveled_distance": "100.00",
                "avg_speed": "35.00",
                "work_duration": "08:30:00",
                "stoppage_duration": "00:15:00",
                "stoppage_count": 5,
                "task": {
                    "operation": {
                        "id": 1,
                        "name": "Plowing"
                    },
                    "taskable": {
                        "id": 1,
                        "name": "Field A",
                        "type": "Field"
                    },
                    "consumed_water": "150.00",
                    "consumed_fertilizer": "25.50",
                    "consumed_poison": "10.00",
                    "operation_area": "500.00",
                    "workers_count": 2
                }
            }
        ],
        "accumulated": {
            "traveled_distance": "300.00",
            "avg_speed": "37.50",
            "work_duration": "25:30:00",
            "stoppage_duration": "01:30:00",
            "stoppage_count": 15,
            "consumed_water": "450.00",
            "consumed_fertilizer": "75.00",
            "consumed_poison": "30.00",
            "operation_area": "1500.00",
            "workers_count": 6
        },
        "expectations": {
            "expected_daily_work": "08:00:00",
            "total_work_duration": "25:30:00",
            "total_efficiency": "85.50"
        }
    }
}
```

## Examples

### Filter by Specific Date

```http
POST /api/tractors/filter_reports
{
    "tractor_id": 1,
    "date": "1402/10/15"
}
```

### Filter by Persian Year

```http
POST /api/tractors/filter_reports
{
    "tractor_id": 1,
    "period": "persian_year",
    "year": "1402"
}
```

### Filter by Specific Month with Operation

```http
POST /api/tractors/filter_reports
{
    "tractor_id": 1,
    "period": "specific_month",
    "month": "1402/10/01",
    "operation": 5
}
```

## Response Examples

### Report with Task Data

```json
{
    "data": {
        "reports": [
            {
                "date": "1403/10/15",
                "traveled_distance": "100.00",
                "avg_speed": "35.00",
                "work_duration": "08:30:00",
                "stoppage_duration": "00:15:00",
                "stoppage_count": 5,
                "task": {
                    "operation": {
                        "id": 1,
                        "name": "Plowing"
                    },
                    "taskable": {
                        "id": 1,
                        "name": "Field A",
                        "type": "Field"
                    },
                    "consumed_water": "150.00",
                    "consumed_fertilizer": "25.50",
                    "consumed_poison": "10.00",
                    "operation_area": "500.00",
                    "workers_count": 2
                }
            }
        ],
        "accumulated": {
            "traveled_distance": "100.00",
            "avg_speed": "35.00",
            "work_duration": "08:30:00",
            "stoppage_duration": "00:15:00",
            "stoppage_count": 5,
            "consumed_water": "150.00",
            "consumed_fertilizer": "25.50",
            "consumed_poison": "10.00",
            "operation_area": "500.00",
            "workers_count": 2
        },
        "expectations": {
            "expected_daily_work": "08:00:00",
            "total_work_duration": "08:30:00",
            "total_efficiency": "106.25"
        }
    }
}
```

### Report without Task Data

```json
{
    "data": {
        "reports": [
            {
                "date": "1403/10/15",
                "traveled_distance": "50.00",
                "avg_speed": "25.00",
                "work_duration": "02:00:00",
                "stoppage_duration": "00:05:00",
                "stoppage_count": 1
            }
        ],
        "accumulated": {
            "traveled_distance": "50.00",
            "avg_speed": "25.00",
            "work_duration": "02:00:00",
            "stoppage_duration": "00:05:00",
            "stoppage_count": 1,
            "consumed_water": "0.00",
            "consumed_fertilizer": "0.00",
            "consumed_poison": "0.00",
            "operation_area": "0.00",
            "workers_count": 0
        },
        "expectations": {
            "expected_daily_work": "08:00:00",
            "total_work_duration": "02:00:00",
            "total_efficiency": "25.00"
        }
    }
}
```

## Error Responses

### 422 Validation Error

Returned when:
- Required parameters are missing
- Invalid date format
- Invalid period value
- Missing dependent parameters (e.g., month for specific_month period)

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "field": ["Error message"]
    }
}
```

### 404 Not Found

Returned when the specified tractor or operation does not exist.

### 403 Forbidden

Returned when the user doesn't have permission to access the tractor's reports.

## Response Structure

### Report Object
Each report in the `reports` array contains:

- **Basic GPS Data** (top level):
  - `date`: Report date in Y/m/d format
  - `traveled_distance`: Total distance traveled in kilometers
  - `avg_speed`: Average speed in km/h
  - `work_duration`: Total working time in H:i:s format
  - `stoppage_duration`: Total stoppage time in H:i:s format
  - `stoppage_count`: Number of stoppages

- **Task Data** (nested in `task` object):
  - `operation`: Operation details with `id` and `name`
  - `taskable`: Taskable entity details with `id`, `name`, and `type`
  - `consumed_water`: Water consumption in liters
  - `consumed_fertilizer`: Fertilizer consumption in kilograms
  - `consumed_poison`: Pesticide consumption in liters
  - `operation_area`: Area covered in square meters
  - `workers_count`: Number of workers involved

### Taskable Types
The `taskable.type` field indicates the type of entity the task was performed on:
- `Field`: Agricultural field
- `Farm`: Entire farm
- `Plot`: Specific plot within a field

### Accumulated Object
The `accumulated` object contains the sum of all values across all reports in the filtered results:

- **GPS Data Accumulation**:
  - `traveled_distance`: Total distance traveled across all reports (km)
  - `avg_speed`: Average speed across all reports (km/h)
  - `work_duration`: Total working time across all reports (H:i:s format)
  - `stoppage_duration`: Total stoppage time across all reports (H:i:s format)
  - `stoppage_count`: Total number of stoppages across all reports

- **Task Data Accumulation**:
  - `consumed_water`: Total water consumption across all tasks (liters)
  - `consumed_fertilizer`: Total fertilizer consumption across all tasks (kg)
  - `consumed_poison`: Total pesticide consumption across all tasks (liters)
  - `operation_area`: Total area covered across all tasks (m²)
  - `workers_count`: Total worker count across all tasks

### Expectations Object
The `expectations` object provides performance metrics:

- `expected_daily_work`: Expected daily work duration for the tractor (H:i:s format)
- `total_work_duration`: Total actual work duration from filtered reports (H:i:s format)
- `total_efficiency`: Overall efficiency percentage (0-100) calculated based on:
  - Daily view: `(total_work_duration / expected_daily_work) * 100`
  - Monthly view: `(total_work_duration / expected_monthly_work) * 100`
  - Yearly view: `(total_work_duration / expected_yearly_work) * 100`

## Notes

- All dates should be provided in Shamsi (Persian) calendar format
- Work duration and stoppage duration are returned in H:i:s format (hours:minutes:seconds)
- Speeds are returned in kilometers per hour with 2 decimal places
- Distances are returned in kilometers with 2 decimal places
- Volumes (water, poison) are returned in liters with 2 decimal places
- Weights (fertilizer) are returned in kilograms with 2 decimal places
- Areas are returned in square meters with 2 decimal places
- Counts (stoppage_count, workers_count) are returned as integers
- The `task` object is only present when there's an associated tractor task for the report
- When no tasks are associated with reports, task-related accumulated values (consumed_water, consumed_fertilizer, etc.) will be 0
- Efficiency is capped at 100% maximum value
