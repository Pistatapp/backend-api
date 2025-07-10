# Valves API

This document outlines the available API endpoints for managing valves in the irrigation system.

## Overview
Valves are essential components of the irrigation system that control water flow to specific areas. Each valve has properties that are used for calculating irrigation volumes, including dripper count, flow rate, and irrigation area.

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

## Irrigation Volume Calculations

Valves are integral to irrigation volume calculations. The system uses the following valve properties:

### Key Properties for Calculations
- **dripper_count**: Number of drippers connected to the valve
- **dripper_flow_rate**: Flow rate per dripper in liters per hour
- **irrigation_area**: Area covered by the valve in hectares

### Volume Calculation Formula
```
Volume (liters) = dripper_count × dripper_flow_rate × duration_in_hours
Volume per hectare = Volume / irrigation_area
```

### Example Calculation
For a valve with:
- 500 drippers
- 4.5 liters/hour flow rate per dripper
- 2.5 hectares irrigation area
- 2 hours irrigation duration

```
Volume = 500 × 4.5 × 2 = 4,500 liters
Volume per hectare = 4,500 / 2.5 = 1,800 liters/hectare
```

## Valve Naming in Reports

When valves are used in irrigation reports:
- If a valve has a `name`, it will be used as the key in valve-specific reports
- If a valve has no `name`, the system will use the format `"valve{id}"` as the key
- This ensures consistent identification of valves across different report types

## Validation Rules

- `name`: Required, string, maximum 255 characters
- `location`: Required, must be an object with lat and lng coordinates
- `irrigation_area`: Required, numeric, minimum 0 (in hectares)
- `dripper_count`: Required, integer, minimum 0
- `dripper_flow_rate`: Required, numeric, minimum 0 (liters/hour per dripper)
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
        ],
        "irrigation_area": [
            "The irrigation area field is required."
        ],
        "dripper_count": [
            "The dripper count field is required."
        ],
        "dripper_flow_rate": [
            "The dripper flow rate field is required."
        ]
    }
}
```

## Integration with Irrigation System

Valves are tightly integrated with the irrigation system:

1. **Irrigation Creation**: When creating irrigations, valves must be specified to calculate water usage
2. **Volume Calculations**: All irrigation volume calculations depend on valve specifications
3. **Report Generation**: Valve-specific reports show individual valve performance and usage
4. **Filtering**: Irrigation reports can be filtered by specific valves to analyze performance

## Best Practices

1. **Accurate Specifications**: Ensure dripper_count, dripper_flow_rate, and irrigation_area are accurate for proper volume calculations
2. **Meaningful Names**: Use descriptive names for valves to make reports more readable
3. **Location Tracking**: Maintain accurate location data for field management
4. **Regular Updates**: Update valve specifications when physical changes are made to the irrigation system 
