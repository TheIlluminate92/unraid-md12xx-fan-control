#!/bin/bash
set -euo pipefail

DIAGNOSE=$1
FIXTURE="$2/diagnostics-test"
mkdir -p "$FIXTURE/config" "$FIXTURE/state" "$FIXTURE/sys"
sed -e "s|/boot/config/plugins/md12xx.fancontrol|$FIXTURE/config|g" \
    -e "s|/var/run/md12xx.fancontrol|$FIXTURE/state|g" \
    -e "s|/sys/class/scsi_generic|$FIXTURE/sys|g" "$DIAGNOSE" > "$FIXTURE/diagnose.sh"
cat > "$FIXTURE/state/status.json" <<'JSON'
{
  "controller": {"state": "attention", "message": "private-shelf"},
  "shelves": [{
    "name": "private-shelf", "serialPort": "private-path", "assignedDisks": ["private-disk"],
    "targetReason": "private-disk 26 C", "targetPercent": 40, "telemetryMessage": "private-shelf",
    "writeMessage": "private-path", "temperatureSource": "private-disk",
    "extra": [{"nestedReasons": ["private-disk"], "nestedMessage": "private-shelf", "state": "normal"}]
  }]
}
JSON
printf '%s\n' '{"state":"fault","message":"private-shelf","targetReason":"private-disk"}' > "$FIXTURE/state/ipmi-status.json"
printf '%s\n' '{"state":"fault","message":"private-shelf"}' > "$FIXTURE/state/watchdog.json"
printf '%s\n' '{"sesDevices":[{"vendor":"DELL","diskMappingMessage":"private-disk"}]}' > "$FIXTURE/state/discovery.json"
bash "$FIXTURE/diagnose.sh"
mkdir "$FIXTURE/extracted"
tar -xzf "$FIXTURE/config/diagnostics/"*.tar.gz -C "$FIXTURE/extracted" --strip-components=1
for artifact in "$FIXTURE/extracted/"*.json; do
  if grep -q 'private-' "$artifact"; then
    echo "Unredacted fixture text in $(basename "$artifact")" >&2
    exit 1
  fi
done
jq -e '.controller.state == "attention" and .shelves[0].targetPercent == 40 and .shelves[0].extra[0].state == "normal"' "$FIXTURE/extracted/status.redacted.json" >/dev/null
jq -e '.state == "fault"' "$FIXTURE/extracted/ipmi-status.redacted.json" "$FIXTURE/extracted/watchdog.json" >/dev/null
echo "Diagnostics archive nested redaction passed."
