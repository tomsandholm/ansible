#!/bin/bash
set -euo pipefail

WATCH_DIR="/var/www/html/pubkey"
USERS_CSV="${WATCH_DIR}/users.csv"
UPDATE_SCRIPT="/var/www/html/update-users-csv.py"

log() {
    logger -t pubkey-sync -- "$*"
}

process_file() {
    local filepath="$1"
    local filename username

    [[ -f "$filepath" ]] || return 0

    filename=$(basename "$filepath")
    [[ "$filename" != .* ]] || return 0

    if [[ ! "$filename" =~ ^[A-Za-z0-9._-]+$ ]]; then
        log "invalid filename, leaving in place: ${filename}"
        return 1
    fi

    username="${filename%.pub}"
    username="${username%.csv}"
    if [[ ! "$username" =~ ^[A-Za-z0-9_-]+$ ]]; then
        log "invalid username from file: ${filename}"
        rm -f "$filepath"
        return 1
    fi

    # Brief pause so writers can finish flushing the file.
    sleep 0.2

    if python3 "$UPDATE_SCRIPT" "$filepath" "$USERS_CSV"; then
        rm -f "$filepath"
        log "updated public_key for ${username} in users.csv and removed ${filename}"
    else
        log "failed to update users.csv from ${filepath}"
        return 1
    fi
}

if ! command -v inotifywait >/dev/null 2>&1; then
    log "inotifywait not found; install inotify-tools"
    exit 1
fi

if [[ ! -f "$USERS_CSV" ]]; then
    log "users file not found: ${USERS_CSV}"
    exit 1
fi

if [[ ! -x "$UPDATE_SCRIPT" ]]; then
    log "update helper missing or not executable: ${UPDATE_SCRIPT}"
    exit 1
fi

mkdir -p "$WATCH_DIR"

shopt -s nullglob
for existing_file in "$WATCH_DIR"/*; do
    process_file "$existing_file" || true
done

log "watching ${WATCH_DIR} for new public key files"

inotifywait -m -e close_write,moved_to --format '%w%f' "$WATCH_DIR" | while read -r filepath; do
    process_file "$filepath" || true
done
