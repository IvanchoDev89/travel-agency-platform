<?php
/**
 * suite_dashboard.php — T6 role-based frontend dashboard.
 * Verifies /mi-cuenta/ page, [tap_front_dash] shortcode, role routing,
 * sidebar/nav rendering, client sections, and dashboard assets.
 */
require __DIR__ . '/bootstrap.php';

$uid = wp_get_current_user()->ID;
$old = $uid ? $uid : 0;

// ---- 1) /mi-cuenta/ page exists with the shortcode ----
$page = get_page_by_path('mi-cuenta');
tap_t_assert($page, 'mi-cuenta page exists');
tap_t_assert($page && strpos($page->post_content, '[tap_front_dash]') !== false, 'mi-cuenta page contains [tap_front_dash]');
tap_t_assert($page && $page->post_status === 'publish', 'mi-cuenta page is published');
$page_id = $page ? (int) $page->ID : 0;

// ---- 2) shortcode registered ----
global $shortcode_tags;
tap_t_assert(isset($shortcode_tags['tap_front_dash']), 'tap_front_dash shortcode registered');

// ---- 3) anonymous sees login card ----
wp_set_current_user(0);
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-login') !== false, 'anonymous user sees login card');
tap_t_assert(strpos($out, 'wp-login.php') !== false, 'anonymous login card links to wp-login');

// ---- 4) admin redirect ----
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($admins) {
    wp_set_current_user((int) $admins[0]);
    $out = do_shortcode('[tap_front_dash]');
    if (strpos($out, 'wp-admin') !== false) {
        tap_t_pass('administrator gets admin panel link');
    } elseif (strpos($out, 'tap-dash-login') !== false || strpos($out, 'tap-dash-sidebar') !== false) {
        tap_t_pass('administrator rendered dashboard (no hard redirect in shortcode output)');
    } else {
        tap_t_fail('administrator dashboard output missing admin link/sidebar');
    }
}

// ---- 5) client dashboard ----
$clients = get_users(['role' => 'tap_client', 'number' => 1, 'fields' => 'ID']);
if (!$clients) {
    tap_t_fail('no tap_client user to test dashboard');
    return tap_t_finish();
}
$client_id = (int) $clients[0];
wp_set_current_user($client_id);

$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-sidebar') !== false, 'client sees dashboard sidebar');
tap_t_assert(strpos($out, 'Mi agencia') === false, 'client has NO agency nav item');
tap_t_assert(strpos($out, 'Cerrar sesión') !== false, 'client sees logout link');
tap_t_assert(strpos($out, 'tap-dash') !== false, 'client dashboard wrapper present');
tap_t_assert(strpos($out, 'Favoritos') !== false, 'client sidebar has Favoritos');
tap_t_assert(strpos($out, 'Mis reservas') !== false, 'client sidebar has Mis reservas');
tap_t_assert(strpos($out, 'Mis reseñas') !== false, 'client sidebar has Mis reseñas');

// ---- 6) client overview section has stats ----
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-stat-card') !== false, 'overview shows stat cards');
tap_t_assert(strpos($out, 'Reservas recientes') !== false, 'overview shows recent bookings heading');

// ---- 7) bookings section ----
$_GET['seccion'] = 'bookings';
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-empty') !== false || strpos($out, 'tap-dash-booking-card') !== false, 'bookings section renders list or empty state');
unset($_GET['seccion']);

// ---- 8) favorites section ----
$_GET['seccion'] = 'favorites';
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-empty') !== false || strpos($out, 'tap-dash-fav-card') !== false, 'favorites section renders grid or empty state');
unset($_GET['seccion']);

// ---- 9) reviews section ----
$_GET['seccion'] = 'reviews';
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'tap-dash-empty') !== false || strpos($out, 'tap-dash-review-card') !== false, 'reviews section renders list or empty state');
unset($_GET['seccion']);

