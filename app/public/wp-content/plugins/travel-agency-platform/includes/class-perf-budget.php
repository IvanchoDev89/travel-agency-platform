<?php
defined('ABSPATH') || exit;

/**
 * Lightweight performance budget for front-end dashboards.
 *
 * Measures queries, wall time and response size only on dashboard pages and
 * records a bounded snapshot. No data is sent anywhere; admins get an early
 * warning before a slow panel reaches production.
 */
class TAP_Perf_Budget {

    const MAX_QUERIES = 60;
    const MAX_SECONDS = 2.0;

    private static $start = 0;
    private static $queries = 0;

    public static function init() {
        add_action('wp', [__CLASS__, 'start']);
        add_action('shutdown', [__CLASS__, 'finish']);
    }

    public static function is_dashboard_request() {
        if (!is_user_logged_in()) { return false; }
        if (isset($_GET['seccion'])) { return true; } // phpcs:ignore WordPress.Security.NonceVerification
        return is_page('mi-cuenta') || is_page('dashboard');
    }

    public static function start() {
        if (!self::is_dashboard_request()) { return; }
        global $wpdb;
        self::$start = microtime(true);
        self::$queries = (int) $wpdb->num_queries;
    }

    public static function finish() {
        if (0 === self::$start || !self::is_dashboard_request()) { return; }
        global $wpdb;
        $elapsed = microtime(true) - self::$start;
        $queries = (int) $wpdb->num_queries - self::$queries;
        $snapshot = [
            'queries'  => $queries,
            'seconds'  => round($elapsed, 3),
            'peak_mb'  => round(memory_get_peak_usage(true) / 1048576, 1),
            'section'  => sanitize_key(isset($_GET['seccion']) ? wp_unslash($_GET['seccion']) : 'overview'), // phpcs:ignore WordPress.Security.NonceVerification
            'recorded_at' => current_time('mysql'),
        ];
        set_transient('tap_dash_perf', $snapshot, DAY_IN_SECONDS);
    }

    public static function snapshot() {
        $value = get_transient('tap_dash_perf');
        return is_array($value) ? $value : [];
    }

    public static function issues() {
        $s = self::snapshot();
        if (!$s) { return []; }
        $out = [];
        if ((int) ($s['queries'] ?? 0) > self::MAX_QUERIES) {
            $out[] = [
                'label'  => __('Panel por encima del presupuesto de consultas', 'travel-agency-platform'),
                'detail' => sprintf(/* translators: 1: consultas, 2: límite */ __('%1$d consultas en la sección %2$s (límite %3$d).', 'travel-agency-platform'), (int) $s['queries'], (string) ($s['section'] ?? ''), self::MAX_QUERIES),
            ];
        }
        if ((float) ($s['seconds'] ?? 0) > self::MAX_SECONDS) {
            $out[] = [
                'label'  => __('Panel por encima del presupuesto de tiempo', 'travel-agency-platform'),
                'detail' => sprintf(/* translators: 1: segundos, 2: sección */ __('%1$s s en %2$s (límite %3$s s).', 'travel-agency-platform'), (string) $s['seconds'], (string) ($s['section'] ?? ''), (string) self::MAX_SECONDS),
            ];
        }
        return $out;
    }
}
