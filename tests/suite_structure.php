<?php
/**
 * suite_structure.php — shared abstractions and presentability regressions.
 */
require_once __DIR__ . '/bootstrap.php';

$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';

/* --- shared labels ------------------------------------------------------------ */
tap_t_assert('No show' === TAP_Labels::booking_status('no_show'), 'booking labels are centralized');
tap_t_assert('Pagada' === TAP_Labels::payment_status('paid'), 'payment labels are centralized');
$email_labels = TAP_Emails_Send::status_labels();
tap_t_assert(isset($email_labels['no_show']), 'email labels delegate to the shared map');
$booking_src = (string) file_get_contents($base . 'includes/class-shortcode-booking.php');
tap_t_assert(false === strpos($booking_src, 'ucfirst($booking->status)'), 'voucher no longer renders raw status codes');

/* --- CSV safety --------------------------------------------------------------- */
tap_t_assert("'=cmd" === TAP_Csv::cell('=cmd'), 'CSV neutralizes formulas');
tap_t_assert("' =cmd" === TAP_Csv::cell(' =cmd'), 'CSV neutralizes leading-space formulas');
tap_t_assert('-12.50' === TAP_Csv::cell('-12.50'), 'CSV keeps negative numbers');
foreach (['class-dashboard.php', 'class-front-dash.php', 'class-leads.php'] as $file) {
    $src = (string) file_get_contents($base . 'includes/' . $file);
    tap_t_assert(false !== strpos($src, 'TAP_Csv::cell'), $file . ' uses the shared CSV helper');
}

/* --- service types ------------------------------------------------------------ */
tap_t_assert(in_array('tap_experience', TAP_Service_Types::bookable(), true), 'experiences are bookable');
tap_t_assert(!in_array('tap_equipment', TAP_Service_Types::reviewable(), true), 'equipment is not reviewable');
foreach (['class-booking.php', 'class-itinerary.php', 'class-reviews.php'] as $file) {
    $src = (string) file_get_contents($base . 'includes/' . $file);
    tap_t_assert(false !== strpos($src, 'TAP_Service_Types::'), $file . ' uses the shared service types');
}

/* --- presentability ----------------------------------------------------------- */
foreach (['Cerrar menú', 'Activar modo oscuro', 'Sobre esta experiencia', 'Buscar'] as $msgid) {
    tap_t_assert($msgid === __($msgid, 'travel-agency-platform'), 'Spanish catalog has: ' . $msgid);
}
$css = (string) file_get_contents($base . 'assets/css/public.css');
tap_t_assert(false !== strpos($css, '.tap-testimonial-card'), 'landing testimonial cards are styled');
tap_t_assert(false !== strpos($css, '.tap-gallery-grid'), 'landing gallery is styled');
$dash = (string) file_get_contents($base . 'includes/class-front-dash.php');
tap_t_assert(false !== strpos($dash, 'onboarding_links'), 'onboarding checklist items are links');
$cleanup = (string) file_get_contents($base . 'includes/class-test-data.php');
tap_t_assert(false !== strpos($cleanup, "wp_get_environment_type()"), 'test-data menu is hidden in production');
$account = (string) file_get_contents($base . 'includes/class-shortcode-account.php');
tap_t_assert(false !== strpos($account, 'TAP_KYC_Docs::for_agency'), 'verified badge requires KYC evidence');
$main_js = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/themes/travel-agency-theme/assets/js/main.js');
tap_t_assert(false !== strpos($main_js, 'tapI18n.searchAria'), 'search aria label is localized');

tap_t_finish();
