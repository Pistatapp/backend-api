# Production Migration Fix Guide

## Problem
The migration `2025_07_10_234725_add_foreign_key_constraint_to_plot_id` is failing because there are orphaned records in the `irrigation_plot` table that reference non-existent `plot_id` values.

## Solution Steps

### Step 1: Roll back the failed migration (if needed)
If the migration partially ran, you may need to manually drop the constraint:

```sql
-- Connect to your MySQL database and run:
ALTER TABLE irrigation_plot DROP FOREIGN KEY IF EXISTS irrigation_plot_plot_id_foreign;
```

### Step 2: Remove the problematic migration files
Delete these migration files from your production server:

```bash
# Remove the failing migration
rm database/migrations/2025_07_10_234725_add_foreign_key_constraint_to_plot_id.php

# Remove the cleanup migration (we'll use the combined one instead)
rm database/migrations/2025_07_10_235459_clean_orphaned_irrigation_plot_records.php
```

### Step 3: Use the new combined migration
The new migration `2025_07_10_235538_add_foreign_key_constraint_to_plot_id_with_cleanup.php` will:
1. Clean up orphaned records first
2. Then add the foreign key constraint

### Step 4: Run the migration
```bash
php artisan migrate
```

## What the fix does

1. **Identifies orphaned records**: Finds records in `irrigation_plot` where:
   - `plot_id` doesn't exist in the `plots` table
   - `irrigation_id` doesn't exist in the `irrigations` table

2. **Removes orphaned records**: Deletes these invalid records to maintain data integrity

3. **Adds foreign key constraint**: Once the data is clean, adds the proper foreign key constraint

4. **Logs the cleanup**: Records how many records were removed for audit purposes

## Alternative Manual Approach

If you prefer to handle this manually, you can:

1. **Check for orphaned records**:
```sql
-- Check orphaned plot_id records
SELECT COUNT(*) FROM irrigation_plot 
WHERE plot_id NOT IN (SELECT id FROM plots);

-- Check orphaned irrigation_id records  
SELECT COUNT(*) FROM irrigation_plot 
WHERE irrigation_id NOT IN (SELECT id FROM irrigations);
```

2. **Clean up manually**:
```sql
-- Remove orphaned records
DELETE FROM irrigation_plot 
WHERE plot_id NOT IN (SELECT id FROM plots);

DELETE FROM irrigation_plot 
WHERE irrigation_id NOT IN (SELECT id FROM irrigations);
```

3. **Add the foreign key constraint**:
```sql
ALTER TABLE irrigation_plot 
ADD CONSTRAINT irrigation_plot_plot_id_foreign 
FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE CASCADE;
```

## Verification

After the migration runs successfully, verify:

```sql
-- Check that the foreign key constraint exists
SHOW CREATE TABLE irrigation_plot;

-- Verify no orphaned records remain
SELECT COUNT(*) FROM irrigation_plot 
WHERE plot_id NOT IN (SELECT id FROM plots);
```

The count should be 0. 
