# Sliders API Documentation

## Get All Sliders
Retrieves a list of all sliders.

**URL:** `/api/sliders`

**Method:** `GET`

**Query Parameters:**
- `page` (optional): Filter sliders by page name (e.g., 'home', 'about')

**Response:** `200 OK`
```json
{
    "data": [
        {
            "id": 1,
            "name": "Home Slider",
            "images": [
                {
                    "sort_order": 1,
                    "url": "http://example.com/storage/slides/image1.jpg"
                }
            ],
            "page": "home",
            "is_active": true,
            "interval": 5
        }
    ]
}
```

## Get Single Slider
Retrieves details of a specific slider.

**URL:** `/api/sliders/{id}`

**Method:** `GET`

**Response:** `200 OK`
```json
{
    "data": {
        "id": 1,
        "name": "Home Slider",
        "images": [
            {
                "sort_order": 1,
                "url": "http://example.com/storage/slides/image1.jpg"
            }
        ],
        "page": "home",
        "is_active": true,
        "interval": 5
    }
}
```

## Create Slider
Creates a new slider. Only accessible by users with 'root' role.

**URL:** `/api/sliders`

**Method:** `POST`

**Headers:**
```
Content-Type: multipart/form-data
```

**Body Parameters:**
- `name` (required): string - Unique name for the slider
- `page` (required): string - Page where the slider will be displayed
- `is_active` (required): boolean - Slider activation status
- `interval` (required): integer - Slide transition interval in seconds
- `images` (required): array - Array of images
  - `sort_order` (required): integer - Order of the image in the slider
  - `file` (required): file - Image file (max: 2048KB)

**Response:** `201 Created`
```json
{
    "data": {
        "id": 1,
        "name": "New Slider",
        "images": [
            {
                "sort_order": 1,
                "url": "http://example.com/storage/slides/new-image.jpg"
            }
        ],
        "page": "home",
        "is_active": true,
        "interval": 5
    }
}
```

## Update Slider
Updates an existing slider. Only accessible by users with 'root' role.

**URL:** `/api/sliders/{id}`

**Method:** `PUT`

**Headers:**
```
Content-Type: multipart/form-data
```

**Body Parameters:**
- `name` (required): string - Unique name for the slider
- `page` (required): string - Page where the slider will be displayed
- `is_active` (required): boolean - Slider activation status
- `interval` (required): integer - Slide transition interval in seconds
- `images` (required): array - Array of images
  - `sort_order` (required): integer - Order of the image in the slider
  - `file` (optional): file - New image file (max: 2048KB)

**Response:** `200 OK`
```json
{
    "data": {
        "id": 1,
        "name": "Updated Slider",
        "images": [
            {
                "sort_order": 1,
                "url": "http://example.com/storage/slides/updated-image.jpg"
            }
        ],
        "page": "about",
        "is_active": false,
        "interval": 3
    }
}
```

## Delete Slider
Deletes a slider. Only accessible by users with 'root' role.

**URL:** `/api/sliders/{id}`

**Method:** `DELETE`

**Response:** `204 No Content`

## Authorization
- GET endpoints are accessible by any authenticated user
- POST, PUT, and DELETE endpoints require 'root' role
- All endpoints require authentication via Bearer token
