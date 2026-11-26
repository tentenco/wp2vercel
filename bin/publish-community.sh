#!/bin/sh
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

remote="${WP2VERCEL_COMMUNITY_REMOTE:-community}"
version="$(sed -n "s/^define('WP2VERCEL_VERSION', '\(.*\)');/\1/p" wp2vercel/wp2vercel.php)"

git fetch -q "$remote" main
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
export GIT_INDEX_FILE="$work/index"
git read-tree HEAD
set -- harness reports wp2vercel-account
git rm -r -q --cached --ignore-unmatch "$@"
tree="$(git write-tree)"
unset GIT_INDEX_FILE

parent="$(git rev-parse "$remote/main")"
if [ "$(git rev-parse "$parent^{tree}")" = "$tree" ]; then
  echo "$parent"
  exit 0
fi
commit="$(printf 'WP2Vercel %s\n' "$version" | git commit-tree "$tree" -p "$parent")"
git push -q "$remote" "$commit:refs/heads/main"
echo "$commit"
