<?php
/**
 * suite_attribution.php — Fase 4: anti-fuga y atribución.
 * Verifies contact masking (email/phone/name), the unlock rule (contact only
 * visible once the agency has a confirmed/paid booking from that client),
 * masked CSV export for locked contacts, post-id/row-id agency lookup
 * consistency in the leads panel, lead -> booking attribution linking and the
 * admin conversion signal. Creates and removes its own fixtures.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb, $at_user_ids, $at_post_ids, $at_booking_codes, $at_lead_ids;

$at_user_ids = [];
$at_post_ids = [];
$at_booking_codes = [];
$at_lead_ids = [];

$token = 'att' . wp_generate_password(6, false) . '_';

function at_user($login) {
    $uid = wp_create_user($login, wp_generate_password(16, false), $login . '@example.test');
    if (is_wp_error($uid)) {
        return 0;
    }
    $GLOBALS['at_user_ids'][] = (int) $uid;
    return (int) $uid;
}

function at_agency($uid, $label) {
    global $wpdb;
    $post_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Attribution Agency ' . $label]);
    if ($post_id && !is_wp_error($post_id)) {
        $GLOBALS['at_post_ids'][] = (int) $post_id;
        update_post_meta($post_id, '_tap_agency_user_id', $uid);
        update_post_meta($post_id, '_tap_agency_status', TAP_Approval::APPROVED);
        update_post_meta($post_id, '_tap_agency_is_active', '1');
        update_post_meta($post_id, '_tap_agency_verified', '1');
    }
    $wpdb->insert($wpdb->prefix . 'tap_agencies', [
        'user_id' => $uid,
        'name'    => 'Attribution Agency ' . $label,
        'slug'    => 'attr-' . $label . '-' . wp_generate_password(6, false),
        'email'   => 'attr' . $label . '@example.test',
        'commission_percent' => 10.00,
        'is_active' => 1,
    ]);
    return [(int) $wpdb->insert_id, (int) $post_id];
}

function at_tour($agency_post_id, $label) {
    $id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Attribution Tour ' . $label . '-' . wp_generate_password(6, false)]);
    if ($id && !is_wp_error($id)) {
        $GLOBALS['at_post_ids'][] = (int) $id;
        update_post_meta($id, '_tap_tour_is_active', '1');
        update_post_meta($id, '_tap_tour_agency_id', $agency_post_id);
        update_post_meta($id, '_tap_tour_price_adult', '90');
        update_post_meta($id, '_tap_tour_capacity', '20');
    }
    return (int) $id;
}

function at_lead($agency, $email, $name, $phone) {
    $r = TAP_Leads::submit([
        'name'      => $name,
        'email'     => $email,
        'phone'     => $phone,
        'message'   => 'Fase 4 attribution fixture',
        'agency_id' => $agency,
    ]);
    if (is_int($r) && $r > 0) {
        $GLOBALS['at_lead_ids'][] = (int) $r;
    }
    return $r;
}

function at_booking($tour, $email, $name, $client_id = 0, $days = 40) {
    $r = TAP_Booking::create([
        'service_type' => 'tap_tour',
        'service_id'   => $tour,
        'check_in'     => gmdate('Y-m-d', strtotime("+{$days} days")),
        'check_out'    => gmdate('Y-m-d', strtotime('+' . ($days + 1) . ' days')),
        'adults'       => 2,
        'children'     => 0,
        'client_id'    => $client_id,
        'guest_email'  => $email,
        'guest_name'   => $name,
    ]);
    if (!is_wp_error($r) && !empty($r['booking_code'])) {
        $GLOBALS['at_booking_codes'][] = (string) $r['booking_code'];
    }
    return $r;
}

$owner = at_user($token . 'owner');
$guest = at_user($token . 'guest');
$agency   = at_agency($owner, $token . 'A');
$agency_b = at_agency($guest, $token . 'B');
$ag_a = $agency[0];   // tap_agencies row id (leads namespace)
$ag_b = $agency_b[0]; // tap_agencies row id
$post_a = $agency[1]; // tap_agency post id (bookings namespace)
$post_b = $agency_b[1];

$tour_a = at_tour($post_a, $token . 'A');
$tour_b = at_tour($post_b, $token . 'B');

/* --- Masking helpers. --- */
tap_t_assert('c***@example.test' === TAP_Attribution::mask_email('carla.turista@example.test'), 'mask_email keeps first char + domain');
tap_t_assert('j***@mail.com' === TAP_Attribution::mask_email('j@mail.com'), 'mask_email masks short local part');
$ph = TAP_Attribution::mask_phone('+54 911 555 0100');
tap_t_assert('+54' === substr($ph, 0, 3) && '00' === substr($ph, -2), 'mask_phone keeps prefix + last 2 digits');
tap_t_assert('Carla T.' === TAP_Attribution::mask_name('Carla Turista'), 'mask_name keeps first name + last initial');
tap_t_assert('Carla' === TAP_Attribution::mask_name('Carla'), 'mask_name single-word kept as is');
tap_t_assert('' === TAP_Attribution::mask_email(''), 'mask_email empty stays empty');

/* --- Unlock rule: locked before any paid booking. --- */
tap_t_assert(false === TAP_Attribution::contact_unlocked($post_a, 'carla@example.com'), 'contact locked with no bookings yet');
tap_t_assert(false === TAP_Attribution::contact_unlocked($ag_a, 'carla@example.com'), 'contact locked via row-id agency too');

