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
                "operation_name": "string",
                "field_name": "string",
                "traveled_distance": "number",
                "min_speed": "number",
                "max_speed": "number",
                "avg_speed": "number",
                "work_duration": "number",
                "stoppage_duration": "number",
                "stoppage_count": "number"
            }
        ],
        "accumulated": {
            "traveled_distance": "number",
            "min_speed": "number",
            "max_speed": "number",
            "avg_speed": "number",
            "work_duration": "number",
            "stoppage_duration": "number",
            "stoppage_count": "number"
        },
        "expectations": {
            "expected_daily_work": "number",
            "total_work_duration": "number",
            "total_efficiency": "number"
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
- Work duration and stoppage duration are returned in seconds
- Speeds are returned in kilometers per hour
- Distances are returned in kilometers
- Efficiency is calculated as a percentage (0-100)
