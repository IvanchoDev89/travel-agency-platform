<?php
/**
 * suite_price_authority.php — T8 server-side price authority.
 * Verifies TAP_Booking::create() always derives totals from the catalogue price
 * and booking fee (never trusting client input), that authentication-bearing
 * identity fields cannot be spoofed, and that the REST /booking endpoint
 * inherits the same authoritative pricing.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

$from = tap_t_find_service(true);
list($fid, $ftype) = $from;
tap_t_assert($fid > 0 && $ftype !== '', "discovered published service ({$ftype} id={$fid})");

$in  = gmdate('Y-m-d', strtotime('+40 days'));
$out = gmdate('Y-m-d', strtotime('+43 days'));

$authoritative = TAP_Booking::calculate_price($ftype, $fid, $in, $out, 2, 0, 0);
$authoritative = round($authoritative, 2);
$fee           = TAP_Booking::get_booking_fee($authoritative);
$expected_total = round($authoritative + $fee, 2);
tap_t_assert($authoritative > 0, 'service has a server-side price to assert against');

$created = [];

// ---- 1) forged total_amount / booking_fee are ignored by create() ----
$data = [
    'service_type'  => $ftype,
    'service_id'    => $fid,
    'check_in'      => $in,
    'check_out'     => $out,
    'adults'        => 2,
    'children'      => 0,
    'total_amount'  => 0.01,
    'booking_fee'   => 999,
    'guest_name'    => 'Forger',
    'guest_email'   => 't8_forge_' . wp_generate_password(6, false) . '@example.test',
];
$res = TAP_Booking::create($data);
tap_t_assert(true !== is_wp_error($res) && !empty($res['booking_id']), 'booking created while passing forged price fields');
$bid = (int) $res['booking_id'];
$created[] = $bid;

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $bid));
tap_t_assert($row !== null, 'booking row persisted');
tap_t_assert($row !== null && abs(((float) $row->total_amount - (float) $row->booking_fee) - $authoritative) < 0.001, 'subtotal derived from catalogue price, not forged input');
tap_t_assert($row !== null && abs((float) $row->booking_fee - $fee) < 0.001, 'booking fee derived server-side, forged 999 ignored');
tap_t_assert($row !== null && abs((float) $row->total_amount - $expected_total) < 0.001, 'total = authoritative subtotal + fee');
$expected_comm = round($authoritative * (floatval($row->commission_percent ?? 0) / 100), 2);
tap_t_assert($row !== null && abs((float) $row->commission_amount - $expected_comm) < 0.001, 'commission computed on authoritative subtotal');

// ---- 2) client_id cannot be spoofed by a logged-in caller ----
$creator = tap_t_make_subscriber();
$victim  = tap_t_make_subscriber();
tap_t_assert($creator > 0 && $victim > 0 && $creator !== $victim, 'creator + victim users available');

wp_set_current_user($creator);
$data2 = $data + ['client_id' => $victim];
$res2  = TAP_Booking::create($data2);
wp_set_current_user(0);
tap_t_assert(true !== is_wp_error($res2), 'logged-in booking created with a forged client_id param');
$bid2 = (int) $res2['booking_id'];
$created[] = $bid2;
$row2 = $wpdb->get_row($wpdb->prepare("SELECT client_id, guest_email FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $bid2));
tap_t_assert($row2 !== null && (int) $row2->client_id === $creator, 'client_id stays the authenticated creator (spoof rejected)');

// ---- 3) guest create() still lands on client_id = 0 (logged-out path) ----
wp_set_current_user(0);
$data3 = [
    'service_type'  => $ftype,
    'service_id'    => $fid,
    'check_in'      => $in,
    'check_out'     => $out,
    'adults'        => 1,
    'children'      => 0,
    'client_id'     => $victim,
    'guest_name'    => 'Guest T8',
    'guest_email'   => 't8_guest_' . wp_generate_password(6, false) . '@example.test',
];
$res3 = TAP_Booking::create($data3);
tap_t_assert(true !== is_wp_error($res3), 'guest booking created');
$bid3 = (int) $res3['booking_id'];
$created[] = $bid3;
$row3 = $wpdb->get_row($wpdb->prepare("SELECT client_id FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $bid3));
tap_t_assert($row3 !== null && (int) $row3->client_id === 0, 'logged-out guest booking always stored with client_id = 0');

// ---- 4) REST /booking inherits authoritative pricing ----
$uid = tap_t_make_subscriber();
wp_set_current_user($uid);
$rest = tap_t_rest('POST', '/tap/v1/booking', [
    'service_type'    => $ftype,
    'service_id'      => $fid,
    'check_in'        => $in,
    'check_out'       => $out,
    'adults'          => 2,
    'children'        => 0,
    'total_amount'    => 0.01,
    'privacy_consent' => 1,
]);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($rest) === '', 'REST booking accepted (total_amount no longer required)');
$rest_data = tap_t_rest_data($rest);
$rid = (int) ($rest_data['booking_id'] ?? 0);
tap_t_assert($rid > 0, 'REST booking returned an id');
if ($rid) $created[] = $rid;
$rrow = $rid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $rid)) : null;
tap_t_assert($rrow !== null && abs((float) $rrow->total_amount - $expected_total) < 0.001, 'REST stored total equals server-computed price');

// ---- 5) REST still enforces privacy consent ----
wp_set_current_user($uid);
$noconsent = tap_t_rest('POST', '/tap/v1/booking', [
    'service_type' => $ftype,
    'service_id'   => $fid,
    'total_amount' => 0.01,
]);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($noconsent) === 'consent_required', 'REST booking without consent rejected');

// cleanup --------------------------------------------------------------------
tap_t_cleanup_bookings($created);
foreach (array_unique(array_filter([$creator, $victim, $uid])) as $uid_del) {
    wp_delete_user($uid_del);
}
tap_t_finish();