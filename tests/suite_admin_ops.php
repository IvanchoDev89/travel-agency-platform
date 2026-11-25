<?php
/**
 * suite_admin_ops.php — disputes, ARCO and campaign preview operations.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) ($admin[0] ?? 0)); }
$sfx = tap_t_suffix();

/* --- disputes ---------------------------------------------------------------- */
$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Ops E2E ' . $sfx]);
$bid = tap_t_seed_booking(['agency_id' => (int) $agency_id, 'booking_code' => 'OPS-' . $sfx, 'status' => 'confirmed', 'payment_status' => 'paid']);
$wpdb->insert($wpdb->prefix . 'tap_disputes', [
    'booking_id' => $bid, 'agency_id' => (int) $agency_id,
    'guest_email' => 'ops-' . strtolower($sfx) . '@example.test',
    'reason' => 'service_quality', 'details' => 'Detalle disputa ' . $sfx,
    'status' => 'open', 'prev_commission' => 'owed',
]);
$did = (int) $wpdb->insert_id;
$detail = TAP_Dashboard_Money::dispute_detail($did);
tap_t_assert(false !== strpos($detail, 'OPS-' . $sfx), 'dispute detail shows the booking');
tap_t_assert(false !== strpos($detail, 'Detalle disputa ' . $sfx), 'dispute detail shows the case details');
tap_t_assert(false !== strpos($detail, 'outcome'), 'dispute detail offers a resolution');

$money_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-money.php');
tap_t_assert(false !== strpos($money_src, 'name="ds"'), 'disputes have text search');
tap_t_assert(false !== strpos($money_src, 'name="dagency"'), 'disputes have agency filter');
tap_t_assert(false !== has_action('admin_post_tap_privacy_export'), 'ARCO export handler registered');

/* --- ARCO -------------------------------------------------------------------- */
$wpdb->insert($wpdb->prefix . 'tap_privacy_requests', [
    'email' => 'arco-' . strtolower($sfx) . '@example.test',
    'name' => 'Solicitante ' . $sfx,
    'rights' => 'access,erasure',
    'details' => 'Solicitud de prueba ' . $sfx,
    'status' => 'pending',
]);
$rid = (int) $wpdb->insert_id;
$req = TAP_Privacy::get_request($rid);
tap_t_assert($req && $req->email === 'arco-' . strtolower($sfx) . '@example.test', 'ARCO request stored and readable');
$note = 'Exportado y verificado ' . $sfx;
tap_t_assert(true === TAP_Privacy::mark_handled($rid, $note), 'ARCO request handled with note');
$req2 = TAP_Privacy::get_request($rid);
tap_t_assert($note === $req2->handled_note && 'done' === $req2->status, 'resolution note and status persisted');
$json = TAP_Privacy::export_request($rid);
tap_t_assert(is_string($json) && false !== strpos($json, '"request"'), 'ARCO export returns the data package');
$obj = json_decode($json, true);
tap_t_assert(is_array($obj) && isset($obj['groups']) && isset($obj['request']), 'ARCO export includes request and data groups');
$cons = TAP_Privacy::consents('arco-' . strtolower($sfx) . '@example.test', 1, 10);
tap_t_assert(isset($cons['items']) && isset($cons['total']), 'consent log is searchable and paginated');
$privacy_detail = TAP_Privacy::request_detail($rid);
tap_t_assert(false !== strpos($privacy_detail, 'action=tap_privacy_export') && false !== strpos($privacy_detail, '_wpnonce'), 'ARCO detail offers a nonce-guarded export');

/* --- campaigns --------------------------------------------------------------- */
$wpdb->insert($wpdb->prefix . 'tap_email_campaigns', [
    'name' => 'Campaña Ops ' . $sfx, 'subject' => 'Asunto ' . $sfx,
    'body' => '<p>Contenido ' . $sfx . '</p>', 'segment' => 'all', 'status' => 'draft',
]);
$cid = (int) $wpdb->insert_id;
$eligible = TAP_Email_Campaigns::eligible_recipients('all');
$raw = TAP_Email_Campaigns::recipients('all');
tap_t_assert(count($eligible) <= count($raw), 'eligible recipients are a filtered subset');
$preview = TAP_Email_Campaigns::preview($cid);
tap_t_assert(false !== strpos($preview, 'Asunto ' . $sfx), 'campaign preview shows the subject');
tap_t_assert(false !== strpos($preview, 'Contenido ' . $sfx), 'campaign preview shows the body');
tap_t_assert(false !== strpos($preview, 'Destinatarios elegibles'), 'campaign preview shows the real recipient count');
tap_t_assert(false !== strpos($preview, 'action=tap_email_campaign_test') && false !== strpos($preview, '_wpnonce'), 'campaign preview offers a nonce-guarded test send');
tap_t_assert(false !== has_action('admin_post_tap_email_campaign_test'), 'campaign test handler registered');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete($wpdb->prefix . 'tap_disputes', ['id' => $did]);
$wpdb->delete($wpdb->prefix . 'tap_privacy_requests', ['id' => $rid]);
$wpdb->delete($wpdb->prefix . 'tap_email_campaigns', ['id' => $cid]);
$wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Ops E2E ' . $sfx]);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
