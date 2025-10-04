# Farm Plan Filtering API Documentation

## Overview

The Farm Plan Filtering API allows you to filter farm plans based on date ranges and treatables (fields, rows, or trees). This endpoint supports complex filtering criteria and returns results in a structured format with Jalali date conversion.

## Endpoint

```
POST /api/farms/{farm}/farm_plans/filter
```

## Authentication

This endpoint requires authentication. Include the Bearer token in the Authorization header:

```
Authorization: Bearer {your_token}
```

## Parameters

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | The ID of the farm to filter plans for |

### Request Body

| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `from_date` | string | No | Start date in Jalali format (YYYY/MM/DD) | `"1402/01/01"` |
| `to_date` | string | No | End date in Jalali format (YYYY/MM/DD) | `"1402/12/29"` |
| `treatable` | array | No | Array of treatables to filter by | See treatable structure below |

### Treatable Structure

Each treatable object in the `treatable` array should have the following structure:

| Field | Type | Required | Description | Valid Values |
|-------|------|----------|-------------|--------------|
| `treatable_id` | integer | Yes | ID of the treatable (field, row, or tree) | Any valid ID |
| `treatable_type` | string | Yes | Type of the treatable | `"field"`, `"row"`, `"tree"` |

## Request Examples

### 1. Filter by Date Range Only

```json
{
    "from_date": "1402/01/01",
    "to_date": "1402/06/30"
}
```

### 2. Filter by Single Treatable

```json
{
    "treatable": [
        {
            "treatable_id": 1,
            "treatable_type": "field"
        }
    ]
}
```

### 3. Filter by Multiple Treatables

```json
{
    "treatable": [
        {
            "treatable_id": 1,
            "treatable_type": "field"
        },
        {
            "treatable_id": 2,
            "treatable_type": "row"
        },
        {
            "treatable_id": 3,
            "treatable_type": "tree"
        }
    ]
}
```

### 4. Combined Filtering (Date Range + Treatables)

```json
{
    "from_date": "1402/01/01",
    "to_date": "1402/12/29",
    "treatable": [
        {
            "treatable_id": 1,
            "treatable_type": "field"
        },
        {
            "treatable_id": 2,
            "treatable_type": "row"
        }
    ]
}
```

### 5. No Filters (Get All Plans)

```json
{}
```

## Response Format

### Success Response (200 OK)

```json
{
    "data": [
        {
            "from_date": "1402/01/01",
            "to_date": "1402/12/29",
            "name": "Spring Planting Plan",
            "treatables": [
                {
                    "name": "Field A",
                    "type": "field"
                },
                {
                    "name": "Row 1",
                    "type": "row"
                }
            ]
        },
        {
            "from_date": "1402/03/01",
            "to_date": "1402/06/30",
            "name": "Summer Maintenance Plan",
            "treatables": [
                {
                    "name": "Tree 1",
                    "type": "tree"
                }
            ]
        }
    ]
}
```

### Response Structure

| Field | Type | Description |
|-------|------|-------------|
| `data` | array | Array of filtered farm plans |
| `data[].from_date` | string | Plan start date in Jalali format (YYYY/MM/DD) |
| `data[].to_date` | string | Plan end date in Jalali format (YYYY/MM/DD) |
| `data[].name` | string | Name of the farm plan |
| `data[].treatables` | array | Array of treatables associated with the plan |
| `data[].treatables[].name` | string | Name of the treatable |
| `data[].treatables[].type` | string | Type of the treatable (`field`, `row`, or `tree`) |

## Error Responses

