#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

php artisan key:generate --force || true
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php-fpm

