<?php
/**
 * suite_currency.php — TAP_Currency: ISO code map, per-code decimals/symbols,
 * HTML-entity and plain (UTF-8) formatting, and tap_currency option roundtrip.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_currency.php
 */
require __DIR__ . '/bootstrap.php';

$orig      = get_option('tap_currency');
$orig_set  = ($orig !== false);

/* 1) code resolution ---------------------------------------------------------- */
tap_t_assert(TAP_Currency::code() === strtoupper((string) ($orig === false ? 'USD' : $orig)), 'code() reflects tap_currency option');

/* 2) supported codes ---------------------------------------------------------- */
$codes = TAP_Currency::codes();
tap_t_assert(is_array($codes) && count($codes) >= 15, 'codes() exposes a broad ISO map (' . count($codes) . ')');
foreach (['USD', 'EUR', 'CRC', 'JPY', 'MXN', 'BRL'] as $c) {
    tap_t_assert(in_array($c, $codes, true), "codes() includes {$c}");
}

/* 3) decimals + symbols ------------------------------------------------------- */
tap_t_assert(TAP_Currency::decimals('JPY') === 0, 'JPY uses 0 decimals');
tap_t_assert(TAP_Currency::decimals('USD') === 2, 'USD uses 2 decimals');
tap_t_assert(TAP_Currency::decimals('CRC') === 2, 'CRC uses 2 decimals');
tap_t_assert(TAP_Currency::symbol('USD') === '$', 'USD symbol');
tap_t_assert(TAP_Currency::symbol('CRC') === '&cent;', 'CRC symbol entity');
tap_t_assert(TAP_Currency::symbol('EUR') === '&euro;', 'EUR symbol entity');
tap_t_assert(TAP_Currency::symbol('ZZZ') === 'ZZZ', 'unknown code falls back to the code itself');

/* 4) plain symbol for JS contexts -------------------------------------------- */
$plain = TAP_Currency::plain_symbol('EUR');
tap_t_assert($plain === '€', 'plain_symbol decodes entities to UTF-8 (got ' . $plain . ')');
tap_t_assert(strpos($plain, '&') === false, 'plain_symbol has no entity ampersands');
tap_t_assert(TAP_Currency::plain_symbol('CRC') === '¢', 'plain_symbol decodes cent sign');

/* 5) amount formatting -------------------------------------------------------- */
tap_t_assert(TAP_Currency::fmt(1234.56, 2) === TAP_Currency::symbol() . ' 1,234.56', 'fmt() uses symbol + thousands separator');
tap_t_assert(TAP_Currency::fmt0(1234.56) === TAP_Currency::symbol() . ' 1,235', 'fmt0() rounds without decimals');
tap_t_assert(TAP_Currency::fmt(0.5, 2) === TAP_Currency::symbol() . ' 0.50', 'fmt() keeps two decimals for small amounts');

/* 6) option roundtrip (JPY zeroes decimals end-to-end) ------------------------ */
update_option('tap_currency', 'JPY');
tap_t_assert(TAP_Currency::code() === 'JPY', 'code() follows updated option');
tap_t_assert(TAP_Currency::fmt(1500) === '&yen; 1,500', 'JPY fmt uses yen symbol + 0 decimals (got "' . TAP_Currency::fmt(1500) . '")');
tap_t_assert(TAP_Currency::decimals() === 0, 'decimals() follows active code');

if ($orig_set) {
    update_option('tap_currency', $orig);
} else {
    delete_option('tap_currency');
}
tap_t_assert(TAP_Currency::code() === strtoupper((string) ($orig === false ? 'USD' : $orig)), 'tap_currency option restored');

tap_t_finish();