### Validation Error (422 Unprocessable Entity)

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "from_date": [
            "The from date field must be a valid date."
        ],
        "treatable.0.treatable_id": [
            "The treatable.0.treatable id field must be an integer."
        ],
        "treatable.0.treatable_type": [
            "The selected treatable.0.treatable type is invalid."
        ]
    }
}
```

### Unauthorized (401 Unauthorized)

```json
{
    "message": "Unauthenticated."
}
```

### Not Found (404 Not Found)

```json
{
    "message": "No query results for model [App\\Models\\Farm] {farm_id}"
}
```

## Filtering Logic

### Date Range Filtering

- **`from_date`**: Filters plans where `start_date >= from_date`
- **`to_date`**: Filters plans where `end_date <= to_date`
- Both dates are automatically converted from Jalali to Gregorian format
- If only one date is provided, the other constraint is not applied

### Treatable Filtering

- Plans are included if they have **any** of the specified treatables
- Multiple treatables are combined with **OR** logic
- Treatable types are case-sensitive and must be exactly: `field`, `row`, or `tree`
- The system looks for exact matches in the `farm_plan_details` table

### Combined Filtering

When both date range and treatable filters are provided:
- Plans must match **both** the date range **AND** have at least one of the specified treatables
- This creates an **AND** relationship between date and treatable filters

## Jalali Date Format

The API uses Jalali (Persian) calendar dates in the format `YYYY/MM/DD`:

- **Year**: 4-digit Jalali year (e.g., 1402)
- **Month**: 2-digit month (01-12)
- **Day**: 2-digit day (01-31)

### Examples:
- `1402/01/01` - First day of Farvardin 1402
- `1402/06/30` - Last day of Shahrivar 1402
- `1402/12/29` - 29th day of Esfand 1402

## Rate Limiting

This endpoint is subject to the standard API rate limiting:
- **Authenticated users**: 1000 requests per hour
- **Unauthenticated users**: 60 requests per hour

## Examples

### cURL Examples

#### Filter by Date Range
```bash
curl -X POST "https://api.example.com/api/farms/1/farm_plans/filter" \
  -H "Authorization: Bearer your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "from_date": "1402/01/01",
    "to_date": "1402/06/30"
  }'
```

#### Filter by Treatables
```bash
curl -X POST "https://api.example.com/api/farms/1/farm_plans/filter" \
  -H "Authorization: Bearer your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "treatable": [
      {
        "treatable_id": 1,
        "treatable_type": "field"
      }
    ]
  }'
```

#### Combined Filtering
```bash
curl -X POST "https://api.example.com/api/farms/1/farm_plans/filter" \
  -H "Authorization: Bearer your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "from_date": "1402/01/01",
    "to_date": "1402/12/29",
    "treatable": [
      {
        "treatable_id": 1,
        "treatable_type": "field"
      },
      {
        "treatable_id": 2,
        "treatable_type": "row"
      }
    ]
  }'
```

### JavaScript/Fetch Example

```javascript
const response = await fetch('/api/farms/1/farm_plans/filter', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer your_token_here',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    from_date: '1402/01/01',
    to_date: '1402/12/29',
    treatable: [
      {
        treatable_id: 1,
        treatable_type: 'field'
      }
    ]
  })
});

const data = await response.json();
console.log(data);
```

### PHP Example

```php
$response = Http::withHeaders([
    'Authorization' => 'Bearer your_token_here',
    'Content-Type' => 'application/json',
])->post('https://api.example.com/api/farms/1/farm_plans/filter', [
    'from_date' => '1402/01/01',
    'to_date' => '1402/12/29',
    'treatable' => [
        [
            'treatable_id' => 1,
            'treatable_type' => 'field'
        ]
    ]
]);

$data = $response->json();
```

## Notes

1. **Date Conversion**: All Jalali dates are automatically converted to Gregorian dates for database queries
2. **Empty Results**: If no plans match the criteria, an empty array is returned
3. **Performance**: For large datasets, consider using specific date ranges to improve query performance
4. **Treatable Types**: The treatable types must match exactly: `field`, `row`, or `tree` (lowercase)
5. **Relationships**: The API automatically loads the necessary relationships to provide treatable names

## Related Endpoints

- `GET /api/farms/{farm}/farm_plans` - List all farm plans
- `POST /api/farms/{farm}/farm_plans` - Create a new farm plan
- `GET /api/farms/{farm}/farm_plans/{plan}` - Get a specific farm plan
- `PUT /api/farms/{farm}/farm_plans/{plan}` - Update a farm plan
- `DELETE /api/farms/{farm}/farm_plans/{plan}` - Delete a farm plan
