# Valves API

This document outlines the available API endpoints for managing valves in the irrigation system.

## List Valves

Retrieve a list of valves for a specific plot.

```http
GET /api/plots/{plot}/valves
```

### Parameters

| Parameter | Type    | In   | Description                |
|-----------|---------|------|----------------------------|
| plot      | integer | path | ID of the plot to list valves from |

### Response

```json
{
    "data": [
        {
            "id": 1,
            "plot_id": 1,
            "name": "Valve 1",
            "location": {
                "lat": 35.7219,
                "lng": 51.3347
            },
            "is_open": false,
            "irrigation_area": 2.5,
            "dripper_count": 500,
            "dripper_flow_rate": 4.5,
            "plot": {
                // Plot details when included
            }
        }
    ]
}
```

## Create Valve

Create a new valve for a specific plot.

```http
POST /api/plots/{plot}/valves
```

### Parameters

| Parameter         | Type    | In   | Description                                |
|------------------|---------|------|--------------------------------------------|
| plot             | integer | path | ID of the plot to create valve in          |
| name             | string  | body | Name of the valve                          |
| location         | object  | body | Location coordinates of the valve          |
| location.lat     | number  | body | Latitude coordinate                        |
| location.lng     | number  | body | Longitude coordinate                       |
| is_open          | boolean | body | Whether the valve is open (optional)       |
| irrigation_area  | number  | body | Area covered by irrigation in hectares     |
| dripper_count    | integer | body | Number of drippers in the valve           |
| dripper_flow_rate| number  | body | Flow rate per dripper (liters/hour)       |

### Request Example

```json
{
    "name": "Block A Valve",
    "location": {
        "lat": 35.7219,
        "lng": 51.3347
    },
    "is_open": false,
    "irrigation_area": 2.5,
    "dripper_count": 500,
    "dripper_flow_rate": 4.5
}
```

### Response

```json
{
    "data": {
        "id": 1,
        "plot_id": 1,
        "name": "Block A Valve",
        "location": {
            "lat": 35.7219,
            "lng": 51.3347
        },
        "is_open": false,
        "irrigation_area": 2.5,
        "dripper_count": 500,
        "dripper_flow_rate": 4.5
    }
}
```

## Get Single Valve

Retrieve details of a specific valve.

```http
GET /api/valves/{valve}
```

### Parameters

| Parameter | Type    | In   | Description           |
|-----------|---------|------|-----------------------|
| valve     | integer | path | ID of the valve      |

### Response

```json
{
    "data": {
        "id": 1,
        "plot_id": 1,
        "name": "Block A Valve",
        "location": {
            "lat": 35.7219,
            "lng": 51.3347
        },
        "is_open": false,
        "irrigation_area": 2.5,
        "dripper_count": 500,
        "dripper_flow_rate": 4.5
    }
}
```

## Update Valve

Update details of a specific valve.

```http
PUT/PATCH /api/valves/{valve}
```

### Parameters

| Parameter         | Type    | In   | Description                                |
|------------------|---------|------|--------------------------------------------|
| valve            | integer | path | ID of the valve to update                  |
| name             | string  | body | Name of the valve                          |
| location         | object  | body | Location coordinates of the valve          |
| location.lat     | number  | body | Latitude coordinate                        |
| location.lng     | number  | body | Longitude coordinate                       |
| is_open          | boolean | body | Whether the valve is open                  |
| irrigation_area  | number  | body | Area covered by irrigation in hectares     |
| dripper_count    | integer | body | Number of drippers in the valve           |
| dripper_flow_rate| number  | body | Flow rate per dripper (liters/hour)       |

### Request Example

```json
{
    "name": "Updated Valve Name",
    "location": {
        "lat": 35.7219,
        "lng": 51.3347
    },
    "is_open": true,
    "irrigation_area": 3.5,
    "dripper_count": 600,
    "dripper_flow_rate": 5.5
}
```

### Response

```json
{
    "data": {
        "id": 1,
        "plot_id": 1,
        "name": "Updated Valve Name",
        "location": {
            "lat": 35.7219,
            "lng": 51.3347
        },
        "is_open": true,
        "irrigation_area": 3.5,
        "dripper_count": 600,
        "dripper_flow_rate": 5.5
    }
}
```

## Delete Valve

Delete a specific valve.

```http
DELETE /api/valves/{valve}
```

### Parameters

| Parameter | Type    | In   | Description           |
|-----------|---------|------|-----------------------|
| valve     | integer | path | ID of the valve      |

### Response

```http
HTTP/1.1 204 No Content
```

## Validation Rules

- `name`: Required, string, maximum 255 characters
- `location`: Required, must be an array with lat and lng coordinates
- `irrigation_area`: Required, numeric, minimum 0
- `dripper_count`: Required, integer, minimum 0
- `dripper_flow_rate`: Required, numeric, minimum 0
- `is_open`: Optional, boolean

## Error Responses

### 404 Not Found
Returned when the requested valve or plot does not exist.

```json
{
    "message": "No query results for model [App\\Models\\Valve] {id}"
}
```

### 422 Validation Error
Returned when the request data fails validation.

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "name": [
            "The name field is required."
        ],
        "location": [
            "The location field is required."
        ]
    }
}
``` 
