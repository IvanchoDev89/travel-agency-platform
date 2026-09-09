<?php
/**
 * suite_disputes.php — Fase 6: booking disputes freeze commissions and
 * payout sellements follow a pending → completed/cancelled lifecycle.
 * Covers TAP_Disputes + TAP_Payouts guards, business rules and the voucher
 * dispute form end-to-end. Self-cleaning.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

$b  = $wpdb->prefix . 'tap_bookings';
$p  = $wpdb->prefix . 'tap_commission_payments';
$d  = $wpdb->prefix . 'tap_disputes';

// --- schema -------------------------------------------------------------------------
$dt = $wpdb->get_var("SHOW TABLES LIKE '{$d}'");
tap_t_assert($dt === $d, 'tap_disputes table migrated');
$pc = $wpdb->get_col("DESCRIBE {$p}");
tap_t_assert(in_array('status', $pc, true) && in_array('paid_at', $pc, true), 'payout status + paid_at migrated');
tap_t_assert(get_option('tap_version') === TAP_VERSION, 'tap_version option current');

// --- fixtures -----------------------------------------------------------------------
$u = tap_t_make_subscriber();
tap_t_assert($u > 0, 'created traveler');
list($fid, $ftype) = tap_t_find_service();
$bk = tap_t_seed_booking(['client_id' => $u, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'confirmed', 'payment_status' => 'paid', 'commission_amount' => 25]);
tap_t_assert($bk > 0, 'seeded confirmed paid booking');
$pending = tap_t_seed_booking(['client_id' => $u, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'pending', 'payment_status' => 'pending', 'commission_amount' => 10]);

// --- open() guards -------------------------------------------------------------------
$r = TAP_Disputes::open(999999, $u, '', 'other', 'ghost');
tap_t_assert(is_wp_error($r) && 'invalid_booking' === $r->get_error_code(), 'unknown booking rejected');

$r = TAP_Disputes::open($pending, $u, '', 'other', 'not confirmed');
tap_t_assert(is_wp_error($r) && 'booking_not_disputable' === $r->get_error_code(), 'non-confirmed/non-paid booking not disputable');

$r = TAP_Disputes::open($bk, $u, '', 'bogus_reason', 'x');
tap_t_assert(is_wp_error($r) && 'invalid_reason' === $r->get_error_code(), 'unlisted reason rejected');

// --- valid open freezes commission ----------------------------------------------------
$d1 = TAP_Disputes::open($bk, $u, '', 'service_quality', 'El servicio no coincidió con lo ofrecido');
tap_t_assert(is_int($d1) && $d1 > 0, 'dispute opened on confirmed paid booking');
tap_t_assert('disputed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk)), 'commission frozen while dispute open');
tap_t_assert((int) TAP_Disputes::active_for_booking($bk)->id === $d1, 'active dispute exposed for booking');
tap_t_assert(count(TAP_Disputes::for_agency((int) $wpdb->get_var($wpdb->prepare("SELECT agency_id FROM {$b} WHERE id=%d", $bk)))) === 1, 'agency sees its dispute');

$dup = TAP_Disputes::open($bk, $u, '', 'other', 'again');
tap_t_assert(is_wp_error($dup) && 'duplicate_dispute' === $dup->get_error_code(), 'second open dispute rejected');

// --- resolve: for_agency releases commission -------------------------------------------
$r = TAP_Disputes::resolve($d1, 'for_agency', 'Proveedor confirma el servicio');
tap_t_assert(true === $r, 'dispute resolved in favour of agency');
tap_t_assert('for_agency' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$d} WHERE id=%d", $d1)), 'dispute status persisted');
tap_t_assert('owed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk)), 'commission released after for_agency');
tap_t_assert((string) $wpdb->get_var($wpdb->prepare("SELECT resolution_note FROM {$d} WHERE id=%d", $d1)) === 'Proveedor confirma el servicio', 'resolution note stored');
tap_t_assert((string) $wpdb->get_var($wpdb->prepare("SELECT resolved_at FROM {$d} WHERE id=%d", $d1)) !== '', 'resolved_at stamped');

$r = TAP_Disputes::resolve($d1, 'against_agency', 'late');
tap_t_assert(is_wp_error($r) && 'dispute_not_open' === $r->get_error_code(), 'resolved dispute cannot be re-resolved');

// --- resolve: against_agency voids commission --------------------------------------------
$d2 = TAP_Disputes::open($bk, $u, '', 'misleading', 'Fotos no reales');
tap_t_assert(is_int($d2) && $d2 > 0, 'second dispute opened');
tap_t_assert('disputed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk)), 'commission frozen again');
TAP_Disputes::resolve($d2, 'against_agency', 'Reembolso emitido');
tap_t_assert('void' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk)), 'commission voided when dispute resolved against agency');

// --- resolve: withdrawn restores -----------------------------------------------------------
$bk2 = tap_t_seed_booking(['client_id' => $u, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'confirmed', 'payment_status' => 'paid', 'commission_amount' => 15]);
tap_t_assert($bk2 > 0, 'seeded fresh owed booking');
$d3 = TAP_Disputes::open($bk2, $u, '', 'other', 'fue un malentendido');
tap_t_assert(is_int($d3) && $d3 > 0, 'third dispute opened');
tap_t_assert('disputed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk2)), 'fresh commission frozen');
TAP_Disputes::resolve($d3, 'withdrawn', 'Cliente retira');
tap_t_assert('owed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $bk2)), 'commission restored on withdrawn');

// --- status helpers ------------------------------------------------------------------------
tap_t_assert('Disputa abierta' === TAP_Disputes::status_label('open'), 'open status label ES');
tap_t_assert('Resuelta a favor de la agencia' === TAP_Disputes::status_label('for_agency'), 'for_agency label ES');

// --- payout lifecycle ------------------------------------------------------------------------
$paid_bk = tap_t_seed_booking(['client_id' => $u, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'confirmed', 'payment_status' => 'paid', 'commission_status' => 'paid', 'commission_amount' => 50]);
$wpdb->insert($p, ['agency_id' => tap_t_test_agency(), 'amount' => 50, 'booking_ids' => (string) $paid_bk, 'method' => 'bank_transfer']);
$payout = (int) $wpdb->insert_id;
tap_t_assert('pending' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p} WHERE id=%d", $payout)), 'created payout starts pending');

tap_t_assert(!TAP_Payouts::complete(999999), 'complete on unknown payout returns false');
tap_t_assert(TAP_Payouts::complete($payout), 'payout marked completed');
tap_t_assert('completed' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p} WHERE id=%d", $payout)), 'payout status completed');
tap_t_assert((string) $wpdb->get_var($wpdb->prepare("SELECT paid_at FROM {$p} WHERE id=%d", $payout)) !== '', 'paid_at stamped on completion');
tap_t_assert(!TAP_Payouts::complete($payout), 'double completion rejected');

$wpdb->insert($p, ['agency_id' => tap_t_test_agency(), 'amount' => 50, 'booking_ids' => (string) $paid_bk, 'method' => 'paypal']);
$payout2 = (int) $wpdb->insert_id;
tap_t_assert(TAP_Payouts::cancel($payout2), 'pending payout cancelled');
tap_t_assert('cancelled' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p} WHERE id=%d", $payout2)), 'payout status cancelled');
tap_t_assert('owed' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id=%d", $paid_bk)), 'cancelled payout reverts commission to owed');
tap_t_assert(!TAP_Payouts::cancel($payout), 'completed payout cannot be cancelled');
tap_t_assert('Pendiente' === TAP_Payouts::status_label('pending'), 'payout status label ES');

// --- voucher dispute flow (traveler) -----------------------------------------------------------
$code = $wpdb->get_var($wpdb->prepare("SELECT booking_code FROM {$b} WHERE id=%d", $bk));
wp_set_current_user($u);
$_GET['code'] = $code;
$pre = do_shortcode('[tap_booking_detail]');
tap_t_assert(strpos($pre, 'Abrir una disputa') !== false, 'voucher shows dispute form for traveler');

$_POST = [
    'tap_open_dispute' => '1',
    '_wpnonce' => wp_create_nonce('tap_open_dispute_' . $code),
    'reason'   => 'cancellation',
    'details'  => 'Cancelaron el servicio un día antes',
];
$post = do_shortcode('[tap_booking_detail]');
wp_set_current_user(0);
tap_t_assert(strpos($post, 'retenida') !== false, 'voucher confirms dispute opened after POST');
tap_t_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$d} WHERE booking_id=%d AND status='open'", $bk)) === 1, 'page submission stored an open dispute');

// --- cleanup ----------------------------------------------------------------------------------
$wpdb->query("DELETE FROM {$d} WHERE booking_id IN (" . implode(',', array_map('intval', [$bk, $pending, $paid_bk, $bk2])) . ")");
$wpdb->delete($p, ['id' => $payout]);
$wpdb->delete($p, ['id' => $payout2]);
$wpdb->query("DELETE FROM {$b} WHERE id IN (" . implode(',', array_map('intval', [$bk, $pending, $paid_bk, $bk2])) . ")");
wp_delete_user($u);
tap_t_finish();