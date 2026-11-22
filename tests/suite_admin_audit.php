<?php
/**
 * suite_admin_audit.php — regression guards for the pre-launch admin audit
 * (blocks 1-3): one money basis, public REST privacy, employee promo gate,
 * SSRF-safe image sideload, fixed booking-detail link, dead payout code and
 * the agency finance empty state.
 *
 * FUNCTIONAL where possible (real calls, real DB rows, rendered HTML); source
 * guards are used only where the behaviour lives inside a large admin render.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_admin_audit.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx        = tap_t_suffix();
$plugin     = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins/travel-agency-platform/');
$orig_uid   = get_current_user_id();
$orig_get   = $_GET;
$orig_post  = $_POST;
$orig_req   = $_REQUEST;
$cleanup_users = [];
$cleanup_posts = [];
$booking_ids   = [];

/* --- wp_die / wp_send_json capture (same pattern as suite_permissions) ------ */
$GLOBALS['aa_die_message'] = null;
$aa_die_factory = function () {
    return function ($message = '') {
        $GLOBALS['aa_die_message'] = $message;
        throw new RuntimeException('aa_die_captured');
    };
};
$aa_run_die = function ($fn) use ($aa_die_factory) {
    $GLOBALS['aa_die_message'] = null;
    $filters = ['wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_rest_handler', 'wp_die_handler'];
    foreach ($filters as $filter) {
        add_filter($filter, $aa_die_factory);
    }
    add_filter('wp_doing_ajax', '__return_true');
    ob_start();
    try {
        $fn();
        $died = false;
    } catch (RuntimeException $e) {
        $died = ('aa_die_captured' === $e->getMessage());
    }
    $out = trim(ob_get_clean());
    foreach ($filters as $filter) {
        remove_filter($filter, $aa_die_factory);
    }
    remove_filter('wp_doing_ajax', '__return_true');
    $json = json_decode($out, true);
    return [$died, is_array($json) ? $json : null];
};

/* --- throwaway fixtures ----------------------------------------------------- */
$owner_uid = wp_insert_user([
    'user_login'   => 'tap_aa_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_aa_' . $sfx . '@example.test',
    'role'         => 'tap_agency_admin',
    'display_name' => 'TAP AA Owner',
]);
tap_t_assert(!is_wp_error($owner_uid) && $owner_uid > 0, 'fixture agency admin created');
if (!is_wp_error($owner_uid)) { $cleanup_users[] = (int) $owner_uid; }

$employee_uid = wp_insert_user([
    'user_login'   => 'tap_aae_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_aae_' . $sfx . '@example.test',
    'role'         => 'tap_agency_employee',
    'display_name' => 'TAP AA Employee',
]);
tap_t_assert(!is_wp_error($employee_uid) && $employee_uid > 0, 'fixture agency employee created');
if (!is_wp_error($employee_uid)) { $cleanup_users[] = (int) $employee_uid; }

$aid = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'TAP AA Agency ' . $sfx,
]);
tap_t_assert((int) $aid > 0, 'fixture agency post created');
if ((int) $aid > 0) {
    $cleanup_posts[] = (int) $aid;
    update_post_meta($aid, '_tap_agency_user_id', (int) $owner_uid);
    if (!is_wp_error($employee_uid)) {
        update_user_meta((int) $employee_uid, '_tap_agency_id', (int) $aid);
    }
}

/* --- 1) ONE money basis: paid + not cancelled, per agency ------------------- */
$bid_paid = tap_t_seed_booking([
    'booking_code'      => 'AA-PAID-' . $sfx,
    'agency_id'         => (int) $aid,
    'total_amount'      => 100,
    'booking_fee'       => 5,
    'commission_amount' => 10,
    'commission_status' => 'owed',
    'payment_status'    => 'paid',
    'status'            => 'confirmed',
]);
$bid_cancelled = tap_t_seed_booking([
    'booking_code'      => 'AA-CANCEL-' . $sfx,
    'agency_id'         => (int) $aid,
    'total_amount'      => 200,
    'booking_fee'       => 10,
    'commission_amount' => 20,
    'commission_status' => 'owed',
    'payment_status'    => 'paid',
    'status'            => 'cancelled',
]);
$bid_unpaid = tap_t_seed_booking([
    'booking_code'      => 'AA-UNPAID-' . $sfx,
    'agency_id'         => (int) $aid,
    'total_amount'      => 300,
    'booking_fee'       => 15,
    'commission_amount' => 30,
    'commission_status' => 'owed',
    'payment_status'    => 'pending',
    'status'            => 'confirmed',
]);
$booking_ids = array_merge($booking_ids, [$bid_paid, $bid_cancelled, $bid_unpaid]);

$st = TAP_Booking_Stats::get_booking_stats((int) $aid);
tap_t_assert(abs((float) $st['revenue'] - 100.0) < 0.01, 'stats revenue = paid + active only (cancelled 200 and unpaid 300 excluded)');
tap_t_assert(abs((float) $st['fees'] - 5.0) < 0.01, 'stats fees use the same paid + active basis');
tap_t_assert(abs((float) $st['commission'] - 10.0) < 0.01, 'stats commission uses the same paid + active basis');
tap_t_assert(abs((float) $st['net'] - 85.0) < 0.01, 'stats net reconciles revenue - fees - commission');
tap_t_assert((int) $st['total'] === 3, 'status counters still include cancelled and unpaid bookings');

