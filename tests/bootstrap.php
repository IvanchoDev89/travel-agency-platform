<?php
/**
 * Shared helpers for TAP E2E suites.
 * Do not run directly — include from a suite XX.php that is executed via:
 *   wp --path="<site>/app/public" eval-file tests/<suite>.php
 */

if (!function_exists('tap_t_pass')) {
    $GLOBALS['tap_failures'] = 0;
    $GLOBALS['tap_passes']   = 0;

    function tap_t_pass($msg) {
        $GLOBALS['tap_passes']++;
        echo "[PASS] {$msg}\n";
    }

    function tap_t_fail($msg) {
        $GLOBALS['tap_failures']++;
        echo "[FAIL] {$msg}\n";
    }

    function tap_t_assert($cond, $msg) {
        $cond ? tap_t_pass($msg) : tap_t_fail($msg);
    }

    /** Run generated results; prints fail=N and exits non-zero on failure. */
    function tap_t_finish() {
        $f = $GLOBALS['tap_failures'];
        echo "fail={$f} done\n";
        exit($f ? 1 : 0);
    }

    /** Resolve booking ids referenced by a commission payment's booking_ids.',
     *  Deletes all rows we create during a suite so re-runs are idempotent. */
    function tap_t_cleanup_bookings(array $booking_ids) {
        global $wpdb;
        if (!$booking_ids) {
            return;
        }
        $in = implode(',', array_map('intval', $booking_ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_commission_payments WHERE booking_ids IN ({$in})");
    }

    function tap_t_seed_booking($overrides = []) {
        global $wpdb;
        $defaults = [
            'booking_code'     => 'TAP-E2E-' . wp_generate_password(6, false),
            'agency_id'        => tap_t_test_agency(),
            'service_type'     => 'tap_tour',
            'service_id'       => 75,
            'total_amount'     => 100,
            'commission_amount'=> 10,
            'commission_percent' => 10,
            'commission_status'=> 'owed',
            'status'           => 'confirmed',
            'created_at'       => current_time('mysql'),
        ];
        $wpdb->insert($wpdb->prefix . 'tap_bookings', array_merge($defaults, $overrides));
        return (int) $wpdb->insert_id;
    }

    /** Agency post id used as the default for seeded bookings. */
    function tap_t_test_agency() {
        $posts = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
        return $posts ? (int) $posts[0] : 68;
    }

    function tap_t_clear_views($listing_id = null) {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_listing_views';
        if (null === $listing_id) {
            $wpdb->query("TRUNCATE {$t}");
        } else {
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE listing_id = %d", $listing_id));
        }
    }
}