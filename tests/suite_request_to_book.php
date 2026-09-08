<?php
/**
 * suite_request_to_book.php — Fase 3: request-to-book workflow.
 * Verifies the per-listing booking mode (instant/request), request status on
 * create, payment blocking while a request is open, agency accept (request →
 * pending/payable) and reject (request → cancelled), client retraction of a
 * request, tour capacity not consumed by open requests, front-end redirect
 * targets and the agency booking_mode select on the manage-listing form.
 * Creates and removes its own fixtures.
 */
require_once __DIR__ . '/bootstrap.php';

$created_posts = [];
$booking_codes = [];

$mk_agency = function ($title) use (&$created_posts) {
    $id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => $title]);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        update_post_meta($id, '_tap_agency_user_id', 1);
        update_post_meta($id, '_tap_agency_status', TAP_Approval::APPROVED);
        update_post_meta($id, '_tap_agency_is_active', '1');
        update_post_meta($id, '_tap_agency_verified', '1');
    }
    return $id;
};
$mk_tour = function ($title, $agency_id, $mode) use (&$created_posts) {
    $id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => $title]);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        update_post_meta($id, '_tap_tour_is_active', '1');
        update_post_meta($id, '_tap_tour_agency_id', $agency_id);
        update_post_meta($id, '_tap_tour_price_adult', '120');
        update_post_meta($id, '_tap_tour_capacity', '20');
        update_post_meta($id, '_tap_booking_mode', $mode);
    }
    return $id;
};
$mk_booking = function ($tour, $code_suffix, $days = 50) use (&$booking_codes) {
    $r = TAP_Booking::create([
        'service_type' => 'tap_tour',
        'service_id'   => $tour,
        'check_in'     => gmdate('Y-m-d', strtotime("+{$days} days")),
        'check_out'    => gmdate('Y-m-d', strtotime('+' . ($days + 1) . ' days')),
        'adults'       => 2,
        'children'     => 0,
        'client_id'    => 0,
        'guest_email'  => 'req-e2e-' . $code_suffix . '@example.test',
        'guest_name'   => 'Request Tester',
    ]);
    if (!is_wp_error($r) && !empty($r['booking_code'])) {
        $booking_codes[] = (string) $r['booking_code'];
    }
    return $r;
};

/* --- booking_mode resolution. --- */
$agency = $mk_agency('Agency RTB E2E ' . wp_generate_password(6, false));
$tour_instant = $mk_tour('Tour Instant E2E ' . wp_generate_password(6, false), $agency, 'instant');
$tour_request = $mk_tour('Tour Request E2E ' . wp_generate_password(6, false), $agency, 'request');
$tour_no_mode = $mk_tour('Tour NoMode E2E ' . wp_generate_password(6, false), $agency, '');
tap_t_assert('instant' === TAP_Booking::booking_mode('tap_tour', $tour_instant), 'booking_mode = instant for instant listing');
tap_t_assert('request' === TAP_Booking::booking_mode('tap_tour', $tour_request), 'booking_mode = request for request listing');
tap_t_assert('instant' === TAP_Booking::booking_mode('tap_tour', $tour_no_mode), 'booking_mode defaults to instant when meta absent');

/* --- save_listing_data persists the mode. --- */
$saved = TAP_Ajax::save_listing_data(1, [
    'title'        => 'Listing saved RTB E2E ' . wp_generate_password(6, false),
    'listing_type' => 'tap_tour',
    'booking_mode' => 'request',
]);
$saved_id = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $saved && $saved_id > 0, 'save_listing_data accepts a booking_mode');
if ($saved_id) {
    $created_posts[] = $saved_id;
    tap_t_assert('request' === get_post_meta($saved_id, '_tap_booking_mode', true), 'booking_mode meta saved via agency form');
    tap_t_assert('request' === TAP_Booking::booking_mode('tap_tour', $saved_id), 'booking_mode resolves from saved meta');
}

/* --- Instant booking starts 'pending' (payable right away). --- */
$b_inst = $mk_booking($tour_instant, 'instant');
tap_t_assert(!is_wp_error($b_inst) && ($b_inst['status'] ?? '') === 'pending', 'instant booking created with status pending');
tap_t_assert(TAP_Booking::is_payable(TAP_Booking::get_booking($b_inst['booking_id'])), 'instant booking is payable');
tap_t_assert(strpos(TAP_Booking::redirect_target(TAP_Booking::get_booking($b_inst['booking_id'])), '/checkout') !== false, 'instant booking redirects to /checkout');

/* --- Request booking starts 'request' (NOT payable until accepted). --- */
$b_req = $mk_booking($tour_request, 'request');
tap_t_assert(!is_wp_error($b_req) && ($b_req['status'] ?? '') === 'request', 'request booking created with status request');
$req_id = (int) $b_req['booking_id'];
$req    = TAP_Booking::get_booking($req_id);
tap_t_assert(false === TAP_Booking::is_payable($req), 'request booking is NOT payable while open');
tap_t_assert(strpos(TAP_Booking::redirect_target($req), '/booking-detail') !== false, 'request booking redirects to /booking-detail');

