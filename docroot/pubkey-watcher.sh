#!/bin/bash
set -euo pipefail

WORKDIR="/var/www/html"
PUBKEY_DIR="${WORKDIR}/pubkey"
USERS_LIST="${WORKDIR}/users.list"
UPDATE_SCRIPT="${WORKDIR}/update-users-list.py"

log() {
    logger -t pubkey-watcher -- "$*"
}

process_pubkey_file() {
    local filepath="$1"
    local filename username

    [[ -f "$filepath" ]] || return 0

    filename=$(basename "$filepath")
    [[ "$filename" == *.csv ]] || return 0

    username="${filename%.csv}"
    if [[ ! "$username" =~ ^[A-Za-z0-9_-]+$ ]]; then
        log "invalid username from file: ${filename}"
        rm -f "$filepath"
        return 1
    fi

    # Brief pause so writers can finish flushing the file.
    sleep 0.2

    if python3 "$UPDATE_SCRIPT" "$filepath" "$USERS_LIST" "$username"; then
        rm -f "$filepath"
        log "updated pub_key for ${username} in users.list"
    else
        log "failed to process ${filepath}"
        return 1
    fi
}

if ! command -v inotifywait >/dev/null 2>&1; then
    log "inotifywait not found; install inotify-tools"
    exit 1
fi

if [[ ! -x "$UPDATE_SCRIPT" ]]; then
    log "update helper missing or not executable: ${UPDATE_SCRIPT}"
    exit 1
fi

mkdir -p "$PUBKEY_DIR"

shopt -s nullglob
for existing_file in "$PUBKEY_DIR"/*.csv; do
    process_pubkey_file "$existing_file" || true
done

log "watching ${PUBKEY_DIR} for new public key files"

inotifywait -m -e close_write,moved_to --format '%w%f' "$PUBKEY_DIR" | while read -r filepath; do
    process_pubkey_file "$filepath" || true
done
