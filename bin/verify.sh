#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${WP_TESTS_DIR:?Set WP_TESTS_DIR to a WordPress test library configured for a disposable test database.}"
: "${PHP_BINARY:=php}"
if [[ ! -x vendor/bin/phpunit || ! -x vendor/bin/phpcs ]]; then
  echo "Run composer install before verification." >&2
  exit 1
fi
npm ci
npm run build
while IFS= read -r -d '' source; do "$PHP_BINARY" -l "$source"; done < <(find . -path ./vendor -prune -o -path ./node_modules -prune -o -name '*.php' -print0)
node --check admin/js/jtc-admin.js
node --check public/js/jtc-public.js
node --check blocks/petition/index.js
# Print the full standards report. Warnings need review; errors fail the gate.
"$PHP_BINARY" vendor/bin/phpcs --report=full || standards_status=$?
if [[ "${standards_status:-0}" != 0 ]]; then "$PHP_BINARY" vendor/bin/phpcs -n; fi
"$PHP_BINARY" vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.0- --extensions=php --ignore=vendor,node_modules,tests .
"$PHP_BINARY" vendor/bin/phpunit
