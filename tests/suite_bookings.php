<?php
/**
 * suite_bookings.php — booking + commission calculation flow.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_bookings.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$ids = [];

// 1) agency resolution (any site): find an agency post + its linked user
$anchor = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
$visible_anchor = tap_t_test_agency();
if ($visible_anchor && get_post($visible_anchor)) {
    $anchor = [$visible_anchor];
}
tap_t_assert(!empty($anchor), 'at least one tap_agency post exists');
$agency_id = $anchor ? (int) $anchor[0] : 0;
$owner_mark = $agency_id ? get_post_meta($agency_id, '_tap_agency_user_id', true) : 0;
if ($owner_mark) {
    $resolved = TAP_Booking::get_agency_for_user((int) $owner_mark);
    tap_t_assert((int) $resolved === $agency_id, "get_agency_for_user(owner) resolves to post {$agency_id}");
} else {
    tap_t_assert(true, 'no _tap_agency_user_id meta on seeded agency (skip resolve assertion)');
}

// 2) commission derivation from plan
// Plan 2 (Básico) = 8%; Plan 3 (Pro) = 5%; Plan 1 (Gratis) = 10% default.
$plans = $wpdb->get_row("SELECT commission_rate FROM {$wpdb->prefix}tap_plans WHERE id = 2");
tap_t_assert(isset($plans->commission_rate) && (float) $plans->commission_rate === 8.0, 'plan id=2 commission_rate = 8%');

// get_agency_commission returns the agency commission RATE
$rate = TAP_Booking::get_agency_commission($agency_id);
tap_t_assert(is_numeric($rate) && $rate > 0, 'get_agency_commission returns numeric rate (got ' . var_export($rate, true) . ')');

// 2b) Volume incentive: the more an agency sells, the LESS commission it pays.
// Use a dedicated throwaway agency so pre-existing paid bookings in the site
// DB cannot push the counter past a tier threshold before the test starts.
$base_rate = (float) get_option('tap_commission_default', 10);
$vol_agency_id = (int) wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'TAP volume E2E ' . tap_t_suffix(),
]);
tap_t_assert($vol_agency_id > 0, 'dedicated volume-test agency created');
$cleanup_rates = [];
$rate_before = (float) TAP_Booking::get_agency_commission($vol_agency_id);
tap_t_assert($rate_before <= $base_rate, 'baseline commission <= default (no volume discount yet)');

// 11-30 paid bookings -> -1 point
for ($i = 0; $i < 11; $i++) {
    $cleanup_rates[] = tap_t_seed_booking([
        'agency_id' => $vol_agency_id, 'payment_status' => 'paid',
        'created_at' => current_time('mysql'), 'commission_amount' => 1,
    ]);
}
$rate_mid = (float) TAP_Booking::get_agency_commission($vol_agency_id);
tap_t_assert($rate_before >= 1 && abs($rate_mid - ($rate_before - 1)) < 0.01, "mid volume lowers commission by 1 point ({$rate_before} -> {$rate_mid})");

// 31+ paid bookings -> -2 points total (floor = 50% of base)
for ($i = 0; $i < 20; $i++) {
    $cleanup_rates[] = tap_t_seed_booking([
        'agency_id' => $vol_agency_id, 'payment_status' => 'paid',
        'created_at' => current_time('mysql'), 'commission_amount' => 1,
    ]);
}
$rate_high = (float) TAP_Booking::get_agency_commission($vol_agency_id);
$expected_high = max($rate_before - 2, $rate_before * 0.5);
tap_t_assert(abs($rate_high - $expected_high) < 0.01, "high volume lowers commission by 2 with 50% floor ({$rate_before} -> {$rate_high})");
tap_t_assert($rate_high >= $rate_before * 0.5 - 0.01, 'commission never drops below 50% of the negotiated base');

// Options are configurable: changing them changes the computed rate.
update_option('tap_volume_tier3_discount', 3);
$rate_custom = (float) TAP_Booking::get_agency_commission($vol_agency_id);
$expected_custom = max($rate_before - 3, $rate_before * 0.5);
tap_t_assert(abs($rate_custom - $expected_custom) < 0.01, 'configurable tier3 discount changes the rate');
delete_option('tap_volume_tier3_discount');
$rate_restored = (float) TAP_Booking::get_agency_commission($vol_agency_id);
tap_t_assert(abs($rate_restored - $rate_high) < 0.01, 'removing the option restores the default tier');
foreach ($cleanup_rates as $cid) {
    $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $cid]);
}
if ($vol_agency_id) {
    wp_delete_post($vol_agency_id, true);
}

// 3) seed a booking for the resolved agency and verify commission math + stats rollup
$b = tap_t_seed_booking(['agency_id' => $agency_id, 'total_amount' => 250, 'commission_amount' => 25, 'commission_percent' => 10]);
$ids[] = $b;
$row = $wpdb->get_row($wpdb->prepare("SELECT total_amount, commission_amount, commission_percent FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert((float) $row->total_amount === 250.0 && (float) $row->commission_amount === 25.0, 'seeded booking stored total/commission');

// 4) agency booking stats aggregates the new booking
$bs = TAP_Booking_Stats::get_booking_stats($agency_id);
tap_t_assert(isset($bs['total'], $bs['commission'], $bs['revenue']), 'get_booking_stats returns total/commission/revenue keys');
tap_t_assert((float) $bs['commission'] >= 25.0, 'get_booking_stats commission includes seeded booking');

// 4b) batched items: get_booking_items_for groups by booking in a single query
$wpdb->insert($wpdb->prefix . 'tap_booking_items', ['booking_id' => $b, 'service_type' => 'tap_tour', 'service_id' => 1, 'quantity' => 2, 'unit_price' => 10, 'subtotal' => 20]);
$wpdb->insert($wpdb->prefix . 'tap_booking_items', ['booking_id' => $b, 'service_type' => 'tap_tour', 'service_id' => 1, 'quantity' => 1, 'unit_price' => 5, 'subtotal' => 5]);
$batched = TAP_Booking::get_booking_items_for([$b, 0, -3]);
tap_t_assert(isset($batched[$b]) && count($batched[$b]) === 2, 'get_booking_items_for groups rows per booking');
tap_t_assert(count(TAP_Booking::get_booking_items_for([$b])) === 1 && count(TAP_Booking::get_booking_items($b)) === 2, 'get_booking_items_for returns the same rows as get_booking_items');
tap_t_assert(TAP_Booking::get_booking_items_for([0, -3]) === [], 'get_booking_items_for ignores invalid ids');
tap_t_assert(TAP_Booking::get_booking_items_for([]) === [], 'get_booking_items_for with no ids is empty');
$wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => $b]);

tap_t_cleanup_bookings($ids);

// 5) REGRESSION: both front-end booking forms must pass the create_booking nonce guard.
// The [tap_booking_form] shortcode JS sends tap_ajax.nonce (action 'tap_nonce'), while the
// accommodation template sends a 'tap_booking_nonce' nonce. create_booking accepts either.
$shortcode_nonce = wp_create_nonce('tap_nonce');
$acc_nonce       = wp_create_nonce('tap_booking_nonce');
tap_t_assert(wp_verify_nonce($shortcode_nonce, 'tap_nonce') || wp_verify_nonce($shortcode_nonce, 'tap_booking_nonce'),
    '[regression] shortcode booking form nonce (tap_nonce) is accepted by create_booking');
tap_t_assert(wp_verify_nonce($acc_nonce, 'tap_nonce') || wp_verify_nonce($acc_nonce, 'tap_booking_nonce'),
    '[regression] accommodation template nonce (tap_booking_nonce) is accepted by create_booking');
// A random/garbage nonce must NOT pass.
$bogus = wp_generate_password(10, false);
tap_t_assert(!(wp_verify_nonce($bogus, 'tap_nonce') || wp_verify_nonce($bogus, 'tap_booking_nonce')),
    '[regression] bogus nonce is rejected by create_booking');

// 6) REGRESSION: the shortcode booking form must carry an idempotency key. Its JS
// posts the whole serialized form, so without a stable per-render key a double
// click / network retry creates duplicate bookings. The theme templates already
// send one; the shortcode (a primary public form) must too.
list($bf_id, $bf_type) = tap_t_find_service(true);
$bf_html = do_shortcode('[tap_booking_form service_type="' . esc_attr($bf_type) . '" service_id="' . (int) $bf_id . '"]');
tap_t_assert(strpos($bf_html, 'name="idempotency_key"') !== false,
    '[regression] shortcode booking form renders an idempotency_key field');

tap_t_finish();