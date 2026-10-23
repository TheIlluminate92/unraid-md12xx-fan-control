#!/bin/sh
set -eu
apk add --no-cache build-base autoconf automake libtool linux-headers curl openssl-dev openssl-libs-static
cd /work
curl -fsSL https://codeload.github.com/ipmitool/ipmitool/tar.gz/19d78782d795d0cf4ceefe655f616210c9143e62 -o source.tar.gz
sha256sum source.tar.gz
echo "b7c9e29e70582e3a2f11cf3ecddba760c54b6aae76951a6e8c4935281f58a662  source.tar.gz" | sha256sum -c -
mkdir -p source
tar -xzf source.tar.gz -C source --strip-components=1
cd source
make distclean >/dev/null 2>&1 || true
./bootstrap
./configure --enable-static --disable-shared --disable-intf-serial --disable-intf-free --disable-intf-usb --disable-intf-dummy --disable-ipmishell --without-readline LDFLAGS=-static IANADIR=/usr/local/emhttp/plugins/md12xx.fancontrol/bin
make -j2 LDFLAGS=-all-static
strip src/ipmitool
cp src/ipmitool /work/ipmitool.bin
cp COPYING /work/IPMITOOL-LICENSE
file src/ipmitool || true
./src/ipmitool -V
if readelf -l src/ipmitool | grep -q INTERP; then echo "Unexpected dynamic dependency" >&2; exit 1; fi
