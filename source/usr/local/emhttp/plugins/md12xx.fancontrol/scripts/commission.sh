#!/bin/bash
set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
CONFIG_FILE="/boot/config/plugins/md12xx.fancontrol/config.json"
STATE_DIR="/var/run/md12xx.fancontrol"
RESULT_ROOT="/boot/config/plugins/md12xx.fancontrol/commissioning"
SHELF_ID="${1:-}"
WAIT_SECONDS="${MD12XX_TEST_WAIT_SECONDS:-10}"

if [ "$(id -u)" -ne 0 ]; then echo "Run this test as root from the Unraid terminal." >&2; exit 1; fi
if [ -z "$SHELF_ID" ]; then echo "Usage: $0 <shelf-id>" >&2; exit 1; fi
for REQUIRED in jq flock sg_ses stty sha1sum awk; do command -v "$REQUIRED" >/dev/null 2>&1 || { echo "$REQUIRED is required." >&2; exit 1; }; done
[ -f "$CONFIG_FILE" ] || { echo "Save the plugin configuration first." >&2; exit 1; }

if jq -e '.enabled == true' "$CONFIG_FILE" >/dev/null; then
  echo "Disable the MD12xx controller before commissioning hardware." >&2
  exit 1
fi
if [ -f /boot/config/plugins/waz.dashboard/waz.dashboard.cfg ] && grep -Eqi '^MD1200_ENABLED="?(yes|true|1|on)"?$' /boot/config/plugins/waz.dashboard/waz.dashboard.cfg; then
  echo "The WAZ Dashboard MD1200 controller is enabled. Disable it before commissioning this standalone plugin." >&2
  exit 1
fi
if ! jq -e --arg id "$SHELF_ID" '.shelves[] | select(.id == $id)' "$CONFIG_FILE" >/dev/null; then
  echo "Unknown shelf id: $SHELF_ID" >&2
  exit 1
fi

SHELF_JSON="$(jq -c --arg id "$SHELF_ID" '.shelves[] | select(.id == $id)' "$CONFIG_FILE")"
SHELF_NAME="$(jq -r '.name' <<< "$SHELF_JSON")"
MODEL="$(jq -r '.model' <<< "$SHELF_JSON")"
PORT="$(jq -r '.serialPort' <<< "$SHELF_JSON")"
SES_ADDRESS="$(jq -r '.sesAddress' <<< "$SHELF_JSON")"
SES_CONFIGURED="$(jq -r '.sesDevice' <<< "$SHELF_JSON")"

