#!/usr/bin/env bash
# Asserts the built image has the required toolchain. Does NOT verify rendering — that is covered by the PDF render test in Task 6.
set -euo pipefail

docker compose run --rm --no-deps --entrypoint sh app -c '
  set -e

  for ext in pdo_pgsql intl bcmath zip gd pcntl; do
    php -m | grep -qx "$ext" || { echo "FAIL: missing PHP extension: $ext"; exit 1; }
  done
  echo "ok: php extensions"

  php -r "exit(version_compare(PHP_VERSION, \"8.4\", \">=\") ? 0 : 1);" \
    || { echo "FAIL: PHP 8.4+ required, found $(php -r "echo PHP_VERSION;")"; exit 1; }
  echo "ok: php $(php -r "echo PHP_VERSION;")"

  weasyprint --version || { echo "FAIL: weasyprint not runnable"; exit 1; }
  composer --version >/dev/null || { echo "FAIL: composer not runnable"; exit 1; }
  echo "ok: weasyprint and composer"
'

echo "PASS: image has the required toolchain (PHP, extensions, weasyprint, composer)"
