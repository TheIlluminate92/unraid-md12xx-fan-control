#!/bin/bash
set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
CONFIG_FILE="/boot/config/plugins/md12xx.fancontrol/config.json"
STATE_DIR="/var/run/md12xx.fancontrol"
RESULT_ROOT="/boot/config/plugins/md12xx.fancontrol/commissioning"
COMMISSION_MARKER="$STATE_DIR/commissioning.active"
SHELF_ID="${1:-}"
MODE="${2:-automatic}"
BASELINE_WAIT_SECONDS="${MD12XX_TEST_BASELINE_SECONDS:-30}"
RESPONSE_TIMEOUT_SECONDS="${MD12XX_TEST_RESPONSE_TIMEOUT_SECONDS:-60}"
SAMPLE_INTERVAL_SECONDS="${MD12XX_TEST_SAMPLE_INTERVAL_SECONDS:-5}"
RESPONSE_SECONDS="${MD12XX_IDENTITY_WAIT_SECONDS:-3}"
SPEED_RESPONSE_SECONDS="${MD12XX_SPEED_RESPONSE_SECONDS:-4}"
RESTORE_WAIT_SECONDS="${MD12XX_RESTORE_WAIT_SECONDS:-30}"

if [ "$(id -u)" -ne 0 ]; then echo "The commissioning service requires administrator privileges." >&2; exit 1; fi
if [ -z "$SHELF_ID" ]; then echo "Usage: $0 <shelf-id>" >&2; exit 1; fi
[[ "$MODE" == automatic || "$MODE" == manual-identify ]] || { echo "Invalid identification mode." >&2; exit 1; }
for REQUIRED in jq flock fuser sg_ses stty sha1sum awk timeout php; do command -v "$REQUIRED" >/dev/null 2>&1 || { echo "$REQUIRED is required." >&2; exit 1; }; done
[ -f "$CONFIG_FILE" ] || { echo "Save the plugin configuration first." >&2; exit 1; }
[[ "$BASELINE_WAIT_SECONDS" =~ ^[1-9][0-9]*$ ]] || { echo "The commissioning baseline window must be a positive number of seconds." >&2; exit 1; }
[[ "$RESPONSE_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]] || { echo "The commissioning response timeout must be a positive number of seconds." >&2; exit 1; }
[[ "$SAMPLE_INTERVAL_SECONDS" =~ ^[1-9][0-9]*$ ]] || { echo "The commissioning sample interval must be a positive number of seconds." >&2; exit 1; }

if jq -e '.enabled == true' "$CONFIG_FILE" >/dev/null; then
  echo "Disable the MD12xx controller before identifying or commissioning hardware." >&2
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

PORT_IDENTIFIER="${PORT#/dev/serial/by-id/}"
[[ "$PORT" == /dev/serial/by-id/* && -n "$PORT_IDENTIFIER" && "$PORT_IDENTIFIER" != "." && "$PORT_IDENTIFIER" != ".." && "$PORT_IDENTIFIER" != */* && "$PORT_IDENTIFIER" != *\\* ]] || {
  echo "Select a valid persistent serial adapter and save the configuration first." >&2
  exit 1
}
[ -e "$PORT" ] || { echo "Serial adapter is missing: $PORT" >&2; exit 1; }

if [ "$(php -r 'require $argv[1]; echo count(md12xx_competing_controllers(md12xx_read_config($argv[2])));' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE")" -gt 0 ]; then
  echo "Another fan controller is active. Disable it, then retry Identify & test." >&2
  exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/${STAMP}-${SHELF_ID}"
mkdir -p "$RESULT_DIR" "$STATE_DIR"
if [ -n "${MD12XX_JOB_DIR:-}" ] && [[ "$MD12XX_JOB_DIR" == "$STATE_DIR/commission-jobs/"* ]]; then
  printf '%s\n' "$RESULT_DIR" > "$MD12XX_JOB_DIR/result-directory"
fi
HASH="$(printf '%s' "$PORT" | sha1sum | cut -c1-12)"
LOCK_FILE="$STATE_DIR/serial-${HASH}.lock"
IDENTITY_CAPTURE="$RESULT_DIR/identity.txt"
printf '%s\n' "$SHELF_ID" > "$COMMISSION_MARKER"
trap 'rm -f "$COMMISSION_MARKER"' EXIT

verify_console() {
  (
    flock -w 15 9 || { echo "Serial adapter remained locked for 15 seconds." >&2; exit 1; }
    if fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
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

query_sas_identity() {
  local CAPTURE="$1"
  (
    flock -w 15 9 || { echo "Serial adapter remained locked for 15 seconds." >&2; exit 1; }
    if fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
      echo "Serial adapter is open in another process." >&2; exit 1
    fi
    stty -F "$PORT" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 1 time 0
    timeout 8 cat "$PORT" > "$CAPTURE" &
    local READER=$!
    sleep 0.3
    printf 'sas_address\r' > "$PORT"
    wait "$READER" 2>/dev/null || true
  ) 9>"$LOCK_FILE"
}

send_speed() {
  local SPEED="$1" CAPTURE
  CAPTURE="$RESULT_DIR/speed-${SPEED}-$$-$RANDOM.txt"
  (
    flock -w 15 9 || { echo "Serial adapter remained locked for 15 seconds." >&2; exit 1; }
    if fuser "$(readlink -f "$PORT")" >/dev/null 2>&1; then
      echo "Serial adapter is open in another process." >&2
      exit 1
    fi
    stty -F "$PORT" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 1 time 0
    timeout "$SPEED_RESPONSE_SECONDS" cat "$PORT" > "$CAPTURE" &
    local READER=$!
    sleep 0.3
    exec 8>"$PORT"
    for _ in 1 2 3 4 5; do printf 'set_speed %s\r' "$SPEED" >&8; sleep 0.1; done
    exec 8>&-
    wait "$READER" 2>/dev/null || true
  ) 9>"$LOCK_FILE"
  if ! grep -Eqi "set_speed[[:space:]]+$SPEED" "$CAPTURE"; then
    echo "The console did not acknowledge set_speed $SPEED." >&2
    return 1
  fi
}

sample_device_rpm() {
  local DEVICE="$1" LABEL="$2" QUERY_TIMEOUT="${3:-10}" SAFE RAW SPEEDS COUNT AVERAGE
  SAFE="$(basename "$DEVICE")"
  RAW="$RESULT_DIR/${LABEL}-${SAFE}-ses.txt"
  timeout "$QUERY_TIMEOUT" sg_ses -p es "$DEVICE" > "$RAW" 2>&1 || return 1
  SPEEDS="$(sed -n 's/.*Actual speed=\([0-9][0-9]*\) rpm.*/\1/p' "$RAW" | awk '$1 > 0')"
  COUNT="$(printf '%s\n' "$SPEEDS" | sed '/^$/d' | wc -l | tr -d ' ')"
  [ "$COUNT" -ge 2 ] || return 1
  AVERAGE="$(printf '%s\n' "$SPEEDS" | awk '{sum += $1; count++} END {printf "%.0f", sum / count}')"
  printf '%s\t%s\n' "$AVERAGE" "$COUNT"
}

write_stable_response_match() {
  local HISTORY="$1" BASELINE="$2" OUTPUT="$3"
  awk -f "$PLUGIN_DIR/scripts/stable-response.awk" "$BASELINE" "$HISTORY" > "$OUTPUT"
}

sample_candidates_for_window() {
  local LABEL="$1" HISTORY="$2" SUMMARY="$3" SUMMARY_MODE="$4" WINDOW_SECONDS="$5"
  local BASELINE="${6:-}" STABLE_MATCH="${7:-}"
  local STARTED_AT NOW ELAPSED REMAINING DELAY QUERY_TIMEOUT ADDRESS DEVICE SAMPLE ROW WINDOW_EXPIRED

  printf 'elapsedSeconds\taddress\tdevice\taverageRpm\tfanCount\n' > "$HISTORY"
  : > "$SUMMARY"
  STARTED_AT="$(date +%s)"
  WINDOW_EXPIRED=false

  while true; do
    NOW="$(date +%s)"
    ELAPSED=$((NOW - STARTED_AT))
    [ "$ELAPSED" -lt "$WINDOW_SECONDS" ] || break
    REMAINING=$((WINDOW_SECONDS - ELAPSED))
    DELAY="$SAMPLE_INTERVAL_SECONDS"
    [ "$DELAY" -le "$REMAINING" ] || DELAY="$REMAINING"
    sleep "$DELAY"

    while IFS=$'\t' read -r ADDRESS DEVICE; do
      NOW="$(date +%s)"
      ELAPSED=$((NOW - STARTED_AT))
      REMAINING=$((WINDOW_SECONDS - ELAPSED))
      if [ "$REMAINING" -le 0 ]; then
        WINDOW_EXPIRED=true
        break
      fi
      QUERY_TIMEOUT=10
      [ "$QUERY_TIMEOUT" -le "$REMAINING" ] || QUERY_TIMEOUT="$REMAINING"
      if SAMPLE="$(sample_device_rpm "$DEVICE" "${LABEL}-${ELAPSED}s" "$QUERY_TIMEOUT")"; then
        NOW="$(date +%s)"
        ELAPSED=$((NOW - STARTED_AT))
        printf '%s\t%s\t%s\t%s\n' "$ELAPSED" "$ADDRESS" "$DEVICE" "$SAMPLE" >> "$HISTORY"
      fi
    done < "$CANDIDATES"

    [ "$WINDOW_EXPIRED" = false ] || break

    if [ -n "$BASELINE" ] && [ -n "$STABLE_MATCH" ]; then
      write_stable_response_match "$HISTORY" "$BASELINE" "$STABLE_MATCH"
      if [ -s "$STABLE_MATCH" ]; then
        RESPONSE_STABILIZED_SECONDS="$ELAPSED"
        break
      fi
    fi
  done

  while IFS=$'\t' read -r ADDRESS DEVICE; do
    ROW="$(awk -F '\t' -v wanted="$ADDRESS" -v mode="$SUMMARY_MODE" '
      NR == 1 || $2 != wanted { next }
      mode == "last" { rpm=$4; fans=$5; found=1; next }
      !found || $4 > rpm { rpm=$4; fans=$5; found=1 }
      END { if (found) printf "%s\t%s", rpm, fans }
    ' "$HISTORY")"
    [ -n "$ROW" ] && printf '%s\t%s\t%s\n' "$ADDRESS" "$DEVICE" "$ROW" >> "$SUMMARY"
  done < "$CANDIDATES"
}

restoration_proven() {
  local LOW_RPM="$1" HIGH_RPM="$2" FINAL_RPM="$3"
  awk -v low="$LOW_RPM" -v high="$HIGH_RPM" -v final="$FINAL_RPM" 'BEGIN {
    tolerance=low*0.15;
    if (tolerance < 300) tolerance=300;
    exit !((final <= low+tolerance) && (high-final >= 250));
  }'
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
  if send_speed 20; then return 0; fi
  echo "WARNING: the 20% restore command failed; keep other controllers stopped and restore the shelf manually." >&2
  return 1
}

restore_and_cleanup() {
  restore_safe || true
  rm -f "$COMMISSION_MARKER"
}

echo "Verifying the selected serial console with a read-only identity query..."
verify_console
echo "Primary, active MD12xx console verified."

if [ "$MODE" = manual-identify ]; then
  # A manual ramp only identifies the physical shelf reached by this adapter.
  # It cannot certify a serial-to-SES pairing or commission control.
  rm -f "$STATE_DIR/manual-identify-${SHELF_ID}.json"
  php -r '
    require $argv[1];
    $config=md12xx_read_config($argv[2]);
    foreach ($config["shelves"] as &$shelf) {
      if ($shelf["id"] === $argv[3]) $shelf["commissioned"]=false;
    }
    unset($shelf);
    md12xx_write_config($config, $argv[2]);
  ' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID"
  trap restore_and_cleanup EXIT
  trap 'exit 130' INT TERM
  echo "Commanding 20% before the manual identification ramp..."
  send_speed 20
  echo "Commanding 50% for 15 seconds. Observe which physical shelf changes."
  send_speed 50
  sleep 15
  restore_safe
  jq -n --arg port "$PORT" --argjson time "$(date +%s)" '{serialPort:$port,completedAt:$time}' > "$STATE_DIR/manual-identify-${SHELF_ID}.json.tmp"
  mv -f "$STATE_DIR/manual-identify-${SHELF_ID}.json.tmp" "$STATE_DIR/manual-identify-${SHELF_ID}.json"
  trap 'rm -f "$COMMISSION_MARKER"' EXIT
  echo "Manual ramp complete. Name the physical shelf, choose its SES enclosure using mapped disks, and explicitly confirm the pairing within 10 minutes. The ramp alone does not commission the shelf."
  exit 0
fi

# A shelf is never left commissioned while a new control test is in progress.
# Only telemetry-proven restoration at the end may set this back to true.
php -r '
  require $argv[1];
  $config=md12xx_read_config($argv[2]);
  foreach ($config["shelves"] as &$shelf) {
    if ($shelf["id"] === $argv[3]) $shelf["commissioned"]=false;
  }
  unset($shelf);
  md12xx_write_config($config, $argv[2]);
' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID"

CANDIDATES="$RESULT_DIR/candidates.tsv"
candidate_ses > "$CANDIDATES"
SAS_CAPTURE="$RESULT_DIR/serial-sas-address.txt"
SAS_MATCHES="$RESULT_DIR/sas-identity-matches.tsv"
: > "$SAS_MATCHES"
echo "Checking for an exact EMM-to-SES SAS identity match..."
if query_sas_identity "$SAS_CAPTURE"; then
  SERIAL_ELI="$(sed -nE 's/.*ELI ADDRESS:[[:space:]]*([[:xdigit:]]{16}).*/\1/p' "$SAS_CAPTURE" | tr 'A-F' 'a-f' | sort -u)"
  if [[ "$SERIAL_ELI" =~ ^[0-9a-f]{16}$ ]] && [ "$SERIAL_ELI" != 0000000000000000 ]; then
    while IFS=$'\t' read -r ADDRESS DEVICE; do
      SES_CAPTURE="$RESULT_DIR/sas-$(basename "$DEVICE")-configuration.txt"
      timeout 10 sg_ses -R -p 0x01 "$DEVICE" > "$SES_CAPTURE" 2>&1 || continue
      SES_ELI="$(sed -nE 's/.*enclosure logical identifier \(hex\):[[:space:]]*([[:xdigit:]]{16}).*/\1/p' "$SES_CAPTURE" | tr 'A-F' 'a-f' | sort -u)"
      if [ "$SES_ELI" = "$SERIAL_ELI" ]; then printf '%s\t%s\n' "$ADDRESS" "$DEVICE" >> "$SAS_MATCHES"; fi
    done < "$CANDIDATES"
  fi
fi
sas_pairing_fallback() {
  [ "$(wc -l < "$SAS_MATCHES")" -eq 1 ] || return 1
  IFS=$'\t' read -r SES_ADDRESS SES_DEVICE < "$SAS_MATCHES"
  echo "RPM proof unavailable. Exact SAS identity match: selected EMM and $SES_DEVICE share enclosure logical identifier $SERIAL_ELI."
  trap restore_and_cleanup EXIT
  trap 'exit 130' INT TERM
  echo "Commanding 20%, then 50% for 15 seconds, then restoring 20%..."
  send_speed 20
  send_speed 50
  sleep 15
  restore_safe
  # Both interfaces have the same enclosure identity. SES fan RPM is not
  # claimed as live proof of the acknowledged serial speed commands.
  MAPPING_JSON="$(php -r 'require $argv[1]; echo json_encode(md12xx_ses_disk_mapping($argv[2]), JSON_UNESCAPED_SLASHES);' "$PLUGIN_DIR/include/common.php" "$SES_ADDRESS")"
  AUTO_DISKS="$(jq -c '.disks // []' <<< "$MAPPING_JSON")"
  READY=false
  if [ "$(jq -r '.state' <<< "$MAPPING_JSON")" = verified ] && [ "$(jq '(.disks // []) | length' <<< "$MAPPING_JSON")" -gt 0 ]; then
    if [ "$ASSIGNMENT" = automatic ]; then
      READY=true
    elif jq -e --argjson mapped "$AUTO_DISKS" '(.disks // []) as $chosen | ($chosen | length > 0) and ([$chosen[] | select(. as $disk | $mapped | index($disk) == null)] | length == 0)' <<< "$SHELF_JSON" >/dev/null; then
      READY=true
    fi
  fi
  php -r '
    require $argv[1];
    $config=md12xx_read_config($argv[2]);
    $automaticDisks=json_decode($argv[6], true) ?: [];
    foreach ($config["shelves"] as &$shelf) {
      if ($shelf["id"] !== $argv[3]) continue;
      $shelf["sesAddress"]=$argv[4];
      $shelf["sesDevice"]=$argv[5];
      if (($shelf["diskAssignment"] ?? "automatic") === "automatic") $shelf["disks"]=$automaticDisks;
      $shelf["calibration"]=[];
      $shelf["verificationMode"]="sas";
      $shelf["commissioned"]=$argv[7] === "true";
    }
    unset($shelf);
    $config=md12xx_disable_active_discovery_after_setup($config);
    md12xx_write_config($config, $argv[2]);
  ' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID" "$SES_ADDRESS" "$SES_DEVICE" "$AUTO_DISKS" "$READY"
  trap 'rm -f "$COMMISSION_MARKER"' EXIT
  echo "SAS identity pairing: PASS; 20% restoration acknowledged. SES fan RPM response remains unverified." | tee "$RESULT_DIR/result.txt"
  echo "Matched SES: $SES_ADDRESS -> $SES_DEVICE" | tee -a "$RESULT_DIR/result.txt"
  echo "Mapped Unraid disks: $(jq -r 'if length then join(", ") else "none" end' <<< "$AUTO_DISKS")" | tee -a "$RESULT_DIR/result.txt"
  if [ "$READY" = true ]; then
    echo "Identity pairing commissioned with reduced RPM verification. Review the shelf and disks before enabling control."
    exit 0
  fi
  echo "Identity matched, but no verified disk assignment was available. The shelf remains uncommissioned." >&2
  exit 1
}
echo "Trying independent RPM proof first; an exact SAS identity match is available as a fallback when SES RPM stays static."
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
LOW_HISTORY="$RESULT_DIR/20-percent-history.tsv"
HIGH_HISTORY="$RESULT_DIR/50-percent-history.tsv"
trap restore_and_cleanup EXIT
trap 'exit 130' INT TERM

echo "Commanding 20% and sampling every ${SAMPLE_INTERVAL_SECONDS}s for ${BASELINE_WAIT_SECONDS}s..."
send_speed 20
sample_candidates_for_window 20-percent "$LOW_HISTORY" "$LOW" last "$BASELINE_WAIT_SECONDS"
if [ ! -s "$LOW" ]; then
  echo "No valid SES fan telemetry was available for the 20% baseline." >&2
  sas_pairing_fallback
  exit 1
fi

STABLE_MATCH="$RESULT_DIR/stable-response.tsv"
RESPONSE_STABILIZED_SECONDS=""
echo "Commanding 50% and sampling every ${SAMPLE_INTERVAL_SECONDS}s until the response stabilizes or ${RESPONSE_TIMEOUT_SECONDS}s elapse..."
send_speed 50
sample_candidates_for_window 50-percent "$HIGH_HISTORY" "$HIGH" maximum "$RESPONSE_TIMEOUT_SECONDS" "$LOW" "$STABLE_MATCH"

RESTORE_SENT=true
restore_safe || RESTORE_SENT=false

MATCHES="$RESULT_DIR/matches.tsv"
cp "$STABLE_MATCH" "$MATCHES"

MATCH_COUNT="$(wc -l < "$MATCHES" | tr -d ' ')"
if [ "$MATCH_COUNT" -ne 1 ]; then
  if [ "$RESTORE_SENT" = true ] && [ "$(wc -l < "$SAS_MATCHES")" -eq 1 ]; then
    sas_pairing_fallback
  fi
  echo "Identification did not find exactly one enclosure with two consecutive stable higher-RPM samples before the ${RESPONSE_TIMEOUT_SECONDS}s timeout." >&2
  echo "A 20% restore was attempted, but no unique enclosure was available for independent restoration proof." >&2
  echo "Use Manual mapping only after checking the captured results in $RESULT_DIR." >&2
  exit 1
fi

IFS=$'\t' read -r SES_ADDRESS SES_DEVICE RPM_20 RPM_50 DELTA PERCENT < "$MATCHES"
FIRST_RESPONSE_SECONDS="$(awk -F '\t' -v wanted="$SES_ADDRESS" -v low="$RPM_20" '
  NR == 1 || $2 != wanted { next }
  { delta=$4-low; pct=(low>0 ? delta/low*100 : 0) }
  delta >= 250 && pct >= 10 { print $1; exit }
' "$HIGH_HISTORY")"

echo "Waiting ${RESTORE_WAIT_SECONDS}s to prove the selected enclosure returned to 20%..."
sleep "$RESTORE_WAIT_SECONDS"
FINAL_SAMPLE="$(sample_device_rpm "$SES_DEVICE" final-restore || true)"
FINAL_RPM="${FINAL_SAMPLE%%$'\t'*}"

if [ "$RESTORE_SENT" != true ] || [ -z "$FINAL_RPM" ] || ! restoration_proven "$RPM_20" "$RPM_50" "$FINAL_RPM"; then
  echo "The first 20% restore was not proven by SES telemetry (${FINAL_RPM:-no RPM} RPM); retrying once." >&2
  restore_safe || true
  sleep "$RESTORE_WAIT_SECONDS"
  FINAL_SAMPLE="$(sample_device_rpm "$SES_DEVICE" final-restore-retry || true)"
  FINAL_RPM="${FINAL_SAMPLE%%$'\t'*}"
fi

if [ -z "$FINAL_RPM" ] || ! restoration_proven "$RPM_20" "$RPM_50" "$FINAL_RPM"; then
  if [ "$(wc -l < "$SAS_MATCHES")" -eq 1 ]; then
    sas_pairing_fallback
  fi
  echo "SAFETY FAILURE: the enclosure did not prove a return to its 20% RPM range." >&2
  echo "The shelf remains uncommissioned. Keep other controllers stopped, resolve the serial connection, then select Identify & test again; every retry begins by commanding 20%." >&2
  exit 1
fi

echo "Final 20% restoration: PASS ($FINAL_RPM RPM)."
# The hardware is safely back at 20%, but keep the commissioning marker until
# the verified mapping and calibration are durably saved. This prevents a
# Settings save from racing the final configuration write.
trap 'rm -f "$COMMISSION_MARKER"' EXIT
trap 'exit 130' INT TERM

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
    $shelf["calibration"]=["rpmAt20"=>(int)$argv[8], "rpmAt50"=>(int)$argv[9]];
    $shelf["commissioned"]=$argv[7] === "true";
  }
  unset($shelf);
  $config=md12xx_disable_active_discovery_after_setup($config);
  md12xx_write_config($config, $argv[2]);
' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE" "$SHELF_ID" "$SES_ADDRESS" "$SES_DEVICE" "$AUTO_DISKS" "$READY" "$RPM_20" "$RPM_50"

{
  echo "MD12xx fan control identification and commissioning"
  echo "Collected: $(date -Is)"
  echo "Shelf: $SHELF_NAME ($MODEL)"
  echo "Serial: $PORT"
  echo "Matched SES: $SES_ADDRESS -> $SES_DEVICE"
  echo "20%: $RPM_20 RPM"
  echo "50%: $RPM_50 RPM"
  echo "Response: PASS (delta +$DELTA RPM, $PERCENT%)"
  echo "Response first observed: ${FIRST_RESPONSE_SECONDS:-unknown}s"
  echo "Stable response confirmed: ${RESPONSE_STABILIZED_SECONDS:-unknown}s (two consecutive samples within 10% or 250 RPM; ${RESPONSE_TIMEOUT_SECONDS}s timeout)"
  echo "Disk assignment: $ASSIGNMENT"
  echo "Automatic disks: $(jq -r 'if length then join(", ") else "none" end' <<< "$AUTO_DISKS")"
  echo "Final restore: PASS ($FINAL_RPM RPM after ${RESTORE_WAIT_SECONDS}s)"
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
