#!/usr/bin/env bash
# Daily consistent logical backup with daily/weekly/monthly retention (BACKUP_RESTORE.md, BR-200..BR-203).
#
# Configuration (environment or .env next to the project; real environment wins):
#   DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_DATABASE [DB_SOCKET]
#   BACKUP_DIR              absolute path OUTSIDE the public webroot (required)
#   BACKUP_KEEP_DAILY=14  BACKUP_KEEP_WEEKLY=8  BACKUP_KEEP_MONTHLY=6
#   BACKUP_MIN_BYTES=2048   minimum plausible size of the compressed dump
#   BACKUP_REMOTE_COMMAND   optional; run per tier with $BACKUP_FILE, $BACKUP_TIER set (second copy, BR-202)
#   BACKUP_ALERT_COMMAND    optional; run on failure with $BACKUP_ALERT_MESSAGE set
#   BACKUP_NOW              optional UTC timestamp YYYYmmddTHHMMSSZ (tests / back-filling)
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=scripts/lib.sh
. "$SCRIPT_DIR/lib.sh"
load_env_file "${ENV_FILE:-$ROOT/.env}"

: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"
DB_HOST="${DB_HOST:-localhost}"; DB_PORT="${DB_PORT:-3306}"; DB_PASSWORD="${DB_PASSWORD:-}"
KEEP_DAILY="${BACKUP_KEEP_DAILY:-14}"; KEEP_WEEKLY="${BACKUP_KEEP_WEEKLY:-8}"; KEEP_MONTHLY="${BACKUP_KEEP_MONTHLY:-6}"
MIN_BYTES="${BACKUP_MIN_BYTES:-2048}"

case "$BACKUP_DIR" in /*) ;; *) fail "BACKUP_DIR must be an absolute path" ;; esac
case "$BACKUP_DIR/" in "$ROOT/public/"*|*/public_html/*|*/public/*) fail "BACKUP_DIR must be outside the public webroot (BR-201)" ;; esac
for n in "$KEEP_DAILY" "$KEEP_WEEKLY" "$KEEP_MONTHLY" "$MIN_BYTES"; do
    [[ "$n" =~ ^[0-9]+$ ]] || fail "retention and size settings must be non-negative integers"
done

DUMP_BIN="$(pick_bin mariadb-dump mysqldump)" || fail "neither mariadb-dump nor mysqldump found"
CLIENT_BIN="$(pick_bin mariadb mysql)" || fail "neither mariadb nor mysql client found"

TS="${BACKUP_NOW:-$(date -u +%Y%m%dT%H%M%SZ)}"
[[ "$TS" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || fail "BACKUP_NOW must look like 20260817T210000Z"
DAY="${TS:0:8}"
DOW="$(date -u -d "$DAY" +%u)"      # 7 = Sunday
DOM="${TS:6:2}"

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly" "$BACKUP_DIR/monthly"
TMP="$(mktemp -d "$BACKUP_DIR/.work.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
CNF="$TMP/client.cnf"
write_client_cnf "$CNF" "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" "${DB_SOCKET:-}"

SCHEMA_VERSION="$("$CLIENT_BIN" --defaults-extra-file="$CNF" -N -B "$DB_DATABASE" \
    -e 'SELECT version FROM app_schema_versions ORDER BY applied_at DESC, version DESC LIMIT 1' 2>/dev/null | tr -cd '0-9.' || true)"
SCHEMA_VERSION="${SCHEMA_VERSION:-unknown}"
NAME="trainingsapp_${TS}_schema-${SCHEMA_VERSION}.sql.gz"
PARTIAL="$TMP/$NAME"

log INFO "dumping $DB_DATABASE to $NAME"
# pipefail makes a failing dump (not only gzip) fail the pipeline: both exit codes are checked (step 1+2).
if ! "$DUMP_BIN" --defaults-extra-file="$CNF" \
        --single-transaction --quick --triggers --default-character-set=utf8mb4 \
        "$DB_DATABASE" | gzip -c > "$PARTIAL"; then
    fail "dump or compression failed for $DB_DATABASE"
fi

SIZE="$(wc -c < "$PARTIAL")"
[ "$SIZE" -ge "$MIN_BYTES" ] || fail "backup is only $SIZE bytes (minimum $MIN_BYTES); refusing to keep it"
gzip -t "$PARTIAL" || fail "gzip integrity test failed"
# grep -c reads everything: with `grep -q` an early exit would SIGPIPE gunzip and trip pipefail.
TABLE_COUNT="$(gunzip -c "$PARTIAL" | grep -c 'CREATE TABLE' || true)"
[ "${TABLE_COUNT:-0}" -ge 1 ] || fail "dump does not contain any CREATE TABLE statement"
INSERT_COUNT="$(gunzip -c "$PARTIAL" | grep -c '^INSERT INTO' || true)"
[ "${INSERT_COUNT:-0}" -ge 1 ] || fail "dump does not contain any INSERT statement (no data)"
( cd "$TMP" && sha256sum "$NAME" > "$NAME.sha256" )

FINAL="$BACKUP_DIR/daily/$NAME"
mv "$PARTIAL" "$FINAL"
mv "$TMP/$NAME.sha256" "$FINAL.sha256"
( cd "$BACKUP_DIR/daily" && sha256sum -c "$NAME.sha256" >/dev/null ) || fail "checksum verification failed"
log INFO "daily backup ok: $FINAL ($SIZE bytes)"

copy_tier() {
    local tier="$1"
    cp "$FINAL" "$BACKUP_DIR/$tier/$NAME"
    cp "$FINAL.sha256" "$BACKUP_DIR/$tier/$NAME.sha256"
    log INFO "$tier snapshot: $BACKUP_DIR/$tier/$NAME"
}
[ "$DOW" = "7" ] && copy_tier weekly
[ "$DOM" = "01" ] && copy_tier monthly

REMOTE_FAILED=0
if [ -n "${BACKUP_REMOTE_COMMAND:-}" ]; then
    tiers=(daily)
    [ "$DOW" = "7" ] && tiers+=(weekly)
    [ "$DOM" = "01" ] && tiers+=(monthly)
    for tier in "${tiers[@]}"; do
        if ! BACKUP_FILE="$BACKUP_DIR/$tier/$NAME" BACKUP_TIER="$tier" bash -c "$BACKUP_REMOTE_COMMAND"; then
            log ERROR "remote copy failed for tier $tier"
            REMOTE_FAILED=1
        fi
    done
fi

if [ "$REMOTE_FAILED" -ne 0 ]; then
    # Keep every local restore point until the second copy works again.
    fail "backup stored locally but the external copy failed; retention was not applied" 3
fi

# Retention only after a verified backup (step 6): keep the newest N per tier (names sort chronologically).
prune() {
    local tier="$1" keep="$2" f
    # find exits 0 when the tier is still empty (a bare `ls glob` would exit 2 and, with pipefail + set -e, abort the script).
    find "$BACKUP_DIR/$tier" -maxdepth 1 -type f -name 'trainingsapp_*.sql.gz' | sort -r | tail -n +"$((keep + 1))" | while read -r f; do
        rm -f -- "$f" "$f.sha256"
        log INFO "retention: removed $f"
    done
}
prune daily "$KEEP_DAILY"
prune weekly "$KEEP_WEEKLY"
prune monthly "$KEEP_MONTHLY"
log INFO "done"
