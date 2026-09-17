<?php
require __DIR__ . '/bootstrap.php';

global $wpdb, $wp_query;
$view_post_id = 0;
$view_bucket = '';
$original_query = $wp_query;
$view_cleanup = function () use (&$view_post_id, &$view_bucket, $original_query) {
    global $wpdb, $wp_query;
    $wp_query = $original_query;
    if ($view_bucket !== '') {
        delete_transient($view_bucket);
        $view_bucket = '';
    }
    if ($view_post_id > 0) {
        $deleted = $wpdb->delete(TAP_Analytics::views_table(), ['listing_id' => $view_post_id]);
        if ($deleted === false) {
            tap_t_fail('owned view rows could not be cleaned up');
        }
        if (!wp_delete_post($view_post_id, true)) {
            tap_t_fail('owned tour could not be cleaned up');
        }
        $view_post_id = 0;
    }
};
register_shutdown_function($view_cleanup);

try {
    $post_id = wp_insert_post([
        'post_type' => 'tap_tour',
        'post_status' => 'publish',
        'post_title' => 'Views E2E ' . tap_t_suffix(),
    ], true);
    if (is_wp_error($post_id) || !$post_id) {
        throw new RuntimeException('Could not create the owned tour fixture');
    }
    $view_post_id = (int) $post_id;
    $view_bucket = 'tap_view_' . get_current_user_id() . '_' . $view_post_id;
    $wp_query = new WP_Query();
    $wp_query->queried_object = get_post($view_post_id);
    $wp_query->queried_object_id = $view_post_id;
    $wp_query->is_single = true;
    $wp_query->is_singular = true;

    TAP_Analytics::record_view();
    $views = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT SUM(views) FROM ' . TAP_Analytics::views_table() . ' WHERE listing_id = %d',
        $view_post_id
    ));
    tap_t_assert($views === 1, 'record_view creates exactly one owned view');
    TAP_Analytics::record_view();
    $repeated_views = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT SUM(views) FROM ' . TAP_Analytics::views_table() . ' WHERE listing_id = %d',
        $view_post_id
    ));
    tap_t_assert($repeated_views === 1, 'transient throttle prevents a duplicate view');
    $month = current_time('Y-m');
    tap_t_assert(TAP_Analytics::total_views($month, $month) >= 1, 'total_views includes the owned view');
    $by_listing = TAP_Analytics::listing_views($month, $month);
    tap_t_assert(isset($by_listing[$view_post_id]) && (int) $by_listing[$view_post_id]->views === 1, 'listing_views includes the owned listing');
} catch (Throwable $error) {
    tap_t_fail('views suite failed: ' . $error->getMessage());
} finally {
    $view_cleanup();
}

tap_t_finish();