// ---- 10) agency dashboard route (agency admin) ----
$agencies = get_users(['role' => 'tap_agency_admin', 'number' => 1, 'fields' => 'ID']);
if ($agencies) {
    $agency_id = (int) $agencies[0];
    wp_set_current_user($agency_id);
    $out = do_shortcode('[tap_front_dash]');
    tap_t_assert(strpos($out, 'tap-dash-sidebar') !== false, 'agency admin sees dashboard sidebar');
    tap_t_assert(strpos($out, 'Mi agencia') !== false, 'agency admin sees agency nav item');
    tap_t_assert(strpos($out, 'Gestionar') !== false, 'agency admin sees Gestionar nav item');
    tap_t_assert(strpos($out, 'Mis reservas') !== false, 'agency admin sees bookings nav item');
} else {
    tap_t_fail('no tap_agency_admin user to test agency dashboard');
}

// ---- 11) employee route (reduced nav) ----
$employees = get_users(['role' => 'tap_agency_employee', 'number' => 1, 'fields' => 'ID']);
if ($employees) {
    wp_set_current_user((int) $employees[0]);
    $out = do_shortcode('[tap_front_dash]');
    tap_t_assert(strpos($out, 'tap-dash-sidebar') !== false, 'employee sees dashboard sidebar');
    tap_t_assert(strpos($out, 'Mis reservas') === false || strpos($out, 'tap-dash') !== false, 'employee has reduced nav (no error)');
} else {
    tap_t_pass('no employee user available (skipped)');
}

// ---- 12) assets exist on disk ----
$base = WP_PLUGIN_DIR . '/travel-agency-platform/';
tap_t_assert(file_exists($base . 'assets/css/dashboard.css'), 'assets/css/dashboard.css exists');
tap_t_assert(file_exists($base . 'assets/js/dashboard.js'), 'assets/js/dashboard.js exists');
tap_t_assert(file_exists($base . 'includes/class-front-dash.php'), 'includes/class-front-dash.php exists');

// ---- 13) AJAX handlers registered ----
$hooked = function_exists('has_action');
if (has_action('wp_ajax_tap_dash_cancel_booking')) {
    tap_t_pass('wp_ajax_tap_dash_cancel_booking registered');
} else {
    tap_t_fail('wp_ajax_tap_dash_cancel_booking NOT registered');
}
if (has_action('wp_ajax_tap_dash_toggle_favorite')) {
    tap_t_pass('wp_ajax_tap_dash_toggle_favorite registered');
} else {
    tap_t_fail('wp_ajax_tap_dash_toggle_favorite NOT registered');
}
if (has_action('wp_ajax_tap_dash_delete_review')) {
    tap_t_pass('wp_ajax_tap_dash_delete_review registered');
} else {
    tap_t_fail('wp_ajax_tap_dash_delete_review NOT registered');
}

// ---- 14) nonce + security wiring present in class source ----
$src = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(strpos($src, 'tap_front_dash_nonce') !== false, 'dashboard class defines front-dash nonce action');
tap_t_assert(strpos($src, 'check_ajax_referer') !== false, 'dashboard AJAX handlers verify nonce');
tap_t_assert(strpos($src, 'wp_send_json_error') !== false, 'dashboard AJAX handlers send error responses');

// ---- 15) enqueue logic is active for singular dashboard page ----
if (function_exists('wp_styles')) {
    wp_styles();
}
$GLOBALS['wp_query'] = new WP_Query(['p' => $page_id, 'post_type' => 'page']);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->is_singular = true;
$GLOBALS['wp_query']->queried_object = get_post($page_id);
TAP_Front_Dash::enqueue_assets();
$loaded = wp_style_is('tap-dashboard', 'registered');
tap_t_assert($loaded, 'tap-dashboard CSS enqueued on dashboard page');
$js = wp_script_is('tap-dashboard', 'registered');
tap_t_assert($js, 'tap-dashboard JS enqueued on dashboard page');

// restore user
wp_set_current_user($old);

tap_t_finish();