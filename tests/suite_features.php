<?php
/**
 * suite_features.php — llms.txt, unpaid-payment alerts and group pricing.
 * FUNCTIONAL: real outputs, real rules, real mail interception.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_features.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$cleanup_posts = [];
$cleanup_bookings = [];
$prev_days = get_option('tap_unpaid_alert_days', null);

/* --- 1) llms.txt ------------------------------------------------------------- */
tap_t_assert(class_exists('TAP_LLMS'), 'llms.txt endpoint class is loaded');
$llms = TAP_LLMS::content();
tap_t_assert(false !== strpos($llms, '# ' . get_bloginfo('name')), 'llms.txt starts with the site name');
tap_t_assert(false !== strpos($llms, home_url('/tour/')), 'llms.txt links the tours archive');
tap_t_assert(false !== strpos($llms, home_url('/centro-de-soporte/')), 'llms.txt links the help center');
tap_t_assert(false !== strpos($llms, '## Servicios destacados'), 'llms.txt lists published services');

/* --- 2) group pricing rule --------------------------------------------------- */
tap_t_assert(isset(TAP_Pricing_Rules::rule_types()['group']), 'group rule type is registered');
$rule_service = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP group price ' . $sfx,
]);
if ((int) $rule_service > 0) {
    $cleanup_posts[] = (int) $rule_service;
    $rule_id = TAP_Pricing_Rules::save([
        'service_type' => 'tap_tour', 'service_id' => (int) $rule_service,
        'rule_type' => 'group', 'condition' => ['min_guests' => 4],
        'modifier_type' => 'percent', 'modifier_value' => -10, 'priority' => 10,
    ]);
    tap_t_assert(!is_wp_error($rule_id) && $rule_id > 0, 'group rule saved');
    $group_price = TAP_Pricing_Rules::quote('tap_tour', (int) $rule_service, 100, ['guests' => 5]);
    $solo_price  = TAP_Pricing_Rules::quote('tap_tour', (int) $rule_service, 100, ['guests' => 2]);
    tap_t_assert(abs(90.0 - $group_price) < 0.01, 'group of 5 gets 10% off (100 -> 90)');
    tap_t_assert(abs(100.0 - $solo_price) < 0.01, 'solo traveler pays the base price');
    TAP_Pricing_Rules::delete($rule_id);
}

/* --- 3) unpaid-payment alert -------------------------------------------------- */
$mails = [];
$mail_filter = static function ($null, $atts) use (&$mails) {
    $mails[] = ['subject' => (string) ($atts['subject'] ?? ''), 'to' => (string) ($atts['to'] ?? '')];
    return true;
};
add_filter('pre_wp_mail', $mail_filter, 10, 2);
update_option('tap_unpaid_alert_days', 3);

$old_unpaid = tap_t_seed_booking([
    'booking_code'   => 'UNPAID-' . $sfx,
    'payment_status' => 'pending',
    'status'         => 'confirmed',
    'created_at'     => gmdate('Y-m-d H:i:s', strtotime('-10 days')),
]);
$cleanup_bookings[] = $old_unpaid;

$mails = [];
TAP_Dashboard_Insights::send_alerts();
$subjects = array_column($mails, 'subject');
tap_t_assert(in_array('Alerta: pagos pendientes', $subjects, true), 'old unpaid booking triggers the admin alert');

/* --- cleanup ----------------------------------------------------------------- */
remove_filter('pre_wp_mail', $mail_filter, 10);
if (null === $prev_days) { delete_option('tap_unpaid_alert_days'); } else { update_option('tap_unpaid_alert_days', $prev_days); }
tap_t_cleanup_bookings($cleanup_bookings);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
wp_set_current_user($orig_uid);
tap_t_finish();
