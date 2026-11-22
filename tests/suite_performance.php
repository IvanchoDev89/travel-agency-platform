<?php
/**
 * suite_performance.php — regression guards for the admin performance pass.
 * FUNCTIONAL: real renders/DB rows, not just source-string assertions.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_performance.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$cleanup_users = [];
$cleanup_posts = [];

/* --- 1) agencies_page() must NOT write on a plain GET ----------------------- */
$admin_uid = wp_insert_user([
    'user_login'   => 'tap_perf_' . $sfx,
    'user_pass'    => tap_t_unique_pass(),
    'user_email'   => 'tap_perf_' . $sfx . '@example.test',
    'role'         => 'administrator',
    'display_name' => 'TAP Perf Admin',
]);
tap_t_assert(!is_wp_error($admin_uid) && $admin_uid > 0, 'fixture admin created');
if (!is_wp_error($admin_uid)) { $cleanup_users[] = (int) $admin_uid; }

$agency_post = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'PERF-OLD-' . $sfx,
]);
tap_t_assert((int) $agency_post > 0, 'fixture agency post created');
if ((int) $agency_post > 0) {
    $cleanup_posts[] = (int) $agency_post;
    update_post_meta($agency_post, '_tap_agency_user_id', (int) $admin_uid);
    $wpdb->insert($wpdb->prefix . 'tap_agencies', [
        'name'     => 'PERF-OLD-' . $sfx,
        'slug'     => 'perf-old-' . $sfx,
        'user_id'  => (int) $admin_uid,
        'is_active'=> 1,
    ]);
    $row_id = (int) $wpdb->insert_id;
    tap_t_assert($row_id > 0, 'fixture registry row created');

    // Drift: post title changed but registry still has the old value.
    wp_update_post(['ID' => $agency_post, 'post_title' => 'PERF-NEW-' . $sfx]);
    wp_set_current_user((int) $admin_uid);
    ob_start();
    TAP_Dashboard_Content::agencies_page();
    ob_end_clean();
    $name_after_get = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tap_agencies WHERE id = %d", $row_id
    ));
    tap_t_assert('PERF-OLD-' . $sfx === $name_after_get, 'rendering the agencies page does not sync the registry on GET');

    // The cron/mutation path must still do the reconciliation.
    TAP_Dashboard_Content::sync_agency_registry();
    $name_after_sync = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tap_agencies WHERE id = %d", $row_id
    ));
    tap_t_assert('PERF-NEW-' . $sfx === $name_after_sync, 'explicit sync still reconciles the registry');

    $wpdb->delete($wpdb->prefix . 'tap_agencies', ['id' => $row_id]);
}

tap_t_assert(
    false !== has_action('tap_agency_registry_hook', ['TAP_Dashboard_Content', 'sync_agency_registry']),
    'registry sync is scheduled via cron hook'
);

/* --- 2) Chart.js: single footer handle, no raw <script src> in body ---------- */
do_action('wp_enqueue_scripts');
$chart_user = get_users(['role' => 'tap_agency_admin', 'number' => 1, 'fields' => 'ids']);
if ($chart_user) {
    wp_set_current_user((int) $chart_user[0]);
    $_GET['seccion'] = 'bo-finances';
    $chart_html = TAP_Front_Dash::render([]);
    unset($_GET['seccion']);
    tap_t_assert(false === strpos($chart_html, 'chart.umd.min.js'), 'agency panels do not inject raw Chart.js script tags');
    $chart = wp_scripts()->registered['tap-chart'] ?? null;
    tap_t_assert($chart && 1 === (int) ($chart->extra['group'] ?? 0), 'Chart.js is registered for the footer');
    tap_t_assert((bool) wp_scripts()->get_data('tap-chart', 'after'), 'chart initializer is attached as inline script');
    wp_set_current_user($orig_uid);
}

