#!/bin/bash
# Build packages/mover.dashboard-VERSION.txz from src/ and stamp its version and
# md5 into mover.dashboard.plg. Usage: ./build.sh [version]   (default: today)
set -euo pipefail
cd "$(dirname "$0")"

name=mover.dashboard
version=${1:-$(date +%Y.%m.%d)}
pkg="packages/$name-$version.txz"

mkdir -p packages
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -a src/. "$stage/"
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +
(cd "$stage" && makepkg -l y -c y "$OLDPWD/$pkg" >/dev/null)

md5=$(md5sum "$pkg" | cut -d' ' -f1)
sed -i -e "s|<!ENTITY version   \"[^\"]*\">|<!ENTITY version   \"$version\">|" \
       -e "s|<!ENTITY md5       \"[^\"]*\">|<!ENTITY md5       \"$md5\">|" "$name.plg"
echo "$pkg $md5"
