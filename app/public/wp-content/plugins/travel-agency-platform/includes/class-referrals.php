<?php
defined('ABSPATH') || exit;

/**
 * Referral program for agencies.
 *
 * Each agency gets a unique referral code. When a new agency registers using
 * that code, the referrer earns a commission (% of the referred agency's
 * commission) for N months.
 */
class TAP_Referrals {

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_track_click'], 5);
        add_action('tap_agency_registered', [__CLASS__, 'on_agency_registered'], 10, 2);
    }

    /** Track a click on a referral link (stores code in cookie). */
    public static function maybe_track_click() {
        if (!empty($_GET['ref'])) {
            $code = sanitize_text_field(wp_unslash($_GET['ref']));
            if ($code) {
                setcookie('tap_referral_code', $code, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
                self::track_click($code);
            }
        }
    }

    /** When an agency registers, link it to the referrer if a code is present. */
    public static function on_agency_registered($user_id, $agency_id) {
        $code = '';
        if (!empty($_COOKIE['tap_referral_code'])) {
            $code = sanitize_text_field(wp_unslash($_COOKIE['tap_referral_code']));
        }
        if (!$code && !empty($_POST['referral_code'])) {
            $code = sanitize_text_field(wp_unslash($_POST['referral_code']));
        }
        if (!$code) return;
        $referrer = self::agency_by_code($code);
        if ($referrer && $referrer !== (int) $agency_id) {
            self::register_referral($referrer, (int) $agency_id);
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_referrals';
    }

    /** Generate or retrieve the referral code for an agency. */
    public static function code_for_agency($agency_id) {
        $agency_id = (int) $agency_id;
        $code = (string) get_post_meta($agency_id, '_tap_agency_referral_code', true);
        if ($code) return $code;
        $code = strtoupper(substr(md5($agency_id . wp_generate_password(8, false)), 0, 8));
        update_post_meta($agency_id, '_tap_agency_referral_code', $code);
        return $code;
    }

    /** URL to share. */
    public static function share_url($agency_id) {
        return home_url('/register/?ref=' . self::code_for_agency($agency_id));
    }

    /** Track a click on a referral link. */
    public static function track_click($code) {
        global $wpdb;
        $code = sanitize_text_field($code);
        if (!$code) return;
        $t = self::table();
        $wpdb->insert($t, [
            'referrer_agency_id' => self::agency_by_code($code),
            'referral_code'      => $code,
            'status'             => 'clicked',
            'expires_at'         => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
    }

    /** Find agency by referral code. */
    public static function agency_by_code($code) {
        global $wpdb;
        $code = sanitize_text_field($code);
        // First check agencies with stored code.
        $posts = get_posts([
            'post_type'   => 'tap_agency',
            'post_status' => 'any',
            'meta_key'    => '_tap_agency_referral_code',
            'meta_value'  => $code,
            'fields'      => 'ids',
            'numberposts' => 1,
        ]);
        if ($posts) return (int) $posts[0];
        // Fallback: generate code for all agencies and match.
        $agencies = get_posts(['post_type'=>'tap_agency','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
        foreach ($agencies as $aid) {
            if (self::code_for_agency((int)$aid) === $code) {
                return (int) $aid;
            }
        }
        return 0;
    }

    /** Register a referral when a new agency signs up with a code. */
    public static function register_referral($referrer_agency_id, $referred_agency_id) {
        global $wpdb;
        $referrer_agency_id = (int) $referrer_agency_id;
        $referred_agency_id = (int) $referred_agency_id;
        if (!$referrer_agency_id || !$referred_agency_id || $referrer_agency_id === $referred_agency_id) return 0;
        $t = self::table();
        $wpdb->insert($t, [
            'referrer_agency_id' => $referrer_agency_id,
            'referred_agency_id' => $referred_agency_id,
            'referral_code'      => self::code_for_agency($referrer_agency_id),
            'status'             => 'registered',
            'commission_rate'    => 10.00,
            'commission_months'  => 12,
            'expires_at'         => date('Y-m-d H:i:s', strtotime('+12 months')),
            'converted_at'       => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** List referrals for an agency. */
    public static function for_agency($agency_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE referrer_agency_id = %d ORDER BY created_at DESC",
            (int) $agency_id
        ));
    }

    /** Stats for an agency's referral program. */
    public static function stats($agency_id) {
        global $wpdb;
        $t = self::table();
        $agency_id = (int) $agency_id;
        $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE referrer_agency_id = %d AND status = 'clicked'", $agency_id));
        $regs   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE referrer_agency_id = %d AND status IN ('registered','converted')", $agency_id));
        $conv   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE referrer_agency_id = %d AND status = 'converted'", $agency_id));
        return ['clicks' => $clicks, 'registrations' => $regs, 'conversions' => $conv];
    }

    /** Convert referral to commission when referred agency makes first booking. */
    public static function convert($referred_agency_id) {
        global $wpdb;
        $t = self::table();
        $referred_agency_id = (int) $referred_agency_id;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE referred_agency_id = %d AND status = 'registered' LIMIT 1",
            $referred_agency_id
        ));
        if (!$row) return;
        $wpdb->update($t, ['status' => 'converted', 'converted_at' => current_time('mysql')], ['id' => (int) $row->id]);
    }
}
