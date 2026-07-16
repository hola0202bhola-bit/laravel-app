#!/bin/sh
set -e

cd /var/www/html

mkdir -p database storage bootstrap/cache

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

chown -R www-data:www-data database storage bootstrap/cache
chmod -R ug+rwX database storage bootstrap/cache

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache

exec apache2-foreground
