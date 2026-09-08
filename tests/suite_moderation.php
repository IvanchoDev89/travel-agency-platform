<?php
/**
 * E2E suite: Fase 4 — moderation engine (assess rules, review REST + leads
 * integration, queue page, reason labels, migration columns).
 * Run via: wp --path="<site>/app/public" eval-file tests/suite_moderation.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$M = 'TAP_Moderation';
tap_t_assert(class_exists($M), 'TAP_Moderation class present');
tap_t_assert(has_action('admin_init', [$M, 'handle_admin_actions']), 'moderation admin handler hooked');
tap_t_assert(is_callable(['TAP_Dashboard', 'moderation_page']), 'moderation admin page registered');

// --- migration columns present on both tables ---
foreach (['tap_reviews', 'tap_leads'] as $t) {
    $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}{$t}");
    tap_t_assert(in_array('mod_status', $cols), "$t has mod_status column");
    tap_t_assert(in_array('mod_reason', $cols), "$t has mod_reason column");
}

// --- assess() rules (deterministic, accent/size insensitive) ---
$r = function ($txt, $kind = 'review') use ($M) {
    return $M::assess($txt, $kind);
};
tap_t_assert($r('Esto es una mierda')['status'] === 'block' && $r('Esto es una mierda')['reason'] === 'abuse', 'abuse (ES) blocked');
tap_t_assert($r('Terrible service, fucking awful')['status'] === 'block', 'abuse (EN) blocked');
tap_t_assert($r('great tour, totally recommended')['status'] === 'ok', 'clean text passes');
tap_t_assert($r('Compra ahora esta oferta increíble')['status'] === 'block' && $r('Compra ahora esta oferta increíble')['reason'] === 'spam', 'spam keywords blocked');
tap_t_assert($r('details at https://bit.ly/xyz and http://example.com/a and www.spam.com')['reason'] === 'spam', '3+ links blocked as spam');
tap_t_assert($r('check this link http://example.com/page')['status'] === 'review' && $r('check this link http://example.com/page')['reason'] === 'links', 'single link flagged for review');
tap_t_assert($r('contact me unonueve@hotmail.com')['status'] === 'block' && $r('contact me unonueve@hotmail.com')['reason'] === 'pii', 'email in review blocked as PII');
tap_t_assert($r('llámame al +34 612 345 678 please')['status'] === 'block', 'phone in review blocked as PII');
tap_t_assert($r('mira este link http://mi-sitio.com para info', 'lead')['status'] === 'review', 'lead with link flagged for review');
tap_t_assert($r('gana dinero facil trabajando desde casa', 'lead')['status'] === 'block', 'lead spam blocked');
tap_t_assert($r('aaaaaaaaaaaaaaaaaaaaaaaaaaa', 'lead')['reason'] === 'gibberish', 'repeated-char text flagged gibberish');
tap_t_assert($r('', 'review')['status'] === 'ok', 'empty text not flagged');
tap_t_assert($M::reason_label('abuse') === 'Lenguaje abusivo', 'reason label translated (ES default)');

// --- REST: review submissions are filtered ---
list($fid, $ftype) = tap_t_find_service();
tap_t_assert($fid > 0, 'discovered service for review moderation');
$u1 = tap_t_make_subscriber();
tap_t_assert($u1 > 0, 'created fixture reviewer');
$mb1 = tap_t_seed_booking(['client_id' => $u1, 'service_type' => $ftype, 'service_id' => $fid]);
wp_set_current_user($u1);
$bad = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 1, 'content' => 'Esto es una mierda de servicio']);
tap_t_assert(tap_t_rest_error_code($bad) === 'review_blocked', 'abusive review rejected by REST (400)');
tap_t_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE user_id=%d AND service_type=%s AND service_id=%d", $u1, $ftype, $fid)) === 0, 'blocked review not stored');

$mk = 'MDRV' . substr(md5(uniqid('', true)), 0, 8);
$ok = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'content' => 'Great experience ' . $mk]);
tap_t_assert(tap_t_rest_error_code($ok) === '', 'clean review accepted');
$ok_id = (int) tap_t_rest_data($ok)['review_id'];
$ok_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_reviews WHERE id=%d", $ok_id));
tap_t_assert($ok_row && $ok_row->mod_status === 'ok' && $ok_row->mod_reason === null, 'clean review stored ok');

$lnk = 'MDRL' . substr(md5(uniqid('', true)), 0, 8);
$u2 = tap_t_make_subscriber();
tap_t_assert($u2 > 0, 'created second fixture reviewer');
$mb2 = tap_t_seed_booking(['client_id' => $u2, 'service_type' => $ftype, 'service_id' => $fid]);
wp_set_current_user($u2);
$mod = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 4, 'content' => 'See ' . $lnk . ' here http://example.com/guide']);
tap_t_assert(tap_t_rest_error_code($mod) === '', 'link-carrying review accepted for moderation');
$mod_id = (int) tap_t_rest_data($mod)['review_id'];
$mod_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_reviews WHERE id=%d", $mod_id));
tap_t_assert($mod_row && $mod_row->mod_status === 'review' && $mod_row->mod_reason === 'links', 'link review flagged pending moderation');
tap_t_assert((int) $mod_row->is_approved === 0, 'flagged review still hidden from public');
wp_set_current_user(0);

// --- leads: spam blocked, clean stored, URL-flagged held ---
$pref = 'MDR' . substr(md5(uniqid('', true)), 0, 8);
$owner = wp_create_user($pref . '_o', wp_generate_password(12, false), $pref . '_o@example.test');
$apost = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'MDR Agency']);
update_post_meta($apost, '_tap_agency_user_id', $owner);
update_post_meta($apost, '_tap_agency_email', 'mdr_agency@example.test');
$wpdb->insert($wpdb->prefix . 'tap_agencies', [
    'user_id' => $owner,
    'name'    => 'MDR Agency',
    'slug'    => 'mdr-' . $pref,
    'email'   => 'mdr_agency@example.test',
    'commission_percent' => 10.00,
    'is_active' => 1,
]);
$agency_id = (int) $wpdb->insert_id;
tap_t_assert($agency_id > 0, 'created fixture agency for leads');

$spam = TAP_Leads::submit(['name' => 'MDR Spammer', 'email' => $pref . '_s@example.test', 'message' => 'gana dinero facil ya', 'agency_id' => $agency_id]);
tap_t_assert(is_wp_error($spam) && $spam->get_error_code() === 'lead_blocked', 'spam lead blocked by engine');
tap_t_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tap_leads WHERE email=%s", $pref . '_s@example.test')) === 0, 'blocked lead not stored');

$clean = TAP_Leads::submit(['name' => 'MDR Clean', 'email' => $pref . '_c@example.test', 'message' => 'Quiero información sobre el tour', 'agency_id' => $agency_id]);
$clean_id = is_wp_error($clean) ? 0 : (int) $clean;
tap_t_assert($clean_id > 0, 'clean lead stored');
$clean_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE id=%d", $clean_id));
tap_t_assert($clean_row && $clean_row->mod_status === 'ok', 'clean lead stored ok');

$flagged = TAP_Leads::submit(['name' => 'MDR Linker', 'email' => $pref . '_l@example.test', 'message' => 'Revisa mi web http://mdr.example.com', 'agency_id' => $agency_id]);
$flag_id = is_wp_error($flagged) ? 0 : (int) $flagged;
$flag_row = $flag_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE id=%d", $flag_id)) : null;
tap_t_assert($flag_id > 0 && $flag_row && $flag_row->mod_status === 'review' && $flag_row->mod_reason === 'links', 'URL lead stored flagged for review');

// --- queue() aggregates flagged rows (both kinds) ---
$q = TAP_Moderation::queue(200);
$found_review = array_filter($q, fn ($i) => $i->kind === 'review' && (int) $i->id === $mod_id);
$found_lead = array_filter($q, fn ($i) => $i->kind === 'lead' && (int) $i->id === $flag_id);
tap_t_assert(count($found_review) === 1, 'flagged review appears in moderation queue');
tap_t_assert(count($found_lead) === 1, 'flagged lead appears in moderation queue');

// --- admin page renders the flagged items ---
ob_start();
TAP_Dashboard::moderation_page();
$html = (string) ob_get_clean();
tap_t_assert(strpos($html, 'Moderación de contenido') !== false, 'moderation page title rendered');
tap_t_assert(strpos($html, $lnk) !== false, 'flagged review content surfaced on page');
tap_t_assert(strpos($html, 'mdr.example.com') !== false, 'flagged lead content surfaced on page');

// --- admin action guard: invalid context/nonce is inert ---
$_GET = [];
tap_t_assert(($M::handle_admin_actions()) === null, 'admin action handler inert without page param');

// --- reason label round trip ---
tap_t_assert($M::reason_label('pii') === 'Datos personales', 'pii label ES');

// ===== Cleanup =====
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_reviews WHERE user_id IN (%d,%d)", $u1, $u2));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_leads WHERE email LIKE %s", $pref . '_%'));
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['id' => $agency_id]);
wp_delete_post($apost, true);
tap_t_cleanup_bookings([$mb1, $mb2]);
wp_delete_user($u1);
wp_delete_user($u2);
if ($owner && !is_wp_error($owner)) {
    wp_delete_user($owner);
}
tap_t_finish();