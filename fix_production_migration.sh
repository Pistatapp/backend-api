#!/bin/bash

# Production Migration Fix Script
# This script fixes the irrigation_plot foreign key constraint issue

echo "=== Production Migration Fix ==="
echo "Fixing irrigation_plot foreign key constraint issue..."

# Step 1: Remove problematic migration files
echo "Step 1: Removing problematic migration files..."
if [ -f "database/migrations/2025_07_10_234725_add_foreign_key_constraint_to_plot_id.php" ]; then
    rm database/migrations/2025_07_10_234725_add_foreign_key_constraint_to_plot_id.php
    echo "✓ Removed failing migration file"
else
    echo "ℹ Failing migration file not found (already removed)"
fi

if [ -f "database/migrations/2025_07_10_235459_clean_orphaned_irrigation_plot_records.php" ]; then
    rm database/migrations/2025_07_10_235459_clean_orphaned_irrigation_plot_records.php
    echo "✓ Removed cleanup migration file"
else
    echo "ℹ Cleanup migration file not found (already removed)"
fi

# Step 2: Check if the combined migration exists
echo "Step 2: Checking for combined migration..."
if [ -f "database/migrations/2025_07_10_235538_add_foreign_key_constraint_to_plot_id_with_cleanup.php" ]; then
    echo "✓ Combined migration file found"
else
    echo "❌ Combined migration file not found!"
    echo "Please ensure the file exists: database/migrations/2025_07_10_235538_add_foreign_key_constraint_to_plot_id_with_cleanup.php"
    exit 1
fi

# Step 3: Run the migration
echo "Step 3: Running migrations..."
php artisan migrate

if [ $? -eq 0 ]; then
    echo "✅ Migration completed successfully!"
else
    echo "❌ Migration failed!"
    echo "Please check the logs and consider manual cleanup."
    exit 1
fi

echo "=== Fix completed ==="
echo "The irrigation_plot foreign key constraint has been fixed."
