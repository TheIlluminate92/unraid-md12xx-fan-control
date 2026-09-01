#!/bin/bash
set -e

STATE_DIR="/var/run/md12xx.fancontrol"
PID_FILE="$STATE_DIR/controller.pid"
stop_pid() {
  local FILE="$1" PATTERN="$2" WAIT_COUNT="${3:-5}" PID
  if [ -s "$FILE" ]; then
    PID="$(cat "$FILE" 2>/dev/null || true)"
    if [ -n "$PID" ] && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -Fq "$PATTERN"; then
      kill "$PID" 2>/dev/null || true
      for ((SECOND=0; SECOND<WAIT_COUNT; SECOND++)); do kill -0 "$PID" 2>/dev/null || break; sleep 1; done
      if kill -0 "$PID" 2>/dev/null; then
        echo "Unable to stop $PATTERN safely; plugin files were not removed." >&2
        return 1
      fi
    fi
  fi
  rm -f "$FILE"
}
stop_pid "$PID_FILE" '/md12xx.fancontrol/include/controller.php' 20
stop_pid "$STATE_DIR/discovery.pid" '/md12xx.fancontrol/include/discovery.php' 20
stop_pid "$STATE_DIR/diagnostics.pid" '/md12xx.fancontrol/scripts/diagnose.sh' 20
for JOB_PID_FILE in "$STATE_DIR"/commission-jobs/*/pid; do
  [ -e "$JOB_PID_FILE" ] || continue
  stop_pid "$JOB_PID_FILE" '/md12xx.fancontrol/scripts/commission-job.sh' 20
done
rm -f "$STATE_DIR/commissioning.active"
rm -f "$STATE_DIR"/serial-*.lock
rm -f "$STATE_DIR/diagnostics.lock"
