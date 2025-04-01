# Maintenance Reports API Documentation

This documentation outlines the available endpoints for managing maintenance reports in the PiStat system.

## List Maintenance Reports
Retrieve a paginated list of maintenance reports.

```http
GET /api/maintenance_reports
```

### Response
```json
{
    "data": [
        {
            "id": 1,
            "maintenance": {
                "id": 1,
                "name": "Oil Change"
            },
            "maintainable": {
                "id": 1,
                "name": "Tractor 1"
            },
            "maintained_by": {
                "id": 1,
                "name": "John Doe"
            },
            "date": "1402/12/25",
            "description": "Regular oil change maintenance",
            "created_at": "1402/12/25 14:30:00"
        }
    ]
}
```

## Get Single Maintenance Report
Retrieve details of a specific maintenance report.

```http
GET /api/maintenance_reports/{id}
```

### Response
```json
{
    "data": {
        "id": 1,
        "maintenance": {
            "id": 1,
            "name": "Oil Change"
        },
        "maintainable": {
            "id": 1,
            "name": "Tractor 1"
        },
        "maintained_by": {
            "id": 1,
            "name": "John Doe"
        },
        "date": "1402/12/25",
        "description": "Regular oil change maintenance",
        "created_at": "1402/12/25 14:30:00"
    }
}
```

## Create Maintenance Report
Create a new maintenance report.

```http
POST /api/maintenance_reports
```

### Request Body
```json
{
    "maintenance_id": 1,
    "maintainable_type": "tractor",
    "maintainable_id": 1,
    "maintained_by": 1,
    "date": "1402/12/25",
    "description": "Regular oil change maintenance"
}
```

### Response
```json
{
    "data": {
        "id": 1,
        "maintenance": {
            "id": 1,
            "name": "Oil Change"
        },
        "maintainable": {
            "id": 1,
            "name": "Tractor 1"
        },
        "maintained_by": {
            "id": 1,
            "name": "John Doe"
        },
        "date": "1402/12/25",
        "description": "Regular oil change maintenance",
        "created_at": "1402/12/25 14:30:00"
    }
}
```

### Validation Rules
- `maintenance_id`: Required, must exist in maintenances table
- `maintainable_type`: Required, string (e.g., 'tractor')
- `maintainable_id`: Required, integer
- `maintained_by`: Required, must exist in labours table
- `date`: Required, must be a valid Shamsi date
- `description`: Required, string, max 500 characters

## Update Maintenance Report
Update an existing maintenance report.

```http
PUT /api/maintenance_reports/{id}
```

### Request Body
```json
{
    "maintenance_id": 1,
    "maintained_by": 1,
    "date": "1402/12/25",
    "description": "Updated maintenance description"
}
```

### Response
```json
{
    "data": {
        "id": 1,
        "maintenance": {
            "id": 1,
            "name": "Oil Change"
        },
        "maintainable": {
            "id": 1,
            "name": "Tractor 1"
        },
        "maintained_by": {
            "id": 1,
            "name": "John Doe"
        },
        "date": "1402/12/25",
        "description": "Updated maintenance description",
        "created_at": "1402/12/25 14:30:00"
    }
}
```

## Delete Maintenance Report
Delete a maintenance report.

```http
DELETE /api/maintenance_reports/{id}
```

### Response
```http
204 No Content
```

## Filter Maintenance Reports
Filter maintenance reports based on various criteria.

```http
POST /api/maintenance_reports/filter
```

### Request Body
```json
{
    "from": "1404/01/01",
    "to": "1404/01/02",
    "maintainable_type": "tractor",
    "maintainable_id": 1,
    "maintained_by": 1,          // Optional
    "maintenance_id": 1          // Optional
}
```

### Response
```json
{
    "data": [
        {
            "id": 1,
            "maintenance": {
                "id": 1,
                "name": "Oil Change"
            },
            "maintainable": {
                "id": 1,
                "name": "Tractor 1"
            },
            "maintained_by": {
                "id": 1,
                "name": "John Doe"
            },
            "date": "1404/01/01",
            "description": "Regular oil change maintenance",
            "created_at": "1404/01/01 14:30:00"
        }
    ]
}
```

### Filter Validation Rules
- `from`: Required, must be a valid Shamsi date
- `to`: Required, must be a valid Shamsi date
- `maintainable_type`: Required, string
- `maintainable_id`: Required, integer
- `maintained_by`: Optional, must exist in labours table
- `maintenance_id`: Optional, must exist in maintenances table

## Authorization
- All endpoints require authentication
- User must have a working environment set
- Users can only view and modify maintenance reports they created
- Standard CRUD permissions apply through MaintenanceReportPolicy

## Notes
- All dates in the API are in Shamsi (Persian) calendar format
- The API follows RESTful conventions
- Responses are wrapped in a `data` object following JSON:API specification style
