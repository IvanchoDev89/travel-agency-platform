<?php
/**
 * TAP_Reviews — verified reviews (Fase 5).
 *
 * A trip review is only accepted once the author holds a confirmed or
 * completed booking for the same service. Messages are authored in Spanish
 * (the source locale) so translations live in the PO catalog.
 */
defined('ABSPATH') || exit;

class TAP_Reviews {

    /** Booking statuses that prove a real, paid experience. */
    public static function verified_statuses() {
        return ['confirmed', 'completed'];
    }

    /**
     * Confirmed/completed bookings for a user, optionally narrowed to one
     * service. Rows are ordered by most recent experience first.
     */
    public static function eligible_bookings($user_id, $service_type = '', $service_id = 0) {
        global $wpdb;
        if (!$user_id) {
            return [];
        }
        $status = array_map('sanitize_key', self::verified_statuses());
        $placeholders = implode(',', array_fill(0, count($status), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_code, service_type, service_id, check_in, total_amount
             FROM {$wpdb->prefix}tap_bookings
             WHERE client_id = %d AND status IN ({$placeholders})
             ORDER BY check_in DESC, id DESC",
            array_merge([$user_id], $status)
        ));
        $out = [];
        foreach ($rows as $row) {
            if ('' !== $service_type && (string) $row->service_type !== (string) $service_type) {
                continue;
            }
            if ($service_id > 0 && (int) $row->service_id !== (int) $service_id) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Whether a user may review a given service (verified guest). */
    public static function can_review($user_id, $service_type, $service_id) {
        return (bool) self::eligible_bookings($user_id, $service_type, $service_id);
    }

    /** Most recent eligible booking for a service, or null. */
    public static function latest_booking($user_id, $service_type, $service_id) {
        $list = self::eligible_bookings($user_id, $service_type, $service_id);
        return $list ? $list[0] : null;
    }

    /** Does a specific booking belong to the user and count as verified? */
    public static function match_booking($user_id, $booking_id) {
        global $wpdb;
        if (!$user_id || !$booking_id) {
            return null;
        }
        $status = array_map('sanitize_key', self::verified_statuses());
        $placeholders = implode(',', array_fill(0, count($status), '%s'));
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, booking_code, service_type, service_id, check_in, total_amount
             FROM {$wpdb->prefix}tap_bookings
             WHERE id = %d AND client_id = %d AND status IN ({$placeholders})",
            array_merge([$booking_id, $user_id], $status)
        ));
    }

    /** Post types that can receive reviews. */
    public static function service_types() {
        return ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
    }

    /** Human message shared by the form gate and REST rejection. */
    public static function verified_only_message() {
        return __('Solo puedes dejar reseñas después de una reserva confirmada o completada.', 'travel-agency-platform');
    }
}