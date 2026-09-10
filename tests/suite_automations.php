<?php
/**
 * suite_automations.php — T4 daily-cron automations: cron registration,
 * payment reminders, pre-arrival messages, post-stay review requests and
 * expiry warnings for plans/promotions, all with the sent-log guard.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$pref = 'auto_' . substr(md5(microtime(true)), 0, 6) . '_';

// ---- wiring & cron ----
tap_t_assert(class_exists('TAP_Automations'), 'TAP_Automations loaded');
tap_t_assert(false !== wp_next_scheduled('tap_auto_hook'), 'daily tap_auto_hook scheduled');
$sched = wp_get_schedule('tap_auto_hook');
tap_t_assert('daily' === $sched, 'tap_auto_hook uses daily recurrence (got ' . var_export($sched, true) . ')');

// capture outbound mail
$mails = [];
add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) {
    $mails[] = isset($atts['to']) ? $atts['to'] : '';
    return true;
}, 10, 2);

$base = [
    'service_id'   => 37,
    'service_type' => 'tap_accommodation',
    'client_id'    => 0,
    'agency_id'    => 0,
    'total_amount' => 150.00,
    'adults'       => 2,
    'children'     => 0,
];
function auto_booking($wpdb, $base, $extra)
{
    return $extra + $base + ['booking_code' => 'AUTO-' . wp_generate_password(6, false, false), 'created_at' => date('Y-m-d H:i:s')];
}

// ---- 1) payment reminders ----
update_option('tap_auto_payment_reminders', '1');
update_option('tap_payment_reminder_hours', 12);
update_option('tap_stale_booking_hours', '');
delete_option('tap_auto_sent_pay');

$b1 = auto_booking($wpdb, $base, ['status' => 'pending', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 20 * 3600), 'guest_email' => 'auto-pay@example.com', 'guest_name' => 'Pago T4']);
$wpdb->insert($wpdb->prefix . 'tap_bookings', $b1);
$bid1 = $wpdb->insert_id;

$b2 = auto_booking($wpdb, $base, ['status' => 'pending', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS), 'guest_email' => 'auto-fresh@example.com', 'guest_name' => 'Nuevo T4']);
$wpdb->insert($wpdb->prefix . 'tap_bookings', $b2);
$bid2 = $wpdb->insert_id;

$mails = [];
TAP_Automations::payment_reminders();
$logPay = get_option('tap_auto_sent_pay', []);
tap_t_assert(in_array('auto-pay@example.com', $mails, true), 'reminder emailed stale pending booking');
tap_t_assert(!in_array('auto-fresh@example.com', $mails, true), 'too-recent booking skipped');
tap_t_assert(isset($logPay[(string) $bid1]), 'reminder logged for ageing booking');
tap_t_assert(!isset($logPay[(string) $bid2]), 'fresh booking not logged');
$count = count($mails);
TAP_Automations::payment_reminders();
tap_t_assert(count($mails) === $count, 'no duplicate reminder emails');

// ---- 2) pre-arrival ----
update_option('tap_auto_prearrival', '1');
delete_option('tap_auto_sent_pre');
$b3 = auto_booking($wpdb, $base, ['status' => 'confirmed', 'check_in' => date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS), 'check_out' => date('Y-m-d', current_time('timestamp') + 3 * DAY_IN_SECONDS), 'guest_email' => 'auto-pre@example.com', 'guest_name' => 'Pre T4']);
$wpdb->insert($wpdb->prefix . 'tap_bookings', $b3);
$bid3 = $wpdb->insert_id;

$mails = [];
TAP_Automations::prearrival_messages();
$logPre = get_option('tap_auto_sent_pre', []);
tap_t_assert(in_array('auto-pre@example.com', $mails, true), 'pre-arrival emailed guest');
tap_t_assert(isset($logPre[(string) $bid3]), 'pre-arrival logged');
$count = count($mails);
TAP_Automations::prearrival_messages();
tap_t_assert(count($mails) === $count, 'no duplicate pre-arrival emails');

// ---- 3) post-stay review requests ----
update_option('tap_auto_review_request', '1');
delete_option('tap_auto_sent_rev');
$b4 = auto_booking($wpdb, $base, ['status' => 'completed', 'check_in' => date('Y-m-d', current_time('timestamp') - 9 * DAY_IN_SECONDS), 'check_out' => date('Y-m-d', current_time('timestamp') - 6 * DAY_IN_SECONDS), 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 9 * DAY_IN_SECONDS), 'guest_email' => 'auto-rev@example.com', 'guest_name' => 'Rev T4']);
$wpdb->insert($wpdb->prefix . 'tap_bookings', $b4);
$bid4 = $wpdb->insert_id;

$mails = [];
TAP_Automations::review_requests();
$logRev = get_option('tap_auto_sent_rev', []);
tap_t_assert(in_array('auto-rev@example.com', $mails, true), 'review request emailed guest');
tap_t_assert(isset($logRev[(string) $bid4]), 'review request logged');

// ---- 4) expiry warnings (subscription + promo) ----
update_option('tap_auto_expiry_warnings', '1');
update_option('tap_expiry_warn_days', 3);
delete_option('tap_auto_sent_exp');

$wpdb->insert($wpdb->prefix . 'tap_agencies', ['user_id' => 0, 'name' => $pref . 'Agencia', 'slug' => $pref . 'slug', 'email' => 'auto-agency@example.com', 'is_active' => 1]);
$aid = $wpdb->insert_id;
$soon = date('Y-m-d', current_time('timestamp') + 2 * DAY_IN_SECONDS);
$wpdb->insert($wpdb->prefix . 'tap_agency_subscriptions', ['agency_id' => $aid, 'plan_id' => 152, 'status' => 'active', 'paid_until' => $soon]);
$sid = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'tap_promos', ['agency_id' => $aid, 'listing_id' => 37, 'months' => 1, 'amount' => 50.00, 'status' => 'active', 'paid_until' => $soon, 'payment_status' => 'paid']);
$pid = $wpdb->insert_id;

$mails = [];
TAP_Automations::expiry_warnings();
$logExp = get_option('tap_auto_sent_exp', []);
tap_t_assert(count($mails) === 2 && in_array('auto-agency@example.com', $mails, true), 'expiry warnings emailed agency for plan and promo');
tap_t_assert(isset($logExp['sub:' . $sid . ':' . $soon]) && isset($logExp['prom:' . $pid . ':' . $soon]), 'expiry warnings logged with sub/promo keys');

// ---- toggles off restore state ----
update_option('tap_auto_payment_reminders', '0');
update_option('tap_auto_prearrival', '0');
update_option('tap_auto_review_request', '0');
update_option('tap_auto_expiry_warnings', '0');

// ---- i18n of automation strings ----
TAP_Localization::set_lang('en');
tap_t_assert('Payment failure' === __('Fallo de pago', 'travel-agency-platform'), 'EN translation for Fallo de pago');
TAP_Localization::set_lang('es');
tap_t_assert('Fallo de pago' === __('Fallo de pago', 'travel-agency-platform'), 'ES identity for Fallo de pago');

// ---- cleanup ----
foreach ([[$bid1, $b1], [$bid2, $b2], [$bid3, $b3], [$bid4, $b4]] as $pair) {
    $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $pair[0]]);
}
$wpdb->delete($wpdb->prefix . 'tap_agency_subscriptions', ['id' => $sid]);
$wpdb->delete($wpdb->prefix . 'tap_promos', ['id' => $pid]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['id' => $aid]);
foreach (['tap_auto_sent_pay', 'tap_auto_sent_pre', 'tap_auto_sent_rev', 'tap_auto_sent_exp', 'tap_stale_booking_hours'] as $opt) {
    delete_option($opt);
}

tap_t_finish();