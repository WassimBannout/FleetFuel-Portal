#!/bin/sh
# Refuses to start containers when this checkout's Compose project already
# belongs to another checkout on this machine.
#
# compose.yaml names the project "fleetfuel". A second clone with the same
# name would replace the first clone's containers (serving the wrong files)
# and reuse its database volume. A second copy needs its own
# COMPOSE_PROJECT_NAME and APP_PORT (README, "A second copy on one machine").
set -eu

cd "$(dirname "$0")/../.."

ids=$(docker compose ps --all --quiet 2>/dev/null || true)
[ -n "$ids" ] || exit 0

here=$(pwd)
here_resolved=$(pwd -P)

# shellcheck disable=SC2086 # one argument per container ID
docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}|{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' $ids \
    | sort -u \
    | while IFS='|' read -r project dir; do
        if [ "$dir" != "$here" ] && [ "$dir" != "$here_resolved" ]; then
            cat >&2 <<EOF
Compose project "$project" already belongs to the checkout at:
    $dir
Starting it from here would replace that checkout's containers and reuse its
database volume. To run this copy next to it, give it its own project name
and port in this checkout's .env, for example:
    COMPOSE_PROJECT_NAME=fleetfuel-copy
    APP_PORT=8081
    APP_URL=http://localhost:8081
If this checkout has no data yet, you can instead delete its .env and run:
    COMPOSE_PROJECT_NAME=fleetfuel-copy APP_PORT=8081 make setup
EOF
            exit 1
        fi
    done
