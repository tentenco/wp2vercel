#!/bin/sh
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

version="$(sed -n "s/^define('WP2VERCEL_VERSION', '\(.*\)');/\1/p" wp2vercel/wp2vercel.php)"
tag="v$version"
community="${WP2VERCEL_COMMUNITY_REPO:-tentenco/wp2vercel}"
dry_run=0
[ "${1:-}" = "--dry-run" ] && dry_run=1

command -v gh > /dev/null || { echo "The GitHub CLI (gh) is needed to publish a release." >&2; exit 1; }
if [ -n "$(git status --porcelain)" ]; then
  echo "Commit or stash your changes first." >&2
  exit 1
fi
if ! grep -q "^## \[$version\]" CHANGELOG.md; then
  echo "CHANGELOG.md has no entry for $version." >&2
  exit 1
fi
if gh release view "$tag" -R "$community" > /dev/null 2>&1; then
  echo "Release $tag already exists." >&2
  exit 1
fi

if [ "$dry_run" -eq 0 ]; then
  git fetch -q origin main
  if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    echo "Release the commit that is on origin/main. Merge your branch and pull first." >&2
    exit 1
  fi
fi

bin/ci.sh

notes="$(mktemp)"
trap 'rm -f "$notes"' EXIT
awk -v version="$version" '
  $0 ~ "^## \\[" version "\\]" { printing = 1; next }
  printing && /^## \[/ { exit }
  printing { print }
' CHANGELOG.md > "$notes"

if [ "$dry_run" -eq 1 ]; then
  echo "Dry run. Would publish $tag to $community from $(git rev-parse --short HEAD) with dist/wp2vercel.zip and these notes:"
  cat "$notes"
  exit 0
fi

target="$(bin/publish-community.sh)"
gh release create "$tag" dist/wp2vercel.zip -R "$community" --target "$target" --title "WP2Vercel $version" --notes-file "$notes"
echo "Published $tag to $community."
