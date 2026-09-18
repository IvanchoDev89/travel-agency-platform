<?php
/**
 * TAP_Automations — recurring workflow automations (Fase 4 / T4).
 *
 * A daily WP-Cron task drives four batteries of work, each independently
 * toggleable from the platform settings page:
 *   1. Payment reminders for pending/request bookings that never got paid.
 *   2. Pre-arrival messages for confirmed bookings whose stay starts soon.
 *   3. Post-stay review requests after a completed stay.
 *   4. Expiry warnings for active plan subscriptions and featured listings.
 *
 * Sent state is tracked in options keyed by task so a booking/plan is never
 * reminded twice. Emails themselves live in TAP_Emails (content + i18n).
 */
defined('ABSPATH') || exit;

class TAP_Automations {

    /** Max items any single cron run processes per task (keeps runs short). */
    const BATCH = 100;

    /** How long sent-log entries are retained before pruning. */
    const LOG_DAYS = 60;

    public static function init() {
        if (!wp_next_scheduled('tap_auto_hook')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tap_auto_hook');
        }
        add_action('tap_auto_hook', [__CLASS__, 'run_all']);
    }

    public static function run_all() {
        self::payment_reminders();
        self::prearrival_messages();
        self::review_requests();
        self::expiry_warnings();
    }

    /* =========================================================
     * 1) Payment reminders
     * ========================================================= */

