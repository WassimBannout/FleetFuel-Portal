#!/bin/bash
# Backs up the production database of compose.production.yaml to a
# compressed SQL file (docs/RUNBOOK.md, "Backups").
#
#     bash docker/production/backup.sh /path/outside/the/repository
#
# mysqldump runs inside the mysql container with the application's own
# account. --single-transaction reads one consistent snapshot without
# blocking writers, so the app can stay up.
#
# The file holds every business record (card numbers, names, hashed
# passwords): encrypt it, keep it off this machine, and never commit it.
# The env file defaults to .env.production; COMPOSE_PROJECT_NAME selects
# another stack.
set -euo pipefail

cd "$(dirname "$0")/../.."

out_dir=${1:?usage: backup.sh <output directory outside the repository>}
env_file=${FLEETFUEL_ENV_FILE:-.env.production}
compose=(docker compose --env-file "$env_file" -f compose.production.yaml)

umask 077
mkdir -p "$out_dir"
file="$out_dir/fleetfuel-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
# A failed dump leaves no file that looks like a backup.
trap 'rm -f "$file.partial"' EXIT

# The password goes through the environment, never the command line.
"${compose[@]}" exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --no-tablespaces --set-gtid-purged=OFF -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
    | gzip -9 > "$file.partial"
mv "$file.partial" "$file"

(cd "$out_dir" && sha256sum "$(basename "$file")" > "$(basename "$file").sha256")
echo "$file"
