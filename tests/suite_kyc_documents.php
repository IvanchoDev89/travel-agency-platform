<?php
/**
 * suite_kyc_documents.php — KYC document lifecycle and resubmission.
 * FUNCTIONAL: real agency, real document rows, review and approval gates.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();

/* --- table and catalogue ------------------------------------------------------ */
tap_t_assert((bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', TAP_KYC_Docs::table())), 'KYC documents table exists');
tap_t_assert(count(TAP_KYC_Docs::types()) >= 3, 'verification document types are defined');

$agency_id = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'KYC E2E ' . $sfx,
]);
update_post_meta($agency_id, '_tap_agency_user_id', (int) ($admin[0] ?? 0));
TAP_Approval::approve($agency_id);

/* --- validation without a real HTTP upload ------------------------------------ */
$bad = TAP_KYC_Docs::store($agency_id, null, 'id_front');
tap_t_assert(is_wp_error($bad) && 'no_file' === $bad->get_error_code(), 'missing file is rejected');
$bad_type = TAP_KYC_Docs::store($agency_id, null, 'bogus');
tap_t_assert(is_wp_error($bad_type) && 'invalid_type' === $bad_type->get_error_code(), 'unknown document type is rejected');

/* --- real document rows ------------------------------------------------------- */
$doc_ids = [];
foreach (['id_front', 'tax_id', 'address'] as $type) {
    $wpdb->insert(TAP_KYC_Docs::table(), [
        'agency_id' => $agency_id, 'doc_type' => $type,
        'file_path' => '/tmp/opencode/kyc-' . $type . '-' . $sfx . '.pdf',
        'file_name' => $type . '.pdf', 'mime' => 'application/pdf', 'file_size' => 1024,
    ]);
    $doc_ids[] = (int) $wpdb->insert_id;
}
tap_t_assert(3 === count(TAP_KYC_Docs::for_agency($agency_id)), 'documents are listed for the agency');
tap_t_assert(3 === count(TAP_KYC_Docs::latest_by_type($agency_id)), 'latest document per type is indexed');

/* --- review transitions ------------------------------------------------------- */
$reason = 'Cédula ilegible ' . $sfx;
$review = TAP_KYC_Docs::review($doc_ids[0], 'rejected', $reason, (int) ($admin[0] ?? 0));
tap_t_assert(true === $review, 'document can be rejected with a reason');
$doc = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TAP_KYC_Docs::table() . ' WHERE id=%d', $doc_ids[0]));
tap_t_assert('rejected' === $doc->status && $reason === $doc->reviewer_note, 'rejection reason is persisted');
$bad_review = TAP_KYC_Docs::review($doc_ids[1], 'rejected', '', (int) ($admin[0] ?? 0));
tap_t_assert(is_wp_error($bad_review), 'rejection without a reason is rejected');

// Approval must be blocked while a document is rejected.
tap_t_assert(false === TAP_Approval::approve($agency_id), 'agency cannot be approved with a rejected document');

/* --- resubmission ------------------------------------------------------------- */
update_post_meta($agency_id, 'tap_kyc_rejection_reason', $reason);
TAP_KYC_Docs::mark_resubmitted($agency_id);
tap_t_assert(TAP_Approval::is_pending($agency_id), 'resubmission returns the agency to pending');
tap_t_assert('' === (string) get_post_meta($agency_id, 'tap_kyc_rejection_reason', true), 'resubmission clears the reason');
tap_t_assert('' !== (string) get_post_meta($agency_id, 'tap_kyc_resubmitted_at', true), 'resubmission timestamp is stored');
$pending_docs = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TAP_KYC_Docs::table() . ' WHERE agency_id=%d AND status="pending"', $agency_id));
tap_t_assert(3 === (int) $pending_docs, 'resubmission resets every document to pending');
TAP_Approval::approve($agency_id);
tap_t_assert(TAP_Approval::is_approved($agency_id), 'agency can be approved after resubmission');

/* --- UI + wiring -------------------------------------------------------------- */
$form = TAP_KYC_Docs::render_upload_form($agency_id, TAP_Approval::status($agency_id));
tap_t_assert(false !== strpos($form, 'tap_kyc_nonce'), 'upload form is nonce protected');
tap_t_assert(false !== strpos($form, 'enctype="multipart/form-data"'), 'upload form accepts files');
tap_t_assert(false !== strpos($form, 'accept=".jpg,.jpeg,.png,.pdf"'), 'upload form restricts file types');
foreach (['doc_status', 'upload', 'serve'] as $action) {
    tap_t_assert(false !== has_action('admin_post_tap_kyc_' . $action), 'admin_post_tap_kyc_' . $action . ' is registered');
}
$mail_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-emails.php');
tap_t_assert(false !== strpos($mail_src, 'tap_kyc_rejection_reason'), 'rejection email includes the real reason');
$dash_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-content.php');
tap_t_assert(false !== strpos($dash_src, 'tap_agency_reject'), 'admin rejection with reason is wired');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete(TAP_KYC_Docs::table(), ['agency_id' => $agency_id]);
delete_post_meta($agency_id, 'tap_kyc_rejection_reason');
delete_post_meta($agency_id, 'tap_kyc_resubmitted_at');
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'KYC E2E ' . $sfx]);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
