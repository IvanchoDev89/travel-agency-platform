<?php
/**
 * suite_money_security.php — critical money-path guards (Round A).
 * FUNCTIONAL: real HMAC verification, real gateway resolution, real capture
 * idempotency, real CSV output and real cron expiry functions.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_money_security.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$cleanup_posts = [];
$cleanup_bookings = [];
$cleanup_users = [];
$option_snapshot = [];

/* --- 1) Onvo webhook HMAC verification -------------------------------------- */
$secret = 'tap_e2e_' . $sfx;
$body   = '{"id":"evt_' . $sfx . '"}';
$now    = 1700000000;
$sig    = hash_hmac('sha256', $now . '.' . $body, $secret);

tap_t_assert(true === TAP_Onvo::verify_webhook($body, 't=' . $now . ',v1=' . $sig, $secret, $now), 'valid Onvo signature accepted');
tap_t_assert(false === TAP_Onvo::verify_webhook($body . ' ', 't=' . $now . ',v1=' . $sig, $secret, $now), 'tampered body rejected');
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $now . ',v1=nothex', $secret, $now), 'malformed signature rejected');
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $now . ',v1=' . $sig, 'wrong-secret', $now), 'wrong secret rejected');
$stale_ts = $now - 400;
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $stale_ts . ',v1=' . hash_hmac('sha256', $stale_ts . '.' . $body, $secret), $secret, $now), 'stale timestamp rejected');
$future_ts = $now + 400;
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $future_ts . ',v1=' . hash_hmac('sha256', $future_ts . '.' . $body, $secret), $secret, $now), 'future timestamp rejected');
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 'v1=' . $sig, $secret, $now), 'missing t rejected');
tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $now, $secret, $now), 'missing v1 rejected');
if ('' === (string) TAP_Onvo::get_webhook_secret()) {
    tap_t_assert(false === TAP_Onvo::verify_webhook($body, 't=' . $now . ',v1=' . $sig, '', $now), 'missing secret rejected');
}

/* --- 2) PayPal verification FAILURE path ------------------------------------ */
tap_t_guard_paypal_credentials();
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
tap_t_remove_paypal_mock();
$failure_filter = static function ($pre, $args, $url) {
    $ok = ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    if (strpos((string) $url, '/v1/oauth2/token') !== false) {
        return $ok + ['body' => wp_json_encode(['access_token' => 'tok-fail'])];
    }
    if (strpos((string) $url, '/verify-webhook-signature') !== false) {
        return $ok + ['body' => wp_json_encode(['verification_status' => 'FAILURE'])];
    }
    return $pre;
};
add_filter('pre_http_request', $failure_filter, 99, 3);
$headers = [
    'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://pp.test/cert.pem',
    'PAYPAL-TRANSMISSION-ID' => 'tx-' . $sfx, 'PAYPAL-TRANSMISSION-SIG' => 'sig',
    'PAYPAL-TRANSMISSION-TIME' => gmdate('c'),
];
tap_t_assert(false === TAP_PayPal::verify_webhook($headers, ['id' => 'evt-' . $sfx]), 'invalid PayPal signature returns false');
remove_filter('pre_http_request', $failure_filter, 99);

/* --- 3) Active gateway resolution ------------------------------------------- */
$gateway_options = ['tap_paypal_enabled', 'tap_sinpe_enabled', 'tap_manual_payment_enabled', 'tap_onvo_enabled', 'tap_sinpe_phone', 'tap_manual_bank_info'];
foreach ($gateway_options as $gopt) {
    $option_snapshot[$gopt] = get_option($gopt, null);
}
foreach (['tap_paypal_enabled', 'tap_sinpe_enabled', 'tap_manual_payment_enabled', 'tap_onvo_enabled'] as $gopt) { update_option($gopt, '0'); }
update_option('tap_sinpe_phone', '');
update_option('tap_manual_bank_info', '');
tap_t_assert(null === TAP_Payment::get_active_gateway(), 'no ready gateway resolves to null');
update_option('tap_sinpe_enabled', '1');
update_option('tap_sinpe_phone', '88888888');
$active = TAP_Payment::get_active_gateway();
tap_t_assert($active && 'sinpe' === $active['id'], 'SINPE resolves as the active ready gateway');
foreach ($option_snapshot as $gopt => $gval) {
    if (null === $gval) { delete_option($gopt); } else { update_option($gopt, $gval); }
}

