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
bash "$PROJECT_DIR/tests/security.sh"
if grep -qE 'require_once[[:space:]]+__DIR__' "$PLUGIN_DIR/MD12xxFanControl.page"; then
  echo "Settings page relies on the page builder __DIR__ context." >&2
  exit 1
fi
grep -Fq "\$pluginRoot = '/usr/local/emhttp/plugins/md12xx.fancontrol';" "$PLUGIN_DIR/MD12xxFanControl.page"

php -r '
  foreach (array_slice($argv, 1) as $path) {
    $document = new DOMDocument();
    if (!$document->load($path)) exit(1);
  }
' "$PROJECT_DIR/ca_profile.xml" "$PROJECT_DIR/plugins/md12xx.fancontrol.xml" "$PROJECT_DIR/icon.svg"
grep -Fq '<Profile>' "$PROJECT_DIR/ca_profile.xml"
grep -Fq '<Beta>true</Beta>' "$PROJECT_DIR/plugins/md12xx.fancontrol.xml"
grep -Fq '<PluginURL>https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/main/releases/md12xx.fancontrol.plg</PluginURL>' "$PROJECT_DIR/plugins/md12xx.fancontrol.xml"
for FIELD in 'Shelf model' 'Unraid version' 'HBA and driver' 'EMM and cabling arrangement' 'Commissioning result' 'Discovery summary' 'Disk mapping' 'Competing fan controller state during testing' 'Optional redacted diagnostics'; do
  grep -Fq "$FIELD" "$PROJECT_DIR/.github/ISSUE_TEMPLATE/hardware-report.yml"
done

mkdir -p "$TMP_DIR/sys/class/enclosure/0:0:99:0/Slot 00/device/block/sda"
php -r '
  require $argv[1];
  echo json_encode(md12xx_ses_disk_mapping("0:0:99:0", $argv[2], $argv[3]), JSON_UNESCAPED_SLASHES);
' "$PLUGIN_DIR/include/common.php" "$PROJECT_DIR/tests/fixtures/disks.ini" "$TMP_DIR/sys" > "$TMP_DIR/disk-mapping.json"
jq -e '.state == "verified" and .blockDevices == ["sda"] and .disks == ["disk1"]' "$TMP_DIR/disk-mapping.json" >/dev/null

php -r '
  require $argv[1];
  echo json_encode(md12xx_ses_disk_mapping("0:0:99:0", $argv[2], $argv[3]), JSON_UNESCAPED_SLASHES);
' "$PLUGIN_DIR/include/common.php" "$PROJECT_DIR/tests/fixtures/disks-duplicates.ini" "$TMP_DIR/sys" > "$TMP_DIR/duplicate-name-mapping.json"
jq -e '.state == "verified" and .blockDevices == ["sda"] and .disks == ["disk1"]' "$TMP_DIR/duplicate-name-mapping.json" >/dev/null

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

MD1220_SYS="$TMP_DIR/sys-md1220"
MD1220_EXPANDER="$MD1220_SYS/devices/host7/port-7:3/expander-7:3"
MD1220_SES="$MD1220_EXPANDER/port-7:3:31/end_device-7:3:31/target7:0:31/7:0:31:0"
mkdir -p "$MD1220_SES" "$MD1220_SYS/class/scsi_generic/sg31"
ln -s "$MD1220_SES" "$MD1220_SYS/class/scsi_generic/sg31/device"
INDEX=0
for BLOCK in sda sdb sdc sdd sde sdf sdg sdh sdi sdj sdk sdl sdm sdn sdo sdp sdq sdr sds sdt sdu sdv sdw sdx; do
  DISK_PATH="$MD1220_EXPANDER/port-7:3:$INDEX/end_device-7:3:$INDEX/target7:0:$INDEX/7:0:$INDEX:0"
  mkdir -p "$DISK_PATH/block/$BLOCK" "$MD1220_SYS/class/scsi_disk/7:0:$INDEX:0"
  ln -s "$DISK_PATH" "$MD1220_SYS/class/scsi_disk/7:0:$INDEX:0/device"
  INDEX=$((INDEX + 1))
done
mkdir -p \
  "$MD1220_SYS/devices/host8/port-8:4/expander-8:4/port-8:4:0/end_device-8:4:0/target8:0:0/8:0:0:0/block/sdy" \
  "$MD1220_SYS/class/scsi_disk/8:0:0:0"
ln -s "$MD1220_SYS/devices/host8/port-8:4/expander-8:4/port-8:4:0/end_device-8:4:0/target8:0:0/8:0:0:0" \
  "$MD1220_SYS/class/scsi_disk/8:0:0:0/device"
php -r '
  require $argv[1];
  echo json_encode(md12xx_ses_disk_mapping("7:0:31:0", $argv[2], $argv[3]), JSON_UNESCAPED_SLASHES);
