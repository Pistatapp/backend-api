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
                "operation_name": "string",
                "field_name": "string",
                "traveled_distance": "100.00",
                "min_speed": "20.00",
                "max_speed": "50.00",
                "avg_speed": "35.00",
                "work_duration": "08:30:00",
                "stoppage_duration": "00:15:00",
                "stoppage_count": 5,
                "consumed_water": "150.00",
                "consumed_fertilizer": "25.50",
                "consumed_poison": "10.00",
                "operation_area": "500.00",
                "workers_count": 2
            }
        ],
        "accumulated": {
            "traveled_distance": "300.00",
            "min_speed": "15.00",
            "max_speed": "60.00",
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

## Notes

- All dates should be provided in Shamsi (Persian) calendar format
- Work duration and stoppage duration are returned in H:i:s format (hours:minutes:seconds)
- Speeds are returned in kilometers per hour with 2 decimal places
- Distances are returned in kilometers with 2 decimal places
- Volumes (water, poison) are returned in liters with 2 decimal places
- Weights (fertilizer) are returned in kilograms with 2 decimal places
- Areas are returned in square meters with 2 decimal places
- Efficiency is calculated as a percentage with 2 decimal places (0-100)
- Counts (stoppage_count, workers_count) are returned as integers
