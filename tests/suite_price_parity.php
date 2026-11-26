<?php
/**
 * suite_price_parity.php — displayed price == charged price, strict dates and
 * refund idempotency. FUNCTIONAL: real quote request, real booking, real refunds.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$admin_id = (int) ($admins[0] ?? 0);
if ($admin_id) { wp_set_current_user($admin_id); }
$sfx = tap_t_suffix();

/* --- 1) malformed dates never fatal ------------------------------------------- */
tap_t_assert(1 === TAP_Booking_Pricing::nights_between('garbage', 'also-garbage'), 'bad dates default to one night');
$bad_quote = TAP_Booking_Pricing::calculate_price('tap_equipment', 999999, 'garbage', 'garbage');
tap_t_assert(is_numeric($bad_quote), 'pricing does not throw on malformed dates');
$bad_range = TAP_Pricing::get_prices_for_range(999999, 'garbage', 'garbage');
tap_t_assert(false === ($bad_range['available'] ?? true), 'room range rejects malformed dates safely');
$ajax_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax.php');
tap_t_assert(false !== strpos($ajax_src, 'TAP_Booking::parse_date($date_value)'), 'booking endpoint validates dates before pricing');

/* --- 2) fixtures -------------------------------------------------------------- */
$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Parity E2E ' . $sfx]);
update_post_meta($agency_id, '_tap_agency_user_id', $admin_id);
TAP_Approval::approve($agency_id);
$service_id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Tour Parity ' . $sfx]);
update_post_meta($service_id, '_tap_tour_agency_id', $agency_id);
update_post_meta($service_id, '_tap_tour_price_adult', 100);
update_post_meta($service_id, '_tap_tour_price_child', 50);
update_post_meta($service_id, '_tap_tour_private_price', 250);
update_post_meta($service_id, '_tap_tour_max_people', 10);
update_post_meta($service_id, '_tap_tour_capacity', 10);
$extra_id = TAP_Service_Extras::create([
    'service_type' => 'tap_tour', 'service_id' => (int) $service_id,
    'name' => 'Almuerzo ' . $sfx, 'price' => 12.5, 'is_per_person' => 1,
]);
tap_t_assert($extra_id > 0, 'per-person extra created');

/* --- 3) quote endpoint vs real booking ---------------------------------------- */
$GLOBALS['pq_die_message'] = null;
$pq_factory = function () {
    return function ($message = '') {
        $GLOBALS['pq_die_message'] = $message;
        throw new RuntimeException('pq_die');
    };
};
$pq_filters = ['wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_rest_handler', 'wp_die_handler'];
foreach ($pq_filters as $filter) { add_filter($filter, $pq_factory); }
add_filter('wp_doing_ajax', '__return_true');

$_POST = [
    'nonce'           => wp_create_nonce('tap_nonce'),
    'service_type'    => 'tap_tour',
    'service_id'      => (int) $service_id,
    'adults'          => 2,
    'children'        => 0,
    'extras'          => [(int) $extra_id],
    'private_upgrade' => '1',
    'check_in'        => gmdate('Y-m-d', strtotime('+40 days')),
    'check_out'       => gmdate('Y-m-d', strtotime('+42 days')),
];
$_REQUEST = $_POST;
ob_start();
try { TAP_Ajax_Public::calculate_booking_total(); } catch (RuntimeException $e) { /* expected */ }
$quote_out = trim(ob_get_clean());
foreach ($pq_filters as $filter) { remove_filter($filter, $pq_factory); }
remove_filter('wp_doing_ajax', '__return_true');
$quote = json_decode($quote_out, true);
$quote = is_array($quote['data'] ?? null) ? $quote['data'] : $quote;
tap_t_assert(isset($quote['total']), 'quote endpoint returned a total: ' . substr($quote_out, 0, 160));

$created = TAP_Booking::create([
    'service_type'    => 'tap_tour',
    'service_id'      => (int) $service_id,
    'adults'          => 2,
    'children'        => 0,
    'extras'          => [(int) $extra_id],
    'private_upgrade' => 1,
    'check_in'        => gmdate('Y-m-d', strtotime('+40 days')),
    'check_out'       => gmdate('Y-m-d', strtotime('+42 days')),
]);
tap_t_assert(!is_wp_error($created), 'booking created with the quoted options' . (is_wp_error($created) ? ': ' . $created->get_error_code() . ' ' . $created->get_error_message() : ''));
$booking = is_wp_error($created) ? null : TAP_Booking::get_booking((int) ($created['booking_id'] ?? 0));
tap_t_assert((bool) $booking, 'booking row exists');
if ($booking && isset($quote['total'])) {
    tap_t_assert(abs((float) $quote['total'] - (float) $booking->total_amount) < 0.01,
        'displayed quote equals the charged total (' . $quote['total'] . ' vs ' . $booking->total_amount . ')');
    tap_t_assert(abs((float) ($quote['subtotal'] ?? 0) - 275.0) < 0.01, 'private price + per-person extras reach the subtotal');
    tap_t_assert(abs((float) ($quote['commission_base'] ?? 0) - (float) $booking->commission_base) < 0.01,
        'commission base agrees between quote and booking');
}

/* --- 4) refunds are idempotent ------------------------------------------------- */
tap_t_install_paypal_mock();
$refund_booking = tap_t_seed_booking([
    'booking_code' => 'REFIDEM-' . $sfx,
    'service_type' => 'tap_tour', 'service_id' => (int) $service_id,
    'total_amount' => 100, 'amount_paid' => 100,
    'payment_status' => 'paid', 'status' => 'confirmed',
]);
update_post_meta($refund_booking, '_tap_paypal_capture_id', 'CAP-REF-' . $sfx);
$count_refunds = function () {
    $n = 0;
    foreach ((array) ($GLOBALS['tap_pp_log'] ?? []) as $entry) {
        if (preg_match('#/refund$#', (string) $entry['url'])) { $n++; }
    }
    return $n;
};
$before = $count_refunds();
$r1 = TAP_Refunds::execute_refund($refund_booking, null, 50);
$r2 = TAP_Refunds::execute_refund($refund_booking, null, 50);
tap_t_assert(!is_wp_error($r1) && !is_wp_error($r2), 'both refund calls report success');
tap_t_assert(1 === $count_refunds() - $before, 'a repeated identical refund hits the gateway only once');
tap_t_assert('' !== (string) get_post_meta($refund_booking, '_tap_refund_executed_hash', true), 'refund claim persisted');
tap_t_remove_paypal_mock();

/* --- cleanup ------------------------------------------------------------------ */
$_POST = [];
$_REQUEST = [];
$wpdb->delete($wpdb->prefix . 'tap_service_extras', ['service_id' => $service_id]);
if (!is_wp_error($created)) { $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) ($created['booking_id'] ?? 0)]); }
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $refund_booking]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Parity E2E ' . $sfx]);
wp_delete_post($service_id, true);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
