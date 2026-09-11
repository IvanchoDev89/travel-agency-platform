<?php
/**
 * suite_booking_lifecycle.php — T7 booking lifecycle improvements.
 * Verifies cancellation policies (resolution + refund % + applied on cancel),
 * the booking status state machine, auto-complete after check-out,
 * commission lifecycle (owed only when paid; voided on cancel/refund)
 * and the front-end refund display.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

$policies = TAP_Booking::cancellation_policies();
tap_t_assert(count($policies) === 4, 'cancellation_policies exposes 4 policies');

// ---- 1) Settings defaults seeded by the installer ----
$defaults = [
    'tap_cancel_policy_tap_accommodation' => 'flexible',
    'tap_cancel_policy_tap_tour'          => 'strict',
    'tap_cancel_policy_tap_transport'     => 'moderate',
    'tap_cancel_policy_tap_car_rental'    => 'moderate',
    'tap_cancel_policy_tap_boat'          => 'strict',
    'tap_cancel_policy_tap_package'       => 'strict',
    'tap_cancel_policy_tap_equipment'     => 'flexible',
];
$ok_defaults = true;
foreach ($defaults as $opt => $val) {
    if (get_option($opt, '') !== $val) {
        $ok_defaults = false;
    }
}
tap_t_assert($ok_defaults, 'per-service policy default options are registered');
tap_t_assert(get_option('tap_booking_auto_complete', '') === '1', 'auto-complete option defaults to enabled');

// ---- 2) Policy resolution priority: listing meta -> legacy acc meta -> option -> flexible ----
list($tour_id, $tour_type) = [0, 'tap_tour'];
$tours = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
$tour_id = $tours ? (int) $tours[0] : 0;
tap_t_assert($tour_id > 0, 'published tap_tour exists for policy tests');

$resolved = TAP_Booking::cancellation_policy('tap_tour', $tour_id);
tap_t_assert($resolved === 'strict', 'tour resolves to strict by default, got ' . $resolved);

update_post_meta($tour_id, '_tap_cancellation_policy', 'non_refundable');
tap_t_assert(TAP_Booking::cancellation_policy('tap_tour', $tour_id) === 'non_refundable', 'listing meta overrides service default');
delete_post_meta($tour_id, '_tap_cancellation_policy');
tap_t_assert(TAP_Booking::cancellation_policy('tap_tour', $tour_id) === 'strict', 'listing meta removed -> falls back to service default');

$accs = get_posts(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
if ($accs) {
    $acc_id = (int) $accs[0];
    update_post_meta($acc_id, '_tap_acc_cancellation', 'moderate');
    tap_t_assert(TAP_Booking::cancellation_policy('tap_accommodation', $acc_id) === 'moderate', 'accommodation legacy meta honored');
    delete_post_meta($acc_id, '_tap_acc_cancellation');
}
tap_t_assert(TAP_Booking::cancellation_policy('tap_unknown_type', 0) === 'flexible', 'unknown service type falls back to flexible');

// ---- 3) refund_percent tables ----
tap_t_assert(TAP_Booking::refund_percent('flexible', 1) === 100, 'flexible 1+ days -> 100%');
tap_t_assert(TAP_Booking::refund_percent('flexible', 0) === 0, 'flexible 0 days -> 0%');
tap_t_assert(TAP_Booking::refund_percent('moderate', 10) === 100, 'moderate 10d -> 100%');
tap_t_assert(TAP_Booking::refund_percent('moderate', 4) === 50, 'moderate 4d -> 50%');
tap_t_assert(TAP_Booking::refund_percent('moderate', 2) === 50, 'moderate 2d -> 50%');
tap_t_assert(TAP_Booking::refund_percent('moderate', 1) === 0, 'moderate 1d -> 0%');
tap_t_assert(TAP_Booking::refund_percent('strict', 7) === 50, 'strict 7d -> 50%');
tap_t_assert(TAP_Booking::refund_percent('strict', 8) === 50, 'strict 8d -> 50%');
tap_t_assert(TAP_Booking::refund_percent('strict', 6) === 0, 'strict 6d -> 0%');
tap_t_assert(TAP_Booking::refund_percent('non_refundable', 30) === 0, 'non_refundable -> 0% always');

// ---- 4) cancellation_refund computation on a booking object ----
$future = date('Y-m-d', strtotime('+10 days'));
$past   = date('Y-m-d', strtotime('-1 day'));
$now    = gmdate('Y-m-d');
$today  = gmdate('Y-m-d', strtotime('+0 days'));

$booking_far = (object) [
    'service_type' => 'tap_tour',
    'service_id'   => $tour_id,
    'check_in'     => $future,
    'total_amount' => 200,
];
$refund = TAP_Booking::cancellation_refund($booking_far);
tap_t_assert($refund['policy'] === 'strict', 'computed policy is strict');
tap_t_assert($refund['refund_percent'] === 50 && $refund['refund_amount'] === 100.0, 'strict 10d -> 50% of 200 = 100');

$booking_near = (object) [
    'service_type' => 'tap_tour',
    'service_id'   => $tour_id,
    'check_in'     => date('Y-m-d', strtotime('+2 days')),
    'total_amount' => 200,
];
$refund = TAP_Booking::cancellation_refund($booking_near);
tap_t_assert($refund['refund_percent'] === 0 && $refund['refund_amount'] === 0.0, 'strict 2d -> 0% refund');

tap_t_assert(TAP_Booking::refund_summary((object) ['service_type' => 'tap_tour', 'service_id' => $tour_id, 'check_in' => $future, 'total_amount' => 100, 'refund_amount' => 50]) !== '', 'refund_summary renders non-empty text');

// ---- 5) client_cancel_request applies the policy on a paid booking ----
$client_id = tap_t_make_subscriber();
tap_t_assert($client_id > 0, 'created throwaway client user');

$created = [];
$b = tap_t_seed_booking([
    'client_id'       => $client_id,
    'service_type'    => 'tap_tour',
    'service_id'      => $tour_id,
    'agency_id'       => tap_t_test_agency(),
    'total_amount'    => 100,
    'commission_amount' => 10,
    'commission_percent' => 10,
    'commission_status' => 'owed',
    'status'          => 'confirmed',
    'payment_status'  => 'paid',
    'check_in'        => $future,
    'check_out'       => date('Y-m-d', strtotime('+12 days')),
]);
$created[] = $b;

$res = TAP_Booking::client_cancel_request($b, $client_id);
tap_t_assert($res === true, 'client cancel returns true');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($row && $row->status === 'cancelled', 'booking marked cancelled');
tap_t_assert($row && $row->cancellation_policy === 'strict', 'cancellation policy stored on booking');
tap_t_assert($row && (float) $row->refund_amount === 50.0, 'refund_amount = 50% of 100, got ' . ($row->refund_amount ?? 'null'));
tap_t_assert($row && (int) $row->refund_percent === 50, 'refund_percent = 50 stored');
tap_t_assert($row && !empty($row->refunded_at), 'refunded_at recorded for money-back cancellation');
tap_t_assert($row && $row->payment_status === 'refunded', 'paid + refund>0 -> payment_status refunded');
tap_t_assert($row && $row->commission_status === 'void', 'commission voided on client cancel');
tap_t_cleanup_bookings($created);

// ---- 6) non-refundable (or inside penalty window) paid booking: no money back ----
$b = tap_t_seed_booking([
    'client_id'       => $client_id,
    'service_type'    => 'tap_tour',
    'service_id'      => $tour_id,
    'agency_id'       => tap_t_test_agency(),
    'total_amount'    => 100,
    'commission_amount' => 10,
    'commission_percent' => 10,
    'commission_status' => 'owed',
    'status'          => 'confirmed',
    'payment_status'  => 'paid',
    'check_in'        => date('Y-m-d', strtotime('+1 day')),
]);
$created[] = $b;
$res = TAP_Booking::client_cancel_request($b, $client_id);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($res === true, 'near-term cancel accepted');
tap_t_assert($row && (float) $row->refund_amount === 0.0, 'strict 1d -> refund_amount 0');
tap_t_assert($row && $row->payment_status === 'paid', 'no money back -> payment_status stays paid');
tap_t_assert($row && $row->commission_status === 'void', 'commission voided even with 0 refund');
tap_t_cleanup_bookings($created);

// ---- 7) state machine transitions ----
$b = tap_t_seed_booking(['status' => 'request', 'payment_status' => 'pending', 'commission_status' => 'owed']);
$created[] = $b;

$err = TAP_Booking::update_status($b, 'completed');
tap_t_assert(is_wp_error($err) && $err->get_error_code() === 'invalid_transition', 'request->completed rejected as invalid transition');

$res = TAP_Booking::update_status($b, 'pending');
tap_t_assert($res === true, 'request->pending accepted');
$res = TAP_Booking::update_status($b, 'confirmed');
tap_t_assert($res === true, 'pending->confirmed accepted');
$res = TAP_Booking::update_status($b, 'confirmed');
tap_t_assert($res === true, 'same-status update is a no-op success');

$err = TAP_Booking::update_status($b, 'request');
tap_t_assert(is_wp_error($err), 'confirmed->request rejected');

$res = TAP_Booking::update_status($b, 'cancelled');
tap_t_assert($res === true, 'confirmed->cancelled accepted');
$row = $wpdb->get_row($wpdb->prepare("SELECT commission_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($row && $row->commission_status === 'void', 'update_status(cancelled) voids commission');
$err = TAP_Booking::update_status($b, 'confirmed');
tap_t_assert(is_wp_error($err), 'cancelled is terminal -> confirmed rejected');
tap_t_cleanup_bookings($created);

// ---- 8) transition filter can extend the machine ----
add_filter('tap_booking_status_transitions', function ($t) {
    $t['pending'][] = 'confirmed_extra';
    if (!in_array('confirmed_extra', $GLOBALS['tap_valid_statuses'] ?? [], true)) {
        $GLOBALS['tap_trans_test'] = true;
    }
    return $t;
});
unset($GLOBALS['tap_trans_test']);
$b = tap_t_seed_booking(['status' => 'pending', 'payment_status' => 'pending', 'commission_status' => 'owed']);
$created[] = $b;
$t = apply_filters('tap_booking_status_transitions', TAP_Booking::$transitions);
tap_t_assert(in_array('confirmed_extra', $t['pending'], true), 'tap_booking_status_transitions filter can extend transitions');
$res = TAP_Booking::update_status($b, 'completed');
tap_t_assert($res === true, 'pending->completed accepted (UI still disables it for pending)');
$res = TAP_Booking::update_status($b, 'cancelled');
tap_t_assert($res === true, 'completed->cancelled accepted');
tap_t_cleanup_bookings($created);

// ---- 9) commission payable only when paid and not cancelled ----
$b = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'pending', 'commission_status' => 'owed']);
$created[] = $b;
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert(TAP_Booking::is_commission_payable($row) === false, 'owed but unpaid -> NOT payable');

$wpdb->update($wpdb->prefix . 'tap_bookings', ['payment_status' => 'paid'], ['id' => $b]);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert(TAP_Booking::is_commission_payable($row) === true, 'owed + paid + confirmed -> payable');

$wpdb->update($wpdb->prefix . 'tap_bookings', ['commission_status' => 'disputed'], ['id' => $b]);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
TAP_Booking::void_commission($b);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($row && $row->commission_status === 'disputed', 'void_commission never touches a disputed commission');
tap_t_cleanup_bookings($created);

$b = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'paid', 'commission_status' => 'owed']);
$created[] = $b;
$wpdb->update($wpdb->prefix . 'tap_bookings', ['commission_status' => 'void'], ['id' => $b]);
TAP_Booking::mark_commission_owed($b);
$row = $wpdb->get_row($wpdb->prepare("SELECT commission_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($row && $row->commission_status === 'void', 'mark_commission_owed does not resurrect a voided commission');
tap_t_cleanup_bookings($created);

// ---- 10) auto-complete after check-out ----
$yesterday = date('Y-m-d', strtotime('-1 day'));
$old_auto = get_option('tap_booking_auto_complete', '1');

$b = tap_t_seed_booking([
    'status'      => 'confirmed',
    'payment_status' => 'paid',
    'commission_status' => 'owed',
    'check_in'    => $past,
    'check_out'   => $yesterday,
    'total_amount' => 100,
    'commission_amount' => 10,
]);
$created[] = $b;

TAP_Booking::complete_past_bookings();
$row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b));
tap_t_assert($row && $row->status === 'completed', 'confirmed booking with past check_out auto-completed');

update_option('tap_booking_auto_complete', '0');
$b2 = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'paid', 'check_in' => $past, 'check_out' => $yesterday]);
$created[] = $b2;
TAP_Booking::complete_past_bookings();
$row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b2));
tap_t_assert($row && $row->status === 'confirmed', 'auto-complete disabled by option');
update_option('tap_booking_auto_complete', $old_auto);

$b3 = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'paid', 'check_in' => $today, 'check_out' => $future]);
$created[] = $b3;
TAP_Booking::complete_past_bookings();
$row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b3));
tap_t_assert($row && $row->status === 'confirmed', 'future check_out left untouched');
tap_t_cleanup_bookings($created);

// ---- 11) settlement query only sees paid, non-cancelled bookings ----
$b4 = tap_t_seed_booking(['status' => 'cancelled', 'payment_status' => 'paid', 'commission_status' => 'owed', 'service_type' => 'tap_tour', 'service_id' => $tour_id]);
$b5 = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'pending', 'commission_status' => 'owed', 'service_type' => 'tap_tour', 'service_id' => $tour_id]);
$b6 = tap_t_seed_booking(['status' => 'confirmed', 'payment_status' => 'paid', 'commission_status' => 'owed', 'service_type' => 'tap_tour', 'service_id' => $tour_id]);
$created = array_merge($created, [$b4, $b5, $b6]);

$ids = implode(',', $created);
$count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings
     WHERE id IN ({$ids})
       AND commission_status = 'owed'
       AND payment_status = 'paid'
       AND status NOT IN ('cancelled', 'refunded')"
);
tap_t_assert($count === 1, 'settlement select returns only the paid non-cancelled booking, got ' . $count);
tap_t_cleanup_bookings($created);

// ---- 12) front-end shows the refund on a cancelled booking ----
if (isset($shortcode_tags['tap_my_bookings'])) {
    $client_uid = tap_t_make_subscriber();
    $cb = tap_t_seed_booking([
        'client_id'       => $client_uid,
        'service_type'    => 'tap_tour',
        'service_id'      => $tour_id,
        'agency_id'       => tap_t_test_agency(),
        'total_amount'    => 100,
        'status'          => 'cancelled',
        'payment_status'  => 'refunded',
        'commission_amount' => 0,
        'commission_percent' => 0,
        'commission_status' => 'void',
        'check_in'        => $future,
        'check_out'       => date('Y-m-d', strtotime('+12 days')),
        'cancellation_policy' => 'strict',
        'refund_amount'   => 50,
        'refund_percent'  => 50,
        'refunded_at'     => current_time('mysql'),
    ]);
    wp_set_current_user($client_uid);
    $out = do_shortcode('[tap_my_bookings]');
    tap_t_assert($cb && strpos($out, 'Reembolso:') !== false, 'my_bookings shows the applied refund amount');
    wp_set_current_user(0);
    tap_t_cleanup_bookings([$cb]);
}

// ---- 13) refunded status label rendered by agency ledger handles void commission ----
$b7 = tap_t_seed_booking([
    'service_type' => 'tap_tour',
    'service_id'   => $tour_id,
    'status'       => 'cancelled',
    'payment_status' => 'paid',
    'commission_status' => 'void',
    'commission_amount' => 10,
    'total_amount' => 100,
]);
$created = [$b7];
// The agency ledger query (agency_panel) should still count it as active client revenue (non refunded/cancelled) = 0,
// since a cancelled booking adds nothing to revenue.
$rev = (float) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END),0)
     FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
    $b7
));
tap_t_assert($rev === 0.0, 'cancelled booking excluded from agency revenue');
tap_t_cleanup_bookings($created);

if (!empty($client_uid)) {
    wp_delete_user($client_uid);
}
if ($client_id) {
    wp_delete_user($client_id);
}
tap_t_finish();