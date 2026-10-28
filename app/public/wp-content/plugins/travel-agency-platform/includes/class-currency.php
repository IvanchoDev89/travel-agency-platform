<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
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

    /**
     * Symbol as plain UTF-8 text (no HTML entities), safe for json_encode and
     * javascript contexts such as the agency calendar.
     */
    public static function plain_symbol($code = null) {
        return html_entity_decode(self::symbol($code), ENT_QUOTES, 'UTF-8');
    }

    public static function fmt($amount, $decimals = null) {
        $decimals = $decimals === null ? self::decimals() : $decimals;
        return self::symbol() . ' ' . number_format((float) $amount, $decimals);
    }

    public static function fmt0($amount) {
        return self::fmt($amount, 0);
    }

    /** List active currencies from DB (fallback to hardcoded map). */
    public static function list_active() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT code, name, symbol, rate_to_usd, decimals FROM {$wpdb->prefix}tap_currencies WHERE is_active = 1 ORDER BY code");
        if (!$rows) {
            $out = [];
            foreach (array_keys(self::$map) as $code) {
                $out[] = (object) [
                    'code' => $code,
                    'name' => $code,
                    'symbol' => self::$map[$code],
                    'rate_to_usd' => 1.0,
                    'decimals' => self::decimals($code),
                ];
            }
            return $out;
        }
        return $rows;
    }

    /** Rate to USD for a currency code. */
    public static function rate($code = null) {
        $code = strtoupper($code ?: self::code());
        global $wpdb;
        $rate = $wpdb->get_var($wpdb->prepare("SELECT rate_to_usd FROM {$wpdb->prefix}tap_currencies WHERE code = %s", $code));
        if ($rate === null) {
            return 1.0;
        }
        return (float) $rate;
    }

    /** Convert an amount from one currency to another. */
    public static function convert($amount, $from, $to) {
        $from = strtoupper($from);
        $to   = strtoupper($to);
        if ($from === $to) return (float) $amount;
        $usd = (float) $amount * self::rate($from);
        if (self::rate($to) <= 0) return $usd;
        return $usd / self::rate($to);
    }

    /** User's preferred currency (from user meta or cookie). */
    public static function user_currency() {
        if (is_user_logged_in()) {
            $c = get_user_meta(get_current_user_id(), 'tap_preferred_currency', true);
            if ($c) return strtoupper($c);
        }
        if (!empty($_COOKIE['tap_currency'])) {
            return strtoupper(sanitize_text_field(wp_unslash($_COOKIE['tap_currency'])));
        }
        return self::code();
    }

    /** Set user's preferred currency. */
    public static function set_user_currency($code) {
        $code = strtoupper(sanitize_text_field($code));
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'tap_preferred_currency', $code);
        }
        setcookie('tap_currency', $code, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
    }

    /** Format amount in a given currency. */
    public static function fmt_in($amount, $code, $decimals = null) {
        $code = strtoupper($code);
        $decimals = $decimals === null ? self::decimals($code) : $decimals;
        // find symbol
        $symbol = $code;
        foreach (self::list_active() as $c) {
            if ($c->code === $code) { $symbol = $c->symbol;
				break; }
        }
        return $symbol . ' ' . number_format((float) $amount, $decimals);
    }

    /** Format amount converted to user's currency. */
    public static function fmt_user($amount_usd, $decimals = null) {
        $code = self::user_currency();
        $converted = self::convert($amount_usd, 'USD', $code);
        return self::fmt_in($converted, $code, $decimals);
    }

    /** Render a currency selector dropdown (shortcode). */
    public static function render_selector($atts = []) {
        $currencies = self::list_active();
        $current = self::user_currency();
        ob_start();
        ?>
        <select id="tap-currency-selector" onchange="tapSetCurrency(this.value)" style="padding:6px 10px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);background:#fff;font-size:var(--tap-text-sm);">
            <?php foreach ($currencies as $c): ?>
                <option value="<?php echo esc_attr($c->code); ?>" <?php selected($current, $c->code); ?>><?php echo esc_html($c->code . ' ' . $c->symbol); ?></option>
            <?php endforeach; ?>
        </select>
        <script>
        function tapSetCurrency(code) {
            var fd = new FormData();
            fd.append('action', 'tap_set_currency');
            fd.append('currency', code);
            fd.append('nonce', (window.tapDash && window.tapDash.nonce) || (window.tapAgencyWizard && window.tapAgencyWizard.listingNonce) || '');
            fetch((window.tapDash && window.tapDash.ajax_url) || (window.ajaxurl || '/wp-admin/admin-ajax.php'), {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(){ window.location.reload(); });
        }
        </script>
        <?php
        return ob_get_clean();
    }
}
