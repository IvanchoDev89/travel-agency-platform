<?php
/**
 * suite_agency_calendar.php — agency operations calendar and no-show control.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();
$user = get_userdata((int) ($admin[0] ?? 0));

$agency_id = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'Calendar E2E ' . $sfx,
]);
update_post_meta($agency_id, '_tap_agency_user_id', (int) ($admin[0] ?? 0));
TAP_Approval::approve($agency_id);

$today = current_time('Y-m-d');
$bid = tap_t_seed_booking([
    'agency_id' => (int) $agency_id,
    'booking_code' => 'CAL-' . $sfx,
    'guest_name' => 'Huésped Calendario',
    'check_in' => $today,
    'check_out' => gmdate('Y-m-d', strtotime($today . ' +2 days')),
    'status' => 'confirmed',
    'payment_status' => 'paid',
]);

tap_t_assert(in_array('no_show', TAP_Booking::$transitions['confirmed'], true), 'confirmed bookings can become no_show');
tap_t_assert(in_array('no_show', array_keys(TAP_Emails_Send::status_labels()), true), 'no_show has a translated label');

$html = TAP_Front_Dash::agency_calendar($user);
tap_t_assert(false !== strpos($html, 'CAL-' . $sfx), 'calendar shows the booking');
tap_t_assert(false !== strpos($html, 'Huésped Calendario'), 'calendar shows the guest');
tap_t_assert(false !== strpos($html, 'data-op="no_show"'), 'calendar offers the no-show action for today');

$future = tap_t_seed_booking([
    'agency_id' => (int) $agency_id,
    'booking_code' => 'CAL-F-' . $sfx,
    'check_in' => gmdate('Y-m-d', strtotime($today . ' +10 days')),
    'status' => 'confirmed',
    'payment_status' => 'paid',
]);
$denied = TAP_Booking::agency_booking_action($future, 'no_show', (int) ($admin[0] ?? 0));
tap_t_assert(is_wp_error($denied), 'future booking cannot be marked no-show');

$res = TAP_Booking::agency_booking_action($bid, 'no_show', (int) ($admin[0] ?? 0));
tap_t_assert(true === $res, 'no-show action succeeds for today');
tap_t_assert('no_show' === TAP_Booking::get_booking($bid)->status, 'booking status is no_show');

$nav_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false !== strpos($nav_src, "case 'bo-calendar'"), 'calendar section is routed');
tap_t_assert(false !== strpos($nav_src, "'key' => 'bo-calendar'"), 'calendar nav item is registered');

$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $future]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Calendar E2E ' . $sfx]);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
