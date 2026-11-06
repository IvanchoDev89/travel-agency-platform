<?php
defined('ABSPATH') || exit;

/**
 * Security headers (HSTS, CSP) with safe defaults.
 */
class TAP_Security_Headers {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
    }

    public static function register_rest() {
        register_rest_route('tap/v1', '/csp-report', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_csp_report'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_csp_report($request) {
        $body = $request->get_body();
        if ($body) {
            error_log('CSP Violation: ' . $body);
        }
        return new WP_REST_Response(null, 204);
    }

    public static function default_policy() {
        $report_url = rest_url('tap/v1/csp-report');
        return "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.paypal.com https://www.sandbox.paypal.com https://www.paypalobjects.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://api.paypal.com https://api.sandbox.paypal.com; frame-src 'self' https://www.paypal.com https://www.sandbox.paypal.com; object-src 'none'; base-uri 'self'; report-uri {$report_url};";
    }

    public static function mode() {
        $mode = get_option('tap_csp_mode', 'report-only');
        return in_array($mode, ['disabled', 'report-only', 'enforce'], true) ? $mode : 'report-only';
    }

    public static function policy() {
        $p = get_option('tap_csp_policy', '');
        if ('' === trim((string) $p)) {
            $p = self::default_policy();
        }
        return $p;
    }

    public static function send() {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // HSTS: only over HTTPS.
        if (is_ssl()) {
            $hsts = (bool) get_option('tap_hsts_enabled', true);
            if ($hsts) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
        $mode = self::mode();
        if ('disabled' === $mode) {
            return;
        }
        $policy = self::policy();
        $header = 'report-only' === $mode ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        header($header . ': ' . $policy);
    }

    public static function add_page() {
        add_options_page(
            __('Seguridad HTTP', 'travel-agency-platform'),
            __('Seguridad HTTP', 'travel-agency-platform'),
            'manage_options',
            'tap-security-headers',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('tap_security_group', 'tap_hsts_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => [__CLASS__, 'sanitize_bool'],
            'default' => true,
        ]);
        register_setting('tap_security_group', 'tap_csp_mode', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_mode'],
            'default' => 'report-only',
        ]);
        register_setting('tap_security_group', 'tap_csp_policy', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => self::default_policy(),
        ]);
    }

    public static function sanitize_bool($v) {
        return !empty($v) ? 1 : 0;
    }

    public static function sanitize_mode($v) {
        $v = sanitize_key($v);
        return in_array($v, ['disabled', 'report-only', 'enforce'], true) ? $v : 'report-only';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $hsts = (bool) get_option('tap_hsts_enabled', true);
        $mode = self::mode();
        $policy = self::policy();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Seguridad HTTP (HSTS / CSP)', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Configura headers de seguridad. Empieza en modo "Solo reporte" para no romper el sitio.', 'travel-agency-platform'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('tap_security_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('HSTS (solo HTTPS)', 'travel-agency-platform'); ?></th>
                        <td>
                            <label><input type="checkbox" name="tap_hsts_enabled" value="1" <?php checked($hsts); ?>> <?php esc_html_e('Activar HSTS', 'travel-agency-platform'); ?></label>
                            <p class="description"><?php esc_html_e('Solo se envía si el sitio está en HTTPS.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Modo CSP', 'travel-agency-platform'); ?></th>
                        <td>
                            <select name="tap_csp_mode">
                                <option value="disabled" <?php selected($mode, 'disabled'); ?>><?php esc_html_e('Desactivado', 'travel-agency-platform'); ?></option>
                                <option value="report-only" <?php selected($mode, 'report-only'); ?>><?php esc_html_e('Solo reporte (recomendado al inicio)', 'travel-agency-platform'); ?></option>
                                <option value="enforce" <?php selected($mode, 'enforce'); ?>><?php esc_html_e('Aplicar (puede romper el sitio)', 'travel-agency-platform'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Política CSP', 'travel-agency-platform'); ?></th>
                        <td>
                            <textarea name="tap_csp_policy" rows="6" class="large-text code"><?php echo esc_textarea($policy); ?></textarea>
                            <p class="description"><?php esc_html_e('Lista blanca de recursos. Ajusta según tus CDNs.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}