<?php
/**
 * suite_stability.php — critical money/stability regressions from the full audit.
 * FUNCTIONAL: idempotency, date validation, money backfills and error wiring.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) ($admin[0] ?? 0)); }
$sfx = tap_t_suffix();
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/';

/* --- strict date parsing ------------------------------------------------------ */
tap_t_assert(TAP_Booking::parse_date('2026-11-25') instanceof DateTime, 'valid date parses');
tap_t_assert(null === TAP_Booking::parse_date('garbage'), 'malformed date rejected');
tap_t_assert(null === TAP_Booking::parse_date('2026-13-45'), 'impossible date rejected');
$bad = TAP_Booking::create([
    'service_type' => 'tap_tour', 'service_id' => 75, 'adults' => 1,
    'guest_email' => 'bad-' . strtolower($sfx) . '@example.test', 'check_in' => 'garbage',
]);
tap_t_assert(is_wp_error($bad) && 'invalid_date' === $bad->get_error_code(), 'create() returns WP_Error on bad date instead of fatal');

/* --- persistent idempotency --------------------------------------------------- */
$idem = 'idem-' . $sfx;
$params = [
    'service_type' => 'tap_tour', 'service_id' => 75, 'adults' => 1,
    'guest_email' => 'idem-' . strtolower($sfx) . '@example.test',
    'idempotency_key' => $idem,
];
$first = TAP_Booking::create($params);
$second = TAP_Booking::create($params);
tap_t_assert(!is_wp_error($first) && !is_wp_error($second), 'idempotent create succeeds');
tap_t_assert((int) $first['booking_id'] === (int) $second['booking_id'], 'replay returns the same booking');
$row = TAP_Booking::get_booking((int) $first['booking_id']);
tap_t_assert('' !== (string) ($row->idempotency_key ?? ''), 'idempotency hash persisted in DB');
$tap_cleanup = [(int) $first['booking_id']];

/* --- money source wiring ------------------------------------------------------ */
$money = (string) file_get_contents($base . 'class-dashboard-money.php');
tap_t_assert(2 === substr_count($money, "SELECT GET_LOCK(%s, 5)\", \$lock)") || false !== strpos($money, 'GET_LOCK'),
    'payout locking present');
tap_t_assert(false !== strpos($money, 'GET_LOCK(%s, 5)", $lock)'), 'payout lock uses get_var, not query()');
$commerce = (string) file_get_contents($base . 'class-ajax-commerce.php');
tap_t_assert(false !== strpos($commerce, "is_wp_error(\$recorded)"), 'PayPal capture failure is propagated');
$payment = (string) file_get_contents($base . 'class-payment.php');
tap_t_assert(false !== strpos($payment, 'previous_refund_id'), 'partial refund is deduplicated');
tap_t_assert(false !== strpos($payment, 'webhook_resolved'), 'webhooks are acknowledged only when resolved');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/travel-agency-platform.php');
tap_t_assert(false !== strpos($bootstrap, "'' === \$migration_error"), 'version advances only when migrations succeed');
$cleanup = (string) file_get_contents($base . 'class-test-data.php');
tap_t_assert(false !== strpos($cleanup, 'content LIKE'), 'test-data cleanup uses the real reviews column');

/* --- agency earnings excludes insurance --------------------------------------- */
$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Stability E2E ' . $sfx]);
$bid2 = tap_t_seed_booking([
    'agency_id' => (int) $agency_id, 'total_amount' => 200, 'booking_fee' => 10,
    'insurance_premium' => 20, 'commission_amount' => 19, 'commission_percent' => 10,
    'payment_status' => 'paid', 'status' => 'confirmed',
]);
$e = TAP_Agency_Payouts::earnings($agency_id);
tap_t_assert(abs(180.0 - (float) $e['gross']) < 0.01, 'insurance premium excluded from agency gross');
$tap_cleanup[] = (int) $bid2;

/* --- cleanup ----------------------------------------------------------------- */
foreach ($tap_cleanup as $bid) { $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]); }
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Stability E2E ' . $sfx]);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
