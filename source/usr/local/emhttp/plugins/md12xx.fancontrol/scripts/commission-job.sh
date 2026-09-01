#!/bin/bash
set -u

SHELF_ID="${1:-}"
JOB_DIR="${2:-}"
PLUGIN_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"

if [[ ! "$SHELF_ID" =~ ^[a-z0-9][a-z0-9_-]{0,47}$ ]] || [ -z "$JOB_DIR" ]; then
  exit 64
fi

mkdir -p "$JOB_DIR"
LOG_FILE="$JOB_DIR/output.log"
EXIT_FILE="$JOB_DIR/exit-code"
STARTED_FILE="$JOB_DIR/started-at"

date +%s > "$STARTED_FILE"
rm -f "$EXIT_FILE"

"$PLUGIN_DIR/scripts/commission.sh" "$SHELF_ID" > "$LOG_FILE" 2>&1
RESULT=$?

printf '%s\n' "$RESULT" > "$EXIT_FILE.tmp"
mv -f "$EXIT_FILE.tmp" "$EXIT_FILE"
exit "$RESULT"
