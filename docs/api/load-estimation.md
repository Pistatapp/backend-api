# Load Estimation API

Endpoints for reading and updating per–crop-type load estimation tables (reference data), and for estimating orchard yield for a field on a farm. All routes require an authenticated user (Laravel Sanctum) and the `ensure.username` middleware (the user must have a username set).

**Base path:** `/api`

**Headers (typical):**

```http
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json
```

---

## 1. Get load estimation table for a crop type

Returns the persisted `load_estimation_tables` record linked to the crop type (headers, rows, metadata). If no table has been stored yet, `data` may be `null`.

### Endpoint

```http
GET /api/crop_types/{crop_type}/load_estimation
```

### URL parameters

| Parameter    | Type    | Required | Description                          |
|-------------|---------|----------|--------------------------------------|
| `crop_type` | integer | Yes      | Crop type ID (route model binding). |

### Request body

None.

### Success response

**`200 OK`**

```json
{
  "data": {
    "id": 1,
    "crop_type_id": 5,
    "headers": ["..."],
    "rows": [
      {
        "condition": "excellent",
        "fruit_cluster_weight": "120",
        "average_bud_count": "0",
        "bud_to_fruit_conversion": "0.8",
        "estimated_to_actual_yield_ratio": "0.9",
        "tree_yield_weight_grams": "5000",
        "tree_weight_kg": "5",
        "tree_count": "100",
        "total_garden_yield_kg": "500"
      }
    ],
    "created_at": "...",
    "updated_at": "..."
  }
}
```

When there is no related row in the database, the backend may return:

```json
{
  "data": null
}
```

### Row shape (as stored)

Rows are JSON objects. Keys align with the update endpoint (see section 2). Values may be strings or numbers depending on how they were saved; the API accepts numeric types on write.

---

## 2. Update load estimation table for a crop type

Replaces the `rows` array on the crop type’s load estimation table (creates or updates the single related record).

### Endpoint

```http
PUT /api/crop_types/{crop_type}/load_estimation
```

or

```http
PATCH /api/crop_types/{crop_type}/load_estimation
```

### Authorization

Only users with the **`root`** role may call this action (`role:root` middleware). Others receive **`403 Forbidden`**.

### URL parameters

| Parameter    | Type    | Required | Description   |
|-------------|---------|----------|---------------|
| `crop_type` | integer | Yes      | Crop type ID. |

### JSON body

| Field   | Type  | Required | Description |
|---------|-------|----------|-------------|
| `rows`  | array | Yes      | One object per orchard condition row (see below). |

#### Each element of `rows`

| Field                              | Type    | Required | Rules |
|------------------------------------|---------|----------|--------|
| `condition`                        | string  | Yes      | One of: `excellent`, `good`, `normal`, `bad`. |
| `fruit_cluster_weight`             | number  | Yes      | ≥ 0. |
| `average_bud_count`                | integer | No       | ≥ 0 if present. |
| `bud_to_fruit_conversion`          | number  | Yes      | ≥ 0. |
| `estimated_to_actual_yield_ratio`  | number  | Yes      | ≥ 0. |
| `tree_yield_weight_grams`          | integer | Yes      | ≥ 0. |
| `tree_weight_kg`                   | integer | No       | ≥ 0 if present. |
| `tree_count`                       | integer | No       | ≥ 0 if present. |
| `total_garden_yield_kg`            | number  | No       | ≥ 0 if present. |

### Success response

**`200 OK`** with an empty JSON object:

```json
{}
```

### Validation errors

**`422 Unprocessable Entity`** — Laravel validation error payload (`message`, `errors`).

---

## 3. Estimate yield for a field (farm context)

Computes estimated yield per tree and total estimated yield for each orchard quality band (`excellent`, `good`, `normal`, `bad`) using:

- The **load estimation table** for the **crop type** linked to the given **field**.
- Request inputs: average bud count, tree count, and optional cluster weight override.

### Endpoint

```http
POST /api/farms/{farm}/load_estimation
```

The `farm` ID is required by the URL; the calculation uses **`field_id`** from the body to resolve the field (and thus its crop type). The client should send a `field_id` that belongs to the same farm.

### URL parameters

| Parameter | Type    | Required | Description |
|-----------|---------|----------|-------------|
| `farm`    | integer | Yes      | Farm ID.    |

### JSON body

| Field                | Type    | Required | Rules |
|----------------------|---------|----------|--------|
| `field_id`           | integer | Yes      | Must exist in `fields.id`. |
| `average_bud_count`  | integer | Yes      | ≥ 0. |
| `tree_count`         | integer | Yes      | ≥ 0. |
| `cluster_weight`     | number  | No       | ≥ 0 if present. When omitted, each condition uses that row’s `fruit_cluster_weight` from the stored table. |

### Success response

**`200 OK`**

```json
{
  "data": {
    "excellent": {
      "estimated_yield_per_tree_kg": 12,
      "estimated_yield_total_kg": 1200
    },
    "good": {
      "estimated_yield_per_tree_kg": 10,
      "estimated_yield_total_kg": 1000
    },
    "normal": {
      "estimated_yield_per_tree_kg": 8,
      "estimated_yield_total_kg": 800
    },
    "bad": {
      "estimated_yield_per_tree_kg": 5,
      "estimated_yield_total_kg": 500
    }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `estimated_yield_per_tree_kg` | integer | Per-tree estimate for that condition; backend uses **`ceil`** on the per-tree kg value. |
| `estimated_yield_total_kg`   | number  | `estimated_yield_per_tree_kg` × `tree_count`; backend uses **`round`**. |

### Calculation (for product understanding)

For each condition row, let `W` = `cluster_weight` from the request if provided, else `fruit_cluster_weight` from the row.

Per-tree grams (before rounding):

`average_bud_count × W × bud_to_fruit_conversion × estimated_to_actual_yield_ratio`

Per-tree kg = that value ÷ 1000, then **ceiled** for the API field. Total kg = per-tree kg × `tree_count`, then **rounded**.

### Error cases

| Status | When |
|--------|------|
| **`404`** | Field not found, or no load estimation table exists for the field’s crop type (`firstOrFail` on the table). |
| **`422`** | Validation failure (invalid `field_id`, types, or constraints). |
| **`401`** | Missing or invalid authentication. |

If the stored table is missing a row for one of the four conditions, that condition may be absent from the `data` object; in normal operation the table should contain all four.

---

## Related: `load_estimation_data` on crop types

Creating or updating a **crop type** via `POST/PUT` crop type endpoints can include optional `load_estimation_data` (validated by `LoadEstimationData` rule). That is **separate** from the singleton `crop_types/{id}/load_estimation` resource documented above, which persists the `load_estimation_tables` relation used by the estimate endpoint. Frontend teams should confirm with product which source the UI should display or edit.

---

## Quick reference

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/crop_types/{crop_type}/load_estimation` | Read table |
| `PUT` / `PATCH` | `/api/crop_types/{crop_type}/load_estimation` | Update rows (root only) |
| `POST` | `/api/farms/{farm}/load_estimation` | Estimate yields for a field |
