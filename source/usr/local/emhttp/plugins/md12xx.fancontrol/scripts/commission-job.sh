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
RESULT_DIR_FILE="$JOB_DIR/result-directory"
COMMISSION_MARKER="/var/run/md12xx.fancontrol/commissioning.active"
CHILD_PID=""

stop_child() {
  if [ -n "$CHILD_PID" ] && kill -0 "$CHILD_PID" 2>/dev/null; then
    kill -TERM "$CHILD_PID" 2>/dev/null || true
    wait "$CHILD_PID" 2>/dev/null || true
  fi
  rm -f "$COMMISSION_MARKER"
}
trap stop_child INT TERM EXIT

date +%s > "$STARTED_FILE"
rm -f "$EXIT_FILE"
rm -f "$RESULT_DIR_FILE"

MD12XX_JOB_DIR="$JOB_DIR" "$PLUGIN_DIR/scripts/commission.sh" "$SHELF_ID" > "$LOG_FILE" 2>&1 &
CHILD_PID=$!
wait "$CHILD_PID"
RESULT=$?
CHILD_PID=""

# A failed test is when its captures are most useful. Package the guarded
# result directory even when commission.sh exited before its normal archive
# step, so Settings can download the evidence without requiring a terminal.
if ! grep -Eq '^Results:[[:space:]]+.+\.(zip|tar\.gz)[[:space:]]*$' "$LOG_FILE" 2>/dev/null && [ -s "$RESULT_DIR_FILE" ]; then
  RESULT_DIR="$(cat "$RESULT_DIR_FILE" 2>/dev/null || true)"
  RESULT_ROOT="/boot/config/plugins/md12xx.fancontrol/commissioning"
  case "$RESULT_DIR" in
    "$RESULT_ROOT"/*-"$SHELF_ID")
      if [ -d "$RESULT_DIR" ]; then
        RESULT_NAME="$(basename "$RESULT_DIR")"
        tar -czf "$RESULT_ROOT/${RESULT_NAME}.tar.gz" -C "$RESULT_ROOT" "$RESULT_NAME"
        printf 'Results: %s\n' "$RESULT_ROOT/${RESULT_NAME}.tar.gz" >> "$LOG_FILE"
      fi
      ;;
  esac
fi

printf '%s\n' "$RESULT" > "$EXIT_FILE.tmp"
mv -f "$EXIT_FILE.tmp" "$EXIT_FILE"
trap - INT TERM EXIT
rm -f "$COMMISSION_MARKER"
exit "$RESULT"
