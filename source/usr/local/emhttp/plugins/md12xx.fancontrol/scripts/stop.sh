#!/bin/bash
set -e

STATE_DIR="/var/run/md12xx.fancontrol"
PID_FILE="$STATE_DIR/controller.pid"
stop_pid() {
  local FILE="$1" PATTERN="$2" PID
  if [ -s "$FILE" ]; then
    PID="$(cat "$FILE" 2>/dev/null || true)"
    if [ -n "$PID" ] && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -Fq "$PATTERN"; then
      kill "$PID" 2>/dev/null || true
      for _ in 1 2 3 4 5; do kill -0 "$PID" 2>/dev/null || break; sleep 1; done
    fi
  fi
  rm -f "$FILE"
}
stop_pid "$PID_FILE" '/md12xx.fancontrol/include/controller.php'
stop_pid "$STATE_DIR/discovery.pid" '/md12xx.fancontrol/include/discovery.php'
rm -f "$STATE_DIR"/serial-*.lock