[[ "$PORT" == /dev/serial/by-id/* ]] || { echo "Configure a persistent serial adapter path first." >&2; exit 1; }
[ -e "$PORT" ] || { echo "Serial adapter is missing: $PORT" >&2; exit 1; }

while IFS= read -r NAME; do
  [ -z "$NAME" ] && continue
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -Fxiq "$NAME"; then
    echo "Competing controller is running: $NAME" >&2
    exit 1
  fi
done < <(jq -r '.legacyContainerNames[]?' "$CONFIG_FILE")

resolve_ses() {
  local ADDRESS="$1" GENERIC RESOLVED
  if [ -n "$ADDRESS" ]; then
    for GENERIC in /sys/class/scsi_generic/sg*; do
      [ -e "$GENERIC/device" ] || continue
      RESOLVED="$(readlink -f "$GENERIC/device")"
      if [ "$(basename "$RESOLVED")" = "$ADDRESS" ]; then echo "/dev/$(basename "$GENERIC")"; return 0; fi
    done
  fi
  [ -n "$SES_CONFIGURED" ] && [ -e "$SES_CONFIGURED" ] && { echo "$SES_CONFIGURED"; return 0; }
  return 1
}

SES_DEVICE="$(resolve_ses "$SES_ADDRESS")" || { echo "SES enclosure was not found for $SHELF_NAME." >&2; exit 1; }
STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/${STAMP}-${SHELF_ID}"
mkdir -p "$RESULT_DIR" "$STATE_DIR"
SUMMARY="$RESULT_DIR/summary.csv"
printf 'shelf,model,command_percent,wait_seconds,fan_count,average_rpm,fan_rpms\n' > "$SUMMARY"

HASH="$(printf '%s' "$PORT" | sha1sum | cut -c1-12)"
LOCK_FILE="$STATE_DIR/serial-${HASH}.lock"
send_speed() {
  local SPEED="$1"
  (
    flock -n 9 || { echo "Serial adapter is locked by another process." >&2; exit 1; }
    if command -v fuser >/dev/null 2>&1 && fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
      echo "Serial adapter is open in another process." >&2
      exit 1
    fi
    stty -F "$PORT" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb
    exec 8>"$PORT"
    for _ in 1 2 3 4 5; do printf 'set_speed %s\r' "$SPEED" >&8; sleep 0.1; done
    exec 8>&-
  ) 9>"$LOCK_FILE"
}

sample_rpm() {
  local LABEL="$1" COMMAND="$2" RAW SPEEDS COUNT AVERAGE LIST
  RAW="$RESULT_DIR/${LABEL}-ses.txt"
  sg_ses -p es "$SES_DEVICE" > "$RAW" 2>&1
  SPEEDS="$(sed -n 's/.*Actual speed=\([0-9][0-9]*\) rpm.*/\1/p' "$RAW" | awk '$1 > 0')"
  COUNT="$(printf '%s\n' "$SPEEDS" | sed '/^$/d' | wc -l | tr -d ' ')"
  [ "$COUNT" -ge 2 ] || { echo "$SHELF_NAME reported only $COUNT non-zero fan values." >&2; return 1; }
  AVERAGE="$(printf '%s\n' "$SPEEDS" | awk '{sum += $1; count++} END {printf "%.0f", sum / count}')"
  LIST="$(printf '%s\n' "$SPEEDS" | paste -sd';' -)"
  printf '%s,%s,%s,%s,%s,%s,%s\n' "$SHELF_ID" "$MODEL" "$COMMAND" "$WAIT_SECONDS" "$COUNT" "$AVERAGE" "$LIST" >> "$SUMMARY"
  echo "$AVERAGE"
}

restore_safe() { set +e; echo "Returning $SHELF_NAME to 20%..."; send_speed 20; }
trap restore_safe EXIT
trap 'restore_safe; trap - EXIT; exit 130' INT TERM

echo "Commissioning $SHELF_NAME ($MODEL) on $SES_DEVICE"
BASELINE="$(sample_rpm baseline baseline)"
echo "Commanding 20%, waiting ${WAIT_SECONDS}s..."
send_speed 20; sleep "$WAIT_SECONDS"; RPM_20="$(sample_rpm 20-percent 20)"
echo "Commanding 50%, waiting ${WAIT_SECONDS}s..."
send_speed 50; sleep "$WAIT_SECONDS"; RPM_50="$(sample_rpm 50-percent 50)"
restore_safe
trap - EXIT INT TERM

RESULT="$(awk -v low="$RPM_20" -v high="$RPM_50" 'BEGIN {delta=high-low; pct=(low>0?delta/low*100:0); printf "%s|%d|%.1f", (delta>=250 && pct>=10?"PASS":"REVIEW"), delta, pct}')"
STATUS="${RESULT%%|*}"; REST="${RESULT#*|}"; DELTA="${REST%%|*}"; PERCENT="${REST#*|}"
{
  echo "MD12xx fan control commissioning"
  echo "Collected: $(date -Is)"
  echo "Shelf: $SHELF_NAME ($MODEL)"
  echo "SES: $SES_ADDRESS -> $SES_DEVICE"
  echo "Serial: $PORT"
  echo "Baseline: $BASELINE RPM"
  echo "20%: $RPM_20 RPM"
  echo "50%: $RPM_50 RPM"
  echo "Response: $STATUS (delta +$DELTA RPM, $PERCENT%)"
  echo "Final command: 20%"
} | tee "$RESULT_DIR/result.txt"

if [ "$STATUS" = "PASS" ]; then
  php -r 'require $argv[1]; $c=md12xx_read_config($argv[2]); foreach ($c["shelves"] as &$s) { if ($s["id"] === $argv[3]) $s["commissioned"] = true; } unset($s); md12xx_write_config($c, $argv[2]);' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID"
  echo "Commissioning saved. The shelf may now be enabled from the Settings page."
else
  echo "Response requires review; commissioning was not granted." >&2
fi

if command -v zip >/dev/null 2>&1; then
  (cd "$RESULT_ROOT" && zip -qr "${STAMP}-${SHELF_ID}.zip" "${STAMP}-${SHELF_ID}")
  echo "Results: $RESULT_ROOT/${STAMP}-${SHELF_ID}.zip"
else
  tar -czf "$RESULT_ROOT/${STAMP}-${SHELF_ID}.tar.gz" -C "$RESULT_ROOT" "${STAMP}-${SHELF_ID}"
  echo "Results: $RESULT_ROOT/${STAMP}-${SHELF_ID}.tar.gz"
fi
[ "$STATUS" = "PASS" ]
