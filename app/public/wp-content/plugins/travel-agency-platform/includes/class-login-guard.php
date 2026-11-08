<?php
defined('ABSPATH') || exit;

/**
 * Brute-force guard for wp-login.php.
 *
 * Counts failed attempts per username and per client IP in short-lived
 * transients and rejects further attempts once the threshold is crossed. A
 * successful login clears both counters, so a legitimate user who mistyped
 * their password is never locked out after recovering.
 */
class TAP_Login_Guard {

    /** Failed attempts for a single username before it is blocked. */
    const MAX_PER_USER = 5;

    /** Failed attempts from a single IP before it is blocked. */
    const MAX_PER_IP = 20;

    /** Window length in seconds (15 minutes). */
    const WINDOW = 900;

    public static function init() {
        add_action('wp_login_failed', [__CLASS__, 'on_failed'], 10, 2);
        add_action('wp_login', [__CLASS__, 'on_success'], 10, 2);
        add_filter('authenticate', [__CLASS__, 'block'], 99, 3);
    }

    /** Read-only counter for a bucket/id pair (TAP_Rate_Limit keys by IP too). */
    public static function count($bucket, $id = '') {
        $value = get_transient(TAP_Rate_Limit::key($bucket, $id));
        return false === $value ? 0 : (int) $value;
    }

    public static function is_blocked($username) {
        $username = self::normalize($username);
        if ('' !== $username && self::count('login_user', $username) >= self::MAX_PER_USER) {
            return true;
        }
        return self::count('login_ip') >= self::MAX_PER_IP;
    }

    public static function on_failed($username, $error = null) {
        $username = self::normalize($username);
        if ('' !== $username) {
            self::bump('login_user', $username);
        }
        self::bump('login_ip');
    }

    public static function on_success($user_login, $user = null) {
        $username = self::normalize($user_login);
        if ('' !== $username) {
            delete_transient(TAP_Rate_Limit::key('login_user', $username));
        }
        delete_transient(TAP_Rate_Limit::key('login_ip'));
    }

    /**
     * Last filter in the authenticate chain: a blocked caller gets a generic
     * error, while a valid credential still wins (so the success hook can
     * reset the counters).
     */
    public static function block($user, $username, $password) {
        if ($user instanceof WP_User) {
            return $user;
        }
        if ('' === self::normalize($username)) {
            return $user;
        }
        if (self::is_blocked($username)) {
            return new WP_Error(
                'tap_login_blocked',
                __('Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.', 'travel-agency-platform')
            );
        }
        return $user;
    }

    private static function bump($bucket, $id = '') {
        $key = TAP_Rate_Limit::key($bucket, $id);
        $value = get_transient($key);
        $value = false === $value ? 0 : (int) $value;
        set_transient($key, $value + 1, self::WINDOW);
    }

    private static function normalize($username) {
        return strtolower(trim((string) $username));
    }
}
