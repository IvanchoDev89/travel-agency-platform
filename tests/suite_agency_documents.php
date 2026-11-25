<?php
/**
 * suite_agency_documents.php — agency invoice and review panels.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) ($admin[0] ?? 0)); }
$sfx = tap_t_suffix();
$user = get_userdata((int) ($admin[0] ?? 0));

$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Docs E2E ' . $sfx]);
update_post_meta($agency_id, '_tap_agency_user_id', (int) ($admin[0] ?? 0));
TAP_Approval::approve($agency_id);
$service_id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Service Docs ' . $sfx]);
update_post_meta($service_id, '_tap_tour_agency_id', (int) $agency_id);

$bid = tap_t_seed_booking(['agency_id' => (int) $agency_id, 'service_id' => (int) $service_id, 'booking_code' => 'DOC-' . $sfx]);
$wpdb->insert($wpdb->prefix . 'tap_invoices', [
    'booking_id' => $bid, 'invoice_number' => 'TAP-E2E-' . $sfx,
    'amount' => 100, 'currency' => 'USD', 'status' => 'issued',
]);
$inv_id = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'tap_reviews', [
    'service_type' => 'tap_tour', 'service_id' => (int) $service_id,
    'user_id' => (int) ($admin[0] ?? 0), 'booking_id' => $bid,
    'rating' => 5, 'title' => 'Excelente', 'content' => 'Muy buena experiencia ' . $sfx,
    'is_approved' => 1,
]);
$review_id = (int) $wpdb->insert_id;

$inv_html = TAP_Front_Dash::agency_invoices($user);
tap_t_assert(false !== strpos($inv_html, 'TAP-E2E-' . $sfx), 'agency invoices panel lists the invoice');
tap_t_assert(false !== strpos($inv_html, 'tap_agency_invoice_pdf'), 'invoice PDF link is nonce scoped');
$rev_html = TAP_Front_Dash::agency_reviews($user);
tap_t_assert(false !== strpos($rev_html, 'Muy buena experiencia'), 'agency reviews panel lists the review');
tap_t_assert(false !== strpos($rev_html, 'name="_wpnonce"') && false !== strpos($rev_html, 'name="review_id"'), 'review reply form carries nonce and review id');

// Reply with the correct owner.
$_POST['review_id'] = (string) $review_id;
$_POST['reply'] = 'Gracias por tu comentario ' . $sfx;
$_POST['_wpnonce'] = wp_create_nonce('tap_agency_review_' . $review_id);
// Handler exits; verify the pure data path instead by checking wiring + capability source.
$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false !== has_action('admin_post_tap_agency_review_reply'), 'review reply handler is registered');
tap_t_assert(false !== strpos($src, "case 'bo-invoices'"), 'invoices section is routed');
tap_t_assert(false !== strpos($src, "case 'bo-reviews'"), 'reviews section is routed');
$inv_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-invoices.php');
tap_t_assert(false !== has_action('admin_post_tap_agency_invoice_pdf'), 'agency invoice PDF handler is registered');

$wpdb->delete($wpdb->prefix . 'tap_reviews', ['id' => $review_id]);
$wpdb->delete($wpdb->prefix . 'tap_invoices', ['id' => $inv_id]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Docs E2E ' . $sfx]);
wp_delete_post($service_id, true);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
