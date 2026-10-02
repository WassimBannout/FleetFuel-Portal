#!/bin/bash
# Restores a backup into a disposable MySQL server and checks it with the
# application itself (docs/RUNBOOK.md, "Restore"). The live database is never
# touched; the throwaway server and its network are removed at the end.
#
#     bash docker/production/restore-check.sh <backup.sql.gz> [image]
#
# It checks that the file matches its .sha256, that the dump loads, that
# every migration is recorded as run, and that the monthly quota counters
# reconcile with the ledger. Then it prints "table rows checksum" for every
# table, to compare with the source database.
set -euo pipefail

backup=${1:?usage: restore-check.sh <backup.sql.gz> [image]}
image=${2:-${FLEETFUEL_IMAGE:-fleetfuel-portal:local}}
name="fleetfuel-restore-check-$$"
# A throwaway password for a throwaway server.
password=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')

if [[ ! -f "$backup" ]]; then
    echo "No such backup: $backup" >&2
    exit 1
fi
if [[ -f "$backup.sha256" ]]; then
    (cd "$(dirname "$backup")" && sha256sum --check --quiet "$(basename "$backup").sha256")
    echo "Checksum file matches." >&2
fi

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$name" >/dev/null
docker run -d --name "$name" --network "$name" \
    -e MYSQL_RANDOM_ROOT_PASSWORD=yes -e MYSQL_DATABASE=fleetfuel_restore \
    -e MYSQL_USER=restore -e MYSQL_PASSWORD="$password" \
    mysql:8.4 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --default-time-zone=+00:00 >/dev/null

sql() {
    docker exec -i -e MYSQL_PWD="$password" "$name" mysql -N -B -urestore fleetfuel_restore "$@"
}

# The first start initialises over a socket only; TCP answers once the real server runs.
for _ in $(seq 1 90); do
    if docker exec -e MYSQL_PWD="$password" "$name" mysqladmin ping --silent -h 127.0.0.1 -urestore >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
sql -e 'SELECT 1' >/dev/null

echo "Loading $(basename "$backup") into a disposable MySQL server..." >&2
gunzip -c "$backup" | sql

# The application, from the production image, against the restored copy.
app() {
    docker run --rm --network "$name" -e APP_ENV=production -e APP_DEBUG=false -e APP_OPTIMIZE=false \
        -e LOG_CHANNEL=stderr -e DB_CONNECTION=mysql -e DB_HOST="$name" -e DB_PORT=3306 \
        -e DB_DATABASE=fleetfuel_restore -e DB_USERNAME=restore -e DB_PASSWORD="$password" \
        "$image" "$@"
}

# Capture first: with pipefail, `cmd | grep -q` turns false when grep exits at the
# first match while cmd is still writing (SIGPIPE), which would hide a pending migration.
migration_status=$(app php artisan migrate:status --no-interaction)
if grep -q 'Pending' <<<"$migration_status"; then
    echo "FAIL: the restored database has pending migrations." >&2
    exit 1
fi
echo "Every migration is recorded as run." >&2

app php artisan usage:reconcile --no-interaction >&2

for table in $(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"); do
    rows=$(sql -e "SELECT COUNT(*) FROM \`$table\`")
    checksum=$(sql -e "CHECKSUM TABLE \`$table\`" | awk '{ print $2 }')
    echo "$table $rows $checksum"
done
