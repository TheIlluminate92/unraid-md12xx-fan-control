#!/bin/bash
set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
CONFIG_FILE="/boot/config/plugins/md12xx.fancontrol/config.json"
STATE_DIR="/var/run/md12xx.fancontrol"
RESULT_ROOT="/boot/config/plugins/md12xx.fancontrol/commissioning"
SHELF_ID="${1:-}"
WAIT_SECONDS="${MD12XX_TEST_WAIT_SECONDS:-10}"
RESPONSE_SECONDS="${MD12XX_IDENTITY_WAIT_SECONDS:-3}"

if [ "$(id -u)" -ne 0 ]; then echo "Run this test as root from the Unraid terminal." >&2; exit 1; fi
if [ -z "$SHELF_ID" ]; then echo "Usage: $0 <shelf-id>" >&2; exit 1; fi
for REQUIRED in jq flock sg_ses stty sha1sum awk timeout php; do command -v "$REQUIRED" >/dev/null 2>&1 || { echo "$REQUIRED is required." >&2; exit 1; }; done
[ -f "$CONFIG_FILE" ] || { echo "Save the plugin configuration first." >&2; exit 1; }

if jq -e '.enabled == true' "$CONFIG_FILE" >/dev/null; then
  echo "Disable the MD12xx controller before identifying or commissioning hardware." >&2
  exit 1
fi
if [ -f /boot/config/plugins/waz.dashboard/waz.dashboard.cfg ] && grep -Eqi '^MD1200_ENABLED="?(yes|true|1|on)"?$' /boot/config/plugins/waz.dashboard/waz.dashboard.cfg; then
  echo "The WAZ Dashboard MD1200 controller is enabled. Disable it before running this guarded test." >&2
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
ASSIGNMENT="$(jq -r '.diskAssignment // (if ((.disks // []) | length) > 0 then "manual" else "automatic" end)' <<< "$SHELF_JSON")"

