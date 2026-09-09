<?php
/**
 * TAP_Disputes — booking disputes (Fase 6).
 *
 * A traveler (or guest) opens a dispute on a paid, confirmed booking. While
 * the dispute is open the booking's commission is frozen ('disputed') and
 * excluded from payouts. Resolution releases the commission in favour of the
 * agency or voids the unpaid commission against it.
 */
defined('ABSPATH') || exit;

class TAP_Disputes {

    const OPEN            = 'open';
    const FOR_AGENCY      = 'for_agency';
    const AGAINST_AGENCY  = 'against_agency';
    const WITHDRAWN       = 'withdrawn';

    /** Disputable booking statuses (a charged, real experience). */
    public static function disputable_statuses() {
        return ['confirmed', 'completed'];
    }

    public static function reasons() {
        return [
            'service_quality' => __('Calidad del servicio', 'travel-agency-platform'),
            'misleading'      => __('Publicidad engañosa', 'travel-agency-platform'),
            'cancellation'    => __('Cancelación o modificación', 'travel-agency-platform'),
            'other'           => __('Otro', 'travel-agency-platform'),
        ];
    }

    public static function status_label($status) {
        $labels = [
            self::OPEN           => __('Disputa abierta', 'travel-agency-platform'),
            self::FOR_AGENCY     => __('Resuelta a favor de la agencia', 'travel-agency-platform'),
            self::AGAINST_AGENCY => __('Resuelta en contra de la agencia', 'travel-agency-platform'),
            self::WITHDRAWN      => __('Retirada', 'travel-agency-platform'),
        ];
        return $labels[$status] ?? (string) $status;
    }

    public static function active_for_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_disputes WHERE booking_id = %d AND status = %s LIMIT 1",
            $booking_id,
            self::OPEN
        ));
    }

    public static function for_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_disputes WHERE booking_id = %d ORDER BY created_at DESC",
            $booking_id
        ));
    }

    public static function for_agency($agency_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_disputes WHERE agency_id = %d ORDER BY created_at DESC",
            $agency_id
        ));
    }

    public static function all($limit = 100) {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tap_disputes ORDER BY created_at DESC LIMIT " . intval($limit));
    }

    /**
     * Open a dispute on a booking. Freezes the commission while open.
     *
     * @param int    $booking_id  tap_bookings row id.
     * @param int    $client_id   logged-in traveler id (0 for guests).
     * @param string $guest_email guest email used for bookings made logged-out.
     * @param string $reason      one of self::reasons() keys.
     * @param string $details     free text.
     * @return int|WP_Error dispute id.
     */
    public static function open($booking_id, $client_id, $guest_email, $reason, $details) {
        global $wpdb;
        $booking_id = intval($booking_id);
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) {
            return new WP_Error('invalid_booking', __('La reserva no existe.', 'travel-agency-platform'));
        }
        if (!in_array($booking->status, self::disputable_statuses(), true) || 'paid' !== $booking->payment_status) {
            return new WP_Error('booking_not_disputable', __('Solo puedes disputar una reserva confirmada y pagada.', 'travel-agency-platform'));
        }
        if (self::active_for_booking($booking_id)) {
            return new WP_Error('duplicate_dispute', __('Ya existe una disputa abierta para esta reserva.', 'travel-agency-platform'));
        }
        if (!array_key_exists($reason, self::reasons())) {
            return new WP_Error('invalid_reason', __('Motivo de disputa no válido.', 'travel-agency-platform'));
        }
        $wpdb->insert(
            $wpdb->prefix . 'tap_disputes',
            [
                'booking_id'   => $booking_id,
                'agency_id'    => (int) $booking->agency_id,
                'client_id'    => intval($client_id),
                'guest_email'  => $guest_email ? sanitize_email($guest_email) : null,
                'reason'       => sanitize_key($reason),
                'details'      => sanitize_textarea_field($details),
                'status'       => self::OPEN,
            ]
        );
        $id = (int) $wpdb->insert_id;
        if ($id && 'owed' === $booking->commission_status) {
            $wpdb->update($wpdb->prefix . 'tap_bookings', ['commission_status' => 'disputed'], ['id' => $booking_id]);
        }
        do_action('tap_dispute_opened', $id, $booking_id, (int) $booking->agency_id);
        return $id;
    }

    /**
     * Resolve an open dispute and apply the commission outcome.
     *
     * @param int    $dispute_id tap_disputes row id.
     * @param string $outcome    for_agency | against_agency | withdrawn.
     * @param string $note       resolution note.
     * @return true|WP_Error
     */
    public static function resolve($dispute_id, $outcome, $note = '') {
        global $wpdb;
        $dispute = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_disputes WHERE id = %d", $dispute_id));
        if (!$dispute || self::OPEN !== $dispute->status) {
            return new WP_Error('dispute_not_open', __('La disputa no está abierta.', 'travel-agency-platform'));
        }
        if (!in_array($outcome, [self::FOR_AGENCY, self::AGAINST_AGENCY, self::WITHDRAWN], true)) {
            return new WP_Error('invalid_outcome', __('Resultado de la disputa no válido.', 'travel-agency-platform'));
        }
        $booking_id = (int) $dispute->booking_id;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        $wpdb->update(
            $wpdb->prefix . 'tap_disputes',
            [
                'status'          => $outcome,
                'resolution_note' => $note ? sanitize_textarea_field($note) : null,
                'resolved_at'     => current_time('mysql'),
            ],
            ['id' => $dispute_id]
        );
        if ($booking && 'disputed' === $booking->commission_status) {
            $next = in_array($outcome, [self::FOR_AGENCY, self::WITHDRAWN], true) ? 'owed' : 'void';
            $wpdb->update($wpdb->prefix . 'tap_bookings', ['commission_status' => $next], ['id' => $booking_id]);
        }
        do_action('tap_dispute_resolved', (int) $dispute_id, $outcome, $booking_id);
        return true;
    }
}