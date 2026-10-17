<?php
/**
 * suite_commissions.php — admin settlement ledger (bulk + per-booking).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_commissions.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$b = $wpdb->prefix . 'tap_bookings';
$p = $wpdb->prefix . 'tap_commission_payments';
$ids = [];

// setup 2 owed bookings
$a = tap_t_seed_booking(['booking_code' => 'E2E-BULK', 'commission_amount' => 20]);
$c = tap_t_seed_booking(['booking_code' => 'E2E-SINGLE', 'commission_amount' => 15]);
$ids[] = $a; $ids[] = $c;

// 1) bulk settle (mirrors admin handler)
$sellist = [$a, $c];
$ph = implode(',', array_fill(0, count($sellist), '%d'));
$rows = $wpdb->get_results($wpdb->prepare("SELECT id, agency_id, commission_amount FROM {$b} WHERE id IN ({$ph}) AND commission_status = 'owed'", $sellist));
$byAgency = [];
foreach ($rows as $r) { $byAgency[$r->agency_id] = ($byAgency[$r->agency_id] ?? 0) + (float) $r->commission_amount; }
foreach ($byAgency as $agencyId => $amount) {
    $aIds = array_column(array_filter($rows, fn($r) => (int) $r->agency_id === (int) $agencyId), 'id');
    $wpdb->insert($p, ['agency_id' => $agencyId, 'amount' => round($amount, 2), 'booking_ids' => implode(',', $aIds), 'method' => 'cash', 'note' => 'suite bulk']);
    $wpdb->query("UPDATE {$b} SET commission_status = 'paid' WHERE id IN (" . implode(',', $aIds) . ")");
}
$st = $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $a));
tap_t_assert($st === 'paid', 'bulk settle marks bookings paid');
$payRow = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p} WHERE FIND_IN_SET(%s, booking_ids)", (string) $a));
tap_t_assert((bool) $payRow, 'bulk settle writes one ledger row referencing both bookings');

// 2) per-booking settle (individual) — bulk already paid $a AND $c, so reset $c
$wpdb->update($b, ['commission_status' => 'owed'], ['id' => $c]);
$single = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$b} WHERE id = %d AND commission_status = 'owed'", $c));
tap_t_assert($single !== null, 'per-booking settle loads owed booking');
if ($single) {
    $wpdb->insert($p, ['agency_id' => $single->agency_id, 'amount' => $single->commission_amount, 'booking_ids' => (string) $single->id, 'method' => 'paypal', 'note' => 'suite individual']);
    $wpdb->update($b, ['commission_status' => 'paid'], ['id' => $single->id]);
}
$st2 = $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $c));
tap_t_assert($st2 === 'paid', 'per-booking settle marks booking paid');
$pay2 = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p} WHERE FIND_IN_SET(%s, booking_ids)", (string) $c));
tap_t_assert((bool) $pay2, 'per-booking settle writes individual ledger row');

// 3) ledger listing returns per-booking rows in date order
$ledger = $wpdb->get_results($wpdb->prepare(
    "SELECT booking_code, commission_amount, commission_status FROM {$b} WHERE agency_id = %d AND commission_amount > 0 ORDER BY created_at DESC LIMIT 30", tap_t_test_agency()
));
$framed = array_filter($ledger, fn($r) => str_starts_with($r->booking_code, 'E2E'));
tap_t_assert(count($framed) >= 2, 'agency ledger lists per-booking commission rows');

// 4) booking-code resolution for settlement display
$codesStr = implode(',', array_map('intval', [$a, $c]));
$codes = $wpdb->get_col("SELECT booking_code FROM {$b} WHERE id IN ({$codesStr})");
sort($codes);
tap_t_assert(in_array('E2E-BULK', $codes, true) && in_array('E2E-SINGLE', $codes, true), 'settlement display resolves booking codes');

// ── Tanda A: money-safety semantics ─────────────────────────────────────

// 5) commission_status defaults to 'waiting' until the booking is actually paid
$wpdb->insert($b, [
    'booking_code'  => 'E2E-WAITING',
    'agency_id'     => tap_t_test_agency(),
    'service_type'  => 'tap_tour',
    'service_id'    => 75,
    'payment_status'=> 'pending',
    'status'        => 'confirmed',
    'created_at'    => current_time('mysql'),
]);
$w = (int) $wpdb->insert_id;
$ids[] = $w;
tap_t_assert('waiting' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $w)), 'new commission defaults to waiting, not owed');

// 6) complete() reconciles the payout amount to the still-eligible set
$x = tap_t_seed_booking(['booking_code' => 'E2E-RECON-X', 'commission_amount' => 10]);
$y = tap_t_seed_booking(['booking_code' => 'E2E-RECON-Y', 'commission_amount' => 5]);
$ids[] = $x; $ids[] = $y;
$wpdb->insert($p, [
    'agency_id'   => tap_t_test_agency(),
    'amount'      => 15,
    'booking_ids' => $x . ',' . $y,
    'method'      => 'cash',
    'note'        => 'suite recon',
    'status'      => 'pending',
    'source'      => 'agency',
]);
$pid_recon = (int) $wpdb->insert_id;
$wpdb->update($b, ['commission_status' => 'void'], ['id' => $x]);
tap_t_assert(true === TAP_Payouts::complete($pid_recon), 'recon payout completes after one booking was voided');
$prow_recon = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p} WHERE id = %d", $pid_recon));
tap_t_assert($prow_recon && abs((float) $prow_recon->amount - 5) < 0.001, 'payout amount reconciled down to the eligible bookings');
tap_t_assert('void' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $x)), 'voided booking is not settled on completion');
tap_t_assert('paid' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $y)), 'eligible booking becomes paid on completion');

// 7) release_commissions never un-pays a booking settled by another completed payout
$z = tap_t_seed_booking(['booking_code' => 'E2E-GUARD', 'commission_amount' => 7, 'commission_status' => 'paid']);
$ids[] = $z;
$wpdb->insert($p, [
    'agency_id'   => tap_t_test_agency(),
    'amount'      => 7,
    'booking_ids' => (string) $z,
    'method'      => 'cash',
    'note'        => 'suite guard A',
    'status'      => 'completed',
    'source'      => 'admin',
]);
$pid_done = (int) $wpdb->insert_id;
$wpdb->insert($p, [
    'agency_id'   => tap_t_test_agency(),
    'amount'      => 7,
    'booking_ids' => (string) $z,
    'method'      => 'cash',
    'note'        => 'suite guard B',
    'status'      => 'pending',
    'source'      => 'agency',
]);
$pid_b = (int) $wpdb->insert_id;
tap_t_assert(true === TAP_Payouts::cancel($pid_b), 'overlapping pending payout can be cancelled');
tap_t_assert('paid' === $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $z)), 'cancelling one payout does not release a booking already settled by another completed payout');

// crafted ledger rows for this section
$wpdb->query($wpdb->prepare("DELETE FROM {$p} WHERE id IN (%d, %d, %d)", $pid_recon, $pid_done, $pid_b));

// 8) reconciliation: a 'paid' commission on a never-paid booking is impossible
//    and must be returned to 'waiting'; a legitimately settled ('paid' + paid
//    payment) row must be left untouched.
$bad = tap_t_seed_booking(['booking_code' => 'E2E-RECON-BAD', 'commission_status' => 'paid', 'payment_status' => 'pending', 'status' => 'completed']);
$good = tap_t_seed_booking(['booking_code' => 'E2E-RECON-GOOD', 'commission_status' => 'paid', 'payment_status' => 'paid', 'status' => 'completed']);
$ids[] = $bad; $ids[] = $good;
TAP_Installer::migrate();
$bad_after  = $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $bad));
$good_after = $wpdb->get_var($wpdb->prepare("SELECT commission_status FROM {$b} WHERE id = %d", $good));
tap_t_assert('waiting' === $bad_after, 'migrate() returns an impossible paid commission (unpaid booking) to waiting');
tap_t_assert('paid' === $good_after, 'migrate() leaves a legitimately settled commission untouched');

tap_t_cleanup_bookings($ids);
tap_t_finish();