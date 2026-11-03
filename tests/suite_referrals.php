<?php
/**
 * suite_referrals.php — referral program (code, discount, points, tiers).
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;

// Use user 1 as referrer.
$referrer_id = 1;
$code = TAP_Customer_Referrals::code_for_user($referrer_id);
tap_t_assert('' !== $code, 'referral code generated');

$resolved = TAP_Customer_Referrals::referrer_by_code($code);
tap_t_assert($resolved === $referrer_id, 'code resolves to referrer');

// Discount calculation.
$disc = TAP_Customer_Referrals::discount_for_total(100, $referrer_id);
tap_t_assert($disc > 0, 'discount computed');

// Tier for a new user is bronze.
tap_t_assert(TAP_Customer_Referrals::tier($referrer_id) === 'bronze', 'new referrer is bronze');

// Points rate within bounds.
$rate = TAP_Customer_Referrals::points_rate($referrer_id);
tap_t_assert($rate >= 0 && $rate <= 0.5, 'points rate in bounds');

// Table exists.
$t = TAP_Customer_Referrals::table();
tap_t_assert($wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t, 'customer referrals table exists');

// Insert a rewarded row and check count.
$wpdb->insert($t, [
    'referrer_user_id' => $referrer_id,
    'referred_user_id' => 2,
    'referral_code' => $code,
    'booking_id' => 999999,
    'status' => 'rewarded',
    'points_awarded' => 10,
]);
$before = TAP_Customer_Referrals::rewarded_count($referrer_id);
tap_t_assert($before >= 1, 'rewarded_count increments');

// Cleanup.
$wpdb->delete($t, ['booking_id' => 999999], ['%d']);

tap_t_finish();