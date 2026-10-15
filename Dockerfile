# Production image for Render (or any Docker host).
# One container runs Nginx + PHP-FPM (web), the queue worker and the scheduler under Supervisor,
# because Render's free plan has no background workers or cron jobs.

FROM php:8.4-fpm-bookworm AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor unzip \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql gd intl zip bcmath opcache exif pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# PHP dependencies (cached until composer.lock changes)
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# Admin/website assets (Tailwind scans the Laravel pagination views in vendor/)
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

FROM base AS app
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini $PHP_INI_DIR/conf.d/99-dgs.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-dgs.conf
COPY docker/nginx.conf /etc/nginx/conf.d/dgs.conf
COPY docker/supervisord.conf /etc/supervisor/dgs.conf
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

ENV PORT=10000
EXPOSE 10000
CMD ["docker/start.sh"]
