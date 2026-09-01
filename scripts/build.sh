#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_PATH="${1:-$PROJECT_DIR/dist/md12xx.fancontrol.plg}"
VERSION="$(tr -d '\r\n' < "$PROJECT_DIR/VERSION")"
FILES_PATH="$(mktemp)"
trap 'rm -f "$FILES_PATH"' EXIT

while IFS= read -r -d '' SOURCE_FILE; do
  RELATIVE="/${SOURCE_FILE#"$PROJECT_DIR/source/"}"
  grep -q ']]>' "$SOURCE_FILE" && { echo "Source contains invalid CDATA terminator: $SOURCE_FILE" >&2; exit 1; }
  printf '<FILE Name="%s">\n<INLINE>\n<![CDATA[\n' "$RELATIVE" >> "$FILES_PATH"
  sed "s/@@VERSION@@/$VERSION/g" "$SOURCE_FILE" >> "$FILES_PATH"
  printf '\n]]>\n</INLINE>\n</FILE>\n' >> "$FILES_PATH"
done < <(find "$PROJECT_DIR/source" -type f -print0 | sort -z)

mkdir -p "$(dirname "$OUTPUT_PATH")"
awk -v files="$FILES_PATH" -v version="$VERSION" '
  /@@FILES@@/ { while ((getline line < files) > 0) print line; close(files); next }
  { gsub(/@@VERSION@@/, version); print }
' "$PROJECT_DIR/plugin/md12xx.fancontrol.plg.in" > "$OUTPUT_PATH"
echo "Built $OUTPUT_PATH"

