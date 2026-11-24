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
tap_t_assert(false !== strpos($src, 'CAST(SUBSTRING_INDEX(invoice_number'), 'next_number orders by numeric suffix, not insertion id');
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

/* --- 8) invoice search/filter ------------------------------------------------- */
$sample = $wpdb->get_row("SELECT invoice_number FROM {$wpdb->prefix}tap_invoices WHERE invoice_number <> '' ORDER BY id DESC LIMIT 1");
if ($sample) {
    $_GET['s'] = $sample->invoice_number;
    ob_start();
    TAP_Invoices::invoices_page();
    $found_html = ob_get_clean();
    unset($_GET['s']);
    tap_t_assert(false !== strpos($found_html, $sample->invoice_number), 'invoice search finds a known invoice');
    tap_t_assert(1 === substr_count($found_html, 'page=tap-invoices&#038;view='), 'invoice search narrows the list to one row');
    $_GET['s'] = 'NOEXISTE-' . wp_generate_password(6, false);
    ob_start();
    TAP_Invoices::invoices_page();
    $none_html = ob_get_clean();
    unset($_GET['s']);
    tap_t_assert(false !== strpos($none_html, 'Sin facturas'), 'invoice search shows an empty state for no matches');
}

/* --- 9) paginator supports independent query args ----------------------------- */
$custom_pager = TAP_Dashboard::paginate(500, 50, 1, 'payout_paged');
tap_t_assert(false !== strpos($custom_pager, 'payout_paged'), 'paginator accepts a custom query arg');
tap_t_assert('' === TAP_Dashboard::paginate(10, 50, 1), 'paginator hides itself for a single page');

/* --- 10) no hidden hard caps in admin tables ---------------------------------- */
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/';
foreach (['class-dashboard-money.php', 'class-dashboard-settings.php', 'class-dashboard-market.php', 'class-transport-admin.php'] as $file) {
    $code = (string) file_get_contents($base . $file);
    tap_t_assert(1 !== preg_match('/ORDER BY[^\n]*LIMIT (50|100|200)/i', $code), $file . ' has no hard row cap');
}
$transport_src = (string) file_get_contents($base . 'class-transport-admin.php');
tap_t_assert(false === strpos($transport_src, 'for_agency(0)'), 'transport admin does not hide rows behind for_agency(0)');

/* --- 11) campaign schedule handler is reachable -------------------------------- */
$campaign_src = (string) file_get_contents($base . 'class-email-campaigns.php');
tap_t_assert(false !== strpos($campaign_src, 'tap_email_campaign_schedule&id='), 'campaign list links the schedule handler');
tap_t_assert(false === strpos((string) file_get_contents($base . 'class-landing-builder.php'), 'function register_admin_menu'), 'duplicate landing menu method removed');

/* --- 12) admin headings fully Spanish ------------------------------------------ */
foreach ([
    'Listado de destacados' => 'Listado Promotions (Destacados)',
    'Constructor de página de inicio' => 'Landing Page Builder',
    'Verificación de lanzamiento' => 'Launch Check',
    'Comisiones — Liquidación' => 'Commissions — Liquidación',
] as $spanish => $msgid) {
    tap_t_assert($spanish === __($msgid, 'travel-agency-platform'), 'Spanish admin label: ' . $msgid);
}

/* --- 13) no PHP warnings/deprecations while rendering admin pages ------------- */
$page_warnings = [];
set_error_handler(static function ($no, $str, $file) use (&$page_warnings) {
    if (false !== strpos($file, 'travel-agency-platform')) {
        $page_warnings[] = basename($file) . ': ' . $str;
    }
    return true;
});
ob_start();
TAP_Coupons::render_page();
TAP_Dashboard_Money::disputes_page();
ob_end_clean();
restore_error_handler();
tap_t_assert([] === $page_warnings, 'coupons and disputes render without plugin warnings (' . implode(' | ', $page_warnings) . ')');

wp_set_current_user($orig_uid);
tap_t_finish();
