#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RUNTIME_DIR="$PROJECT_DIR/source/usr/local/emhttp/plugins/md12xx.fancontrol"

if find "$RUNTIME_DIR" -type f ! -name '*.svg' -print0 | xargs -0 grep -n -E 'https?://|\b(curl|wget|socat|telnet|ssh|scp)\b|fsockopen|stream_socket_client|curl_[a-z_]+'; then
  echo "Outbound network primitive found in installed runtime." >&2
  exit 1
fi

if grep -R -n -E '/mnt(/|$)|/root(/|$)|/home(/|$)|docker\.sock' "$RUNTIME_DIR"; then
  echo "Broad filesystem or Docker socket access found in installed runtime." >&2
  exit 1
fi

if grep -R -n -E '/dev/sg(11|18)|FTE33O9T|FTE32AB2|/mnt/user/Back-Up|MD1200_(TOP|BOTTOM)_' "$PROJECT_DIR/source"; then
  echo "Server-specific identifier found in installed runtime." >&2
  exit 1
fi

DISCOVERY="$RUNTIME_DIR/include/discovery.php"
DIAGNOSTICS="$RUNTIME_DIR/scripts/diagnose.sh"
grep -Fq "printf '_who\\r'" "$RUNTIME_DIR/scripts/commission.sh"
if grep -n 'set_speed' "$DISCOVERY"; then
  echo "Read-only discovery contains a speed command." >&2
  exit 1
fi

grep -Fq 'config.redacted.json' "$DIAGNOSTICS"
grep -Fq 'status.redacted.json' "$DIAGNOSTICS"
grep -Fq 'discovery.redacted.json' "$DIAGNOSTICS"
grep -Fq 'uname -rmo' "$DIAGNOSTICS"
grep -Fq '.assignedDisks = ' "$DIAGNOSTICS"
grep -Fq '.missingDisks = ' "$DIAGNOSTICS"
grep -Fq '.temperatureSource = "mapped-disk"' "$DIAGNOSTICS"
grep -Fq '.sesAddress = ' "$DIAGNOSTICS"
grep -Fq 'enclosure-${ENCLOSURE_INDEX}-ses.txt' "$DIAGNOSTICS"
if grep -Fq 'cp -f "$CONFIG_DIR/config.json"' "$DIAGNOSTICS"; then
  echo "Diagnostics copies raw configuration." >&2
  exit 1
fi

grep -Fq "const MD12XX_CONFIG_FILE = '/boot/config/plugins/md12xx.fancontrol/config.json';" "$RUNTIME_DIR/include/common.php"
grep -Fq "const MD12XX_RUNTIME_DIR = '/var/run/md12xx.fancontrol';" "$RUNTIME_DIR/include/common.php"
grep -Fq 'credentials: "same-origin"' "$RUNTIME_DIR/assets/js/settings.js"
grep -Fq 'window.csrf_token' "$RUNTIME_DIR/assets/js/settings.js"
grep -Fq 'basename(' "$RUNTIME_DIR/include/download.php"
grep -Fq 'realpath(' "$RUNTIME_DIR/include/download.php"
grep -Fq 'application/gzip' "$RUNTIME_DIR/include/download.php"

echo "MD12xx local-only security policy passed."