[[ "$PORT" == /dev/serial/by-id/* ]] || { echo "Select a persistent serial adapter and save the configuration first." >&2; exit 1; }
[ -e "$PORT" ] || { echo "Serial adapter is missing: $PORT" >&2; exit 1; }

while IFS= read -r NAME; do
  [ -z "$NAME" ] && continue
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -Fxiq "$NAME"; then
    echo "Competing controller is running: $NAME" >&2
    exit 1
  fi
done < <(jq -r '.legacyContainerNames[]?' "$CONFIG_FILE")

STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/${STAMP}-${SHELF_ID}"
mkdir -p "$RESULT_DIR" "$STATE_DIR"
HASH="$(printf '%s' "$PORT" | sha1sum | cut -c1-12)"
LOCK_FILE="$STATE_DIR/serial-${HASH}.lock"
IDENTITY_CAPTURE="$RESULT_DIR/identity.txt"

verify_console() {
  (
    flock -n 9 || { echo "Serial adapter is locked by another process." >&2; exit 1; }
    if command -v fuser >/dev/null 2>&1 && fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
      echo "Serial adapter is open in another process." >&2
      exit 1
    fi
    stty -F "$PORT" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 1 time 0
    timeout "$RESPONSE_SECONDS" cat "$PORT" > "$IDENTITY_CAPTURE" &
    local READER=$!
    sleep 0.25
    exec 8>"$PORT"
    printf '_who\r' >&8
    exec 8>&-
    wait "$READER" 2>/dev/null || true
  ) 9>"$LOCK_FILE"

  local FINGERPRINTS=0
  grep -Eqi 'Host[[:space:]]+Links[[:space:]]+UP[[:space:]]*:' "$IDENTITY_CAPTURE" && FINGERPRINTS=$((FINGERPRINTS + 1))
  grep -Eqi 'Expansion[[:space:]]+Links[[:space:]]+UP[[:space:]]*:' "$IDENTITY_CAPTURE" && FINGERPRINTS=$((FINGERPRINTS + 1))
  grep -Eqi 'Drive\(s\)[[:space:]]*:' "$IDENTITY_CAPTURE" && FINGERPRINTS=$((FINGERPRINTS + 1))
  grep -Eqi 'EMM[[:space:]]*\(' "$IDENTITY_CAPTURE" && FINGERPRINTS=$((FINGERPRINTS + 1))
  grep -Eqi 'Power[[:space:]]+Supplies[[:space:]]*:' "$IDENTITY_CAPTURE" && FINGERPRINTS=$((FINGERPRINTS + 1))
  if [ "$FINGERPRINTS" -lt 4 ] || ! grep -Eqi 'I.?m[[:space:]]+primary[[:space:]]+and[[:space:]]+active' "$IDENTITY_CAPTURE"; then
    echo "The selected adapter did not prove a primary, active MD12xx console. No fan command was sent." >&2
    return 1
  fi
}

send_speed() {
  local SPEED="$1"
  (
    flock -n 9 || { echo "Serial adapter is locked by another process." >&2; exit 1; }
    if command -v fuser >/dev/null 2>&1 && fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
      echo "Serial adapter is open in another process." >&2
      exit 1
    fi
    stty -F "$PORT" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 0 time 1
    exec 8<>"$PORT"
    for _ in 1 2 3 4 5; do printf 'set_speed %s\r' "$SPEED" >&8; sleep 0.1; done
    timeout 1 cat <&8 >/dev/null 2>&1 || true
    exec 8>&-
  ) 9>"$LOCK_FILE"
}

sample_device_rpm() {
  local DEVICE="$1" LABEL="$2" SAFE RAW SPEEDS COUNT AVERAGE
  SAFE="$(basename "$DEVICE")"
  RAW="$RESULT_DIR/${LABEL}-${SAFE}-ses.txt"
  timeout 10 sg_ses -p es "$DEVICE" > "$RAW" 2>&1 || return 1
  SPEEDS="$(sed -n 's/.*Actual speed=\([0-9][0-9]*\) rpm.*/\1/p' "$RAW" | awk '$1 > 0')"
  COUNT="$(printf '%s\n' "$SPEEDS" | sed '/^$/d' | wc -l | tr -d ' ')"
  [ "$COUNT" -ge 2 ] || return 1
  AVERAGE="$(printf '%s\n' "$SPEEDS" | awk '{sum += $1; count++} END {printf "%.0f", sum / count}')"
  printf '%s\t%s\n' "$AVERAGE" "$COUNT"
}

candidate_ses() {
  php -r '
    require $argv[1];
    foreach (md12xx_discover_ses() as $s) {
      if (!empty($s["supportedCandidate"])) echo $s["address"], "\t", $s["device"], "\n";
    }
  ' "$PLUGIN_DIR/include/common.php"
}

restore_safe() {
  echo "Returning the selected MD12xx console to 20%..."
  send_speed 20 || echo "WARNING: the 20% restore command failed; keep other controllers stopped and restore the shelf manually." >&2
}

echo "Verifying the selected serial console with a read-only identity query..."
verify_console
echo "Primary, active MD12xx console verified."

CANDIDATES="$RESULT_DIR/candidates.tsv"
candidate_ses > "$CANDIDATES"
if [ -n "$SES_ADDRESS" ]; then
  awk -F '\t' -v wanted="$SES_ADDRESS" '$1 == wanted' "$CANDIDATES" > "$CANDIDATES.selected"
  mv "$CANDIDATES.selected" "$CANDIDATES"
elif [ -n "$SES_CONFIGURED" ]; then
  awk -F '\t' -v wanted="$SES_CONFIGURED" '$2 == wanted' "$CANDIDATES" > "$CANDIDATES.selected"
  mv "$CANDIDATES.selected" "$CANDIDATES"
fi
[ -s "$CANDIDATES" ] || { echo "No supported MD1200/MD1220 SES enclosure is available for this test." >&2; exit 1; }

LOW="$RESULT_DIR/20-percent.tsv"
HIGH="$RESULT_DIR/50-percent.tsv"
: > "$LOW"; : > "$HIGH"
trap restore_safe EXIT
trap 'restore_safe; trap - EXIT; exit 130' INT TERM

echo "Commanding 20%, waiting ${WAIT_SECONDS}s, then recording every candidate enclosure..."
send_speed 20
sleep "$WAIT_SECONDS"
while IFS=$'\t' read -r ADDRESS DEVICE; do
  if SAMPLE="$(sample_device_rpm "$DEVICE" 20-percent)"; then printf '%s\t%s\t%s\n' "$ADDRESS" "$DEVICE" "$SAMPLE" >> "$LOW"; fi
done < "$CANDIDATES"

echo "Commanding 50%, waiting ${WAIT_SECONDS}s, then looking for the enclosure whose RPM rises..."
send_speed 50
sleep "$WAIT_SECONDS"
while IFS=$'\t' read -r ADDRESS DEVICE; do
  if SAMPLE="$(sample_device_rpm "$DEVICE" 50-percent)"; then printf '%s\t%s\t%s\n' "$ADDRESS" "$DEVICE" "$SAMPLE" >> "$HIGH"; fi
done < "$CANDIDATES"

restore_safe
trap - EXIT INT TERM

MATCHES="$RESULT_DIR/matches.tsv"
awk -F '\t' '
  NR == FNR { low[$1]=$3; next }
  ($1 in low) {
    delta=$3-low[$1]; pct=(low[$1]>0 ? delta/low[$1]*100 : 0);
    if (delta >= 250 && pct >= 10) printf "%s\t%s\t%d\t%d\t%d\t%.1f\n", $1, $2, low[$1], $3, delta, pct;
  }
' "$LOW" "$HIGH" > "$MATCHES"

MATCH_COUNT="$(wc -l < "$MATCHES" | tr -d ' ')"
if [ "$MATCH_COUNT" -ne 1 ]; then
  echo "Identification was ambiguous: expected exactly one responding SES enclosure, found $MATCH_COUNT." >&2
  echo "The 20% command was restored and no hardware mapping was saved." >&2
  echo "Use Manual mapping only after checking the captured results in $RESULT_DIR." >&2
  exit 1
fi

IFS=$'\t' read -r SES_ADDRESS SES_DEVICE RPM_20 RPM_50 DELTA PERCENT < "$MATCHES"
MAPPING_JSON="$(php -r 'require $argv[1]; echo json_encode(md12xx_ses_disk_mapping($argv[2]), JSON_UNESCAPED_SLASHES);' "$PLUGIN_DIR/include/common.php" "$SES_ADDRESS")"
AUTO_DISKS="$(jq -c '.disks // []' <<< "$MAPPING_JSON")"
AUTO_COUNT="$(jq '(.disks // []) | length' <<< "$MAPPING_JSON")"
READY=false
if [ "$ASSIGNMENT" = "manual" ]; then
  [ "$(jq '(.disks // []) | length' <<< "$SHELF_JSON")" -gt 0 ] && READY=true
elif [ "$AUTO_COUNT" -gt 0 ]; then
  READY=true
fi

php -r '
  require $argv[1];
  $config=md12xx_read_config($argv[2]);
  $automaticDisks=json_decode($argv[6], true) ?: [];
  foreach ($config["shelves"] as &$shelf) {
    if ($shelf["id"] !== $argv[3]) continue;
    $shelf["sesAddress"]=$argv[4];
    $shelf["sesDevice"]=$argv[5];
    $assignment=$shelf["diskAssignment"] ?? (!empty($shelf["disks"]) ? "manual" : "automatic");
    if ($assignment === "automatic") $shelf["disks"]=$automaticDisks;
    $shelf["commissioned"]=$argv[7] === "true";
  }
  unset($shelf);
  md12xx_write_config($config, $argv[2]);
' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID" "$SES_ADDRESS" "$SES_DEVICE" "$AUTO_DISKS" "$READY"

{
  echo "MD12xx fan control identification and commissioning"
  echo "Collected: $(date -Is)"
  echo "Shelf: $SHELF_NAME ($MODEL)"
  echo "Serial: $PORT"
  echo "Matched SES: $SES_ADDRESS -> $SES_DEVICE"
  echo "20%: $RPM_20 RPM"
  echo "50%: $RPM_50 RPM"
  echo "Response: PASS (delta +$DELTA RPM, $PERCENT%)"
  echo "Disk assignment: $ASSIGNMENT"
  echo "Automatic disks: $(jq -r 'if length then join(", ") else "none" end' <<< "$AUTO_DISKS")"
  echo "Final command: 20%"
} | tee "$RESULT_DIR/result.txt"

if [ "$READY" = true ]; then
  echo "Identification and commissioning saved. The shelf may now be enabled from Settings."
else
  echo "The serial-to-SES pairing passed, but no usable disk assignment was found." >&2
  echo "The pairing was saved without commissioning. Use Manual mapping, select the shelf disks, save, and rerun this test." >&2
fi

if command -v zip >/dev/null 2>&1; then
  (cd "$RESULT_ROOT" && zip -qr "${STAMP}-${SHELF_ID}.zip" "${STAMP}-${SHELF_ID}")
  echo "Results: $RESULT_ROOT/${STAMP}-${SHELF_ID}.zip"
else
  tar -czf "$RESULT_ROOT/${STAMP}-${SHELF_ID}.tar.gz" -C "$RESULT_ROOT" "${STAMP}-${SHELF_ID}"
  echo "Results: $RESULT_ROOT/${STAMP}-${SHELF_ID}.tar.gz"
fi
[ "$READY" = true ]
