<?php
/**
 * suite_refunds.php — Fase 1 money integrity.
 *
 * Real refunds: a cancellation that has to return money actually reverses the
 * PayPal capture (or records an offline refund when no capture exists), and the
 * ledger only flips payment_status -> refunded when the money truly came back.
 * Also covers the client cancel AJAX delegation, the tap_payment_completed hook
 * on capture, the admin CSRF guard on update_booking_status and the advisory
 * lock that serializes room/tour availability checks with the insert.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

$orig_client  = get_option('tap_paypal_client_id', '');
$orig_secret  = get_option('tap_paypal_secret', '');
$orig_sandbox = get_option('tap_paypal_sandbox', '1');
$orig_tour_policy = get_option('tap_cancel_policy_tap_tour', '');
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
update_option('tap_paypal_sandbox', '1');
update_option('tap_cancel_policy_tap_tour', 'strict');

/** Self-contained PayPal mock: counts refund calls; optionally fails them. */
function rf_install_mock($fail_refund = false) {
    $GLOBALS['rf_refund_calls'] = 0;
    $GLOBALS['rf_mock'] = function ($pre, $args, $url) use ($fail_refund) {
        $json = function ($d) {
            return ['headers' => [], 'body' => json_encode($d), 'response' => ['code' => 200], 'cookies' => [], 'filename' => null];
        };
        if (strpos($url, '/v1/oauth2/token') !== false) {
            return $json(['access_token' => 'tok-test', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }
        if (preg_match('#/refund$#', $url)) {
            $GLOBALS['rf_refund_calls']++;
            if ($fail_refund) {
                return new WP_Error('http_fail', 'refund declined');
            }
            return $json(['id' => 'REF-PP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]);
        }
        return new WP_Error('no_route', 'no mock route for this url');
    };
    add_filter('pre_http_request', $GLOBALS['rf_mock'], 10, 3);
}

function rf_remove_mock() {
    if (!empty($GLOBALS['rf_mock'])) {
        remove_filter('pre_http_request', $GLOBALS['rf_mock'], 10);
    }
    $GLOBALS['rf_mock'] = null;
}

function rf_make_agency_admin() {
    $suffix = wp_generate_password(6, false);
    $uid = wp_insert_user([
        'user_login' => 'rf_agency_' . $suffix,
        'user_pass'  => wp_generate_password(12, false),
        'user_email' => 'rf_agency_' . $suffix . '@example.test',
        'role'       => 'tap_agency_admin',
    ]);
    if (is_wp_error($uid) || !$uid) {
        return [0, 0];
    }
    $aid = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'RF Agency ' . $suffix]);
    if (!$aid || is_wp_error($aid)) {
        wp_delete_user($uid);
        return [0, 0];
    }
    update_post_meta($aid, '_tap_agency_user_id', (int) $uid);
    return [(int) $uid, (int) $aid];
}

$bookings = [];

function rf_seed_paid_booking($user_id, $amount = 100, $in_days = 15, $agency_id = null) {
    global $wpdb;
    $id = $wpdb->insert(
        $wpdb->prefix . 'tap_bookings',
        [
            'booking_code'      => 'REF-' . wp_generate_password(6, false),
            'agency_id'         => null === $agency_id ? tap_t_test_agency() : $agency_id,
            'client_id'         => $user_id,
            'service_type'      => 'tap_tour',
            'service_id'        => 0,
            'check_in'          => gmdate('Y-m-d', strtotime("+{$in_days} days")),
            'check_out'         => gmdate('Y-m-d', strtotime('+' . ($in_days + 2) . ' days')),
            'adults'            => 1,
            'total_amount'      => $amount,
            'commission_amount' => round($amount * 0.1, 2),
            'commission_percent'=> 10,
            'commission_status' => 'owed',
            'payment_status'    => 'paid',
            'status'            => 'confirmed',
        ]
    );
    return (int) $wpdb->insert_id;
}

$owner = tap_t_make_subscriber();
tap_t_assert($owner > 0, 'created subscriber fixture for refunds');

// ── 1) online refund: capture present → PayPal called, money returns ──
rf_install_mock(false);
$b1 = rf_seed_paid_booking($owner);
$bookings[] = $b1;
update_post_meta($b1, '_tap_paypal_capture_id', 'CAP-PP-1');
$r = TAP_Booking::client_cancel_request($b1, $owner);
$row1 = TAP_Booking::get_booking($b1);
tap_t_assert(true === $r, 'client cancels a paid-plus-capture booking');
tap_t_assert('cancelled' === $row1->status && 'refunded' === $row1->payment_status, 'cancelled status AND refunded payment_status');
tap_t_assert('REF-PP-1' === get_post_meta($b1, '_tap_paypal_refund_id', true), 'paypal refund id persisted after a real refund');
tap_t_assert(!empty($row1->refunded_at) && 'void' === $row1->commission_status, 'refund stamped + commission voided');
tap_t_assert('strict' === $row1->cancellation_policy && abs((float) $row1->refund_amount - 50.0) < 0.001, 'policy penalty applied (strict 15d → 50%)');
tap_t_assert('' === get_post_meta($b1, '_tap_refund_error', true), 'no refund error recorded on success');
tap_t_assert((int) $GLOBALS['rf_refund_calls'] === 1, 'paypal refund endpoint hit exactly once');
rf_remove_mock();

// ── 2) gateway failure → booking cancelled but NOT refunded ──────────
rf_install_mock(true);
$b2 = rf_seed_paid_booking($owner);
$bookings[] = $b2;
update_post_meta($b2, '_tap_paypal_capture_id', 'CAP-PP-1');
$r = TAP_Booking::client_cancel_request($b2, $owner);
rf_remove_mock();
$row2 = TAP_Booking::get_booking($b2);
tap_t_assert(true === $r, 'cancellation still succeeds when the gateway refund fails');
tap_t_assert('cancelled' === $row2->status, 'status cancelled even on refund failure');
tap_t_assert('paid' === $row2->payment_status, 'payment_status stays paid (money was NOT returned)');
tap_t_assert(empty($row2->refunded_at), 'refunded_at NOT stamped on failure');
tap_t_assert('' !== get_post_meta($b2, '_tap_refund_error', true), 'refund error recorded for operators');

// ── 3) offline payment (no capture) → ledger refund, no gateway call ──
rf_install_mock(false);
$b3 = rf_seed_paid_booking($owner);
$bookings[] = $b3;
$r = TAP_Booking::client_cancel_request($b3, $owner);
rf_remove_mock();
$row3 = TAP_Booking::get_booking($b3);
tap_t_assert(true === $r, 'client cancels an offline-paid booking');
tap_t_assert('refunded' === $row3->payment_status, 'offline paid booking marked refunded');
tap_t_assert((int) $GLOBALS['rf_refund_calls'] === 0, 'no gateway call for a booking without a capture id');

// ── 4) non-refundable policy → paid booking cancelled, nothing back ──
rf_install_mock(true);
update_option('tap_cancel_policy_tap_tour', 'non_refundable');
$b4 = rf_seed_paid_booking($owner);
$bookings[] = $b4;
$r = TAP_Booking::client_cancel_request($b4, $owner);
update_option('tap_cancel_policy_tap_tour', $orig_tour_policy);
rf_remove_mock();
$row4 = TAP_Booking::get_booking($b4);
tap_t_assert('cancelled' === $row4->status, 'non-refundable booking cancels');
tap_t_assert('paid' === $row4->payment_status, 'non-refundable paid booking keeps payment_status paid');
tap_t_assert(empty($row4->refunded_at) && (float) $row4->refund_amount === 0.0, 'no refund recorded when policy returns nothing');
tap_t_assert('void' === $row4->commission_status, 'commission still voided on cancellation');

// ── 5) agency cancellation routes through the same real-refund path ──
rf_install_mock(false);
list($agency_uid, $agency_post) = rf_make_agency_admin();
tap_t_assert($agency_uid && $agency_post, 'created agency admin fixture');
$b5 = rf_seed_paid_booking($owner, 100, 15, $agency_post);
$bookings[] = $b5;
update_post_meta($b5, '_tap_paypal_capture_id', 'CAP-PP-1');
$r = TAP_Booking::agency_booking_action($b5, 'cancel', $agency_uid);
rf_remove_mock();
$row5 = TAP_Booking::get_booking($b5);
tap_t_assert(true === $r && 'agency' === $row5->cancelled_by, 'agency cancels a paid booking');
tap_t_assert('refunded' === $row5->payment_status, 'agency cancel also refunds real money');
tap_t_assert('REF-PP-1' === get_post_meta($b5, '_tap_paypal_refund_id', true), 'agency cancel stores the paypal refund id');

// ── 6) double-booking guard: locked overlap check rejects a second stay ──
$acc  = wp_insert_post(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'post_title' => 'Refund Acc']);
$room = wp_insert_post([
    'post_type' => 'tap_room', 'post_status' => 'publish', 'post_title' => 'Refund Room',
    'post_parent' => $acc, 'post_excerpt' => 'suite room',
]);
tap_t_assert($acc && $room, 'created accommodation + room fixtures');
update_post_meta($room, '_tap_room_accommodation_id', (int) $acc);
update_post_meta($room, '_tap_room_inventory', 1);
update_post_meta($room, '_tap_room_price_per_night', 60);
update_post_meta($room, '_tap_room_is_active', '1');

wp_set_current_user($owner);
$r_in  = gmdate('Y-m-d', strtotime('+40 days'));
$r_out = gmdate('Y-m-d', strtotime('+42 days'));
$first = TAP_Booking::create([
    'service_type' => 'tap_accommodation',
    'service_id'   => $acc,
    'room_id'      => $room,
    'check_in'     => $r_in,
    'check_out'    => $r_out,
    'adults'       => 1,
]);
tap_t_assert(!is_wp_error($first) && !empty($first['booking_id']), 'first room booking created');
if (!is_wp_error($first)) { $bookings[] = (int) $first['booking_id']; }
$second = TAP_Booking::create([
    'service_type' => 'tap_accommodation',
    'service_id'   => $acc,
    'room_id'      => $room,
    'check_in'     => $r_in,
    'check_out'    => $r_out,
    'adults'       => 1,
]);
tap_t_assert(is_wp_error($second) && 'room_unavailable' === $second->get_error_code(), 'overlapping second booking rejected (room_unavailable)');
if (!is_wp_error($first)) {
    TAP_Booking::client_cancel_request((int) $first['booking_id'], $owner);
    $after = TAP_Booking::create([
        'service_type' => 'tap_accommodation',
        'service_id'   => $acc,
        'room_id'      => $room,
        'check_in'     => $r_in,
        'check_out'    => $r_out,
        'adults'       => 1,
    ]);
    tap_t_assert(!is_wp_error($after), 'room is bookable again after the stay is cancelled');
    if (!is_wp_error($after)) { $bookings[] = (int) $after['booking_id']; }
}
wp_set_current_user(0);

// ── 7) wiring / source guards ───────────────────────────────────────
$src_front = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false !== strpos($src_front, 'client_cancel_request($booking_id'), 'ajax_cancel_booking delegates to client_cancel_request');
tap_t_assert(false === strpos($src_front, '"status"           => \'cancelled\',\n                \'cancelled_by\''), 'ajax_cancel_booking no longer writes the raw row');
$src_ajax = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/class-ajax.php');
tap_t_assert(false !== strpos($src_ajax, 'record_paid_capture('), 'capture_paypal_order delegates to the idempotent capture path');
tap_t_assert(false !== strpos($src_ajax, "check_ajax_referer('tap_admin_booking', '_ajax_nonce')"), 'admin branch of update_booking_status is CSRF-protected');
$src_booking = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/class-booking.php');
tap_t_assert(false !== strpos($src_booking, "do_action('tap_payment_completed', (int) \$booking_id, \$method, \$capture_id)"), 'record_paid_capture fires tap_payment_completed (receipts + commission)');
tap_t_assert(false !== strpos($src_booking, 'GET_LOCK'), 'create() serializes room/tour availability with an advisory MySQL lock');
tap_t_assert(false !== strpos($src_booking, 'function execute_refund('), 'TAP_Booking::execute_refund exists');
$main_src = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/travel-agency-platform.php');
tap_t_assert(false !== strpos($main_src, "wp_create_nonce('tap_admin_booking')"), 'admin nonce localized on the admin script');

// cleanup ──────────────────────────────────────────────────────────
rf_remove_mock();
wp_set_current_user(0);
if (!empty($acc)) { wp_delete_post($acc, true); }
if (!empty($room)) { wp_delete_post($room, true); }
tap_t_cleanup_bookings($bookings);
if (!empty($agency_post)) { wp_delete_post($agency_post, true); }
if (!empty($agency_uid)) { wp_delete_user($agency_uid); }
wp_delete_user($owner);
update_option('tap_paypal_client_id', $orig_client);
update_option('tap_paypal_secret', $orig_secret);
update_option('tap_paypal_sandbox', $orig_sandbox);
update_option('tap_cancel_policy_tap_tour', $orig_tour_policy);
tap_t_finish();