<?php
/**
 * TAP E2E suite: guest checkout (Fase 1 / P3).
 *
 * Logged-out visitors can book with client_id = 0 and their contact data in the
 * guest_* columns, receive emails, view the voucher by code+email, and pay on
 * /checkout against a short-lived payment token. Site-agnostic: discovers a
 * published service on the current install, creates throwaway client users.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb, $created_booking_ids, $created_client_uids;
$created_booking_ids = [];
$created_client_uids = [];

/** Find a published service suitable for direct booking (non-tour first). */
function gco_service() {
    $types = ['tap_package', 'tap_boat', 'tap_transport', 'tap_car_rental', 'tap_accommodation', 'tap_tour'];
    foreach ($types as $t) {
        $posts = get_posts(['post_type' => $t, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
        if ($posts) {
            return [(int) $posts[0], $t];
        }
    }
    return [0, ''];
}

function gco_make_client_user() {
    global $created_client_uids;
    $suffix = wp_generate_password(5, false);
    $uid = wp_insert_user([
        'user_login'   => 'tap_client_' . $suffix,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => 'tap_client_' . $suffix . '@example.test',
        'role'         => 'subscriber',
        'display_name' => 'TAP Client',
    ]);
    if (is_wp_error($uid) || !$uid) {
        return 0;
    }
    $created_client_uids[] = (int) $uid;
    return (int) $uid;
}

function gco_cleanup() {
    global $created_booking_ids, $created_client_uids;
    foreach ($created_client_uids as $uid) {
        wp_delete_user($uid);
    }
    foreach ($created_booking_ids as $id) {
        delete_transient('tap_guest_pay_' . TAP_Booking::get_booking($id)->booking_code);
    }
    tap_t_cleanup_bookings($created_booking_ids);
}

// --- Discover a service to book against ------------------------------------
list($service_id, $service_type) = gco_service();
tap_t_assert($service_id > 0 && $service_type !== '', "discovered a published service (id={$service_id}, {$service_type})");

$future_in  = gmdate('Y-m-d', strtotime('+30 days'));
$future_out = gmdate('Y-m-d', strtotime('+33 days'));

$booking_data = [
    'service_type' => $service_type,
    'service_id'   => $service_id,
    'check_in'     => $future_in,
    'check_out'    => $future_out,
    'adults'       => 2,
    'children'     => 0,
    'total_amount' => 150,
    'notes'        => 'guest e2e',
];

// 1) Guest happy path: client_id = 0 + contact data persisted -----------------
$guest_email = 'guest_' . wp_generate_password(6, false) . '@example.test';
$guest_name  = 'Guest ' . wp_generate_password(4, false);
$res = TAP_Booking::create($booking_data + ['client_id' => 0, 'guest_name' => $guest_name, 'guest_email' => $guest_email, 'guest_phone' => '5000-1234']);
tap_t_assert(true !== is_wp_error($res) && !empty($res['booking_code']), 'guest booking created (client_id=0)');
$gid = (int) $res['booking_id'];
$created_booking_ids[] = $gid;

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $gid));
tap_t_assert($row !== null && (int) $row->client_id === 0, 'guest booking stored with client_id = 0');
tap_t_assert($row !== null && $row->guest_email === $guest_email, 'guest booking stored with guest_email');
tap_t_assert($row !== null && $row->guest_name === $guest_name, 'guest booking stored with guest_name');
tap_t_assert($row !== null && $row->guest_phone === '5000-1234', 'guest booking stored with guest_phone');
$fee = TAP_Booking::get_booking_fee(150);
tap_t_assert($row !== null && abs((float) $row->total_amount - round(150 + $fee, 2)) < 0.001, 'guest booking total includes booking fee');

// 2) Guest validation: email required ----------------------------------------
$noemail = TAP_Booking::create($booking_data + ['client_id' => 0, 'guest_name' => 'No Email']);
tap_t_assert(is_wp_error($noemail) && $noemail->get_error_code() === 'guest_email_required', 'guest booking without email rejected');
$bademail = TAP_Booking::create($booking_data + ['client_id' => 0, 'guest_name' => 'Bad', 'guest_email' => 'not-an-email']);
tap_t_assert(is_wp_error($bademail) && $bademail->get_error_code() === 'guest_email_required', 'guest booking with invalid email rejected');

// 3) Logged-in customer: defaults to current user ----------------------------
$client_uid = gco_make_client_user();
wp_set_current_user($client_uid);
$logged = TAP_Booking::create($booking_data);
wp_set_current_user(0);
tap_t_assert(!is_wp_error($logged), 'registered customer booking created');
$lrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $logged['booking_id']));
tap_t_assert($lrow !== null && (int) $lrow->client_id === $client_uid, 'registered customer booking stored with client_id = user_id');

