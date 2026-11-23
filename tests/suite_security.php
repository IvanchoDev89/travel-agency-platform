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
// The agency panel was merged into the front-dash, so the source-level
// invariants below have to look at both halves.
$account  = $src('includes/class-shortcode-account.php');
$account .= $src('includes/class-front-dash.php');
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
// The catalog must translate the un-numbered placeholder, not a renumbered
// msgid (phpcbf once rewrote it and desynchronised every locale).
$L = 'TAP_Localization';
$locale_before = get_locale();
$L::set_lang('en');
$translated = __('Destacado %d mes(es)', 'travel-agency-platform');
$L::set_lang('es');
tap_t_assert(false !== strpos($translated, '%d'), 'promotion translation keeps an unnumbered %d placeholder');
tap_t_assert(false === strpos($translated, '%1$d'), 'promotion translation is not placeholder-renumbered');
if ('es_ES' === $locale_before) { $L::set_lang('es'); }

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

// ===== I. Referral security hardening ======================================
$ref_src = $src('includes/class-customer-referrals.php');
tap_t_assert(
    false !== strpos($ref_src, "httponly' => true"),
    'referral cookie is HttpOnly'
);
tap_t_assert(
    false !== strpos($ref_src, "'samesite' => 'Lax'"),
    'referral cookie uses SameSite'
);
tap_t_assert(
    false !== strpos($ref_src, 'tap_ref_rl_'),
    'referral click tracking is rate limited'
);
tap_t_assert(
    false !== strpos($ref_src, 'Anti-self-referral (guest checkout'),
    'guest self-referral is blocked'
);

// ===== J. Security headers ==================================================
$sec_src = $src('includes/class-security-headers.php');
tap_t_assert(
    false !== strpos($sec_src, "X-Content-Type-Options: nosniff"),
    'nosniff header is sent'
);
tap_t_assert(
    false !== strpos($sec_src, "X-Frame-Options: SAMEORIGIN"),
    'frame-options header is sent'
);
tap_t_assert(
    false !== strpos($sec_src, "Strict-Transport-Security"),
    'HSTS is configured (HTTPS only)'
);

// ===== K. Booking rate limit ===============================================
$ajax_src = $src('includes/class-ajax.php');
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$bk_key = TAP_Rate_Limit::key('booking_create');
delete_transient($bk_key);
$bk_hits = 0;
for ($i = 0; $i < 3; $i++) {
    if (!TAP_Rate_Limit::too_many('booking_create', 3, MINUTE_IN_SECONDS)) { $bk_hits++; }
}
tap_t_assert(3 === $bk_hits, 'booking_create allows exactly the limit');
tap_t_assert(true === TAP_Rate_Limit::too_many('booking_create', 3, MINUTE_IN_SECONDS), 'booking_create blocks past the limit');
delete_transient($bk_key);
unset($_SERVER['REMOTE_ADDR']);

// ===== L. Referral idempotency =============================================
$installer_src = (string) file_get_contents($plugin . '../travel-agency-platform/includes/class-installer.php');
// fallback path
if (!file_exists($plugin . '../travel-agency-platform/includes/class-installer.php')) {
    $installer_src = (string) file_get_contents('/home/marcelo/Local Sites/travel-agency/app/public/wp-content/plugins/travel-agency-platform/includes/class-installer.php');
}
global $wpdb;
$ref_table = $wpdb->prefix . 'tap_customer_referrals';
$ref_indexes = $wpdb->get_col($wpdb->prepare(
    "SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s",
    $ref_table
));
tap_t_assert(in_array('booking_unique', array_map('strtolower', $ref_indexes), true), 'referral table has a real booking_unique index');

// ===== M. Login brute-force guard ==========================================
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$lg_user = 'suite_login_' . wp_generate_password(6, false);
delete_transient(TAP_Rate_Limit::key('login_user', strtolower($lg_user)));
delete_transient(TAP_Rate_Limit::key('login_ip'));
tap_t_assert(!TAP_Login_Guard::is_blocked($lg_user), 'login guard starts unlocked');
for ($lg_i = 0; $lg_i < 5; $lg_i++) {
    TAP_Login_Guard::on_failed($lg_user);
}
tap_t_assert(TAP_Login_Guard::is_blocked($lg_user), 'login guard blocks after 5 failed attempts');
TAP_Login_Guard::on_success($lg_user);
tap_t_assert(!TAP_Login_Guard::is_blocked($lg_user), 'successful login clears the counters');
delete_transient(TAP_Rate_Limit::key('login_user', strtolower($lg_user)));
delete_transient(TAP_Rate_Limit::key('login_ip'));
unset($_SERVER['REMOTE_ADDR']);

