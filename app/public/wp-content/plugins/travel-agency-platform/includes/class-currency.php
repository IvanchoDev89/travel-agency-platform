<?php
/**
 * Global currency handling for the travel platform.
 * The `tap_currency` setting stores an ISO code; this maps it to a symbol
 * and provides consistent amount formatting across the front and back end.
 */
class TAP_Currency {

    protected static $map = [
        'USD' => '$',
        'EUR' => '&euro;',
        'GBP' => '&pound;',
        'CRC' => '&cent;',
        'NIO' => 'C$',
        'MXN' => '$',
        'PEN' => 'S/',
        'COP' => '$',
        'CLP' => '$',
        'ARS' => '$',
        'BRL' => 'R$',
        'GTQ' => 'Q',
        'HNL' => 'L',
        'DOP' => 'RD$',
        'CUP' => '$',
        'BOB' => 'Bs',
        'UYU' => '$',
        'JPY' => '&yen;',
    ];

    protected static $decimals_map = [
        'JPY' => 0,
    ];

    public static function code() {
        return strtoupper(get_option('tap_currency', 'USD'));
    }

    public static function codes() {
        return array_keys(self::$map);
    }

    public static function decimals($code = null) {
        $code = $code ?: self::code();
        return self::$decimals_map[$code] ?? 2;
    }

    public static function symbol($code = null) {
        $code = $code ?: self::code();
        if (isset(self::$map[$code])) {
            return self::$map[$code];
        }
        return $code;
    }

    public static function fmt($amount, $decimals = null) {
        $decimals = $decimals === null ? self::decimals() : $decimals;
        return self::symbol() . ' ' . number_format((float) $amount, $decimals);
    }

    public static function fmt0($amount) {
        return self::fmt($amount, 0);
    }
}