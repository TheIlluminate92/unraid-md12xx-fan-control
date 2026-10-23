#!/bin/bash
set -euo pipefail
RUNTIME=/usr/local/emhttp/plugins/md12xx.fancontrol
STATE=/var/run/md12xx.fancontrol
mkdir -p "$STATE"
exec 8>"$STATE/ipmi-supervisor.lock"
flock -n 8 || exit 1
child=""
finish() {
  trap - EXIT TERM INT
  if [ -n "$child" ]; then
    kill "$child" 2>/dev/null || true
    for ((i=0; i<25; i++)); do kill -0 "$child" 2>/dev/null || break; sleep 1; done
    if kill -0 "$child" 2>/dev/null; then echo 'IPMI controller did not stop; manual recovery required' >&2; exit 1; fi
    wait "$child" 2>/dev/null || true
  fi
  /usr/bin/php "$RUNTIME/include/ipmi-recover.php"
}
trap finish EXIT
trap 'exit 0' TERM INT
while true; do
  /usr/bin/php "$RUNTIME/include/ipmi-controller.php" 8>&- &
  child=$!
  echo "$child" > "$STATE/ipmi-controller.pid"
  while kill -0 "$child" 2>/dev/null; do
    sleep 5
    if [ -f /boot/config/plugins/md12xx.fancontrol/ipmi-owned.json ] && { [ ! -f "$STATE/ipmi-status.json" ] || [ $(( $(date +%s) - $(stat -c %Y "$STATE/ipmi-status.json") )) -gt 40 ]; }; then
      /usr/bin/php "$RUNTIME/include/ipmi-recover.php" || true
    fi
  done
  wait "$child" 2>/dev/null || true
  child=""
  /usr/bin/php "$RUNTIME/include/ipmi-recover.php" || true
  sleep 5
done