// 4) Voucher access rules ------------------------------------------------------
$gcode = $res['booking_code'];
$lcode = $logged['booking_code'];

$_GET = ['code' => $gcode];
$html = do_shortcode('[tap_booking_detail]');
tap_t_assert(stripos($html, 'verifica el correo') !== false, 'guest voucher prompts for email verification');
unset($_GET['email']);

$_GET = ['code' => $gcode, 'email' => 'wrong@example.test'];
$html = do_shortcode('[tap_booking_detail]');
tap_t_assert(stripos($html, 'no coincide') !== false, 'guest voucher rejects wrong email');
unset($_GET['email']);

$_GET = ['code' => $gcode, 'email' => $guest_email];
$html = do_shortcode('[tap_booking_detail]');
tap_t_assert(stripos($html, 'Voucher de Reserva') !== false && stripos($html, $gcode) !== false, 'guest voucher renders with matching email');

$_GET = ['code' => $lcode];
$html = do_shortcode('[tap_booking_detail]');
tap_t_assert(stripos($html, 'Log in to view your booking voucher') !== false, 'guest cannot view a registered customer booking');
$_GET = [];

// 5) Checkout: guest must verify email before paying --------------------------
$_GET = ['code' => $gcode];
$html = do_shortcode('[tap_checkout]');
tap_t_assert(stripos($html, 'verifica el correo') !== false, 'guest checkout asks for email before payment');

$_GET = ['code' => $gcode, 'email' => 'wrong@example.test'];
$html = do_shortcode('[tap_checkout]');
tap_t_assert(stripos($html, 'verifica el correo') !== false, 'guest checkout rejects wrong email');

$_GET = ['code' => $gcode, 'email' => $guest_email];
$html = do_shortcode('[tap_checkout]');
tap_t_assert(stripos($html, 'tap-checkout-summary') !== false && stripos($html, $gcode) !== false, 'guest checkout shows summary after email verification');
tap_t_assert(TAP_Ajax::get_guest_pay_token($gcode), 'guest payment token set after checkout verification');

// Payment gate helpers --------------------------------------------------------
$pay_token = $gcode;
TAP_Ajax::set_guest_pay_token($pay_token);
tap_t_assert(TAP_Ajax::get_guest_pay_token($pay_token), 'guest pay token set via helper');

// 6) Guest cancellation --------------------------------------------------------
$cancel_noemail = TAP_Booking::client_cancel_request($gid, 0);
tap_t_assert(is_wp_error($cancel_noemail) && $cancel_noemail->get_error_code() === 'no_email', 'guest cancel without email rejected');

$cancel_wrongemail = TAP_Booking::client_cancel_request($gid, 0, 'wrong@example.test');
tap_t_assert(is_wp_error($cancel_wrongemail) && $cancel_wrongemail->get_error_code() === 'no_email', 'guest cancel with wrong email rejected');

$cancel = TAP_Booking::client_cancel_request($gid, 0, $guest_email);
tap_t_assert(true === $cancel, 'guest can cancel own guest booking (client_id 0 + email)');
$crow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $gid));
tap_t_assert($crow && $crow->status === 'cancelled', 'guest cancellation recorded (status cancelled)');

// A registered user cannot cancel a guest booking.
$cancel_usurp = TAP_Booking::client_cancel_request($gid, $client_uid);
tap_t_assert(is_wp_error($cancel_usurp) && $cancel_usurp->get_error_code() === 'forbidden', 'registered user cannot cancel a guest booking');

// A guest cannot cancel a registered customer's booking.
$cancel_other = TAP_Booking::client_cancel_request((int) $logged['booking_id'], 0, $guest_email);
tap_t_assert(is_wp_error($cancel_other) && $cancel_other->get_error_code() === 'no_user', 'guest cannot cancel a registered customer booking');

// Registered owner can cancel their own booking.
$cancel_owner = TAP_Booking::client_cancel_request((int) $logged['booking_id'], $client_uid);
tap_t_assert(true === $cancel_owner, 'registered customer can cancel own booking');

// 7) Rate limiting --------------------------------------------------------------
$rl_email = 'rl_' . wp_generate_password(5, false) . '@example.test';
for ($i = 0; $i < 4; $i++) {
    TAP_Ajax::guest_book_rate_bump($rl_email);
}
tap_t_assert(!TAP_Ajax::guest_book_rate_blocked($rl_email), 'rate limit not reached below threshold');
TAP_Ajax::guest_book_rate_bump($rl_email);
tap_t_assert(TAP_Ajax::guest_book_rate_blocked($rl_email), 'rate limit reached after 5 guest bookings');
delete_transient(TAP_Ajax::guest_bucket($rl_email));

gco_cleanup();
tap_t_finish();