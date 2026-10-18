<?php
/**
 * Tanda C suite: permission & state gates.
 *
 * Covers C6/C20 (legacy agency panel money gating + approval banner),
 * C7 (agency delete cleanup), C8 (service_detail post_status), C9 (employee
 * pricing access), C14 (subscription approval gate + capture ownership) and
 * C15 (handle_agency_action capability check + register rate limit).
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$sfx = tap_t_suffix();
$pc_cleanup_users = [];
$pc_cleanup_posts = [];

/* --- wp_die / wp_send_json capture helper -----------------------------------
 * The wp_die_*_handler filters receive the default handler NAME and must
 * RETURN a callable; the callable gets the real wp_die() message, so capture
 * it there before throwing (suite_privacy's simpler throw-at-filter variant
 * never sees the message argument). */
$GLOBALS['pc_die_message'] = null;
$pc_die_factory = function () {
    return function ($message = '') {
        $GLOBALS['pc_die_message'] = $message;
        throw new RuntimeException('pc_die_captured');
    };
};
$pc_run_die = function ($fn) use ($pc_die_factory) {
    $GLOBALS['pc_die_message'] = null;
    $filters = ['wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_rest_handler', 'wp_die_handler'];
    foreach ($filters as $filter) {
        add_filter($filter, $pc_die_factory);
    }
    add_filter('wp_doing_ajax', '__return_true');
    ob_start();
    try {
        $fn();
        $died = false;
    } catch (RuntimeException $e) {
        $died = ('pc_die_captured' === $e->getMessage());
    }
    $out = trim(ob_get_clean());
    foreach ($filters as $filter) {
        remove_filter($filter, $pc_die_factory);
    }
    remove_filter('wp_doing_ajax', '__return_true');
    $json = json_decode($out, true);
    return [$died, is_array($json) ? $json : null, $GLOBALS['pc_die_message']];
};

/* --- fixtures: owner + pending agency + employee + traveler ---------------- */
$owner_uid = wp_insert_user([
    'user_login'   => 'tap_pc_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_pc_' . $sfx . '@example.test',
    'role'         => 'tap_agency_admin',
    'display_name' => 'TAP PC Owner',
]);
tap_t_assert(!is_wp_error($owner_uid) && $owner_uid, 'fixture agency owner created');
$pc_cleanup_users[] = (int) $owner_uid;

$aid = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'TAP PC Agency ' . $sfx,
]);
tap_t_assert((int) $aid > 0, 'fixture agency post created');
$pc_cleanup_posts[] = (int) $aid;
update_post_meta($aid, '_tap_agency_user_id', (int) $owner_uid);
TAP_Approval::pending($aid);

$staff_uid = wp_insert_user([
    'user_login'   => 'tap_pce_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_pce_' . $sfx . '@example.test',
    'role'         => 'tap_agency_employee',
    'display_name' => 'TAP PC Employee',
]);
tap_t_assert(!is_wp_error($staff_uid) && $staff_uid, 'fixture employee created');
$pc_cleanup_users[] = (int) $staff_uid;
update_user_meta($staff_uid, '_tap_agency_id', (int) $aid);

$traveler_uid = wp_insert_user([
    'user_login'   => 'tap_pcv_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_pcv_' . $sfx . '@example.test',
    'role'         => 'subscriber',
    'display_name' => 'TAP PC Traveler',
]);
tap_t_assert(!is_wp_error($traveler_uid) && $traveler_uid, 'fixture traveler created');
$pc_cleanup_users[] = (int) $traveler_uid;

$owner = get_user_by('id', (int) $owner_uid);
$staff = get_user_by('id', (int) $staff_uid);

// One booking so the bookings table (and its money columns) actually render.
$pc_booking_id = tap_t_seed_booking(['agency_id' => (int) $aid]);
tap_t_assert($pc_booking_id > 0, 'fixture booking seeded for the agency');

