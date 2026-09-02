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

        global $wpdb;
        $table = self::views_table();
        $date  = current_time('Y-m-d');

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE listing_id = %d AND view_date = %s",
            $post_id, $date
        ));
        if ($row) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET views = views + 1 WHERE id = %d",
                (int) $row
            ));
        } else {
            $wpdb->insert($table, [
                'listing_id'  => (int) $post_id,
                'service_type' => $type,
                'view_date'   => $date,
                'views'       => 1,
            ]);
        }
        do_action('tap_listing_view', $post_id);
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
            "SELECT listing_id, COALESCE(SUM(views),0) views FROM " . self::views_table() . " WHERE {$where} GROUP BY listing_id",
            OBJECT_K
        );
        return $rows ?: [];
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