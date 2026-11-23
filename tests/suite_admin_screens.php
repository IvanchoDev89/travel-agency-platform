<?php
/**
 * suite_admin_screens.php — admin screen regressions from the deep audit.
 * FUNCTIONAL: real page renders with the real administrator.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_admin_screens.php
 */
require_once __DIR__ . '/bootstrap.php';

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$orig_uid = get_current_user_id();
if (!$admin) {
    tap_t_assert(false, 'administrator fixture exists');
    tap_t_finish();
}
wp_set_current_user((int) $admin[0]);
global $submenu;
do_action('admin_menu');

/* --- 1) Launch Check is actually in the menu -------------------------------- */
$slugs = array_column((array) ($submenu['travel-platform'] ?? []), 2);
tap_t_assert(in_array('tap-launch-check', $slugs, true), 'Launch Check is registered under travel-platform');

/* --- 2) dashboard renders without the undefined-key warning ------------------ */
$warnings = [];
set_error_handler(static function ($errno, $errstr) use (&$warnings) {
    if (false !== strpos($errstr, 'Undefined')
        || false !== strpos($errstr, ' tentar acessar')) { $warnings[] = $errstr; }
    return false;
});
ob_start();
TAP_Dashboard_Bookings::dashboard_page();
$dashboard_html = ob_get_clean();
restore_error_handler();
tap_t_assert([] === $warnings, 'dashboard renders without undefined-key warnings (' . implode(' | ', $warnings) . ')');
tap_t_assert(false !== strpos($dashboard_html, 'Agencias'), 'dashboard still shows the Agencias KPI');

/* --- 3) invoices pagination --------------------------------------------------- */
ob_start();
TAP_Invoices::invoices_page();
$invoices_html = ob_get_clean();
tap_t_assert(false !== strpos($invoices_html, 'tablenav-pages'), 'invoices list is paginated');
$invoice_rows = substr_count($invoices_html, 'page=tap-invoices&#038;view=');
tap_t_assert($invoice_rows > 0 && $invoice_rows <= 50, 'invoices page shows a bounded, non-empty page (' . $invoice_rows . ' rows)');
$_GET['paged'] = 10;
ob_start();
TAP_Invoices::invoices_page();
$older_invoices_html = ob_get_clean();
unset($_GET['paged']);
tap_t_assert(false !== strpos($older_invoices_html, 'Generar PDF'), 'PDF-less invoices offer a regenerate action');

/* --- 4) print button survives KSES in the detail view ------------------------ */
global $wpdb;
$inv = $wpdb->get_row("SELECT id, invoice_number FROM {$wpdb->prefix}tap_invoices ORDER BY id DESC LIMIT 1");
if ($inv) {
    $_GET['view'] = (int) $inv->id;
    ob_start();
    TAP_Invoices::invoices_page();
    $detail = ob_get_clean();
    unset($_GET['view']);
    tap_t_assert(false !== strpos($detail, 'tap-invoice-print'), 'detail view renders the print button');
    tap_t_assert(false !== strpos($detail, 'window.print()'), 'print button has working JS');
    tap_t_assert(false !== strpos($detail, $inv->invoice_number), 'detail view shows the invoice number');
}

/* --- 5) invoice numbering helper --------------------------------------------- */
$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-invoices.php');
tap_t_assert(false !== strpos($src, 'ORDER BY invoice_number DESC'), 'next_number orders by number, not insertion id');
tap_t_assert(false !== strpos($src, "'tap_gen_pdf_'"), 'PDF regeneration is nonce guarded');

/* --- 6) admin locale, hook order and export routing -------------------------- */
tap_t_assert('es_ES' === apply_filters('locale', 'en_US'), 'admin locale resolves to Spanish');
tap_t_assert(31 === has_action('admin_menu', ['TAP_Launch_Check', 'add_page']), 'Launch Check registers after its parent menu');
tap_t_assert(false !== has_action('admin_init', ['TAP_Dashboard_Insights', 'maybe_export_analytics']), 'analytics CSV export runs on admin_init');
$content_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-content.php');
tap_t_assert(false !== strpos($content_src, 'LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID'), 'reviews from deleted users are kept with a LEFT JOIN');
$money_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-money.php');
tap_t_assert(false !== strpos($money_src, 'data-settle='), 'per-booking settlement modal has a real trigger');

/* --- 7) commissions page renders the settle trigger -------------------------- */
ob_start();
TAP_Dashboard_Money::commissions_page();
$commissions_html = ob_get_clean();
tap_t_assert(false !== strpos($commissions_html, 'data-settle='), 'commissions list renders the settle button');

wp_set_current_user($orig_uid);
tap_t_finish();