$an = TAP_Analytics::agency_stats((int) $aid);
tap_t_assert(abs((float) $an['revenue'] - 100.0) < 0.01, 'agency analytics revenue excludes cancelled bookings too');

/* --- 2) public REST must not leak the agency commission --------------------- */
$agencies_resp = tap_t_rest('GET', '/tap/v1/agencies');
$agencies_data = tap_t_rest_data($agencies_resp);
tap_t_assert(is_array($agencies_data), 'public agencies REST returns an array');
$commission_leaks = 0;
foreach ((array) $agencies_data as $entry) {
    if (is_array($entry) && array_key_exists('commission', $entry)) { $commission_leaks++; }
}
tap_t_assert(0 === $commission_leaks, 'public agencies REST never exposes the commission field');

/* --- 3) employee cannot create/pay a promotion ------------------------------ */
$promo_table = $wpdb->prefix . 'tap_promos';
$promo_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$promo_table}");
wp_set_current_user((int) $employee_uid);
$_POST = [
    'nonce'      => wp_create_nonce('tap_front_dash_nonce'),
    'listing_id' => 75,
    'months'     => 1,
];
$_REQUEST = $_POST;
[$died, $json] = $aa_run_die(['TAP_Ajax_Commerce', 'promo_request']);
$promo_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$promo_table}");
tap_t_assert($died && is_array($json) && empty($json['success']), 'employee promo request is rejected');
tap_t_assert(false !== strpos($json['data']['message'] ?? '', 'administrador'), 'rejection says only the agency admin may promote');
tap_t_assert($promo_before === $promo_after, 'denied promo creates no promo row');
wp_set_current_user($orig_uid);
$_POST = $orig_post;
$_REQUEST = $orig_req;

/* --- 4) SSRF-safe binary fetch (functional, no network needed) -------------- */
tap_t_assert(is_wp_error(TAP_ICal_Import::fetch_binary('http://127.0.0.1/secret')), 'fetch_binary blocks loopback host');
tap_t_assert(is_wp_error(TAP_ICal_Import::fetch_binary('http://169.254.169.254/latest/meta-data/')), 'fetch_binary blocks cloud metadata host');
tap_t_assert(is_wp_error(TAP_ICal_Import::fetch_binary('ftp://example.com/x')), 'fetch_binary blocks non-http scheme');

/* --- 5) finances empty state (real render) ---------------------------------- */
wp_set_current_user((int) $owner_uid);
$_GET['seccion'] = 'bo-finances';
ob_start();
$finance_html = TAP_Front_Dash::render([]);
ob_end_clean();
tap_t_assert(false !== strpos($finance_html, 'Estado de liquidaciones'), 'finances renders the payout status section');
tap_t_assert(false !== strpos($finance_html, 'Aún no hay liquidaciones'), 'finances shows a helpful empty state with no payouts');
$_GET = $orig_get;
wp_set_current_user($orig_uid);

/* --- 6) source guards for changes inside large admin renders ---------------- */
$bk_src = (string) file_get_contents($plugin . 'includes/class-dashboard-bookings.php');
tap_t_assert(false !== strpos($bk_src, "home_url('/booking-detail/?code='"), 'calendar link points to the front-end booking detail page');
tap_t_assert(false === strpos($bk_src, "admin_url('admin.php?page=tap-booking-detail"), 'broken admin booking-detail link is gone');

$payouts_src = (string) file_get_contents($plugin . 'includes/class-payouts.php');
$fd_src      = (string) file_get_contents($plugin . 'includes/class-front-dash.php');
$main_src    = (string) file_get_contents($plugin . 'travel-agency-platform.php');
$media_src   = (string) file_get_contents($plugin . 'includes/class-agency-media.php');
tap_t_assert(false === strpos($payouts_src, 'public static function request('), 'dead TAP_Payouts::request removed');
tap_t_assert(false === strpos($payouts_src, 'public static function statuses('), 'dead TAP_Payouts::statuses removed');
tap_t_assert(false === strpos($payouts_src, 'public static function sources('), 'dead TAP_Payouts::sources removed');
tap_t_assert(false === strpos($fd_src, 'payout_method_label'), 'dead payout_method_label removed');
tap_t_assert(false === strpos($fd_src, 'payout_requested'), 'dead payout_requested audit label removed');
tap_t_assert(false === strpos($main_src, 'tap_upload_review_image'), 'unused review-image AJAX action unregistered');
tap_t_assert(false !== strpos($media_src, 'fetch_binary'), 'media sideload uses the IP-pinned fetcher');

/* --- cleanup ---------------------------------------------------------------- */
tap_t_cleanup_bookings($booking_ids);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
foreach ($cleanup_users as $uid) { wp_delete_user($uid); }
$_GET = $orig_get;
$_POST = $orig_post;
$_REQUEST = $orig_req;
wp_set_current_user($orig_uid);

tap_t_finish();