' "$PLUGIN_DIR/include/common.php" "$PROJECT_DIR/tests/fixtures/disks-md1220.ini" "$MD1220_SYS" > "$TMP_DIR/md1220-mapping.json"
jq -e '.state == "verified" and .source == "sas-expander" and (.blockDevices | length) == 24 and (.disks | length) == 24 and (.blockDevices | index("sdy")) == null' "$TMP_DIR/md1220-mapping.json" >/dev/null

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
grep -Fq '$reader = @fopen($port, '\''r'\'');' "$PLUGIN_DIR/include/controller.php"
grep -Fq '$writer = @fopen($port, '\''w'\'');' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'timeout "$SPEED_RESPONSE_SECONDS" cat "$PORT"' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'Final 20% restoration: PASS' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq '$shelf["calibration"]=[' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'RESULT_DIR_FILE' "$PLUGIN_DIR/scripts/commission-job.sh"
grep -Fq 'MD12XX_JOB_DIR=' "$PLUGIN_DIR/scripts/commission-job.sh"
grep -Fq 'Results: %s' "$PLUGIN_DIR/scripts/commission-job.sh"
grep -Fq 'md12xx_controller_verify_target' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'assigned disk inventory incomplete; fail-safe' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'automatic disk mapping changed; fail-safe' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'Controller status is stale' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq "'stale' =>" "$PLUGIN_DIR/include/api.php"
grep -Fq 'md12xx_commission_active' "$PLUGIN_DIR/include/common.php"
grep -Fq 'md12xx_fuser_binary' "$PLUGIN_DIR/include/common.php"
MARKER_LINE="$(grep -nF 'if (!is_file($marker)) return false;' "$PLUGIN_DIR/include/common.php" | head -1 | cut -d: -f1)"
PROC_SCAN_LINE="$(grep -nF "glob('/proc/[0-9]*/cmdline')" "$PLUGIN_DIR/include/common.php" | head -1 | cut -d: -f1)"
[ -n "$MARKER_LINE" ] && [ -n "$PROC_SCAN_LINE" ] && [ "$MARKER_LINE" -lt "$PROC_SCAN_LINE" ]
grep -Fq 'jq flock fuser sg_ses' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq '$oldDisks === $newDisks' "$PLUGIN_DIR/include/api.php"
grep -Fq 'if (!$sameHardware) $shelf['\''calibration'\''] = [];' "$PLUGIN_DIR/include/api.php"
grep -Fq '$activeId === $id && md12xx_commission_active()' "$PLUGIN_DIR/include/api.php"
grep -Fq '$write['\''state'\''] === '\''pending'\''' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'no assigned disks; using fail-safe' "$PLUGIN_DIR/include/controller.php"
grep -Fq 'flock -w 15 9' "$PLUGIN_DIR/scripts/commission.sh"
grep -Fq 'md12xx_commission_active' "$PLUGIN_DIR/include/discovery.php"
grep -Fq 'md12xx-commission-start' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'pollCommission' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'Download test results' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'window.setTimeout(updateTitle, 400)' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'downloadLocalArchive("diagnostics", payload.file)' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'link.download = file' "$PLUGIN_DIR/assets/js/settings.js"
! grep -Fq 'window.location.assign(downloadEndpoint' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'Icon="icon-disks"' "$PLUGIN_DIR/MD12xxFanControl.page"
grep -Fq 'Tag="icon-disk"' "$PLUGIN_DIR/MD12xxFanControl.page"
grep -Fq 'Version @@VERSION@@' "$PLUGIN_DIR/MD12xxFanControl.page"
grep -Fq 'JSON_HEX_TAG' "$PLUGIN_DIR/MD12xxFanControl.page"
grep -Fq 'scheduleCurveResize' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'window.setTimeout(apply, 300)' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq "'pollSeconds' => 5" "$PLUGIN_DIR/include/common.php"
grep -Fq 'max(5, min(300' "$PLUGIN_DIR/include/common.php"
grep -Fq "'state' => 'blocked'" "$PLUGIN_DIR/include/controller.php"
grep -Fq "'timeout 10 '" "$PLUGIN_DIR/include/controller.php"
grep -Fq "unset(\$controlConfig['discovery'], \$controlConfig['pollSeconds'])" "$PLUGIN_DIR/include/controller.php"
grep -Fq 'Setup is complete; turn off Test likely FTDI adapters' "$PLUGIN_DIR/assets/js/settings.js"
grep -Fq 'Setup is already complete; active connection testing was turned off.' "$PROJECT_DIR/plugin/md12xx.fancontrol.plg.in"
grep -Fq 'rm -rf "$RUNTIME_DIR" "/var/run/md12xx.fancontrol" "$CONFIG_DIR"' "$PROJECT_DIR/plugin/md12xx.fancontrol.plg.in"
grep -Fq 'Configuration, commissioning results, and runtime state were deleted.' "$PROJECT_DIR/plugin/md12xx.fancontrol.plg.in"
grep -Fq 'application/zip' "$PLUGIN_DIR/include/download.php"
grep -Fq 'application/gzip' "$PLUGIN_DIR/include/download.php"
grep -Fq 'diagnostics.pid' "$PLUGIN_DIR/scripts/diagnose.sh"
grep -Fq 'diagnostics.pid' "$PLUGIN_DIR/scripts/stop.sh"
grep -Fq 'basename(' "$PLUGIN_DIR/include/download.php"

