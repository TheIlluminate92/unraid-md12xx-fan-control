#!/bin/bash
set -u

RUNTIME_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
STATE_DIR="/var/run/md12xx.fancontrol"
SUPERVISOR_PID_FILE="$STATE_DIR/supervisor.pid"
CONTROLLER_PID_FILE="$STATE_DIR/controller.pid"
WATCHDOG_FILE="$STATE_DIR/watchdog.json"
CONTROLLER_LOG="$STATE_DIR/controller.log"
NOTIFY_SCRIPT="/usr/local/emhttp/webGui/scripts/notify"
ALERT_STAMP_FILE="$STATE_DIR/watchdog-alert.stamp"
MAX_LOG_BYTES=65536
ALERT_INTERVAL=900

mkdir -p "$STATE_DIR"
printf '%s\n' "$$" > "$SUPERVISOR_PID_FILE"

running=true
child_pid=""
restart_count=0
consecutive_failures=0
failure_notified=false

json_message() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/[[:cntrl:]]/ /g'
}

write_watchdog() {
  local state="$1" message="$2" delay="${3:-0}" child="${4:-0}" tmp
  tmp="$WATCHDOG_FILE.tmp.$$"
  printf '{\n  "generatedAt": %s,\n  "state": "%s",\n  "message": "%s",\n  "restartCount": %s,\n  "restartDelaySeconds": %s,\n  "controllerPid": %s\n}\n' \
    "$(date +%s)" "$state" "$(json_message "$message")" "$restart_count" "$delay" "$child" > "$tmp"
  mv -f "$tmp" "$WATCHDOG_FILE"
}

notify_local() {
  local importance="$1" subject="$2" description="$3" now last=0
  now="$(date +%s)"
  [ -r "$ALERT_STAMP_FILE" ] && last="$(cat "$ALERT_STAMP_FILE" 2>/dev/null || echo 0)"
  if [ "$importance" != "normal" ] && [ $((now - last)) -lt "$ALERT_INTERVAL" ]; then
    return
  fi
  printf '%s\n' "$now" > "$ALERT_STAMP_FILE"
  if [ -x "$NOTIFY_SCRIPT" ]; then
    "$NOTIFY_SCRIPT" -e "MD12xx Fan Control" -s "$subject" -d "$description" -i "$importance" >/dev/null 2>&1 || true
  else
    logger -t md12xx.fancontrol "$subject: $description" 2>/dev/null || true
  fi
}

process_alive() {
  local pid="$1" state
  kill -0 "$pid" 2>/dev/null || return 1
  [ -r "/proc/$pid/stat" ] || return 1
  state="$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || true)"
  [ "$state" != "Z" ]
}

trim_log() {
  local size tmp
  [ -f "$CONTROLLER_LOG" ] || return
  size="$(wc -c < "$CONTROLLER_LOG" 2>/dev/null || echo 0)"
  [ "$size" -le "$MAX_LOG_BYTES" ] && return
  tmp="$CONTROLLER_LOG.tmp.$$"
  tail -c "$MAX_LOG_BYTES" "$CONTROLLER_LOG" > "$tmp" 2>/dev/null && mv -f "$tmp" "$CONTROLLER_LOG"
}

shutdown() {
  running=false
  if [ -n "$child_pid" ] && process_alive "$child_pid"; then
    kill "$child_pid" 2>/dev/null || true
    for _ in 1 2 3 4 5; do
      process_alive "$child_pid" || break
      sleep 1
    done
  fi
}

cleanup() {
  rm -f "$SUPERVISOR_PID_FILE"
  if [ -z "$child_pid" ] || ! process_alive "$child_pid"; then
    rm -f "$CONTROLLER_PID_FILE"
  fi
  write_watchdog "stopped" "Controller supervisor stopped intentionally" 0 0
}

trap shutdown INT TERM
trap cleanup EXIT

while $running; do
  trim_log
  started_at="$(date +%s)"
  /usr/bin/php "$RUNTIME_DIR/include/controller.php" >> "$CONTROLLER_LOG" 2>&1 &
  child_pid="$!"
  printf '%s\n' "$child_pid" > "$CONTROLLER_PID_FILE"
  if [ "$restart_count" -gt 0 ]; then
    write_watchdog "recovering" "Controller restarted; monitoring recovery" 0 "$child_pid"
  else
    write_watchdog "normal" "Controller is running under supervision" 0 "$child_pid"
  fi
  recovered=false

  while $running && process_alive "$child_pid"; do
    alive_for=$(( $(date +%s) - started_at ))
    if [ "$alive_for" -ge 60 ] && [ "$recovered" = false ]; then
      recovered=true
      consecutive_failures=0
      write_watchdog "normal" "Controller is running under supervision" 0 "$child_pid"
      if $failure_notified; then
        notify_local normal "Controller recovered" "The fan controller restarted and has remained healthy for 60 seconds."
        failure_notified=false
      fi
    fi
    sleep 1
  done

  wait "$child_pid" 2>/dev/null
  exit_code="$?"
  rm -f "$CONTROLLER_PID_FILE"
  child_pid=""
  $running || break

  restart_count=$((restart_count + 1))
  consecutive_failures=$((consecutive_failures + 1))
  if [ "$consecutive_failures" -ge 5 ]; then
    delay=60
  else
    delay=$((5 << (consecutive_failures - 1)))
  fi
  write_watchdog "restarting" "Controller exited unexpectedly (exit $exit_code); restart scheduled" "$delay" 0
  notify_local alert "Controller stopped unexpectedly" "The plugin will restart the fan controller in $delay seconds. Open Settings > Utilities > MD12xx Fan Control to review status."
  failure_notified=true

  for ((remaining=delay; remaining>0; remaining--)); do
    $running || break
    sleep 1
  done
done
