#!/usr/bin/env bash
#
# Run the static analysis toolchain locally (same checks as CI).
#
#   tools/lint.sh            # PHPStan (blocking) + PHPCS (informational)
#   tools/lint.sh --phpstan  # PHPStan only
#   tools/lint.sh --phpcs    # PHPCS only
#   tools/lint.sh --fix      # PHPCS auto-fixes what it can, then reports
#
# Requires `composer install` to have been run once.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

[ -x vendor/bin/phpstan ] || { echo "[lint] run 'composer install' first" >&2; exit 2; }

run_phpstan=1
run_phpcs=1
fix=0
case "${1:-}" in
    --phpstan) run_phpcs=0 ;;
    --phpcs)   run_phpstan=0 ;;
    --fix)     fix=1 ;;
    "")        ;;
    *)         echo "[lint] unknown option: $1" >&2; exit 2 ;;
esac

status=0

if [ "$run_phpstan" -eq 1 ]; then
    echo "[lint] PHPStan (level 1) ..."
    vendor/bin/phpstan analyse --no-progress || status=1
fi

if [ "$run_phpcs" -eq 1 ]; then
    if [ "$fix" -eq 1 ]; then
        echo "[lint] PHPCS autofix ..."
        vendor/bin/phpcbf || true
    fi
    echo "[lint] PHPCS (WordPress, focused ruleset) ..."
    # Informational for now: the codebase still carries WPCS debt that is being
    # burned down incrementally, so this does not fail the run.
    vendor/bin/phpcs --report=summary || true
fi

if [ "$status" -ne 0 ]; then
    echo "[lint] FAILED" >&2
else
    echo "[lint] OK"
fi
exit "$status"
