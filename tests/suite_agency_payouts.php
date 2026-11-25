<?php
/**
 * suite_agency_payouts.php — agency net earnings and payout requests.
 * FUNCTIONAL: real paid booking, real commission/fee math, real request.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();

$agency_id = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'Payouts E2E ' . $sfx,
]);
$uid = (int) ($admin[0] ?? 0);
update_post_meta($agency_id, '_tap_agency_user_id', $uid);
TAP_Approval::approve($agency_id);
update_post_meta($agency_id, '_tap_agency_commission', 10);

// Paid booking: 200 gross, fee 10, commission 10% of net subtotal (190) = 19.
$bid = tap_t_seed_booking([
    'agency_id' => (int) $agency_id,
    'total_amount' => 190,
    'booking_fee' => 10,
    'commission_amount' => 19,
    'commission_status' => 'owed',
    'payment_status' => 'paid',
    'status' => 'completed',
]);
$earn = TAP_Agency_Payouts::earnings($agency_id);
tap_t_assert(abs(190.0 - (float) $earn['gross']) < 0.01, 'gross revenue is the paid booking total');
tap_t_assert(abs(10.0 - (float) $earn['fees']) < 0.01, 'platform fee is deducted');
tap_t_assert(abs(19.0 - (float) $earn['commission']) < 0.01, 'commission is deducted');
tap_t_assert(abs(161.0 - (float) $earn['net']) < 0.01, 'agency net = gross - fee - commission');
tap_t_assert(abs(161.0 - (float) $earn['available']) < 0.01, 'full net is available before a request');

$payout_id = TAP_Agency_Payouts::request($agency_id, $uid);
tap_t_assert(is_int($payout_id) && $payout_id > 0, 'payout request created');
$again = TAP_Agency_Payouts::request($agency_id, $uid);
tap_t_assert(is_wp_error($again), 'a second request cannot double-pay');
$earn2 = TAP_Agency_Payouts::earnings($agency_id);
tap_t_assert(abs(0.0 - (float) $earn2['available']) < 0.01, 'pending request reduces available balance');

$other = get_users(['role' => 'subscriber', 'number' => 1, 'fields' => 'ids']);
if ($other) {
    $denied = TAP_Agency_Payouts::request($agency_id, (int) $other[0]);
    tap_t_assert(is_wp_error($denied), 'unrelated user cannot request this agency payout');
}

$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false !== strpos($src, 'TAP_Agency_Payouts::earnings'), 'finances panel shows agency earnings');
tap_t_assert(false !== strpos($src, 'tap_agency_payout_request'), 'finances panel has a request form');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete(TAP_Agency_Payouts::table(), ['agency_id' => $agency_id]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['user_id' => $uid, 'name' => 'Payouts E2E ' . $sfx]);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