/* --- C6: money sections gated away from employees -------------------------- */
// agency_panel echoes its markup (it is an echo-style shortcode renderer).
$pc_panel = function ($user) {
    ob_start();
    TAP_Shortcode_Agency_Panel::agency_panel($user);
    return (string) ob_get_clean();
};
wp_set_current_user($staff_uid);
$html_emp = $pc_panel($staff);
wp_set_current_user($owner_uid);
$html_adm = $pc_panel($owner);
wp_set_current_user(0);

tap_t_assert(
    false === strpos($html_emp, 'Liquidaciones de comisiones') && false === strpos($html_emp, 'Libro de comisiones'),
    'employee panel hides settlements and commission ledger'
);
tap_t_assert(
    false === strpos($html_emp, 'Disputas de tus reservas') && false === strpos($html_emp, 'Destacados (promociones)'),
    'employee panel hides disputes and featured promos'
);
tap_t_assert(
    false === strpos($html_emp, 'Por cobrar') && false === strpos($html_emp, 'Neto para ti'),
    'employee panel hides commission stat cards'
);
tap_t_assert(
    false === strpos($html_emp, '<th>Comisión</th>') && false === strpos($html_emp, '<th>Neto</th>'),
    'employee bookings table hides commission/net columns'
);
tap_t_assert(
    strpos($html_adm, 'Liquidaciones de comisiones') !== false && strpos($html_adm, 'Libro de comisiones') !== false,
    'agency admin panel renders settlements and ledger'
);
tap_t_assert(
    strpos($html_adm, '<th>Comisión</th>') !== false && strpos($html_adm, '<th>Neto</th>') !== false,
    'agency admin bookings table keeps commission/net columns'
);
tap_t_assert(
    strpos($html_adm, 'Destacados (promociones)') !== false && strpos($html_adm, 'Disputas de tus reservas') !== false,
    'agency admin panel renders promos and disputes sections'
);

/* --- C6b: front-dash overview must not leak money stats to employees ------- */
// /mi-cuenta/ (TAP_Front_Dash) is the live agency panel; its Resumen stats grid
// must gate the net/receivable cards exactly like the legacy panel above.
$pc_front = function ($user) {
    wp_set_current_user($user->ID);
    $_GET['seccion'] = 'overview';
    $h = TAP_Front_Dash::render([]);
    unset($_GET['seccion']);
    return (string) $h;
};
$html_fe_emp = $pc_front($staff);
$html_fe_adm = $pc_front($owner);
wp_set_current_user(0);
tap_t_assert(
    false === strpos($html_fe_emp, 'Neto para ti') && false === strpos($html_fe_emp, 'Por cobrar'),
    'employee front-dash overview hides net and receivable money stats'
);
tap_t_assert(
    strpos($html_fe_adm, 'Neto para ti') !== false && strpos($html_fe_adm, 'Por cobrar') !== false,
    'agency admin front-dash overview shows net and receivable money stats'
);

/* --- C20: approval status banner ------------------------------------------- */
tap_t_assert(
    strpos($html_adm, 'Tu agencia está en revisión') !== false,
    'pending agency shows review banner to its owner'
);
TAP_Approval::approve($aid);
$html_ok = $pc_panel($owner);
tap_t_assert(
    false === strpos($html_ok, 'Tu agencia está en revisión') && false === strpos($html_ok, 'Tu agencia no fue aprobada'),
    'approved agency shows no approval banner'
);
TAP_Approval::reject($aid);
$html_rej = $pc_panel($owner);
tap_t_assert(
    strpos($html_rej, 'Tu agencia no fue aprobada') !== false,
    'rejected agency shows rejection banner'
);
TAP_Approval::pending($aid);

/* --- C8: service_detail post_status gate ------------------------------------ */
$draft = wp_insert_post([
    'post_type'    => 'tap_tour',
    'post_status'  => 'draft',
    'post_title'   => 'TAP PC Tour ' . $sfx,
    'post_author'  => (int) $owner_uid,
]);
tap_t_assert((int) $draft > 0, 'fixture draft tour created');
$pc_cleanup_posts[] = (int) $draft;
update_post_meta($draft, TAP_Post_Types::agency_meta_key('tap_tour'), (int) $aid);

