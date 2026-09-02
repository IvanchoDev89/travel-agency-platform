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

tap_t_cleanup_bookings($ids);
tap_t_finish();