php -r '
  require $argv[1];
  $config = md12xx_defaults();
  $config["manualSpeed"] = 100;
  $validated = md12xx_validate_config($config);
  if ($validated["manualSpeed"] !== 100) exit(1);
  $config["shelves"] = [["id" => "shelf-a", "commissioned" => true]];
  $config["discovery"]["autoProbeKnownFtdi"] = true;
  $disabled = md12xx_disable_active_discovery_after_setup($config);
  if ($disabled["discovery"]["autoProbeKnownFtdi"] !== false) exit(1);
  $config["shelves"][] = ["id" => "shelf-b", "commissioned" => false];
  $retained = md12xx_disable_active_discovery_after_setup($config);
  if ($retained["discovery"]["autoProbeKnownFtdi"] !== true) exit(1);

  foreach (["serialPort", "sesAddress", "sesDevice", "disks"] as $duplicate) {
    $test = md12xx_defaults();
    $base = ["id" => "shelf-a", "serialPort" => "/dev/serial/by-id/a", "sesAddress" => "1:0:1:0", "sesDevice" => "/dev/sg1", "disks" => ["disk1"]];
    $other = ["id" => "shelf-b", "serialPort" => "/dev/serial/by-id/b", "sesAddress" => "1:0:2:0", "sesDevice" => "/dev/sg2", "disks" => ["disk2"]];
    $other[$duplicate] = $base[$duplicate];
    $test["shelves"] = [$base, $other];
    try { md12xx_validate_config($test); exit(1); } catch (InvalidArgumentException $expected) {}
  }
  $test = md12xx_defaults();
  $test["shelves"] = [["id" => "shelf-a", "calibration" => ["rpmAt20" => 3500, "rpmAt50" => 6300]]];
  $validated = md12xx_validate_config($test);
  if (($validated["shelves"][0]["calibration"]["rpmAt20"] ?? 0) !== 3500) exit(1);
  $calibration = ["rpmAt20" => 3500, "rpmAt50" => 6300];
  if (!md12xx_controller_verify_target(20, 3500, $calibration)["passed"]) exit(1);
  if (md12xx_controller_verify_target(20, 5200, $calibration)["passed"]) exit(1);
  if (!md12xx_controller_verify_target(30, 4400, $calibration)["passed"]) exit(1);
  if (md12xx_controller_verify_target(30, 3500, $calibration)["passed"]) exit(1);
  if (!md12xx_controller_verify_target(50, 6300, $calibration)["passed"]) exit(1);
  if (md12xx_controller_verify_target(50, 3500, $calibration)["passed"]) exit(1);
' "$PLUGIN_DIR/include/common.php"

php "$PLUGIN_DIR/include/controller.php" --once --dry-run \
  --config="$PROJECT_DIR/tests/fixtures/auto.json" \
  --disks="$PROJECT_DIR/tests/fixtures/disks.ini" \
  --fixture-dir="$PROJECT_DIR/tests/fixtures/ses" \
  --state="$TMP_DIR/auto-state.json"
jq -e '.controller.state == "normal" and .shelves[0].targetPercent == 30 and .shelves[0].averageRpm == 3500 and .shelves[0].fanCount == 4 and .shelves[0].writeState == "dry-run"' "$TMP_DIR/auto-state.json" >/dev/null

jq '.shelves[0].disks = ["disk1", "disk2"]' "$PROJECT_DIR/tests/fixtures/auto.json" > "$TMP_DIR/incomplete.json"
php "$PLUGIN_DIR/include/controller.php" --once --dry-run \
  --config="$TMP_DIR/incomplete.json" \
  --disks="$PROJECT_DIR/tests/fixtures/disks.ini" \
  --fixture-dir="$PROJECT_DIR/tests/fixtures/ses" \
  --state="$TMP_DIR/incomplete-state.json"
jq -e '.shelves[0].targetPercent == 50 and .shelves[0].targetReason == "assigned disk inventory incomplete; fail-safe" and .shelves[0].missingDisks == ["disk2"]' "$TMP_DIR/incomplete-state.json" >/dev/null

php "$PLUGIN_DIR/include/controller.php" --once --dry-run \
  --config="$PROJECT_DIR/tests/fixtures/manual.json" \
  --disks="$PROJECT_DIR/tests/fixtures/disks.ini" \
  --fixture-dir="$PROJECT_DIR/tests/fixtures/ses" \
  --state="$TMP_DIR/manual-state.json"
jq -e '.controller.mode == "manual" and .shelves[0].model == "MD1220" and .shelves[0].targetPercent == 100 and .shelves[0].writeState == "dry-run"' "$TMP_DIR/manual-state.json" >/dev/null

if grep -R -n -E '/dev/sg(11|18)|FTE33O9T|FTE32AB2|/mnt/user/Back-Up|MD1200_(TOP|BOTTOM)_' "$PROJECT_DIR/source"; then
  echo "Server-specific values remain in standalone source." >&2
  exit 1
fi

echo "MD12xx runtime verification passed."
