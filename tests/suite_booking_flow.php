<?php
/**
 * suite_booking_flow.php — Fase C: full booking lifecycle.
 * create -> confirm -> pay -> completed, plus cancel/refund, stale-cancel
 * cron and the booking fee engine. Site-agnostic (discovered service + user).
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$booking_ids = [];
$uid = tap_t_make_subscriber();
tap_t_assert($uid > 0, 'created throwaway client user');

list($service_id, $service_type) = tap_t_find_service(true);
tap_t_assert($service_id > 0 && $service_type !== '', "discovered a published service (id={$service_id}, {$service_type})");

$in  = gmdate('Y-m-d', strtotime('+18 days'));
$out = gmdate('Y-m-d', strtotime('+21 days'));

// 1) create -> pending -> confirm -> pay -> completed ------------------------
wp_set_current_user($uid);
$res = TAP_Booking::create([
    'service_type' => $service_type,
    'service_id'   => $service_id,
    'check_in'     => $in,
    'check_out'    => $out,
    'adults'       => 2,
    'children'     => 0,
    'total_amount' => 120,
    'notes'        => 'fase c flow e2e',
]);
wp_set_current_user(0);
tap_t_assert(!is_wp_error($res) && !empty($res['booking_code']), 'create() returned booking code');
$idA = (int) $res['booking_id'];
$booking_ids[] = $idA;
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $idA));
tap_t_assert($row !== null && in_array($row->status, ['pending', 'confirmed'], true), 'booking starts pending (or auto-confirmed)');
tap_t_assert($row !== null && (int) $row->client_id === $uid, 'booking stored for current user');

// get_booking() + get_booking_by_code() roundtrip
tap_t_assert((int) TAP_Booking::get_booking($idA)->id === $idA, 'get_booking returns the row');
$by_code = TAP_Booking::get_booking_by_code($res['booking_code']);
tap_t_assert($by_code !== null && (int) $by_code->id === $idA, 'get_booking_by_code roundtrips');

// confirm
tap_t_assert(true === TAP_Booking::update_status($idA, 'confirmed'), 'update_status(confirmed) ok');
tap_t_assert('confirmed' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id=%d", $idA)), 'status persisted as confirmed');

// invalid transitions rejected
$bad = TAP_Booking::update_status($idA, 'exploded');
tap_t_assert(is_wp_error($bad) && $bad->get_error_code() === 'invalid_status', 'invalid status rejected');

// pay
tap_t_assert(true === TAP_Booking::update_payment_status($idA, 'paid'), 'update_payment_status(paid) ok');
tap_t_assert('paid' === $wpdb->get_var($wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}tap_bookings WHERE id=%d", $idA)), 'payment_status persisted as paid');

// complete
tap_t_assert(true === TAP_Booking::update_status($idA, 'completed'), 'update_status(completed) ok');

// 2) cancel on a paid booking -> cancelled + refunded -------------------------
wp_set_current_user($uid);
$resB = TAP_Booking::create([
    'service_type' => $service_type,
    'service_id'   => $service_id,
    'check_in'     => $in,
    'check_out'    => $out,
    'adults'       => 1,
    'total_amount' => 80,
]);
tap_t_assert(!is_wp_error($resB) && !empty($resB['booking_id']), 'second booking created for cancel flow');
$idB = (int) $resB['booking_id'];
$booking_ids[] = $idB;
tap_t_assert(true === TAP_Booking::update_payment_status($idB, 'paid'), 'second booking marked paid');
tap_t_assert(true === TAP_Booking::client_cancel_request($idB, $uid), 'client cancel ok on paid booking');
wp_set_current_user(0);
$cancel_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id=%d", $idB));
tap_t_assert($cancel_row && $cancel_row->status === 'cancelled' && $cancel_row->payment_status === 'refunded', 'cancel sets cancelled + refunded');
tap_t_assert($cancel_row && $cancel_row->cancelled_by === 'client' && $cancel_row->cancel_requested_at, 'cancel records cancelled_by/at');
$again = TAP_Booking::client_cancel_request($idB, $uid);
wp_set_current_user(0);
tap_t_assert(is_wp_error($again) && $again->get_error_code() === 'bad_status', 'already-cancelled booking cannot be cancelled again');

// 3) cancel_stale_bookings() cancels only old pending rows --------------------
$old_hours = get_option('tap_stale_booking_hours', 24);
update_option('tap_stale_booking_hours', 2);
$stale = $wpdb->insert($wpdb->prefix . 'tap_bookings', [
    'booking_code'      => 'STALE-' . wp_generate_password(6, false),
    'agency_id'         => tap_t_test_agency(),
    'client_id'         => $uid,
    'service_type'      => $service_type,
    'service_id'        => $service_id,
    'check_in'          => $in,
    'check_out'         => $out,
    'adults'            => 1,
    'total_amount'      => 40,
    'commission_amount' => 4,
    'commission_percent'=> 10,
    'status'            => 'pending',
    'created_at'        => gmdate('Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS),
]);
$stale_id = (int) $wpdb->insert_id;
$booking_ids[] = $stale_id;
$fresh = $wpdb->insert($wpdb->prefix . 'tap_bookings', [
    'booking_code'      => 'FRESH-' . wp_generate_password(6, false),
    'agency_id'         => tap_t_test_agency(),
    'client_id'         => $uid,
    'service_type'      => $service_type,
    'service_id'        => $service_id,
    'check_in'          => $in,
    'check_out'         => $out,
    'adults'            => 1,
    'total_amount'      => 40,
    'commission_amount' => 4,
    'commission_percent'=> 10,
    'status'            => 'pending',
    'created_at'        => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS),
]);
$fresh_id = (int) $wpdb->insert_id;
$booking_ids[] = $fresh_id;
TAP_Booking::cancel_stale_bookings();
tap_t_assert('cancelled' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id=%d", $stale_id)), 'stale pending booking cancelled');
tap_t_assert('pending' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id=%d", $fresh_id)), 'recent pending booking kept');
update_option('tap_stale_booking_hours', $old_hours);

// 4) booking fee: none / fixed / percent --------------------------------------
$orig_type  = get_option('tap_booking_fee_type', 'none');
$orig_value = get_option('tap_booking_fee_value', 0);
update_option('tap_booking_fee_type', 'none');
update_option('tap_booking_fee_value', 5);
tap_t_assert(TAP_Booking::get_booking_fee(120) === 0.0, 'fee none -> 0');
update_option('tap_booking_fee_type', 'fixed');
tap_t_assert(TAP_Booking::get_booking_fee(120) === 5.0, 'fee fixed 5 -> 5');
update_option('tap_booking_fee_type', 'percent');
update_option('tap_booking_fee_value', 10);
tap_t_assert(TAP_Booking::get_booking_fee(120) === 12.0, 'fee percent 10 -> 12');
update_option('tap_booking_fee_type', $orig_type);
update_option('tap_booking_fee_value', $orig_value);

tap_t_cleanup_bookings($booking_ids);
wp_delete_user($uid);
tap_t_finish();