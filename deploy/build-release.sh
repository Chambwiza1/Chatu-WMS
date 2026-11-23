#!/bin/sh
# Builds a clean release zip: no .env, no data, no install lock, no logs.
# Usage: sh deploy/build-release.sh [output-dir]
set -e
cd "$(dirname "$0")/.."
VER=$(grep -o "APP_VERSION = '[^']*'" app/Libraries/Core/Audit.php | cut -d"'" -f2)
OUT=$(cd "${1:-..}" && pwd)
# the offline cache and page carry the version: refuse to build if they disagree
grep -q "chatuwms-$VER'" public/sw.js || { echo "public/sw.js cache name is not chatuwms-$VER"; exit 1; }
[ "$(grep -o '?v=[0-9.]*' public/offline.html | sort -u)" = "?v=$VER" ] || { echo "public/offline.html links are not ?v=$VER"; exit 1; }
STAGE=$(mktemp -d)/chatuwarehouse
mkdir -p "$STAGE"
tar --exclude='./.env' --exclude='./writable/*' --exclude='./.git' -cf - . | (cd "$STAGE" && tar -xf -)
for d in cache logs session uploads debugbar; do mkdir -p "$STAGE/writable/$d"; cp writable/index.html "$STAGE/writable/$d/index.html" 2>/dev/null || true; done
cp writable/.htaccess writable/index.html "$STAGE/writable/"
chmod -R u+rwX,go+rX "$STAGE" && chmod -R 775 "$STAGE/writable"
(cd "$(dirname "$STAGE")" && rm -f "$OUT/chatu-warehouse-$VER.zip" && zip -qr9 "$OUT/chatu-warehouse-$VER.zip" chatuwarehouse)
rm -rf "$(dirname "$STAGE")"
echo "$OUT/chatu-warehouse-$VER.zip"
