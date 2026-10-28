<?php
defined('ABSPATH') || exit;

/**
 * Waitlist: when a service/room is fully booked, travelers can join a waitlist
 * and get notified when availability opens up.
 */
class TAP_Waitlist {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_waitlist';
    }

    /** Add a user/guest to the waitlist. */
    public static function add($service_type, $service_id, $check_in, $check_out = '', $user_id = 0, $guest_email = '', $room_id = 0, $adults = 1) {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'service_type' => sanitize_key($service_type),
            'service_id'   => (int) $service_id,
            'room_id'      => $room_id ? (int) $room_id : null,
            'user_id'      => $user_id ? (int) $user_id : null,
            'guest_email'  => sanitize_email($guest_email),
            'check_in'     => sanitize_text_field($check_in),
            'check_out'    => $check_out ? sanitize_text_field($check_out) : null,
            'adults'       => (int) $adults,
            'status'       => 'waiting',
        ]);
        return (int) $wpdb->insert_id;
    }

    /** List waitlist entries for a service. */
    public static function for_service($service_type, $service_id, $status = 'waiting') {
        global $wpdb;
        $t = self::table();
        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE service_type = %s AND service_id = %d AND status = %s ORDER BY created_at ASC",
                $service_type, (int) $service_id, $status
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE service_type = %s AND service_id = %d ORDER BY created_at ASC",
            $service_type, (int) $service_id
        ));
    }

    /** Mark an entry as notified. */
    public static function mark_notified($id) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), [
            'status'      => 'notified',
            'notified_at' => current_time('mysql'),
        ], ['id' => (int) $id], ['%s', '%s'], ['%d']);
    }

    /** Remove/cancel an entry. */
    public static function cancel($id) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), ['status' => 'cancelled'], ['id' => (int) $id], ['%s'], ['%d']);
    }

    /** Count waiting for a service. */
    public static function count_waiting($service_type, $service_id) {
        global $wpdb;
        $t = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE service_type = %s AND service_id = %d AND status = 'waiting'",
            $service_type, (int) $service_id
        ));
    }
}