/* --- 4) Capture idempotency (no double spend) -------------------------------- */
$cap_booking = tap_t_seed_booking([
    'booking_code' => 'CAP-' . $sfx, 'total_amount' => 100,
    'commission_amount' => 10, 'payment_status' => 'pending', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $cap_booking;
$first = TAP_Booking::record_paid_capture($cap_booking, 'sinpe', 'CAPID-' . $sfx);
$second = TAP_Booking::record_paid_capture($cap_booking, 'sinpe', 'CAPID-' . $sfx);
tap_t_assert(true === $first, 'first capture marks the booking paid');
tap_t_assert(is_wp_error($second) && 'already_paid' === $second->get_error_code(), 'duplicate capture is rejected');
$cap_row = TAP_Booking::get_booking($cap_booking);
tap_t_assert('paid' === $cap_row->payment_status && abs(100.0 - (float) $cap_row->amount_paid) < 0.01, 'amount is recorded once, not doubled');

/* --- 5) CSV exports + formula-injection neutrality --------------------------- */
$csv_agency = tap_t_test_agency();
$csv_booking = tap_t_seed_booking([
    'booking_code' => '=HYPER("http://evil.test")', 'agency_id' => $csv_agency,
]);
$cleanup_bookings[] = $csv_booking;
$csv = TAP_Front_Dash::export_bookings_csv($csv_agency);
tap_t_assert(false !== strpos($csv, "'=HYPER"), 'CSV neutralizes formula-leading cells');
tap_t_assert(false !== strpos($csv, 'codigo,servicio'), 'CSV header order is preserved');
tap_t_assert("'A,B" === TAP_Front_Dash::csv_cell('=A,B') || false !== strpos(TAP_Front_Dash::csv_cell('=A,B'), "'=A,B"), 'csv_cell keeps quoting alongside neutralization');

/* --- 6) Promo expiry via cron entry point ------------------------------------ */
$listing = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'PROMO-' . $sfx]);
if ((int) $listing > 0) {
    $cleanup_posts[] = (int) $listing;
    $yesterday = $wpdb->get_var("SELECT DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    $wpdb->insert($wpdb->prefix . 'tap_promos', [
        'agency_id' => $csv_agency, 'listing_id' => (int) $listing, 'months' => 1,
        'amount' => 10, 'status' => 'active', 'paid_until' => $yesterday,
        'payment_status' => 'paid',
    ]);
    $promo_id = (int) $wpdb->insert_id;
    delete_transient('tap_promos_expiry_check');
    TAP_Promotions::maybe_expire();
    $promo_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $wpdb->prefix . 'tap_promos WHERE id = %d', $promo_id));
    tap_t_assert('expired' === $promo_status, 'expired promo is flipped by maybe_expire()');
    TAP_Promotions::maybe_expire();
    $again = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $wpdb->prefix . 'tap_promos WHERE id = %d', $promo_id));
    tap_t_assert('expired' === $again, 'promo expiry is idempotent');
    $wpdb->delete($wpdb->prefix . 'tap_promos', ['id' => $promo_id]);
}

/* --- 7) Subscription expiry via cron entry point ----------------------------- */
$wpdb->insert($wpdb->prefix . 'tap_agency_subscriptions', [
    'agency_id' => $csv_agency, 'plan_id' => 2, 'status' => 'active',
    'paid_until' => $yesterday, 'payment_status' => 'paid',
]);
$sub_id = (int) $wpdb->insert_id;
delete_transient('tap_subscriptions_expiry_check');
TAP_Subscriptions::maybe_expire();
$sub_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $wpdb->prefix . 'tap_agency_subscriptions WHERE id = %d', $sub_id));
tap_t_assert('expired' === $sub_status, 'expired subscription is flipped by maybe_expire()');
$wpdb->delete($wpdb->prefix . 'tap_agency_subscriptions', ['id' => $sub_id]);

/* --- 8) Cancellation policy + paid-amount math ------------------------------- */
$future = gmdate('Y-m-d', strtotime('+30 days'));
$past   = gmdate('Y-m-d', strtotime('-1 day'));
$cancel_booking = (object) [
    'status' => 'confirmed', 'check_in' => $future, 'amount_paid' => 40, 'total_amount' => 100,
    'client_id' => 0, 'agency_id' => 0,
];
tap_t_assert(true === TAP_Booking::can_cancel_booking($cancel_booking), 'confirmed future booking can be cancelled');
$cancel_booking->status = 'cancelled';
tap_t_assert(false === TAP_Booking::can_cancel_booking($cancel_booking), 'cancelled booking cannot be cancelled again');
$cancel_booking->status = 'confirmed';
$cancel_booking->check_in = $past;
tap_t_assert(false === TAP_Booking::can_cancel_booking($cancel_booking), 'past booking is past the cancellation cutoff');
tap_t_assert(abs(40.0 - TAP_Booking::amount_already_paid($cancel_booking)) < 0.01, 'amount_already_paid reads the paid portion');
tap_t_assert(abs(60.0 - TAP_Booking::remaining_amount($cancel_booking)) < 0.01, 'remaining_amount is total minus paid');

$other_user = wp_insert_user([
    'user_login' => 'tap_ms_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_ms_' . $sfx . '@example.test', 'role' => 'subscriber',
]);
if (!is_wp_error($other_user)) {
    $cleanup_users[] = (int) $other_user;
    $owned = (object) ['client_id' => (int) $other_user, 'agency_id' => 0];
    tap_t_assert(true === TAP_Booking::user_can_manage_booking((int) $other_user, $owned), 'booking owner can manage it');
    tap_t_assert(false === TAP_Booking::user_can_manage_booking(0, $owned), 'anonymous user cannot manage it');
}

/* --- cleanup ----------------------------------------------------------------- */
tap_t_cleanup_bookings($cleanup_bookings);
foreach ($cleanup_users as $uid) { wp_delete_user($uid); }
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
wp_set_current_user($orig_uid);
tap_t_finish();
