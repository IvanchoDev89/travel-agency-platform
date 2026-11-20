<?php
/**
 * suite_agency_panel.php — agency back-office presentation/guard regressions:
 * payout failed dead-end, onboarding wiring, session-expiry redirect,
 * signup commission copy, payout/audit labels, disputes section, dual-role nav.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');

// 1) Payout request form available in the failed-payout state
tap_t_assert(strpos($src, 'private static function payout_request_form') !== false, 'payout form extracted to a reusable helper');
tap_t_assert(substr_count($src, 'self::payout_request_form(') >= 2, 'payout form used in both normal and failed states');
tap_t_assert(strpos($src, 'Solicita de nuevo una liquidación') === false, 'no misleading "request again" copy without a form');

// 2) Onboarding: real selector + correct nonce
tap_t_assert(strpos($src, 'a[href*="manage-listing"]') !== false, 'onboarding targets the real "Nuevo" link');
tap_t_assert(strpos($src, "data-onboarding=\"new-listing\"") === false, 'onboarding no longer targets a missing element');
tap_t_assert(strpos($src, "(window.tapDash && window.tapDash.nonce)") !== false, 'onboarding posts the front-dash nonce');

// 3) Session expiry redirect: real login URL, not /login/
tap_t_assert(strpos($src, "home_url('/login/')") === false, 'no redirect to nonexistent /login/');
$leads_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-leads.php');
tap_t_assert(strpos($leads_src, "home_url('/login/')") === false, 'leads handler uses a real login URL too');

// 4) Signup commission copy uses the configured option
$acc_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-shortcode-account.php');
tap_t_assert(strpos($acc_src, "get_option('tap_commission_default'") !== false, 'signup copy reads the configured commission');
tap_t_assert(strpos($acc_src, 'Comisión típica: 10%') === false, 'signup copy no longer hardcodes 10%');

// 5) Raw payout/audit codes are mapped to labels
tap_t_assert(method_exists('TAP_Front_Dash', 'payout_method_label'), 'payout method label helper exists');
tap_t_assert(method_exists('TAP_Front_Dash', 'audit_action_label'), 'audit action label helper exists');
tap_t_assert(TAP_Front_Dash::payout_method_label('bank_transfer') !== 'bank_transfer', 'raw payout method is translated');
tap_t_assert(TAP_Front_Dash::audit_action_label('booking #123') !== 'booking #123', 'raw audit code is translated');

// 6) Disputes section is reachable
tap_t_assert(strpos($src, "case 'bo-disputas'") !== false, 'disputas route exists');
tap_t_assert(strpos($src, "['key' => 'bo-disputas'") !== false, 'disputas nav item exists');
tap_t_assert(method_exists('TAP_Front_Dash', 'agency_disputes'), 'disputas renderer exists');

// 7) Dual-role users use canonical admin role for section access
tap_t_assert(strpos($src, 'private static function is_employee_only') !== false, 'canonical employee-only helper exists');
tap_t_assert(strpos($src, 'if (TAP_Roles::is_agency_employee($user))') === false, 'section guards no longer use the pure role check');

tap_t_finish();
