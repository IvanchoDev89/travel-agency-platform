<?php
defined('ABSPATH') || exit;

/**
 * Multi-language content for services and destinations.
 * Stores translations in tap_translations (entity_type, entity_id, lang, field).
 */
class TAP_Translations {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_translations';
    }

    /** Supported languages (code => label). */
    public static function languages() {
        return ['es' => 'Español', 'en' => 'English'];
    }

    /** Default language. */
    public static function default_lang() {
        return 'es';
    }

    /** Current language (from query or cookie or site locale). */
    public static function current_lang() {
        if (!empty($_GET['lang'])) {
            $l = sanitize_key(wp_unslash($_GET['lang']));
            if (isset(self::languages()[$l])) return $l;
        }
        if (!empty($_COOKIE['tap_lang'])) {
            $l = sanitize_key(wp_unslash($_COOKIE['tap_lang']));
            if (isset(self::languages()[$l])) return $l;
        }
        $locale = get_locale();
        if (strpos($locale, 'en') === 0) return 'en';
        return 'es';
    }

    /** Set current language (cookie). */
    public static function set_lang($lang) {
        if (!isset(self::languages()[$lang])) return;
        setcookie('tap_lang', $lang, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
    }

    /** Get a translated field, fallback to default (es) then post field. */
    public static function get($entity_type, $entity_id, $field, $lang = null) {
        global $wpdb;
        $lang = $lang ?: self::current_lang();
        if ($lang === self::default_lang()) {
            // default lang uses post fields, not translations
            return null;
        }
        $t = self::table();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$t} WHERE entity_type = %s AND entity_id = %d AND lang = %s AND field = %s",
            $entity_type, (int) $entity_id, $lang, $field
        ));
        return $val !== null ? $val : null;
    }

    /** Save a translation. */
    public static function set($entity_type, $entity_id, $lang, $field, $value) {
        global $wpdb;
        $t = self::table();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE entity_type = %s AND entity_id = %d AND lang = %s AND field = %s",
            $entity_type, (int) $entity_id, $lang, $field
        ));
        if ($existing) {
            $wpdb->update($t, ['value' => $value], ['id' => (int) $existing], ['%s'], ['%d']);
        } else {
            $wpdb->insert($t, [
                'entity_type' => $entity_type,
                'entity_id'   => (int) $entity_id,
                'lang'        => $lang,
                'field'       => $field,
                'value'       => $value,
            ], ['%s', '%d', '%s', '%s', '%s']);
        }
    }

    /** Delete all translations for an entity. */
    public static function delete($entity_type, $entity_id) {
        global $wpdb;
        $t = self::table();
        $wpdb->delete($t, ['entity_type' => $entity_type, 'entity_id' => (int) $entity_id], ['%s', '%d']);
    }

    /** Get all translations for an entity in a language. */
    public static function all_for($entity_type, $entity_id, $lang) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field, value FROM {$t} WHERE entity_type = %s AND entity_id = %d AND lang = %s",
            $entity_type, (int) $entity_id, $lang
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            $out[$r->field] = $r->value;
        }
        return $out;
    }

    /** Render language selector. */
    public static function render_selector() {
        $current = self::current_lang();
        ob_start();
        ?>
        <select id="tap-lang-selector" onchange="tapSetLang(this.value)" style="padding:6px 10px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);background:#fff;font-size:var(--tap-text-sm);">
            <?php foreach (self::languages() as $code => $label): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($current, $code); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <script>
        function tapSetLang(code) {
            var u = new URL(window.location.href);
            u.searchParams.set('lang', code);
            window.location.href = u.toString();
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /** Init: set cookie if lang param present. */
    public static function init() {
        if (!empty($_GET['lang'])) {
            $l = sanitize_key(wp_unslash($_GET['lang']));
            if (isset(self::languages()[$l])) {
                self::set_lang($l);
            }
        }
    }
}
