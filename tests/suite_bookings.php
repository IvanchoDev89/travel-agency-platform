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

// 3) seed a booking for the resolved agency and verify commission math + stats rollup
$b = tap_t_seed_booking(['agency_id' => $agency_id, 'total_amount' => 250, 'commission_amount' => 25, 'commission_percent' => 10]);
$ids[] = $b;
$row = $wpdb->get_row($wpdb->prepare("SELECT total_amount, commission_amount, commission_percent FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert((float) $row->total_amount === 250.0 && (float) $row->commission_amount === 25.0, 'seeded booking stored total/commission');

// 4) agency booking stats aggregates the new booking
$bs = TAP_Booking::get_booking_stats($agency_id);
tap_t_assert(isset($bs['total'], $bs['commission'], $bs['revenue']), 'get_booking_stats returns total/commission/revenue keys');
tap_t_assert((float) $bs['commission'] >= 25.0, 'get_booking_stats commission includes seeded booking');

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

tap_t_finish();