<?php
/**
 * suite_money_ledger.php — immutable money audit trail and invariant checks.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admins) { wp_set_current_user((int) $admins[0]); }
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';

/* --- 1) ledger records real money events -------------------------------------- */
$bid = tap_t_seed_booking([
    'booking_code'   => 'LEDGER-' . $sfx,
    'service_type'   => 'tap_tour', 'service_id' => 75,
    'total_amount'   => 100, 'amount_paid' => 0,
    'payment_status' => 'pending', 'status' => 'confirmed',
]);
$details = (object) ['amount' => (object) ['value' => '100.00', 'currency_code' => 'USD']];
tap_t_assert(true === TAP_Booking::record_paid_capture($bid, 'paypal', 'CAP-LEDGER-' . $sfx, $details), 'capture recorded');
$wpdb->update($wpdb->prefix . 'tap_bookings', ['refund_amount' => 25, 'amount_paid' => 75], ['id' => $bid]);
TAP_Money_Ledger::on_partial_refund($bid, 'paypal', 'REF-LEDGER-' . $sfx, 25);
TAP_Money_Ledger::on_payment_refunded($bid, 'paypal', 'REF-LEDGER-' . $sfx);

$events = array_map(static fn($r) => (string) $r->event, TAP_Money_Ledger::for_booking($bid));
tap_t_assert(in_array('payment_completed', $events, true), 'ledger records payment_completed');
tap_t_assert(in_array('partial_refund', $events, true), 'ledger records partial_refund');
tap_t_assert(in_array('payment_refunded', $events, true), 'ledger records payment_refunded');
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TAP_Money_Ledger::table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1', $bid));
tap_t_assert($row && abs(75.0 - (float) $row->amount_paid) < 0.01 && abs(25.0 - (float) $row->refund_amount) < 0.01,
    'ledger freezes the booking money state at event time');
$src = (string) file_get_contents($base . 'includes/class-money-ledger.php');
tap_t_assert(false === strpos($src, 'UPDATE ' . '{$wpdb->prefix}tap_money_ledger')
    && false === strpos($src, 'DELETE FROM ' . '{$wpdb->prefix}tap_money_ledger'), 'ledger is append-only');

/* --- 2) invariant detection ---------------------------------------------------- */
$bad = tap_t_seed_booking([
    'booking_code'   => 'BADINV-' . $sfx,
    'service_type'   => 'tap_tour', 'service_id' => 75,
    'total_amount'   => 100, 'amount_paid' => 10,
    'payment_status' => 'paid', 'status' => 'confirmed',
]);
$issues = TAP_Money_Ledger::check_invariants();
$found = false;
foreach ($issues as $issue) {
    if ((int) ($issue['booking'] ?? 0) === $bad) { $found = true; }
}
tap_t_assert($found, 'underpaid booking marked paid is detected as an invariant violation');

/* --- 3) daily reconciliation persists the snapshot ----------------------------- */
$saved = TAP_Money_Ledger::run_reconciliation();
tap_t_assert(is_array($saved) && count($saved) > 0, 'reconciliation returns detected issues');
tap_t_assert(count(TAP_Money_Ledger::stored_issues()) === count($saved), 'reconciliation snapshot is persisted for the admin page');
$found_stored = false;
foreach (TAP_Money_Ledger::stored_issues() as $issue) {
    if ((int) ($issue['booking'] ?? 0) === $bad) { $found_stored = true; }
}
tap_t_assert($found_stored, 'admin exceptions include the reconciled violation');
$bootstrap = (string) file_get_contents($base . 'travel-agency-platform.php');
tap_t_assert(false !== strpos($bootstrap, 'tap_money_reconcile_hook'), 'reconciliation cron is scheduled');

/* --- 4) guarded state transitions -------------------------------------------- */
tap_t_assert(true === TAP_Money_State::allowed('payment', 'pending', 'paid'), 'pending can become paid');
tap_t_assert(false === TAP_Money_State::allowed('payment', 'refunded', 'paid'), 'refunded is terminal');
$state_booking = tap_t_seed_booking([
    'booking_code'   => 'STATE-' . $sfx,
    'service_type'   => 'tap_tour', 'service_id' => 75,
    'total_amount'   => 50, 'payment_status' => 'pending', 'status' => 'confirmed',
]);
tap_t_assert(true === TAP_Money_State::transition_payment($state_booking, 'partial', 'test'), 'CAS transition succeeds');
tap_t_assert(true === TAP_Money_State::transition_payment($state_booking, 'partial', 'test'), 'same-state transition is idempotent');
$wpdb->update($wpdb->prefix . 'tap_bookings', ['payment_status' => 'refunded'], ['id' => $state_booking]);
$refused = TAP_Money_State::transition_payment($state_booking, 'paid', 'test');
tap_t_assert(is_wp_error($refused) && 'invalid_transition' === $refused->get_error_code(), 'illegal transition is refused');

/* --- 5) durable outbox -------------------------------------------------------- */
$dedupe = 'test-' . $sfx;
$e1 = TAP_Event_Outbox::enqueue('money_issue', ['issue' => ['type' => 'Prueba', 'booking' => $state_booking]], $dedupe);
$e2 = TAP_Event_Outbox::enqueue('money_issue', ['issue' => ['type' => 'Prueba', 'booking' => $state_booking]], $dedupe);
tap_t_assert((int) $e1 > 0 && 0 === (int) $e2, 'outbox deduplicates identical events');
$res = TAP_Event_Outbox::process(50);
tap_t_assert((int) ($res['done'] ?? 0) >= 1, 'outbox dispatches due events');
$status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . TAP_Event_Outbox::table() . ' WHERE id = %d', $e1));
tap_t_assert('done' === $status, 'dispatched event is marked done');
$bootstrap_src = (string) file_get_contents($base . 'travel-agency-platform.php');
tap_t_assert(false !== strpos($bootstrap_src, 'tap_outbox_process_hook'), 'outbox cron is scheduled');

/* --- cleanup ------------------------------------------------------------------ */
$wpdb->delete(TAP_Money_Ledger::table(), ['booking_id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bad]);
delete_transient('tap_money_issues');
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $state_booking]);
$wpdb->delete(TAP_Event_Outbox::table(), ['dedupe_key' => hash('sha256', 'test-' . $sfx)]);
wp_set_current_user($orig_uid);
tap_t_finish();
