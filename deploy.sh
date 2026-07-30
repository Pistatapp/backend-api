#!/bin/bash

set -e

echo "🚀 Starting deployment process for Pistat API..."

PROJECT_DIR="/home/api/domains/api.pistatapp.ir/public_html"
cd "$PROJECT_DIR"

echo "📥 Pulling latest changes from main branch..."
git fetch origin main
git reset --hard origin/main

echo "📦 Installing/updating Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "🗄️ Running database migrations..."
php artisan migrate --force

echo "🧹 Clearing old caches..."
php artisan optimize:clear

echo "⚡ Optimizing application for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "🔄 Restarting queue workers..."
php artisan queue:restart

echo "✅ Deployment completed successfully!"
