#!/bin/sh
# Creates the isolated test database (FR-014) and grants the application user access to it.
# Runs only when the mysql-data volume is initialized for the first time.
# All values come from the container environment; no credentials are stored here.

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"
: "${MYSQL_USER:?MYSQL_USER is required}"
: "${DB_TEST_DATABASE:?DB_TEST_DATABASE is required}"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root <<EOSQL
CREATE DATABASE IF NOT EXISTS \`$DB_TEST_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$DB_TEST_DATABASE\`.* TO '$MYSQL_USER'@'%';
EOSQL