    public static function payment_reminders() {
        if ('1' !== (string) get_option('tap_auto_payment_reminders', '0')) {
            return;
        }
        $hours    = max(1, (int) get_option('tap_payment_reminder_hours', 12));
        $staleRaw = get_option('tap_stale_booking_hours', '24');
        $stale    = (false === $staleRaw || '' === $staleRaw) ? 24 : max(1, (int) $staleRaw);
        $stale    = max($hours + 1, $stale);
        $now   = current_time('timestamp');

        global $wpdb;
        $t = $wpdb->prefix . 'tap_bookings';
        list($ids, $finished) = self::batch_run('pay', function ($wpdb, $after_id, $limit) use ($t, $now, $stale, $hours) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$t}
                 WHERE status IN ('pending','request')
                   AND created_at BETWEEN %s AND %s
                   AND id > %d
                 ORDER BY created_at ASC, id ASC
                 LIMIT %d",
                date('Y-m-d H:i:s', $now - $stale * HOUR_IN_SECONDS),
                date('Y-m-d H:i:s', $now - $hours * HOUR_IN_SECONDS),
                $after_id,
                $limit
            ));
        });

        foreach ($ids as $id) {
            $id = (int) $id;
            $booking = TAP_Booking::get_booking($id);
            if (!$booking) {
                continue;
            }
            if (self::sent('pay', $id)) {
                continue;
            }
            if (TAP_Emails::send_payment_reminder($booking)) {
                self::mark('pay', $id);
            }
        }
        self::end_batch('pay', $ids, $finished);
    }

    /* =========================================================
     * 2) Pre-arrival messages
     * ========================================================= */

    public static function prearrival_messages() {
        if ('1' !== (string) get_option('tap_auto_prearrival', '0')) {
            return;
        }
        $today = date('Y-m-d', current_time('timestamp'));
        $soon  = date('Y-m-d', current_time('timestamp') + 2 * DAY_IN_SECONDS);

        global $wpdb;
        $t = $wpdb->prefix . 'tap_bookings';
        list($ids, $finished) = self::batch_run('pre', function ($wpdb, $after_id, $limit) use ($t, $today, $soon) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$t}
                 WHERE status = 'confirmed'
                   AND check_in BETWEEN %s AND %s
                   AND id > %d
                 ORDER BY check_in ASC, id ASC
                 LIMIT %d",
                $today,
                $soon,
                $after_id,
                $limit
            ));
        });

        foreach ($ids as $id) {
            $id = (int) $id;
            $booking = TAP_Booking::get_booking($id);
            if (!$booking) {
                continue;
            }
            if (self::sent('pre', $id)) {
                continue;
            }
            if (TAP_Emails::send_prearrival_msg($booking)) {
                self::mark('pre', $id);
            }
        }
        self::end_batch('pre', $ids, $finished);
    }

    /* =========================================================
     * 3) Post-stay review requests
     * ========================================================= */

    public static function review_requests() {
        if ('1' !== (string) get_option('tap_auto_review_request', '0')) {
            return;
        }
        $from = date('Y-m-d', current_time('timestamp') - 14 * DAY_IN_SECONDS);
        $to   = date('Y-m-d', current_time('timestamp') - 2 * DAY_IN_SECONDS);

        global $wpdb;
        $t = $wpdb->prefix . 'tap_bookings';
        list($ids, $finished) = self::batch_run('rev', function ($wpdb, $after_id, $limit) use ($t, $from, $to) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$t}
                 WHERE status = 'completed'
                   AND check_out BETWEEN %s AND %s
                   AND id > %d
                 ORDER BY check_out ASC, id ASC
                 LIMIT %d",
                $from,
                $to,
                $after_id,
                $limit
            ));
        });

        foreach ($ids as $id) {
            $id = (int) $id;
            $booking = TAP_Booking::get_booking($id);
            if (!$booking) {
                continue;
            }
            if (!get_permalink($booking->service_id)) {
                continue;
            }
            if (self::sent('rev', $id)) {
                continue;
            }
            if (TAP_Emails::send_review_request($booking)) {
                self::mark('rev', $id);
            }
        }
        self::end_batch('rev', $ids, $finished);
    }

    /* =========================================================
     * 4) Expiry warnings (subscriptions + featured promotions)
     * ========================================================= */

    public static function expiry_warnings() {
        if ('1' !== (string) get_option('tap_auto_expiry_warnings', '0')) {
            return;
        }
        $days = max(1, (int) get_option('tap_expiry_warn_days', 3));
        $until = date('Y-m-d', current_time('timestamp') + $days * DAY_IN_SECONDS);
        $today = date('Y-m-d', current_time('timestamp'));

        global $wpdb;

        list($subs, $sub_done) = self::batch_run('exp_sub', function ($wpdb, $after_id, $limit) use ($today, $until) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT id, agency_id, plan_id, paid_until FROM {$wpdb->prefix}tap_agency_subscriptions
                 WHERE status = 'active' AND paid_until BETWEEN %s AND %s AND id > %d
                 ORDER BY paid_until ASC, id ASC LIMIT %d",
                $today, $until, $after_id, $limit
            ));
        });
        foreach ($subs as $s) {
            $key = 'sub:' . (int) $s->id . ':' . $s->paid_until;
            if (self::sent('exp', $key)) {
                continue;
            }
            $label = get_the_title((int) $s->plan_id) ?: __('Plan', 'travel-agency-platform');
            if (TAP_Emails::send_expiry_warning((int) $s->agency_id, 'subscription', $label, $s->paid_until)) {
                self::mark('exp', $key);
            }
        }
        self::end_batch('exp_sub', $subs, $sub_done);

        list($promos, $promo_done) = self::batch_run('exp_prom', function ($wpdb, $after_id, $limit) use ($today, $until) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT id, agency_id, listing_id, paid_until FROM {$wpdb->prefix}tap_promos
                 WHERE status = 'active' AND paid_until BETWEEN %s AND %s AND id > %d
                 ORDER BY paid_until ASC, id ASC LIMIT %d",
                $today, $until, $after_id, $limit
            ));
        });
        foreach ($promos as $p) {
            $key = 'prom:' . (int) $p->id . ':' . $p->paid_until;
            if (self::sent('exp', $key)) {
                continue;
            }
            $label = get_the_title((int) $p->listing_id) ?: __('Servicio destacado', 'travel-agency-platform');
            if (TAP_Emails::send_expiry_warning((int) $p->agency_id, 'promo', $label, $p->paid_until)) {
                self::mark('exp', $key);
            }
        }
        self::end_batch('exp_prom', $promos, $promo_done);
    }

    /* =========================================================
     * Batching: each task walks its rows in id order a bounded
     * batch at a time, persisting a cursor so a huge table is
     * drained across daily runs instead of one long cron.
     * ========================================================= */

    /**
     * Fetch the next batch of rows for a task past $cursor.
     * Returns [rows, finished] where finished tells end_batch()
     * whether a cursor should be persisted.
     */
    private static function batch_run($task, callable $query) {
        global $wpdb;
        $cursor = (int) get_option('tap_auto_cursor_' . $task, 0);
        $rows = $query($wpdb, $cursor, self::BATCH);
        $rows = is_array($rows) ? $rows : [];
        $finished = count($rows) < self::BATCH;
        return [$rows, $finished];
    }

    private static function end_batch($task, array $rows, $finished) {
        if ($finished) {
            delete_option('tap_auto_cursor_' . $task);
            return;
        }
        $last = 0;
        foreach ($rows as $row) {
            $id = is_object($row) ? $row->id : $row;
            if ((int) $id > $last) {
                $last = (int) $id;
            }
        }
        if ($last > 0) {
            update_option('tap_auto_cursor_' . $task, $last, false);
        }
    }

    /* =========================================================
     * Sent-log helpers (option-backed arrays) — stored without
     * autoload and pruned so they never grow without bound.
     * ========================================================= */

    private static function log_key($task) {
        return 'tap_auto_sent_' . $task;
    }

    private static function sent($task, $id) {
        $log = (array) get_option(self::log_key($task), []);
        return isset($log[(string) $id]);
    }

    private static function mark($task, $id) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LOG_DAYS * DAY_IN_SECONDS);
        $log = (array) get_option(self::log_key($task), []);
        $log[(string) $id] = current_time('Y-m-d H:i:s');
        foreach ($log as $key => $when) {
            if (strcmp((string) $when, $cutoff) < 0) {
                unset($log[$key]);
            }
        }
        // autoload=no: these lists are maintenance state, not per-request data.
        update_option(self::log_key($task), $log, false);
    }
}