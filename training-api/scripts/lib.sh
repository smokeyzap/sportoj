#!/usr/bin/env bash
# Shared helpers for backup_database.sh and restore_verify.sh. Sourced, not executed.

log() { printf '%s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" >&2; }

alert() {
    # Optional operational notification (BACKUP_RESTORE section 6, step 7). Never include secrets in $1.
    if [ -n "${BACKUP_ALERT_COMMAND:-}" ]; then
        BACKUP_ALERT_MESSAGE="$1" bash -c "$BACKUP_ALERT_COMMAND" >/dev/null 2>&1 || true
    fi
}

fail() {
    log ERROR "$1"
    alert "$1"
    exit "${2:-1}"
}

# Load KEY=VALUE pairs from an env file WITHOUT executing it. Real environment variables win.
load_env_file() {
    local file="$1" line key value
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|'#'*) continue ;; esac
        line="${line#export }"
        key="${line%%=*}"
        value="${line#*=}"
        [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
        if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then
            value="${value:1:${#value}-2}"
        else
            value="${value%% \#*}"
        fi
        if [ -z "${!key+x}" ]; then
            export "$key=$value"
        fi
    done < "$file"
}

pick_bin() {
    local want
    for want in "$@"; do
        if command -v "$want" >/dev/null 2>&1; then
            command -v "$want"
            return 0
        fi
    done
    return 1
}

# Write a mode-0600 client option file so the password never shows up in the process list.
write_client_cnf() {
    local file="$1" host="$2" port="$3" user="$4" pass="$5" socket="${6:-}"
    ( umask 077; : > "$file" )
    {
        echo "[client]"
        echo "user=\"$user\""
        echo "password=\"${pass//\\/\\\\}\""
        if [ -n "$socket" ]; then
            echo "socket=$socket"
        else
            echo "host=$host"
            echo "port=$port"
        fi
    } >> "$file"
}
