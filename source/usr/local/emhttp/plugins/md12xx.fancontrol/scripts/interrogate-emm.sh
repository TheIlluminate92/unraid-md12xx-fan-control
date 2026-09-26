#!/bin/bash
set -euo pipefail

# Read-only console inventory. Run only with the controller disabled and no
# other process holding either service adapter. The command list is captured,
# never parsed into commands to execute.
PLUGIN_DIR="/usr/local/emhttp/plugins/md12xx.fancontrol"
CONFIG_FILE="/boot/config/plugins/md12xx.fancontrol/config.json"
STATE_DIR="/var/run/md12xx.fancontrol"
RESULT_ROOT="/boot/config/plugins/md12xx.fancontrol/diagnostics"

[ "$(id -u)" -eq 0 ] || { echo "Run as root on Unraid." >&2; exit 1; }
[ "$#" -gt 0 ] || { echo "Usage: $0 /dev/serial/by-id/<adapter> [second-adapter...]" >&2; exit 1; }
for tool in jq php flock fuser stty timeout sha1sum tar sg_ses; do
  command -v "$tool" >/dev/null || { echo "$tool is required." >&2; exit 1; }
done

[ -f "$CONFIG_FILE" ] || { echo "Plugin configuration is missing." >&2; exit 1; }
jq -e '.enabled == false' "$CONFIG_FILE" >/dev/null || { echo "Disable the MD12xx controller first." >&2; exit 1; }
[ "$(php -r 'require $argv[1]; echo count(md12xx_competing_controllers(md12xx_read_config($argv[2])));' "$PLUGIN_DIR/include/common.php" "$CONFIG_FILE")" -eq 0 ] || {
  echo "Another fan controller is active; stop it first." >&2; exit 1;
}
mkdir -p "$STATE_DIR" "$RESULT_ROOT"
exec 7>"$STATE_DIR/emm-interrogation.lock"
flock -n 7 || { echo "An EMM interrogation is already running." >&2; exit 1; }
[ ! -e "$STATE_DIR/commissioning.active" ] || { echo "Commissioning is active." >&2; exit 1; }

STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/emm-${STAMP}"
mkdir -p "$RESULT_DIR"
archive="$RESULT_DIR.tar.gz"
finish() {
  local status=$?
  trap - EXIT
  tar -czf "$archive" -C "$RESULT_ROOT" "$(basename "$RESULT_DIR")" || true
  echo "Review serial identifiers before public upload: $archive"
  exit "$status"
}
trap finish EXIT
echo "Collected: $(date -Is)" > "$RESULT_DIR/manifest.txt"
echo "Commands sent: _who, _ver, devils (console command listing). No discovered commands were executed." >> "$RESULT_DIR/manifest.txt"

query() {
  local port="$1" command="$2" output="$3" reader
  stty -F "$port" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 1 time 0
  timeout 8 cat "$port" > "$output" &
  reader=$!
  sleep 0.3
  printf '%s\r' "$command" > "$port"
  wait "$reader" 2>/dev/null || true
}

index=0
for port in "$@"; do
  identifier="${port#/dev/serial/by-id/}"
  [[ "$port" == /dev/serial/by-id/* && -n "$identifier" && "$identifier" != */* && "$identifier" != . && "$identifier" != .. && "$identifier" != *\\* && ! "$identifier" =~ [[:cntrl:]] ]] || {
    echo "Use a persistent /dev/serial/by-id adapter path: $port" >&2; exit 1;
  }
  [ -e "$port" ] || { echo "Adapter is missing: $port" >&2; exit 1; }
  index=$((index + 1))
  dir="$RESULT_DIR/adapter-${index}"
  mkdir -p "$dir"
  printf '%s\n' "$port" > "$dir/adapter-path.txt"
  hash="$(printf '%s' "$port" | sha1sum | cut -c1-12)"
  (
    exec 9>"$STATE_DIR/serial-${hash}.lock"
    flock -w 15 9 || { echo "Adapter remained locked: $port" >&2; exit 1; }
    if fuser "$(readlink -f "$port")" >/dev/null 2>&1; then
      echo "Adapter is open in another process: $port" >&2; exit 1
    fi
    query "$port" _who "$dir/who.txt"
    grep -Eqi 'I.?m[[:space:]]+primary[[:space:]]+and[[:space:]]+active' "$dir/who.txt" || {
      echo "No verified primary/active EMM response from $port; no further queries sent." >&2; exit 1;
    }
    query "$port" _ver "$dir/version.txt"
    query "$port" devils "$dir/command-list.txt"
  )
done

index=0
for generic in /sys/class/scsi_generic/sg*; do
  [ "$(cat "$generic/device/type" 2>/dev/null || true)" = 13 ] || continue
  index=$((index + 1))
  device="/dev/${generic##*/}"
  dir="$RESULT_DIR/enclosure-${index}"
  mkdir -p "$dir"
  readlink -f "$generic/device" > "$dir/scsi-path.txt"
  for page in 00 01 02 04 05 07 0a 0e 0f; do
    timeout 10 sg_ses -R -p "0x$page" "$device" > "$dir/ses-${page}.txt" 2>&1 || true
  done
  if command -v sg_inq >/dev/null 2>&1; then
    timeout 10 sg_inq "$device" > "$dir/inquiry.txt" 2>&1 || true
    for page in 00 80 83; do
      timeout 10 sg_inq -p "0x$page" "$device" > "$dir/vpd-${page}.txt" 2>&1 || true
    done
  fi
done
