<?php
/**
 * E2E suite: i18n engine (default es_ES front-end + en_US translation).
 * Run via: wp --path="<site>/app/public" eval-file tests/suite_i18n.php
 */
require __DIR__ . '/bootstrap.php';

$mo_en  = TAP_PLUGIN_DIR . 'languages/travel-agency-platform-en_US.mo';
$mo_es  = TAP_PLUGIN_DIR . 'languages/travel-agency-platform-es_ES.mo';
tap_t_assert(file_exists($mo_en), 'en_US.mo shipped');
tap_t_assert(file_exists($mo_es), 'es_ES.mo shipped');

$L = 'TAP_Localization';
tap_t_assert(class_exists($L), 'TAP_Localization class present');

// 1) Default front-end: Spanish (msgids stay OR English msgids translate to ES)
tap_t_assert(get_locale() === 'es_ES', 'front-end default locale is es_ES (got ' . get_locale() . ')');
tap_t_assert(__('Ver voucher', 'travel-agency-platform') === 'Ver voucher', 'es mode keeps Spanish msgid unchanged');
tap_t_assert(__('Adults', 'travel-agency-platform') === 'Adultos', 'es mode translates English msgid to Spanish: ' . __('Adults', 'travel-agency-platform'));

// 2) set_lang('en') -> en_US translation active
tap_t_assert($L::set_lang('en') === true, 'set_lang("en") accepted');
tap_t_assert(get_locale() === 'en_US', 'en mode locale is en_US (got ' . get_locale() . ')');
tap_t_assert(__('Ver voucher', 'travel-agency-platform') === 'View voucher', 'en mode translates Spanish msgid: got ' . __('Ver voucher', 'travel-agency-platform'));
tap_t_assert(__('Adults', 'travel-agency-platform') === 'Adults', 'en mode keeps English msgid unchanged');

// 3) Switcher renders both languages + active marker
$sw = do_shortcode('[tap_lang_switcher]');
tap_t_assert(strpos($sw, '?lang=es') !== false && strpos($sw, '?lang=en') !== false, 'switcher renders both lang links');
tap_t_assert(strpos($sw, 'is-active') !== false && $L::current_lang() === 'en', 'switcher marks active lang (en)');

// 4) Invalid lang ignored
tap_t_assert($L::set_lang('fr') === false, 'set_lang("fr") rejected');

// 5) Back to Spanish restores defaults
tap_t_assert($L::set_lang('es') === true, 'set_lang("es") accepted');
tap_t_assert(get_locale() === 'es_ES', 'back to es_ES');
tap_t_assert(__('Ver voucher', 'travel-agency-platform') === 'Ver voucher', 'es restored after en round-trip');
tap_t_assert(__('Adults', 'travel-agency-platform') === 'Adultos', 'es EN->ES translation restored');

// 6) Catalog integrity: both .mo files load, are substantial and fully translated.
$entry_text = static function ($entry) {
    if (is_object($entry)) {
        return implode('|', (array) ($entry->translations ?? []));
    }
    return (string) $entry;
};
foreach (['es_ES', 'en_US'] as $loc) {
    $mo = new MO();
    $loaded = $mo->import_from_file(TAP_PLUGIN_DIR . "languages/travel-agency-platform-{$loc}.mo");
    tap_t_assert(false !== $loaded, "{$loc}.mo loads successfully");
    tap_t_assert(count($mo->entries) > 4000, "{$loc}.mo has a full catalog (" . count($mo->entries) . " entries)");
    $empty = 0;
    foreach ($mo->entries as $translation) {
        if ('' === $entry_text($translation)) { $empty++; }
    }
    tap_t_assert(0 === $empty, "{$loc}.mo has no empty translations ({$empty} found)");
}

// High-traffic msgids must resolve in both catalogs.
foreach (['es_ES', 'en_US'] as $loc) {
    $mo = new MO();
    $mo->import_from_file(TAP_PLUGIN_DIR . "languages/travel-agency-platform-{$loc}.mo");
    foreach (['Adults', 'Ver voucher', 'Cancelada', 'Comisión', 'Buscar'] as $msgid) {
        tap_t_assert(isset($mo->entries[$msgid]) && '' !== $entry_text($mo->entries[$msgid]), "{$loc} resolves high-traffic msgid '{$msgid}'");
    }
}

// Plural forms must keep both entries.
$mo = new MO();
$mo->import_from_file(TAP_PLUGIN_DIR . 'languages/travel-agency-platform-es_ES.mo');
tap_t_assert(isset($mo->entries['%d unidad']) && false !== strpos($entry_text($mo->entries['%d unidad']), 'unidad'), 'singular plural entry present');
$plural = $mo->entries['%d unidad'] ?? null;
tap_t_assert($plural && is_object($plural) && count($plural->translations) >= 2, 'plural forms stored for the singular msgid');
tap_t_assert($plural && false !== strpos($entry_text($plural), 'unidades'), 'plural translation contains the plural form');

tap_t_finish();