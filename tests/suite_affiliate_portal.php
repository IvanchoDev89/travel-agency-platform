<?php
/**
 * suite_affiliate_portal.php — self-service affiliate dashboard and settlements.
 * FUNCTIONAL: real affiliate, real conversions, real payout request.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_affiliate_portal.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) $admin[0]); }
$sfx = tap_t_suffix();

$user_id = (int) ($admin[0] ?? 0);
$user = get_userdata($user_id);
$email = $user ? $user->user_email : 'aff-' . strtolower($sfx) . '@example.test';

/* --- 1) affiliate linked by user_id / email ---------------------------------- */
$wpdb->insert(TAP_Affiliates::table(), [
    'name' => 'Portal Afiliado ' . $sfx,
    'email' => $email,
    'code' => 'AFF' . strtoupper($sfx),
    'commission_pct' => 10,
    'cap_per_booking' => 30,
    'status' => 'active',
    'user_id' => $user_id,
]);
$aff_id = (int) $wpdb->insert_id;
tap_t_assert($aff_id > 0, 'affiliate fixture created');
$mine = TAP_Affiliates::for_user($user_id);
tap_t_assert($mine && (int) $mine->id === $aff_id, 'affiliate resolves from the logged-in user');

/* --- 2) clicks, conversions and released commissions -------------------------- */
$wpdb->insert(TAP_Affiliates::clicks_table(), [
    'affiliate_id' => $aff_id, 'ip' => '203.0.113.8', 'user_agent' => 'TapAff/1.0',
]);
$wpdb->insert(TAP_Affiliates::clicks_table(), [
    'affiliate_id' => $aff_id, 'ip' => '203.0.113.9', 'user_agent' => 'TapAff/1.0',
]);
$booking_ids = [];
foreach ([12.5, 7.5] as $amount) {
    $bid = tap_t_seed_booking(['booking_code' => 'AFF-' . $sfx . '-' . wp_generate_password(4, false)]);
    $booking_ids[] = $bid;
    $wpdb->insert(TAP_Affiliates::conv_table(), [
        'affiliate_id' => $aff_id, 'booking_id' => $bid,
        'commission_amount' => $amount, 'status' => 'released',
        'release_at' => current_time('mysql'),
    ]);
}
$stats = TAP_Affiliates::stats($aff_id);
tap_t_assert(2 === (int) $stats['clicks'], 'stats count clicks');
tap_t_assert(2 === (int) $stats['conversions'], 'stats count conversions');
tap_t_assert(abs(20.0 - (float) $stats['available']) < 0.01, 'available is the sum of released commissions');
tap_t_assert(2 === count(TAP_Affiliates::available_conversions($aff_id)), 'both conversions are available');

/* --- 3) payout request is server-computed and idempotent --------------------- */
$payout_id = TAP_Affiliates::request_payout($aff_id, $user_id);
tap_t_assert(is_int($payout_id) && $payout_id > 0, 'payout request created');
$payout = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TAP_Affiliates::payouts_table() . ' WHERE id=%d', $payout_id));
tap_t_assert(abs(20.0 - (float) $payout->amount) < 0.01, 'payout amount comes from released commissions');
tap_t_assert(2 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TAP_Affiliates::payout_items_table() . ' WHERE payout_id=%d', $payout_id)), 'payout links both conversions');
tap_t_assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TAP_Affiliates::conv_table() . ' WHERE affiliate_id=%d AND status="released"', $aff_id)), 'requested conversions are no longer available');
$again = TAP_Affiliates::request_payout($aff_id, $user_id);
tap_t_assert(is_wp_error($again), 'a second request cannot double-pay');

// A different user cannot request this affiliate's payout.
$other = get_users(['role' => 'subscriber', 'number' => 1, 'fields' => 'ids']);
if ($other) {
    $wpdb->update(TAP_Affiliates::conv_table(), ['status' => 'released'], ['affiliate_id' => $aff_id]);
    wp_set_current_user((int) $other[0]);
    $denied = TAP_Affiliates::request_payout($aff_id, (int) $other[0]);
    tap_t_assert(is_wp_error($denied), 'unrelated user cannot request the payout');
    wp_set_current_user((int) $admin[0]);
}

/* --- 4) portal rendering + nav ------------------------------------------------ */
$wpdb->query('DELETE FROM ' . TAP_Affiliates::payout_items_table() . ' WHERE payout_id IN (SELECT id FROM ' . TAP_Affiliates::payouts_table() . ' WHERE affiliate_id=' . (int) $aff_id . ')');
$wpdb->delete(TAP_Affiliates::payouts_table(), ['affiliate_id' => $aff_id]);
$wpdb->update(TAP_Affiliates::conv_table(), ['status' => 'released'], ['affiliate_id' => $aff_id]);
$html = TAP_Affiliates::portal();
tap_t_assert(false !== strpos($html, 'Portal de afiliado'), 'portal renders its heading');
tap_t_assert(false !== strpos($html, 'tap-aff-metrics'), 'portal renders metrics');
tap_t_assert(false !== strpos($html, 'AFF' . strtoupper($sfx)), 'portal shows the affiliate code in tracking links');
tap_t_assert(false !== strpos($html, 'name="tap_affiliate_nonce"') && false !== strpos($html, 'name="affiliate_id" value="' . $aff_id . '"'), 'payout form carries the affiliate nonce and id');
$src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-affiliates.php');
tap_t_assert(false !== strpos($src, 'admin_post_tap_affiliate_payout_status'), 'admin settlement handler is registered');
tap_t_assert(false !== strpos($src, 'current_user_can(\'manage_options\')'), 'admin settlement requires manage_options');
$nav_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash-client.php');
tap_t_assert(false !== strpos($nav_src, "case 'affiliates'"), 'mi-cuenta routes the affiliate section');
tap_t_assert(false !== strpos($nav_src, 'TAP_Affiliates::for_user'), 'affiliate nav is shown only to linked users');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->query('DELETE FROM ' . TAP_Affiliates::payout_items_table() . ' WHERE payout_id IN (SELECT id FROM ' . TAP_Affiliates::payouts_table() . ' WHERE affiliate_id=' . (int) $aff_id . ')');
$wpdb->delete(TAP_Affiliates::payouts_table(), ['affiliate_id' => $aff_id]);
$wpdb->delete(TAP_Affiliates::conv_table(), ['affiliate_id' => $aff_id]);
$wpdb->delete(TAP_Affiliates::clicks_table(), ['affiliate_id' => $aff_id]);
$wpdb->delete(TAP_Affiliates::table(), ['id' => $aff_id]);
foreach ($booking_ids as $bid) { $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]); }
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
