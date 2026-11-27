<?php
defined('ABSPATH') || exit;

/**
 * Centralised, guarded money-state transitions.
 *
 * Every write to payment_status / commission_status goes through a compare-
 * and-swap UPDATE, so a stale request can never overwrite a newer state (for
 * example a refund arriving after a capture). Same-state calls are idempotent.
 */
class TAP_Money_State {

    private static $payment_map = [
        'pending'  => ['pending', 'partial', 'paid', 'failed', 'refunded'],
        'partial'  => ['partial', 'paid', 'failed', 'refunded'],
        'paid'     => ['paid', 'refunded', 'partial', 'failed'],
        'failed'   => ['failed', 'pending', 'partial', 'paid'],
        'refunded' => ['refunded'],
    ];

    private static $commission_map = [
        'waiting'  => ['waiting', 'owed', 'paid', 'disputed', 'void'],
        'owed'     => ['owed', 'paid', 'disputed', 'void'],
        'paid'     => ['paid', 'owed', 'disputed', 'void'],
        'disputed' => ['disputed', 'owed', 'waiting', 'paid', 'void'],
        'void'     => ['void'],
    ];

    public static function allowed($kind, $from, $to) {
        $map = 'payment' === $kind ? self::$payment_map : self::$commission_map;
        if (!isset($map[$from])) { return false; }
        return in_array($to, $map[$from], true);
    }

    /** Atomic payment_status transition. @return true|WP_Error */
    public static function transition_payment($booking_id, $to, $reason = '') {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }
        $from = (string) $booking->payment_status;
        if (!self::allowed('payment', $from, $to)) {
            return new WP_Error('invalid_transition', sprintf(
                /* translators: 1: estado actual, 2: estado solicitado */
                __('Transición de pago no permitida: %1$s → %2$s.', 'travel-agency-platform'), $from, $to
            ));
        }
        if ($from === $to) { return true; }
        $updated = $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['payment_status' => $to],
            ['id' => $booking_id, 'payment_status' => $from]
        );
        if (1 !== $updated) {
            return new WP_Error('lost_race', __('El pago cambió en otra petición.', 'travel-agency-platform'));
        }
        if (class_exists('TAP_Money_Ledger')) {
            TAP_Money_Ledger::record($booking_id, 'payment_status_' . $to, 0, (string) $reason);
        }
        do_action('tap_money_state_changed', 'payment', $booking_id, $from, $to);
        return true;
    }

    /** Atomic commission_status transition. @return true|WP_Error */
    public static function transition_commission($booking_id, $to, $reason = '') {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }
        $from = (string) ($booking->commission_status ?? 'waiting');
        if (!self::allowed('commission', $from, $to)) {
            return new WP_Error('invalid_transition', sprintf(
                /* translators: 1: estado actual, 2: estado solicitado */
                __('Transición de comisión no permitida: %1$s → %2$s.', 'travel-agency-platform'), $from, $to
            ));
        }
        if ($from === $to) { return true; }
        $updated = $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['commission_status' => $to],
            ['id' => $booking_id, 'commission_status' => $from]
        );
        if (1 !== $updated) {
            return new WP_Error('lost_race', __('La comisión cambió en otra petición.', 'travel-agency-platform'));
        }
        if (class_exists('TAP_Money_Ledger')) {
            TAP_Money_Ledger::record($booking_id, 'commission_status_' . $to, 0, (string) $reason);
        }
        do_action('tap_money_state_changed', 'commission', $booking_id, $from, $to);
        return true;
    }
}
