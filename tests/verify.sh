#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$PROJECT_DIR/source/usr/local/emhttp/plugins/md12xx.fancontrol"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

bash "$PROJECT_DIR/scripts/build.sh" "$TMP_DIR/md12xx.fancontrol.plg"
for FILE in "$PLUGIN_DIR"/include/*.php; do php -l "$FILE" >/dev/null; done
for FILE in "$PLUGIN_DIR"/scripts/*.sh "$PROJECT_DIR/scripts/build.sh"; do bash -n "$FILE"; done
node --check "$PLUGIN_DIR/assets/js/settings.js"
if grep -qE 'require_once[[:space:]]+__DIR__' "$PLUGIN_DIR/MD12xxFanControl.page"; then
  echo "Settings page relies on the page builder __DIR__ context." >&2
  exit 1
fi
grep -Fq "\$pluginRoot = '/usr/local/emhttp/plugins/md12xx.fancontrol';" "$PLUGIN_DIR/MD12xxFanControl.page"

mkdir -p "$TMP_DIR/sys/class/enclosure/0:0:99:0/Slot 00/device/block/sda"
php -r '
  require $argv[1];
  echo json_encode(md12xx_ses_disk_mapping("0:0:99:0", $argv[2], $argv[3]), JSON_UNESCAPED_SLASHES);
' "$PLUGIN_DIR/include/common.php" "$PROJECT_DIR/tests/fixtures/disks.ini" "$TMP_DIR/sys" > "$TMP_DIR/disk-mapping.json"
jq -e '.state == "verified" and .blockDevices == ["sda"] and .disks == ["disk1"]' "$TMP_DIR/disk-mapping.json" >/dev/null

mkdir -p \
  "$TMP_DIR/sys-topology/devices/host9/port-9:1/expander-9:1/port-9:1:11/end_device-9:1:11/target9:0:11/9:0:11:0" \
  "$TMP_DIR/sys-topology/devices/host9/port-9:1/expander-9:1/port-9:1:0/end_device-9:1:0/target9:0:0/9:0:0:0/block/sda" \
  "$TMP_DIR/sys-topology/class/scsi_generic/sg11" \
  "$TMP_DIR/sys-topology/class/scsi_disk/9:0:0:0"
ln -s "$TMP_DIR/sys-topology/devices/host9/port-9:1/expander-9:1/port-9:1:11/end_device-9:1:11/target9:0:11/9:0:11:0" \
  "$TMP_DIR/sys-topology/class/scsi_generic/sg11/device"
ln -s "$TMP_DIR/sys-topology/devices/host9/port-9:1/expander-9:1/port-9:1:0/end_device-9:1:0/target9:0:0/9:0:0:0" \
  "$TMP_DIR/sys-topology/class/scsi_disk/9:0:0:0/device"
php -r '
  require $argv[1];
  echo json_encode(md12xx_ses_disk_mapping("9:0:11:0", $argv[2], $argv[3]), JSON_UNESCAPED_SLASHES);
' "$PLUGIN_DIR/include/common.php" "$PROJECT_DIR/tests/fixtures/disks.ini" "$TMP_DIR/sys-topology" > "$TMP_DIR/topology-mapping.json"
jq -e '.state == "verified" and .source == "sas-expander" and .blockDevices == ["sda"] and .disks == ["disk1"]' "$TMP_DIR/topology-mapping.json" >/dev/null

php "$PLUGIN_DIR/include/discovery.php" --once \
  --config="$PROJECT_DIR/tests/fixtures/auto.json" \
  --state="$TMP_DIR/discovery-state.json"
jq -e '.serialPorts | type == "array"' "$TMP_DIR/discovery-state.json" >/dev/null
if grep -n 'set_speed' "$PLUGIN_DIR/include/discovery.php"; then
  echo "Read-only discovery contains a fan-speed command." >&2
  exit 1
fi
grep -Fq "printf '_who\\r'" "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'expected exactly one responding SES enclosure' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'Returning the selected MD12xx console to 20%' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'md12xx_ses_disk_mapping' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq '@fopen($port, '\''r+'\'')' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'exec 8<>"$PORT"' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'flock -w 15 9' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'commissioning.active' "$PLUGIN_DIR/include/discovery.php"

php "$PLUGIN_DIR/include/controller.php" --once --dry-run \
  --config="$PROJECT_DIR/tests/fixtures/auto.json" \
  --disks="$PROJECT_DIR/tests/fixtures/disks.ini" \
  --fixture-dir="$PROJECT_DIR/tests/fixtures/ses" \
  --state="$TMP_DIR/auto-state.json"
jq -e '.controller.state == "normal" and .shelves[0].targetPercent == 30 and .shelves[0].averageRpm == 3500 and .shelves[0].fanCount == 4 and .shelves[0].writeState == "dry-run"' "$TMP_DIR/auto-state.json" >/dev/null

php "$PLUGIN_DIR/include/controller.php" --once --dry-run \
  --config="$PROJECT_DIR/tests/fixtures/manual.json" \
  --disks="$PROJECT_DIR/tests/fixtures/disks.ini" \
  --fixture-dir="$PROJECT_DIR/tests/fixtures/ses" \
  --state="$TMP_DIR/manual-state.json"
jq -e '.controller.mode == "manual" and .shelves[0].model == "MD1220" and .shelves[0].targetPercent == 40 and .shelves[0].writeState == "dry-run"' "$TMP_DIR/manual-state.json" >/dev/null

if grep -R -n -E '/dev/sg(11|18)|FTE33O9T|FTE32AB2|/mnt/user/Back-Up|MD1200_(TOP|BOTTOM)_' "$PROJECT_DIR/source"; then
  echo "Server-specific values remain in standalone source." >&2
  exit 1
fi

echo "MD12xx runtime verification passed."