wp_set_current_user(0);
$svc_anon = TAP_Shortcode_Search::service_detail(['id' => $draft]);
tap_t_assert(
    strpos($svc_anon, 'Servicio no encontrado.') !== false,
    'anonymous visitor cannot view a draft service'
);
wp_set_current_user($owner_uid);
$svc_owner = TAP_Shortcode_Search::service_detail(['id' => $draft]);
tap_t_assert(
    false === strpos($svc_owner, 'Servicio no encontrado.'),
    'the owning agency admin can preview its draft service'
);
wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
wp_set_current_user(0);
$svc_pub = TAP_Shortcode_Search::service_detail(['id' => $draft]);
tap_t_assert(
    false === strpos($svc_pub, 'Servicio no encontrado.'),
    'published service renders for anonymous visitors'
);

/* --- C9: employee pricing access ------------------------------------------- */
$acc = wp_insert_post([
    'post_type'   => 'tap_accommodation',
    'post_status' => 'publish',
    'post_title'  => 'TAP PC Acc ' . $sfx,
    'post_author' => (int) $owner_uid,
]);
$pc_cleanup_posts[] = (int) $acc;
update_post_meta($acc, TAP_Post_Types::agency_meta_key('tap_accommodation'), (int) $aid);
$room = wp_insert_post([
    'post_type'   => 'tap_room',
    'post_status' => 'publish',
    'post_title'  => 'TAP PC Room ' . $sfx,
]);
$pc_cleanup_posts[] = (int) $room;
update_post_meta($room, '_tap_room_accommodation_id', (int) $acc);

wp_set_current_user($staff_uid);
$_POST = $_REQUEST = [
    'nonce'    => wp_create_nonce('tap_pricing'),
    'room_id'  => (int) $room,
    'date'     => '2030-03-10',
    'price'    => '123',
];
[$pc_died, $pc_json] = $pc_run_die(['TAP_Pricing', 'ajax_save_pricing']);
tap_t_assert(
    is_array($pc_json) && !empty($pc_json['success']),
    'employee can save daily pricing for a room of its agency'
);

wp_set_current_user($traveler_uid);
$_POST = $_REQUEST = [
    'nonce'    => wp_create_nonce('tap_pricing'),
    'room_id'  => (int) $room,
    'date'     => '2030-03-11',
    'price'    => '55',
];
[$pc_died, $pc_json] = $pc_run_die(['TAP_Pricing', 'ajax_save_pricing']);
tap_t_assert(
    is_array($pc_json) && empty($pc_json['success']) && isset($pc_json['message']),
    'traveler without agency is rejected by the pricing editor'
);
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d",
    (int) $room
));

/* --- C14: subscription approval gate + capture ownership -------------------- */
$pc_plans = TAP_Subscriptions::get_plans();
tap_t_assert(!empty($pc_plans), 'at least one plan is configured');
$pc_plan_id = $pc_plans ? (int) $pc_plans[0]->id : 0;

$pc_err = TAP_Subscriptions::subscribe((int) $aid, $pc_plan_id);
tap_t_assert(
    is_wp_error($pc_err) && 'agency_not_approved' === $pc_err->get_error_code(),
    'pending agency cannot request a subscription'
);

TAP_Approval::approve($aid);
$pc_sub_id = TAP_Subscriptions::subscribe((int) $aid, $pc_plan_id);
tap_t_assert(!is_wp_error($pc_sub_id) && (int) $pc_sub_id > 0, 'approved agency can request a subscription');
tap_t_assert(
    (int) $aid === TAP_Subscriptions::agency_for_subscription((int) $pc_sub_id),
    'agency_for_subscription resolves the owning agency'
);

