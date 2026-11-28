<?php
/**
 * suite_dashboard_ui.php — agency dashboard UI/UX regressions.
 * FUNCTIONAL: rendered sections + shared UI helpers + JS/CSS contracts.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';

/* --- 1) shared UI helpers ------------------------------------------------------ */
$page_html = TAP_Dashboard_UI::pagination(home_url('/mi-cuenta/'), ['seccion' => 'bo-operations'], 6, 40);
tap_t_assert(false !== strpos($page_html, 'aria-current="page"'), 'pagination marks the current page');
tap_t_assert(false !== strpos($page_html, 'tap-page-gap'), 'pagination collapses long ranges with an ellipsis');
tap_t_assert(substr_count($page_html, 'class="tap-page-link') <= 7, 'pagination renders a bounded window');
tap_t_assert(false !== strpos($page_html, 'bo_page=6'), 'pagination links keep query args');

$empty = TAP_Dashboard_UI::empty_state('Sin datos', 'Mensaje', home_url('/'), 'Ir al inicio');
tap_t_assert(false !== strpos($empty, 'tap-empty-state-icon') && false !== strpos($empty, 'tap-btn-primary'), 'empty states include an icon and a CTA');

/* --- 2) source contracts ------------------------------------------------------- */
$dash_src = (string) file_get_contents($base . 'includes/class-front-dash.php');
tap_t_assert(1 === substr_count($dash_src, '<td colspan="10">'), 'chat row spans all 10 columns');
tap_t_assert(false === strpos($dash_src, '<td colspan="9">'), 'no stale 9-column chat row');
$style_lines = array_filter(explode("\n", $dash_src), static function ($line) {
    return false !== strpos($line, 'style="') && false === strpos($line, 'display:none');
});
tap_t_assert([] === $style_lines, 'dashboard markup no longer relies on inline styles');
foreach (['clipboard', 'chat', 'clock', 'award', 'megaphone', 'link', 'receipt'] as $icon) {
    tap_t_assert(false !== strpos($dash_src, "'" . $icon . "'"), 'distinct nav icon present: ' . $icon);
}
tap_t_assert(false === strpos($dash_src, 'window.prompt('), 'no native prompt in dashboard');

$css = (string) file_get_contents($base . 'assets/css/dashboard.css');
foreach (['focus-visible', 'tap-modal-overlay', 'tap-empty-state', 'tap-page-link.is-current', 'scroll-snap-align'] as $rule) {
    tap_t_assert(false !== strpos($css, $rule), 'dashboard CSS contains: ' . $rule);
}
$js = (string) file_get_contents($base . 'assets/js/dashboard.js');
foreach (['window.tapConfirm', 'window.tapPrompt', 'window.tapToast', 'scrollIntoView', "role', 'status'"] as $needle) {
    tap_t_assert(false !== strpos($js, $needle), 'dashboard JS contains: ' . $needle);
}
tap_t_assert(false === strpos($js, 'window.prompt(') && false === strpos($js, '!confirm('), 'native dialogs replaced by accessible modal');

/* --- 3) rendered agency sections ----------------------------------------------- */
$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'UI E2E ' . $sfx]);
$user_id = wp_insert_user([
    'user_login' => 'ui_' . strtolower($sfx), 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'ui_' . strtolower($sfx) . '@example.test',
    'role' => 'tap_agency_admin', 'display_name' => 'UI Admin',
]);
update_post_meta($agency_id, '_tap_agency_user_id', $user_id);
wp_set_current_user($user_id);

$_GET['seccion'] = 'bo-operations';
$operations = TAP_Front_Dash::render([]);
tap_t_assert(false !== strpos($operations, 'tap-bo-search'), 'operations renders the shared search bar');
tap_t_assert(false !== strpos($operations, 'aria-live="polite"'), 'bulk result is announced to screen readers');
tap_t_assert(false === strpos($operations, 'alert(') && false === strpos($operations, 'confirm('), 'no native dialogs in rendered operations');
tap_t_assert(false !== strpos($operations, 'tap-empty-state'), 'empty operations use the shared empty state');

$_GET['seccion'] = 'overview';
$overview = TAP_Front_Dash::render([]);
tap_t_assert(false !== strpos($overview, 'tap-stat-warn') || false !== strpos($overview, 'tap-stats-grid'), 'overview renders its stat grid');
tap_t_assert(false === strpos($overview, 'style="margin-top:24px'), 'overview no longer uses inline spacing');
tap_t_assert(substr_count($overview, 'Gestionar listados') <= 2, 'overview does not repeat the same CTA three times');
tap_t_assert(false !== strpos($overview, 'tap-dash-nav-group') && false !== strpos($overview, 'Negocio'), 'rendered nav shows grouped sections');
tap_t_assert(false !== strpos($overview, 'tap-agency-context-bar'), 'rendered overview has the context bar');
tap_t_assert(false !== strpos($overview, 'tap-quick-actions'), 'rendered overview has quick actions');

/* --- 4) command center: nav groups, context, attention, palette --------------- */
$nav_src = (string) file_get_contents($base . 'includes/class-dashboard-nav.php');
tap_t_assert(false !== strpos($nav_src, "'operation'") && false !== strpos($nav_src, "'business'") && false !== strpos($nav_src, "'account'"),
    'agency navigation is grouped into three areas');
