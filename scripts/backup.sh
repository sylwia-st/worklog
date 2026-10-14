#!/usr/bin/env bash
# Kopia bazy co godzinę (timer systemd na hoście). Zostawia kopie z ostatnich 30 dni;
# jeśli od poprzedniej kopii nic się nie zmieniło, nowej nie zostawia.
set -uo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${WORKLOG_BACKUP_DIR:-$HOME/worklog-backups}"
KEEP_DAYS="${WORKLOG_BACKUP_DAYS:-30}"
mkdir -p "$DEST"
chmod 700 "$DEST"

docker inspect -f '{{.State.Running}}' worklog 2>/dev/null | grep -q true || exit 0

new="$DEST/worklog-$(date +%Y%m%d-%H%M).db"
if ! "$DIR/worklog" backup "$new" >/dev/null 2>"$DEST/.last-error"; then
    command -v notify-send >/dev/null && notify-send --app-name=Worklog --urgency=critical \
        "Raport czasu: kopia zapasowa się nie udała" "$(head -c 300 "$DEST/.last-error")"
    exit 1
fi
rm -f "$DEST/.last-error"

# identyczna jak poprzednia → usuń duplikat (zostaje starsza z tą samą treścią)
prev="$(ls -1t "$DEST"/worklog-*.db 2>/dev/null | sed -n 2p)"
if [[ -n "$prev" ]] && cmp -s "$new" "$prev"; then
    rm -f "$new"
fi

find "$DEST" -maxdepth 1 -name 'worklog-*.db' -mtime +"$KEEP_DAYS" -delete
# pozostałości po przerwanej kopii
find "$DEST" -maxdepth 1 -name '*.part' -mmin +60 -delete
