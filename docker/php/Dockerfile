# syntax=docker/dockerfile:1
#
# Development image shared by the `app` (PHP-FPM) and `scheduler` services.
# Source code is bind-mounted at /var/www/html at runtime, so it is not copied
# into the image. Composer runs inside this image so dependencies resolve
# against the same PHP version and extensions the application uses.
FROM php:8.3-fpm-trixie

# Match the host user so files written to the bind mount (vendor/, storage/,
# caches) stay owned by the developer instead of root. The Makefile passes the
# real values; 1000 is only a fallback.
ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions bcmath intl opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# PHP-FPM normally starts as root and drops to www-data. Here the whole
# container runs as `app`, so the pool's user/group lines are disabled.
RUN groupadd --non-unique --gid "${GID}" app \
    && useradd --non-unique --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app \
    && sed -i -e 's/^user = www-data/;user = www-data/' -e 's/^group = www-data/;group = www-data/' \
        /usr/local/etc/php-fpm.d/www.conf

COPY php.ini /usr/local/etc/php/conf.d/zz-fleetfuel.ini

WORKDIR /var/www/html
USER app