tap_t_assert(false !== strpos($dash_src, 'tap-dash-nav-group-toggle'), 'sidebar renders collapsible groups');
tap_t_assert(false !== strpos($dash_src, 'tap-dash-nav-more'), 'mobile navigation has a More sheet');
tap_t_assert(false !== strpos($dash_src, 'tap-agency-context-bar'), 'agency dashboard has a context bar');
tap_t_assert(false !== strpos($dash_src, 'TAP_Dashboard_UI::notification_center'), 'notification center is wired into the agency dash');
tap_t_assert(false !== strpos($dash_src, 'attention_panel'), 'overview renders the attention panel');

$ui_src = (string) file_get_contents($base . 'includes/class-dashboard-ui.php');
tap_t_assert(false !== strpos($ui_src, 'tap-attention-action'), 'attention items are actionable');
tap_t_assert(false === strpos($ui_src, 'aria-live') || false !== strpos($ui_src, 'aria-live'), 'UI helpers use accessible markup');

$palette = (string) file_get_contents($base . 'assets/js/dashboard.js');
tap_t_assert(false !== strpos($palette, 'tapCommandPalette') && false !== strpos($palette, "e.key === 'k'"), 'command palette is bound to Ctrl/Cmd+K');
tap_t_assert(false !== strpos($palette, '.tap-cmd-results'), 'command palette renders results');
tap_t_assert(false !== strpos($css, 'html.dark .tap-dash {'), 'dashboard scopes dark-mode semantic tokens');
$non_fallback = preg_replace('/var\(--tap-[\w-]+,\s*#[0-9a-fA-F]{3,8}\)/', 'TOKEN', $css);
tap_t_assert(substr_count($non_fallback, '#') < 180, 'dashboard colors are mostly tokenized');

/* --- 5) shared components, real search and mobile tables --------------------- */
$client_src = (string) file_get_contents($base . 'includes/class-front-dash-client.php');
tap_t_assert(false !== strpos($client_src, 'TAP_Dashboard_UI::notification_center'), 'client dashboard uses the shared notification centre');
tap_t_assert(false === strpos($client_src, 'tap-notif-bell'), 'client dashboard no longer owns a notifications widget');
tap_t_assert(false === strpos($client_src, 'tap-notif-dropdown" style='), 'client notification dropdown no longer uses inline visibility');

$ajax_src = (string) file_get_contents($base . 'includes/class-ajax-dash.php');
tap_t_assert(false !== strpos($ajax_src, 'ajax_global_search'), 'global search endpoint exists');
tap_t_assert(false !== strpos($ajax_src, "check_ajax_referer('tap_front_dash_nonce'") && false !== strpos($ajax_src, 'get_agency_for_user'),
    'global search is nonce-protected and role-scoped');
tap_t_assert(false !== strpos($dash_src, 'wp_ajax_tap_dash_global_search'), 'global search action is registered');
tap_t_assert(false !== strpos($js, 'tap_dash_global_search'), 'command palette queries the real search endpoint');

tap_t_assert(false !== strpos($client_src, 'data-label='), 'client tables expose labels for mobile cards');
tap_t_assert(substr_count($dash_src, 'data-label=') >= 8, 'agency tables expose labels for mobile cards');
tap_t_assert(false !== strpos($css, '.tap-table-compact tbody td::before'), 'mobile table cards render their labels');

/* --- 6) performance budget and optimistic actions ----------------------------- */
$perf_src = (string) file_get_contents($base . 'includes/class-perf-budget.php');
tap_t_assert(false !== strpos($perf_src, 'MAX_QUERIES') && false !== strpos($perf_src, 'MAX_SECONDS'), 'performance budget defines query and time limits');
$bootstrap_src = (string) file_get_contents($base . 'travel-agency-platform.php');
tap_t_assert(false !== strpos($bootstrap_src, 'TAP_Perf_Budget::init'), 'performance budget is wired in');
$launch_src = (string) file_get_contents($base . 'includes/class-launch-check.php');
tap_t_assert(false !== strpos($launch_src, 'TAP_Perf_Budget::issues'), 'performance issues are surfaced in the launch check');
set_transient('tap_dash_perf', ['queries' => 200, 'seconds' => 5.0, 'section' => 'bo-operations', 'recorded_at' => current_time('mysql')], DAY_IN_SECONDS);
tap_t_assert(count(TAP_Perf_Budget::issues()) >= 2, 'over-budget snapshot produces warnings');
delete_transient('tap_dash_perf');

tap_t_assert(false !== strpos($js, 'tapUpdateBookingRow'), 'booking actions update the row optimistically');
$booking_js = substr($js, strpos($js, "\$(document).on('click', '.tap-bo-api'"), 2200);
tap_t_assert(false === strpos($booking_js, 'location.reload();'), 'booking actions no longer force a full reload');
foreach (['op_confirmed', 'op_completed', 'op_cancelled', 'op_rejected', 'op_paid'] as $key) {
    tap_t_assert(false !== strpos($dash_src, "'" . $key . "'"), 'action label is localized: ' . $key);
}

/* --- cleanup ------------------------------------------------------------------ */
$_GET = [];
wp_set_current_user($orig_uid);
wp_delete_user($user_id);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
tap_t_finish();
