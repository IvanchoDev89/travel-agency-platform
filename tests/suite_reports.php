<?php
/**
 * suite_reports.php — financial reporting consistency.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_reports.php
 *
 * Regression guard: cancelled/voided commissions must NOT be counted as
 * earned platform commission, and a voided commission must not reduce the
 * agency net. The canonical predicate (already used by TAP_Dashboard_Insights)
 * is: payment_status = 'paid' AND commission_status <> 'void'.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$booking_ids = [];
$test_uid = 0;

// Scope the aggregates to a private agency id so no other row on the site can
// influence the exact-value assertions.
$aid = 990000 + wp_rand(1, 9999);

function tap_t_near($a, $b) {
    return abs((float) $a - (float) $b) < 0.01;
}

// A: active, paid, owed            -> counts (100)
// B: cancelled, void               -> ignored
// C: active, UNPAID (waiting)      -> not earned yet
// D: active, paid, but void        -> ignored, and must not reduce net
$a = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 1000, 'commission_amount' => 100, 'commission_status' => 'owed',    'payment_status' => 'paid',    'status' => 'confirmed']);
$b = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 500,  'commission_amount' => 50,  'commission_status' => 'void',    'payment_status' => 'paid',    'status' => 'cancelled']);
$c = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 300,  'commission_amount' => 30,  'commission_status' => 'waiting', 'payment_status' => 'pending', 'status' => 'confirmed']);
$d = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 200,  'commission_amount' => 40,  'commission_status' => 'void',    'payment_status' => 'paid',    'status' => 'confirmed']);
$booking_ids = array_merge($booking_ids, [$a, $b, $c, $d]);

$stats = TAP_Booking_Stats::get_booking_stats($aid);
tap_t_assert(tap_t_near($stats['revenue'], 1500), 'revenue counts active bookings only (1000+300+200)');
tap_t_assert(tap_t_near($stats['commission'], 100), 'commission excludes cancelled/unpaid/void (100)');
tap_t_assert(tap_t_near($stats['net'], 1370), 'net zeroes void commission, keeps active commission (1370)');
tap_t_assert(tap_t_near($stats['revenue'], 1500) && tap_t_near($stats['fees'], 0), 'revenue/fees come from active bookings (1500 / 0)');

// Monthly series: a paid commission increases it, a void one does not.
// Delta approach because the series is site-wide (no agency filter).
$before = TAP_Booking_Stats::get_monthly_series(12);
$i = count($before['commission']) - 1;
$baseline = (float) $before['commission'][$i];

$e = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 1000, 'commission_amount' => 250, 'commission_status' => 'owed', 'payment_status' => 'paid',    'status' => 'confirmed']);
$f = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 1000, 'commission_amount' => 999, 'commission_status' => 'void', 'payment_status' => 'paid',    'status' => 'cancelled']);
$booking_ids = array_merge($booking_ids, [$e, $f]);

$after = TAP_Booking_Stats::get_monthly_series(12);
tap_t_assert(tap_t_near((float) $after['commission'][$i] - $baseline, 250), 'monthly commission series ignores cancelled/void (+250)');

// The void amount is intentionally preserved for the "cancelled commissions"
// audit views; void_commission() must flip the status only.
$g = tap_t_seed_booking(['agency_id' => $aid, 'total_amount' => 100, 'commission_amount' => 70, 'commission_status' => 'owed', 'payment_status' => 'paid', 'status' => 'confirmed']);
$booking_ids[] = $g;
TAP_Booking::void_commission($g);
$row = $wpdb->get_row($wpdb->prepare("SELECT commission_amount, commission_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $g));
tap_t_assert($row && $row->commission_status === 'void', 'void_commission() sets commission_status=void');
tap_t_assert($row && tap_t_near($row->commission_amount, 70), 'void_commission() preserves commission_amount for audit');

// Client-supplied line-item prices must never be persisted by create().
list($service_id, $service_type) = tap_t_find_service(true);
if ($service_id > 0) {
    $test_uid = tap_t_make_subscriber();
    wp_set_current_user($test_uid);
    $res = TAP_Booking::create([
        'service_type' => $service_type,
        'service_id'   => $service_id,
        'check_in'     => gmdate('Y-m-d', strtotime('+18 days')),
        'check_out'    => gmdate('Y-m-d', strtotime('+21 days')),
        'adults'       => 2,
        'items'        => [[
            'service_type' => $service_type,
            'service_id'   => $service_id,
            'quantity'     => 1,
            'unit_price'   => 999999,
            'subtotal'     => 999999,
        ]],
    ]);
    wp_set_current_user(0);
    if (!is_wp_error($res)) {
        $bid = (int) $res['booking_id'];
        $booking_ids[] = $bid;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_booking_items WHERE booking_id = %d",
            $bid
        ));
        tap_t_assert($count === 0, 'create() discards the forged 999999 line-item price');
    } else {
        tap_t_assert(false, 'create() with items returned a booking, got: ' . $res->get_error_code());
    }
} else {
    tap_t_assert(false, 'no published service available to exercise create()');
}

tap_t_cleanup_bookings($booking_ids);
if ($test_uid) {
    wp_delete_user($test_uid);
}
echo 'cleaned ' . count($booking_ids) . " bookings\n";

tap_t_finish();
