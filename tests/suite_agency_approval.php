<?php
/**
 * suite_agency_approval.php — Fase 2: agency approval workflow + KYC.
 * Verifies the TAP_Approval state machine, KYC round-trip, public gating
 * (search shortcode/AJAX/REST, featured, agencies list/detail, single-service
 * visibility), booking rejection for pending agencies, the legacy backfill and
 * the approve/reject events. Creates and removes its own fixtures.
 */
require_once __DIR__ . '/bootstrap.php';

$created_posts = [];
$booking_code  = '';

tap_t_assert(class_exists('TAP_Approval'), 'TAP_Approval class exists');
tap_t_assert(TAP_Approval::PENDING === 'pending', 'constant PENDING = pending');
tap_t_assert(TAP_Approval::APPROVED === 'approved', 'constant APPROVED = approved');
tap_t_assert(TAP_Approval::REJECTED === 'rejected', 'constant REJECTED = rejected');

$mk_agency = function ($title) use (&$created_posts) {
    $id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => $title]);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        update_post_meta($id, '_tap_agency_user_id', 1);
        update_post_meta($id, '_tap_agency_is_active', '1');
    }
    return $id;
};
$mk_service = function ($title, $agency_id) use (&$created_posts) {
    $id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => $title]);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        update_post_meta($id, '_tap_tour_is_active', '1');
        update_post_meta($id, '_tap_tour_agency_id', $agency_id);
    }
    return $id;
};

/* --- Legacy backfill: agencies without status meta are approved (one-time). --- */
$legacy = $mk_agency('Legacy E2E ' . wp_generate_password(6, false));
tap_t_assert($legacy && !is_wp_error($legacy), 'created legacy agency fixture (no status meta)');
tap_t_assert(TAP_Approval::status($legacy) === TAP_Approval::APPROVED, 'legacy status = approved without meta');
delete_option(TAP_Approval::MIGRATED_OPTION);
TAP_Approval::maybe_migrate_legacy();
tap_t_assert(TAP_Approval::status($legacy) === TAP_Approval::APPROVED, 'migration backfills legacy agency to approved');
tap_t_assert('1' === get_option(TAP_Approval::MIGRATED_OPTION), 'migration ran once (option set)');
tap_t_assert(false === TAP_Approval::set_status($legacy, 'nope'), 'set_status rejects invalid statuses');

/* --- Pending agency fixture. --- */
$title_pending = 'Pending E2E ' . wp_generate_password(6, false);
$pending = $mk_agency($title_pending);
update_post_meta($pending, '_tap_agency_verified', '1');
update_post_meta($pending, '_tap_agency_status', TAP_Approval::PENDING);
update_post_meta($pending, '_tap_agency_is_active', '0');
tap_t_assert(TAP_Approval::is_pending($pending), 'pending agency is pending');
tap_t_assert(!TAP_Approval::is_approved($pending), 'pending agency is NOT approved');
tap_t_assert(TAP_Approval::APPROVED === TAP_Approval::APPROVED && TAP_Approval::status($pending) === TAP_Approval::PENDING, 'status() = pending');

/* --- KYC round-trip. --- */
$kyc = ['legal_name' => 'TAP Legal E2E', 'doc_type' => 'juridica', 'doc_number' => '3-101-555555', 'legal_tax_id' => '3-101-555555'];
TAP_Approval::save_kyc($pending, $kyc);
$stored = TAP_Approval::kyc($pending);
tap_t_assert('TAP Legal E2E' === $stored['legal_name'], 'KYC legal_name saved');
tap_t_assert('juridica' === $stored['doc_type'], 'KYC doc_type saved');
tap_t_assert('3-101-555555' === $stored['doc_number'], 'KYC doc_number saved');
tap_t_assert('3-101-555555' === $stored['legal_tax_id'], 'KYC legal_tax_id saved');
$fields = TAP_Approval::kyc_fields();
tap_t_assert(!empty($fields['legal_name']) && !empty($fields['doc_type']), 'kyc_fields() exposes legal fields');

