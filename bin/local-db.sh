#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
project_root="$PWD"
for binary in initdb pg_ctl psql createdb php; do
    command -v "$binary" >/dev/null || { echo "Missing prerequisite: $binary" >&2; exit 1; }
done
php bin/local-secrets.php
umask 077
mkdir -p var/postgresql var/postgresql-socket
chmod 700 var/postgresql var/postgresql-socket
if [ ! -f var/postgresql/PG_VERSION ]; then
    initdb -D var/postgresql -U hostdesk_owner --auth-local=trust --auth-host=scram-sha-256 --no-locale --encoding=UTF8
fi
if ! pg_ctl -D var/postgresql status >/dev/null 2>&1; then
    pg_ctl -D var/postgresql -l "$project_root/var/postgresql.log" -o "-h 127.0.0.1 -p 55432 -k $project_root/var/postgresql-socket" start
fi
# Password comes from a protected file, never an argument, committed file or printed SQL.
export HOSTDESK_DB_PASSWORD
HOSTDESK_DB_PASSWORD="$(cat var/keys/db.password)"
if [ "$(psql -h "$project_root/var/postgresql-socket" -p 55432 -U hostdesk_owner -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname='hostdesk'")" != 1 ]; then
    psql -X -v ON_ERROR_STOP=1 -h "$project_root/var/postgresql-socket" -p 55432 -U hostdesk_owner -d postgres <<'SQL'
\getenv db_password HOSTDESK_DB_PASSWORD
CREATE ROLE hostdesk LOGIN PASSWORD :'db_password';
SQL
fi
unset HOSTDESK_DB_PASSWORD
for database in hostdesk hostdesk_test; do
    if [ "$(psql -h "$project_root/var/postgresql-socket" -p 55432 -U hostdesk_owner -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname='$database'")" != 1 ]; then
        createdb -h "$project_root/var/postgresql-socket" -p 55432 -U hostdesk_owner -O hostdesk "$database"
    fi
done
echo 'Project-local PostgreSQL ready at 127.0.0.1:55432 (hostdesk and hostdesk_test).'
