#!/bin/bash
# Runs once, on the first start of an empty MySQL data volume. The official
# entrypoint sources this file, so docker_process_sql connects as root over the
# local socket. The development database and user already exist (from
# MYSQL_DATABASE / MYSQL_USER); this adds the isolated test database.
#
# The test user may only use databases whose names start with the test
# database name (e.g. fleetfuel_test, fleetfuel_test_1 for parallel runs), so
# tests physically cannot read or reset the development database.

: "${MYSQL_TEST_DATABASE:?MYSQL_TEST_DATABASE is required}"
: "${MYSQL_TEST_USER:?MYSQL_TEST_USER is required}"
: "${MYSQL_TEST_PASSWORD:?MYSQL_TEST_PASSWORD is required}"

# In GRANT patterns "_" is a wildcard, so escape it before appending "%".
test_database_pattern="${MYSQL_TEST_DATABASE//_/\\_}%"

docker_process_sql --database=mysql <<-EOSQL
	CREATE DATABASE IF NOT EXISTS \`${MYSQL_TEST_DATABASE}\`
	    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	CREATE USER IF NOT EXISTS '${MYSQL_TEST_USER}'@'%' IDENTIFIED BY '${MYSQL_TEST_PASSWORD}';
	GRANT ALL PRIVILEGES ON \`${test_database_pattern}\`.* TO '${MYSQL_TEST_USER}'@'%';
EOSQL

mysql_note "Created test database ${MYSQL_TEST_DATABASE} for user ${MYSQL_TEST_USER}"
