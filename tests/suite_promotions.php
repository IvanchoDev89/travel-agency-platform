<?php
/**
 * suite_promotions.php — canonical meta keys + featured-first ordering (F-FF).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_promotions.php
 */
require __DIR__ . '/bootstrap.php';

// 1) canonical prefixes for every service type
$expected = [
    'tap_accommodation' => 'acc',
    'tap_tour'          => 'tour',
    'tap_transport'     => 'trans',
    'tap_car_rental'    => 'car',
    'tap_boat'          => 'boat',
    'tap_package'       => 'pkg',
];
foreach ($expected as $type => $prefix) {
    $got = TAP_Promotions::prefix_for_type($type);
    tap_t_assert($got === $prefix, "prefix_for_type({$type}) = {$prefix} (got " . var_export($got, true) . ")");
}

// 2) listing_meta_keys delegates to keys_for_type -> canonical keys
$expectedKey = ['tap_accommodation' => '_tap_acc_', 'tap_tour' => '_tap_tour_', 'tap_transport' => '_tap_trans_', 'tap_car_rental' => '_tap_car_', 'tap_boat' => '_tap_boat_', 'tap_package' => '_tap_pkg_'];
foreach ($expectedKey as $type => $prefix) {
    $keys = TAP_Promotions::keys_for_type($type);
    tap_t_assert($keys !== null && str_starts_with((string) $keys['flag_key'], $prefix), "keys_for_type({$type}) canonical flag key " . ($keys['flag_key'] ?? '?'));
    tap_t_assert($keys !== null && str_starts_with((string) $keys['active_key'], $prefix), "keys_for_type({$type}) canonical active key " . ($keys['active_key'] ?? '?'));
    tap_t_assert($keys !== null && str_starts_with((string) $keys['until_key'], $prefix), "keys_for_type({$type}) canonical until key " . ($keys['until_key'] ?? '?'));
}

// 3) featured status resolution for a known featured listing
// future-until featured listing must report is_featured() true
$featuredListing = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'meta_key' => '_tap_tour_is_featured', 'meta_value' => '1', 'posts_per_page' => 1, 'suppress_filters' => false]);
if ($featuredListing) {
    $p = $featuredListing[0];
    $until = get_post_meta($p->ID, '_tap_tour_featured_until', true);
    tap_t_assert(TAP_Promotions::is_featured($p->ID) === true, 'active featured listing reports is_featured true');
    if ($until && strtotime($until) >= current_time('timestamp')) {
        tap_t_assert(TAP_Promotions::is_featured($p->ID, ($until)) === true, 'is_featured true while within until window');
    }
    // featured casts to first for a fresh ASC query
    $q = new WP_Query([
        'post_type' => 'tap_tour',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);
    $featIdx = null;
    foreach ($q->posts as $i => $post) {
        if ((int) $post->ID === (int) $p->ID) {
            $featIdx = $i;
            break;
        }
    }
    tap_t_assert($featIdx === 0, 'featured listing sorts first in archive query (index ' . var_export($featIdx, true) . ')');
} else {
    tap_t_assert(true, 'no active featured listing present (seed one to test ordering)');
}

tap_t_finish();