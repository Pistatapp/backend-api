# Tractor Report Filtering Documentation

## Overview
The tractor report filtering feature allows users to filter tractor reports based on various criteria such as date range, operation, and field. This feature helps users find specific reports more efficiently.

## Endpoint

```http
POST /api/tractor_reports/filter
```

## Request Body

| Parameter    | Type    | Required | Description                                           |
|-------------|---------|----------|-------------------------------------------------------|
| tractor_id  | integer | Yes      | ID of the tractor to filter reports for              |
| from_date   | string  | No*      | Start date in Jalali format (e.g., "1404/01/01")    |
| to_date     | string  | No*      | End date in Jalali format (e.g., "1404/01/30")      |
| operation_id| integer | No       | ID of the operation to filter by                     |
| field_id    | integer | No       | ID of the field to filter by                         |

\* `from_date` and `to_date` are required together. If one is provided, the other must also be provided.

## Validation Rules

- `tractor_id`: Must exist in the tractors table
- `from_date`: Must be a valid Shamsi (Jalali) date
- `to_date`: Must be a valid Shamsi (Jalali) date and must be after or equal to from_date
- `operation_id`: Must exist in the operations table
- `field_id`: Must exist in the fields table

## Response

Returns a collection of tractor reports that match the filter criteria.

### Success Response Structure

```json
{
    "data": [
        {
            "id": integer,
            "tractor_id": integer,
            "date": string,
            "start_time": string,
            "end_time": string,
            "description": string,
            "created_by": integer,
            "operation": {
                "id": integer,
                "name": string
                // ...other operation fields
            },
            "field": {
                "id": integer,
                "name": string
                // ...other field fields
            }
        }
        // ...more reports
    ]
}
```

## Example Usage

### Request with All Filters

```http
POST /api/tractor_reports/filter
Content-Type: application/json

{
    "tractor_id": 1,
    "from_date": "1404/01/01",
    "to_date": "1404/01/30",
    "operation_id": 2,
    "field_id": 3
}
```

### Request with Only Required Filter

```http
POST /api/tractor_reports/filter
Content-Type: application/json

{
    "tractor_id": 1
}
```

### Request with Date Range

```http
POST /api/tractor_reports/filter
Content-Type: application/json

{
    "tractor_id": 1,
    "from_date": "1404/01/01",
    "to_date": "1404/01/30"
}
```

## Error Responses

### Validation Error (422 Unprocessable Entity)
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "tractor_id": [
            "The tractor id field is required."
        ],
        "to_date": [
            "The to date field is required when from date is present."
        ]
    }
}
```

### Not Found Error (404 Not Found)
```json
{
    "message": "Resource not found."
}
```

## Notes

- The response is paginated and ordered by the most recent reports first
- All dates are handled in Jalali (Shamsi) calendar format
- The feature automatically converts Jalali dates to Gregorian for database operations
- Multiple filters can be combined to narrow down the results
