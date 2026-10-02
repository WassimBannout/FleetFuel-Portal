#!/bin/bash
# Local production rehearsal (M11, docs/RUNBOOK.md).
#
# Builds the production image and runs it with MySQL and the scheduler in a
# throwaway Compose project (fleetfuel-rehearsal). It performs the release
# steps, smoke-tests sign-in, POS purchases, the delivery lifecycle, reports
# and the CSV, checks the scheduler and the logs, backs up, restores into a
# disposable database, and restarts. Then it removes everything it created.
# The development stack and its volume are never touched.
#
#     make rehearse        (or: bash docker/production/rehearse.sh)
#
# Needs Docker with Compose v2, a free local port (REHEARSAL_PORT, default
# 8094) and network access for Newman (npm). It stops at the first failure.
set -euo pipefail

cd "$(dirname "$0")/../.."

port=${REHEARSAL_PORT:-8094}
base="http://127.0.0.1:$port"
work=$(mktemp -d)
export COMPOSE_PROJECT_NAME=fleetfuel-rehearsal
export FLEETFUEL_IMAGE=fleetfuel-portal:rehearsal
export FLEETFUEL_ENV_FILE="$work/rehearsal.env"
started=$(date +%s)

compose() { docker compose --env-file "$FLEETFUEL_ENV_FILE" -f compose.production.yaml "$@"; }
step() { printf '\n== %s\n' "$*"; }
ok() { printf '   ok: %s\n' "$*"; }
fail() { printf '   FAIL: %s\n' "$*" >&2; exit 1; }
secret() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }
# Runs a command quietly; shows its output only when it fails.
quiet() {
    if ! "$@" >"$work/last.log" 2>&1; then
        cat "$work/last.log" >&2
        fail "$*"
    fi
}
live_sql() { compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -N -B -u"$MYSQL_USER" "$MYSQL_DATABASE"'; }
live_tables() {
    local table
    for table in $(echo "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name" | live_sql); do
        echo "$table $(echo "SELECT COUNT(*) FROM \`$table\`" | live_sql) $(echo "CHECKSUM TABLE \`$table\`" | live_sql | awk '{ print $2 }')"
    done
}

cleanup() {
    step "Removing the rehearsal stack, its volume, image and settings"
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    docker image rm "$FLEETFUEL_IMAGE" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

if curl -s -o /dev/null --max-time 2 "$base"; then
    fail "port $port is in use; set REHEARSAL_PORT to a free port"
fi

step "Disposable settings from .env.production.example (secrets generated, never printed)"
demo_password=$(secret 12)
app_key="base64:$(head -c 32 /dev/urandom | base64)"
(
    umask 077
    sed -e "s|^APP_KEY=.*|APP_KEY=$app_key|" \
        -e "s|^APP_URL=.*|APP_URL=$base|" \
        -e "s|^APP_PORT=.*|APP_PORT=$port|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(secret 24)|" \
        -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" \
        -e "s|^DEMO_MODE=.*|DEMO_MODE=true|" \
        -e "s|^DEMO_PASSWORD=.*|DEMO_PASSWORD=$demo_password|" \
        .env.production.example > "$FLEETFUEL_ENV_FILE"
)
ok "plain HTTP on $base, so SESSION_SECURE_COOKIE=false here only"
compose down -v --remove-orphans >/dev/null 2>&1 || true

step "Building the production image"
build_started=$(date +%s)
quiet docker build -f docker/production/Dockerfile -t "$FLEETFUEL_IMAGE" .
size=$(docker image inspect "$FLEETFUEL_IMAGE" --format '{{.Size}}' | awk '{ printf "%.0f MB", $1 / 1000000 }')
ok "$FLEETFUEL_IMAGE built in $(( $(date +%s) - build_started )) s ($size by docker image inspect)"

step "Inspecting the image"
quiet docker run --rm --entrypoint bash "$FLEETFUEL_IMAGE" -c '
    set -e
    test "$(id -u)" != 0
    for path in .env tests docs tools node_modules vendor/phpunit vendor/laravel/pint vendor/larastan; do test ! -e "$path"; done
    test -f public/build/manifest.json
    if command -v composer >/dev/null; then exit 1; fi
    if touch app/probe 2>/dev/null; then exit 1; fi
    touch storage/logs/probe bootstrap/cache/probe'
ok "non-root user; no .env, tests, tools or dev packages; code read-only, storage writable; compiled assets"

step "Release steps: database, migrations, demo data"
quiet compose up -d --wait mysql
quiet compose run --rm app php artisan migrate --force --no-interaction
ok "php artisan migrate --force"
if compose run --rm app php artisan demo:seed --no-interaction >/dev/null 2>&1; then
    fail "demo:seed ran in production without --force"
fi
ok "demo:seed without --force is refused in production"
quiet compose run --rm app php artisan demo:seed --force --no-interaction
ok "php artisan demo:seed --force (fictional demo data, empty database)"

step "Starting the app and exactly one scheduler"
quiet compose up -d --wait app scheduler
ok "app healthy (image health check on /up), scheduler healthy (its process check)"

step "HTTP checks on $base"
[[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/up")" == 200 ]] || fail "/up"
health=$(curl -s "$base/health")
[[ "$health" == *'"database":"ok"'* ]] || fail "/health answered $health"
ok "/up 200, /health $health"
login=$(curl -s "$base/login")
asset_re='/build/assets/app-[A-Za-z0-9_-]+\.js'
[[ "$login" =~ $asset_re ]] || fail "the sign-in page references no compiled script"
asset=${BASH_REMATCH[0]}
# Capture, then search: with pipefail, `cmd | grep -q` can fail when grep exits
# at the first match while cmd is still writing (SIGPIPE, status 141).
asset_headers=$(curl -sI "$base$asset") || fail "could not fetch $asset"
grep -qi '^cache-control: max-age=31536000' <<<"$asset_headers" || fail "$asset is not cached for a year"
login_headers=$(curl -sI "$base/login") || fail "could not fetch /login"
grep -qi '^x-content-type-options: nosniff' <<<"$login_headers" || fail "security headers missing"
missing=$(curl -s -w ' %{http_code}' "$base/no-such-page")
[[ "$missing" == *'Page not found'*' 404' ]] || fail "the 404 page"
about=$(compose exec -T app php artisan about --json)
for expected in '"environment":"production"' '"debug_mode":false' '"config":true' '"routes":true' '"views":true'; do
    [[ "$about" == *"$expected"* ]] || fail "artisan about lacks $expected"
done
ok "hashed assets cached for a year, security headers, plain 404 page, production with debug off and every cache in use"

step "Web sign-in (manager)"
jar="$work/cookies.txt"
login_page=$(curl -s -c "$jar" -b "$jar" "$base/login") || fail "could not fetch /login"
token_re='name="_token" value="([^"]+)"'
[[ "$login_page" =~ $token_re ]] || fail "the sign-in page has no CSRF token"
token=${BASH_REMATCH[1]}
signin=$(printf '%s' "$demo_password" | curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$jar" -b "$jar" \
    --data-urlencode "_token=$token" --data-urlencode 'email=manager.atlas@fleetfuel.test' --data-urlencode 'password@-' "$base/login")
[[ "$signin" == "302 $base/dashboard" ]] || fail "sign-in answered $signin"
dashboard=$(curl -s -b "$jar" "$base/dashboard")
[[ "$dashboard" == *'Atlas Logistics'* && "$dashboard" != *'Cedar Catering'* ]] || fail "the manager dashboard is not scoped to Atlas"
ok "signed in through the form (CSRF token, session cookie); the dashboard shows Atlas only"

step "API scenarios with Newman: POS purchases, manager scope, deliveries, reports and CSV, token revocation"
newman() {
    DP="$demo_password" docker run --rm --network "${COMPOSE_PROJECT_NAME}_default" \
        -v "$PWD/postman:/postman:ro" -e DP -e npm_config_update_notifier=false node:24.21-alpine \
        sh -c 'npx --yes newman@6 run /postman/FleetFuel.postman_collection.json -e /postman/local.postman_environment.json --env-var base_url=http://app:8080/api/v1 --env-var "demo_password=$DP" --reporters cli --color off "$@"' newman "$@"
}
newman --folder '01 — POS scenario (M06)' --folder '02 — Manager access (M06)' --folder '05 — Revoke tokens' > "$work/newman-pos.log" 2>&1 \
    || { cat "$work/newman-pos.log" >&2; fail "Newman folders 01, 02 and 05"; }
grep -E '^\s*│\s*(requests|assertions)\s' "$work/newman-pos.log" | sed 's/^/   /'
newman --folder '03 — Delivery scenario (M07)' --folder '04 — Reports and export (M08)' > "$work/newman-ops.log" 2>&1 \
    || { cat "$work/newman-ops.log" >&2; fail "Newman folders 03 and 04"; }
grep -E '^\s*│\s*(requests|assertions)\s' "$work/newman-ops.log" | sed 's/^/   /'
quiet compose exec -T app php artisan usage:reconcile --no-interaction
ok "every request and assertion passed; the quota counters reconcile with the ledger"

step "Scheduler and logs"
schedule=$(compose exec -T scheduler php artisan schedule:list --no-interaction) || fail "schedule:list failed"
grep -q 'rates:sync' <<<"$schedule" || fail "rates:sync is not scheduled"
quiet compose exec -T app php artisan rates:sync --force --no-interaction
logs=$(compose logs --no-color app scheduler 2>&1)
if grep -qF "$demo_password" <<<"$logs" || grep -qF "${app_key#base64:}" <<<"$logs"; then
    fail "a secret appeared in the container logs"
fi
ok "rates:sync is scheduled and runs; no password or key in the logs"

step "Backup, then restore into a disposable database"
# Writers stopped, so the copy can be compared exactly with the live tables.
quiet compose stop app scheduler
live=$(live_tables)
backup=$(bash docker/production/backup.sh "$work/backups")
ok "$(basename "$backup") ($(du -h "$backup" | cut -f1)), with a .sha256 file"
restored=$(bash docker/production/restore-check.sh "$backup" "$FLEETFUEL_IMAGE" 2>"$work/restore.log") \
    || { cat "$work/restore.log" >&2; fail "restore check"; }
if [[ "$live" != "$restored" ]]; then
    diff <(echo "$live") <(echo "$restored") >&2 || true
    fail "the restored tables differ from the live ones"
fi
ok "restored copy: migrations complete, counters reconcile, same rows and checksums in all $(wc -l <<<"$live") tables"

step "Restart from scratch: containers recreated, data kept"
purchases=$(echo 'SELECT COUNT(*) FROM fuel_transactions' | live_sql)
quiet compose down
quiet compose up -d --wait app scheduler
[[ "$(echo 'SELECT COUNT(*) FROM fuel_transactions' | live_sql)" == "$purchases" ]] || fail "purchases changed across the restart"
[[ "$(curl -s "$base/health")" == *'"database":"ok"'* ]] || fail "/health after the restart"
ok "$purchases purchases before and after; ready again"

step "Rehearsal passed in $(( $(date +%s) - started )) s"
