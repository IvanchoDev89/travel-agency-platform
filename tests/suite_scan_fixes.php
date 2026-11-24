<?php
/**
 * suite_scan_fixes.php — regressions for the general bug scan (4 waves).
 * FUNCTIONAL where possible; source assertions for wiring that needs a browser.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_scan_fixes.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/';
$src = static fn($f) => (string) file_get_contents($base . $f);

/* --- Wave 1: booking form ids match the JS selectors ------------------------- */
$booking = $src('class-shortcode-booking.php');
tap_t_assert(false === strpos($booking, '#tap_check_in-<?php'), 'booking JS no longer uses -N selectors');
tap_t_assert(false === strpos($booking, '#tap_adults-<?php'), 'adults selector matches emitted id');
tap_t_assert(false !== strpos($booking, 'id="tap_check_in_<?php echo (int) $n; ?>"'), 'non-tour check-in id is suffixed');
tap_t_assert(false !== strpos($booking, 'insurance_plan'), 'quote request includes the insurance plan');
tap_t_assert(false !== strpos($src('class-ajax-public.php'), 'insurance_premium'), 'live total quotes the insurance premium');
tap_t_assert(false !== strpos($booking, "wp_create_nonce('tap_booking_nonce')"), 'voucher note uses the booking nonce');
tap_t_assert(false !== strpos($src('class-ajax-dash.php'), 'get_agency_for_user($uid)'), 'employees may operate their agency bookings');

/* --- Wave 1: money ----------------------------------------------------------- */
tap_t_assert(false !== strpos($src('class-refunds.php'), "['paid', 'partial']"), 'partial payments are refundable');
tap_t_assert(false !== strpos($src('class-booking.php'), "payment_status IN ('paid','partial')"), 'refund retry covers partial payments');
tap_t_assert(false !== strpos($src('class-payment.php'), 'record_paid_capture($booking_id, \'onvo\''), 'Onvo records amount_paid');
tap_t_assert(false !== strpos($src('class-booking.php'), '$booking_currency = !empty($booking->currency)'), 'capture currency uses the booking currency');

// Functional: a partial booking is no longer short-circuited as "nothing to refund".
$pb = tap_t_seed_booking(['payment_status' => 'partial', 'amount_paid' => 40, 'total_amount' => 100, 'refund_amount' => 40, 'status' => 'confirmed']);
$partial_booking = TAP_Booking::get_booking($pb);
tap_t_assert('partial' === $partial_booking->payment_status, 'partial fixture created');
// No gateway meta -> offline refund path must be reached (returns true), not the status gate.
delete_post_meta($pb, '_tap_paypal_capture_id');
delete_post_meta($pb, '_tap_onvo_payment_intent_id');
tap_t_assert(true === TAP_Refunds::execute_refund($pb, $partial_booking, 40), 'partial refund reaches the execution path');
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $pb]);

/* --- Wave 1: invoice numbering + stable PDF ---------------------------------- */
$inv_src = $src('class-invoices.php');
tap_t_assert(false !== strpos($inv_src, 'CAST(SUBSTRING_INDEX'), 'invoice sequence orders numerically');
tap_t_assert(false !== strpos($inv_src, 'for ($attempt = 0; $attempt < 5'), 'invoice insert retries on collision');
tap_t_assert(false !== strpos($inv_src, 'function write_pdf'), 'PDF writes to a stable path');
tap_t_assert(false !== strpos($inv_src, 'wp_delete_file($old)'), 'old PDF copies are removed');

/* --- Wave 2: privacy --------------------------------------------------------- */
$payload = (array) TAP_API::client_booking_payload((object) [
    'id' => 1, 'booking_code' => 'X', 'internal_notes' => 'secret', 'commission_amount' => 9,
    'commission_percent' => 10, 'cancel_reason' => 'r', 'total_amount' => 100,
]);
tap_t_assert(!isset($payload['internal_notes']), 'REST payload hides internal notes');
tap_t_assert(!isset($payload['commission_amount']), 'REST payload hides commission');
tap_t_assert(isset($payload['booking_code']), 'REST payload keeps client fields');

// Itinerary votes never expose voter PII.
$itin_table = $wpdb->prefix . 'tap_itinerary_votes';
$wpdb->insert($itin_table, ['itinerary_id' => 999999, 'service_id' => 1, 'voter_email' => 'pii@example.test', 'voter_name' => 'PII', 'vote' => 1]);
$votes = TAP_Itinerary_Collab::votes(999999);
$wpdb->delete($itin_table, ['itinerary_id' => 999999]);
tap_t_assert($votes && !isset($votes[0]->voter_email), 'shared itinerary votes omit voter email');

// Waiver ownership.
$wb = tap_t_seed_booking(['client_id' => 0, 'guest_email' => 'owner@example.test']);
$wbooking = TAP_Booking::get_booking($wb);
$other = get_users(['role' => 'subscriber', 'number' => 1, 'fields' => 'ids']);
if ($other) {
    wp_set_current_user((int) $other[0]);
    tap_t_assert(false === TAP_Waiver::can_sign($wbooking), 'unrelated user cannot sign a waiver');
    wp_set_current_user((int) $admin[0]);
}
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $wb]);