$pc_order = 'ORD-PC-' . $sfx;
$wpdb->insert($wpdb->prefix . 'tap_payment_orders', [
    'paypal_order_id' => $pc_order,
    'object_type'     => 'subscription',
    'object_id'       => (int) $pc_sub_id,
    'amount'          => 10,
    'status'          => 'created',
]);
wp_set_current_user($traveler_uid);
$_POST = $_REQUEST = [
    'nonce'           => wp_create_nonce('tap_plan_nonce'),
    'paypal_order_id' => $pc_order,
];
[$pc_died, $pc_json] = $pc_run_die(['TAP_Ajax_Commerce', 'capture_subscription_paypal']);
tap_t_assert(
    is_array($pc_json) && empty($pc_json['success']) && isset($pc_json['data']['message']),
    'foreign user cannot capture another agency\'s subscription order'
);
TAP_Approval::pending($aid);

/* --- C14b: promotion capture ownership -------------------------------------
 * Mirrors the subscription capture: a foreign logged-in user must not be able
 * to capture (and thereby activate) another agency's promotion order. PayPal
 * is mocked so a successful capture is the only thing that could activate it. */
$pc_promo_id    = 0;
$pc_promo_order = 'ORD-PCP-' . $sfx;
$orig_cid = get_option('tap_paypal_client_id', '');
$orig_sec = get_option('tap_paypal_secret', '');
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
tap_t_install_paypal_mock();

$wpdb->insert($wpdb->prefix . 'tap_promos', [
    'agency_id' => (int) $aid, 'listing_id' => (int) $draft, 'months' => 1, 'amount' => 5.00,
    'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'manual', 'notes' => 'pc',
]);
$pc_promo_id = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'tap_payment_orders', [
    'paypal_order_id' => $pc_promo_order,
    'object_type'     => 'promotion',
    'object_id'       => $pc_promo_id,
    'amount'          => 5.00,
    'status'          => 'created',
]);
tap_t_assert($pc_promo_id > 0, 'fixture promotion + order created for the agency');

wp_set_current_user($traveler_uid);
$_POST = $_REQUEST = [
    'nonce'           => wp_create_nonce('tap_front_dash_nonce'),
    'paypal_order_id' => $pc_promo_order,
];
[$pc_died, $pc_json] = $pc_run_die(['TAP_Ajax_Commerce', 'capture_promo_paypal']);
tap_t_assert(
    is_array($pc_json) && empty($pc_json['success']) && isset($pc_json['data']['message']),
    'foreign user cannot capture another agency\'s promotion order'
);
tap_t_assert(
    'pending' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_promos WHERE id=%d", $pc_promo_id)),
    'foreign promo capture leaves the promotion pending (no activation)'
);

tap_t_remove_paypal_mock();
update_option('tap_paypal_client_id', $orig_cid);
update_option('tap_paypal_secret', $orig_sec);
wp_set_current_user(0);

/* --- C15a: handle_agency_action capability check ---------------------------- */
$pc_ref = new ReflectionMethod('TAP_Dashboard_Content', 'handle_agency_action');
$pc_ref->setAccessible(true);

wp_set_current_user($traveler_uid);
$pc_msg = null;
[$pc_died, $pc_json, $pc_msg] = $pc_run_die(
    fn() => $pc_ref->invoke(null, 'commission', (int) $aid, ['commission' => 5])
);
tap_t_assert($pc_died, 'handle_agency_action dies for users without tap_manage_agencies');
tap_t_assert(
    is_string($pc_msg) && strpos($pc_msg, 'No tienes permiso para realizar esta acción.') !== false,
    'handle_agency_action rejection message is shown'
);
tap_t_assert(
    '' === (string) get_post_meta($aid, '_tap_agency_commission', true),
    'unprivileged action did not modify the agency commission'
);

wp_set_current_user(1);
[$pc_died] = $pc_run_die(
    fn() => $pc_ref->invoke(null, 'commission', (int) $aid, ['commission' => 7])
);
tap_t_assert(!$pc_died, 'platform manager passes the capability check');
tap_t_assert(
    7.0 === (float) get_post_meta($aid, '_tap_agency_commission', true),
    'platform manager action applied'
);

