<?php
defined('ABSPATH') || exit;

/**
 * Durable event outbox.
 *
 * Critical money events are persisted before being dispatched, so a fatal,
 * timeout or crashed worker can never silently lose them. The dispatcher
 * retries with bounded backoff and records the last error.
 */
class TAP_Event_Outbox {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_event_outbox';
    }

    public static function init() {
        add_action('tap_outbox_process_hook', [__CLASS__, 'process']);
        add_action('tap_outbox_money_issue', [__CLASS__, 'log_money_issue'], 10, 1);
        add_action('tap_outbox_overpayment', [__CLASS__, 'log_overpayment'], 10, 1);
    }

    /** Persist an event. $dedupe_key prevents identical retries from piling up. */
    public static function enqueue($event, array $payload = [], $dedupe_key = '') {
        global $wpdb;
        $event = sanitize_key((string) $event);
        if ('' === $event) { return 0; }
        $dedupe_key = $dedupe_key !== '' ? hash('sha256', (string) $dedupe_key) : '';
        if ($dedupe_key !== '') {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE dedupe_key = %s', $dedupe_key
            ));
            if ($exists) { return 0; }
        }
        $wpdb->insert(self::table(), [
            'event'       => $event,
            'payload'     => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'dedupe_key'  => $dedupe_key ?: null,
            'status'      => 'pending',
            'attempts'    => 0,
            'available_at'=> current_time('mysql'),
            'created_at'  => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Dispatch due events. Safe to call from cron or admin. */
    public static function process($limit = 20) {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT %d",
            current_time('mysql'), max(1, (int) $limit)
        ));
        $done = 0;
        $failed = 0;
        foreach ((array) $rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (!is_array($payload)) { $payload = []; }
            try {
                do_action('tap_outbox_' . $row->event, $payload);
                $wpdb->update($table, ['status' => 'done', 'last_error' => null], ['id' => (int) $row->id]);
                ++$done;
            } catch (Throwable $e) {
                $attempts = (int) $row->attempts + 1;
                $wait = min(10, $attempts) * 5;
                $wpdb->update($table, [
                    'attempts'     => $attempts,
                    'status'       => $attempts >= 5 ? 'failed' : 'pending',
                    'last_error'   => substr($e->getMessage(), 0, 240),
                    'available_at' => gmdate('Y-m-d H:i:s', time() + $wait * MINUTE_IN_SECONDS),
                ], ['id' => (int) $row->id]);
                ++$failed;
            }
        }
        return ['done' => $done, 'failed' => $failed];
    }

    public static function log_money_issue($payload) {
        if (!class_exists('TAP_Audit_Log')) { return; }
        $issue = is_array($payload) ? ($payload['issue'] ?? []) : [];
        if (!is_array($issue)) { return; }
        TAP_Audit_Log::log(
            'money_invariant_issue',
            'booking',
            (int) ($issue['booking'] ?? 0),
            (string) ($issue['type'] ?? ''),
            0,
            0
        );
    }

    public static function log_overpayment($payload) {
        if (!class_exists('TAP_Audit_Log')) { return; }
        TAP_Audit_Log::log(
            'overpayment_review',
            'booking',
            (int) ($payload['booking_id'] ?? 0),
            (string) ($payload['surplus'] ?? ''),
            0,
            0
        );
    }

    public static function pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status = 'pending'");
    }
}
