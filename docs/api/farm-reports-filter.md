# Farm Reports Filter API

This endpoint allows filtering farm reports based on multiple criteria including reportable type/id, operations, labours, and date range.

## Request

```http
POST /api/farms/{farm}/farm_reports/filter
```

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `filters` | object | Object containing filter criteria |

### Filter Object Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| `reportable_type` | string | Type of reportable entity (e.g., 'field') | No |
| `reportable_id` | array | Array of reportable entity IDs | No |
| `operation_ids` | array | Array of operation IDs to filter by | No |
| `labour_ids` | array | Array of labour IDs to filter by | No |
| `date_range` | object | Date range for filtering | No |
| `date_range.from` | string | Start date in Jalali format (YYYY/MM/DD) | Required if date_range is provided |
| `date_range.to` | string | End date in Jalali format (YYYY/MM/DD) | Required if date_range is provided |

## Example Request

```json
{
  "filters": {
    "reportable_type": "field",
    "reportable_id": [1, 2],
    "operation_ids": [1, 2],
    "labour_ids": [1],
    "date_range": {
      "from": "1402/10/11",
      "to": "1402/11/11"
    }
  }
}
```

## Response

### Success Response

```json
{
  "data": [
    {
      "id": 1,
      "date": "1402/10/15",
      "value": 100,
      "description": "Report description",
      "operation": {
        "id": 1,
        "name": "Operation Name"
      },
      "labour": {
        "id": 1,
        "name": "Labour Name"
      },
      "reportable": {
        "id": 1,
        "name": "Field Name"
      },
      "creator": {
        "id": 1,
        "name": "Creator Name"
      },
      "verified": true,
      "created_at": "1402/10/15 12:30:00"
    }
  ]
}
```

### Error Response

```json
{
  "message": "The farm reports filter request is invalid.",
  "errors": {
    "filters.date_range.from": [
      "The from date field is required when date range is present."
    ]
  }
}
```

## Validation Rules

- `reportable_type`: Must be a valid model type (e.g., 'field')
- `reportable_id`: Each ID must exist in the corresponding table
- `operation_ids`: Each ID must exist in operations table
- `labour_ids`: Each ID must exist in labours table
- `date_range.from`: Must be a valid Jalali date
- `date_range.to`: Must be a valid Jalali date and after or equal to from date

## Authorization

- User must be authenticated
- User must have access to the specified farm