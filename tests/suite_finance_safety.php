<?php
/**
 * suite_finance_safety.php — Fase A/C financial safety regressions.
 * FUNCTIONAL: refund currency, offline settlement, payout reconciliation,
 * amount mismatches, processor fees, checkout currency and credit notes.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$admin_id = (int) ($admins[0] ?? 0);
if ($admin_id) { wp_set_current_user($admin_id); }
$cleanup_bookings = [];
$cleanup_posts = [];

/* --- 1) refunds use the booking currency, not the language currency ------------ */
$orig_currency   = get_option('tap_currency', 'USD');
$orig_currency_en= get_option('tap_currency_en', 'USD');
$orig_client     = get_option('tap_paypal_client_id', '');
$orig_secret     = get_option('tap_paypal_secret', '');
tap_t_guard_paypal_credentials();
update_option('tap_currency', 'USD');
update_option('tap_currency_en', 'USD');
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
$_GET['lang'] = 'en';
tap_t_assert('USD' === TAP_Currency::checkout_code() && 'USD' === TAP_Currency::code(), 'baseline currency setup');
update_option('tap_currency_en', 'CRC');

$fx_booking = tap_t_seed_booking([
    'booking_code'   => 'FXREF-' . $sfx,
    'service_type'   => 'tap_tour', 'service_id' => 75,
    'currency'       => 'EUR', 'total_amount' => 100,
    'amount_paid'    => 100, 'payment_status' => 'paid', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $fx_booking;
update_post_meta($fx_booking, '_tap_paypal_capture_id', 'CAP-FX-' . $sfx);
tap_t_install_paypal_mock();
$fx_res = TAP_Refunds::execute_refund($fx_booking, null, 25);
tap_t_assert(!is_wp_error($fx_res), 'EUR refund executed');
$refund_body = null;
foreach ((array) ($GLOBALS['tap_pp_log'] ?? []) as $entry) {
    if (preg_match('#/refund$#', (string) $entry['url'])) { $refund_body = json_decode($entry['args']['body'], true); }
}
tap_t_assert('EUR' === ($refund_body['amount']['currency_code'] ?? ''), 'refund uses booking currency despite ?lang=en');
tap_t_remove_paypal_mock();
unset($_GET['lang']);
update_option('tap_currency', $orig_currency);
update_option('tap_currency_en', $orig_currency_en);
update_option('tap_paypal_client_id', $orig_client);
update_option('tap_paypal_secret', $orig_secret);

/* --- 2) partial online payments cannot be clicked into "fully paid" ------------ */
$partial_booking = tap_t_seed_booking([
    'booking_code'   => 'PARTPAY-' . $sfx,
    'service_type'   => 'tap_tour', 'service_id' => 75,
    'total_amount'   => 100, 'amount_paid' => 30,
    'payment_status' => 'partial', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $partial_booking;
update_post_meta($partial_booking, '_tap_paypal_capture_id', 'CAP-PART-' . $sfx);
$blocked = TAP_Booking::agency_booking_action($partial_booking, 'mark_paid', $admin_id, '');
tap_t_assert(is_wp_error($blocked) && 'partial_online_payment' === $blocked->get_error_code(),
    'mark_paid refuses a partially captured online payment');
$settled = TAP_Booking::agency_booking_action($partial_booking, 'settle_offline', $admin_id, 'Transferencia ' . $sfx);
tap_t_assert(true === $settled, 'offline settlement with evidence succeeds');
$row = TAP_Booking::get_booking($partial_booking);
tap_t_assert(abs(100.0 - (float) $row->amount_paid) < 0.01 && 'paid' === $row->payment_status, 'offline settlement fills only the outstanding balance');
$evidence = json_decode((string) get_post_meta($partial_booking, '_tap_offline_settlement', true), true);
tap_t_assert(is_array($evidence) && abs(70.0 - (float) ($evidence['added'] ?? 0)) < 0.01, 'offline settlement keeps the evidence');
$no_reason = tap_t_seed_booking([
    'booking_code' => 'NOREASON-' . $sfx, 'service_type' => 'tap_tour', 'service_id' => 75,
    'total_amount' => 10, 'payment_status' => 'pending', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $no_reason;
tap_t_assert(is_wp_error(TAP_Booking::agency_booking_action($no_reason, 'settle_offline', $admin_id, '')),
    'offline settlement requires a receipt');

/* --- 3) agency payout amount is capped to the balance that really exists ------ */
$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Fin Safety ' . $sfx]);
$cleanup_posts[] = (int) $agency_id;
update_post_meta($agency_id, '_tap_agency_user_id', $admin_id);
TAP_Approval::approve($agency_id);
$svc = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Fin Safety Svc ' . $sfx]);
$cleanup_posts[] = (int) $svc;
update_post_meta($svc, '_tap_tour_agency_id', $agency_id);
$pay_booking = tap_t_seed_booking([
    'booking_code' => 'PAYSAFE-' . $sfx, 'service_type' => 'tap_tour', 'service_id' => (int) $svc,
    'agency_id' => $agency_id, 'total_amount' => 100, 'amount_paid' => 100,
    'commission_amount' => 10, 'commission_percent' => 10, 'commission_status' => 'owed',
    'payment_status' => 'paid', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $pay_booking;
$request = TAP_Agency_Payouts::request($agency_id, $admin_id);
tap_t_assert(!is_wp_error($request) && (int) $request > 0, 'agency payout requested under lock');
$wpdb->update($wpdb->prefix . 'tap_bookings', ['refund_amount' => 30], ['id' => $pay_booking]);
$completed = TAP_Agency_Payouts::complete($request);
tap_t_assert(true === $completed, 'payout completed after partial refund');
$payout = $wpdb->get_row($wpdb->prepare('SELECT amount, status FROM ' . TAP_Agency_Payouts::table() . ' WHERE id = %d', $request));
tap_t_assert($payout && abs(60.0 - (float) $payout->amount) < 0.01, 'payout amount capped to current entitlement');
$wpdb->delete(TAP_Agency_Payouts::table(), ['id' => $request]);

/* --- 4) amount mismatches are flagged, not retried forever -------------------- */
$mismatch_booking = tap_t_seed_booking([
    'booking_code' => 'ONVOMIS-' . $sfx, 'service_type' => 'tap_tour', 'service_id' => 75,
    'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'pending',
]);
$cleanup_bookings[] = $mismatch_booking;
update_post_meta($mismatch_booking, '_tap_onvo_expected_amount', 100);
$onvo = new ReflectionMethod('TAP_Payment', 'handle_onvo_payment_succeeded');
$onvo->setAccessible(true);
$intent = (object) [
    'id' => 'pi-mis-' . $sfx, 'amount' => 7000, 'currency' => 'USD',
    'metadata' => (object) ['booking_id' => $mismatch_booking],
];
tap_t_assert(true === $onvo->invoke(null, $intent), 'mismatched Onvo webhook is acknowledged (no infinite retry)');
$mismatch = json_decode((string) get_post_meta($mismatch_booking, '_tap_payment_mismatch', true), true);
tap_t_assert(is_array($mismatch) && abs(70.0 - (float) ($mismatch['received'] ?? 0)) < 0.01, 'amount mismatch recorded for review');
tap_t_assert('pending' === TAP_Booking::get_booking($mismatch_booking)->payment_status, 'mismatched payment does not mark the booking paid');

/* --- 5) subscription/promo captures verify the amount ------------------------- */
$order_id = 'ORD-MIS-' . $sfx;
TAP_Payment::record_order($order_id, 'subscription', 987654, 10, 'created');
$capture = new ReflectionMethod('TAP_Payment', 'handle_capture_completed');
$capture->setAccessible(true);
$capture->invoke(null, (object) ['resource' => (object) [
    'id' => 'CAP-MIS-' . $sfx,
    'amount' => (object) ['value' => '20.00', 'currency_code' => 'USD'],
    'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $order_id]],
]]);
$order = TAP_Payment::resolve_order($order_id);
tap_t_assert($order && 'mismatch' === $order->status, 'mismatched subscription capture is not activated');
$wpdb->delete(TAP_Payment::orders_table(), ['paypal_order_id' => $order_id]);

/* --- 6) processor fee + checkout currency + credit note ----------------------- */
$breakdown = TAP_Booking::capture_breakdown((object) [
    'purchase_units' => [[ 'payments' => ['captures' => [[
        'seller_receivable_breakdown' => [
            'paypal_fee' => (object) ['value' => '3.50'],
            'net_amount' => (object) ['value' => '96.50'],
        ],
    ]]]]],
]);
tap_t_assert($breakdown && abs(3.5 - (float) $breakdown['fee']) < 0.01, 'PayPal fee extracted from gateway breakdown');
$bk = tap_t_seed_booking([
    'booking_code' => 'FEE-' . $sfx, 'service_type' => 'tap_tour', 'service_id' => 75,
    'total_amount' => 100, 'payment_status' => 'pending', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $bk;
$details = (object) ['amount' => (object) ['value' => '100.00', 'currency_code' => 'USD'],
    'purchase_units' => [[ 'payments' => ['captures' => [[
        'seller_receivable_breakdown' => [
            'paypal_fee' => (object) ['value' => '3.50'],
            'net_amount' => (object) ['value' => '96.50'],
        ],
    ]]]]]];
TAP_Booking::record_paid_capture($bk, 'paypal', 'CAP-FEE-' . $sfx, $details);
tap_t_assert(abs(3.5 - (float) get_post_meta($bk, '_tap_processor_fee', true)) < 0.01, 'processor fee persisted at capture');
$inv_id = TAP_Invoices::issue($bk);
tap_t_assert((int) $inv_id > 0, 'invoice issued for refunded booking');
$wpdb->update($wpdb->prefix . 'tap_bookings', ['refund_amount' => 25], ['id' => $bk]);
$note_id = TAP_Invoices::on_refund($bk, 'paypal', 'REF-CN-' . $sfx, 25);
tap_t_assert((int) $note_id > 0, 'credit note issued for the refund');
tap_t_assert(1 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TAP_Invoices::credit_notes_table() . ' WHERE booking_id = %d', $bk)),
    'duplicate credit note suppressed');
tap_t_assert(false !== strpos(TAP_Invoices::render_html((int) $inv_id), 'Notas de crédito'), 'credit note appears on the invoice');

update_option('tap_currency', 'CRC');
update_option('tap_currency_en', 'USD');
$_GET['lang'] = 'en';
tap_t_assert('CRC' === TAP_Currency::checkout_code() && 'USD' === TAP_Currency::code(), 'checkout currency ignores ?lang=');
unset($_GET['lang']);
update_option('tap_currency', $orig_currency);
update_option('tap_currency_en', $orig_currency_en);

/* --- 7) dispute clawback of an already-paid commission ----------------------- */
$cb_agency = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Clawback ' . $sfx]);
$cleanup_posts[] = (int) $cb_agency;
$cb_booking = tap_t_seed_booking([
    'booking_code'      => 'CLAW-' . $sfx,
    'service_type'      => 'tap_tour', 'service_id' => 75,
    'agency_id'         => $cb_agency, 'total_amount' => 100,
    'commission_amount' => 10, 'commission_status' => 'paid',
    'payment_status'    => 'paid', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $cb_booking;
$dispute_id = TAP_Disputes::open($cb_booking, 0, '', 'service_quality', 'Prueba ' . $sfx);
tap_t_assert((int) $dispute_id > 0, 'dispute opened on the paid booking');
$resolved = TAP_Disputes::resolve($dispute_id, TAP_Disputes::AGAINST_AGENCY, 'Resuelta ' . $sfx);
tap_t_assert(true === $resolved, 'dispute resolved against the agency');
$credit = $wpdb->get_var($wpdb->prepare(
    'SELECT SUM(amount) FROM ' . TAP_Agency_Payouts::table() . ' WHERE agency_id = %d AND note LIKE %s',
    $cb_agency, '%' . $wpdb->esc_like('disputa #' . (int) $dispute_id) . '%'
));
tap_t_assert(abs(-10.0 - (float) $credit) < 0.01, 'paid commission is returned as a negative payout credit');
tap_t_assert(in_array((int) $dispute_id, (array) get_post_meta($cb_booking, '_tap_dispute_clawbacks', true), true), 'clawback is idempotent by dispute id');
tap_t_assert('void' === TAP_Booking::get_booking($cb_booking)->commission_status, 'commission voided after the clawback');
$wpdb->delete($wpdb->prefix . 'tap_disputes', ['booking_id' => $cb_booking]);
$wpdb->delete(TAP_Agency_Payouts::table(), ['agency_id' => $cb_agency]);

/* --- 8) commission is prorated on partial refunds ---------------------------- */
$pr_booking = tap_t_seed_booking([
    'booking_code'      => 'PRORATE-' . $sfx,
    'service_type'      => 'tap_tour', 'service_id' => 75,
    'total_amount'      => 100, 'commission_amount' => 10,
    'commission_status' => 'owed', 'payment_status' => 'paid', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $pr_booking;
TAP_Booking::prorate_commission_after_refund($pr_booking, 30);
tap_t_assert(abs(7.0 - (float) TAP_Booking::get_booking($pr_booking)->commission_amount) < 0.01, '30% refund prorates commission to 7');
TAP_Booking::prorate_commission_after_refund($pr_booking, 50);
tap_t_assert(abs(5.0 - (float) TAP_Booking::get_booking($pr_booking)->commission_amount) < 0.01, 'cumulative 50% refund prorates commission to 5');
TAP_Booking::prorate_commission_after_refund($pr_booking, 50);
tap_t_assert(abs(5.0 - (float) TAP_Booking::get_booking($pr_booking)->commission_amount) < 0.01, 'redelivered refund does not prorate twice');
tap_t_assert(abs(10.0 - (float) get_post_meta($pr_booking, '_tap_commission_original', true)) < 0.01, 'original commission preserved as evidence');

/* --- cleanup ----------------------------------------------------------------- */
foreach ($cleanup_bookings as $bid) {
    delete_post_meta($bid, '_tap_paypal_capture_id');
    delete_post_meta($bid, '_tap_refund_executed_hash');
    delete_post_meta($bid, '_tap_refund_seen_dummy');
    delete_post_meta($bid, '_tap_payment_mismatch');
    delete_post_meta($bid, '_tap_offline_settlement');
    delete_post_meta($bid, '_tap_dispute_clawbacks');
    delete_post_meta($bid, '_tap_commission_original');
    delete_post_meta($bid, '_tap_commission_prorated');
    $wpdb->delete(TAP_Invoices::credit_notes_table(), ['booking_id' => $bid]);
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'tap_invoices WHERE booking_id = %d', $bid)) as $iid) {
        $wpdb->delete($wpdb->prefix . 'tap_invoices', ['id' => $iid]);
    }
    $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
}
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Fin Safety ' . $sfx]);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
