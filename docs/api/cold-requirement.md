# Cold Requirement Calculation API

This endpoint calculates the cold requirement (chilling portions) for a farm based on historical weather data.

## Endpoint

```
POST /api/v1/farms/{farm}/cold-requirement
```

## Parameters

### URL Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| farm      | integer | The ID of the farm |

### Request Body Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| start_dt  | string | Yes | - | Start date in Y-m-d format |
| end_dt    | string | Yes | - | End date in Y-m-d format |
| min_temp  | integer | No | 0 | Minimum temperature threshold (°C) |
| max_temp  | integer | No | 7 | Maximum temperature threshold (°C) |
| method    | string | No | "method1" | Calculation method ("method1" or "method2") |

## Response

```json
{
    "data": {
        "min_temp": 0,
        "max_temp": 7,
        "start_dt": "1402/09/01",
        "end_dt": "1402/09/30",
        "num_days": 30,
        "satisfied_cp": 240,
        "daily_details": [
            {
                "index": 1,
                "date": "1402/09/01",
                "min_temp": -2,
                "avg_temp": 4.2,
                "max_temp": 12,
                "hours_in_range": 8
            }
            // ... more days
        ]
    }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| min_temp | integer | Minimum temperature threshold used in calculation |
| max_temp | integer | Maximum temperature threshold used in calculation |
| start_dt | string | Start date in Jalali format (Y/m/d) |
| end_dt | string | End date in Jalali format (Y/m/d) |
| num_days | integer | Total number of days in the period |
| satisfied_cp | integer | Total calculated cold requirement (hours or portions) |
| daily_details | array | Daily breakdown of temperature statistics |
| daily_details[].index | integer | Day index (1-based) |
| daily_details[].date | string | Date in Jalali format (Y/m/d) |
| daily_details[].min_temp | float | Minimum temperature of the day |
| daily_details[].avg_temp | float | Average temperature of the day |
| daily_details[].max_temp | float | Maximum temperature of the day |
| daily_details[].hours_in_range | integer | Number of hours within temperature thresholds |

## Calculation Methods

### Method 1
Counts the number of hours where temperature falls within the specified range (min_temp to max_temp).

### Method 2
Uses a more complex physiological model to calculate chilling portions based on the Dynamic Model, considering temperature transitions and their effects on dormancy breaking.

## Example Request

```http
POST /api/v1/farms/1/cold-requirement
Content-Type: application/json

{
    "start_dt": "2023-12-01",
    "end_dt": "2023-12-30",
    "min_temp": 0,
    "max_temp": 7,
    "method": "method1"
}
```
