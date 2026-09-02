<?php
/**
 * suite_views.php — listing views tracking (F-AN).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_views.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
tap_t_clear_views();

$tour = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
if (!$tour) {
    echo "SKIP no tour to track issues run\n";
    tap_t_finish();
}
$tid = (int) $tour[0];

// record_view() requires a singular WP_Query context; simulate one.
query_posts(['post_type' => 'tap_tour', 'p' => $tid]);

// 1) record_view writes a daily upsert row
delete_transient('tap_view_' . get_current_user_id() . '_' . $tid);
TAP_Analytics::record_view();
$row = $wpdb->get_row($wpdb->prepare("SELECT views FROM " . TAP_Analytics::views_table() . " WHERE listing_id = %d", $tid));
tap_t_assert($row && (int) $row->views >= 1, 'record_view creates listing_views row');

// 2) transient throttle: immediate second call does not double count
$v1 = (int) $row->views;
TAP_Analytics::record_view();
$v2 = (int) $wpdb->get_var($wpdb->prepare("SELECT views FROM " . TAP_Analytics::views_table() . " WHERE listing_id = %d", $tid));
tap_t_assert($v2 === $v1, '5-min transient throttle prevents double count (' . $v1 . ' -> ' . $v2 . ')');

// 3) totals
$tot = TAP_Analytics::total_views(date('Y-m'), date('Y-m'));
tap_t_assert($tot >= $v2, 'total_views aggregates current month');

$byListing = TAP_Analytics::listing_views();
$found = false;
foreach ((array) $byListing as $lv) {
    if ((int) $lv->listing_id === $tid) { $found = true; break; }
}
tap_t_assert($found, 'listing_views() returns per-listing aggregation containing tracking id');

tap_t_clear_views($tid);
tap_t_finish();