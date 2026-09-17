<?php
/**
 * suite_back_office.php — T9 agency back-office (Fase 16).
 * Covers agency-side booking operations, the self-service payout request
 * lifecycle and the new /mi-cuenta/ back-office sections.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

$bo_users = [];
$bo_agencies = [];

function bo_make_agency($role = 'tap_agency_admin') {
    global $bo_users, $bo_agencies;
    $suffix = wp_generate_password(5, false);
    $uname  = 'bo_' . $suffix;
    $uid    = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => $role,
        'display_name' => 'BO ' . $role,
    ]);
    if (is_wp_error($uid) || !$uid) {
        return [0, 0];
    }
    $aid = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'BO Agency ' . $suffix]);
    if (!$aid || is_wp_error($aid)) {
        wp_delete_user($uid);
        return [0, 0];
    }
    update_post_meta($aid, '_tap_agency_user_id', (int) $uid);
    $bo_users[]    = (int) $uid;
    $bo_agencies[] = (int) $aid;
    return [(int) $uid, (int) $aid];
}

$bookings = [];
list($owner_uid, $owner_aid) = bo_make_agency('tap_agency_admin');
list($other_uid, $other_aid) = bo_make_agency('tap_agency_admin');
list($emp_uid, $emp_aid)     = bo_make_agency('tap_agency_employee');
tap_t_assert($owner_uid && $owner_aid && $other_uid && $other_aid, 'created owner + other agency fixtures');

list($ftype, $fid) = tap_t_find_service(false);
tap_t_assert($fid > 0, "discovered a published service ({$ftype} id={$fid})");

$in  = gmdate('Y-m-d', strtotime('+15 days'));
$out = gmdate('Y-m-d', strtotime('+17 days'));

function bo_booking($agency_id, $overrides = []) {
    global $bookings, $ftype, $fid;
    $defaults = [
        'agency_id'    => $agency_id,
        'service_type' => $ftype ?: 'tap_tour',
        'service_id'   => $fid ?: 75,
        'check_in'     => gmdate('Y-m-d', strtotime('+15 days')),
        'check_out'    => gmdate('Y-m-d', strtotime('+17 days')),
        'total_amount' => 100,
        'commission_amount' => 10,
        'commission_percent'=> 10,
        'commission_status' => 'owed',
        'payment_status'=> 'pending',
        'status'        => 'pending',
    ];
    $id = tap_t_seed_booking(array_merge($defaults, $overrides));
    $bookings[] = (int) $id;
    return (int) $id;
}

// ── 1) Ownership guards ──────────────────────────────────────────────
$b1 = bo_booking($owner_aid, ['status' => 'request']);
wp_set_current_user($other_uid);
$r = TAP_Booking::agency_booking_action($b1, 'confirm');
tap_t_assert(is_wp_error($r) && 'forbidden' === $r->get_error_code(), 'another agency cannot operate a foreign booking');

wp_set_current_user($owner_uid);
$r = TAP_Booking::agency_booking_action($b1, 'nonsense');
tap_t_assert(is_wp_error($r) && 'invalid_action' === $r->get_error_code(), 'unknown operation rejected');

// ── 2) confirm → complete ────────────────────────────────────────────
$r = TAP_Booking::agency_booking_action($b1, 'confirm');
tap_t_assert(true === $r, 'agency confirms a request booking');
tap_t_assert('confirmed' === TAP_Booking::get_booking($b1)->status, 'booking status is confirmed');

$r = TAP_Booking::agency_booking_action($b1, 'complete');
tap_t_assert(true === $r, 'agency completes a confirmed booking');
tap_t_assert('completed' === TAP_Booking::get_booking($b1)->status, 'booking status is completed');
$r = TAP_Booking::agency_booking_action($b1, 'cancel');
tap_t_assert(is_wp_error($r) && 'bad_status' === $r->get_error_code(), 'completed booking cannot be cancelled by agency');

// ── 3) mark_paid fires the commission hook ───────────────────────────
$before = TAP_Booking::get_booking($b1);
$hook_hits = [];
$cb = function ($booking_id) use (&$hook_hits) { $hook_hits[] = (int) $booking_id; };
add_action('tap_payment_completed', $cb);
$b2 = bo_booking($owner_aid, ['status' => 'confirmed', 'payment_status' => 'pending']);
$r  = TAP_Booking::agency_booking_action($b2, 'mark_paid');
tap_t_assert(is_wp_error($r) && 'forbidden' === $r->get_error_code(), 'agency cannot mark a booking as paid');
tap_t_assert('pending' === TAP_Booking::get_booking($b2)->payment_status && !$hook_hits, 'denied manual payment leaves payment and hooks untouched');
list($admin_uid, $admin_aid) = bo_make_agency('administrator');
tap_t_assert($admin_uid > 0, 'created administrator fixture');
foreach (['request', 'pending', 'completed', 'cancelled', 'refunded'] as $blocked_status) {
    $blocked = bo_booking($owner_aid, ['status' => $blocked_status]);
    $denied = TAP_Booking::agency_booking_action($blocked, 'mark_paid', $admin_uid);
    tap_t_assert(is_wp_error($denied) && 'bad_status' === $denied->get_error_code(), 'admin cannot mark paid from ' . $blocked_status);
}
$refunded = bo_booking($owner_aid, ['status' => 'confirmed', 'payment_status' => 'refunded']);
$denied = TAP_Booking::agency_booking_action($refunded, 'mark_paid', $admin_uid);
tap_t_assert(is_wp_error($denied) && 'refunded' === TAP_Booking::get_booking($refunded)->payment_status, 'admin cannot reverse a refund using mark_paid');
$r = TAP_Booking::agency_booking_action($b2, 'mark_paid', $admin_uid);
$repeat = TAP_Booking::agency_booking_action($b2, 'mark_paid', $admin_uid);
remove_action('tap_payment_completed', $cb);
tap_t_assert(true === $repeat && count($hook_hits) === 1, 'repeated manual payment does not repeat the payment event');
tap_t_assert(true === $r, 'administrator marks a confirmed booking as paid');
tap_t_assert('paid' === TAP_Booking::get_booking($b2)->payment_status, 'payment_status becomes paid');
tap_t_assert(in_array($b2, $hook_hits, true), 'mark_paid triggered tap_payment_completed');
tap_t_assert('owed' === TAP_Booking::get_booking($b2)->commission_status, 'commission is owed after payment');

// ── 4) agency cancellation applies the policy penalty + voids commission ──
$b3 = bo_booking($owner_aid, [
    'status' => 'confirmed', 'payment_status' => 'paid',
    'check_in' => gmdate('Y-m-d', strtotime('+10 days')),
    'check_out' => gmdate('Y-m-d', strtotime('+12 days')),
]);
$r = TAP_Booking::agency_booking_action($b3, 'cancel');
$row3 = TAP_Booking::get_booking($b3);
tap_t_assert(true === $r, 'agency cancels a confirmed booking');
tap_t_assert('cancelled' === $row3->status && 'agency' === $row3->cancelled_by, 'status cancelled and cancelled_by=agency');
tap_t_assert(!empty($row3->cancellation_policy), 'cancellation policy recorded on agency cancel');
tap_t_assert('void' === $row3->commission_status, 'cancelled booking commission is voided');

// ── 5) payout request lifecycle ──────────────────────────────────────
$p1 = bo_booking($owner_aid, ['status' => 'confirmed', 'payment_status' => 'paid', 'commission_amount' => 12, 'commission_status' => 'owed']);
$p2 = bo_booking($owner_aid, ['status' => 'confirmed', 'payment_status' => 'paid', 'commission_amount' => 8,  'commission_status' => 'owed']);

$r = TAP_Payouts::request($other_aid);
tap_t_assert(is_wp_error($r) && 'nothing_owed' === $r->get_error_code(), 'payout request with no owed commission is rejected');

$pid = TAP_Payouts::request($owner_aid, 'paypal', 'suite payout');
tap_t_assert(is_int($pid) && $pid > 0, 'agency payout request created a pending payout');
$prow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $pid));
tap_t_assert($prow && 'pending' === $prow->status && 'agency' === $prow->source, 'payout row is pending + source=agency');
$ids = array_map('intval', explode(',', $prow->booking_ids));
$ph  = implode(',', array_fill(0, count($ids), '%d'));
$expected_amount = (float) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(commission_amount),0) FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$ph}) AND payment_status='paid' AND commission_status='owed'",
    ...$ids
));
tap_t_assert($prow && abs((float) $prow->amount - round($expected_amount, 2)) < 0.001, 'payout amount exactly equals the grouped owed commissions');
tap_t_assert(in_array($p1, $ids, true) && in_array($p2, $ids, true), 'payout covers both owed bookings');
tap_t_assert('owed' === TAP_Booking::get_booking($p1)->commission_status, 'bookings stay owed while the request is pending');

$r = TAP_Payouts::request($owner_aid);
tap_t_assert(is_wp_error($r) && 'nothing_owed' === $r->get_error_code(), 'the same owed bookings cannot be requested twice (pending payout excluded)');

$r = TAP_Payouts::complete($pid);
tap_t_assert(true === $r, 'admin completes the agency payout');
tap_t_assert('paid' === TAP_Booking::get_booking($p1)->commission_status, 'bookings become paid on payout completion');
tap_t_assert('paid' === TAP_Booking::get_booking($p2)->commission_status, 'second booking becomes paid too');
tap_t_assert(false === TAP_Payouts::complete($pid), 'completing an already-completed payout fails');
tap_t_assert(is_wp_error(TAP_Payouts::request($owner_aid)) || (int) $prow->id !== (int) TAP_Payouts::request($owner_aid), 'paid bookings are no longer requestable');

// ── 6) payout cancellation reverts nothing it should not ─────────────
$p3  = bo_booking($owner_aid, ['status' => 'confirmed', 'payment_status' => 'paid', 'commission_amount' => 5, 'commission_status' => 'owed']);
$pid2 = TAP_Payouts::request($owner_aid);
tap_t_assert(is_int($pid2) && $pid2 > 0, 'a second payout request is created for remaining owed commission');
tap_t_assert(true === TAP_Payouts::cancel($pid2), 'pending payout can be cancelled');
tap_t_assert('owed' === TAP_Booking::get_booking($p3)->commission_status, 'cancelled request leaves the commission owed');
tap_t_assert(false === TAP_Payouts::complete($pid2), 'a cancelled payout cannot be completed');

// ── 7) front-office sections + wiring ────────────────────────────────
$src = file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false !== strpos($src, 'tap_dash_agency_booking') && false !== strpos($src, 'tap_dash_agency_payout'), 'dashboard class defines the book-office AJAX actions');
tap_t_assert(has_action('wp_ajax_tap_dash_agency_booking') && has_action('wp_ajax_tap_dash_agency_payout'), 'book-office AJAX endpoints are registered');
tap_t_assert(false !== strpos($src, "'bo-operations'") && false !== strpos($src, "'bo-finances'") && false !== strpos($src, "'bo-listings'"), 'back-office sections wired in the dashboard nav/routing');

wp_set_current_user($owner_uid);
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(false !== strpos($out, 'Resumen') && false !== strpos($out, 'tap-stats-grid') && false !== strpos($out, 'Reservas recientes'), 'agency admin overview renders the stats grid + recent bookings');
tap_t_assert(false !== strpos($out, 'Ver perfil público') && false !== strpos($out, 'Gestionar listados'), 'overview offers public profile + listings quick actions');

$b4 = bo_booking($owner_aid, ['status' => 'pending']);
$_GET['seccion'] = 'bo-operations';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'Operaciones') && false !== strpos($out, 'tap-bo-api'), 'agency admin sees the operations section with action buttons');
tap_t_assert(false !== strpos($out, 'Confirmar') && false !== strpos($out, 'Cancelar'), 'operations section renders confirm/cancel actions');

$_GET['seccion'] = 'bo-finances';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'Finanzas') && false !== strpos($out, 'tap-bo-payout'), 'agency admin sees the finances section with the payout form');

wp_set_current_user($emp_uid);
$_GET['seccion'] = 'overview';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'Operaciones') && false === strpos($out, 'bo-finances'), 'employee nav hides the finances section');
tap_t_assert(false === strpos($out, 'Por cobrar') && false === strpos($out, 'Leads de contacto'), 'employee overview hides finance cards + leads');
$_GET['seccion'] = 'bo-finances';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'Solo el administrador de la agencia'), 'employee cannot render the finances section');

// legacy /dashboard/ redirect + unified back-office links
tap_t_assert(has_action('template_redirect') && false !== strpos($src, 'dashboard_redirect'), 'legacy /dashboard/ page 301-redirects to /mi-cuenta/');
$all_includes = implode("\n", array_map(function ($f) { return file_get_contents(WP_PLUGIN_DIR . '/travel-agency-platform/includes/' . $f); }, ['class-emails.php', 'class-ajax.php', 'class-chatbot.php', 'class-shortcodes.php', 'class-front-dash.php']));
tap_t_assert(false === strpos($all_includes, "home_url('/dashboard/')") && false === strpos($all_includes, 'home_url("/dashboard/")'), 'no plugin code links to the legacy /dashboard/ page anymore');

$cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_commission_payments");
tap_t_assert(in_array('source', $cols, true), 'payout table exposes the source column');

// cleanup ────────────────────────────────────────────────────────────
wp_set_current_user(0);
tap_t_cleanup_bookings($bookings);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_commission_payments WHERE id IN (%d,%d)", $pid, $pid2));
foreach ($bo_agencies as $aid) {
    wp_delete_post($aid, true);
}
foreach ($bo_users as $uid) {
    wp_delete_user($uid);
}
tap_t_finish();
