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

    public static function statuses() {
        return [self::PENDING, self::COMPLETED, self::CANCELLED, self::FAILED];
    }

    public static function status_label($status) {
        $labels = [
            self::PENDING   => __('Pendiente', 'travel-agency-platform'),
            self::COMPLETED => __('Completada', 'travel-agency-platform'),
            self::CANCELLED => __('Cancelada', 'travel-agency-platform'),
            self::FAILED    => __('Fallida', 'travel-agency-platform'),
        ];
        return $labels[$status] ?? (string) $status;
    }

    public static function sources() {
        return ['admin', 'agency'];
    }

    /**
     * Agency self-service payout request: groups all currently OWED commission
     * bookings of the agency into a single pending payout row WITHOUT touching
     * the bookings yet. Bookings already covered by a pending payout (from any
     * source) are excluded so nothing can be double-liquidated.
     */
    public static function request($agency_id, $method = 'bank_transfer', $note = '') {
        global $wpdb;
        $agency_id = intval($agency_id);
        if ($agency_id < 1) {
            return new WP_Error('no_agency', __('Agencia inválida.', 'travel-agency-platform'));
        }
        $method = in_array($method, ['bank_transfer', 'paypal', 'cheque', 'cash'], true) ? $method : 'bank_transfer';
        $table  = $wpdb->prefix . 'tap_bookings';
        $ptable = $wpdb->prefix . 'tap_commission_payments';

        // Serialize the whole select-and-insert so two simultaneous requests
        // cannot pay out the same still-owed commissions twice. If another
        // process holds the lock we bail instead of running unprotected.
        $lock = 'tap_payout_request_' . $agency_id;
        if (1 !== (int) $wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock))) {
            return new WP_Error('locked', __('Otro proceso está liquidando estas comisiones. Inténtalo de nuevo.', 'travel-agency-platform'));
        }
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, b.commission_amount
                   FROM {$table} b
                  WHERE b.agency_id = %d
                    AND b.commission_status = 'owed'
                    AND b.payment_status = 'paid'
                    AND b.status NOT IN ('cancelled', 'refunded')
                    AND b.commission_amount > 0
                    AND NOT EXISTS (
                        SELECT 1 FROM {$ptable} p
                         WHERE p.status = 'pending'
                           AND FIND_IN_SET(b.id, p.booking_ids)
                    )",
                $agency_id
            ));
            if (!$rows) {
                return new WP_Error('nothing_owed', __('No tienes comisiones por cobrar disponibles para liquidar.', 'travel-agency-platform'));
            }

            $amount       = round(array_sum(array_map(fn($r) => (float) $r->commission_amount, $rows)), 2);
            $booking_ids  = implode(',', array_column($rows, 'id'));
            $wpdb->insert($ptable, [
                'agency_id'   => $agency_id,
                'amount'      => $amount,
                'booking_ids' => $booking_ids,
                'method'      => $method,
                'note'        => sanitize_textarea_field($note) ?: __('Solicitud de liquidación', 'travel-agency-platform'),
                'source'      => 'agency',
                'created_by'  => get_current_user_id(),
            ]);
            $payment_id = (int) $wpdb->insert_id;
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('{$lock}')");
        }
        if (!$payment_id) {
            return new WP_Error('db_error', __('No se pudo registrar la solicitud.', 'travel-agency-platform'));
        }

        do_action('tap_payout_requested', $payment_id, $agency_id, $amount);
        return $payment_id;
    }

    /** Mark a pending payout as transferred. */
    public static function complete($payment_id) {
        global $wpdb;
        $payment_id = intval($payment_id);
        if (!self::transition($payment_id, [self::PENDING], self::COMPLETED)) {
            return false;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_ids FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d",
            $payment_id
        ));
        if ($row && $row->booking_ids) {
            $ids = array_filter(array_map('intval', explode(',', $row->booking_ids)));
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
        $payment_id = intval($payment_id);
        $ptable = $wpdb->prefix . 'tap_commission_payments';
        $row = $wpdb->get_row($wpdb->prepare("SELECT booking_ids FROM {$ptable} WHERE id = %d", $payment_id));
        if (!$row || !$row->booking_ids) {
            return;
        }
        $ids = array_filter(array_map('intval', explode(',', $row->booking_ids)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tap_bookings b
                SET b.commission_status = 'owed'
              WHERE b.id IN ({$in})
                AND b.commission_status = 'paid'
                AND NOT EXISTS (
                    SELECT 1 FROM {$ptable} p
                     WHERE p.status = 'completed'
                       AND p.id != %d
                       AND FIND_IN_SET(b.id, p.booking_ids)
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
