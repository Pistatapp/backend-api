# Search API Documentation

## Overview

The Search API provides a unified endpoint for searching across multiple resource types in the application. It uses a centralized `SearchService` that can be extended to support any resource type.

## Base URL

```
/api/search
```

## Authentication

All search endpoints require authentication using Laravel Sanctum. Include the Bearer token in the Authorization header:

```
Authorization: Bearer {your-token}
```

---

## Global Search Endpoint

### `GET /api/search`

Search for resources across all types or a specific resource type.

### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `q` | string | Yes | The search query (1-255 characters) |
| `type` | string | No | The specific resource type to search. If omitted, searches all types. |
| `filters` | object | No | Additional filters to apply to the search |
| `filters.farm_id` | integer | No | Filter results by farm ID (for labours, teams, maintenances) |
| `filters.crop_id` | integer | No | Filter results by crop ID (for crop_types) |
| `filters.active` | boolean | No | Filter by active status (for crops, crop_types) |

### Available Resource Types

- `users` - Search users by mobile number
- `crops` - Search crops by name
- `crop_types` - Search crop types by name
- `labours` - Search labours by name, personnel number, or mobile
- `teams` - Search teams by name
- `maintenances` - Search maintenances by name

### Response Format

#### Single Type Search

When searching a specific type (with `type` parameter):

```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      // ... other resource fields
    }
  ],
  "meta": {
    "query": "john",
    "type": "users",
    "count": 1
  }
}
```

#### Multi-Type Search

When searching all types (without `type` parameter):

```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "mobile": "09123456789",
        // ... other user fields
      }
    ],
    "labours": [
      {
        "id": 5,
        "name": "John Worker",
        // ... other labour fields
      }
    ]
  },
  "meta": {
    "query": "john",
    "types": ["users", "labours"],
    "total_count": 2,
    "counts_by_type": {
      "users": 1,
      "labours": 1
    }
  }
}
```

### Examples

#### Example 1: Search All Resource Types

**Request:**
```http
GET /api/search?q=john
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "mobile": "09123456789",
        "profile": {
          "name": "John Doe"
        }
      }
    ],
    "labours": [
      {
        "id": 5,
        "name": "John Worker",
        "personnel_number": "EMP001",
        "mobile": "09187654321"
      }
    ]
  },
  "meta": {
    "query": "john",
    "types": ["users", "labours"],
    "total_count": 2,
    "counts_by_type": {
      "users": 1,
      "labours": 1
    }
  }
}
```

#### Example 2: Search Specific Resource Type

**Request:**
```http
GET /api/search?q=apple&type=crops
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 3,
      "name": "Apple",
      "is_active": true,
      "cold_requirement": 800,
      "created_by": 1
    }
  ],
  "meta": {
    "query": "apple",
    "type": "crops",
    "count": 1
  }
}
```

#### Example 3: Search with Filters

**Request:**
```http
GET /api/search?q=team&type=teams&filters[farm_id]=5
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 2,
      "name": "Harvest Team A",
      "farm_id": 5,
      "supervisor_id": 10,
      "labours_count": 8
    }
  ],
  "meta": {
    "query": "team",
    "type": "teams",
    "count": 1
  }
}
```

#### Example 4: Search Active Crops Only

**Request:**
```http
GET /api/search?q=orange&type=crops&filters[active]=1
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 7,
      "name": "Orange",
      "is_active": true,
      "cold_requirement": 600,
      "created_by": null
    }
  ],
  "meta": {
    "query": "orange",
    "type": "crops",
    "count": 1
  }
}
```

---

## Resource-Specific Search

You can also use the existing resource endpoints with the `search` parameter. These endpoints automatically use the `SearchService` internally.

### Users Search

**Endpoint:** `GET /api/users?search={query}`

**Searchable Fields:**
- `mobile` - User's mobile number

**Example:**
```http
GET /api/users?search=0912
Authorization: Bearer {token}
```

---

### Crops Search

**Endpoint:** `GET /api/crops?search={query}`

**Searchable Fields:**
- `name` - Crop name

**Additional Parameters:**
- `active` (0|1) - Filter by active status

**Example:**
```http
GET /api/crops?search=apple&active=1
Authorization: Bearer {token}
```

---

### Crop Types Search

**Endpoint:** `GET /api/crops/{crop_id}/crop_types?search={query}`

**Searchable Fields:**
- `name` - Crop type name

**Additional Parameters:**
- `active` (0|1) - Filter by active status

**Example:**
```http
GET /api/crops/3/crop_types?search=golden&active=1
Authorization: Bearer {token}
```

---

### Labours Search

**Endpoint:** `GET /api/farms/{farm_id}/labours?search={query}`

**Searchable Fields:**
- `name` - Labour name
- `personnel_number` - Personnel/employee number
- `mobile` - Mobile number

