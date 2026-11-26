#!/bin/sh
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
tools="$root/.tools"
versions="${*:-8.1 8.2 8.3 8.4}"

command -v docker > /dev/null || { echo "Docker is needed to run the PHP matrix." >&2; exit 1; }
mkdir -p "$tools/phpunit" "$tools/wordpress-core"

quiet() {
  log="$(mktemp)"
  if "$@" > "$log" 2>&1; then
    grep -E "^(OK|Tests:|# (tests|pass|fail))" "$log" | sed 's/^/  /' || true
    rm -f "$log"
    return 0
  fi
  cat "$log"
  rm -f "$log"
  return 1
}

phpunit_for() {
  case "$1" in
    8.1) echo 10 ;;
    8.2) echo 11 ;;
    *) echo 12 ;;
  esac
}

if [ ! -f "$tools/wordpress-core/wordpress/wp-includes/version.php" ]; then
  echo "Fetching WordPress for the HTML API"
  curl -fsSL https://wordpress.org/latest.tar.gz | tar -xz -C "$tools/wordpress-core"
fi

node --check "$root/wp2vercel/assets/admin.js"
echo "JavaScript syntax: ok"

for php in $versions; do
  major="$(phpunit_for "$php")"
  phar="$tools/phpunit/phpunit-$major.phar"
  if [ ! -f "$phar" ]; then
    echo "Fetching PHPUnit $major"
    curl -fsSL -o "$phar" "https://phar.phpunit.de/phpunit-$major.phar"
  fi
  echo "PHP $php with PHPUnit $major"
  quiet docker run --rm \
    -v "$root:/work:ro" \
    -v "$tools:/tools:ro" \
    -e WP_CORE_DIR=/tools/wordpress-core/wordpress \
    -w /work/wp2vercel \
    "php:$php-cli" sh -c "
      set -e
      find . -name '*.php' -not -path './.phpunit.cache/*' -print0 | xargs -0 -n1 php -l > /dev/null
      php /tools/phpunit/phpunit-$major.phar --do-not-cache-result --no-progress --colors=never
    "
done

"$root/bin/build-zip.sh"
echo "All checks passed."
