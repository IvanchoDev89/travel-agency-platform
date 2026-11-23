<?php
/**
 * suite_invoices.php — invoices are self-contained, complete and styled.
 * FUNCTIONAL: real issue -> snapshot -> deleted booking -> HTML + PDF.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_invoices.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }

$sfx = wp_generate_password(6, false);
$agency_id = tap_t_test_agency();

/* --- service + booking with full billing data -------------------------------- */
$service_id = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'publish',
    'post_title' => 'Tour factura E2E ' . $sfx,
]);
$code = 'TAP-FACT-' . strtoupper($sfx);
$bid = tap_t_seed_booking([
    'booking_code' => $code,
    'agency_id'    => $agency_id,
    'service_type' => 'tap_tour',
    'service_id'   => (int) $service_id,
    'guest_name'   => 'Huésped Factura ' . $sfx,
    'guest_email'  => 'factura-' . strtolower($sfx) . '@example.test',
    'total_amount' => 123.45,
    'currency'     => 'EUR',
    'check_in'     => '2026-12-01',
    'check_out'    => '2026-12-05',
    'nights'       => 4,
    'adults'       => 2,
]);

$inv_id = TAP_Invoices::issue($bid);
tap_t_assert(is_int($inv_id) && $inv_id > 0, 'issue() creates an invoice');
$inv = TAP_Invoices::get($inv_id);
tap_t_assert('EUR' === $inv->currency, 'invoice keeps its own currency');

/* --- snapshot is frozen at issue time ---------------------------------------- */
$snap = json_decode((string) $inv->snapshot, true);
tap_t_assert(is_array($snap), 'invoice stores a data snapshot');
tap_t_assert($code === ($snap['booking_code'] ?? ''), 'snapshot has the booking code');
tap_t_assert(false !== strpos((string) ($snap['guest_name'] ?? ''), $sfx), 'snapshot has the guest name');
tap_t_assert(false !== strpos((string) ($snap['service_title'] ?? ''), $sfx), 'snapshot has the service title');
tap_t_assert('EUR' === ($snap['currency'] ?? ''), 'snapshot has the invoice currency');

/* --- simulate the real failure: booking + listing deleted -------------------- */
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
wp_delete_post((int) $service_id, true);

$html = TAP_Invoices::render_html($inv_id);
tap_t_assert(false !== strpos($html, $code), 'deleted booking: HTML still shows the booking code');
tap_t_assert(false !== strpos($html, $sfx), 'deleted booking: HTML still shows guest and service');
tap_t_assert(false !== strpos($html, 'Datos de facturación'), 'HTML has a billing-details section');
tap_t_assert(false !== strpos($html, 'Detalle del servicio'), 'HTML has a service-detail section');
tap_t_assert(false !== strpos($html, 'tap-invoice-brand'), 'HTML renders the styled brand band');
tap_t_assert(false !== strpos($html, 'tap-invoice-total'), 'HTML renders the highlighted total');
tap_t_assert(false === strpos($html, 'Los datos de la reserva original'), 'no missing-data notice when snapshot is complete');
tap_t_assert(false !== strpos($html, '&euro;') || false !== strpos($html, '€'), 'HTML uses the invoice currency, not the site default');

/* --- PDF is styled and self-contained ---------------------------------------- */
$before = (string) $wpdb->get_var($wpdb->prepare("SELECT pdf_url FROM {$wpdb->prefix}tap_invoices WHERE id=%d", $inv_id));
$url = TAP_Invoices::generate_pdf($inv_id);
tap_t_assert('' !== $url, 'generate_pdf() returns a URL');
if ('' !== $url) {
    $path = ABSPATH . ltrim(str_replace(home_url('/'), '', $url), '/');
    $bytes = file_exists($path) ? (string) file_get_contents($path) : '';
    tap_t_assert(false !== strpos($bytes, '/F2'), 'PDF embeds the bold font');
    tap_t_assert(false !== strpos($bytes, 're f'), 'PDF draws filled style bands');
    tap_t_assert(false !== strpos($bytes, 'RG'), 'PDF draws rules');
    tap_t_assert(false !== strpos($bytes, $code), 'PDF contains the booking code');
    tap_t_assert(strlen($bytes) > 1500, 'PDF is richer than the old plain-text one (' . strlen($bytes) . ' bytes)');
    if ($path !== $before && file_exists($path)) { wp_delete_file($path); }
    $wpdb->update($wpdb->prefix . 'tap_invoices', ['pdf_url' => $before], ['id' => $inv_id]);
}

/* --- printable admin view isolates the invoice ------------------------------- */
$css = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/assets/css/admin-invoice.css');
tap_t_assert(false !== strpos($css, '@media print'), 'print stylesheet exists');
tap_t_assert(false !== strpos($css, '#wpadminbar'), 'print stylesheet hides the admin chrome');

/* --- the PDF button always regenerates the current layout -------------------- */
$invoices_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-invoices.php');
tap_t_assert(false !== strpos($invoices_src, "'tap_download_pdf_'"), 'PDF download link is nonce guarded');
tap_t_assert(false !== strpos($invoices_src, "add_action('admin_init', [__CLASS__, 'maybe_download_pdf'])"), 'PDF regeneration handler is hooked');
ob_start();
TAP_Invoices::invoices_page();
$list_html = ob_get_clean();
tap_t_assert(false !== strpos($list_html, 'download_pdf='), 'list PDF button regenerates instead of serving stale bytes');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete($wpdb->prefix . 'tap_invoices', ['id' => $inv_id]);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