/* --- Services under a pending agency are hidden. --- */
$tour_title = 'Tour Pending E2E ' . wp_generate_password(6, false);
$tour = $mk_service($tour_title, $pending);
update_post_meta($tour, '_tap_tour_is_featured', '1');
tap_t_assert((int) TAP_Approval::listing_agency_id($tour, 'tap_tour') === (int) $pending, 'listing_agency_id resolves owner from meta');
tap_t_assert(false === TAP_Approval::is_service_visible($tour, 'tap_tour'), 'is_service_visible false for pending-agency service');
tap_t_assert(in_array((int) $tour, array_map('intval', TAP_Approval::excluded_listing_ids()), true), 'excluded_listing_ids contains pending service');

$gated = TAP_Approval::exclude_from_query(['post_type' => 'tap_tour']);
tap_t_assert(in_array((int) $tour, array_map('intval', $gated['post__not_in']), true), 'exclude_from_query sets post__not_in');

/* --- REST excludes pending-agency listings. --- */
$resp = tap_t_rest('GET', '/tap/v1/search', ['keyword' => $tour_title]);
$data = tap_t_rest_data($resp);
$ids = array_column((array) ($data['results'] ?? []), 'id');
tap_t_assert(!in_array((int) $tour, array_map('intval', $ids), true), 'REST /search excludes pending-agency service');

$resp2 = tap_t_rest('GET', '/tap/v1/services/tap_tour');
$ids2 = array_column((array) ($data = tap_t_rest_data($resp2))['services'] ?? [], 'id');
tap_t_assert(!in_array((int) $tour, array_map('intval', $ids2), true), 'REST services-by-type excludes pending service');

/* --- Agency directory & detail gating. --- */
$title_ok = 'Approved E2E ' . wp_generate_password(6, false);
$ready = $mk_agency($title_ok);
update_post_meta($ready, '_tap_agency_verified', '1');
update_post_meta($ready, '_tap_agency_status', TAP_Approval::APPROVED);
update_post_meta($ready, '_tap_agency_is_active', '1');

$list_html = TAP_Shortcodes::agencies_list(['limit' => 50]);
tap_t_assert(strpos($list_html, esc_html($title_ok)) !== false, 'agencies_list includes approved+verified agency');
tap_t_assert(strpos($list_html, esc_html($title_pending)) === false, 'agencies_list hides pending agency');

$detail_pending = TAP_Shortcodes::agency_detail(['id' => $pending]);
tap_t_assert(false !== strpos($detail_pending, 'en revisión'), 'agency_detail shows pending message publicly');
$detail_ready = TAP_Shortcodes::agency_detail(['id' => $ready]);
tap_t_assert(false !== strpos($detail_ready, esc_html($title_ok)), 'agency_detail renders approved agency');
tap_t_assert('' === TAP_Shortcodes::agency_services(['agency' => $pending]), 'agency_services hidden for pending agency');

/* --- Featured listing excludes pending agency service. --- */
$featured_html = TAP_Shortcodes::featured_services(['type' => 'tap_tour', 'limit' => 50]);
tap_t_assert(strpos($featured_html, esc_html($tour_title)) === false, 'featured_services excludes pending-agency listing');

/* --- Booking is rejected for a pending agency. --- */
$b = TAP_Booking::create([
    'service_type' => 'tap_tour',
    'service_id'   => $tour,
    'check_in'     => gmdate('Y-m-d', strtotime('+40 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+41 days')),
    'adults'       => 1,
    'children'     => 0,
    'client_id'    => 0,
    'guest_email'  => 'approval-e2e@example.test',
    'guest_name'   => 'Approval Tester',
]);
tap_t_assert(is_wp_error($b), 'booking create for pending agency returns WP_Error');
tap_t_assert($b->get_error_code() === 'agency_pending', 'booking error code = agency_pending');

/* --- Approve: event + visibility flips. --- */
$fired = [];
add_action('tap_agency_approved', function ($id) use (&$fired) { $fired['approved'] = $id; });
tap_t_assert(TAP_Approval::approve($pending), 'approve() returns true');
tap_t_assert(TAP_Approval::is_approved($pending), 'agency is approved after approve()');
tap_t_assert('1' === get_post_meta($pending, TAP_Approval::ACTIVE_META, true), 'approve() sets is_active=1');
tap_t_assert(isset($fired['approved']) && (int) $fired['approved'] === (int) $pending, 'tap_agency_approved action fired with agency id');
tap_t_assert(true === TAP_Approval::is_service_visible($tour, 'tap_tour'), 'is_service_visible true after approval');
tap_t_assert(!in_array((int) $tour, array_map('intval', TAP_Approval::excluded_listing_ids()), true), 'excluded_listing_ids cleared after approval');

