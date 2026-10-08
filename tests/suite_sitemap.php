<?php
/**
 * suite_sitemap.php — TAP_Sitemap: WP core sitemap providers for service post
 * types/taxonomies, pending-agency listings excluded from the index, and the
 * robots.txt Sitemap line.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_sitemap.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

/* Fixtures FIRST: excluded_listing_ids() caches per request, so build the
 * pending-agency listing before the first exclusion read. */
$pending_agency = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'TAP E2E Sitemap Agency ' . tap_t_suffix(),
]);
update_post_meta($pending_agency, TAP_Approval::STATUS_META, TAP_Approval::PENDING);

$hidden_listing = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'TAP E2E Hidden Tour ' . tap_t_suffix(),
]);
update_post_meta($hidden_listing, '_tap_tour_agency_id', (int) $pending_agency);

$visible_listing = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'TAP E2E Visible Tour ' . tap_t_suffix(),
]);

/* 1) wiring ------------------------------------------------------------------ */
tap_t_assert((bool) has_filter('wp_sitemaps_post_types', ['TAP_Sitemap', 'post_types']), 'wp_sitemaps_post_types filter registered');
tap_t_assert((bool) has_filter('wp_sitemaps_taxonomies', ['TAP_Sitemap', 'taxonomies']), 'wp_sitemaps_taxonomies filter registered');
tap_t_assert((bool) has_filter('wp_sitemaps_posts_query_args', ['TAP_Sitemap', 'posts_query_args']), 'wp_sitemaps_posts_query_args filter registered');
tap_t_assert((bool) has_action('do_robots', ['TAP_Sitemap', 'robots_sitemap_line']), 'robots.txt hook registered');

/* 2) providers include every public service type ----------------------------- */
$pt_out = TAP_Sitemap::post_types([]);
foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'] as $pt) {
    $obj = get_post_type_object($pt);
    tap_t_assert($obj && isset($pt_out[$pt]), "sitemap provider includes {$pt}");
}
tap_t_assert(!isset($pt_out['tap_room']), 'sitemap provider excludes private tap_room');
tap_t_assert(!isset($pt_out['tap_agency']), 'sitemap provider excludes tap_agency');

$existing = ['tap_tour' => get_post_type_object('tap_tour')];
$again = TAP_Sitemap::post_types($existing);
tap_t_assert(isset($again['tap_tour']) && count($again) >= count($existing), 'post_types() merges without clobbering');

/* 3) taxonomy providers ------------------------------------------------------ */
$tax_out = TAP_Sitemap::taxonomies([]);
foreach (['tap_location', 'tap_service_cat', 'tap_property_type', 'tap_amenity', 'tap_tour_type'] as $tax) {
    tap_t_assert(isset($tax_out[$tax]), "sitemap taxonomy provider includes {$tax}");
}

/* 4) posts_query_args: pending-agency listings excluded, others untouched ----- */
$args = TAP_Sitemap::posts_query_args([], 'tap_tour');
$not_in = array_map('intval', (array) ($args['post__not_in'] ?? []));
tap_t_assert(in_array((int) $hidden_listing, $not_in, true), 'listing of pending agency lands in post__not_in');
tap_t_assert(!in_array((int) $visible_listing, $not_in, true), 'listing without pending agency stays indexable');

$other = ['post_type' => 'page', 'posts_per_page' => 5];
$pass = TAP_Sitemap::posts_query_args($other, 'page');
tap_t_assert($pass === $other, 'non-service post types pass through untouched');

/* 5) robots.txt line --------------------------------------------------------- */
ob_start();
TAP_Sitemap::robots_sitemap_line();
$robots = ob_get_clean();
tap_t_assert(strpos($robots, 'Sitemap:') !== false, 'robots output starts a Sitemap line');
tap_t_assert(strpos($robots, home_url('/wp-sitemap.xml')) !== false, 'robots line points at wp-sitemap.xml');

/* cleanup -------------------------------------------------------------------- */
wp_delete_post((int) $hidden_listing, true);
wp_delete_post((int) $visible_listing, true);
wp_delete_post((int) $pending_agency, true);

tap_t_finish();