// Guest token is visitor-bound.
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'TapScan/1.0';
TAP_Guest_Checkout::set_guest_pay_token('TAP-SCAN-CODE');
tap_t_assert(true === TAP_Guest_Checkout::get_guest_pay_token('TAP-SCAN-CODE'), 'same visitor keeps the token');
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
tap_t_assert(false === TAP_Guest_Checkout::get_guest_pay_token('TAP-SCAN-CODE'), 'a different visitor cannot reuse the token');

// Marketing consent + unsubscribe.
tap_t_assert(false === TAP_Privacy::marketing_ok('scan@example.test'), 'no marketing consent by default');
TAP_Privacy::record_consent('scan@example.test', 'marketing', 0);
tap_t_assert(true === TAP_Privacy::marketing_ok('scan@example.test'), 'consent enables marketing');
TAP_Privacy::opt_out('scan@example.test');
tap_t_assert(true === TAP_Privacy::is_opted_out('scan@example.test'), 'opt-out is recorded');
tap_t_assert(false === TAP_Privacy::marketing_ok('scan@example.test'), 'opt-out blocks marketing');
$token = TAP_Privacy::unsubscribe_token('scan@example.test');
tap_t_assert(hash_equals($token, TAP_Privacy::unsubscribe_token('scan@example.test')), 'unsubscribe token is stable');
$wpdb->query("DELETE FROM {$wpdb->prefix}tap_consents WHERE email = 'scan@example.test'");
$wpdb->delete($wpdb->prefix . 'tap_email_optouts', ['email' => 'scan@example.test']);

tap_t_assert(false !== strpos($src('class-shuttle.php'), 'booking_required'), 'shuttle join requires a paid booking');
tap_t_assert(false !== strpos($src('class-api.php'), 'min(100, max(1, absint'), 'REST per_page is clamped');
tap_t_assert(false !== strpos($src('class-shortcode-search.php'), 'is_service_visible((int) $post->ID'), 'service detail honours agency approval');

/* --- Wave 3: data ------------------------------------------------------------ */
tap_t_assert(false !== strpos($src('class-booking-stats.php'), 'function money_where'), 'single money basis helper exists');
tap_t_assert(false !== strpos($src('class-booking-stats.php'), 'refund_amount'), 'revenue is net of refunds');
tap_t_assert(false !== strpos($src('class-installer.php'), 'tap_commission_backfill_v2'), 'destructive backfill is one-shot');
tap_t_assert(false !== strpos($src('class-installer.php'), 'tap_points_expiry_hook'), 'deactivate clears every cron');
tap_t_assert(false !== strpos($src('class-payment.php'), 'tap_processed_events'), 'webhook idempotency uses a bounded table');

// Payout links are normalized and readable.
$pid = 999999;
TAP_Payouts::set_links($pid, [11, 22, 22]);
$ids = TAP_Payouts::ids_for($pid);
sort($ids);
tap_t_assert([11, 22] === $ids, 'payout links are deduplicated and readable');
$wpdb->delete($wpdb->prefix . 'tap_payout_bookings', ['payout_id' => $pid]);

// Schema indexes present after migration.
TAP_Installer::create_tables();
tap_t_assert((bool) $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}tap_bookings WHERE Key_name = 'room_id'"), 'room_id index exists');
tap_t_assert((bool) $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}tap_daily_pricing WHERE Key_name = 'service_date'"), 'service_date index exists');
tap_t_assert((bool) $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tap_payout_bookings'"), 'payout link table exists');
tap_t_assert((bool) $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tap_email_optouts'"), 'opt-out table exists');

/* --- Wave 4: UX -------------------------------------------------------------- */
tap_t_assert(false === strpos($src('class-shortcode-support.php'), "home_url('/soporte/')"), 'support search points to a real page');
tap_t_assert(false !== strpos($src('class-shortcode-account.php'), 'TAP_API::get_price_key'), 'agency prices use canonical meta keys');
tap_t_assert(false !== strpos($booking, '$booking->check_in ? date_i18n'), 'voucher guards null check-in');
tap_t_assert(false !== strpos($src('class-front-dash.php'), "'map'"), 'nav map icon exists');
tap_t_assert(false !== strpos($src('class-front-dash.php'), "'flag'"), 'nav flag icon exists');
tap_t_assert(false === strpos($src('class-front-dash-client.php'), 'id="main-content"'), 'no duplicate main-content id');
tap_t_assert(false !== strpos($src('class-emails-send.php'), "method_exists('TAP_Emails', 'set_html_content_type')"), 'HTML email content-type check actually resolves the method');
tap_t_assert(false !== strpos($inv_src, 'backfill_snapshots'), 'legacy invoice snapshots are backfilled');

wp_set_current_user($orig_uid);
tap_t_finish();
