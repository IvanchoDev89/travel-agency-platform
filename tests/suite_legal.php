<?php
/**
 * suite_legal.php — digital contracts: versioned documents + acceptances.
 * FUNCTIONAL: real seeded documents, real acceptance rows, real gates.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_legal.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();

/* --- 1) seed + immutability --------------------------------------------------- */
TAP_Legal::seed_defaults();
$docs = TAP_Legal::for_context(TAP_Legal::CONTEXT_TRAVELER);
tap_t_assert(count($docs) >= 2, 'traveler context has current documents');
foreach ($docs as $doc) {
    tap_t_assert(hash('sha256', $doc->body) === $doc->sha256, 'document hash matches its body: ' . $doc->doc_key);
}
$agency_docs = TAP_Legal::for_context(TAP_Legal::CONTEXT_AGENCY);
tap_t_assert(count($agency_docs) >= 1, 'agency context has a current agreement');

// A new version must not overwrite the previous one.
$base = TAP_Legal::current('traveler_terms', 'es');
tap_t_assert($base && (int) $base->version >= 1, 'seeded version exists');
$new_body = $base->body . '<p>Cláusula de prueba ' . esc_html($sfx) . '</p>';
$wpdb->insert(TAP_Legal::table_docs(), [
    'doc_key' => $base->doc_key, 'context' => $base->context, 'lang' => $base->lang,
    'version' => (int) $base->version + 1, 'title' => $base->title, 'body' => $new_body,
    'sha256' => hash('sha256', $new_body), 'published' => 1,
]);
$current = TAP_Legal::current('traveler_terms', 'es');
tap_t_assert((int) $base->version + 1 === (int) $current->version, 'publishing a change selects the new version');
tap_t_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . TAP_Legal::table_docs() . ' WHERE doc_key=%s AND lang=%s AND version=%d',
    $base->doc_key, $base->lang, (int) $base->version
)) >= 1, 'the old version remains immutable');
$wpdb->delete(TAP_Legal::table_docs(), ['doc_key' => $base->doc_key, 'lang' => $base->lang, 'version' => (int) $base->version + 1]);

// Canonical HTML files are the single source of truth and are seeded verbatim.
$legal_dir = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/legal/';
foreach (['traveler_terms', 'agency_terms', 'cancellation_policy', 'privacy_notice'] as $key) {
    $file = $legal_dir . $key . '.es.html';
    tap_t_assert(is_readable($file), 'canonical legal file exists: ' . $key);
    $live = TAP_Legal::current($key, 'es');
    $expected = hash('sha256', (string) file_get_contents($file));
    tap_t_assert($live && $live->sha256 === $expected, 'seeded version matches the canonical file: ' . $key);
    tap_t_assert($live && strlen($live->body) > 2000, 'canonical text is complete, not the fallback: ' . $key);
}

/* --- 2) acceptance evidence --------------------------------------------------- */
$booking_id = tap_t_seed_booking([
    'booking_code' => 'LEGAL-' . $sfx,
    'client_id'    => 0,
    'guest_email'  => 'legal-' . strtolower($sfx) . '@example.test',
    'guest_name'   => 'Firmante Legal',
]);
$booking = TAP_Booking::get_booking($booking_id);
tap_t_assert(false === TAP_Legal::booking_current($booking_id), 'new booking has no accepted terms');

$docs = TAP_Legal::for_context(TAP_Legal::CONTEXT_TRAVELER);
$accept_ids = [];
foreach ($docs as $doc) {
    $wpdb->insert(TAP_Legal::table_accept(), [
        'document_id' => (int) $doc->id, 'doc_key' => $doc->doc_key,
        'version' => (int) $doc->version, 'document_hash' => $doc->sha256,
        'booking_id' => (int) $booking_id, 'user_id' => 0,
        'guest_email' => strtolower((string) $booking->guest_email),
        'context' => TAP_Legal::CONTEXT_TRAVELER, 'signer_name' => 'Firmante Legal',
        'ip_hash' => hash('sha256', '203.0.113.5' . wp_salt('auth')),
        'ua_hash' => hash('sha256', 'TapLegal/1.0' . wp_salt('auth')),
        'accepted_at' => current_time('mysql'),
    ]);
    $accept_ids[] = (int) $wpdb->insert_id;
}
tap_t_assert(true === TAP_Legal::booking_current($booking_id), 'booking is current after accepting every document');
$first = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TAP_Legal::table_accept() . ' WHERE id=%d', $accept_ids[0]));
tap_t_assert(strlen((string) $first->document_hash) === 64, 'acceptance stores the full document hash');
tap_t_assert(strlen((string) $first->ip_hash) === 64 && strlen((string) $first->ua_hash) === 64, 'client fingerprint is hashed, not stored raw');
tap_t_assert('Firmante Legal' === $first->signer_name, 'signer name is recorded');

// Changing the document version invalidates the old acceptance.
$changed_body = $docs[0]->body . '<p>cambio</p>';
$wpdb->insert(TAP_Legal::table_docs(), [
    'doc_key' => $docs[0]->doc_key, 'context' => $docs[0]->context, 'lang' => $docs[0]->lang,
    'version' => (int) $docs[0]->version + 1, 'title' => $docs[0]->title, 'body' => $changed_body,
    'sha256' => hash('sha256', $changed_body), 'published' => 1,
]);
tap_t_assert(false === TAP_Legal::booking_current($booking_id), 'a new document version requires re-acceptance');
$wpdb->delete(TAP_Legal::table_docs(), ['doc_key' => $docs[0]->doc_key, 'lang' => $docs[0]->lang, 'version' => (int) $docs[0]->version + 1]);

/* --- 3) gates wired ----------------------------------------------------------- */
$commerce = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-commerce.php');
tap_t_assert(2 === substr_count($commerce, 'TAP_Legal::booking_current'), 'both payment handlers gate on accepted terms');
tap_t_assert(false !== strpos($commerce, 'TAP_Legal::agency_current'), 'plan subscription gates on the agency agreement');
$checkout = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-shortcode-booking.php');
tap_t_assert(false !== strpos($checkout, 'TAP_Legal::render_acceptance'), 'checkout renders the acceptance form');

/* --- 4) receipt + snapshot ---------------------------------------------------- */
tap_t_assert(method_exists('TAP_Legal', 'send_receipt'), 'acceptance receipt can be emailed');
$html = TAP_Legal::render_acceptance(TAP_Legal::CONTEXT_TRAVELER, (int) $booking_id);
tap_t_assert(false !== strpos($html, 'tap_legal_consent'), 'acceptance form has the consent checkbox');
tap_t_assert(false !== strpos($html, 'signer_name'), 'acceptance form asks for the signer name');
tap_t_assert(false !== strpos($html, 'tap_legal_nonce'), 'acceptance form is nonce protected');

/* --- cleanup ----------------------------------------------------------------- */
foreach ($accept_ids as $aid) { $wpdb->delete(TAP_Legal::table_accept(), ['id' => $aid]); }
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $booking_id]);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
