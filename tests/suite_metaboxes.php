<?php
/**
 * suite_metaboxes.php — TAP_Metaboxes: field registry per post type and the
 * save() pipeline (nonce gate, capability gate, sanitization, checkbox
 * defaults, destination validation).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_metaboxes.php
 */
require __DIR__ . '/bootstrap.php';

/* 1) field registry (lazily populated, front-end safe) ----------------------- */
$acc = TAP_Metaboxes::get_fields('tap_accommodation');
tap_t_assert(is_array($acc) && count($acc) > 10, 'accommodation registry populated (' . count($acc) . ' fields)');
foreach (['_tap_acc_agency_id', '_tap_acc_price_per_night', '_tap_acc_capacity', '_tap_seo_title'] as $key) {
    tap_t_assert(isset($acc[$key]), "accommodation registry has {$key}");
}
tap_t_assert(isset($acc['_tap_seo_title']) && $acc['_tap_seo_title']['type'] === 'text', 'seo title field is a text input');

$tour = TAP_Metaboxes::get_fields('tap_tour');
tap_t_assert(isset($tour['_tap_tour_duration']) && isset($tour['_tap_tour_difficulty']), 'tour registry has duration + difficulty');
tap_t_assert($tour['_tap_tour_difficulty']['type'] === 'select', 'difficulty is a select');
tap_t_assert(array_key_exists('hard', $tour['_tap_tour_difficulty']['options']), 'difficulty options include hard');

$agency = TAP_Metaboxes::get_fields('tap_agency');
tap_t_assert(isset($agency['_tap_agency_verified']) && $agency['_tap_agency_verified']['type'] === 'checkbox', 'agency registry has a checkbox field');

tap_t_assert(TAP_Metaboxes::get_fields('page') === [], 'unknown post type yields empty registry');

/* 2) register() is idempotent ------------------------------------------------ */
$before = TAP_Metaboxes::get_fields('tap_accommodation');
TAP_Metaboxes::register();
$after = TAP_Metaboxes::get_fields('tap_accommodation');
tap_t_assert($after === $before, 'repeat register() does not disturb the registry');

/* 3) fixtures ---------------------------------------------------------------- */
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$admin_id = $admins ? (int) $admins[0] : 0;
tap_t_assert($admin_id > 0, 'administrator user available');
wp_set_current_user($admin_id);

// Drafts: map_meta_cap only needs edit_tap_tours for an own draft, which the
// administrator catalog grants (edit_published_tap_tours is not granted).
$tour_id = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'draft',
    'post_title'  => 'TAP E2E Metabox Tour ' . tap_t_suffix(),
    'post_author' => $admin_id,
]);
$agency_id = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'draft',
    'post_title'  => 'TAP E2E Metabox Agency ' . tap_t_suffix(),
    'post_author' => $admin_id,
]);
tap_t_assert($tour_id > 0 && $agency_id > 0, 'draft fixtures created');

/* 4) save() without a nonce is a no-op --------------------------------------- */
$_POST = ['_tap_tour_duration' => 'Should not be saved'];
TAP_Metaboxes::save($tour_id, get_post($tour_id));
tap_t_assert(get_post_meta($tour_id, '_tap_tour_duration', true) === '', 'save() without nonce writes nothing');

/* 5) save() with a tampered nonce is a no-op --------------------------------- */
$_POST = ['tap_metabox_nonce' => 'deadbeefcafe', '_tap_tour_duration' => 'Nope'];
TAP_Metaboxes::save($tour_id, get_post($tour_id));
tap_t_assert(get_post_meta($tour_id, '_tap_tour_duration', true) === '', 'save() with invalid nonce writes nothing');

/* 6) save() with a valid nonce persists sanitized values ---------------------- */
$_POST = [
    'tap_metabox_nonce' => wp_create_nonce('tap_metabox_save'),
    '_tap_tour_duration'=> ' 3 days / 2 nights ',
    '_tap_tour_price_adult' => '120.5',
    'tap_destination'   => 999999999, // invalid term → must not assign
];
TAP_Metaboxes::save($tour_id, get_post($tour_id));
tap_t_assert(get_post_meta($tour_id, '_tap_tour_duration', true) === '3 days / 2 nights', 'duration saved and sanitized');
tap_t_assert(get_post_meta($tour_id, '_tap_tour_price_adult', true) === '120.5', 'price saved as sanitized text');
$dest_terms = wp_get_object_terms($tour_id, 'tap_location', ['fields' => 'ids']);
tap_t_assert(!is_wp_error($dest_terms) && empty($dest_terms), 'invalid destination term not assigned');

/* 7) checkbox: absent → explicit '0' ------------------------------------------ */
$_POST = [
    'tap_metabox_nonce' => wp_create_nonce('tap_metabox_save'),
    '_tap_agency_phone' => '+506 5555 0000',
    // _tap_agency_verified intentionally missing
];
TAP_Metaboxes::save($agency_id, get_post($agency_id));
tap_t_assert(get_post_meta($agency_id, '_tap_agency_phone', true) === '+506 5555 0000', 'agency text field saved');
tap_t_assert(get_post_meta($agency_id, '_tap_agency_verified', true) === '0', 'absent checkbox persisted as "0"');

$_POST = [
    'tap_metabox_nonce' => wp_create_nonce('tap_metabox_save'),
    '_tap_agency_verified' => 'on',
];
TAP_Metaboxes::save($agency_id, get_post($agency_id));
tap_t_assert(get_post_meta($agency_id, '_tap_agency_verified', true) === '1', 'checked checkbox persisted as "1"');

/* cleanup -------------------------------------------------------------------- */
wp_delete_post($tour_id, true);
wp_delete_post($agency_id, true);
$_POST = [];
wp_set_current_user(0);

tap_t_finish();