/* --- C15b: agency register rate limit --------------------------------------- */
$pc_ip   = '203.0.113.77';
$_SERVER['REMOTE_ADDR'] = $pc_ip;
$pc_key  = TAP_Rate_Limit::key('agency_reg');
delete_transient($pc_key);
wp_set_current_user(0);
$pc_reg = ['nonce' => wp_create_nonce('tap_register_nonce')];
$pc_last = null;
for ($pc_i = 0; $pc_i < 6; $pc_i++) {
    $_POST = $_REQUEST = $pc_reg;
    [, $pc_last] = $pc_run_die(['TAP_Ajax', 'agency_register']);
}
tap_t_assert(
    is_array($pc_last) && empty($pc_last['success'])
        && isset($pc_last['data']['message'])
        && strpos($pc_last['data']['message'], 'Demasiados registros en poco tiempo') !== false,
    'sixth registration attempt from one IP is rate limited'
);
delete_transient($pc_key);
unset($_SERVER['REMOTE_ADDR']);

/* --- C7: agency delete cleanup (runs last: destroys the fixtures) ----------- */
update_post_meta($aid, '_tap_agency_verified', '1');
wp_set_current_user(1);
[$pc_died] = $pc_run_die(fn() => $pc_ref->invoke(null, 'delete', (int) $aid, []));
tap_t_assert(!$pc_died, 'platform manager can delete an agency');

$pc_ag = get_post($aid);
tap_t_assert($pc_ag && 'trash' === $pc_ag->post_status, 'deleted agency post moved to trash');
tap_t_assert(
    'pending' === (string) get_post_meta($aid, TAP_Approval::STATUS_META, true),
    'deleted agency approval status reset to pending (a restore needs review)'
);
tap_t_assert(
    '' === (string) get_post_meta($aid, '_tap_agency_verified', true),
    'deleted agency verification flag removed'
);

$owner_after = get_user_by('id', (int) $owner_uid);
tap_t_assert(
    $owner_after && !in_array('tap_agency_admin', (array) $owner_after->roles, true) && !empty($owner_after->roles),
    'owner lost the agency-admin role but keeps a valid role'
);
$staff_after = get_user_by('id', (int) $staff_uid);
tap_t_assert(
    $staff_after
        && '' === (string) get_user_meta($staff_uid, '_tap_agency_id', true)
        && !in_array('tap_agency_employee', (array) $staff_after->roles, true)
        && !empty($staff_after->roles),
    'employee lost the agency mapping and the employee role but keeps a valid role'
);
tap_t_assert(
    0 === (int) TAP_Booking::get_agency_for_user($owner_uid) && 0 === (int) TAP_Booking::get_agency_for_user($staff_uid),
    'neither owner nor employee resolve an agency after deletion'
);
tap_t_assert(
    '' === (string) get_post_meta($draft, TAP_Post_Types::agency_meta_key('tap_tour'), true)
        && '' === (string) get_post_meta($acc, TAP_Post_Types::agency_meta_key('tap_accommodation'), true),
    'listing agency ownership meta stripped from draft and published listings'
);

/* --- cleanup ---------------------------------------------------------------- */
wp_set_current_user(0);
tap_t_cleanup_bookings([$pc_booking_id]);
$wpdb->delete($wpdb->prefix . 'tap_payment_orders', ['paypal_order_id' => $pc_order]);
$wpdb->delete($wpdb->prefix . 'tap_payment_orders', ['paypal_order_id' => $pc_promo_order]);
$wpdb->delete($wpdb->prefix . 'tap_promos', ['id' => (int) $pc_promo_id]);
$wpdb->delete(TAP_Subscriptions::subs_table(), ['agency_id' => (int) $aid]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['user_id' => (int) $owner_uid]);
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d",
    (int) $room
));
foreach ($pc_cleanup_posts as $pid) {
    wp_delete_post($pid, true);
}
foreach ($pc_cleanup_users as $uid) {
    wp_delete_user($uid);
}

tap_t_finish();
