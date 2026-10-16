<?php
defined('ABSPATH') || exit;

/**
 * TAP_Rate_Limit — tiny transient-based per-IP throttle shared by the public
 * (logged-out) endpoints: search, autocomplete, pricing and the itinerary
 * wizard all run expensive WP_Query / price lookups that a scraper could
 * hammer.
 *
 * The bucket + optional id + client IP + host key keeps independent budgets
 * per feature and per site. Counting is inclusive: the request that reaches
 * the limit is the first one rejected.
 */
class TAP_Rate_Limit {

    /**
     * Transient key for a bucket/id pair. Exposed so callers and tests can
     * reset a bucket deterministically.
     */
    public static function key($bucket, $id = '') {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        return 'tap_rl_' . md5($bucket . '|' . $id . '|' . self::client_ip() . '|' . $host);
    }

    /**
     * Increment the bucket and report whether the request should be blocked.
     *
     * @param string $bucket Feature name (e.g. 'search', 'chat').
     * @param int    $limit  Allowed requests inside the window.
     * @param int    $window Window length in seconds.
     * @param string $id     Optional extra discriminator (e.g. an email).
     * @return bool True when the caller is over the limit.
     */
    public static function too_many($bucket, $limit, $window, $id = '') {
        $key  = self::key($bucket, $id);
        $data = get_transient($key);
        if (false === $data) {
            set_transient($key, 1, $window);
            return false;
        }
        if ((int) $data >= (int) $limit) {
            return true;
        }
        set_transient($key, (int) $data + 1, $window);
        return false;
    }

    /** Best-effort client IP (no proxy headers: they are spoofable). */
    public static function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }
}
