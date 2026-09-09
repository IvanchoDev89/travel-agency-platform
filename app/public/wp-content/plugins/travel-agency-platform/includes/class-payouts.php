<?php
/**
 * TAP_Payouts — commission payout ledger lifecycle (Fase 6).
 *
 * A payout row is created when the admin liquidates commissions (bookings are
 * marked paid at that point). The payout itself then moves pending → completed
 * once the transfer actually happens, or pending → cancelled (which reverts the
 * involved commissions to 'owed' so they can be paid later).
 */
defined('ABSPATH') || exit;

class TAP_Payouts {

    const PENDING   = 'pending';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';

    public static function statuses() {
        return [self::PENDING, self::COMPLETED, self::CANCELLED];
    }

    public static function status_label($status) {
        $labels = [
            self::PENDING   => __('Pendiente', 'travel-agency-platform'),
            self::COMPLETED => __('Completada', 'travel-agency-platform'),
            self::CANCELLED => __('Cancelada', 'travel-agency-platform'),
        ];
        return $labels[$status] ?? (string) $status;
    }

    /** Mark a pending payout as transferred. */
    public static function complete($payment_id) {
        global $wpdb;
        if (!self::_transition($payment_id, [self::PENDING], self::COMPLETED)) {
            return false;
        }
        $wpdb->update($wpdb->prefix . 'tap_commission_payments', ['paid_at' => current_time('mysql')], ['id' => $payment_id]);
        do_action('tap_payout_completed', (int) $payment_id);
        return true;
    }

    /** Cancel a pending payout; reverts its commissions to 'owed'. */
    public static function cancel($payment_id) {
        global $wpdb;
        if (!self::_transition($payment_id, [self::PENDING], self::CANCELLED)) {
            return false;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT booking_ids FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        if ($row && $row->booking_ids) {
            $ids = array_filter(array_map('intval', explode(',', $row->booking_ids)));
            if ($ids) {
                $in = implode(',', $ids);
                $wpdb->query("UPDATE {$wpdb->prefix}tap_bookings SET commission_status = 'owed' WHERE id IN ({$in}) AND commission_status = 'paid'");
            }
        }
        do_action('tap_payout_cancelled', (int) $payment_id);
        return true;
    }

    private static function _transition($id, array $from, $to) {
        global $wpdb;
        $id = intval($id);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $id));
        if (!$row || !in_array($row->status, $from, true)) {
            return false;
        }
        return (bool) $wpdb->update($wpdb->prefix . 'tap_commission_payments', ['status' => $to], ['id' => $id]);
    }
}