/* --- Open requests do not consume tour capacity. --- */
$slots_before = TAP_Booking::tour_slots($tour_request, gmdate('Y-m-d', strtotime('+50 days')));
$req2 = $mk_booking($tour_request, 'request2', 52);
$slots_after = TAP_Booking::tour_slots($tour_request, gmdate('Y-m-d', strtotime('+50 days')));
tap_t_assert((int) $slots_before['booked'] === (int) $slots_after['booked'], 'open requests do not consume tour capacity');

/* --- Agency accept: request → pending → payable. --- */
$fired = [];
add_action('tap_booking_status_updated', function ($id, $status, $prev = '') use (&$fired) {
    if ($status === 'pending' && $prev === 'request') $fired['accepted'] = $id;
}, 10, 3);
tap_t_assert(true === TAP_Booking::update_status($req_id, 'pending'), 'agency accept (request → pending) succeeds');
tap_t_assert('pending' === TAP_Booking::get_booking($req_id)->status, 'request accepted → status pending');
tap_t_assert(true === TAP_Booking::is_payable(TAP_Booking::get_booking($req_id)), 'accepted request is now payable');
tap_t_assert(isset($fired['accepted']) && (int) $fired['accepted'] === $req_id, 'status_updated fired with prev=request on accept');

/* --- Agency reject: request → cancelled → not payable. --- */
$rej_id = (int) $mk_booking($tour_request, 'reject', 54)['booking_id'];
tap_t_assert(true === TAP_Booking::update_status($rej_id, 'cancelled'), 'agency reject (request → cancelled) succeeds');
tap_t_assert('cancelled' === TAP_Booking::get_booking($rej_id)->status, 'rejected request → status cancelled');
tap_t_assert(false === TAP_Booking::is_payable(TAP_Booking::get_booking($rej_id)), 'rejected request is not payable');

/* --- Client can retract an open request. --- */
$retr = TAP_Booking::create([
    'service_type' => 'tap_tour',
    'service_id'   => $tour_request,
    'check_in'     => gmdate('Y-m-d', strtotime('+56 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+57 days')),
    'adults'       => 2,
    'children'     => 0,
    'client_id'    => 1,
]);
$retr_id = (int) $retr['booking_id'];
$booking_codes[] = (string) $retr['booking_code'];
tap_t_assert(($retr['status'] ?? '') === 'request', 'logged-in client request created as request');
$cancel = TAP_Booking::client_cancel_request($retr_id, 1);
tap_t_assert(true === $cancel, 'client can cancel an open request');
tap_t_assert('cancelled' === TAP_Booking::get_booking($retr_id)->status, 'retracted request → status cancelled');

/* --- Guest-accessible checkout notices for request / cancelled bookings. --- */
$req3 = $mk_booking($tour_request, 'guestreq', 58);
$req3_id = (int) $req3['booking_id'];
TAP_Ajax::set_guest_pay_token($req3['booking_code']);
$chk = TAP_Shortcodes::checkout(['code' => $req3['booking_code']]);
tap_t_assert(false !== strpos($chk, 'pendiente de confirmación'), 'checkout shows pending notice for open request');
$rej2 = $mk_booking($tour_request, 'guestrej', 60);
$rej2_id = (int) $rej2['booking_id'];
TAP_Booking::update_status($rej2_id, 'cancelled');
TAP_Ajax::set_guest_pay_token($rej2['booking_code']);
$chk2 = TAP_Shortcodes::checkout(['code' => $rej2['booking_code']]);
tap_t_assert(false !== strpos($chk2, 'cancelada o rechazada'), 'checkout shows rejected notice for cancelled request');

/* --- Booking form label reflects request mode. --- */
$form_html = TAP_Shortcodes::booking_form(['service_type' => 'tap_tour', 'service_id' => $tour_request]);
tap_t_assert(false !== strpos($form_html, 'Solicitar reserva'), 'booking_form shows request-to-book label');
$form_inst = TAP_Shortcodes::booking_form(['service_type' => 'tap_tour', 'service_id' => $tour_instant]);
tap_t_assert(false === strpos($form_inst, 'Solicitar reserva') && false === strpos($form_inst, 'Enviar solicitud'), 'instant form keeps direct-booking labels');

/* --- Cleanup. --- */
global $wpdb;
foreach ($booking_codes as $code) {
    $bk = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s", $code));
    if ($bk) {
        $wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => (int) $bk]);
        $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) $bk]);
        delete_post_meta((int) $bk, '_tap_paypal_order_id');
        delete_post_meta((int) $bk, '_tap_paypal_capture_id');
        delete_post_meta((int) $bk, '_tap_payment_details');
    }
}
foreach ($created_posts as $pid) {
    delete_post_meta($pid, '_tap_booking_mode');
    wp_delete_post($pid, true);
}
tap_t_finish();