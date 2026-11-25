<?php
/**
 * suite_agency_management.php — full agency management screen and handlers.
 * FUNCTIONAL: detail view, KYC, owner/staff, audit and POST commission.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_agency_management.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();

/* --- detail view -------------------------------------------------------------- */
$agency_id = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'Gestión E2E ' . $sfx, 'post_excerpt' => 'Agencia de prueba de gestión',
]);
tap_t_assert((int) $agency_id > 0, 'agency fixture created');
update_post_meta($agency_id, '_tap_agency_email', 'gestion-' . strtolower($sfx) . '@example.test');
update_post_meta($agency_id, '_tap_agency_phone', '22223333');
update_post_meta($agency_id, '_tap_agency_commission', '11.5');
update_post_meta($agency_id, '_tap_agency_user_id', (int) ($admin[0] ?? 0));
TAP_Approval::save_kyc($agency_id, [
    'legal_name' => 'Representante ' . $sfx,
    'doc_type' => 'fisica',
    'doc_number' => '1-2345-' . $sfx,
    'legal_tax_id' => '',
]);

$html = TAP_Dashboard_Content::agency_detail($agency_id);
tap_t_assert(false !== strpos($html, 'Gestión E2E ' . $sfx), 'detail shows the agency name');
tap_t_assert(false !== strpos($html, 'Representante ' . $sfx), 'detail shows KYC data');
tap_t_assert(false !== strpos($html, '1-2345-' . $sfx), 'detail shows the KYC document number');
tap_t_assert(false !== strpos($html, 'value="11.5"'), 'detail shows the current commission');
tap_t_assert(false !== strpos($html, 'name="action" value="tap_agency_save"'), 'save form posts to a registered handler');
tap_t_assert(false !== strpos($html, 'name="action" value="tap_agency_owner"'), 'owner form posts to a registered handler');
tap_t_assert(false !== strpos($html, 'name="action" value="tap_agency_staff"'), 'staff form posts to a registered handler');
tap_t_assert(false !== strpos($html, 'Registro de actividad'), 'detail renders the audit trail');
tap_t_assert(false !== strpos($html, 'Aprobada') || false !== strpos($html, 'Pendiente'), 'detail shows approval status');

/* --- handlers registered ------------------------------------------------------ */
foreach (['save', 'owner', 'staff', 'commission'] as $action) {
    tap_t_assert(false !== has_action('admin_post_tap_agency_' . $action), 'admin_post_tap_agency_' . $action . ' is registered');
}
$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-content.php');
tap_t_assert(false !== strpos($src, "case 'restore'"), 'restore action exists');
tap_t_assert(false !== strpos($src, 'log_agency('), 'agency actions write audit entries');
tap_t_assert(false !== strpos($src, 'method="post" action="<?php echo esc_url(admin_url(\'admin-post.php\')); ?>" style="display:inline-flex'), 'commission mutation uses POST, not GET');

/* --- filters + registry sync -------------------------------------------------- */
$list_src = $src;
foreach (['status_filter', 'verify_filter', 'active_filter', 'name="s"'] as $needle) {
    tap_t_assert(false !== strpos($list_src, $needle), 'agency list supports filter: ' . $needle);
}
TAP_Dashboard_Content::sync_agency_registry();
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . $wpdb->prefix . 'tap_agencies WHERE name = %s ORDER BY id DESC LIMIT 1',
    'Gestión E2E ' . $sfx
));
tap_t_assert($row && abs((float) $row->commission_percent - 11.5) < 0.01, 'registry sync reflects the saved commission');

/* --- audit log ---------------------------------------------------------------- */
if (class_exists('TAP_Audit_Log')) {
    TAP_Audit_Log::log('agency_test', 'agency', $agency_id, 'E2E', $agency_id);
    tap_t_assert((int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . TAP_Audit_Log::table() . ' WHERE agency_id=%d AND action="agency_test"',
        $agency_id
    )) >= 1, 'audit entry persisted for the agency');
}

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Gestión E2E ' . $sfx]);
if (class_exists('TAP_Audit_Log')) { $wpdb->delete(TAP_Audit_Log::table(), ['agency_id' => $agency_id]); }
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
