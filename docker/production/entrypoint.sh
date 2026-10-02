#!/bin/bash
# Entrypoint of the production image.
#
# 1. Caches configuration, routes, events and views from this container's
#    environment (`php artisan optimize`). It never migrates or seeds: those
#    are explicit release steps (docs/RUNBOOK.md).
# 2. `web` (the default) runs PHP-FPM and nginx side by side. If either one
#    stops, the other is stopped too and the container exits, so the host
#    restarts it instead of serving errors. Any other command runs as given,
#    e.g. `php artisan schedule:work` or `php artisan migrate --force`.
set -euo pipefail

cd /var/www/html

if [[ "${APP_OPTIMIZE:-true}" == "true" ]]; then
    php artisan optimize --no-interaction
fi

if [[ "${1:-web}" != "web" ]]; then
    exec "$@"
fi

php-fpm --nodaemonize --force-stderr &
fpm=$!
nginx -e stderr -g 'daemon off;' &
web=$!

trap 'kill -TERM "$fpm" "$web" 2>/dev/null || true' TERM INT

set +e
wait -n "$fpm" "$web"
status=$?
kill -TERM "$fpm" "$web" 2>/dev/null
wait
exit "$status"
