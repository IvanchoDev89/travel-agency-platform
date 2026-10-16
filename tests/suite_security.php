<?php
/**
 * Security invariants — escaping, nonces and hardened SQL.
 *
 * These are the guarantees that static review established during the v1.5.14
 * quality pass. They are asserted at the source level so a refactor that
 * quietly drops one of them fails the battery instead of shipping.
 *
 * Site-agnostic: reads source files and calls pure helpers only, leaves no
 * data behind.
 */

require_once __DIR__ . '/bootstrap.php';

$plugin = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins/travel-agency-platform/');
$src = static function ($rel) use ($plugin) {
    return (string) file_get_contents($plugin . $rel);
};

// ===== A. Canonical agency ownership (D4) ==================================
// Six independent derivations of the agency meta key used to drift; they now
// go through TAP_Post_Types, which is what keeps equipment listings counting
// towards plan limits instead of silently escaping the check.
tap_t_assert(
    '_tap_acc_agency_id' === TAP_Post_Types::agency_meta_key('tap_accommodation'),
    'agency_meta_key derives the accommodation key'
);
tap_t_assert(
    '_tap_tour_agency_id' === TAP_Post_Types::agency_meta_key('tap_tour'),
    'agency_meta_key derives the tour key'
);
tap_t_assert(
    '' === TAP_Post_Types::agency_meta_key('unknown_type'),
    'agency_meta_key returns empty for an unknown type'
);

// ===== B. Cancellation hints resolve every policy (D13) ====================
// The listing wizard used to look up the non-existent key "nonrefund" and
// return a blank hint for "strict", leaving the agency without a refund rule.
$strict_hint = TAP_Acc_Wizard_Steps::acc_cancellation_hint('strict');
tap_t_assert('' !== $strict_hint, 'strict policy has a hint (was blank before D13)');
tap_t_assert(false !== mb_strpos($strict_hint, '50%'), 'strict hint states the refund percentage');
tap_t_assert('' !== TAP_Acc_Wizard_Steps::acc_cancellation_hint('non_refundable'), 'non_refundable key resolves (was "nonrefund")');
tap_t_assert('' === TAP_Acc_Wizard_Steps::acc_cancellation_hint('nonexistent'), 'unknown policy key falls back to empty');

// ===== C. Canonical nonce helpers exist ====================================
tap_t_assert(is_callable(['TAP_Dashboard', 'post_nonce_ok']), 'TAP_Dashboard::post_nonce_ok is the shared nonce verifier');
tap_t_assert(is_callable(['TAP_Dashboard', 'post_verified']), 'TAP_Dashboard::post_verified gates a POST trigger');

// ===== D. Hardened SQL: IDs interpolated into IN () are cast ===============
// Booking ids reach "IN ({$id_list})" from a database round trip; the cast
// keeps the invariant explicit if the query behind it ever changes.
$money = $src('includes/class-dashboard-money.php');
tap_t_assert(
    false !== strpos($money, "array_map('intval', \$agency_ids)"),
    'payout IN () list is built from intval-ed booking ids'
);

// ===== E. Attribute-context output is escaped ==============================
// Bare echoes into class=/value= attributes are the XSS-shaped half of the
// EscapeOutput report; the fixed sites must stay fixed.
// The agency panel was split out of this file in 1.5.17, so the source-level
// invariants below have to look at both halves.
$account  = $src('includes/class-shortcode-account.php');
$account .= $src('includes/class-shortcode-agency-panel.php');
tap_t_assert(
    false !== strpos($account, '<option value="<?php echo esc_attr($m); ?>">'),
    'plan duration option values are attribute-escaped'
);
$dash_content = $src('includes/class-dashboard-content.php');
tap_t_assert(
    false !== strpos($dash_content, 'class="nav-tab<?php echo esc_attr($class); ?>"'),
    'admin nav tab class is attribute-escaped'
);
$acc_wizard  = $src('includes/class-acc-wizard.php');
$acc_wizard .= $src('includes/class-acc-wizard-room-form.php');
$acc_wizard .= $src('includes/class-acc-wizard-steps.php');
tap_t_assert(
    false !== strpos($acc_wizard, 'class="tap-field<?php echo esc_attr($span); ?>"'),
    'wizard field class is attribute-escaped'
);
tap_t_assert(
    false !== strpos($acc_wizard, 'class="tap-wizard-step<?php echo esc_attr($cls); ?>"'),
    'wizard step class is attribute-escaped'
);

// ===== F. Msgids stay stable ===============================================
// phpcbf renumbered %d placeholders into %1$d, which rewrites the msgid and
// desynchronises every catalog. Placeholder order is a source-level decision.
$commerce = $src('includes/class-ajax-commerce.php');
tap_t_assert(
    false !== strpos($commerce, 'Destacado %d mes(es)'),
    'promotion msgid keeps positional-free placeholders'
);
tap_t_assert(
    false === strpos($commerce, 'Destacado %1$d mes(es)'),
    'promotion msgid is not placeholder-renumbered'
);

// ===== G. Refunds have a single route ======================================
// TAP_Booking used to carry seven delegating wrappers; both classes must not
// define the same entry point any more.
$booking_src  = $src('includes/class-booking.php');
$booking_src .= $src('includes/class-booking-pricing.php');
tap_t_assert(
    false === strpos($booking_src, 'return TAP_Refunds::'),
    'TAP_Booking no longer delegates to TAP_Refunds'
);
tap_t_assert(
    class_exists('TAP_Refunds'),
    'TAP_Refunds is the single home of refund logic'
);

// ===== H. Public rate limiter ==============================================
// One transient-based throttle backs every public endpoint; the boundary must
// reject the (limit + 1)-th request, and separate buckets must not bleed.
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$rl_bucket = 'suite_security_rl_' . wp_generate_password(6, false);
$rl_key    = TAP_Rate_Limit::key($rl_bucket);
delete_transient($rl_key);

$rl_passed = 0;
for ($rl_i = 0; $rl_i < 3; $rl_i++) {
    if (!TAP_Rate_Limit::too_many($rl_bucket, 3, MINUTE_IN_SECONDS)) {
        $rl_passed++;
    }
}
tap_t_assert(3 === $rl_passed, 'rate limiter allows exactly the limit');
tap_t_assert(
    true === TAP_Rate_Limit::too_many($rl_bucket, 3, MINUTE_IN_SECONDS),
    'rate limiter blocks the request past the limit'
);

$rl_other = 'suite_security_rl2_' . wp_generate_password(6, false);
delete_transient(TAP_Rate_Limit::key($rl_other));
tap_t_assert(
    false === TAP_Rate_Limit::too_many($rl_other, 1, MINUTE_IN_SECONDS),
    'separate buckets keep independent budgets'
);

delete_transient($rl_key);
delete_transient(TAP_Rate_Limit::key($rl_other));
unset($_SERVER['REMOTE_ADDR']);

tap_t_finish();
