#!/bin/sh
# Container entrypoint: configure the port, migrate, warm caches, then run everything under Supervisor.
set -e
cd /var/www/html

sed -i "s/__PORT__/${PORT:-10000}/" /etc/nginx/conf.d/dgs.conf

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi
# Environment variables only exist at runtime, so config/route/view caches are built here.
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/dgs.conf
