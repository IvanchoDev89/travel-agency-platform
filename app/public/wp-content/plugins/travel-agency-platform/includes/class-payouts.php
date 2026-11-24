<?php
/**
 * TAP_Payouts — commission payout ledger lifecycle (Fase 6, self-service Fase 16).
 *
 * Two creation paths:
 *  - admin   : the administrator liquidates commissions (bookings are marked
 *              'paid' at creation).
 *  - agency  : the agency self-serves a payout REQUEST over its OWED commission
 *              while bookings stay 'oweed' until the admin completes it.
 * The payout then moves pending → completed once the transfer happens, or
 * pending → cancelled (reverts the involved commissions so they can be paid
 * later).
 */
defined('ABSPATH') || exit;

class TAP_Payouts {

    const PENDING   = 'pending';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
    const FAILED    = 'failed';

    public static function status_label($status) {
        $labels = [
            self::PENDING   => __('Pendiente', 'travel-agency-platform'),
            self::COMPLETED => __('Completada', 'travel-agency-platform'),
            self::CANCELLED => __('Cancelada', 'travel-agency-platform'),
            self::FAILED    => __('Fallida', 'travel-agency-platform'),
        ];
        return $labels[$status] ?? (string) $status;
    }

    /** Normalized payout<->booking link table (index-backed, replaces FIND_IN_SET). */
    public static function link_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_payout_bookings';
    }

    /** Persist the booking set for a payout (CSV stays for legacy display). */
    public static function set_links($payment_id, array $booking_ids) {
        global $wpdb;
        $payment_id = (int) $payment_id;
        $wpdb->delete(self::link_table(), ['payout_id' => $payment_id]);
        foreach (array_unique(array_filter(array_map('intval', $booking_ids))) as $bid) {
            $wpdb->insert(self::link_table(), ['payout_id' => $payment_id, 'booking_id' => $bid]);
        }
    }

    /** Booking ids for a payout: normalized links, falling back to legacy CSV. */
    public static function ids_for($payment_id) {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT booking_id FROM ' . self::link_table() . ' WHERE payout_id = %d',
            (int) $payment_id
        )));
        if ($ids) { return array_values(array_filter($ids)); }
        $csv = $wpdb->get_var($wpdb->prepare(
            "SELECT booking_ids FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d",
            (int) $payment_id
        ));
        return array_values(array_filter(array_map('intval', explode(',', (string) $csv))));
    }

    /**
     * Backfill normalized links for legacy payouts that still only have the
     * CSV column (e.g. rows created before the upgrade or by direct DB seeds).
     */
    public static function sync_all_links() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.id, p.booking_ids FROM {$wpdb->prefix}tap_commission_payments p
             WHERE p.booking_ids IS NOT NULL AND p.booking_ids <> '' AND p.booking_ids <> '0'
               AND NOT EXISTS (SELECT 1 FROM " . self::link_table() . " l WHERE l.payout_id = p.id)"
        );
        foreach ((array) $rows as $row) {
            self::set_links((int) $row->id, explode(',', (string) $row->booking_ids));
        }
    }

    /** Mark a pending payout as transferred. */
    public static function complete($payment_id) {
        global $wpdb;
        $payment_id = intval($payment_id);
        if (!self::transition($payment_id, [self::PENDING], self::COMPLETED)) {
            return false;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_ids, source FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d",
            $payment_id
        ));
        // Only agency-source requests keep their bookings 'owed' until the
        // transfer happens, so only they need reconciliation. Admin-source
        // payouts already flipped their bookings to 'paid' at creation: their
        // amount and booking set are authoritative and must be left untouched.
        if ($row && 'agency' === $row->source) {
            $ids = self::ids_for($payment_id);
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                // Agency-source requests keep bookings 'owed' on purpose: only now
                // that the transfer happened do their commissions become 'paid'.
                // Re-check eligibility so a booking disputed, cancelled or
                // refunded since the request (which froze/voided its commission)
                // is never paid out on top of the frozen amount.
                $eligible = $wpdb->get_results(
                    "SELECT id, commission_amount FROM {$wpdb->prefix}tap_bookings
                      WHERE id IN ({$in})
                        AND commission_status = 'owed'
                        AND payment_status = 'paid'
                        AND commission_amount > 0
                        AND status NOT IN ('cancelled', 'refunded')"
                );
                // Reconcile the ledger amount with what is actually being
                // settled now: disputes/refunds/voids since the request shrink
                // the eligible set and the row must not claim more.
                $settled = round(array_sum(array_map(fn($r) => (float) $r->commission_amount, $eligible)), 2);
                $wpdb->update($wpdb->prefix . 'tap_commission_payments', ['amount' => $settled], ['id' => $payment_id]);
                if ($eligible) {
                    $eligible_in = implode(',', array_map('intval', array_column($eligible, 'id')));
                    $wpdb->query(
                        "UPDATE {$wpdb->prefix}tap_bookings
                            SET commission_status = 'paid'
                          WHERE id IN ({$eligible_in})
                            AND commission_status = 'owed'"
                    );
                }
            }
        }
        $wpdb->update($wpdb->prefix . 'tap_commission_payments', ['paid_at' => current_time('mysql')], ['id' => $payment_id]);
        do_action('tap_payout_completed', (int) $payment_id);
        return true;
    }

    /** Cancel a pending payout; reverts its commissions to 'owed'. */
    public static function cancel($payment_id) {
        if (!self::transition($payment_id, [self::PENDING], self::CANCELLED)) {
            return false;
        }
        self::release_commissions($payment_id);
        do_action('tap_payout_cancelled', (int) $payment_id);
        return true;
    }

    /**
     * Mark a pending payout as failed (the transfer did not go through).
     * Commissions are released back to 'owed' so the agency can request again.
     */
    public static function fail($payment_id, $note = '') {
        global $wpdb;
        $payment_id = intval($payment_id);
        if (!self::transition($payment_id, [self::PENDING], self::FAILED)) {
            return false;
        }
        $note  = trim((string) $note);
        $note  = $note !== '' ? $note : __('Pago no completado por el banco/procesador.', 'travel-agency-platform');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT note FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        $wpdb->update(
            $wpdb->prefix . 'tap_commission_payments',
            ['note' => ($existing && $existing->note) ? $existing->note . ' | ' . $note : $note],
            ['id' => $payment_id]
        );
        self::release_commissions($payment_id);
        do_action('tap_payout_failed', (int) $payment_id);
        return true;
    }

    /**
     * Revert this payout's bookings from 'paid' back to 'owed' — but only
     * those not already settled by a DIFFERENT completed payout, so
     * cancelling one row can never make a commission payable twice.
     */
    private static function release_commissions($payment_id) {
        global $wpdb;
        self::sync_all_links();
        $payment_id = intval($payment_id);
        $ptable = $wpdb->prefix . 'tap_commission_payments';
        $ids = self::ids_for($payment_id);
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $link = self::link_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tap_bookings b
                SET b.commission_status = 'owed'
              WHERE b.id IN ({$in})
                AND b.commission_status = 'paid'
                AND NOT EXISTS (
                    SELECT 1 FROM {$ptable} p
                     JOIN {$link} l ON l.payout_id = p.id
                     WHERE p.status = 'completed'
                       AND p.id != %d
                       AND l.booking_id = b.id
                )",
            $payment_id
        ));
    }

    /** Atomic status claim: the UPDATE only lands when the row is still in $from. */
    private static function transition($id, array $from, $to) {
        global $wpdb;
        $id  = intval($id);
        $set = "'" . implode("','", array_map('esc_sql', $from)) . "'";
        $ok  = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tap_commission_payments SET status = %s WHERE id = %d AND status IN ({$set})",
            $to,
            $id
        ));
        return (bool) $ok;
    }
}
