#!/usr/bin/env bash
# TAP test runner — executes all E2E suites against a target WP install.
#
# Usage:
#   SITE=travel    ./tests/run.sh            # travel-agency site (default)
#   SITE=ivanchodev ./tests/run.sh           # ivanchodev site
#   WP_PATH="/abs/path/app/public" ./tests/run.sh
#   SUITES="suite_promotions suite_views" ./tests/run.sh   # subset
#
# Each suite runs in a fresh `wp eval-file` process against real WP code +
# a real MySQL install, so results reflect actual plugin behaviour.
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"
SITE="${SITE:-travel}"
SUITES="${SUITES:-}"

case "$SITE" in
  travel)     WP_PATH="${WP_PATH:-$HOME/Local Sites/travel-agency/app/public}" ;;
  ivanchodev) WP_PATH="${WP_PATH:-$HOME/Local Sites/ivanchodev/app/public}" ;;
  *) WP_PATH="${WP_PATH:-}" ;;
esac

if [ ! -f "$WP_PATH/wp-load.php" ]; then
  echo "[FATAL] WP_PATH not found: $WP_PATH" >&2
  echo "        Set WP_PATH to the target site's app/public dir." >&2
  exit 2
fi

WP_BIN="${WP_BIN:-$HOME/.local/bin/wp}"
if [ ! -x "$WP_BIN" ]; then
  echo "[FATAL] WP_BIN not executable: $WP_BIN" >&2
  exit 2
fi

if [ "${TAP_TEST_ALLOW_DB_WRITES:-}" != "1" ]; then
  echo "[FATAL] These suites modify and may delete site data: $WP_PATH" >&2
  echo "Use a disposable test site and set TAP_TEST_ALLOW_DB_WRITES=1 to continue." >&2
  exit 2
fi

SUITE_TIMEOUT="${SUITE_TIMEOUT:-300}"
if ! [[ "$SUITE_TIMEOUT" =~ ^[1-9][0-9]*$ ]] || ! command -v timeout >/dev/null 2>&1; then
  echo "[FATAL] timeout is required and SUITE_TIMEOUT must be a positive integer." >&2
  exit 2
fi

preflight="$(timeout --kill-after=5 "$SUITE_TIMEOUT" "$WP_BIN" --path="$WP_PATH" eval 'if (!defined("TAP_VERSION") || !class_exists("TAP_Booking")) { WP_CLI::error("Travel Agency Platform is not active."); }' 2>&1)"
if [ "$?" -ne 0 ]; then
  printf '[FATAL] WordPress preflight failed:\n%s\n' "$preflight" >&2
  exit 2
fi

if [ -z "$SUITES" ]; then
  SUITES="suite_core suite_bookings suite_commissions suite_promotions suite_views suite_analytics suite_payments suite_guest_checkout suite_leads suite_booking_flow suite_pricing suite_paypal suite_rest suite_reviews suite_i18n suite_bugs suite_seo suite_agency_manage suite_chatbot suite_moderation suite_destinations suite_tour_catalog suite_equipment suite_agency_approval suite_request_to_book suite_attribution suite_reviews_verified suite_disputes suite_privacy suite_itinerary suite_automations suite_content suite_dashboard suite_booking_lifecycle suite_price_authority suite_back_office suite_refunds suite_front_editor"
fi

fails=0
total_ok=0
for suite in $SUITES; do
  if ! [[ "$suite" =~ ^suite_[a-zA-Z0-9_]+$ ]]; then
    echo "[FAIL] invalid suite name: $suite"
    fails=$((fails+1))
    continue
  fi
  file="$SCRIPT_DIR/$suite.php"
  [ -f "$file" ] || { echo "[FAIL] missing $file"; fails=$((fails+1)); continue; }
  out="$(timeout --kill-after=5 "$SUITE_TIMEOUT" "$WP_BIN" --path="$WP_PATH" eval-file "$file" 2>&1)"
  code=$?
  summaries=0
  nf=""
  invalid=0
  passes=0
  while IFS= read -r line; do
    if [[ "$line" =~ ^fail=([0-9]+)\ done$ ]]; then
      summaries=$((summaries+1))
      nf="${BASH_REMATCH[1]}"
    elif [[ "$line" == fail=* ]]; then
      invalid=1
    fi
    if [[ "$line" == *'[FAIL]'* || "$line" == *'SKIP'* ]]; then
      invalid=1
    fi
    if [[ "$line" == '[PASS]'* ]]; then
      passes=$((passes+1))
    fi
  done <<< "$out"
  echo "== $suite (suite exit $code) =="
  printf '%s\n' "$out"
  if [ "$code" -eq 0 ] && [ "$summaries" -eq 1 ] && [ "$nf" = "0" ] && [ "$invalid" -eq 0 ] && [ "$passes" -gt 0 ]; then
    echo "   -> ok"
    total_ok=$((total_ok+1))
  else
    echo "   -> FAILED (requires assertions, one fail=0 done summary, no failures or skips, and exit 0)"
    fails=$((fails+1))
  fi
done

echo
echo "Suites failing: $fails"
exit $fails