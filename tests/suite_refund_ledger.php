<?php
/**
 * suite_refund_ledger.php — multiple partial refunds must accumulate, and a
 * redelivered webhook must never subtract the same refund twice.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admins) { wp_set_current_user((int) $admins[0]); }

$booking_id = tap_t_seed_booking([
    'booking_code'   => 'REFLEDGER-' . $sfx,
    'service_type'   => 'tap_tour',
    'service_id'     => 75,
    'total_amount'   => 100,
    'amount_paid'    => 100,
    'payment_status' => 'paid',
    'status'         => 'confirmed',
]);
$order_id = 'ORD-REF-' . $sfx;
TAP_Payment::record_order($order_id, 'booking', $booking_id, 100, 'completed', 'CAP-REF-' . $sfx);

$handler = new ReflectionMethod('TAP_Payment', 'handle_capture_refunded');
$handler->setAccessible(true);
$event = function ($refund_id, $amount) use ($order_id) {
    return (object) ['resource' => (object) [
        'id' => $refund_id,
        'amount' => (object) ['value' => (string) $amount, 'currency_code' => 'USD'],
        'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $order_id]],
    ]];
};
$fresh = function () use ($booking_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        'SELECT refund_amount, amount_paid, payment_status FROM ' . $wpdb->prefix . 'tap_bookings WHERE id = %d',
        $booking_id
    ));
};

$handler->invoke(null, $event('REF-A-' . $sfx, 30));
$row = $fresh();
tap_t_assert(abs(30.0 - (float) $row->refund_amount) < 0.01 && abs(70.0 - (float) $row->amount_paid) < 0.01,
    'first partial refund reduces the balance');

$handler->invoke(null, $event('REF-B-' . $sfx, 20));
$row = $fresh();
tap_t_assert(abs(50.0 - (float) $row->refund_amount) < 0.01 && abs(50.0 - (float) $row->amount_paid) < 0.01,
    'second partial refund accumulates instead of overwriting the first');

// Redelivery of the FIRST refund must be ignored even though another refund
// came after it — the old "compare with last id" check failed exactly here.
$handler->invoke(null, $event('REF-A-' . $sfx, 30));
$row = $fresh();
tap_t_assert(abs(50.0 - (float) $row->refund_amount) < 0.01, 'redelivered refund is not subtracted twice');

$handler->invoke(null, $event('REF-C-' . $sfx, 50));
$row = $fresh();
tap_t_assert(abs(100.0 - (float) $row->refund_amount) < 0.01 && abs(0.0 - (float) $row->amount_paid) < 0.01,
    'cumulative full refund empties the balance');
tap_t_assert('refunded' === $row->payment_status, 'booking closes once all money is back');
$seen = (array) get_post_meta($booking_id, '_tap_paypal_refund_seen');
tap_t_assert(3 === count($seen), 'all three refund ids are recorded in the ledger');

/* --- overpayment is flagged instead of absorbed ------------------------------ */
$over_booking = tap_t_seed_booking([
    'booking_code'   => 'OVERPAY-' . $sfx,
    'service_type'   => 'tap_tour',
    'service_id'     => 75,
    'total_amount'   => 100,
    'payment_status' => 'pending',
    'status'         => 'confirmed',
]);
$over_details = (object) ['amount' => (object) ['value' => '120.00', 'currency_code' => 'USD']];
tap_t_assert(true === TAP_Booking::record_paid_capture($over_booking, 'paypal', 'CAP-OVER-' . $sfx, $over_details),
    'overpaid capture is recorded as paid');
$over_row = $fresh_for = null;
global $wpdb;
$over_row = $wpdb->get_row($wpdb->prepare('SELECT amount_paid, payment_status FROM ' . $wpdb->prefix . 'tap_bookings WHERE id = %d', $over_booking));
tap_t_assert(abs(100.0 - (float) $over_row->amount_paid) < 0.01, 'ledger keeps only the agreed amount');
$over = json_decode((string) get_post_meta($over_booking, '_tap_overpayment', true), true);
tap_t_assert(is_array($over) && abs(20.0 - (float) ($over['surplus'] ?? 0)) < 0.01, 'surplus is recorded for manual refund');

/* --- cleanup ----------------------------------------------------------------- */
delete_post_meta($booking_id, '_tap_paypal_refund_seen');
$wpdb->delete(TAP_Payment::orders_table(), ['paypal_order_id' => $order_id]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $booking_id]);
delete_post_meta($over_booking, '_tap_overpayment');
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $over_booking]);
wp_set_current_user($orig_uid);
tap_t_finish();
