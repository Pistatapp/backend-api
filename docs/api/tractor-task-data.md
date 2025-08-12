# Tractor Task Data API

## Partially Update Tractor Task Data

Allows updating task-specific numeric details on a tractor task, such as consumed inputs and operation area.

```http
PATCH /api/tractor_tasks/{tractor_task_id}/data
```

### Authentication
- Requires authentication (Sanctum)
- Requires authorization to update the specified tractor task

### Path Parameters
- `tractor_task_id` (integer): ID of the task to update

### Request Body
Provide any subset of the following fields:

| Field                 | Type    | Description                          |
|-----------------------|---------|--------------------------------------|
| consumed_water        | number  | Water consumed during the task       |
| consumed_fertilizer   | number  | Fertilizer consumed during the task  |
| consumed_poison       | number  | Pesticide consumed during the task   |
| operation_area        | number  | Area covered (e.g., hectares)        |
| workers_count         | integer | Number of workers involved           |

Example:

```json
{
  "consumed_water": 120.5,
  "consumed_fertilizer": 4.2,
  "operation_area": 2.3,
  "workers_count": 3
}
```

### Responses

#### 200 OK
Returns the updated tractor task resource.

```json
{
  "data": {
    "id": 10,
    "operation": {
      "id": 1,
      "name": "Plowing"
    },
    "field": {
      "id": 7,
      "name": "Field A"
    },
    "date": "1403/05/21",
    "start_time": "08:00",
    "end_time": "12:00",
    "status": "pending",
    "data": {
      "consumed_water": 120.5,
      "consumed_fertilizer": 4.2,
      "consumed_poison": null,
      "operation_area": 2.3,
      "workers_count": 3
    },
    "created_by": {
      "id": 5,
      "name": "John Doe"
    },
    "created_at": "1403/05/21 12:15:30"
  }
}
```

#### 422 Unprocessable Entity
Validation error (e.g., negative values or invalid types).

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "consumed_water": [
      "The consumed water must be at least 0."
    ]
  }
}
```

#### 403 Forbidden / 404 Not Found
Insufficient permissions or resource not found.

---

## Full Update Including Data

The standard update endpoint also accepts a `data` object to update these fields along with other attributes.

```http
PUT /api/tractor_tasks/{tractor_task_id}
```

### Request Body (excerpt)

```json
{
  "operation_id": 1,
  "field_id": 2,
  "date": "1403/05/21",
  "start_time": "08:00",
  "end_time": "12:00",
  "data": {
    "consumed_water": 100,
    "consumed_poison": 1.2
  }
}
```

---

## Reports Including Task Data

When filtering tractor reports, the per-report items and accumulated totals now include task `data` fields if present.

```http
POST /api/tractors/filter_reports
```

### Response (excerpt)

```json
{
  "data": {
    "reports": [
      {
        "operation_name": "Plowing",
        "filed_name": "Field A",
        "traveled_distance": 100,
        "min_speed": 0,
        "max_speed": 50,
        "avg_speed": 20,
        "work_duration": 3600,
        "stoppage_duration": 1200,
        "stoppage_count": 5,
        "consumed_water": 120.5,
        "consumed_fertilizer": 4.2,
        "consumed_poison": 0,
        "operation_area": 2.3,
        "workers_count": 3
      }
    ],
    "accumulated": {
      "traveled_distance": 600,
      "min_speed": 0,
      "max_speed": 150,
      "avg_speed": 40,
      "work_duration": 21600,
      "stoppage_duration": 7200,
      "stoppage_count": 30,
      "consumed_water": 320.5,
      "consumed_fertilizer": 10.4,
      "consumed_poison": 1.2,
      "operation_area": 6.9,
      "workers_count": 9
    },
    "expectations": {
      "expected_daily_work": 28800,
      "total_work_duration": 21600,
      "total_efficiency": 75
    }
  }
}
```