/* --- 3) email log retention -------------------------------------------------- */
$log_table = $wpdb->prefix . 'tap_email_log';
$old_id = 0;
$new_id = 0;
$wpdb->insert($log_table, [
    'template_key' => 'tap_perf_old', 'recipient' => 'perf@example.test',
    'subject' => 'old', 'status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s', strtotime('-120 days')),
]);
$old_id = (int) $wpdb->insert_id;
$wpdb->insert($log_table, [
    'template_key' => 'tap_perf_new', 'recipient' => 'perf@example.test',
    'subject' => 'new', 'status' => 'sent', 'sent_at' => current_time('mysql'),
]);
$new_id = (int) $wpdb->insert_id;
$deleted = TAP_Email_Templates::prune_log(60);
tap_t_assert($deleted > 0, 'prune_log deletes rows older than the retention window');
tap_t_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$log_table} WHERE id = %d", $old_id)), 'old email-log row removed');
tap_t_assert((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$log_table} WHERE id = %d", $new_id)), 'recent email-log row kept');
tap_t_assert(false !== has_action('tap_data_retention_hook'), 'daily data-retention hook is registered');

/* --- 4) map transient purge -------------------------------------------------- */
set_transient('tap_map_data_v9_tap_perf', ['x' => 1], HOUR_IN_SECONDS);
set_transient('tap_services_map_data_v3_tap_perf', ['x' => 1], HOUR_IN_SECONDS);
$ver_before = (int) get_option('tap_map_cache_version', 1);
TAP_Destination_Spots::clear_map_cache();
tap_t_assert((int) get_option('tap_map_cache_version', 1) > $ver_before, 'clearing map cache bumps the version');
tap_t_assert(false === get_transient('tap_map_data_v9_tap_perf'), 'old map transient purged on invalidation');
tap_t_assert(false === get_transient('tap_services_map_data_v3_tap_perf'), 'old services-map transient purged on invalidation');
tap_t_assert(false !== has_action('tap_data_retention_hook'), 'transient sweep shares the daily retention hook');

/* --- 5) cohort retention keeps its shape with one query --------------------- */
$cohort_client = 990000 + wp_rand(1, 9999);
$cohort_ids = [];
$cohort_month = gmdate('Y-m', strtotime('first day of 3 months ago'));
$return_month = gmdate('Y-m', strtotime('first day of 2 months ago'));
$cohort_ids[] = tap_t_seed_booking([
    'booking_code' => 'PERF-COH-' . $sfx,
    'client_id'    => $cohort_client,
    'created_at'   => gmdate('Y-m-d H:i:s', strtotime('first day of 3 months ago')),
]);
$cohort_ids[] = tap_t_seed_booking([
    'booking_code' => 'PERF-COH2-' . $sfx,
    'client_id'    => $cohort_client,
    'created_at'   => gmdate('Y-m-d H:i:s', strtotime('first day of 2 months ago')),
]);
$cohorts = TAP_Analytics::cohort_retention(6);
$mine = null;
foreach ($cohorts as $c) {
    if ($c['cohort'] === $cohort_month) { $mine = $c; break; }
}
tap_t_assert($mine && $mine['size'] >= 1, 'cohort retention finds the seeded client');
tap_t_assert($mine && 5 === count($mine['returns']), 'cohort returns keeps the fixed months-1 key map');
tap_t_assert($mine && ($mine['returns'][$return_month] ?? 0) >= 1, 'returning client is counted in the next month');

/* --- 6) rating priming: one query, correct stats from cache ------------------ */
$rating_post = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'TAP rating perf ' . $sfx,
]);
if ((int) $rating_post > 0) {
    $cleanup_posts[] = (int) $rating_post;
    wp_cache_delete('tap_rating_tap_tour_' . (int) $rating_post, 'tap_ratings');
    $wpdb->insert($wpdb->prefix . 'tap_reviews', [
        'service_type' => 'tap_tour', 'service_id' => (int) $rating_post,
        'user_id' => (int) $admin_uid, 'rating' => 4.0, 'is_approved' => 1,
    ]);
    $review_id = (int) $wpdb->insert_id;
    TAP_API::prime_rating_stats([['tap_tour', (int) $rating_post]]);
    $stats = TAP_API::get_rating_stats('tap_tour', (int) $rating_post);
    tap_t_assert(abs((float) $stats['avg'] - 4.0) < 0.01, 'primed rating stats return the real average');
    tap_t_assert(1 === (int) $stats['count'], 'primed rating stats return the real count');
    tap_t_assert(false !== wp_cache_get('tap_rating_tap_tour_' . (int) $rating_post, 'tap_ratings'), 'ratings are cached for the request');
    $wpdb->delete($wpdb->prefix . 'tap_reviews', ['id' => $review_id]);
    wp_cache_delete('tap_rating_tap_tour_' . (int) $rating_post, 'tap_ratings');
}

