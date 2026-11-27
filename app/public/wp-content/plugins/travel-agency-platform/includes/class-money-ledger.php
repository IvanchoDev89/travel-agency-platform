<?php
defined('ABSPATH') || exit;

/**
 * Append-only money ledger and daily invariant checks.
 *
 * Existing payment flows keep working as they do; this class only observes
 * their events and writes an immutable audit trail, then verifies that the
 * resulting state still satisfies the platform's financial invariants.
 * Rows are never updated or deleted by plugin code.
 */
class TAP_Money_Ledger {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_money_ledger';
    }

    public static function init() {
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 90, 3);
        add_action('tap_payment_refunded', [__CLASS__, 'on_payment_refunded'], 90, 3);
        add_action('tap_payment_partially_refunded', [__CLASS__, 'on_partial_refund'], 90, 4);
        add_action('tap_payment_overpaid', [__CLASS__, 'on_overpaid'], 90, 4);
        add_action('tap_commission_prorated', [__CLASS__, 'on_prorated'], 90, 4);
        add_action('tap_dispute_commission_clawback', [__CLASS__, 'on_clawback'], 90, 3);
        add_action('tap_commission_paid', [__CLASS__, 'on_commission_paid'], 90, 2);
        add_action('tap_payout_completed', [__CLASS__, 'on_payout_completed'], 90, 1);
        add_action('tap_money_reconcile_hook', [__CLASS__, 'run_reconciliation']);
    }

    /** Write one immutable ledger row. */
    public static function record($booking_id, $event, $amount = 0, $reason = '', $ref = '') {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $row = $booking_id ? TAP_Booking::get_booking($booking_id) : null;
        $wpdb->insert(self::table(), [
            'booking_id'     => $booking_id,
            'event'          => sanitize_key((string) $event),
            'amount'         => round((float) $amount, 2),
            'currency'       => $row && !empty($row->currency) ? (string) $row->currency : '',
            'payment_status' => $row ? (string) $row->payment_status : '',
            'amount_paid'    => $row ? round((float) $row->amount_paid, 2) : 0,
            'refund_amount'  => $row ? round((float) ($row->refund_amount ?? 0), 2) : 0,
            'commission_status' => $row ? (string) $row->commission_status : '',
            'actor'          => get_current_user_id(),
            'reason'         => sanitize_text_field((string) $reason),
            'reference'      => sanitize_text_field((string) $ref),
            'created_at'     => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function for_booking($booking_id, $limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT %d',
            (int) $booking_id,
            max(1, (int) $limit)
        )) ?: [];
    }

    public static function on_payment_completed($booking_id, $method = '', $capture_id = '') {
        $booking = TAP_Booking::get_booking((int) $booking_id);
        self::record($booking_id, 'payment_completed', $booking ? (float) $booking->amount_paid : 0,
            (string) $method, (string) $capture_id);
    }

    public static function on_payment_refunded($booking_id, $gateway = '', $ref = '') {
        $booking = TAP_Booking::get_booking((int) $booking_id);
        self::record($booking_id, 'payment_refunded', $booking ? (float) ($booking->refund_amount ?? 0) : 0,
            (string) $gateway, (string) $ref);
    }

    public static function on_partial_refund($booking_id, $gateway = '', $ref = '', $amount = 0) {
        self::record($booking_id, 'partial_refund', (float) $amount, (string) $gateway, (string) $ref);
    }

    public static function on_overpaid($booking_id, $gateway = '', $capture_id = '', $surplus = 0) {
        self::record($booking_id, 'overpayment', (float) $surplus, (string) $gateway, (string) $capture_id);
    }

    public static function on_prorated($booking_id, $original = 0, $new = 0, $refund = 0) {
        self::record($booking_id, 'commission_prorated', (float) $new - (float) $original,
            sprintf(/* translators: %s: importe reembolsado */ __('Reembolso %s', 'travel-agency-platform'), (string) $refund));
    }

    public static function on_clawback($dispute_id, $booking_id, $amount = 0) {
        self::record($booking_id, 'dispute_clawback', -(float) $amount, __('Disputa', 'travel-agency-platform'), (string) $dispute_id);
    }

    public static function on_commission_paid($agency_id, $payment_id = 0) {
        self::record(0, 'commission_paid', 0, __('Agencia', 'travel-agency-platform') . ' ' . (int) $agency_id, (string) $payment_id);
    }

    public static function on_payout_completed($payment_id) {
        self::record(0, 'payout_completed', 0, '', (string) $payment_id);
    }

    /**
     * Read-only invariant checks. Returns issue descriptors suitable for the
     * financial exceptions page. Bounded queries keep this cron-friendly.
     */
    public static function check_invariants() {
        global $wpdb;
        $b = $wpdb->prefix . 'tap_bookings';
        $p = $wpdb->prefix . 'tap_commission_payments';
        $l = self::link_table_name();
        $issues = [];

        $rows = $wpdb->get_results(
            "SELECT id, booking_code, total_amount, amount_paid FROM {$b}
              WHERE payment_status = 'paid' AND amount_paid < total_amount - 0.01 LIMIT 50"
        );
        foreach ((array) $rows as $r) {
            $issues[] = [
                'type' => __('Pagado por debajo del total', 'travel-agency-platform'),
                'booking' => (int) $r->id,
                'code' => (string) $r->booking_code,
                'detail' => TAP_Currency::fmt((float) $r->amount_paid) . ' / ' . TAP_Currency::fmt((float) $r->total_amount),
            ];
        }

        $rows = $wpdb->get_results(
            "SELECT id, booking_code, amount_paid, refund_amount FROM {$b}
              WHERE COALESCE(refund_amount,0) > COALESCE(amount_paid,0) + 0.01 LIMIT 50"
        );
        foreach ((array) $rows as $r) {
            $issues[] = [
                'type' => __('Reembolso superior a lo cobrado', 'travel-agency-platform'),
                'booking' => (int) $r->id,
                'code' => (string) $r->booking_code,
                'detail' => TAP_Currency::fmt((float) $r->refund_amount) . ' / ' . TAP_Currency::fmt((float) $r->amount_paid),
            ];
        }

        $rows = $wpdb->get_results(
            "SELECT b.id, b.booking_code FROM {$b} b
              WHERE b.commission_status = 'paid' AND b.commission_amount > 0
                AND NOT EXISTS (
                    SELECT 1 FROM {$p} pay JOIN {$l} lnk ON lnk.payout_id = pay.id
                     WHERE pay.status = 'completed' AND lnk.booking_id = b.id
                ) LIMIT 50"
        );
        foreach ((array) $rows as $r) {
            $issues[] = [
                'type' => __('Comisión liquidada sin payout', 'travel-agency-platform'),
                'booking' => (int) $r->id,
                'code' => (string) $r->booking_code,
                'detail' => __('El estado dice pagada pero no hay transferencia registrada.', 'travel-agency-platform'),
            ];
        }

        $rows = $wpdb->get_results(
            "SELECT b.id, b.booking_code, COUNT(*) c FROM {$b} b
              JOIN {$l} lnk ON lnk.booking_id = b.id
              JOIN {$p} pay ON pay.id = lnk.payout_id AND pay.status = 'pending'
             GROUP BY b.id HAVING COUNT(*) > 1 LIMIT 50"
        );
        foreach ((array) $rows as $r) {
            $issues[] = [
                'type' => __('Reserva en dos payouts pendientes', 'travel-agency-platform'),
                'booking' => (int) $r->id,
                'code' => (string) $r->booking_code,
                'detail' => (int) $r->c . ' ' . __('liquidaciones pendientes', 'travel-agency-platform'),
            ];
        }

        $rows = $wpdb->get_results(
            "SELECT id, booking_code, amount_paid FROM {$b}
              WHERE payment_status = 'refunded' AND amount_paid > 0.01 LIMIT 50"
        );
        foreach ((array) $rows as $r) {
            $issues[] = [
                'type' => __('Reembolsada con saldo pendiente', 'travel-agency-platform'),
                'booking' => (int) $r->id,
                'code' => (string) $r->booking_code,
                'detail' => TAP_Currency::fmt((float) $r->amount_paid),
            ];
        }

        return $issues;
    }

    private static function link_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'tap_payout_bookings';
    }

    public static function run_reconciliation() {
        $issues = self::check_invariants();
        set_transient('tap_money_issues', $issues, 2 * DAY_IN_SECONDS);
        update_option('tap_money_reconciled_at', current_time('mysql'), false);
        return $issues;
    }

    public static function stored_issues() {
        $issues = get_transient('tap_money_issues');
        return is_array($issues) ? $issues : [];
    }
}
