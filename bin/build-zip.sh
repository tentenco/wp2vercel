#!/bin/sh
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
plugin="$root/wp2vercel"
header="$(sed -n 's/^ \* Version: *//p' "$plugin/wp2vercel.php" | tr -d '[:space:]')"
constant="$(sed -n "s/^define('WP2VERCEL_VERSION', '\(.*\)');/\1/p" "$plugin/wp2vercel.php")"
stable="$(sed -n 's/^Stable tag: *//p' "$plugin/readme.txt" | tr -d '[:space:]')"
expected="${1:-$header}"

for found in "$header" "$constant" "$stable"; do
  if [ "$found" != "$expected" ]; then
    echo "Version mismatch: expected $expected, found header=$header constant=$constant readme=$stable" >&2
    exit 1
  fi
done

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/wp2vercel" "$root/dist"
(cd "$plugin" && tar -cf - \
  --exclude='./tests' \
  --exclude='./phpunit.xml' \
  --exclude='./.phpunit.cache' \
  --exclude='.DS_Store' \
  .) | (cd "$stage/wp2vercel" && tar -xf -)

find "$stage" -exec env TZ=UTC touch -t 202601010000 {} +

rm -f "$root/dist/wp2vercel.zip"
(cd "$stage" && find wp2vercel -type f | LC_ALL=C sort | TZ=UTC zip -q -X "$root/dist/wp2vercel.zip" -@)
echo "Built dist/wp2vercel.zip ($expected)"