**Example:**
```http
GET /api/farms/5/labours?search=john
Authorization: Bearer {token}
```

---

### Teams Search

**Endpoint:** `GET /api/farms/{farm_id}/teams?search={query}`

**Searchable Fields:**
- `name` - Team name

**Example:**
```http
GET /api/farms/5/teams?search=harvest
Authorization: Bearer {token}
```

---

### Maintenances Search

**Endpoint:** `GET /api/farms/{farm_id}/maintenances?search={query}`

**Searchable Fields:**
- `name` - Maintenance name

**Example:**
```http
GET /api/farms/5/maintenances?search=tractor
Authorization: Bearer {token}
```

---

## Error Responses

### 400 Bad Request - Invalid Resource Type

```json
{
  "message": "Resource type 'invalid_type' is not supported. Available types: users, crops, crop_types, labours, teams, maintenances",
  "available_types": [
    "users",
    "crops",
    "crop_types",
    "labours",
    "teams",
    "maintenances"
  ]
}
```

### 422 Unprocessable Entity - Validation Error

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "q": [
      "The q field is required."
    ]
  }
}
```

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 500 Internal Server Error

```json
{
  "message": "An error occurred while searching.",
  "error": "Detailed error message (only in debug mode)"
}
```

---

## Permission & Authorization

### User Scoping

The search results are automatically scoped based on the authenticated user's role and permissions:

#### Users
- **Root users**: Can search all users
- **Admin/Super-admin users**: Can only search users they created
- **Other users**: Limited to their working environment

#### Crops & Crop Types
- **Root users**: Can search global resources (created_by = null)
- **Other users**: Can search their own resources and global resources

#### Labours, Teams, Maintenances
- Results are automatically filtered by the specified `farm_id`
- Users must have appropriate permissions to access the farm

---

## Extending the Search Service

The `SearchService` is designed to be extensible. To add a new resource type:

### 1. Register the Resource Type

In your service provider or controller:

```php
$searchService->registerResourceType('new_resource', [
    'model' => \App\Models\NewResource::class,
    'searchable_columns' => ['name', 'description'],
    'resource' => \App\Http\Resources\NewResourceResource::class,
    'with' => ['relation1', 'relation2'],
    'scope_method' => 'scopeNewResource',
]);
```

### 2. Add Scope Method

In the `SearchService` class, add a scope method:

```php
protected function scopeNewResource(Builder $query, User $user, array $filters): Builder
{
    // Apply user-specific filtering
    if (isset($filters['some_filter'])) {
        $query->where('some_column', $filters['some_filter']);
    }
    
    return $query;
}
```

### 3. Update Validation Rules

In `SearchController`, add the new type to validation:

```php
'type' => 'nullable|string|in:users,crops,crop_types,labours,teams,maintenances,new_resource',
```

---

## Best Practices

### 1. Use Specific Type When Possible
For better performance, specify the `type` parameter when you know what you're searching for:

```http
GET /api/search?q=john&type=users
```

### 2. Apply Filters
Use filters to narrow down results:

```http
GET /api/search?q=team&type=teams&filters[farm_id]=5
```

### 3. Use Resource-Specific Endpoints
For resource-specific searches within a context (e.g., labours in a farm), use the resource endpoint:

```http
GET /api/farms/5/labours?search=john
```

### 4. Minimum Query Length
Keep search queries meaningful (minimum 1 character, but 2-3 characters recommended for better results).

### 5. Handle Empty Results
Always check if results are empty before processing:

```javascript
if (response.data.data.length === 0) {
  // Handle no results
}
```

---

## JavaScript/TypeScript Examples

### Using Axios

```javascript
import axios from 'axios';

// Search all types
async function searchAll(query) {
  try {
    const response = await axios.get('/api/search', {
      params: { q: query },
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
    return response.data;
  } catch (error) {
    console.error('Search failed:', error);
  }
}

// Search specific type with filters
async function searchLabours(query, farmId) {
  try {
    const response = await axios.get('/api/search', {
      params: {
        q: query,
        type: 'labours',
        filters: { farm_id: farmId }
      },
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
    return response.data;
  } catch (error) {
    console.error('Search failed:', error);
  }
}
```

### Using Fetch API

```javascript
// Search crops with active filter
async function searchActiveCrops(query) {
  const params = new URLSearchParams({
    q: query,
    type: 'crops',
    'filters[active]': '1'
  });
  
  const response = await fetch(`/api/search?${params}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  if (!response.ok) {
    throw new Error('Search failed');
  }
  
  return await response.json();
}
```

---

## Rate Limiting

The search endpoint is subject to the same rate limiting as other API endpoints. If you exceed the rate limit, you'll receive a `429 Too Many Requests` response.

---

## Support

For questions or issues with the Search API, please contact the development team or create an issue in the project repository.
