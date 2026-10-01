#!/bin/sh
# Creates .env and .env.testing from the tracked examples, only when absent.
# Existing files are never modified, so APP_KEY and the database passwords
# survive every repeated `make setup`.
#
# A second copy of the repository on the same machine needs its own Compose
# project and port. Set them for the first setup, and they are written into
# the new .env:
#     COMPOSE_PROJECT_NAME=fleetfuel-copy APP_PORT=8081 make setup
set -eu

cd "$(dirname "$0")/../.."

random_secret() { # usage: random_secret [BYTES], default 24 bytes (48 hex characters)
    # Hex characters are safe in env files and SQL strings.
    od -An -N"${1:-24}" -tx1 /dev/urandom | tr -d ' \n'
}

env_value() { # usage: env_value FILE KEY
    sed -n "s/^$2=//p" "$1" | tail -n 1
}

if [ -f .env ]; then
    echo "Keeping existing .env"
else
    project=${COMPOSE_PROJECT_NAME:-}
    port=${APP_PORT:-8080}

    # Compose's own rule for project names; a port is digits only.
    if [ -n "$project" ] && ! printf '%s' "$project" | grep -Eq '^[a-z0-9][a-z0-9_-]*$'; then
        echo "COMPOSE_PROJECT_NAME may use lowercase letters, digits, - and _ only." >&2
        exit 1
    fi
    if ! printf '%s' "$port" | grep -Eq '^[0-9]{1,5}$'; then
        echo "APP_PORT must be a port number." >&2
        exit 1
    fi

    umask 077
    sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(random_secret)/" \
        -e "s/^DB_TEST_PASSWORD=.*/DB_TEST_PASSWORD=$(random_secret)/" \
        -e "s/^DEMO_PASSWORD=.*/DEMO_PASSWORD=$(random_secret 8)/" \
        -e "s/^APP_PORT=.*/APP_PORT=$port/" \
        -e "s|^APP_URL=.*|APP_URL=http://localhost:$port|" \
        ${project:+-e "s/^# COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$project/"} \
        .env.example > .env
    echo "Created .env with generated local database and demo passwords"
    if [ -n "$project" ]; then
        echo "This copy uses Compose project $project on port $port"
    fi
fi

for key in DB_PASSWORD DB_TEST_PASSWORD; do
    if [ -z "$(env_value .env "$key")" ]; then
        echo "$key is empty in .env; set a local password before running setup." >&2
        exit 1
    fi
done

if [ -f .env.testing ]; then
    echo "Keeping existing .env.testing"
else
    umask 077
    sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(env_value .env DB_TEST_PASSWORD)/" \
        .env.testing.example > .env.testing
    echo "Created .env.testing for the isolated test database"
fi
