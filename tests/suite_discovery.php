<?php
/**
 * suite_discovery.php — public directory, map cache and featured guards.
 * FUNCTIONAL: real REST output and real hook registration.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_discovery.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$cleanup_posts = [];
$plugin = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins/travel-agency-platform/';

/* --- 1) REST agencies includes legacy approved agencies ---------------------- */
$legacy = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'TAP legacy agency ' . $sfx,
]);
if ((int) $legacy > 0) {
    $cleanup_posts[] = (int) $legacy;
    update_post_meta($legacy, '_tap_agency_verified', '1');
    update_post_meta($legacy, TAP_Approval::STATUS_META, TAP_Approval::APPROVED);
    // Deliberately DO NOT set ACTIVE_META: that was the bug.
    delete_post_meta($legacy, TAP_Approval::ACTIVE_META);

    if (!did_action('rest_api_init')) { do_action('rest_api_init'); }
    $resp = tap_t_rest('GET', '/tap/v1/agencies');
    $data = tap_t_rest_data($resp);
    $ids = array_map(static function ($a) { return (int) ($a['id'] ?? 0); }, (array) $data);
    tap_t_assert(in_array((int) $legacy, $ids, true), 'legacy agency without ACTIVE_META appears in REST directory');
}

/* --- 2) map cache purged on approval changes -------------------------------- */
tap_t_assert(false !== has_action('tap_agency_approved', ['TAP_Destination_Spots', 'clear_map_cache']), 'map cache purged when an agency is approved');
tap_t_assert(false !== has_action('tap_agency_rejected', ['TAP_Destination_Spots', 'clear_map_cache']), 'map cache purged when an agency is rejected');

/* --- 3) Leaflet CSS is enqueued before the shortcode renders ---------------- */
$main_src = (string) file_get_contents($plugin . 'travel-agency-platform.php');
$search_src = (string) file_get_contents($plugin . 'includes/class-shortcode-search.php');
tap_t_assert(false !== strpos($main_src, "strpos((string) \$post->post_content, 'tap_destinations_map')"), 'Leaflet CSS is enqueued in wp_enqueue_scripts');
tap_t_assert(false === strpos($search_src, "wp_enqueue_style('tap-leaflet'"), 'map shortcode no longer enqueues CSS too late');

/* --- 4) featured shortcode honours promotion expiry ------------------------- */
tap_t_assert(false !== strpos($search_src, 'TAP_Promotions::featured_ids($types)'), 'featured shortcode uses the expiry-aware promotion helper');

/* --- cleanup ----------------------------------------------------------------- */
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
tap_t_finish();
