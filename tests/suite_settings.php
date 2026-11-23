<?php
/**
 * suite_settings.php — admin settings functionality and feedback.
 * FUNCTIONAL: real page renders, real sanitizers, real hooks.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_settings.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$plugin = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins/travel-agency-platform/';

/* --- 1) broken submit paths are wired ---------------------------------------- */
tap_t_assert(false !== has_action('admin_post_tap_email_campaign_save', ['TAP_Email_Campaigns', 'handle_save']), 'campaign save uses the real class');
tap_t_assert(false === has_action('admin_post_tap_email_campaign_save', ['TAP_Email_Campaigns_Admin', 'handle_save']), 'no callback points at the missing Admin class');
tap_t_assert(false !== has_action('admin_post_tap_lead_form', ['TAP_Leads', 'handle_newsletter']), 'newsletter admin-post handler exists');
tap_t_assert(false !== has_action('admin_post_nopriv_tap_lead_form', ['TAP_Leads', 'handle_newsletter']), 'anonymous newsletter handler exists');

$waiver_service = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'TAP waiver service ' . $sfx,
]);
update_post_meta($waiver_service, '_tap_tour_waiver_required', '1');
$waiver_booking = tap_t_seed_booking(['service_type' => 'tap_tour', 'service_id' => (int) $waiver_service]);
$waiver = TAP_Waiver::render_form($waiver_booking);
tap_t_assert(false !== strpos($waiver, 'admin-post.php'), 'waiver form targets admin-post.php');
tap_t_assert(false !== strpos($waiver, 'tap_sign_waiver'), 'waiver form keeps its action field');

$_GET = ['tap_newsletter' => 'ok'];
ob_start();
TAP_Leads::newsletter_feedback();
$feedback = ob_get_clean();
unset($_GET['tap_newsletter']);
tap_t_assert(false !== strpos($feedback, 'Te has suscrito'), 'newsletter confirmation is rendered');

/* --- 2) options.php capability filters --------------------------------------- */
tap_t_assert('tap_manage_settings' === apply_filters('option_page_capability_tap_settings', 'manage_options'), 'tap_settings save cap matches its page cap');
tap_t_assert('tap_manage_settings' === apply_filters('option_page_capability_tap_privacy_group', 'manage_options'), 'privacy save cap matches its page cap');

/* --- 3) every registered option has a field on its page ---------------------- */
TAP_Dashboard_Settings::register_settings();
TAP_Privacy::register_settings();
TAP_Discounts::register_settings();
TAP_Customer_Referrals::register_settings();
TAP_Security_Headers::register_settings();
$wl = $GLOBALS['new_whitelist_options'];

$snapshot = [];
foreach ($wl as $opts) {
    foreach ((array) $opts as $name) { $snapshot[$name] = get_option($name, null); }
}

if ($admin) {
    wp_set_current_user((int) $admin[0]);

    ob_start();
    TAP_Dashboard_Settings::settings_page();
    $html = ob_get_clean();
    $missing = [];
    foreach ((array) ($wl['tap_settings'] ?? []) as $name) {
        if (!preg_match('/name=[\"\']' . preg_quote($name, '/') . '[\"\']/', $html)) { $missing[] = $name; }
    }
    tap_t_assert([] === $missing, 'all tap_settings options have a form field (missing: ' . implode(',', $missing) . ')');
    tap_t_assert(false !== strpos($html, 'name="tap_unpaid_alert_days"'), 'unpaid alert days field is present');

    // settings_errors() must actually print queued messages.
    add_settings_error('tap_settings', 'tap_e2e', 'MENSAJE-E2E-SETTINGS');
    ob_start();
    TAP_Dashboard_Settings::settings_page();
    $with_error = ob_get_clean();
    tap_t_assert(false !== strpos($with_error, 'MENSAJE-E2E-SETTINGS'), 'settings page prints validation feedback');
}

