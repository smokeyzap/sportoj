#!/usr/bin/env bash
# Safe restore test into an EMPTY temporary database (BACKUP_RESTORE.md section 8). Never touches production.
#
# Usage: scripts/restore_verify.sh /path/to/trainingsapp_...sql.gz
#
# Environment (or .env):
#   RESTORE_DB_DATABASE       target database (default: ${DB_DATABASE}_restore_test); must differ from DB_DATABASE
#   RESTORE_DB_HOST/PORT/USERNAME/PASSWORD   default to DB_*
#   RESTORE_CREATE_DB=1       create the target database when it does not exist
#   RESTORE_DROP_AFTER=1      drop the target database after a successful verification
#   RESTORE_ALLOW_ANY_NAME=1  allow target names that contain neither "restore" nor "test"
#   BACKUP_DIR                when set, the result is appended to $BACKUP_DIR/restore-tests.log
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=scripts/lib.sh
. "$SCRIPT_DIR/lib.sh"
load_env_file "${ENV_FILE:-$ROOT/.env}"

FILE="${1:-}"
[ -n "$FILE" ] || fail "usage: $0 backup.sql.gz" 2
[ -f "$FILE" ] || fail "backup file not found: $FILE" 2

DB_HOST="${DB_HOST:-localhost}"; DB_PORT="${DB_PORT:-3306}"
R_HOST="${RESTORE_DB_HOST:-$DB_HOST}"; R_PORT="${RESTORE_DB_PORT:-$DB_PORT}"
R_USER="${RESTORE_DB_USERNAME:-${DB_USERNAME:-}}"; R_PASS="${RESTORE_DB_PASSWORD:-${DB_PASSWORD:-}}"
R_DB="${RESTORE_DB_DATABASE:-${DB_DATABASE:+${DB_DATABASE}_restore_test}}"
[ -n "$R_USER" ] || fail "RESTORE_DB_USERNAME or DB_USERNAME is required" 2
[ -n "$R_DB" ] || fail "RESTORE_DB_DATABASE is required" 2
[[ "$R_DB" =~ ^[A-Za-z0-9_]+$ ]] || fail "unsafe database name: $R_DB" 2

if [ -n "${DB_DATABASE:-}" ] && [ "$R_DB" = "$DB_DATABASE" ] && [ "$R_HOST" = "$DB_HOST" ] && [ "$R_PORT" = "$DB_PORT" ]; then
    fail "refusing to restore over the production database ($R_DB)" 2
fi
if [ "${RESTORE_ALLOW_ANY_NAME:-0}" != "1" ]; then
    case "$R_DB" in *restore*|*test*) ;; *) fail "target database name must contain 'restore' or 'test' (or set RESTORE_ALLOW_ANY_NAME=1)" 2 ;; esac
fi

CLIENT_BIN="$(pick_bin mariadb mysql)" || fail "neither mariadb nor mysql client found"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
CNF="$TMP/client.cnf"
write_client_cnf "$CNF" "$R_HOST" "$R_PORT" "$R_USER" "$R_PASS" "${RESTORE_DB_SOCKET:-}"
sql() { "$CLIENT_BIN" --defaults-extra-file="$CNF" -N -B "$@"; }

record() {
    if [ -n "${BACKUP_DIR:-}" ] && [ -d "$BACKUP_DIR" ]; then
        printf '%s %s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(basename "$FILE")" "$1" "$R_DB" >> "$BACKUP_DIR/restore-tests.log"
    fi
}
bail() { record "FAILED: $1"; fail "$1" "${2:-1}"; }

# 1. integrity of the file itself
gzip -t "$FILE" || bail "gzip integrity test failed"
SUMFILE="$FILE.sha256"
[ -f "$SUMFILE" ] || bail "checksum file missing: $SUMFILE"
( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$SUMFILE")" >/dev/null 2>&1 ) || bail "SHA-256 checksum does not match"

# 2. target database must exist (or be created) and be empty
if ! sql -e "USE \`$R_DB\`" >/dev/null 2>&1; then
    [ "${RESTORE_CREATE_DB:-0}" = "1" ] || bail "database $R_DB does not exist (set RESTORE_CREATE_DB=1 to create it)" 2
    sql -e "CREATE DATABASE \`$R_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || bail "cannot create $R_DB"
fi
TABLES="$(sql "$R_DB" -e 'SHOW TABLES' | wc -l)"
[ "$TABLES" -eq 0 ] || bail "target database $R_DB is not empty ($TABLES tables); refusing to restore" 2

# 3. import
gunzip -c "$FILE" | "$CLIENT_BIN" --defaults-extra-file="$CNF" "$R_DB" || bail "import failed"

# 4. content checks
count() { sql "$R_DB" -e "SELECT COUNT(*) FROM $1"; }
SCHEMA="$(sql "$R_DB" -e 'SELECT version FROM app_schema_versions ORDER BY applied_at DESC, version DESC LIMIT 1')"
DATASET="$(sql "$R_DB" -e "SELECT meta_value FROM app_meta WHERE meta_key='dataset_version'")"
[ -n "$SCHEMA" ] || bail "app_schema_versions is empty after restore"
[ -n "$DATASET" ] || bail "dataset_version missing after restore"
PROGRAMS="$(count programs)"; BLOCKS="$(count training_blocks)"; TEMPLATES="$(count workout_templates)"
USERS="$(count users)"; RUNS="$(count user_programs)"; SESSIONS="$(count workout_sessions)"
[ "$PROGRAMS" -ge 1 ] || bail "no program content restored"
[ "$BLOCKS" -ge 5 ] || bail "expected at least 5 training blocks, found $BLOCKS"
[ "$TEMPLATES" -ge 30 ] || bail "expected at least 30 workout templates, found $TEMPLATES"
if [ -n "${EXPECT_MIN_USERS:-}" ] && [ "$USERS" -lt "$EXPECT_MIN_USERS" ]; then bail "expected at least $EXPECT_MIN_USERS users, found $USERS"; fi

SUMMARY="schema=$SCHEMA dataset=$DATASET programs=$PROGRAMS blocks=$BLOCKS templates=$TEMPLATES users=$USERS user_programs=$RUNS workout_sessions=$SESSIONS"
log INFO "RESTORE_OK $SUMMARY"
echo "RESTORE_OK $SUMMARY"
record "OK $SUMMARY"

if [ "${RESTORE_DROP_AFTER:-0}" = "1" ]; then
    sql -e "DROP DATABASE \`$R_DB\`" && log INFO "dropped $R_DB"
fi
