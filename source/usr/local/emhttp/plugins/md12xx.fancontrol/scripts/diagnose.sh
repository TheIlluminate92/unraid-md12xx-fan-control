#!/bin/bash
set -euo pipefail

CONFIG_DIR="/boot/config/plugins/md12xx.fancontrol"
STATE_DIR="/var/run/md12xx.fancontrol"
RESULT_ROOT="$CONFIG_DIR/diagnostics"
STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/$STAMP"
mkdir -p "$STATE_DIR"
exec 9>"$STATE_DIR/diagnostics.lock"
flock -n 9 || { echo "A diagnostics export is already running." >&2; exit 1; }
printf '%s\n' "$$" > "$STATE_DIR/diagnostics.pid"
trap 'rm -f "$STATE_DIR/diagnostics.pid"' EXIT
mkdir -p "$RESULT_DIR"

{
  echo "Collected: $(date -Is)"
  echo "Unraid: $(cat /etc/unraid-version 2>/dev/null || true)"
  echo "Kernel: $(uname -rmo)"
  echo "sg_ses: $(command -v sg_ses 2>/dev/null || echo missing)"
  echo "Privacy: local archive; hostname, serial identifiers, disk names, and paths redacted"
} > "$RESULT_DIR/system.txt"

if [ -f "$CONFIG_DIR/config.json" ]; then
  jq '
    .legacyContainerNames = ((.legacyContainerNames // []) | length) |
    .shelves = ((.shelves // []) | to_entries | map(
      (.key + 1) as $index | .value |
      .name = ("Shelf " + ($index | tostring)) |
      .serialPort = (if (.serialPort // "") == "" then "" else "/dev/serial/by-id/[redacted]" end) |
      .sesAddress = (if (.sesAddress // "") == "" then "" else "[redacted]" end) |
      .sesDevice = (if (.sesDevice // "") == "" then "" else "/dev/sg[redacted]" end) |
      .disks = ((.disks // []) | to_entries | map("mapped-disk-" + ((.key + 1) | tostring)))
    ))
  ' "$CONFIG_DIR/config.json" > "$RESULT_DIR/config.redacted.json"
fi

if [ -f /var/run/md12xx.fancontrol/status.json ]; then
  jq '
    .shelves = ((.shelves // []) | to_entries | map(
      (.key + 1) as $index | .value |
      .name = ("Shelf " + ($index | tostring)) |
      del(.serialPort) |
      .sesAddress = (if (.sesAddress // "") == "" then "" else "[redacted]" end) |
      .sesDevice = (if (.sesDevice // "") == "" then "" else "/dev/sg[redacted]" end) |
      .assignedDisks = ((.assignedDisks // []) | to_entries | map("mapped-disk-" + ((.key + 1) | tostring))) |
      .missingDisks = ((.missingDisks // []) | to_entries | map("missing-disk-" + ((.key + 1) | tostring))) |
      if has("temperatureSource") and .temperatureSource != null then .temperatureSource = "mapped-disk" else . end
    ))
  ' /var/run/md12xx.fancontrol/status.json > "$RESULT_DIR/status.redacted.json"
fi

if [ -f /var/run/md12xx.fancontrol/discovery.json ]; then
  jq '
    .serialPorts = ((.serialPorts // []) | map({
      vendorId, productId, manufacturer, product, knownFtdiCandidate,
      probeState, blueDressPrompt, md12xxResponse, primaryActive, consoleVerified, message
    })) |
    .sesDevices = ((.sesDevices // []) | map({
      vendor, model, supportedCandidate, diskMappingState,
      blockDeviceCount: ((.blockDevices // []) | length),
      diskCount: ((.disks // []) | length), diskMappingMessage
    })) |
    .disks = ((.disks // []) | map({temperatureAvailable: (.temperatureC != null)}))
  ' /var/run/md12xx.fancontrol/discovery.json > "$RESULT_DIR/discovery.redacted.json"
fi

ENCLOSURE_INDEX=0
for GENERIC in /sys/class/scsi_generic/sg*; do
  [ -e "$GENERIC/device" ] || continue
  TYPE="$(cat "$GENERIC/device/type" 2>/dev/null || true)"
  [ "$TYPE" = "13" ] || continue
  ENCLOSURE_INDEX=$((ENCLOSURE_INDEX + 1))
  SG="/dev/$(basename "$GENERIC")"
  {
    echo "Address: [redacted]"
    echo "Vendor: $(cat "$GENERIC/device/vendor" 2>/dev/null || true)"
    echo "Model: $(cat "$GENERIC/device/model" 2>/dev/null || true)"
    timeout 10 sg_ses -p es "$SG" 2>&1 || true
  } > "$RESULT_DIR/enclosure-${ENCLOSURE_INDEX}-ses.txt"
done

tar -czf "$RESULT_ROOT/${STAMP}.tar.gz" -C "$RESULT_ROOT" "$STAMP"
rm -rf -- "$RESULT_DIR"
echo "Read-only diagnostics: $RESULT_ROOT/${STAMP}.tar.gz"