// ===== N. CSP defaults cover shipped assets ================================
$policy = TAP_Security_Headers::default_policy();
foreach (['manifest-src', 'worker-src', "font-src 'self'"] as $needle) {
    tap_t_assert(false !== strpos($policy, $needle), 'CSP default policy allows ' . $needle);
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
$csp_key = TAP_Rate_Limit::key('csp_report');
delete_transient($csp_key);
$csp_hits = 0;
for ($i = 0; $i < 2; $i++) {
    if (!TAP_Rate_Limit::too_many('csp_report', 2, MINUTE_IN_SECONDS)) { $csp_hits++; }
}
tap_t_assert(2 === $csp_hits, 'csp_report allows exactly the limit');
tap_t_assert(true === TAP_Rate_Limit::too_many('csp_report', 2, MINUTE_IN_SECONDS), 'csp_report blocks past the limit');
delete_transient($csp_key);
unset($_SERVER['REMOTE_ADDR']);

// ===== O. iCal SSRF guard ==================================================
tap_t_assert(false === TAP_ICal_Import::is_safe_url('http://127.0.0.1/feed.ics'), 'iCal rejects loopback IPv4');
tap_t_assert(false === TAP_ICal_Import::is_safe_url('http://10.0.0.5/feed.ics'), 'iCal rejects private IPv4');
tap_t_assert(false === TAP_ICal_Import::is_safe_url('http://localhost/feed.ics'), 'iCal rejects localhost');
tap_t_assert(false === TAP_ICal_Import::is_safe_url('ftp://8.8.8.8/feed.ics'), 'iCal only allows http(s)');
tap_t_assert(true === TAP_ICal_Import::is_safe_url('http://8.8.8.8/feed.ics'), 'iCal allows a public IP');

// ===== P. Referral anti-fraud ==============================================
tap_t_assert(method_exists('TAP_Customer_Referrals', 'approve_pending'), 'high-point payouts can be approved manually');
tap_t_assert(method_exists('TAP_Customer_Referrals', 'reject_pending'), 'high-point payouts can be rejected manually');
tap_t_assert(false !== strpos($ref_src, "'pending_review'"), 'referrals support a pending-review state');
tap_t_assert(false !== strpos($ref_src, 'is_suspicious'), 'referrals are flagged when the fingerprint repeats');

// ===== Q. Anti-spam guards (chat / Q&A / itinerary) ========================
$ajax_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax.php');
tap_t_assert(
    false !== strpos($ajax_src, "too_many('chat_client'") && false !== strpos($ajax_src, "chat_client_send"),
    'client chat sending is rate limited'
);
tap_t_assert(
    false === strpos(substr($ajax_src, strpos($ajax_src, 'chat_client_thread')), "too_many('chat_client'"),
    'client chat read-poll is NOT rate limited (15s polling)'
);
$public_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-public.php');
tap_t_assert(false !== strpos($public_src, "too_many('qa_submit'"), 'Q&A submits are rate limited');
tap_t_assert(false !== strpos($public_src, "'is_approved'  => 0"), 'Q&A answers start unapproved');
tap_t_assert(false !== strpos($public_src, "\$_POST['tap_hp']"), 'Q&A checks the honeypot');
$collab_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-itinerary-collab.php');
tap_t_assert(false !== strpos($collab_src, "\$params['tap_hp']"), 'itinerary comments check the honeypot');

// ===== R. Security fixes: XSS, SSRF, IDOR, privacy ==========================
$seo_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-seo.php');
tap_t_assert(false !== strpos($seo_src, 'JSON_HEX_TAG'), 'JSON-LD escapes < to block </script> injection');
tap_t_assert(false === strpos($seo_src, 'JSON_UNESCAPED_SLASHES'), 'JSON-LD no longer leaves raw slashes');
$spots_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-destination-spots.php');
tap_t_assert(false !== strpos($spots_src, "wp_kses_post((string) get_post_meta(\$post->ID, '_tap_spot_photo_credit'"), 'map photo credit is sanitized but keeps links');
$emails_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-emails.php');
tap_t_assert(false !== strpos($emails_src, 'to_hash=') && false === strpos($emails_src, "'TAP email failed: to=%s"), 'mail failures log hashes, not PII');
$push_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-push.php');
tap_t_assert(false !== strpos($push_src, 'safe_endpoint(') && false !== strpos($push_src, "TAP_ICal_Import::is_safe_url"), 'push endpoints validated at subscribe and send');
$trans_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-transport.php');
tap_t_assert(false !== strpos($trans_src, "owns_booking") && false !== strpos($trans_src, "'passengers' => \$passengers"), 'transport live checks trip ownership');
$ical_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ical.php');
tap_t_assert(false === strpos($ical_src, "'UID:booking-'"), 'public iCal feed does not leak booking codes');
$dash_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-dash.php');
tap_t_assert(2 === substr_count($dash_src, 'caller_is_agency_member'), 'client-chat dash endpoints reject agency members');
tap_t_assert(method_exists('TAP_Ajax', 'caller_is_agency_member'), 'agency-member helper is publicly reusable');

// ===== S. WordPress hardening ==============================================
tap_t_assert(method_exists('TAP_Site_Hardening', 'block_user_discovery'), 'site hardening class loaded');
tap_t_assert(false !== has_filter('xmlrpc_enabled', '__return_false'), 'XML-RPC is disabled');
tap_t_assert(false !== has_filter('rest_pre_dispatch', ['TAP_Site_Hardening', 'block_user_discovery']), 'user enumeration filter hooked');
$anon_req = new WP_REST_Request('GET', '/wp/v2/users');
tap_t_assert(
    is_wp_error(TAP_Site_Hardening::block_user_discovery(null, rest_get_server(), $anon_req)),
    'anonymous /wp/v2/users is blocked'
);
wp_set_current_user(1);
$admin_req = new WP_REST_Request('GET', '/wp/v2/users');
tap_t_assert(
    !is_wp_error(TAP_Site_Hardening::block_user_discovery(null, rest_get_server(), $admin_req)),
    'admin /wp/v2/users still allowed'
);
tap_t_assert(
    !is_wp_error(TAP_Site_Hardening::block_user_discovery(null, rest_get_server(), new WP_REST_Request('GET', '/tap/v1/search'))),
    'public tap/v1 REST is not affected'
);
wp_set_current_user(0);
$sec_headers_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-security-headers.php');
tap_t_assert(false === strpos($sec_headers_src, 'cdn.jsdelivr.net'), 'unused jsdelivr CSP source removed');
$wp_cfg = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-config.php');
tap_t_assert(false !== strpos($wp_cfg, "'local' !== WP_ENVIRONMENT_TYPE"), 'production constants are env-guarded');
tap_t_assert(false === strpos($wp_cfg, 'define( \'FORCE_SSL_ADMIN\', true );') || false !== strpos($wp_cfg, "'local' !== WP_ENVIRONMENT_TYPE"), 'SSL admin does not break local HTTP');

// ===== T. Public nonce acceptance (real wp_create_nonce round-trip) ========
$uid_before = get_current_user_id();
wp_set_current_user(1);
$n_front = wp_create_nonce('tap_front_dash_nonce');
$n_plain = wp_create_nonce('tap_nonce');
tap_t_assert(TAP_Ajax_Public::public_nonce_ok($n_front), 'dashboard nonce accepted by public handlers');
tap_t_assert(TAP_Ajax_Public::public_nonce_ok($n_plain), 'public tap_nonce accepted by public handlers');
tap_t_assert(!TAP_Ajax_Public::public_nonce_ok(wp_create_nonce('tap_other_action')), 'unrelated nonce rejected');
tap_t_assert(!TAP_Ajax_Public::public_nonce_ok(''), 'empty nonce rejected');
// A nonce from another user/session must not verify for this user.
wp_set_current_user(0);
$anon_nonce = wp_create_nonce('tap_nonce');
wp_set_current_user(1);
tap_t_assert(!TAP_Ajax_Public::public_nonce_ok($anon_nonce), 'anonymous nonce cannot authenticate a logged-in user');
wp_set_current_user($uid_before);

// ===== U. PHP security-fixed versions (CVE-2026-109618/109619) ===============
tap_t_assert(true === TAP_Requirements::is_php_patched('8.2.35'), 'PHP 8.2.35 is patched');
tap_t_assert(false === TAP_Requirements::is_php_patched('8.2.34'), 'PHP 8.2.34 is rejected');
tap_t_assert(false === TAP_Requirements::is_php_patched('8.2.27'), 'the vulnerable local PHP is rejected');
tap_t_assert(true === TAP_Requirements::is_php_patched('8.3.36'), 'PHP 8.3.36 is patched');
tap_t_assert(false === TAP_Requirements::is_php_patched('8.3.30'), 'PHP 8.3.30 is rejected even though it is above 8.2.35');
tap_t_assert(true === TAP_Requirements::is_php_patched('8.4.27'), 'PHP 8.4.27 is patched');
tap_t_assert(false === TAP_Requirements::is_php_patched('8.4.4'), 'PHP 8.4.4 is rejected');
tap_t_assert(true === TAP_Requirements::is_php_patched('8.5.12'), 'PHP 8.5.12 is patched');
tap_t_assert(false === TAP_Requirements::is_php_patched('8.5.3'), 'PHP 8.5.3 is rejected');
tap_t_assert('8.2.35' === TAP_Requirements::patched_for('8.2.27'), 'patched_for reports the fix for the 8.2 branch');
tap_t_assert(true === TAP_Requirements::is_php_patched(), 'the running PHP is patched');

tap_t_finish();
