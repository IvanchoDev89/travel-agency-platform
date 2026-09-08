<?php
/**
 * P4 — Leads de contacto: core de TAP_Leads, shortcode y export CSV.
 * Site-agnostic: creates its own test agency/service/leads and cleans up.
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb, $ld_agency_id, $ld_agency_post_id, $ld_other_agency_id, $ld_service_id, $ld_other_service_id, $ld_user_ids, $ld_post_ids;

$token    = 'lead_' . wp_generate_password(6, false);
$ld_user_ids = [];
$ld_post_ids = [];

// Unique prefix to reuse across sub-tests.
$pref = 'nr9z' . $token;

function ld_make_user($login) {
    $uid = wp_create_user($login, wp_generate_password(16, false), $login . '@example.test');
    if (is_wp_error($uid)) {
        return 0;
    }
    $GLOBALS['ld_user_ids'][] = (int) $uid;
    return (int) $uid;
}

function ld_make_agency($uid, $label, $active = 1) {
    global $wpdb;
    $post_id = wp_insert_post([
        'post_type'   => 'tap_agency',
        'post_status' => 'publish',
        'post_title'  => 'Lead Test Agency ' . $label,
    ]);
    $GLOBALS['ld_post_ids'][] = (int) $post_id;
    update_post_meta($post_id, '_tap_agency_user_id', $uid);
    update_post_meta($post_id, '_tap_agency_email', 'agency' . $label . '@example.test');

    $slug = 'leads-' . $label . '-' . wp_generate_password(6, false);
    $wpdb->insert($wpdb->prefix . 'tap_agencies', [
        'user_id' => $uid,
        'name'    => 'Lead Test Agency ' . $label,
        'slug'    => $slug,
        'email'   => 'agency' . $label . '@example.test',
        'commission_percent' => 10.00,
        'is_active' => $active,
    ]);
    return [(int) $wpdb->insert_id, (int) $post_id];
}

function ld_make_service($agency_id, $label) {
    $post_id = wp_insert_post([
        'post_type'   => 'tap_tour',
        'post_status' => 'publish',
        'post_title'  => 'Lead Tour ' . $label . '-' . wp_generate_password(6, false),
    ]);
    $GLOBALS['ld_post_ids'][] = (int) $post_id;
    update_post_meta($post_id, '_tap_tour_agency_id', $agency_id);
    return (int) $post_id;
}

// ===== Setup =====
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_leads WHERE email LIKE %s", $pref . '_%'));
$any = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_leads");
tap_t_assert(0 === (int) $any, 'leads table is empty before suite');

$ld_owner_uid = ld_make_user($pref . '_o');
tap_t_assert($ld_owner_uid > 0, 'created lead test owner user');
list($ld_agency_id, $ld_agency_post_id) = ld_make_agency($ld_owner_uid, $pref . 'A');
list($ld_other_agency_id, $ld_other_agency_post_id) = ld_make_agency(ld_make_user($pref . '_b'), $pref . 'B');
$ld_service_id = ld_make_service($ld_agency_id, $pref . 'S');
$ld_other_service_id = ld_make_service($ld_other_agency_id, $pref . 'T');
tap_t_assert($ld_agency_id > 0 && $ld_other_agency_id > 0, 'created two test agencies');

$email = $pref . '_c@example.test';
$ip    = '10.' . (abs(crc32($pref)) % 255 + 1) . '.0.1';

// ===== Validation =====
$r = TAP_Leads::submit(['name' => 'Mini Lead', 'agency_id' => $ld_agency_id, 'email' => '']);
tap_t_assert(is_wp_error($r) && 'email_required' === $r->get_error_code(), 'submit rejects empty email');

$r = TAP_Leads::submit(['name' => 'Mini Lead', 'agency_id' => $ld_agency_id, 'email' => 'invalid@domain']);
tap_t_assert(is_wp_error($r) && in_array($r->get_error_code(), ['email_required', 'invalid_email'], true), 'submit rejects invalid email');

$r = TAP_Leads::submit(['name' => '', 'email' => $email, 'agency_id' => $ld_agency_id]);
tap_t_assert(is_wp_error($r) && 'name_required' === $r->get_error_code(), 'submit rejects empty name');

$r = TAP_Leads::submit(['name' => 'Mini Lead', 'email' => $email, 'agency_id' => $ld_agency_id, 'message' => str_repeat('x', 2200)]);
tap_t_assert(is_wp_error($r) && 'message_too_long' === $r->get_error_code(), 'submit rejects message over 2000 chars');

$r = TAP_Leads::submit(['name' => 'Mini Lead', 'email' => $email, 'agency_id' => 999999]);
tap_t_assert(is_wp_error($r) && 'invalid_agency' === $r->get_error_code(), 'submit rejects unknown agency');

$r = TAP_Leads::submit(['name' => 'Mini Lead', 'email' => $email, 'agency_id' => $ld_other_agency_id, 'service_id' => $ld_service_id]);
tap_t_assert(is_wp_error($r) && 'invalid_service' === $r->get_error_code(), 'submit rejects service not owned by agency');

// ===== Happy path =====
$lid = TAP_Leads::submit([
    'name'       => 'Carla Turista',
    'email'      => $email,
    'phone'      => '+54 911 555 0100',
    'message'    => 'Quiero info del tour.',
    'agency_id'  => $ld_agency_id,
    'service_id' => $ld_service_id,
    'ip'         => $ip,
]);
tap_t_assert(is_int($lid) && $lid > 0, 'submit returns lead id');

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE id = %d", $lid));
tap_t_assert($row && $row->agency_id == $ld_agency_id, 'lead stored with agency_id');
tap_t_assert($row && $row->service_id == $ld_service_id, 'lead stored with service_id');
tap_t_assert($row && $row->name === 'Carla Turista' && $row->email === $email, 'lead stored with sanitized name/email');
tap_t_assert($row && $row->phone === '+54 911 555 0100', 'lead stored with phone');
tap_t_assert($row && $row->source === 'agency', 'lead source defaults to agency');

$lid2 = TAP_Leads::submit([
    'name'      => 'Dan Acosta',
    'email'     => $pref . '_d@example.test',
    'agency_id' => $ld_agency_id,
    'service_id' => $ld_service_id,
    'ip'        => '10.' . (abs(crc32($pref)) % 255 + 1) . '.0.2',
]);
tap_t_assert(is_int($lid2), 'second lead accepted');

// ===== Listing queries =====
$all = TAP_Leads::for_agency($ld_agency_id, 0);
$count = TAP_Leads::count_for_agency($ld_agency_id);
tap_t_assert($count === 2, 'count_for_agency returns 2');
tap_t_assert(count($all) === 2 && (int) $all[0]->id === $lid2, 'for_agency newest first');
$other = TAP_Leads::for_agency($ld_other_agency_id, 0);
tap_t_assert(empty($other) && 0 === TAP_Leads::count_for_agency($ld_other_agency_id), 'other agency sees no leads');

// ===== Rate limit (email) =====
$rl_email = $pref . '_rl@example.test';
$rl_ip    = '10.' . (abs(crc32($pref . 'x')) % 255 + 1) . '.0.9';
$ok = true;
for ($i = 0; $i < TAP_Leads::EMAIL_HOURLY_LIMIT; $i++) {
    $r = TAP_Leads::submit(['name' => 'RL Lead', 'email' => $rl_email, 'agency_id' => $ld_agency_id, 'ip' => $rl_ip]);
    if (!is_int($r)) {
        $ok = false;
        break;
    }
}
tap_t_assert($ok, 'rate limit allows EMAIL_HOURLY_LIMIT leads');
$r = TAP_Leads::submit(['name' => 'RL Lead', 'email' => $rl_email, 'agency_id' => $ld_agency_id, 'ip' => $rl_ip]);
tap_t_assert(is_wp_error($r) && 'lead_rate_limit' === $r->get_error_code(), '6th lead from same email refused');

// ===== IP rate limit =====
$rl_ip2 = '10.' . (abs(crc32($pref . 'y')) % 255 + 1) . '.0.9';
for ($i = 0; $i < TAP_Leads::IP_HOURLY_LIMIT; $i++) {
    TAP_Leads::submit(['name' => 'IP Lead ' . $i, 'email' => $pref . '_ip' . $i . '@example.test', 'agency_id' => $ld_agency_id, 'ip' => $rl_ip2]);
}
$r = TAP_Leads::submit(['name' => 'IP Lead', 'email' => $pref . '_ipx@example.test', 'agency_id' => $ld_agency_id, 'ip' => $rl_ip2]);
tap_t_assert(is_wp_error($r) && 'lead_rate_limit' === $r->get_error_code(), '11th lead from same ip refused');

// ===== CSV export =====
$csv = TAP_Leads::export_csv($ld_agency_id);
tap_t_assert(strpos($csv, 'name,email,phone,message') !== false, 'csv has header row');
tap_t_assert(strpos($csv, $email) === false, 'csv hides full lead email while contact is locked');
tap_t_assert(strpos($csv, '***') !== false, 'csv masks contact fields while locked');
tap_t_assert(substr($csv, 0, 3) === "\xEF\xBB\xBF", 'csv starts with BOM');

// ===== Shortcode rendering =====
$html = do_shortcode('[tap_lead_form agency="' . $ld_agency_id . '"]');
tap_t_assert(strpos($html, 'tap-lead-form') !== false, 'agency shortcode renders form');
tap_t_assert(strpos($html, 'name="tap_name"') !== false, 'agency form has name field');
tap_t_assert(strpos($html, 'value="' . $ld_agency_id . '"') !== false, 'agency form carries agency id');

$html = do_shortcode('[tap_lead_form service="' . $ld_service_id . '"]');
tap_t_assert(strpos($html, 'tap-lead-form') !== false, 'service shortcode renders form');
tap_t_assert(strpos($html, 'value="' . $ld_service_id . '"') !== false, 'service form carries service id');
tap_t_assert(strpos($html, 'value="' . $ld_agency_id . '"') !== false, 'service form derives agency id');

$html = do_shortcode('[tap_lead_form service="' . $ld_other_service_id . '" agency="' . $ld_agency_id . '"]');
tap_t_assert($html === '', 'service of another agency blocked');

$html = do_shortcode('[tap_lead_form agency="999999"]');
tap_t_assert($html === '', 'unknown agency shortcode renders empty');

$dash_html = do_shortcode('[tap_agency_detail id="' . $ld_agency_post_id . '"]');
tap_t_assert(strpos($dash_html, 'tap-lead-form') !== false, 'agency detail page includes lead form');

list($inactive_row, $inactive_post) = ld_make_agency(ld_make_user($pref . '_i'), $pref . 'I', 0);
$html = do_shortcode('[tap_lead_form agency="' . $inactive_post . '"]');
tap_t_assert($html === '', 'inactive agency shortcode renders empty (post id)');
$html = do_shortcode('[tap_lead_form agency="' . $inactive_row . '"]');
tap_t_assert($html === '', 'inactive agency shortcode renders empty (row id)');
$r = TAP_Leads::submit(['name' => 'Mini Lead', 'email' => $email, 'agency_id' => $inactive_row]);
tap_t_assert(is_wp_error($r) && 'invalid_agency' === $r->get_error_code(), 'submit rejects inactive agency');

// ===== Cleanup =====
foreach ($GLOBALS['ld_user_ids'] as $_uid) {
    if ($_uid) {
        wp_delete_user($_uid);
    }
}
foreach ($GLOBALS['ld_post_ids'] as $_pid) {
    if ($_pid) {
        wp_delete_post($_pid, true);
    }
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_leads WHERE email LIKE %s", $pref . '_%'));
$wpdb->query("DELETE FROM {$wpdb->prefix}tap_agencies WHERE name LIKE 'Lead Test Agency " . $pref . "%'");

tap_t_finish();