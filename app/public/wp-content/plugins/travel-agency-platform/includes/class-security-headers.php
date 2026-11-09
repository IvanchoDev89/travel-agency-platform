<?php
defined('ABSPATH') || exit;

/**
 * Security headers (HSTS, CSP) with safe defaults.
 */
class TAP_Security_Headers {

    /** Bump when the shipped default policy gains directives. */
    const POLICY_VERSION = 2;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        self::maybe_upgrade_policy();
    }

    /**
     * Migrate a stored policy that is a previous shipped default so new
     * directives (e.g. manifest-src) reach existing installs. Custom policies
     * that never had the old worker-src directive are left untouched.
     */
    public static function maybe_upgrade_policy() {
        if ((int) get_option('tap_csp_policy_version', 0) >= self::POLICY_VERSION) {
            return;
        }
        $stored = (string) get_option('tap_csp_policy', '');
        if ('' !== $stored && false === strpos($stored, 'manifest-src')) {
            $upgraded = str_replace(
                "worker-src 'self' blob:;",
                "worker-src 'self' blob:; manifest-src 'self';",
                $stored
            );
            if ($upgraded !== $stored) {
                update_option('tap_csp_policy', $upgraded);
            }
        }
        update_option('tap_csp_policy_version', self::POLICY_VERSION, false);
    }

    public static function register_rest() {
        register_rest_route('tap/v1', '/csp-report', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_csp_report'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_csp_report($request) {
        // Throttle the unauthenticated endpoint so a flood cannot fill the log.
        if (class_exists('TAP_Rate_Limit') && TAP_Rate_Limit::too_many('csp_report', 60, 60)) {
            return new WP_REST_Response(null, 429);
        }

        // Only accept the content types browsers use for CSP reports.
        $ct = $request->get_content_type();
        $content_type = is_array($ct) ? strtolower((string) ($ct['value'] ?? '')) : '';
        $allowed_types = ['application/csp-report', 'application/reports+json', 'application/json'];
        if ('' !== $content_type && !in_array($content_type, $allowed_types, true)) {
            return new WP_REST_Response(null, 415);
        }

        $body = $request->get_body();
        if ($body) {
            // Sanitize: strip control chars and truncate.
            $body = preg_replace('/[\r\n\t\x00-\x1F\x7F]/', ' ', $body);
            $body = substr((string) $body, 0, 2000);
            error_log('CSP Violation: ' . $body);

            // Daily counter for monitoring (option read is cached).
            $today = gmdate('Y-m-d');
            $stats = get_option('tap_csp_report_stats', []);
            if (!is_array($stats) || ($stats['date'] ?? '') !== $today) {
                $stats = ['date' => $today, 'count' => 0];
            }
            $stats['count'] = (int) $stats['count'] + 1;
            update_option('tap_csp_report_stats', $stats, false);
        }
        return new WP_REST_Response(null, 204);
    }

    public static function default_policy() {
        $report_url = rest_url('tap/v1/csp-report');
        return "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.paypal.com https://www.sandbox.paypal.com https://www.paypalobjects.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://api.paypal.com https://api.sandbox.paypal.com; frame-src 'self' https://www.paypal.com https://www.sandbox.paypal.com; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; report-uri {$report_url};";
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
        $stats = get_option('tap_csp_report_stats', []);
        $reports_today = is_array($stats) && ($stats['date'] ?? '') === gmdate('Y-m-d') ? (int) ($stats['count'] ?? 0) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Seguridad HTTP (HSTS / CSP)', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Configura headers de seguridad. Empieza en modo "Solo reporte" para no romper el sitio.', 'travel-agency-platform'); ?></p>
            <p><strong><?php esc_html_e('Reportes CSP recibidos hoy:', 'travel-agency-platform'); ?></strong> <?php echo (int) $reports_today; ?></p>
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