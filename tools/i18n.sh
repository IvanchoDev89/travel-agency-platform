#!/usr/bin/env bash
#
# Refresh the plugin's translation catalogs from a freshly generated POT.
#
# The source language is Spanish (msgids are in Spanish):
#   - es_ES : identity for Spanish msgids; translates any English msgid to ES.
#   - en_US : Spanish msgid -> English translation.
#
# `wp i18n make-pot` does not extract every runtime string (for example the
# chatbot replies injected via __($spec['reply'])) and the catalogs also carry
# curated entries. A plain `msgmerge` would move those to the obsolete section
# and drop them from the compiled catalog, so this script rescues them back.
#
# Requirements: wp-cli (`wp i18n`) and the GNU gettext tools (msgmerge,
# msgattrib, msgcat, msgfmt). Override the wp binary with WP_BIN=/path/to/wp.
#
# Usage:
#   tools/i18n.sh              # regenerate POT + update and compile both catalogs
#   tools/i18n.sh --pot-only   # only regenerate languages/travel-agency-platform.pot
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

PLUGIN_DIR="$ROOT_DIR/app/public/wp-content/plugins/travel-agency-platform"
LANG_DIR="$PLUGIN_DIR/languages"
DOMAIN="travel-agency-platform"
LOCALES=("es_ES" "en_US")
POT="$LANG_DIR/$DOMAIN.pot"

WP_BIN="${WP_BIN:-$(command -v wp || true)}"

die() { echo "[i18n] $*" >&2; exit 1; }

for tool in msgmerge msgattrib msgcat msgfmt; do
    command -v "$tool" >/dev/null 2>&1 || die "missing gettext tool: $tool"
done
[ -n "$WP_BIN" ] || die "wp-cli not found (install it or set WP_BIN=/path/to/wp)"
[ -d "$PLUGIN_DIR" ] || die "plugin directory not found: $PLUGIN_DIR"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "[i18n] generating POT for $DOMAIN ..."
"$WP_BIN" i18n make-pot "$PLUGIN_DIR" "$POT" --slug="$DOMAIN" --domain="$DOMAIN" --quiet
echo "[i18n] POT written to ${POT#"$ROOT_DIR"/} ($(grep -c '^msgid ' "$POT") msgids)"

if [ "${1:-}" = "--pot-only" ]; then
    exit 0
fi

for loc in "${LOCALES[@]}"; do
    po="$LANG_DIR/$DOMAIN-$loc.po"
    mo="$LANG_DIR/$DOMAIN-$loc.mo"
    [ -f "$po" ] || die "missing catalog: $po"

    # 1. Refresh references/header from the POT, keep existing translations for
    #    matching msgids, and move POT-unknown entries to the obsolete section.
    msgmerge --no-fuzzy-matching -o "$tmp/merged.po" "$po" "$POT" 2>/dev/null

    # 2. Rescue the entries the POT did not know about: extract them from the
    #    obsolete section and reactivate them by stripping the leading "#~ ".
    msgattrib --only-obsolete --no-wrap "$tmp/merged.po" \
        | sed -E 's/^#~ ?//' > "$tmp/rescue.po"

    # 3. Re-merge: updated catalog first (its translations win), rescued second.
    msgattrib --no-obsolete --no-wrap "$tmp/merged.po" > "$tmp/active.po"
    msgcat --use-first "$tmp/active.po" "$tmp/rescue.po" -o "$tmp/final.po"

    # 4. Validate and compile, then install the refreshed catalog.
    msgfmt --check --statistics -o "$mo" "$tmp/final.po"
    mv "$tmp/final.po" "$po"

    printf '[i18n] %s: %s msgids -> %s\n' \
        "$loc" "$(grep -c '^msgid ' "$po")" "$(basename "$mo")"
done

echo "[i18n] done."