/* --- Leads lookup consistency (post id vs row id). --- */
$lead_email = $token . 'lead@example.test';
$lead_phone = '+506 8888 7777';
at_lead($ag_a, $lead_email, 'Carla Turista', $lead_phone);
$stored = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE email = %s", $lead_email));
tap_t_assert($stored && (int) $stored->agency_id === (int) $ag_a, 'lead stored under row-id agency');
tap_t_assert(count(TAP_Leads::for_agency($post_a, 0)) > 0, 'for_agency finds leads queried by agency post id');
tap_t_assert(TAP_Leads::count_for_agency($post_a) >= 1, 'count_for_agency works by agency post id');

/* --- CSV masked while locked; unmasked after a confirmed booking. --- */
$csv_locked = TAP_Leads::export_csv($post_a);
tap_t_assert(false !== strpos($csv_locked, 'Carla T.'), 'csv masks the name while locked');
tap_t_assert(false === strpos($csv_locked, 'Carla Turista'), 'csv hides the full name while locked');
tap_t_assert(false === strpos($csv_locked, $lead_email), 'csv hides the full email while locked');
tap_t_assert(false === strpos($csv_locked, $lead_phone), 'csv hides the full phone while locked');

/* --- Link lead -> booking (attribution), same agency + email. --- */
$b1 = at_booking($tour_a, $lead_email, 'Carla Turista', 0, 42);
$b1_id = (int) $b1['booking_id'];
$row1 = $wpdb->get_row($wpdb->prepare("SELECT lead_id, status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b1_id));
tap_t_assert($row1 && (int) $row1->lead_id === (int) $stored->id, 'booking linked to matching lead id on create');

/* --- Cross-agency: same email, lead belongs to agency A only -> no link at B. --- */
$b2 = at_booking($tour_b, $lead_email, 'Carla Turista', 0, 44);
$b2_id = (int) $b2['booking_id'];
$row2 = $wpdb->get_row($wpdb->prepare("SELECT lead_id FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $b2_id));
tap_t_assert($row2 && (int) $row2->lead_id === 0, 'booking NOT linked to a lead from another agency');

/* --- Unlock rule: confirmed (paid) booking reveals contact. --- */
tap_t_assert(true === TAP_Booking::update_status($b1_id, 'confirmed'), 'booking confirmed status accepted');
tap_t_assert('confirmed' === TAP_Booking::get_booking($b1_id)->status, 'booking status is confirmed');
tap_t_assert(true === TAP_Attribution::booking_unlocked(TAP_Booking::get_booking($b1_id)), 'confirmed booking unlocks contact');
tap_t_assert(true === TAP_Attribution::contact_unlocked($post_a, $lead_email), 'contact unlocked after confirmed booking (post id)');
tap_t_assert(true === TAP_Attribution::contact_unlocked($ag_a, $lead_email), 'contact unlocked via row-id agency too');

/* --- CSV unmasked once unlocked. --- */
$csv_open = TAP_Leads::export_csv($post_a);
tap_t_assert(false !== strpos($csv_open, 'Carla Turista'), 'csv shows the full name once unlocked');
tap_t_assert(false !== strpos($csv_open, $lead_email), 'csv shows the full email once unlocked');
tap_t_assert(false !== strpos($csv_open, $lead_phone), 'csv shows the full phone once unlocked');

/* --- Logged-in client: unlock by account email. --- */
$wpu = wp_create_user($token . 'client', wp_generate_password(16, false), $token . 'wpu@example.test');
$GLOBALS['at_user_ids'][] = (int) $wpu;
tap_t_assert(false === TAP_Attribution::contact_unlocked($post_a, $token . 'wpu@example.test'), 'account-email contact locked before booking');
wp_set_current_user((int) $wpu);
$b3 = at_booking($tour_a, '', $token . 'Client', 0, 46);
wp_set_current_user(0);
$b3_id = (int) $b3['booking_id'];
TAP_Booking::update_status($b3_id, 'confirmed');
tap_t_assert(true === TAP_Attribution::contact_unlocked($post_a, $token . 'wpu@example.test'), 'account-email contact unlocked after confirmed booking');

/* --- Pending booking does not unlock, regardless of email. --- */
$b4 = at_booking($tour_a, $token . 'pend@example.test', 'Pending Client', 0, 48);
tap_t_assert(false === TAP_Attribution::contact_unlocked($post_a, $token . 'pend@example.test'), 'pending booking does not unlock contact');

/* --- Cleanup. --- */
foreach ($at_booking_codes as $code) {
    $bk = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s", $code));
    if ($bk) {
        $wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => (int) $bk]);
        $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) $bk]);
        delete_post_meta((int) $bk, '_tap_paypal_order_id');
        delete_post_meta((int) $bk, '_tap_paypal_capture_id');
        delete_post_meta((int) $bk, '_tap_payment_details');
    }
}
foreach ($at_lead_ids as $lid) {
    $wpdb->delete($wpdb->prefix . 'tap_leads', ['id' => (int) $lid]);
}
foreach ($at_post_ids as $pid) {
    delete_post_meta($pid, '_tap_booking_mode');
    wp_delete_post($pid, true);
}
foreach ($at_user_ids as $uid) {
    wp_delete_user($uid);
}
tap_t_finish();