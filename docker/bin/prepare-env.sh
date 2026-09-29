#!/bin/sh
# Creates .env and .env.testing from the tracked examples, only when absent.
# Existing files are never modified, so APP_KEY and the database passwords
# survive every repeated `make setup`.
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
    umask 077
    sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(random_secret)/" \
        -e "s/^DB_TEST_PASSWORD=.*/DB_TEST_PASSWORD=$(random_secret)/" \
        -e "s/^DEMO_PASSWORD=.*/DEMO_PASSWORD=$(random_secret 8)/" \
        .env.example > .env
    echo "Created .env with generated local database and demo passwords"
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
