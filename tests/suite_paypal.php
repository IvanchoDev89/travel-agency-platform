<?php
/**
 * suite_paypal.php — Fase C: PayPal gateway against a mocked HTTP transport.
 * Exercises create_order, create_order_generic (record_order), capture_order,
 * refund_capture and verify_webhook with pre_http_request responses. The mock
 * also records request bodies so we can assert the currency sent to PayPal.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_client_id  = get_option('tap_paypal_client_id', '');
$orig_secret     = get_option('tap_paypal_secret', '');
$orig_sandbox    = get_option('tap_paypal_sandbox', '1');
$orig_currency   = get_option('tap_currency', 'USD');
tap_t_guard_paypal_credentials();
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
update_option('tap_paypal_sandbox', '1');
update_option('tap_currency', 'USD');

tap_t_install_paypal_mock();
tap_t_assert(TAP_PayPal::is_ready(), 'paypal ready with configured credentials');
tap_t_assert(TAP_PayPal::is_sandbox(), 'paypal in sandbox mode');

$booking_ids = [];
list($service_id, $service_type) = tap_t_find_service(true);
tap_t_assert($service_id > 0, 'discovered service to pay for');

// 1) create_order for a booking -------------------------------------------------
$payer = tap_t_make_subscriber();
tap_t_assert($payer > 0, 'created payer user');
wp_set_current_user($payer);
$res = TAP_Booking::create([
    'service_type' => $service_type,
    'service_id'   => $service_id,
    'check_in'     => gmdate('Y-m-d', strtotime('+40 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+42 days')),
    'adults'       => 2,
    'total_amount' => 99,
]);
wp_set_current_user(0);
tap_t_assert(!is_wp_error($res), 'booking created for paypal order');
$bid = (int) $res['booking_id'];
$booking_ids[] = $bid;

$order = TAP_PayPal::create_order($bid, home_url('/checkout'), home_url('/'));
tap_t_assert(is_array($order) && $order['order_id'] === 'ORD-PP-1', 'create_order returns mock order id');
tap_t_assert($order['approval_url'] === 'https://pp.test/approve', 'create_order extracts payer-action URL');
tap_t_assert(get_post_meta($bid, '_tap_paypal_order_id', true) === 'ORD-PP-1', 'paypal order id persisted on booking');

// currency sent to PayPal follows the service's currency meta
$prefix = class_exists('TAP_Post_Types') ? TAP_Post_Types::meta_prefix($service_type) : '';
if ($prefix) { update_post_meta($service_id, '_tap_' . $prefix . '_currency', 'EUR'); }
$order_eur = TAP_PayPal::create_order($bid, home_url('/checkout'), home_url('/'));
tap_t_assert(is_array($order_eur), 'create_order works with EUR currency too');
$last_create = null;
foreach ($GLOBALS['tap_pp_log'] as $entry) {
    if (strpos($entry['url'], '/v2/checkout/orders') !== false && strpos($entry['url'], '/capture') === false) {
        $last_create = json_decode($entry['args']['body'], true);
    }
}
$sent_currency = $last_create['purchase_units'][0]['amount']['currency_code'] ?? '';
tap_t_assert($sent_currency === 'EUR', 'paypal order body uses configured currency (EUR)');
tap_t_assert($sent_currency !== 'USD', 'currency is not hardcoded');
if ($prefix) { update_post_meta($service_id, '_tap_' . $prefix . '_currency', 'USD'); }

// 2) create_order_generic records a payment order ------------------------------
$generic = TAP_PayPal::create_order_generic(15, 'Sub e2e', 'REF-SUB-1', 'subscription', 987);
tap_t_assert(is_array($generic) && strpos((string) $generic['order_id'], 'ORD-PP-') === 0, 'generic order created');
$ord_row = TAP_Payment::resolve_order($generic['order_id']);
tap_t_assert($ord_row && $ord_row->object_type === 'subscription' && (int) $ord_row->object_id === 987, 'record_order tracked the subscription payment');
tap_t_assert($ord_row && abs((float) $ord_row->amount - 15.0) < 0.001 && $ord_row->status === 'created', 'order amount/status persisted');

// 3) capture_order ---------------------------------------------------------------
$cap = TAP_PayPal::capture_order('ORD-PP-1');
tap_t_assert(is_array($cap) && $cap['status'] === 'COMPLETED', 'capture completes');
tap_t_assert($cap['capture_id'] === 'CAP-PP-1' && $cap['capture_status'] === 'COMPLETED', 'capture ids returned');

// 4) refund_capture ----------------------------------------------------------------
$ref = TAP_PayPal::refund_capture('CAP-PP-1', 10);
tap_t_assert(is_array($ref) && $ref['refund_id'] === 'REF-PP-1' && $ref['status'] === 'COMPLETED', 'refund performed');
tap_t_assert($ref['amount'] === '10.00', 'refund amount returned');

// 5) verify_webhook -----------------------------------------------------------------
$headers = [
    'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://pp.test/cert',
    'PAYPAL-TRANSMISSION-ID' => 'T1', 'PAYPAL-TRANSMISSION-SIG' => 'sig',
    'PAYPAL-TRANSMISSION-TIME' => '2026-11-26T00:00:00Z',
];
update_option('tap_paypal_webhook_id', 'WEBHOOK-E2E');
$raw_event = '{"id":"WH-E2E","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-E2E"}}';
tap_t_assert(true === TAP_PayPal::verify_webhook($headers, $raw_event), 'raw webhook event verified');
$verify = null;
foreach ($GLOBALS['tap_pp_log'] as $entry) {
    if (strpos($entry['url'], '/verify-webhook-signature') !== false) { $verify = $entry; }
}
tap_t_assert(null !== $verify, 'verify endpoint called');
tap_t_assert('Bearer tok-test' === ($verify['args']['headers']['Authorization'] ?? ''), 'verification uses the OAuth Bearer token');
$sent = json_decode($verify['args']['body'], true);
tap_t_assert(is_array($sent['webhook_event'] ?? null), 'webhook_event is sent as an object, not a JSON string');
tap_t_assert('WH-E2E' === ($sent['webhook_event']['id'] ?? ''), 'webhook_event keeps the original event');
tap_t_assert('WEBHOOK-E2E' === ($sent['webhook_id'] ?? ''), 'configured webhook id is forwarded');
// PayPal signs the exact bytes: a trailing newline must survive verbatim.
$raw_bytes = $raw_event . "\n";
tap_t_assert(true === TAP_PayPal::verify_webhook($headers, $raw_bytes), 'event with trailing whitespace is accepted');
$last_body = null;
foreach ($GLOBALS['tap_pp_log'] as $entry) {
    if (strpos($entry['url'], '/verify-webhook-signature') !== false) { $last_body = $entry['args']['body']; }
}
tap_t_assert(false !== strpos((string) $last_body, $raw_bytes), 'raw event bytes are forwarded without trimming');
tap_t_assert(false === TAP_PayPal::verify_webhook($headers, 'not json'), 'malformed event rejected without an HTTP call');
delete_option('tap_paypal_webhook_id');

tap_t_remove_paypal_mock();

// restore options + cleanup
update_option('tap_paypal_client_id', $orig_client_id);
update_option('tap_paypal_secret', $orig_secret);
update_option('tap_paypal_sandbox', $orig_sandbox);
update_option('tap_currency', $orig_currency);
$wpdb->delete(TAP_Payment::orders_table(), ['object_type' => 'booking']);
$wpdb->delete(TAP_Payment::orders_table(), ['paypal_order_id' => 'ORD-PP-3']);
tap_t_cleanup_bookings($booking_ids);
wp_delete_user($payer);
tap_t_finish();