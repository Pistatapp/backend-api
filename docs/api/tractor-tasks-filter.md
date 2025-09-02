# Tractor Tasks Filter API

## Endpoint

```
POST /api/tractor_tasks/filter
```

## Description

Filter tractor tasks based on various criteria including date range, fields, operations, and tractor ID.

## Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `start_date` | string | Yes | Start date in Jalali format (YYYY/MM/DD) |
| `end_date` | string | Yes | End date in Jalali format (YYYY/MM/DD) |
| `fields` | array | No | Array of field IDs to filter by |
| `operations` | array | No | Array of operation IDs to filter by |
| `tractor_id` | integer | Yes | ID of the tractor to filter tasks for |

## Example Request
```json
{
    "start_date": "1403/10/11",
    "end_date": "1403/10/30",
    "tractor_id": 1,
    "fields": [1, 2, 3],
    "operations": [1, 2]
}
```

## Response

Returns a collection of `TractorTaskResource` objects containing the filtered tractor tasks.

### Response Structure

```json
{
    "data": [
        {
            "id": 1,
            "operation": {
                "id": 1,
                "name": "Plowing"
            },
            "taskable": {
                "id": 1,
                "name": "Field A",
                "coordinates": [...]
            },
            "date": "1403/10/11",
            "start_time": "08:00",
            "end_time": "10:00",
            "status": "pending",
            "consumed_water": 100,
            "consumed_fertilizer": 50,
            "consumed_poison": 25,
            "operation_area": 5.5,
            "workers_count": 3,
            "created_by": {
                "id": 1,
                "name": "John Doe"
            },
            "created_at": "1403/10/11 08:00:00"
        }
    ]
}
```

## Validation Rules

- `start_date` and `end_date` must be valid dates
- `end_date` must be after or equal to `start_date`
- `tractor_id` must exist in the tractors table
- `fields` array elements must exist in the fields table
- `operations` array elements must exist in the operations table

## Notes

- The API accepts Jalali dates and converts them to Carbon objects
- Results are ordered by date (ascending) and start time (ascending)
- The response includes related data for operations, taskable objects, and creators
