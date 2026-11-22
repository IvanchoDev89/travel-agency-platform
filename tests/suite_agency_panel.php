<?php
/**
 * suite_agency_panel.php — agency back-office presentation regressions.
 * FUNCTIONAL where the UI has a runtime surface; source guards only for the
 * few invariants that have no renderable path (redirect targets).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_agency_panel.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$src      = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');
$orig_uid = get_current_user_id();
$sfx      = tap_t_suffix();
$cleanup_users = [];
$cleanup_posts = [];

/* --- 1) Finances render: platform cut, no payout form, real empty state ------ */
$admins = get_users(['role' => 'tap_agency_admin', 'number' => 1, 'fields' => 'ids']);
if ($admins) {
    wp_set_current_user((int) $admins[0]);
    $_GET['seccion'] = 'bo-finances';
    $finances = TAP_Front_Dash::render([]);
    unset($_GET['seccion']);

    tap_t_assert(false !== strpos($finances, 'Comisión de la plataforma pendiente'), 'finances shows the platform-cut commission card');
    tap_t_assert(false !== strpos($finances, 'Comisión de la plataforma por mes'), 'finances shows the monthly commission chart');
    tap_t_assert(false !== strpos($finances, 'transferencias a tu agencia se coordinan'), 'finances shows the informational transfer note');
    tap_t_assert(false !== strpos($finances, 'Estado de liquidaciones'), 'finances shows the payout status section');
    tap_t_assert(false === strpos($finances, 'tap-bo-payout') && false === strpos($finances, 'payout_request_form'), 'no payout request form rendered');
    tap_t_assert(false === strpos($finances, 'Historial de liquidaciones'), 'no retired payout history rendered');

    // Disputes section is reachable through the real router.
    $_GET['seccion'] = 'bo-disputas';
    $disputes = TAP_Front_Dash::render([]);
    unset($_GET['seccion']);
    tap_t_assert(false !== strpos($disputes, 'Disputas'), 'disputas section renders through the router');
    tap_t_assert(method_exists('TAP_Front_Dash', 'audit_action_label'), 'audit action label helper exists');
    tap_t_assert(TAP_Front_Dash::audit_action_label('booking #123') !== 'booking #123', 'raw audit code is translated');
    wp_set_current_user($orig_uid);
}

/* --- 2) Onboarding targets a real selector and posts the real nonce ---------- */
$onb_uid = wp_insert_user([
    'user_login' => 'tap_ap_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_ap_' . $sfx . '@example.test', 'role' => 'tap_agency_admin',
]);
if (!is_wp_error($onb_uid) && (int) $onb_uid > 0) {
    $cleanup_users[] = (int) $onb_uid;
    $onb_agency = wp_insert_post([
        'post_type' => 'tap_agency', 'post_status' => 'publish',
        'post_title' => 'TAP panel agency ' . $sfx,
    ]);
    if ((int) $onb_agency > 0) {
        $cleanup_posts[] = (int) $onb_agency;
        update_post_meta($onb_agency, '_tap_agency_user_id', (int) $onb_uid);
    }
    delete_user_meta((int) $onb_uid, '_tap_onboarding_tour_seen');
    wp_set_current_user((int) $onb_uid);
    $overview = TAP_Front_Dash::render([]);
    wp_set_current_user($orig_uid);
    tap_t_assert(false !== strpos($overview, 'a[href*="manage-listing"]'), 'onboarding targets the real "Nuevo" link');
    tap_t_assert(false === strpos($overview, 'data-onboarding="new-listing"'), 'onboarding no longer targets a missing element');
    tap_t_assert(false !== strpos($overview, 'window.tapDash && window.tapDash.nonce'), 'onboarding posts the front-dash nonce');
}

/* --- 3) Signup copy reads the configured commission -------------------------- */
$prev_commission = get_option('tap_commission_default', null);
update_option('tap_commission_default', '17');
$signup = do_shortcode('[tap_agency_register]');
tap_t_assert(false !== strpos($signup, '17%'), 'signup copy uses the configured commission');
tap_t_assert(false === strpos($signup, 'Comisión típica: 10%'), 'signup copy does not hardcode 10%');
if (null === $prev_commission) { delete_option('tap_commission_default'); } else { update_option('tap_commission_default', $prev_commission); }

/* --- 4) Dual-role users are not treated as employee-only --------------------- */
$dual_uid = wp_insert_user([
    'user_login' => 'tap_dual_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_dual_' . $sfx . '@example.test', 'role' => 'subscriber',
]);
if (!is_wp_error($dual_uid) && (int) $dual_uid > 0) {
    $cleanup_users[] = (int) $dual_uid;
    $dual = get_userdata((int) $dual_uid);
    $dual->add_role('tap_agency_admin');
    $dual->add_role('tap_agency_employee');
    $method = new ReflectionMethod('TAP_Front_Dash', 'is_employee_only');
    $method->setAccessible(true);
    tap_t_assert(false === $method->invoke(null, get_userdata((int) $dual_uid)), 'admin+employee user is not employee-only');
}

/* --- 5) Source guards with no runtime surface -------------------------------- */
$leads_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-leads.php');
tap_t_assert(false === strpos($src, "home_url('/login/')"), 'no redirect to nonexistent /login/ in front-dash');
tap_t_assert(false === strpos($leads_src, "home_url('/login/')"), 'leads handler uses a real login URL too');
tap_t_assert(false === strpos($src, 'if (TAP_Roles::is_agency_employee($user))'), 'section guards no longer use the pure role check');

/* --- cleanup ----------------------------------------------------------------- */
wp_set_current_user($orig_uid);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
foreach ($cleanup_users as $uid) { wp_delete_user($uid); }
tap_t_finish();
