<?php
/**
 * suite_coupons.php — money-affecting coupon and discount-tier guards.
 * FUNCTIONAL: real CRUD, validation and discount math.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_coupons.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$ids = [];
$table = TAP_Coupons::table();

/* --- 1) percent coupon: lifecycle + max uses --------------------------------- */
$cid = TAP_Coupons::create([
    'code' => 'tap' . $sfx, 'discount_type' => 'percent', 'discount_value' => 10,
    'max_uses' => 2, 'is_active' => 1,
]);
$ids[] = $cid;
tap_t_assert($cid > 0, 'percent coupon created');
$row = TAP_Coupons::get($cid);
tap_t_assert($row && 'TAP' . strtoupper($sfx) === $row->code, 'coupon code stored uppercase');

$valid = TAP_Coupons::validate('tap' . $sfx, 200);
tap_t_assert($valid && (int) $valid->id === $cid, 'lowercase code validates case-insensitively');
tap_t_assert(abs(20.0 - TAP_Coupons::discount_for($valid, 200)) < 0.01, '10% coupon discounts 200 -> 20');

TAP_Coupons::mark_used('tap' . $sfx);
tap_t_assert(1 === (int) TAP_Coupons::get($cid)->used_count, 'mark_used increments the counter');
tap_t_assert(null !== TAP_Coupons::validate('tap' . $sfx, 200), 'coupon still valid after first use');
TAP_Coupons::mark_used('tap' . $sfx);
tap_t_assert(null === TAP_Coupons::validate('tap' . $sfx, 200), 'coupon exhausted at max uses');

/* --- 2) fixed coupon caps at the total ---------------------------------------- */
$cid2 = TAP_Coupons::create([
    'code' => 'FIX' . $sfx, 'discount_type' => 'fixed', 'discount_value' => 50, 'is_active' => 1,
]);
$ids[] = $cid2;
$fixed = TAP_Coupons::validate('FIX' . $sfx, 30);
tap_t_assert($fixed && abs(30.0 - TAP_Coupons::discount_for($fixed, 30)) < 0.01, 'fixed coupon never exceeds the total');
tap_t_assert(abs(50.0 - TAP_Coupons::discount_for($fixed, 200)) < 0.01, 'fixed coupon applies full value when below total');

/* --- 3) gates: inactive, expired, min amount, nonsense percent ---------------- */
$cid3 = TAP_Coupons::create([
    'code' => 'INACT' . $sfx, 'discount_type' => 'percent', 'discount_value' => 10, 'is_active' => 0,
]);
$ids[] = $cid3;
tap_t_assert(null === TAP_Coupons::validate('INACT' . $sfx, 100), 'inactive coupon rejected');

$cid4 = TAP_Coupons::create([
    'code' => 'EXP' . $sfx, 'discount_type' => 'percent', 'discount_value' => 10,
    'is_active' => 1, 'expires_at' => gmdate('Y-m-d', strtotime('-1 day')),
]);
$ids[] = $cid4;
tap_t_assert(null === TAP_Coupons::validate('EXP' . $sfx, 100), 'expired coupon rejected');

$cid5 = TAP_Coupons::create([
    'code' => 'MIN' . $sfx, 'discount_type' => 'percent', 'discount_value' => 10,
    'is_active' => 1, 'min_amount' => 500,
]);
$ids[] = $cid5;
tap_t_assert(null === TAP_Coupons::validate('MIN' . $sfx, 100), 'coupon below min amount rejected');
tap_t_assert(null !== TAP_Coupons::validate('MIN' . $sfx, 600), 'coupon above min amount accepted');

$crazy = (object) ['discount_type' => 'percent', 'discount_value' => 250];
tap_t_assert(abs(100.0 - TAP_Coupons::discount_for($crazy, 100)) < 0.01, 'percent discount capped at 100%');

/* --- 4) discount tiers CSV round-trip ---------------------------------------- */
$csv = TAP_Discounts::tiers_to_csv([['nights' => 14, 'percent' => 15], ['nights' => 7, 'percent' => 10]]);
tap_t_assert('14:15, 7:10' === $csv, 'tiers_to_csv formats pairs in order');
$parse = new ReflectionMethod('TAP_Discounts', 'parse_tiers_csv');
$parse->setAccessible(true);
$parsed = $parse->invoke(null, '14:15, basura, 7:10, 0:5, 3:-2');
tap_t_assert(2 === count($parsed), 'malformed tier entries ignored');
tap_t_assert(7 === $parsed[0]['nights'] && 10.0 === (float) $parsed[0]['percent'], 'valid tier parsed and sorted');

/* --- cleanup ----------------------------------------------------------------- */
foreach ($ids as $id) { TAP_Coupons::delete($id); }
tap_t_finish();
