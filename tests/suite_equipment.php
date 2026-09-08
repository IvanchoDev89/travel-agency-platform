<?php
/**
 * suite_equipment.php — Fase 1: tap_equipment catalog + per-day pricing + booking.
 * Verifies the CPT wiring, price-key helpers, calculate_price (day/hour) and a
 * real booking create for equipment. Creates and removes its own fixtures.
 */
require_once __DIR__ . '/bootstrap.php';

$created_posts = [];
$booking_id = 0;

tap_t_assert(post_type_exists('tap_equipment'), 'tap_equipment post type registered');
$types = TAP_Post_Types::get_service_types();
tap_t_assert(!empty($types['tap_equipment']), 'tap_equipment in service types map');
tap_t_assert('eq' === TAP_Post_Types::meta_prefix('tap_equipment'), 'meta_prefix eq for equipment');
tap_t_assert('_tap_eq_price_day' === TAP_API::get_price_key('tap_equipment'), 'API price key = _tap_eq_price_day');

// Fixture.
$agency = tap_t_test_agency();
$eq = wp_insert_post([
    'post_type'   => 'tap_equipment',
    'post_status' => 'publish',
    'post_title'  => 'Kayak E2E ' . wp_generate_password(5, false),
]);
if ($eq && !is_wp_error($eq)) {
    $created_posts[] = (int) $eq;
    update_post_meta($eq, '_tap_eq_is_active', '1');
    update_post_meta($eq, '_tap_eq_price_day', '25');
    update_post_meta($eq, '_tap_eq_price_hour', '10');
    update_post_meta($eq, '_tap_eq_currency', 'USD');
    update_post_meta($eq, '_tap_eq_agency_id', $agency);
    update_post_meta($eq, '_tap_eq_quantity', '5');
}
tap_t_assert($eq && !is_wp_error($eq), 'created temp equipment fixture');

$in  = gmdate('Y-m-d', strtotime('+30 days'));
$out = gmdate('Y-m-d', strtotime('+31 days'));   // 1 day
$out3 = gmdate('Y-m-d', strtotime('+33 days'));  // 3 days

tap_t_assert(25.0 === TAP_Booking::calculate_price('tap_equipment', $eq, $in, $out), 'equipment price = price_day x 1 day');
tap_t_assert(75.0 === TAP_Booking::calculate_price('tap_equipment', $eq, $in, $out3), 'equipment price = price_day x 3 days');

$eq_hourly = wp_insert_post(['post_type' => 'tap_equipment', 'post_status' => 'publish', 'post_title' => 'Hourly E2E']);
if ($eq_hourly && !is_wp_error($eq_hourly)) {
    $created_posts[] = (int) $eq_hourly;
    update_post_meta($eq_hourly, '_tap_eq_is_active', '1');
    update_post_meta($eq_hourly, '_tap_eq_price_hour', '10');
    update_post_meta($eq_hourly, '_tap_eq_agency_id', $agency);
}
tap_t_assert($eq_hourly && !is_wp_error($eq_hourly), 'created hourly equipment fixture');
tap_t_assert(30.0 === TAP_Booking::calculate_price('tap_equipment', $eq_hourly, $in, $out3), 'equipment falls back to hour price x days when no day price');

// Booking create: agency resolved from _tap_eq_agency_id, totals per-day.
$b = TAP_Booking::create([
    'service_type' => 'tap_equipment',
    'service_id'   => $eq,
    'check_in'     => $in,
    'check_out'    => $out3,
    'adults'       => 1,
    'children'     => 0,
    'client_id'    => 0,
    'guest_email'  => 'eq-e2e@example.test',
    'guest_name'   => 'Eq Tester',
]);
tap_t_assert(!is_wp_error($b), 'equipment booking created');
if (!is_wp_error($b) && !empty($b['booking_id'])) {
    $booking_id = (int) $b['booking_id'];
    $bk = TAP_Booking::get_booking($booking_id);
    tap_t_assert(is_object($bk) && 75.0 === (float) $bk->total_amount, 'booking total = day price x 3 days');
    tap_t_assert(is_object($bk) && (int) $bk->agency_id === (int) $agency, 'booking agency resolved from equipment meta');
    tap_t_assert(is_object($bk) && 3 === (int) $bk->nights, 'booking nights = 3');
}

// REST default search exposes the equipment catalog.
$resp = tap_t_rest('GET', '/tap/v1/search', ['keyword' => 'Kayak E2E']);
$data = tap_t_rest_data($resp);
$ids = array_column((array) $data['results'] ?? [], 'id');
tap_t_assert(in_array((int) $eq, array_map('intval', $ids), true), 'REST search returns equipment listing');

// Cleanup.
if ($booking_id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $booking_id]);
    $wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => $booking_id]);
}
foreach ($created_posts as $pid) {
    wp_delete_post($pid, true);
}
tap_t_finish();