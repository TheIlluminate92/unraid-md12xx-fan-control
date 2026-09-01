#!/bin/bash
set -euo pipefail

CONFIG_DIR="/boot/config/plugins/md12xx.fancontrol"
RESULT_ROOT="$CONFIG_DIR/diagnostics"
STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="$RESULT_ROOT/$STAMP"
mkdir -p "$RESULT_DIR"

{
  echo "Collected: $(date -Is)"
  echo "Unraid: $(cat /etc/unraid-version 2>/dev/null || true)"
  echo "Kernel: $(uname -a)"
  echo "sg_ses: $(command -v sg_ses 2>/dev/null || echo missing)"
} > "$RESULT_DIR/system.txt"

cp -f "$CONFIG_DIR/config.json" "$RESULT_DIR/config.json" 2>/dev/null || true
cp -f /var/run/md12xx.fancontrol/status.json "$RESULT_DIR/status.json" 2>/dev/null || true
cp -f /var/run/md12xx.fancontrol/discovery.json "$RESULT_DIR/discovery.json" 2>/dev/null || true
ls -la /dev/serial/by-id > "$RESULT_DIR/serial-adapters.txt" 2>&1 || true

for GENERIC in /sys/class/scsi_generic/sg*; do
  [ -e "$GENERIC/device" ] || continue
  TYPE="$(cat "$GENERIC/device/type" 2>/dev/null || true)"
  [ "$TYPE" = "13" ] || continue
  SG="/dev/$(basename "$GENERIC")"
  {
    echo "Address: $(basename "$(readlink -f "$GENERIC/device")")"
    echo "Vendor: $(cat "$GENERIC/device/vendor" 2>/dev/null || true)"
    echo "Model: $(cat "$GENERIC/device/model" 2>/dev/null || true)"
    sg_ses -p es "$SG" 2>&1 || true
  } > "$RESULT_DIR/$(basename "$SG")-ses.txt"
done

tar -czf "$RESULT_ROOT/${STAMP}.tar.gz" -C "$RESULT_ROOT" "$STAMP"
echo "Read-only diagnostics: $RESULT_ROOT/${STAMP}.tar.gz"
