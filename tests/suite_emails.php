<?php
/**
 * suite_emails.php — TAP_Emails: localized status/payment labels, event wiring
 * and the notification handlers (booking cancelled, payment approved, payout
 * requested, refund retried) end-to-end through wp_mail interception.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_emails.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$p = $wpdb->prefix;

/* 1) localized label maps ---------------------------------------------------- */
$status = TAP_Emails::status_labels();
tap_t_assert(is_array($status) && count($status) === 6, 'status_labels() returns 6 statuses');
tap_t_assert($status['pending'] === 'Pendiente' && $status['confirmed'] === 'Confirmada', 'status_labels() source strings are Spanish');
tap_t_assert(TAP_Emails::status_label('confirmed') === 'Confirmada', 'status_label() resolves known status');
tap_t_assert(TAP_Emails::status_label('bogus_status') === 'bogus_status', 'status_label() falls back to raw value');

$payment = TAP_Emails::payment_labels();
tap_t_assert(is_array($payment) && count($payment) === 5, 'payment_labels() returns 5 payment states');
tap_t_assert(TAP_Emails::payment_label('refunded') === 'Reembolsada', 'payment_label() resolves known state');
tap_t_assert(TAP_Emails::payment_label('unknown') === 'unknown', 'payment_label() falls back to raw value');
tap_t_assert(!defined('TAP_Emails::STATUS_LABELS'), 'legacy STATUS_LABELS const removed (callers migrated)');

/* 2) handler wiring ---------------------------------------------------------- */
tap_t_assert((bool) has_action('tap_booking_cancelled', ['TAP_Emails', 'on_booking_cancelled']), 'tap_booking_cancelled → on_booking_cancelled');
tap_t_assert((bool) has_action('tap_payment_approved', ['TAP_Emails', 'on_payment_approved']), 'tap_payment_approved → on_payment_approved');
tap_t_assert((bool) has_action('tap_payout_requested', ['TAP_Emails', 'on_payout_requested']), 'tap_payout_requested → on_payout_requested');
tap_t_assert((bool) has_action('tap_booking_refund_retried', ['TAP_Emails', 'on_booking_refund_retried']), 'tap_booking_refund_retried → on_booking_refund_retried');

/* 3) capture outbound mail --------------------------------------------------- */
$mails = [];
add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) {
    $mails[] = [
        'to'      => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'],
        'subject' => isset($atts['subject']) ? (string) $atts['subject'] : '',
    ];
    return true;
}, 10, 2);

/* 4) fixtures: throwaway agency + booking ------------------------------------ */
$agency_id = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'TAP E2E Emails Agency ' . tap_t_suffix(),
]);
update_post_meta($agency_id, '_tap_agency_email', 'e2e-agency@example.test');

$booking_id = tap_t_seed_booking([
    'agency_id'     => (int) $agency_id,
    'guest_email'   => 'e2e-guest@example.test',
    'guest_name'    => 'Huésped E2E',
    'payment_status'=> 'pending',
    'status'        => 'confirmed',
]);

/* 5) booking cancelled: client + agency -------------------------------------- */
$mails = [];
do_action('tap_booking_cancelled', $booking_id, null);
tap_t_assert(count($mails) === 2, 'booking cancelled emails client and agency (got ' . count($mails) . ')');
$recips = array_column($mails, 'to');
tap_t_assert(in_array('e2e-guest@example.test', $recips, true), 'client notified at guest email');
tap_t_assert(in_array('e2e-agency@example.test', $recips, true), 'agency notified at agency email');
tap_t_assert(strpos($mails[0]['subject'], 'cancelad') !== false || strpos($mails[1]['subject'], 'cancelad') !== false, 'cancellation subject mentions cancellation');

/* 6) payment approved: agency only ------------------------------------------- */
$mails = [];
do_action('tap_payment_approved', $booking_id, 'paypal', 'E2E-TXN');
tap_t_assert(count($mails) === 1 && $mails[0]['to'] === 'e2e-agency@example.test', 'payment approved notifies the agency');
tap_t_assert(strpos($mails[0]['subject'], 'Pago aprobado') !== false || strpos($mails[0]['subject'], 'reserva') !== false, 'payment subject references the booking');

/* 7) refund retried: admin ---------------------------------------------------- */
$mails = [];
do_action('tap_booking_refund_retried', $booking_id);
tap_t_assert(count($mails) === 1 && $mails[0]['to'] === get_option('admin_email'), 'refund retried notifies the admin');
tap_t_assert(strpos($mails[0]['subject'], 'Reserva') !== false, 'refund retried subject references the booking');

/* 8) payout requested: admin -------------------------------------------------- */
$payment_id = (int) $wpdb->insert($p . 'tap_commission_payments', [
    'agency_id'  => (int) $agency_id,
    'amount'     => 55.50,
    'booking_ids'=> (string) $booking_id,
    'status'     => 'pending',
    'created_at' => current_time('mysql'),
]);
tap_t_assert($payment_id > 0, 'commission payment fixture inserted');
$mails = [];
do_action('tap_payout_requested', $payment_id, (int) $agency_id, 55.50);
tap_t_assert(count($mails) === 1 && $mails[0]['to'] === get_option('admin_email'), 'payout request notifies the admin');

/* 9) missing booking is a no-op ---------------------------------------------- */
$mails = [];
do_action('tap_booking_cancelled', 999999999, null);
do_action('tap_payment_approved', 999999999, 'paypal', '');
tap_t_assert(count($mails) === 0, 'unknown booking sends no mail');

/* cleanup -------------------------------------------------------------------- */
tap_t_cleanup_bookings([$booking_id]);
$wpdb->delete($p . 'tap_commission_payments', ['id' => $payment_id]);
wp_delete_post((int) $agency_id, true);
remove_all_filters('pre_wp_mail');

tap_t_finish();
