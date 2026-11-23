<?php
/**
 * suite_booking_money.php — money-critical booking/checkout guards (Fase 1).
 * FUNCTIONAL: real booking creation, real pricing, real webhook resolution.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_booking_money.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$cleanup_posts = [];
$cleanup_bookings = [];
$cleanup_slots = [];
$cleanup_orders = [];

/* --- 1) Onvo webhook resolves via intent metadata ---------------------------- */
$onvo_booking = tap_t_seed_booking(['booking_code' => 'ONVO-' . $sfx]);
$cleanup_bookings[] = $onvo_booking;
$intent = (object) [
    'id' => 'pi_e2e_' . $sfx,
    'metadata' => (object) ['booking_id' => $onvo_booking, 'booking_code' => 'ONVO-' . $sfx],
];
tap_t_assert(
    (int) $onvo_booking === TAP_Payment::find_booking_by_onvo_intent('pi_e2e_' . $sfx, $intent),
    'Onvo intent metadata resolves its booking'
);
$intent_code_only = (object) ['id' => 'pi_e2e2_' . $sfx, 'metadata' => (object) ['booking_code' => 'ONVO-' . $sfx]];
tap_t_assert(
    (int) $onvo_booking === TAP_Payment::find_booking_by_onvo_intent('pi_e2e2_' . $sfx, $intent_code_only),
    'Onvo booking_code metadata resolves its booking'
);

/* --- 2) coupon cap is shared between quote and creation ---------------------- */
$coupon = (object) ['discount_type' => 'percent', 'discount_value' => 50];
$discount = TAP_Coupons::effective_discount($coupon, 100, 100, 10);
tap_t_assert(abs(2.5 - $discount) < 0.01, 'coupon capped at 25% of commission (100 * 10% * 25% = 2.5)');
$src_booking = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-booking.php');
$src_quote = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-public.php');
tap_t_assert(false !== strpos($src_booking, 'TAP_Coupons::effective_discount') && false !== strpos($src_quote, 'TAP_Coupons::effective_discount'), 'both quote and creation use the shared coupon calculator');

/* --- 3) slot price is charged, not just displayed ---------------------------- */
$tour = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP slot price ' . $sfx,
]);
if ((int) $tour > 0) {
    $cleanup_posts[] = (int) $tour;
    update_post_meta($tour, '_tap_tour_price_adult', 100);
    $date = gmdate('Y-m-d', strtotime('+30 days'));
    $slot = TAP_Tour_Availability::create_slot([
        'service_type' => 'tap_tour', 'service_id' => (int) $tour,
        'date' => $date, 'price' => 150, 'capacity' => 10,
    ]);
    if ($slot) {
        $cleanup_slots[] = (int) $slot;
        $with_slot = TAP_Booking_Pricing::calculate_price('tap_tour', (int) $tour, $date, $date, 1, 0, 0, (int) $slot);
        $no_slot   = TAP_Booking_Pricing::calculate_price('tap_tour', (int) $tour, $date, $date, 1, 0);
        tap_t_assert(abs(150.0 - $with_slot) < 0.01, 'slot price override is applied by calculate_price');
        tap_t_assert(abs(100.0 - $no_slot) < 0.01, 'base price applies when no slot is given');
        $created = TAP_Booking::create([
            'service_type' => 'tap_tour', 'service_id' => (int) $tour,
            'slot_id' => (int) $slot, 'check_in' => $date, 'check_out' => $date,
            'adults' => 1, 'children' => 0, 'guest_name' => 'E2E Slot',
            'guest_email' => 'slot-' . $sfx . '@example.test',
            'tap_privacy_consent' => '1',
        ]);
        if (is_array($created) && !empty($created['booking_id'])) {
            $cleanup_bookings[] = (int) $created['booking_id'];
            $row = TAP_Booking::get_booking($created['booking_id']);
            tap_t_assert((float) $row->total_amount >= 150.0, 'create() charges the slot price, not the base price');
        } else {
            tap_t_assert(false, 'booking with slot price could not be created: ' . (is_wp_error($created) ? $created->get_error_message() : 'unknown'));
        }
    }
}

/* --- 4) guests get contact fields; multiple forms get unique IDs ------------- */
$form_service = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP form service ' . $sfx,
]);
$cleanup_posts[] = (int) $form_service;
wp_set_current_user(0);
$atts = ['service_type' => 'tap_tour', 'service_id' => (int) $form_service];
$form1 = TAP_Shortcode_Booking::booking_form($atts);
$form2 = TAP_Shortcode_Booking::booking_form($atts);
tap_t_assert(false !== strpos($form1, 'name="guest_name"'), 'guest form renders the name field');
tap_t_assert(false !== strpos($form1, 'name="guest_email"'), 'guest form renders the email field');
preg_match('/id="(tap-booking-form-[0-9]+)"/', $form1, $m1);
preg_match('/id="(tap-booking-form-[0-9]+)"/', $form2, $m2);
tap_t_assert(!empty($m1[1]) && !empty($m2[1]) && $m1[1] !== $m2[1], 'multiple booking forms have unique IDs');
wp_set_current_user($orig_uid);

