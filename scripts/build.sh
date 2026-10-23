#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_PATH="${1:-$PROJECT_DIR/dist/md12xx.fancontrol.plg}"
VERSION="$(tr -d '\r\n' < "$PROJECT_DIR/VERSION")"
PLUGIN_VERSION="$(tr -d '\r\n' < "$PROJECT_DIR/PLUGIN_VERSION")"
FILES_PATH="$(mktemp)"
trap 'rm -f "$FILES_PATH"' EXIT

while IFS= read -r -d '' SOURCE_FILE; do
  RELATIVE="/${SOURCE_FILE#"$PROJECT_DIR/source/"}"
  RELATIVE="${RELATIVE/\/plugins\/md12xx.fancontrol\//\/plugins\/.md12xx.fancontrol.staged\/}"
  if [[ "$SOURCE_FILE" == *.png || "$RELATIVE" == */bin/* ]]; then
    printf '<FILE Name="%s" Type="base64"><INLINE>' "$RELATIVE" >> "$FILES_PATH"
    base64 < "$SOURCE_FILE" | tr -d '\r\n' >> "$FILES_PATH"
    printf '</INLINE></FILE>\n' >> "$FILES_PATH"
    continue
  fi
  grep -q ']]>' "$SOURCE_FILE" && { echo "Source contains invalid CDATA terminator: $SOURCE_FILE" >&2; exit 1; }
  printf '<FILE Name="%s">\n<INLINE>\n<![CDATA[\n' "$RELATIVE" >> "$FILES_PATH"
  sed "s/@@VERSION@@/$VERSION/g;s/@@PLUGIN_VERSION@@/$PLUGIN_VERSION/g;s/\r$//" "$SOURCE_FILE" >> "$FILES_PATH"
  printf '\n]]>\n</INLINE>\n</FILE>\n' >> "$FILES_PATH"
done < <(find "$PROJECT_DIR/source" -type f -print0 | sort -z)

mkdir -p "$(dirname "$OUTPUT_PATH")"
awk -v files="$FILES_PATH" -v version="$VERSION" -v plugin_version="$PLUGIN_VERSION" '
  /@@FILES@@/ { while ((getline line < files) > 0) print line; close(files); next }
  { sub(/\r$/, ""); gsub(/@@VERSION@@/, version); gsub(/@@PLUGIN_VERSION@@/, plugin_version); print }
' "$PROJECT_DIR/plugin/md12xx.fancontrol.plg.in" > "$OUTPUT_PATH"
echo "Built $OUTPUT_PATH"

