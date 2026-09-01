#!/bin/bash
set -e

STATE_DIR="/var/run/md12xx.fancontrol"
PID_FILE="$STATE_DIR/controller.pid"
if [ -s "$PID_FILE" ]; then
  PID="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [ -n "$PID" ] && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -q '/md12xx.fancontrol/include/controller.php'; then
    kill "$PID" 2>/dev/null || true
    for _ in 1 2 3 4 5; do kill -0 "$PID" 2>/dev/null || break; sleep 1; done
  fi
fi
rm -f "$PID_FILE" "$STATE_DIR"/serial-*.lock

