#!/bin/sh
# Acceptance check T01 (second half): running `make setup` again must keep the
# APP_KEY, generated credentials and existing database rows.
#
# Stores one marker row in the database-backed cache table, reruns setup,
# verifies the env files are byte-identical and the row survived, then removes
# the marker. Requires the stack to be running (after a first `make setup`).
set -eu

cd "$(dirname "$0")/../.."

artisan() {
    docker compose exec -T app php artisan "$@"
}

marker="setup-check-$(date +%s)"
env_before=$(sha256sum .env .env.testing)

artisan tinker --execute="cache()->forever('$marker', 'kept');"
make setup
env_after=$(sha256sum .env .env.testing)
value=$(artisan tinker --execute="echo cache()->get('$marker', 'missing');" | tr -d '\r\n')
artisan tinker --execute="cache()->forget('$marker');"

if [ "$env_before" != "$env_after" ]; then
    echo "FAIL: .env or .env.testing changed during repeated setup" >&2
    exit 1
fi

if [ "$value" != "kept" ]; then
    echo "FAIL: database marker row was lost (got '$value')" >&2
    exit 1
fi

echo "PASS: repeated setup kept APP_KEY, credentials and database rows"
