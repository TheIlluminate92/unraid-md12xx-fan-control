#!/bin/bash
set -e

RUNTIME_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
STATE_DIR="/var/run/md12xx.fancontrol"
PID_FILE="$STATE_DIR/controller.pid"
mkdir -p "$STATE_DIR"

if [ -s "$PID_FILE" ]; then
  PID="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -q '/md12xx.fancontrol/include/controller.php'; then
    exit 0
  fi
fi

rm -f "$PID_FILE"
nohup /usr/bin/php "$RUNTIME_DIR/include/controller.php" >/dev/null 2>&1 &
echo "$!" > "$PID_FILE"