/* --- 5) insurance premium is included in the authoritative total ------------ */
$ins_tour = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP insurance ' . $sfx,
]);
if ((int) $ins_tour > 0) {
    $cleanup_posts[] = (int) $ins_tour;
    update_post_meta($ins_tour, '_tap_tour_price_adult', 100);
    $premium = TAP_Travel_Insurance::quote('basic', 100);
    $created = TAP_Booking::create([
        'service_type' => 'tap_tour', 'service_id' => (int) $ins_tour,
        'check_in' => gmdate('Y-m-d', strtotime('+40 days')),
        'check_out' => gmdate('Y-m-d', strtotime('+40 days')),
        'adults' => 1, 'children' => 0, 'guest_name' => 'E2E Ins',
        'guest_email' => 'ins-' . $sfx . '@example.test',
        'tap_privacy_consent' => '1', 'insurance_plan' => 'basic',
    ]);
    if (is_array($created) && !empty($created['booking_id'])) {
        $cleanup_bookings[] = (int) $created['booking_id'];
        $row = TAP_Booking::get_booking($created['booking_id']);
        $fee = (float) TAP_Booking::get_booking_fee(100);
        tap_t_assert(abs((100 + $fee + $premium) - (float) $row->total_amount) < 0.02, 'insurance premium is charged in the total');
        tap_t_assert(!empty(TAP_Travel_Insurance::for_booking($created['booking_id'])), 'insurance policy is issued with the booking');
    } else {
        tap_t_assert(false, 'insured booking could not be created');
    }
}

/* --- 6) waiver gate + offline payment methods -------------------------------- */
$waiver_tour = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP waiver gate ' . $sfx,
]);
if ((int) $waiver_tour > 0) {
    $cleanup_posts[] = (int) $waiver_tour;
    update_post_meta($waiver_tour, '_tap_tour_waiver_required', '1');
    update_post_meta($waiver_tour, '_tap_tour_waiver', 'Texto del descargo E2E.');
    update_post_meta($waiver_tour, '_tap_tour_price_adult', 100);
    $wb = tap_t_seed_booking([
        'service_type' => 'tap_tour', 'service_id' => (int) $waiver_tour,
        'total_amount' => 100, 'payment_status' => 'pending', 'status' => 'confirmed',
    ]);
    $cleanup_bookings[] = $wb;
    tap_t_assert(true === TAP_Waiver::is_required('tap_tour', (int) $waiver_tour), 'waiver requirement is detected');
    tap_t_assert(false === TAP_Waiver::has_valid($wb), 'booking without signature has no valid waiver');

    $commerce_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-commerce.php');
    tap_t_assert(2 === substr_count($commerce_src, 'TAP_Waiver::has_valid('), 'both payment handlers block on an unsigned waiver');

    $admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
    if ($admin_ids) {
        wp_set_current_user((int) $admin_ids[0]);
        $checkout_html = TAP_Shortcode_Booking::checkout(['code' => TAP_Booking::get_booking($wb)->booking_code]);
        wp_set_current_user($orig_uid);
        tap_t_assert(false !== strpos($checkout_html, 'Descargo de responsabilidad'), 'checkout renders the required waiver form');
    }
}

$prev_sinpe = get_option('tap_sinpe_enabled', null);
$prev_phone = get_option('tap_sinpe_phone', null);
update_option('tap_sinpe_enabled', '1');
update_option('tap_sinpe_phone', '88887777');
$payable = tap_t_seed_booking([
    'service_type' => 'tap_tour', 'service_id' => 75,
    'total_amount' => 100, 'payment_status' => 'pending', 'status' => 'confirmed',
]);
$cleanup_bookings[] = $payable;
$admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin_ids) {
    wp_set_current_user((int) $admin_ids[0]);
    $offline_html = TAP_Shortcode_Booking::checkout(['code' => TAP_Booking::get_booking($payable)->booking_code]);
    wp_set_current_user($orig_uid);
    tap_t_assert(false !== strpos($offline_html, 'Otros métodos de pago'), 'checkout shows configured offline methods');
    tap_t_assert(false !== strpos($offline_html, '88887777'), 'SINPE instructions include the configured number');
}
if (null === $prev_sinpe) { delete_option('tap_sinpe_enabled'); } else { update_option('tap_sinpe_enabled', $prev_sinpe); }
if (null === $prev_phone) { delete_option('tap_sinpe_phone'); } else { update_option('tap_sinpe_phone', $prev_phone); }

/* --- cleanup ----------------------------------------------------------------- */
wp_set_current_user($orig_uid);
foreach ($cleanup_slots as $sid) {
    $wpdb->delete(TAP_Tour_Availability::table(), ['id' => (int) $sid]);
}
foreach ($cleanup_orders as $oid) {
    $wpdb->delete(TAP_Payment::orders_table(), ['id' => (int) $oid]);
}
tap_t_cleanup_bookings($cleanup_bookings);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
tap_t_finish();
