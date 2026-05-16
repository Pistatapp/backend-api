# QR entity lookup API

Resolve a scanned payload (`unique_id`) to a farm entity and return the **same JSON shape** as that entity’s normal `show` endpoint (including eager-loaded relations). The value encoded in QR images is the plain-text `unique_id` string.

All routes require:

- **Laravel Sanctum** authentication (`Authorization: Bearer {access_token}`)
- **`ensure.username`** middleware (the user must have a username set)
- An **active** account (`is_active`); inactive users receive `403` with a deactivation message from global API middleware

**Base path:** `/api`

**Route name:** `entities.by-unique-id`

**Headers (typical):**

```http
Authorization: Bearer {access_token}
Accept: application/json
```

For `POST`, also send:

```http
Content-Type: application/json
```

---

## Resolve entity by `unique_id`

### Endpoints

Either HTTP method is supported with the same validation and response shape.

```http
GET /api/entities/by-unique-id
```

```http
POST /api/entities/by-unique-id
```

### Query parameters (`GET`)

| Parameter   | Type   | Required | Description |
|------------|--------|----------|-------------|
| `unique_id` | string | Yes      | Identifier read from the QR code (same value used when generating the QR). Max length 255. |

### JSON body (`POST`)

| Field       | Type   | Required | Description |
|------------|--------|----------|-------------|
| `unique_id` | string | Yes      | Same as the `GET` query parameter. |

Example:

```json
{
  "unique_id": "AbCdEfGhIjKlMnO"
}
```

### How resolution works

1. The server looks up `unique_id` in this **fixed order**: `fields` → `plots` → `rows` → `trees` → `farm_plans`.
2. **Unique index** applies per table; the same string could theoretically exist on more than one table. If **more than one** table contains a row with that `unique_id`, the API responds with **`409 Conflict`** (ambiguous code).
3. If **no** row matches, the API responds with **`404 Not Found`**.
4. If exactly one row is found, the server runs **`view`** authorization for that model (same rules as the resource `show` action), then returns the appropriate API resource with extra **`meta`** (see below).

### Authorization (same as each resource’s `show`)

| `meta.entity_type` | Policy summary |
|--------------------|----------------|
| `field` | User must belong to the field’s farm (`field.farm.users`). |
| `plot` | User must belong to the plot’s field’s farm. |
| `row` | User must belong to the row’s field’s farm. |
| `tree` | User must belong to the tree’s row’s field’s farm. |
| `farm_plan` | User must belong to the plan’s farm **and** have the **`view-treatment-plan-details`** permission. |

If the user is not allowed to view the resolved model, the API responds with **`403 Forbidden`**.

### Success response

**`200 OK`**

The response is a standard Laravel `JsonResource` envelope: primary attributes live under **`data`**. An extra **`meta`** object is merged at the top level (via `additional()`).

| Field | Type | Description |
|-------|------|-------------|
| `data` | object | Same structure as `GET /api/fields/{field}`, `GET /api/rows/{row}`, `GET /api/plots/{plot}`, `GET /api/trees/{tree}`, or `GET /api/farm_plans/{farm_plan}` respectively (including loaded relations for that `show` action). |
| `meta.entity_type` | string | One of: `field`, `plot`, `row`, `tree`, `farm_plan`. |
| `meta.unique_id` | string | Echo of the resolved row’s `unique_id`. |

Example (shape only; field names depend on `entity_type` and your resource definitions):

```json
{
  "data": {
    "id": 12,
    "farm_id": 3,
    "name": "North block",
    "unique_id": "AbCdEfGhIjKlMnO"
  },
  "meta": {
    "entity_type": "field",
    "unique_id": "AbCdEfGhIjKlMnO"
  }
}
```

Relation loading matches the corresponding controller `show` method (e.g. field: attachments, crop type, reports with operation/labour, counts for rows/plots/trees; tree: attachments and reports; farm plan: details with treatment and treatable; etc.).

---

## Error responses

### `401 Unauthorized`

Missing or invalid bearer token.

### `422 Unprocessable Entity`

Validation failed (e.g. missing `unique_id`).

```json
{
  "message": "The unique id field is required.",
  "errors": {
    "unique_id": ["The unique id field is required."]
  }
}
```

### `403 Forbidden`

- User cannot **`view`** the resolved model (not on the farm, or missing `view-treatment-plan-details` for a farm plan), or
- Account deactivated (`EnsureUserIsActive`), or
- Username missing when required (`ensure.username`)

Message body depends on the middleware or gate; policy denials typically follow Laravel’s default JSON error format.

### `404 Not Found`

No row with that `unique_id` was found in any of the five tables.

### `409 Conflict`

More than one table contained a row with the same `unique_id` (data inconsistency). Message indicates the client should contact support.

---

## Client notes

1. **Scanning:** Decode the QR to the **text** payload and send it as `unique_id`.
2. **Deep links:** `GET` with `?unique_id=` is convenient for universal links; `POST` is equivalent for mobile apps that prefer a JSON body.
3. **Typing:** Use `meta.entity_type` to branch UI or routing without inferring type from `data` shape alone.
