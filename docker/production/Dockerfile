# syntax=docker/dockerfile:1
#
# Production image (M11). One container serves the application with nginx
# and PHP-FPM as a non-root user. It holds the code, the locked production
# Composer dependencies (no dev packages) and the compiled Vite assets.
# There is no source bind mount: configuration comes from environment
# variables at run time, and the entrypoint caches it on start.
#
#     docker build -f docker/production/Dockerfile -t fleetfuel-portal:local .
#
# The same image runs the web server (default), the scheduler
# (`php artisan schedule:work`) and one-off release commands
# (`php artisan migrate --force`). See docs/RUNBOOK.md.

# PHP with the application's extensions, shared by the build and final stages.
FROM php:8.3-fpm-trixie AS php-base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath intl opcache pcntl pdo_mysql zip

# Locked production dependencies. The lockfile layer is cached until it changes.
FROM php-base AS vendor
COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
# Builds the optimized autoloader and runs package:discover, which lists only
# the production packages in bootstrap/cache.
RUN composer dump-autoload --no-dev --optimize --no-interaction

# Compiled, hashed CSS and JavaScript from the locked npm dependencies.
FROM node:24.21-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php-base AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled /etc/nginx/conf.d \
    && groupadd --gid 10001 app \
    && useradd --uid 10001 --gid 10001 --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin app \
    && sed -i -e 's/^user = /;user = /' -e 's/^group = /;group = /' /usr/local/etc/php-fpm.d/www.conf

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-fleetfuel.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-fleetfuel.conf
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/entrypoint.sh /usr/local/bin/fleetfuel-entrypoint

WORKDIR /var/www/html
# The code stays owned by root, so the running user cannot change it; only
# storage/ and bootstrap/cache/ are writable.
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod 0755 /usr/local/bin/fleetfuel-entrypoint

USER app
EXPOSE 8080
# Liveness only (no database); readiness is GET /health.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --start-interval=2s --retries=3 \
    CMD curl --fail --silent --output /dev/null http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["fleetfuel-entrypoint"]
CMD ["web"]
