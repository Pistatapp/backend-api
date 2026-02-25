# MySQL to MongoDB Migration Guide (Laravel 12.x)

This document provides step-by-step instructions to migrate the **pistat** project from MySQL to MongoDB, following the [Laravel 12.x MongoDB documentation](https://laravel.com/docs/12.x/mongodb).

---

## Project snapshot (current state)

- **Laravel:** 12.x, **PHP:** 8.2
- **Default DB:** `mysql` (connection `mysql`)
- **Second DB:** `mysql_gps` (GPS data — used in `StoreGpsData` job)
- **Models:** 51 Eloquent models (all use default connection)
- **Migrations:** 145 SQL migrations
- **MySQL-specific code:** Raw PDO in `TractorPathStreamService`, deadlock retry in `StoreGpsData`, driver checks in several migrations, queue/cache config

---

## Phase 1: Prerequisites and MongoDB setup

### Step 1.1 — Install the MongoDB PHP extension

The `mongodb` PHP extension is required. With Laravel Herd or `php.new` it may already be installed. Otherwise install via PECL:

```bash
pecl install mongodb
```

Enable it in `php.ini` (both CLI and web server):

```ini
extension=mongodb
```

Verify:

```bash
php -m | grep mongodb
```

### Step 1.2 — Run MongoDB locally or use Atlas

- **Local:** Install [MongoDB Community Server](https://www.mongodb.com/docs/manual/installation/) and start the service.
- **Cloud:** Create a cluster on [MongoDB Atlas](https://www.mongodb.com/atlas) and add your IP to the cluster’s IP Access List.

### Step 1.3 — Install the Laravel MongoDB package

From the project root:

```bash
composer require mongodb/laravel-mongodb
```

Installation will fail if the `mongodb` PHP extension is not loaded (check both CLI and web PHP).

---

## Phase 2: Configuration

### Step 2.1 — Environment variables

Add to `.env` (and optionally to `.env.example`):

**Local MongoDB:**

```env
MONGODB_URI="mongodb://localhost:27017"
MONGODB_DATABASE="pistat"
```

**MongoDB Atlas:**

```env
MONGODB_URI="mongodb+srv://<username>:<password>@<cluster>.mongodb.net/<dbname>?retryWrites=true&w=majority"
MONGODB_DATABASE="pistat"
```

If you keep MySQL during transition, leave existing `DB_*` vars; you can switch the default later.

### Step 2.2 — Database config

In `config/database.php`, inside the `'connections' => [` array, add a `mongodb` connection:

```php
'mongodb' => [
    'driver' => 'mongodb',
    'dsn' => env('MONGODB_URI', 'mongodb://localhost:27017'),
    'database' => env('MONGODB_DATABASE', 'laravel_app'),
],
```

Optional: add a second MongoDB connection for GPS (e.g. same cluster, different database):

```php
'mongodb_gps' => [
    'driver' => 'mongodb',
    'dsn' => env('MONGODB_URI', 'mongodb://localhost:27017'),
    'database' => env('MONGODB_GPS_DATABASE', env('MONGODB_DATABASE', 'pistat_gps')),
],
```

### Step 2.3 — Switch default connection to MongoDB (when ready)

After you have migrated code and data, set:

```env
DB_CONNECTION=mongodb
```

Keep `config/database.php` default as:

```php
'default' => env('DB_CONNECTION', 'mysql'),
```

So that changing `DB_CONNECTION` in `.env` is enough to switch.

### Step 2.4 — Queue and job batching (optional MongoDB queue)

Laravel supports a [MongoDB queue driver](https://laravel.com/docs/12.x/mongodb). To use MongoDB for queues and failed jobs:

In `config/queue.php`:

- Set `'database' => env('DB_CONNECTION', 'mysql')` (or the connection that holds jobs). When `DB_CONNECTION=mongodb`, this will use MongoDB.
- Same for `config/queue.php` `batching.database` and `failed.database`.

If you keep MySQL for queues during migration, leave these pointing at `mysql` until you fully switch.

---

## Phase 3: Code changes (project-specific)

Your codebase uses MySQL-specific features that must be adapted for MongoDB.

### Step 3.1 — Models: use MongoDB Eloquent

The package provides a MongoDB Eloquent base. For each model that should live in MongoDB:

1. **Use the MongoDB model class and trait:**

   Change from:

   ```php
   use Illuminate\Database\Eloquent\Model;
   class GpsData extends Model
   ```

   To:

   ```php
   use MongoDB\Laravel\Eloquent\Model;
   class GpsData extends Model
   ```

   (Or the namespace documented by `mongodb/laravel-mongodb` for Laravel 12 — check the package’s `Model` class.)

2. **Set the connection** if not using default:

   ```php
   protected $connection = 'mongodb';
   ```

   For a dedicated GPS DB:

   ```php
   protected $connection = 'mongodb_gps';
   ```

3. **Primary key:** MongoDB uses `_id` (ObjectId or string). The package usually maps this. Remove any `$incrementing = false` or custom `$keyType` unless the package docs say otherwise.

4. **Collections:** Collection name is typically the snake_case plural of the class (e.g. `gps_data`). Override with `protected $collection = 'gps_data';` if needed.

5. **Relationships:** Keep `belongsTo` / `hasMany` etc.; the package supports them (and embedded relations). Ensure related models are also MongoDB models and use compatible keys.

Apply the same pattern to **all 51 models** that should be stored in MongoDB. Start with the ones involved in GPS and auth (e.g. `GpsData`, `User`, `Tractor`, etc.), then the rest.

### Step 3.2 — StoreGpsData job (GPS writes)

Current code uses `DB::connection('mysql_gps')` and raw table inserts. For MongoDB:

1. **Use the MongoDB connection** (e.g. `mongodb` or `mongodb_gps`):

   ```php
   return DB::connection('mongodb_gps'); // or 'mongodb'
   ```

2. **Replace raw inserts** with the query builder or Eloquent. MongoDB has no SQL; use the package’s builder, e.g.:

   ```php
   $connection->collection('gps_data')->insert($records);
   ```

   Check the package docs for the exact API (e.g. `insertOne`/`insertMany` or bulk insert).

3. **Transactions / deadlock:** MongoDB has different transaction semantics. Remove MySQL deadlock retry logic (e.g. `DeadlockException`, `isDeadlockException`, retry loops) and replace with MongoDB transaction API if you need multi-document transactions, or rely on single-doc writes.

4. **Schema of `$records`:** Keep fields like `tractor_id`, `coordinate`, `speed`, `status`, `directions`, `imei`, `date_time`. Store arrays/objects natively; no need for `json_encode` if the driver accepts arrays.

### Step 3.3 — TractorPathStreamService (GPS reads and streaming)

This service uses:

- `DB::table('gps_data')` for queries
- `DB::connection()->getPdo()` and raw SQL with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY`

MongoDB does not use PDO or SQL. You must:

1. **Use the MongoDB connection:**

   ```php
   DB::connection('mongodb'); // or 'mongodb_gps'
   ```

2. **Replace all raw SQL** with the MongoDB query builder or Eloquent. For example:

   - “Has any gps_data for tractor_id and date range?” → `DB::connection('mongodb')->collection('gps_data')->where(...)->exists()`
   - “Get last point before startOfDay” → same connection, `where('tractor_id', $tractorId)->where('date_time', '<', $startOfDay)->orderBy('date_time', 'desc')->first()`
   - The main stream query → cursor/iterator over the collection with `where`, `orderBy`, and no raw SQL.

3. **Streaming:** Remove `getPdo()`, `setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, ...)`, and `$stmt->execute(...)`. Use the MongoDB driver’s cursor (or the package’s cursor) to iterate over documents and yield the same JSON shape you already use. The package/docs will show how to run a query and iterate without loading all into memory.

4. **Date/time:** Store dates as MongoDB date types (or ISO strings); query with the same types so range queries work correctly.

### Step 3.4 — Migrations

- **SQL migrations do not run on MongoDB.** Laravel’s default migration runner is for SQL (MySQL, PostgreSQL, etc.).
- **Options:**
  1. **Do not run existing SQL migrations** when `DB_CONNECTION=mongodb`. Use the MongoDB package’s way of defining schema (collections, indexes) — often in code or one-off scripts.
  2. **Create new “migrations” or scripts** that create collections and indexes for MongoDB (e.g. using the MongoDB connection and the driver’s API or the package’s schema builder if it provides one).
  3. **Keep MySQL migrations** for environments that still use MySQL (e.g. staging during transition).

- **Driver checks:** You have migrations that do `Schema::getConnection()->getDriverName() === 'mysql'`. For MongoDB, the driver name will differ (e.g. `mongodb`). Update these to either:
  - Skip the block when driver is `mongodb`, or
  - Add equivalent logic for MongoDB (e.g. create index instead of foreign key).

Files that contain MySQL driver checks:

- `database/migrations/2024_08_10_151442_drop_team_id_from_labours_table.php`
- `database/migrations/2026_01_01_082521_rename_employees_to_labours.php`
- `database/migrations/2025_06_07_125944_drop_tractor_constraint_and_add_farm_id_to_drivers.php`
- `database/migrations/2026_02_16_000001_rename_labour_attendance_tables_to_user_based.php`
- `database/migrations/2026_01_02_155217_modify_labours_table_structure.php`

Adjust or skip these for MongoDB as needed.

### Step 3.5 — Third-party packages

- **Spatie Laravel Permission:** Check [compatibility with MongoDB](https://spatie.be/docs/laravel-permission) (or use a MongoDB-capable fork/version if required).
- **Laravel Sanctum:** Personal access tokens are stored in the DB; ensure the token model uses the MongoDB connection and that migrations for that table are not run on MongoDB (or are replaced by MongoDB collection setup).
- **Spatie Media Library:** Same: store metadata in MongoDB and ensure the package supports MongoDB or adapt storage.
- **Laravel Horizon:** If you use the database queue, switching `DB_CONNECTION` to MongoDB and using the MongoDB queue driver means Horizon will read jobs from MongoDB; confirm Horizon supports that in your Laravel/Horizon version.

---

## Phase 4: Data migration (MySQL → MongoDB)

1. **Export from MySQL:** Use `mysqldump` or a script that reads from MySQL and writes to MongoDB (e.g. Laravel commands using both connections).
2. **Transform:** Map tables to collections; convert auto-increment IDs to ObjectIds or string IDs as required by your models and the package.
3. **Import into MongoDB:** Use the Laravel MongoDB connection or `mongodb` shell/scripts to insert documents. Create indexes after bulk insert for performance.
4. **Verify:** Compare counts and critical fields; run a few critical flows in the app against MongoDB.

---

## Phase 5: Order of operations (recommended)

1. Install PHP `mongodb` extension and MongoDB server (or Atlas).
2. `composer require mongodb/laravel-mongodb`.
3. Add `mongodb` (and optionally `mongodb_gps`) in `config/database.php` and add `MONGODB_*` to `.env`. Keep default connection as `mysql` initially.
4. Convert one high-value model (e.g. `GpsData`) to MongoDB model and one code path (e.g. `StoreGpsData` + `TractorPathStreamService`) to use MongoDB only; test.
5. Extend to all models and all code paths that touch the DB (including queue, Sanctum, Spatie).
6. Add MongoDB “schema” (collections + indexes); stop running SQL migrations for MongoDB.
7. Run data migration (export MySQL → transform → import MongoDB).
8. Switch `DB_CONNECTION=mongodb` and optionally use MongoDB queue driver.
9. Remove or guard MySQL-specific code (PDO options, deadlock retries, raw SQL) and migration branches.
10. Test thoroughly and roll back plan (keep MySQL dump and env switch) until stable.

---

## Summary checklist

- [ ] PHP `mongodb` extension installed and enabled (CLI + web).
- [ ] MongoDB server running (local or Atlas).
- [ ] `composer require mongodb/laravel-mongodb` done.
- [ ] `config/database.php`: `mongodb` (and optionally `mongodb_gps`) connection added.
- [ ] `.env`: `MONGODB_URI`, `MONGODB_DATABASE` (and `MONGODB_GPS_DATABASE` if used).
- [ ] All target models switched to MongoDB Eloquent and correct `$connection`.
- [ ] `StoreGpsData`: use MongoDB connection and collection insert; remove MySQL deadlock logic.
- [ ] `TractorPathStreamService`: no PDO/raw SQL; use MongoDB query builder/cursor for streaming.
- [ ] Migrations: no SQL migrations for MongoDB; add MongoDB collection/index setup; update driver checks.
- [ ] Queue/cache/failed jobs: config updated if using MongoDB for queues.
- [ ] Spatie Permission, Sanctum, Media Library, Horizon: compatibility checked and adapted.
- [ ] Data migrated from MySQL to MongoDB and verified.
- [ ] `DB_CONNECTION=mongodb` in `.env` and full regression testing.

This aligns with the [Laravel 12.x MongoDB documentation](https://laravel.com/docs/12.x/mongodb) and adapts it to your existing MySQL setup and GPS pipeline.
