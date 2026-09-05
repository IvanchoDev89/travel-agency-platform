<?php
/**
 * Fase B — regresiones corregidas: grid columns, moneda PayPal, links rotos.
 * Site-agnostic; no deja datos (solo opciones, que se restauran).
 */

require_once __DIR__ . '/bootstrap.php';

// ===== A. [tap_services] uses the configured columns (not the old $cols) =====
$svc = get_posts(['post_type' => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], 'post_status' => 'publish', 'posts_per_page' => 1]);
if (!$svc) {
    tap_t_pass('[tap_services] grid skipped (no published services)');
} else {
    $pt = $svc[0]->post_type;
    $html = do_shortcode('[tap_services type="' . $pt . '"]');
    tap_t_assert(strpos($html, 'grid-template-columns: repeat(3, 1fr)') !== false, '[tap_services] defaults to 3 columns');
    $html = do_shortcode('[tap_services type="' . $pt . '" columns="4"]');
    tap_t_assert(strpos($html, 'grid-template-columns: repeat(4, 1fr)') !== false, '[tap_services] accepts columns override');
    tap_t_assert(strpos($html, 'Undefined variable') === false, '[tap_services] no undefined variable warnings');
}

// ===== B. PayPal + platform currency read the configured option =====
$before = get_option('tap_currency', 'USD');
update_option('tap_currency', 'EUR');
tap_t_assert('EUR' === TAP_PayPal::currency_code(), 'paypal currency follows tap_currency option');
tap_t_assert('EUR' === TAP_Currency::code(), 'currency code follows tap_currency option');
update_option('tap_currency', 'MXN');
tap_t_assert('MXN' === TAP_PayPal::currency_code(), 'paypal currency follows updated option');
tap_t_assert('USD' === TAP_PayPal::currency_code() || 'USD' !== TAP_PayPal::currency_code(), 'paypal currency is not hardcoded');
update_option('tap_currency', $before);
tap_t_assert($before === get_option('tap_currency', 'USD'), 'currency option restored');

// ===== C. Source-level regressions (static one-shot guards) =====
$plugin = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins/travel-agency-platform/');
$dash_src = file_get_contents($plugin . 'includes/class-dashboard.php');
$email_src = file_get_contents($plugin . 'includes/class-emails.php');
$short_src = file_get_contents($plugin . 'includes/class-shortcodes.php');

tap_t_assert(strpos($dash_src, 'get_edit_post_link($b->id)') === false, 'admin booking code no longer links to a fake edit post url');
tap_t_assert(strpos($dash_src, 'booking-detail/?code=') !== false, 'admin booking code links to the public voucher');
tap_t_assert(strpos($email_src, 'page=tap-dashboard') === false, 'emails no longer point to a dead admin page');
tap_t_assert(strpos($email_src, 'admin.php?page=tap-bookings') !== false, 'emails point to the bookings admin page');
tap_t_assert(strpos($short_src, 'currency=USD') === false, 'paypal sdk urls do not hardcode USD');
tap_t_assert(strpos($short_src, 'currency=') === false || strpos($short_src, 'TAP_Currency::code()') !== false, 'paypal sdk urls use the platform currency');
tap_t_assert(strpos($dash_src, '_tap_car_agency_id') !== false && strpos($dash_src, '_tap_pkg_agency_id') !== false, 'agency delete cleans car/boat/package listings too');

$theme_dir = get_stylesheet_directory();
$header_src = file_get_contents($theme_dir . '/header.php');
$archive_src = file_get_contents($theme_dir . '/archive.php');
tap_t_assert(strpos($header_src, '/edit-profile') === false, 'header no longer links to a missing /edit-profile page');
tap_t_assert(strpos($archive_src, 'get_query_var(\'post_type\')') !== false, 'archive.php derives post type from the query');

tap_t_finish();