/* --- 4) secrets are never echoed back into the form -------------------------- */
$secret = 'SECRETO-E2E-' . $sfx;
$prev_secret = get_option('tap_paypal_secret', null);
update_option('tap_paypal_secret', $secret);
if ($admin) {
    ob_start();
    TAP_Dashboard_Settings::settings_page();
    $secret_html = ob_get_clean();
    tap_t_assert(false === strpos($secret_html, $secret), 'stored PayPal secret is not rendered in the form');
    tap_t_assert(false !== strpos($secret_html, 'Dejar vacío para mantener'), 'secret field explains how to keep the value');
}
$sanitizers = get_registered_settings();
$cb = $sanitizers['tap_paypal_secret']['sanitize_callback'] ?? null;
tap_t_assert($cb && $secret === $cb(''), 'empty secret submit keeps the current value');
if (null === $prev_secret) { delete_option('tap_paypal_secret'); } else { update_option('tap_paypal_secret', $prev_secret); }

/* --- 5) sanitizer round-trips ------------------------------------------------- */
$cases = [
    'tap_currency' => ['EUR', 'EUR'],
    'tap_currency:bad' => ['XX', 'USD'],
    'tap_booking_fee_type' => ['percent', 'percent'],
    'tap_booking_fee_value' => ['-5', 0.0],
    'tap_unpaid_alert_days' => ['0', 0],
];
foreach ($cases as $label => $case) {
    $name = explode(':', $label)[0];
    $reg = $sanitizers[$name] ?? [];
    $cb = $reg['sanitize_callback'] ?? null;
    if (!$cb) { continue; }
    $out = $cb($case[0]);
    tap_t_assert($out == $case[1], "{$name} sanitizer round-trips " . var_export($case[0], true));
}

/* --- 6) admin CSS has no half-finished artifacts ------------------------------ */
$css = (string) file_get_contents($plugin . 'assets/css/admin-modern.css');
tap_t_assert(false === strpos($css, "\nCSS\n") && false === strpos($css, 'echo done'), 'admin CSS has no heredoc leftovers');
foreach (['#adminmenu', '#wpadminbar', '.wp-list-table', '.button-primary', '.card '] as $global_selector) {
    tap_t_assert(false === strpos($css, $global_selector), 'admin CSS avoids global selector ' . $global_selector);
}

/* --- 7) admin actions that silently ignored form fields --------------------- */
$aff_id = TAP_Affiliates::create([
    'name' => 'Afiliado E2E ' . $sfx, 'email' => 'af-' . $sfx . '@example.test',
    'code' => 'E2E' . strtoupper(substr($sfx, 0, 6)), 'status' => 'active',
]);
tap_t_assert((int) $aff_id > 0, 'affiliate created');
$aff_row = TAP_Affiliates::get($aff_id);
tap_t_assert($aff_row && $aff_row->code === 'E2E' . strtoupper(substr($sfx, 0, 6)), 'affiliate code from the form is saved');
tap_t_assert($aff_row && 'active' === $aff_row->status, 'affiliate status from the form is honoured');

if ($admin) {
    set_transient('tap_dispute_notice', ['error' => true, 'message' => 'ERROR-E2E-DISPUTA'], 60);
    ob_start();
    TAP_Dashboard_Money::disputes_page();
    $disputes_html = ob_get_clean();
    tap_t_assert(false !== strpos($disputes_html, 'notice-error'), 'dispute errors render as errors, not success');
    tap_t_assert(false !== strpos($disputes_html, 'ERROR-E2E-DISPUTA'), 'dispute error message is shown');
    delete_transient('tap_dispute_notice');
}

$campaign_src = (string) file_get_contents($plugin . 'includes/class-email-campaigns.php');
tap_t_assert(false !== strpos($campaign_src, "'status'       => \$scheduled_at ? 'scheduled' : 'draft'"), 'scheduled campaigns are not forced to draft');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete(TAP_Affiliates::table(), ["id" => (int) $aff_id]);
wp_delete_post($waiver_service, true);
tap_t_cleanup_bookings([$waiver_booking]);
foreach ($snapshot as $name => $value) {
    if (null === $value) { delete_option($name); } else { update_option($name, $value); }
}
wp_set_current_user($orig_uid);
tap_t_finish();
