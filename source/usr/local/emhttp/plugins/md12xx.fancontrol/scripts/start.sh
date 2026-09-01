#!/bin/bash
set -e

RUNTIME_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
STATE_DIR="/var/run/md12xx.fancontrol"
PID_FILE="$STATE_DIR/controller.pid"
DISCOVERY_PID_FILE="$STATE_DIR/discovery.pid"
mkdir -p "$STATE_DIR"

pid_matches() {
  local pid_file="$1"
  local marker="$2"
  local pid
  [ -s "$pid_file" ] || return 1
  pid="$(cat "$pid_file" 2>/dev/null || true)"
  [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && [ -r "/proc/$pid/cmdline" ] \
    && tr '\0' ' ' < "/proc/$pid/cmdline" | grep -Fq "$marker"
}

if ! pid_matches "$PID_FILE" '/md12xx.fancontrol/include/controller.php'; then
  rm -f "$PID_FILE"
  nohup /usr/bin/php "$RUNTIME_DIR/include/controller.php" >/dev/null 2>&1 &
  echo "$!" > "$PID_FILE"
fi

if ! pid_matches "$DISCOVERY_PID_FILE" '/md12xx.fancontrol/include/discovery.php'; then
  rm -f "$DISCOVERY_PID_FILE"
  nohup /usr/bin/php "$RUNTIME_DIR/include/discovery.php" >/dev/null 2>&1 &
  echo "$!" > "$DISCOVERY_PID_FILE"
fi
