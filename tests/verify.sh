#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$PROJECT_DIR/source/usr/local/emhttp/plugins/md12xx.fancontrol"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

"$PROJECT_DIR/scripts/build.sh" "$TMP_DIR/md12xx.fancontrol.plg"
for FILE in "$PLUGIN_DIR"/include/*.php; do php -l "$FILE" >/dev/null; done
for FILE in "$PLUGIN_DIR"/scripts/*.sh "$PROJECT_DIR/scripts/build.sh"; do bash -n "$FILE"; done
node --check "$PLUGIN_DIR/assets/js/settings.js"

php "$PLUGIN_DIR/include/discovery.php" --once \
  --config="$PROJECT_DIR/tests/fixtures/auto.json" \
  --state="$TMP_DIR/discovery-state.json"
jq -e '.serialPorts | type == "array"' "$TMP_DIR/discovery-state.json" >/dev/null
if grep -n 'set_speed' "$PLUGIN_DIR/include/discovery.php"; then
  echo "Read-only discovery contains a fan-speed command." >&2
  exit 1
fi

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
