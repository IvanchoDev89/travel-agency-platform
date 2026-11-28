<?php
/**
 * suite_algorithm_fixes.php — capacity, migration order and discount algorithms.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';

/* --- 1) migration installs columns before backfilling them -------------------- */
$installer = (string) file_get_contents($base . 'includes/class-installer.php');
$backfill_at = strpos($installer, "get_option('tap_legacy_money_backfill')");
$commission_col_at = strpos($installer, 'ADD COLUMN commission_base');
$amount_col_at = strpos($installer, 'ADD COLUMN amount_paid');
tap_t_assert($backfill_at > $commission_col_at && $backfill_at > $amount_col_at,
    'money backfill runs after the columns it writes exist');

/* --- 2) losing a cancel race does not release a seat twice -------------------- */
$service = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Algo ' . $sfx]);
$date = gmdate('Y-m-d', strtotime('+40 days'));
$slot = TAP_Tour_Availability::create_slot([
    'service_type' => 'tap_tour', 'service_id' => (int) $service,
    'date' => $date, 'price' => 100, 'capacity' => 5,
]);
$booked_ok = TAP_Tour_Availability::book_slot((int) $slot, 2);
tap_t_assert($booked_ok && 2 === (int) TAP_Tour_Availability::get_slot($slot)->booked, 'slot starts with two seats held');

$booking_id = tap_t_seed_booking([
    'booking_code' => 'CANCEL-' . $sfx, 'service_type' => 'tap_tour',
    'service_id' => (int) $service, 'slot_id' => (int) $slot,
    'adults' => 2, 'children' => 0, 'check_in' => $date,
    'status' => 'confirmed', 'payment_status' => 'pending',
]);
$stale = TAP_Booking::get_booking($booking_id);
// Another request wins the cancellation while this one holds a stale row.
$wpdb->update($wpdb->prefix . 'tap_bookings', ['status' => 'cancelled'], ['id' => $booking_id]);

$method = new ReflectionMethod('TAP_Booking', 'apply_cancellation');
$method->setAccessible(true);
$res = $method->invoke(null, $booking_id, $stale, 'system', 'cancelled', '');
tap_t_assert(is_wp_error($res) && 'bad_status' === $res->get_error_code(), 'stale cancellation loses the CAS claim');
tap_t_assert(2 === (int) TAP_Tour_Availability::get_slot($slot)->booked, 'the losing request does not release the seat again');

/* --- 3) capacity is not stolen when book_slot fails --------------------------- */
$booking_src = (string) file_get_contents($base . 'includes/class-booking.php');
$guarded = false;
if (preg_match('/if \(\$create_error && \$slot_booked[^)]*\)/', $booking_src)) { $guarded = true; }
tap_t_assert($guarded, 'slot is released only when it was actually incremented');
tap_t_assert(false !== strpos($booking_src, "['id' => \$booking_id, 'status' => \$prev]"), 'status writes use compare-and-swap');

/* --- 4) dispute resolution is CAS-guarded ------------------------------------- */
$dispute_src = (string) file_get_contents($base . 'includes/class-disputes.php');
tap_t_assert(false !== strpos($dispute_src, 'TAP_Money_State::transition_commission'), 'dispute resolution uses the guarded transition');

/* --- 5) displayed discounts respect the 100% cap ------------------------------ */
$acc = wp_insert_post(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'post_title' => 'Aloj ' . $sfx]);
$room = wp_insert_post(['post_type' => 'tap_room', 'post_status' => 'publish', 'post_title' => 'Hab ' . $sfx]);
update_post_meta($room, '_tap_room_accommodation_id', (int) $acc);
update_post_meta($room, '_tap_room_price_per_night', 100);
$original_settings = get_option('tap_discount_settings', []);
update_option('tap_discount_settings', [
    'early_enabled' => '1', 'early_days' => 1, 'early_percent' => 60,
    'last_enabled' => '1', 'last_days' => 365, 'last_percent' => 60,
    'long_enabled' => '1', 'long_tiers' => [['nights' => 7, 'percent' => 60]],
]);
$in = gmdate('Y-m-d', strtotime('+30 days'));
$out = gmdate('Y-m-d', strtotime('+40 days'));
$quote = TAP_Discounts::get_quote((int) $room, $in, $out, (int) $acc);
$sum = 0.0;
foreach ((array) ($quote['discounts'] ?? []) as $d) { $sum += (float) ($d['amount'] ?? 0); }
tap_t_assert($quote['available'], 'quote is available for the test room');
tap_t_assert($sum <= (float) $quote['savings'] + 0.02, 'per-discount amounts add up to the applied saving');
update_option('tap_discount_settings', $original_settings);

/* --- cleanup ------------------------------------------------------------------ */
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $booking_id]);
$wpdb->delete(TAP_Tour_Availability::table(), ['service_id' => (int) $service]);
wp_delete_post($service, true);
wp_delete_post($room, true);
wp_delete_post($acc, true);
tap_t_finish();
