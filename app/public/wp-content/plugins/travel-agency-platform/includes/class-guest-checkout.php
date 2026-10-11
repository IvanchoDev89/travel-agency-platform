<?php
defined('ABSPATH') || exit;

/**
 * Guest checkout helpers: the per-email rate-limit bucket behind the booking\n * form and the transient that holds a guest payment token. Utilities rather\n * than handlers, so they move out of TAP_Ajax.
 */
class TAP_Guest_Checkout {
    public static function get_guest_pay_token($booking_code) {
        return (bool) get_transient('tap_guest_pay_' . (string) $booking_code);
    }


    public static function set_guest_pay_token($booking_code) {
        set_transient('tap_guest_pay_' . (string) $booking_code, 1, 30 * MINUTE_IN_SECONDS);
    }


    public static function guest_book_rate_bump($email) {
        $key = self::guest_bucket((string) $email);
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
    }


    public static function guest_book_rate_blocked($email) {
        return (int) get_transient(self::guest_bucket((string) $email)) >= 5;
    }


    public static function guest_bucket($email) {
        return 'tap_guest_book_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower((string) $email));
    }
}
