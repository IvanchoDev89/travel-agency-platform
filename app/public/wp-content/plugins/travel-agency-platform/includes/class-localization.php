<?php
defined('ABSPATH') || exit;

/**
 * Multilingual front-end (Fase 3).
 *
 * Strategy: Spanish is the source language (msgids are Spanish), the default
 * public locale is es_ES, and an optional en_US translation file
 * (languages/travel-agency-platform-en_US.mo) switches the visitor UI to English.
 *
 * Language selection order: ?lang= param > tap_lang cookie > user meta > es.
 * The front-end locale filter keeps admin/WP-CLI environments untouched so the
 * test battery keeps asserting the Spanish source strings.
 */
class TAP_Localization {
    const DOMAIN = 'travel-agency-platform';

    /** code => WP locale. */
    protected static $langs = [
        'es' => 'es_ES',
        'en' => 'en_US',
    ];

    /** Forced language (used by set_lang mid-request). */
    protected static $forced = '';

    public static function init() {
        add_shortcode('tap_lang_switcher', [__CLASS__, 'switcher']);
        if (!empty($_GET['lang'])) {
            $lang = sanitize_key((string) $_GET['lang']);
            if (isset(self::$langs[$lang]) && !headers_sent()) {
                setcookie('tap_lang', $lang, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/');
            }
            if (isset(self::$langs[$lang]) && is_user_logged_in()) {
                update_user_meta(get_current_user_id(), 'tap_lang', $lang);
            }
        }
    }

    public static function supported_langs() {
        return self::$langs;
    }

    /** Resolve the effective language code for the current request. */
    public static function resolve_lang() {
        if (self::$forced !== '') {
            return self::$forced;
        }
        $lang = '';
        if (!empty($_GET['lang'])) {
            $candidate = sanitize_key((string) $_GET['lang']);
            if (isset(self::$langs[$candidate])) {
                $lang = $candidate;
            }
        }
        if ($lang === '' && !empty($_COOKIE['tap_lang'])) {
            $candidate = sanitize_key((string) $_COOKIE['tap_lang']);
            if (isset(self::$langs[$candidate])) {
                $lang = $candidate;
            }
        }
        if ($lang === '' && is_user_logged_in()) {
            $meta = get_user_meta(get_current_user_id(), 'tap_lang', true);
            if (is_string($meta) && isset(self::$langs[$meta])) {
                $lang = $meta;
            }
        }
        if ($lang === '') {
            $lang = 'es';
        }
        return $lang;
    }

    public static function current_lang() {
        return self::resolve_lang();
    }

    public static function wp_locale($lang = null) {
        $lang = $lang ?: self::resolve_lang();
        return isset(self::$langs[$lang]) ? self::$langs[$lang] : 'es_ES';
    }

    /** Front-end locale only; admin and AJAX keep WordPress' own locale. */
    public static function filter_locale($locale) {
        if (is_admin() && !wp_doing_ajax()) {
            return $locale;
        }
        return self::wp_locale();
    }

    public static function filter_determine_locale($locale) {
        if (is_admin() && !wp_doing_ajax()) {
            return $locale;
        }
        return self::wp_locale();
    }

    /**
     * Force a language for the remainder of the request (and persist it).
     *
     * @param string $lang 'es'|'en'
     * @return bool
     */
    public static function set_lang($lang) {
        if (!isset(self::$langs[$lang])) {
            return false;
        }
        self::$forced = $lang;
        $locale = self::$langs[$lang];
        if (!headers_sent()) {
            setcookie('tap_lang', $lang, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/');
        }
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'tap_lang', $lang);
        }
        if (function_exists('switch_to_locale')) {
            switch_to_locale($locale);
        }
        unload_textdomain(self::DOMAIN);
        load_textdomain(self::DOMAIN, TAP_PLUGIN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo');
        return true;
    }

    /** [tap_lang_switcher] — ES | EN links that preserve the current URL. */
    public static function switcher($atts = []) {
        $atts = shortcode_atts(['class' => 'tap-lang-switcher'], $atts, 'tap_lang_switcher');
        $current = self::resolve_lang();
        $langs = self::$langs;
        $html = '<div class="' . esc_attr($atts['class']) . '">';
        $glue = '';
        $current_uri = (empty($_SERVER['REQUEST_URI']) ? '/' : esc_url_raw((string) $_SERVER['REQUEST_URI']));
        foreach ($langs as $code => $locale) {
            $html .= $glue;
            $label = strtoupper((string) $code);
            $class = $code === $current ? ' is-active' : '';
            $url = add_query_arg('lang', $code, $current_uri);
            $html .= '<a class="tap-lang-link' . $class . '" href="' . esc_url($url) . '" hreflang="' . esc_attr($locale) . '" lang="' . esc_attr($locale) . '">' . esc_html($label) . '</a>';
            $glue = ' <span class="tap-lang-sep" aria-hidden="true">&middot;</span> ';
        }
        $html .= '</div>';
        return $html;
    }
}