<?php
defined('ABSPATH') || exit;

class TAP_Analytics {
    public static function init() {
        add_action('template_redirect', [__CLASS__, 'record_view']);
    }

    public static function views_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_listing_views';
    }

    public static function record_view() {
        if (is_admin() || !is_singular()) return;
        $post_id = get_queried_object_id();
        $type = get_post_type($post_id);
        if (TAP_Promotions::keys_for_type($type) === null) return;

        $bucket = 'tap_view_' . get_current_user_id() . '_' . $post_id;
        if (get_transient($bucket)) return;
        set_transient($bucket, 1, 5 * MINUTE_IN_SECONDS);

        $write = function () use ($post_id, $type) {
            global $wpdb;
            $table = self::views_table();
            $date  = current_time('Y-m-d');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (listing_id, service_type, view_date, views)
                 VALUES (%d, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE views = views + 1
                 /* listing_date unique key guards the atomic increment */",
                (int) $post_id, $type, $date
            ));
            do_action('tap_listing_view', $post_id);
        };

        if (defined('WP_CLI') && WP_CLI) {
            // No HTTP response is being served (CLI/cron-style run), so the
            // counter write cannot slow a page render and is applied now.
            $write();
            return;
        }

        // Non-blocking: the counter write is deferred to PHP shutdown, after
        // the response has already been flushed, so a busy listing page never
        // waits on the stats INSERT/UPDATE.
        add_action('shutdown', $write);
    }

    public static function total_views($from = '', $to = '') {
        global $wpdb;
        $where = self::date_where($from, $to);
        return (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(views),0) FROM " . self::views_table() . " WHERE {$where}"
        );
    }

    public static function listing_views($from = '', $to = '') {
        global $wpdb;
        $where = self::date_where($from, $to);
        $rows = $wpdb->get_results(
            "SELECT listing_id, COALESCE(SUM(views),0) views
             FROM " . self::views_table() . " WHERE {$where}
             GROUP BY listing_id
             LIMIT 1000",
            OBJECT_K
        );
        return $rows ?: [];
    }

    /**
     * Booking funnel for the given month range (inclusive, YYYY-MM).
     *
     * Stages are derived from existing tables — no extra event tracking:
     * listing views → bookings created → bookings confirmed → bookings paid.
     * Each stage carries the step conversion vs. the previous one.
     *
     * @return array<int,array{key:string,label:string,value:int,rate:float}>
     */
    public static function booking_funnel($from = '', $to = '') {
        global $wpdb;
        $f = $from && preg_match('/^\d{4}-\d{2}$/', $from) ? $from : '';
        $t = $to && preg_match('/^\d{4}-\d{2}$/', $to) ? $to : '';
        $where = ['1=1'];
        if ($f) $where[] = $wpdb->prepare("DATE_FORMAT(created_at, '%%Y-%%m') >= %s", $f);
        if ($t) $where[] = $wpdb->prepare("DATE_FORMAT(created_at, '%%Y-%%m') <= %s", $t);
        $w = implode(' AND ', $where);
        $b = $wpdb->prefix . 'tap_bookings';

        $views     = (int) self::total_views($from, $to);
        $created   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$b} WHERE {$w}");
        $confirmed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$b} WHERE {$w} AND status IN ('confirmed','completed')");
        $paid      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$b} WHERE {$w} AND payment_status = 'paid'");

        $stages = [
            ['key' => 'views',     'label' => __('Vistas de listados', 'travel-agency-platform'),    'value' => $views],
            ['key' => 'created',   'label' => __('Reservas creadas', 'travel-agency-platform'),      'value' => $created],
            ['key' => 'confirmed', 'label' => __('Reservas confirmadas', 'travel-agency-platform'),  'value' => $confirmed],
            ['key' => 'paid',      'label' => __('Reservas pagadas', 'travel-agency-platform'),      'value' => $paid],
        ];
        $prev = 0;
        foreach ($stages as $i => &$s) {
            $s['rate'] = ($i > 0 && $prev > 0) ? round($s['value'] / $prev * 100, 1) : 0.0;
            $prev = $s['value'];
        }
        unset($s);
        return $stages;
    }

    private static function date_where($from, $to) {
        global $wpdb;
        if ($from && !preg_match('/^\d{4}-\d{2}$/', $from)) $from = '';
        if ($to && !preg_match('/^\d{4}-\d{2}$/', $to)) $to = '';
        $where = ['1=1'];
        if ($from) $where[] = $wpdb->prepare("DATE_FORMAT(view_date, '%%Y-%%m') >= %s", $from);
        if ($to)   $where[] = $wpdb->prepare("DATE_FORMAT(view_date, '%%Y-%%m') <= %s", $to);
        return implode(' AND ', $where);
    }
}