$resp3 = tap_t_rest('GET', '/tap/v1/search', ['keyword' => $tour_title]);
$data3 = tap_t_rest_data($resp3);
$ids3 = array_column((array) ($data3['results'] ?? []), 'id');
tap_t_assert(in_array((int) $tour, array_map('intval', $ids3), true), 'REST /search includes service after approval');
tap_t_assert(strpos(TAP_Shortcodes::featured_services(['type' => 'tap_tour', 'limit' => 50]), esc_html($tour_title)) !== false, 'featured_services includes service after approval');

/* --- Bookings succeed once approved. --- */
$b2 = TAP_Booking::create([
    'service_type' => 'tap_tour',
    'service_id'   => $tour,
    'check_in'     => gmdate('Y-m-d', strtotime('+45 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+46 days')),
    'adults'       => 1,
    'children'     => 0,
    'client_id'    => 0,
    'guest_email'  => 'approval-e2e@example.test',
    'guest_name'   => 'Approval Tester',
]);
tap_t_assert(!is_wp_error($b2) && !empty($b2['booking_id']), 'booking create succeeds after approval');
if (!is_wp_error($b2) && !empty($b2['booking_id'])) {
    $booking_code = (string) ($b2['booking_code'] ?? '');
}

/* --- Reject: excluded again + event fired. --- */
$title_rej = 'Rejected E2E ' . wp_generate_password(6, false);
$rejected = $mk_agency($title_rej);
update_post_meta($rejected, '_tap_agency_verified', '1');
update_post_meta($rejected, '_tap_agency_status', TAP_Approval::APPROVED);
update_post_meta($rejected, '_tap_agency_is_active', '1');
$tour2 = $mk_service('Tour Rejected E2E ' . wp_generate_password(6, false), $rejected);
tap_t_assert(in_array((int) $tour2, array_map('intval', TAP_Approval::excluded_listing_ids()), true) === false, 'approved agency (rejected fixture) initially visible');

$fired2 = [];
add_action('tap_agency_rejected', function ($id) use (&$fired2) { $fired2['rejected'] = $id; });
tap_t_assert(TAP_Approval::reject($rejected), 'reject() returns true');
tap_t_assert(TAP_Approval::status($rejected) === TAP_Approval::REJECTED, 'status = rejected after reject()');
tap_t_assert(!TAP_Approval::is_approved($rejected), 'rejected agency is NOT approved');
tap_t_assert('0' === get_post_meta($rejected, TAP_Approval::ACTIVE_META, true), 'reject() sets is_active=0');
tap_t_assert(isset($fired2['rejected']) && (int) $fired2['rejected'] === (int) $rejected, 'tap_agency_rejected action fired');
tap_t_assert(!TAP_Approval::is_service_visible($tour2, 'tap_tour'), 'rejected-agency service hidden again');
tap_t_assert(in_array((int) $tour2, array_map('intval', TAP_Approval::excluded_listing_ids()), true), 'rejected-agency service excluded');

/* --- Cleanup. --- */
global $wpdb;
if ($booking_code) {
    $bk_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s", $booking_code));
    if ($bk_id) {
        $wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => (int) $bk_id]);
        $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) $bk_id]);
    }
}
foreach ($created_posts as $pid) {
    delete_post_meta($pid, TAP_Approval::STATUS_META);
    delete_post_meta($pid, TAP_Approval::ACTIVE_META);
    foreach (array_keys(TAP_Approval::kyc_fields()) as $k) {
        delete_post_meta($pid, TAP_Approval::KYC_PREFIX . $k);
    }
    delete_post_meta($pid, TAP_Approval::KYC_PREFIX . 'submitted_at');
    wp_delete_post($pid, true);
}
tap_t_finish();