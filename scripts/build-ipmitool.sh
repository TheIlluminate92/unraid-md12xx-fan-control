#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf -- "$WORK"' EXIT
cp "$ROOT/scripts/build-ipmitool-container.sh" "$WORK/build.sh"
docker run --rm --mount "type=bind,src=$WORK,dst=/work" \
  alpine@sha256:21f3e19bafab57a9ff7aa9dd571dac72f868370472c10f450cc0416cc1aca553 sh /work/build.sh
DEST="$ROOT/source/usr/local/emhttp/plugins/md12xx.fancontrol/bin"
cp "$WORK/ipmitool.bin" "$DEST/ipmitool.bin"
cp "$WORK/IPMITOOL-LICENSE" "$DEST/IPMITOOL-LICENSE"
(cd "$DEST" && sha256sum ipmitool.bin enterprise-numbers IPMITOOL-LICENSE MUSL-LICENSE OPENSSL-LICENSE > SHA256SUMS)
echo 'Dependency rebuilt. Review changed library versions and checksums before packaging.'
