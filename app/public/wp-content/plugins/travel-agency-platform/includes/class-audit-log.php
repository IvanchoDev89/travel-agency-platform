<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Audit log for agency actions (who did what, when).
 */
class TAP_Audit_Log {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_audit_log';
    }

    /** Log an action. */
    public static function log($action, $object_type = null, $object_id = null, $details = null, $agency_id = 0, $user_id = 0) {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$agency_id && $user_id) {
            if (class_exists('TAP_Booking')) {
                $agency_id = (int) TAP_Booking::get_agency_for_user($user_id);
            }
        }
        $wpdb->insert(self::table(), [
            'user_id'     => (int) $user_id,
            'agency_id'   => (int) $agency_id,
            'action'      => sanitize_key($action),
            'object_type' => $object_type ? sanitize_key($object_type) : null,
            'object_id'   => $object_id ? (int) $object_id : null,
            'details'     => $details ? sanitize_textarea_field($details) : null,
            'ip'          => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Get log entries for an agency. */
    public static function for_agency($agency_id, $limit = 50, $offset = 0) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE agency_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            (int) $agency_id, (int) $limit, (int) $offset
        ));
    }

    /** Get log entries for a user. */
    public static function for_user($user_id, $limit = 50) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
    }

    /** Count for agency. */
    public static function count_for_agency($agency_id) {
        global $wpdb;
        $t = self::table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE agency_id = %d", (int) $agency_id));
    }
}
