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
esac

if [ ! -f "$WP_PATH/wp-load.php" ]; then
  echo "[FATAL] WP_PATH not found: $WP_PATH" >&2
  echo "        Set WP_PATH to the target site's app/public dir." >&2
  exit 2
fi

WP_BIN="${WP_BIN:-$HOME/.local/bin/wp}"

if [ -z "$SUITES" ]; then
  SUITES="suite_core suite_bookings suite_commissions suite_promotions suite_views suite_analytics suite_payments suite_guest_checkout suite_leads suite_booking_flow suite_pricing suite_paypal suite_rest suite_reviews suite_i18n suite_bugs suite_seo suite_agency_manage suite_chatbot suite_moderation suite_destinations suite_tour_catalog suite_equipment suite_agency_approval suite_request_to_book suite_attribution suite_reviews_verified suite_disputes suite_privacy suite_itinerary suite_automations suite_content suite_dashboard suite_booking_lifecycle suite_price_authority suite_back_office suite_refunds suite_front_editor"
fi

fails=0
total_ok=0
for suite in $SUITES; do
  file="$SCRIPT_DIR/$suite.php"
  [ -f "$file" ] || { echo "[SKIP] missing $file"; continue; }
  out="$($WP_BIN --path="$WP_PATH" eval-file "$file" 2>&1)"
  code=$?
  final="$(echo "$out" | grep -E '^fail=[0-9]+ ' | tail -1)"
  nf="${final#fail=}"; nf="${nf%% *}"
  echo "== $suite (suite exit $code) =="
  echo "$out" | grep -E '^\[(PASS|FAIL)|^fail=' | sed 's/^/   /'
  if [ "$code" -eq 0 ]; then
    echo "   -> ok"
  else
    echo "   -> FAILED"
    fails=$((fails+1))
  fi
done

echo
echo "Suites failing: $fails"
exit $fails