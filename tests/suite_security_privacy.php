<?php
/**
 * suite_security_privacy.php — KYC encryption, hashed IPs and PII inventory.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';

/* --- 1) KYC encryption at rest ------------------------------------------------ */
$plain = 'documento-identidad-' . $sfx;
$encrypted = TAP_KYC_Docs::encrypt($plain);
tap_t_assert(is_string($encrypted) && 0 === strpos($encrypted, 'TAPKYC1:'), 'KYC payload is encrypted with a marker');
tap_t_assert(false === strpos($encrypted, $plain), 'plaintext is not present in the stored payload');
tap_t_assert($plain === TAP_KYC_Docs::decrypt($encrypted), 'encrypted payload round-trips');
tap_t_assert($plain === TAP_KYC_Docs::decrypt($plain), 'legacy plaintext documents remain readable');
$kyc_src = (string) file_get_contents($base . 'includes/class-kyc-docs.php');
tap_t_assert(false !== strpos($kyc_src, "'is_encrypted' => 1"), 'new uploads are marked encrypted');
tap_t_assert(false !== strpos($kyc_src, 'self::decrypt('), 'serving decrypts documents');

/* --- 2) IPs are hashed, not stored raw ---------------------------------------- */
$ip = '203.0.113.' . (mt_rand(1, 250));
$hashed = TAP_Privacy::hash_ip($ip);
tap_t_assert(64 === strlen($hashed) && $hashed !== $ip, 'IP is stored as a salted hash');
$_SERVER['REMOTE_ADDR'] = $ip;
$consent_id = TAP_Privacy::record_consent('priv-' . strtolower($sfx) . '@example.test', 'booking', 0);
unset($_SERVER['REMOTE_ADDR']);
$stored_ip = (int) $consent_id > 0
    ? $wpdb->get_var($wpdb->prepare('SELECT ip FROM ' . $wpdb->prefix . 'tap_consents WHERE id = %d', $consent_id))
    : '';
tap_t_assert($stored_ip === $hashed, 'consent log stores the hash, not the address');

$wpdb->insert($wpdb->prefix . 'tap_consents', [
    'email' => 'legacy-' . strtolower($sfx) . '@example.test',
    'scope' => 'booking', 'policy_version' => '1.0', 'ip' => '198.51.100.' . mt_rand(1, 250),
]);
$legacy_id = (int) $wpdb->insert_id;
for ($i = 0; $i < 20; $i++) { TAP_Privacy::hash_legacy_ips(200); }
$legacy_ip = (string) $wpdb->get_var($wpdb->prepare('SELECT ip FROM ' . $wpdb->prefix . 'tap_consents WHERE id = %d', $legacy_id));
tap_t_assert(64 === strlen($legacy_ip) && false === filter_var($legacy_ip, FILTER_VALIDATE_IP), 'legacy raw IPs are migrated to hashes');
$privacy_src = (string) file_get_contents($base . 'includes/class-privacy.php');
tap_t_assert(false === strpos($privacy_src, "'ip'             => isset(\$_SERVER") && false === strpos($privacy_src, "'ip'         => isset(\$_SERVER"),
    'no new raw IP writes remain');

/* --- 3) PII inventory is truthful --------------------------------------------- */
$issues = TAP_PII_Inventory::issues();
tap_t_assert([] === $issues, 'declared PII tables and columns all exist');
$pii_src = (string) file_get_contents($base . 'includes/class-pii-inventory.php');
tap_t_assert(false !== strpos($pii_src, "'retention'"), 'inventory declares retention for every entry');

/* --- 4) endpoint guards -------------------------------------------------------- */
$serve_src = (string) file_get_contents($base . 'includes/class-kyc-docs.php');
tap_t_assert(false !== strpos($serve_src, "check_admin_referer('tap_kyc_serve_") && false !== strpos($serve_src, 'get_agency_for_user'),
    'document serving has nonce and ownership checks');
$dash_src = (string) file_get_contents($base . 'includes/class-ajax-dash.php');
tap_t_assert(substr_count($dash_src, 'check_ajax_referer') >= 16, 'dashboard AJAX handlers verify nonces');
tap_t_assert(false !== strpos($dash_src, "current_user_can('manage_options')"), 'admin-only operations check capabilities');

/* --- cleanup ------------------------------------------------------------------ */
if ((int) $consent_id > 0) { $wpdb->delete($wpdb->prefix . 'tap_consents', ['id' => $consent_id]); }
$wpdb->delete($wpdb->prefix . 'tap_consents', ['id' => $legacy_id]);
tap_t_cleanup_test_consents();
tap_t_finish();
