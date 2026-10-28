<?php
defined('ABSPATH') || exit;

/**
 * Loyalty program for travelers: points, levels, redemption.
 */
class TAP_Loyalty {

    public static function init() {
        add_action('tap_booking_status_updated', [__CLASS__, 'on_status_updated'], 10, 3);
    }

    public static function on_status_updated($booking_id, $new_status, $prev_status) {
        if ('completed' === $new_status && 'completed' !== $prev_status) {
            self::on_booking_completed($booking_id);
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_loyalty_points';
    }

    /** Points per currency unit (configurable). */
    public static function points_per_unit() {
        return (float) get_option('tap_loyalty_points_per_unit', 1.0);
    }

    /** Points needed to redeem 1 unit of currency. */
    public static function points_per_redemption_unit() {
        return (int) get_option('tap_loyalty_points_per_redemption_unit', 100);
    }

    /** Level thresholds. */
    public static function levels() {
        return [
            'bronze' => ['min' => 0,    'label' => __('Bronce', 'travel-agency-platform'), 'discount' => 0],
            'silver' => ['min' => 500,  'label' => __('Plata', 'travel-agency-platform'),   'discount' => 5],
            'gold'   => ['min' => 2000, 'label' => __('Oro', 'travel-agency-platform'),     'discount' => 10],
        ];
    }

    /** Get user's total points (non-expired). */
    public static function balance($user_id) {
        global $wpdb;
        $t = self::table();
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM {$t} WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
            (int) $user_id
        ));
        return (int) $sum;
    }

    /** Get user's level. */
    public static function level($user_id) {
        $bal = self::balance($user_id);
        $level = 'bronze';
        foreach (self::levels() as $key => $info) {
            if ($bal >= $info['min']) $level = $key;
        }
        return $level;
    }

    /** Award points for a booking. */
    public static function award($user_id, $points, $source = 'booking', $source_id = 0) {
        global $wpdb;
        $points = (int) $points;
        if ($points <= 0) return 0;
        $wpdb->insert(self::table(), [
            'user_id'    => (int) $user_id,
            'points'     => $points,
            'source'     => sanitize_key($source),
            'source_id'  => (int) $source_id,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+12 months')),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Redeem points for a discount. Returns discount amount or 0. */
    public static function redeem($user_id, $points) {
        $points = (int) $points;
        if ($points <= 0) return 0;
        $balance = self::balance($user_id);
        if ($points > $balance) return 0;
        $rate = self::points_per_redemption_unit();
        $discount = floor($points / $rate);
        if ($discount <= 0) return 0;
        $actual_points = $discount * $rate;
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id'    => (int) $user_id,
            'points'     => -$actual_points,
            'source'     => 'redemption',
            'source_id'  => 0,
            'expires_at' => null,
        ]);
        return (float) $discount;
    }

    /** Points earned for an amount. */
    public static function points_for_amount($amount) {
        return (int) floor((float) $amount * self::points_per_unit());
    }

    /** History for a user. */
    public static function history($user_id, $limit = 20) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
    }

    /** Award points on booking completion. Hook into booking status change. */
    public static function on_booking_completed($booking_id) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $booking_id));
        if (!$booking || !$booking->client_id) return;
        $points = self::points_for_amount($booking->total_amount);
        if ($points > 0) {
            self::award((int) $booking->client_id, $points, 'booking', (int) $booking_id);
        }
    }
}