/* --- 7) Spanish admin + mobile layout -------------------------------------- */
$plugin_dir = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins/travel-agency-platform/');

$agency_user = get_users(['role' => 'tap_agency_admin', 'number' => 1, 'fields' => 'ids']);
if ($agency_user) {
    wp_set_current_user((int) $agency_user[0]);
    $_GET['seccion'] = 'bo-operations';
    $ops_html = TAP_Front_Dash::render([]);
    unset($_GET['seccion']);
    tap_t_assert(false !== strpos($ops_html, 'Error de red'), 'agency operations error message is in Spanish');
    tap_t_assert(false === strpos($ops_html, 'Network error'), 'no raw English network error remains');
}

wp_set_current_user((int) $admin_uid);
ob_start();
TAP_Dashboard_Insights::reports_page();
$reports_html = ob_get_clean();
tap_t_assert(false !== strpos($reports_html, 'Tarifas de reserva'), 'reports header uses the translated Booking fees label');
tap_t_assert(false === strpos($reports_html, '>Booking fees<'), 'no raw English Booking fees header remains');

$pricing_css = (string) file_get_contents($plugin_dir . 'assets/css/admin-pricing.css');
tap_t_assert(false !== strpos($pricing_css, '@media (max-width: 640px)'), 'pricing dialog has a mobile breakpoint');
tap_t_assert(false !== strpos($pricing_css, 'min-width: 0'), 'pricing dialog drops the fixed 420px minimum on mobile');
$front_src = (string) file_get_contents($plugin_dir . 'includes/class-front-dash.php');
tap_t_assert(false !== strpos($front_src, 'bottom: 12px'), 'onboarding tooltip docks to the bottom on mobile');
tap_t_assert(false !== strpos($front_src, 'window.innerWidth - tipWidth'), 'onboarding tooltip is clamped to the viewport');

/* --- 8) [tap_help_article] renders (was registered with no callback) -------- */
$help_post = wp_insert_post([
    'post_type'   => 'tap_help',
    'post_status' => 'publish',
    'post_title'  => 'PERF Help ' . $sfx,
    'post_content'=> 'Contenido de prueba del artículo.',
]);
if ((int) $help_post > 0) {
    $cleanup_posts[] = (int) $help_post;
    $article_html = do_shortcode('[tap_help_article id="' . (int) $help_post . '"]');
    tap_t_assert(false !== strpos($article_html, 'PERF Help ' . $sfx), 'tap_help_article renders the article by id');
    tap_t_assert(false !== strpos($article_html, 'Volver al centro de soporte'), 'article includes the translated back link');
    $missing_html = do_shortcode('[tap_help_article slug="no-existe-' . $sfx . '"]');
    tap_t_assert(false !== strpos($missing_html, 'Artículo no encontrado.'), 'unknown article shows a translated empty state');
    wp_delete_post($help_post, true);
    $cleanup_posts = array_diff($cleanup_posts, [(int) $help_post]);
}

/* --- cleanup ---------------------------------------------------------------- */
tap_t_cleanup_bookings($cohort_ids);
$wpdb->delete($log_table, ['id' => $old_id]);
$wpdb->delete($log_table, ['id' => $new_id]);
delete_transient('tap_map_data_v9_tap_perf');
delete_transient('tap_services_map_data_v3_tap_perf');
wp_set_current_user($orig_uid);
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
foreach ($cleanup_users as $uid) { wp_delete_user($uid); }

tap_t_finish();
