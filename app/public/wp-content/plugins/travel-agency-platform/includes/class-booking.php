<?php
defined('ABSPATH') || exit;

class TAP_Booking {

    /**
     * Allowed booking status transitions (state machine).
     * Keys = current status, values = statuses reachable from it.
     * Filterable via 'tap_booking_status_transitions'.
     */
    public static $transitions = [
        'request'   => ['pending', 'confirmed', 'cancelled'],
        'pending'   => ['confirmed', 'completed', 'cancelled', 'refunded'],
        'confirmed' => ['completed', 'cancelled', 'refunded'],
        'completed' => ['refunded', 'cancelled'],
        'cancelled' => [],
        'refunded'  => [],
    ];

    /*
     * Cancellation policy and refund domain.
     *
     * The implementation lives in TAP_Refunds; these wrappers keep the public
     * TAP_Booking API stable for the plugin and for the test suites.
     */








    /**
     * Supported service type slugs (used by settings and policy resolution).
     */
    public static function service_type_slugs() {
        return [
            'tap_accommodation',
            'tap_tour',
            'tap_transport',
            'tap_car_rental',
            'tap_boat',
            'tap_package',
            'tap_equipment',
        ];
    }

    /**
     * Human readable labels for service types.
     */
    public static function service_type_labels() {
        return [
            'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de coches', 'travel-agency-platform'),
            'tap_boat'          => __('Barco', 'travel-agency-platform'),
            'tap_package'       => __('Paquete', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipo', 'travel-agency-platform'),
        ];
    }

    public static function get_booking_fee($subtotal) {
        $type  = get_option('tap_booking_fee_type', 'none');
        $value = floatval(get_option('tap_booking_fee_value', 0));
        if ('none' === $type || $value <= 0) {
            return 0.0;
        }
        if ('fixed' === $type) {
            return round($value, 2);
        }
        if ('percent' === $type) {
            return round($subtotal * ($value / 100), 2);
        }
        return 0.0;
    }

    /**
     * Booking mode of a listing: 'instant' (direct booking + payment) or
     * 'request' (request-to-book: the agency must first accept the request).
     */
    public static function booking_mode($service_type = '', $service_id = 0) {
        $service_id = intval($service_id);
        if ($service_id && get_post_type($service_id) === sanitize_text_field($service_type)) {
            if (get_post_meta($service_id, '_tap_booking_mode', true) === 'request') {
                return 'request';
            }
        }
        return 'instant';
    }

    /**
     * A booking is payable once the agency has accepted it (status 'pending'
     * after a request, or a direct instant booking) and no payment was made.
     */
    public static function is_payable($booking) {
        if (!$booking) return false;
        if ('paid' === $booking->payment_status) return false;
        return in_array($booking->status, ['pending', 'confirmed'], true);
    }

    /**
     * Front-end redirect a cancelled/request booking should land on after
     * being created or accepted.
     */
    public static function redirect_target($booking) {
        if ($booking && $booking->status === 'request') {
            return home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code));
        }
        return home_url('/checkout?code=' . rawurlencode($booking->booking_code));
    }

    /**
     * Creates a booking from a raw payload and returns its id and code.
     *
     * @param array $data Raw booking payload (service_type, service_id, dates…).
     * @return array{booking_id:int, booking_code:string}|WP_Error
     */
    public static function create($data) {
        global $wpdb;

        // High-entropy booking code with a uniqueness retry loop (the column
        // is UNIQUE, so a birthday collision must never resolve to another
        // booking's voucher).
        $booking_code = self::unique_booking_code();
        $agency_id = self::resolve_agency_id($data['service_type'], $data['service_id']);

        if ($agency_id && !TAP_Approval::is_approved($agency_id)) {
            return new WP_Error('agency_pending', __('La agencia que ofrece este servicio aún está en revisión y no puede recibir reservas.', 'travel-agency-platform'));
        }

        $commission_percent = self::get_agency_commission($agency_id);
        $total = TAP_Booking_Pricing::calculate_price(
            sanitize_text_field($data['service_type']),
            intval($data['service_id']),
            $data['check_in'] ?? '',
            $data['check_out'] ?? '',
            intval($data['adults'] ?? 1),
            intval($data['children'] ?? 0),
            !empty($data['room_id']) ? intval($data['room_id']) : 0
        );

        $subtotal = $total;
        $booking_fee = self::get_booking_fee($subtotal);
        $booking_fee = round(max(0, $booking_fee), 2);
        $total = round($subtotal + $booking_fee, 2);
        $commission_amount = round($subtotal * ($commission_percent / 100), 2);

        $nights = 0;
        if (!empty($data['check_in']) && !empty($data['check_out'])) {
            $d1 = new DateTime($data['check_in']);
            $d2 = new DateTime($data['check_out']);
            $nights = max(1, $d1->diff($d2)->days);
        }

        $room_check_in = !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : '';
        $room_check_out = !empty($data['check_out']) ? sanitize_text_field($data['check_out']) : '';

        $today = current_time('Y-m-d');
        if ($room_check_in && $room_check_in < $today) {
            return new WP_Error('past_date', __('No puedes reservar para una fecha pasada. Revisa la fecha de inicio.', 'travel-agency-platform'));
        }
        if ($room_check_in && $room_check_out && $room_check_out <= $room_check_in) {
            return new WP_Error('bad_range', __('La fecha de salida debe ser posterior a la de entrada.', 'travel-agency-platform'));
        }

        // Guest checkout support: logged-out visitors book with client_id = 0 and
        // their contact data in the guest_* columns (no WordPress account required).
        $client_id = (int) get_current_user_id();
        $guest_email = !empty($data['guest_email']) ? sanitize_email($data['guest_email']) : '';

        if (!$client_id && !is_email($guest_email)) {
            return new WP_Error(
                'guest_email_required',
                __('Debes indicar un correo electrónico válido para completar la reserva.', 'travel-agency-platform')
            );
        }

        // Serialize the availability check + insert per room/tour slot so two
        // simultaneous bookings cannot both pass the COUNT() pre-check and
        // oversell the same dates (advisory MySQL lock, always released).
        $locks = [];
        if (!empty($data['room_id']) && $room_check_in && $room_check_out) {
            $locks[] = 'tap_room_' . intval($data['room_id']);
        }
        if (sanitize_text_field($data['service_type']) === 'tap_tour' && !empty($data['check_in'])) {
            $locks[] = 'tap_tour_' . intval($data['service_id']) . '_' . sanitize_text_field($data['check_in']);
        }

        foreach ($locks as $lock_key) {
            $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_key));
        }

        $create_error = null;
        $booking_id   = 0;

        try {
            if (!empty($data['room_id']) && $room_check_in && $room_check_out) {
                $table = $wpdb->prefix . 'tap_bookings';
                $overlap = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table
                     WHERE room_id = %d
                       AND status NOT IN ('cancelled', 'refunded')
                       AND check_in < %s AND check_out > %s",
                    intval($data['room_id']),
                    $room_check_out,
                    $room_check_in
                ));

                $inventory = intval(get_post_meta(intval($data['room_id']), '_tap_room_inventory', true)) ?: 1;
                if (intval($overlap) >= $inventory) {
                    $create_error = new WP_Error('room_unavailable', __('The room is not available for the selected dates.', 'travel-agency-platform'));
                } else {
                    $avail = TAP_Booking_Pricing::get_room_availability(intval($data['room_id']), $room_check_in, $room_check_out);
                    if (!$avail['available']) {
                        $create_error = new WP_Error('dates_full', __('No hay disponibilidad para las fechas seleccionadas.', 'travel-agency-platform'));
                    }
                }
            }

            if (!$create_error && sanitize_text_field($data['service_type']) === 'tap_tour') {
                $check_date = !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : '';
                $guests = intval($data['adults'] ?? 1) + intval($data['children'] ?? 0);
                $slots = self::tour_slots(intval($data['service_id']), $check_date);
                if ($slots['booked'] + $guests > $slots['capacity']) {
                    $create_error = new WP_Error(
                        'tour_full',
                        /* translators: 1: cupos disponibles, 2: cupos solicitados */
                        sprintf(__('El tour está completo para la fecha seleccionada. Quedan %1$d cupos y solicitaste %2$d.', 'travel-agency-platform'), $slots['remaining'], $guests)
                    );
                }
            }

            if (!$create_error) {
                $result = $wpdb->insert(
                $wpdb->prefix . 'tap_bookings',
                [
                    'booking_code'      => $booking_code,
                    'agency_id'         => $agency_id,
                    'client_id'         => $client_id,
                    'service_type'      => sanitize_text_field($data['service_type']),
                    'service_id'        => intval($data['service_id']),
                    'room_id'           => !empty($data['room_id']) ? intval($data['room_id']) : null,
                    'package_id'        => !empty($data['package_id']) ? intval($data['package_id']) : null,
                    'check_in'          => !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : null,
                    'check_out'         => !empty($data['check_out']) ? sanitize_text_field($data['check_out']) : null,
                    'adults'            => intval($data['adults'] ?? 1),
                    'children'          => intval($data['children'] ?? 0),
                    'nights'            => $nights,
                    'total_amount'      => $total,
                    'booking_fee'       => $booking_fee,
                    'commission_amount' => $commission_amount,
                    'commission_percent'=> $commission_percent,
                    'status'            => self::booking_mode($data['service_type'], $data['service_id']) === 'request' ? 'request' : 'pending',
                    'payment_status'    => 'pending',
                    'notes'             => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                    'guest_name'        => !empty($data['guest_name']) ? sanitize_text_field($data['guest_name']) : null,
                    'guest_email'       => !empty($data['guest_email']) ? sanitize_email($data['guest_email']) : null,
                    'guest_phone'       => !empty($data['guest_phone']) ? sanitize_text_field($data['guest_phone']) : null,
                    'created_at'        => current_time('mysql'),
                ]
				);

				if ($result === false) {
                    $create_error = new WP_Error('booking_error', __('Could not create booking', 'travel-agency-platform'));
                } else {
                    $booking_id = $wpdb->insert_id;

                    // Line items are NOT written from client input: a booking
                    // covers a single service whose authoritative amount is the
                    // server-computed total_amount/subtotal above, and there is
                    // no server-side cart to validate per-item prices. Trusting
                    // caller-supplied unit_price/subtotal would let a client
                    // forge the voucher breakdown. Internal callers that need
                    // line items must write them from server-derived prices.
                }
            }
        } finally {
            foreach (array_reverse($locks) as $lock_key) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_key));
            }
        }

        if ($create_error) {
            return $create_error;
        }

        do_action('tap_booking_created', $booking_id, $data);

        // Only auto-confirm when the listing accepts direct bookings. A
        // request-to-book listing must await the agency's approval; skipping
        // this would bypass the request workflow entirely.
        if (get_option('tap_booking_auto_confirm', '0') === '1'
            && self::booking_mode($data['service_type'], $data['service_id']) !== 'request') {
            self::update_status($booking_id, 'confirmed');
        }

        return [
            'booking_id'   => $booking_id,
            'booking_code' => $booking_code,
            'status'       => $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id)) ?: 'pending',
            'message'      => __('Booking created successfully', 'travel-agency-platform'),
        ];
    }

    public static function cancel_stale_bookings() {
        global $wpdb;
        $table  = $wpdb->prefix . 'tap_bookings';
        $hours  = (int) apply_filters('tap_stale_booking_hours', get_option('tap_stale_booking_hours', 24));
        if ($hours < 1) return;

        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - $hours * HOUR_IN_SECONDS);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status IN ('pending', 'request') AND created_at < %s",
            $cutoff
        ));

        foreach ($ids as $id) {
            $booking = self::get_booking((int) $id);
            if (!$booking) {
                continue;
            }
            $reason = ('request' === $booking->status)
                ? __('La agencia no respondió a tu solicitud dentro del plazo y se canceló automáticamente.', 'travel-agency-platform')
                : __('La reserva se canceló automáticamente por no confirmarse dentro del plazo.', 'travel-agency-platform');
            if ('paid' === $booking->payment_status) {
                self::update_status((int) $id, 'cancelled', $reason);
            } else {
                self::apply_cancellation((int) $id, $booking, 'system', 'cancelled', $reason);
            }
        }
    }

    public static function tour_slots($service_id, $date = '') {
        global $wpdb;
        $cap = max(1, intval(get_post_meta($service_id, '_tap_tour_capacity', true)));
        $booked = 0;
        if ($date) {
            $booked = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(adults + children), 0) FROM {$wpdb->prefix}tap_bookings WHERE service_type = 'tap_tour' AND service_id = %d AND check_in = %s AND status NOT IN ('cancelled', 'refunded', 'request')",
                $service_id, $date
            )));
        }
        return [
            'capacity'  => $cap,
            'booked'    => $booked,
            'remaining' => max(0, $cap - $booked),
        ];
    }

    public static function client_cancel_request($booking_id, $user_id = 0, $guest_email = '', $booking_code = '') {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();

        $booking = null;
        if ($user_id) {
            // Logged-in: the numeric id is a fine handle for the owner.
            $booking = self::get_booking($booking_id);
            if (!$booking) {
                return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
            }
            if ((int) $booking->client_id !== (int) $user_id) {
                return new WP_Error('forbidden', __('No tienes permiso para cancelar esta reserva.', 'travel-agency-platform'));
            }
        } else {
            // Guest flow. The printed booking code is high entropy and
            // unguessable, so it is the preferred handle; a numeric id alone
            // (enumerable) is never enough on its own.
            $guest_email = sanitize_email($guest_email);
            $booking_code = sanitize_text_field((string) $booking_code);
            if ($booking_code) {
                $booking = self::get_booking_by_code($booking_code);
                if (!$booking) {
                    return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
                }
                $booking_id = (int) $booking->id;
            } else {
                $booking = self::get_booking($booking_id);
                if (!$booking) {
                    return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
                }
            }
            if ((int) $booking->client_id !== 0) {
                return new WP_Error('no_user', __('Debes iniciar sesión.', 'travel-agency-platform'));
            }
            if (!$guest_email || strcasecmp($guest_email, (string) $booking->guest_email) !== 0) {
                return new WP_Error('no_email', __('Debes verificar el correo de la reserva para cancelarla.', 'travel-agency-platform'));
            }
        }

        if (!in_array($booking->status, ['pending', 'confirmed', 'request'], true)) {
            return new WP_Error('bad_status', __('Esta reserva ya no puede cancelarse.', 'travel-agency-platform'));
        }
        if ($booking->check_in && $booking->check_in < current_time('Y-m-d')) {
            return new WP_Error('started', __('La reserva ya comenzó.', 'travel-agency-platform'));
        }

        return self::apply_cancellation($booking_id, $booking, 'client');
    }

    /**
     * Shared cancellation side-effects used by client and agency flows:
     * applies the policy penalty, flips payment_status to refunded when a
     * refund is actually due, voids any outstanding commission and fires the
     * lifecycle hook. Callers are responsible for ownership/business guards.
     *
     * @param string $target_status 'cancelled' (default) or 'refunded'. When
     *                              'refunded' is requested but no money was
     *                              actually returned, the booking falls back
     *                              to 'cancelled' to keep the ledger honest.
     */
    private static function apply_cancellation($booking_id, $booking, $cancelled_by, $target_status = 'cancelled', $reason = '') {
        global $wpdb;

        $prev_status = $booking->status;

        // CAS: atomically claim the cancellation so a concurrent client+agency
        // cancel (or a double click) can only ever execute the refund path once.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tap_bookings SET status = 'cancelled', cancel_requested_at = %s, cancelled_by = %s WHERE id = %d AND status = %s",
            current_time('mysql'),
            $cancelled_by,
            (int) $booking_id,
            $prev_status
        ));
        if (!$claimed) {
            return new WP_Error('bad_status', __('Esta reserva ya no puede cancelarse.', 'travel-agency-platform'));
        }

        $refund = TAP_Refunds::cancellation_refund($booking);

        // Refund the money for real before marking the ledger as refunded.
        // When the gateway refund fails the booking stays cancelled but the
        // payment_status remains 'paid' and _tap_refund_error/_tap_refund_pending
        // record why so the nightly cron can retry and reconcile. The pending
        // marker is written before the gateway call so a fatal mid-refund is
        // still reconcilable.
        $refund_success = true;
        if ('paid' === $booking->payment_status && (float) $refund['refund_amount'] > 0) {
            update_post_meta($booking_id, '_tap_refund_pending', current_time('mysql'));
            $refund_result = TAP_Refunds::execute_refund($booking_id, $booking, $refund['refund_amount']);
            if (is_wp_error($refund_result)) {
                $refund_success = false;
            } else {
                delete_post_meta($booking_id, '_tap_refund_pending');
            }
        }

        $money_returned = 'paid' === $booking->payment_status && (float) $refund['refund_amount'] > 0 && $refund_success;
        $final_status   = ('refunded' === $target_status)
            ? ($money_returned ? 'refunded' : 'cancelled')
            : 'cancelled';

        $new_amount_paid = $money_returned
            ? round(max(0.0, (float) ($booking->amount_paid ?? 0) - (float) $refund['refund_amount']), 2)
            : (float) ($booking->amount_paid ?? 0);

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            [
                'status'               => $final_status,
                'cancel_requested_at'  => current_time('mysql'),
                'cancelled_by'         => $cancelled_by,
                'cancel_reason'        => $reason !== '' ? $reason : null,
                'cancellation_policy'  => $refund['policy'],
                'refund_amount'        => round($refund['refund_amount'], 2),
                'refund_percent'       => $refund['refund_percent'],
                'refunded_at'          => $money_returned ? current_time('mysql') : null,
                'amount_paid'          => $new_amount_paid,
            ],
            ['id' => $booking_id]
        );

        if ($money_returned) {
            self::update_payment_status($booking_id, 'refunded');
            do_action('tap_payment_refunded', $booking_id, 'paypal', get_post_meta($booking_id, '_tap_paypal_refund_id', true));
        }

        // A cancelled booking must never keep earning commission; force so even
        // already-paid commissions stop accruing value for this booking.
        self::void_commission($booking_id, true);

        do_action('tap_booking_status_updated', $booking_id, $final_status, $prev_status);
        do_action('tap_booking_cancelled', $booking_id, $booking);
        return true;
    }

    /**
     * Agency back-office booking operations (Fase 16 / T9).
     * Acts on a booking owned by the caller's agency. Supported ops:
     *   confirm   request|pending -> confirmed
     *   complete  confirmed        -> completed
     *   mark_paid pending          -> paid  (triggers commission earning)
     *   cancel    request|pending|confirmed -> cancelled with policy penalty
     */
    public static function agency_booking_action($booking_id, $action, $user_id = 0, $reason = '') {
        $user_id = $user_id ?: get_current_user_id();
        $user    = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('no_user', __('Debes iniciar sesión.', 'travel-agency-platform'));
        }

        $booking = self::get_booking(intval($booking_id));
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }

        $is_admin = user_can($user, 'manage_options');
        // Employees act with the same booking operations as the agency admin
        // (confirm/complete/cancel) once they are mapped to the booking's
        // agency; mark_paid below stays platform-admin only.
        $user_agency = (int) self::get_agency_for_user($user_id);
        if (!$is_admin && !$user_agency) {
            return new WP_Error('no_agency', __('Solo cuentas de agencia pueden operar reservas.', 'travel-agency-platform'));
        }
        if (!$is_admin && (int) $booking->agency_id !== $user_agency) {
            return new WP_Error('forbidden', __('No tienes permiso sobre esta reserva.', 'travel-agency-platform'));
        }

        switch ($action) {
            case 'confirm':
                return self::update_status($booking->id, 'confirmed');
            case 'complete':
                return self::update_status($booking->id, 'completed');
            case 'mark_paid':
                if (!$is_admin) {
                    return new WP_Error('forbidden', __('No tienes permiso sobre esta reserva.', 'travel-agency-platform'));
                }
                if (!in_array($booking->status, ['confirmed', 'completed'], true)) {
                    return new WP_Error('bad_status', __('Invalid status', 'travel-agency-platform'));
                }
                if (!in_array($booking->payment_status, ['pending', 'partial', 'failed', 'paid'], true)) {
                    return new WP_Error('invalid_status', __('Invalid payment status', 'travel-agency-platform'));
                }
                if ('paid' === $booking->payment_status) {
                    return true;
                }
                global $wpdb;
                $updated = $wpdb->update(
                    $wpdb->prefix . 'tap_bookings',
                    ['payment_status' => 'paid', 'amount_paid' => round((float) $booking->total_amount, 2)],
                    ['id' => $booking->id, 'status' => $booking->status, 'payment_status' => $booking->payment_status]
                );
                if (1 !== $updated) {
                    return new WP_Error('payment_update_failed', __('Invalid payment status', 'travel-agency-platform'));
                }
                do_action('tap_payment_completed', $booking->id, 'office', '');
                return true;
            case 'cancel':
                if (!in_array($booking->status, ['pending', 'confirmed', 'request'], true)) {
                    return new WP_Error('bad_status', __('Esta reserva ya no puede cancelarse.', 'travel-agency-platform'));
                }
                if ($booking->check_in && $booking->check_in < current_time('Y-m-d')) {
                    return new WP_Error('started', __('La reserva ya comenzó.', 'travel-agency-platform'));
                }
                return self::apply_cancellation($booking->id, $booking, 'agency', 'cancelled', $reason);
            default:
                return new WP_Error('invalid_action', __('Operación inválida.', 'travel-agency-platform'));
        }
    }

    public static function update_status($booking_id, $status, $reason = '') {
        global $wpdb;

        $valid_statuses = ['pending', 'request', 'confirmed', 'cancelled', 'completed', 'refunded'];
        if (!in_array($status, $valid_statuses, true)) {
            return new WP_Error('invalid_status', __('Invalid status', 'travel-agency-platform'));
        }

        $prev = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        $booking = $prev ? self::get_booking($booking_id) : null;
        if (!$booking) {
            return new WP_Error('not_found', __('Booking not found', 'travel-agency-platform'));
        }

        if ($status === $prev) {
            return true;
        }

        $transitions = apply_filters('tap_booking_status_transitions', self::$transitions);
        $allowed = $transitions[$prev] ?? [];
        if (!in_array($status, $allowed, true)) {
            return new WP_Error(
                'invalid_transition',
                sprintf(
                    /* translators: 1: estado actual de la reserva, 2: estado solicitado */
                    __('No se puede pasar el estado de "%1$s" a "%2$s".', 'travel-agency-platform'),
                    $prev,
                    $status
                )
            );
        }

        // A paid, completed booking must never be side-stepped to 'cancelled':
        // the only valid destination is 'refunded' so the money goes back
        // through the gateway. Unpaid completed bookings may still cancel.
        if ('completed' === $prev && 'cancelled' === $status && 'paid' === $booking->payment_status) {
            return new WP_Error('paid_completed', __('Una reserva pagada y completada no puede cancelarse: debe reembolsarse (estado "refunded").', 'travel-agency-platform'));
        }

        // Money-aware cancellation: a paid booking must never be cancelled on
        // paper without returning the client's money first. This routes the
        // whole cancellation (policy refund + ledger + commission voiding)
        // through apply_cancellation() so no caller can skip the refund.
        if (in_array($status, ['cancelled', 'refunded'], true) && 'paid' === $booking->payment_status) {
            return self::apply_cancellation($booking_id, $booking, 'system', $status, $reason);
        }

        $data = ['status' => $status];
        if (in_array($status, ['cancelled', 'refunded'], true) && $reason !== '') {
            $data['cancel_reason'] = $reason;
        }
        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            $data,
            ['id' => $booking_id]
        );

        // Cancelling / refunding a booking voids any still-owed commission.
        if (in_array($status, ['cancelled', 'refunded'], true)) {
            self::void_commission($booking_id, true);
        }

        do_action('tap_booking_status_updated', $booking_id, $status, $prev);

        return true;
    }

    /**
     * Nightly reconciliation for refunds that failed at the gateway. Finds
     * paid-but-cancelled bookings flagged with a refund error and retries the
     * gateway refund; on success the ledger marks the payment refunded.
     */
    public static function retry_pending_refunds() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}tap_bookings
             WHERE payment_status = 'paid' AND status IN ('cancelled', 'refunded')
             ORDER BY updated_at ASC LIMIT 50"
        );

        foreach ($rows as $row) {
            $booking_id = (int) $row->id;
            $booking    = self::get_booking($booking_id);
            if (!$booking || !get_post_meta($booking_id, '_tap_refund_error', true)) {
                continue;
            }

            $amount = (float) ($booking->refund_amount ?? 0);
            if ($amount <= 0) {
                $amount = TAP_Refunds::cancellation_refund($booking)['refund_amount'];
            }
            if ($amount <= 0) {
                continue;
            }

            $result = TAP_Refunds::execute_refund($booking_id, $booking, $amount);
            if (is_wp_error($result)) {
                continue;
            }

            delete_post_meta($booking_id, '_tap_refund_pending');
            delete_post_meta($booking_id, '_tap_refund_error');
            self::update_payment_status($booking_id, 'refunded');
            $wpdb->update(
                $wpdb->prefix . 'tap_bookings',
                ['refunded_at' => current_time('mysql')],
                ['id' => $booking_id]
            );
            do_action('tap_payment_refunded', $booking_id, 'paypal', get_post_meta($booking_id, '_tap_paypal_refund_id', true));
            do_action('tap_booking_refund_retried', $booking_id);
        }
    }

    /**
     * Commission can only become payable once the client has actually paid.
     * Called on successful payment capture.
     */
    public static function mark_commission_owed($booking_id) {
        global $wpdb;
        $booking_id = intval($booking_id);
        if (!$booking_id) return;

        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT commission_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $booking_id
        ));

        if (in_array($current, ['owed', 'waiting'], true)) {
            $wpdb->update(
                $wpdb->prefix . 'tap_bookings',
                ['commission_status' => 'owed'],
                ['id' => $booking_id]
            );
        }
    }

    /**
     * Revert a booking's commission to void when it can no longer be collected,
     * but never touch commissions already settled or frozen by a dispute.
     *
     * NOTE: commission_amount is intentionally left untouched so the voided
     * amount stays visible in the "cancelled commissions" audit views
     * (TAP_Dashboard_Money totals, agency panel). Consumers that report
     * commission MUST therefore filter on the status: reporting uses
     * `commission_status <> 'void'` over active bookings (TAP_Booking_Stats,
     * TAP_Dashboard_Content), while settlement uses the stricter
     * `commission_status = 'owed' AND payment_status = 'paid'` (TAP_Payouts).
     *
     * @param bool $force When true, also voids 'paid' commissions (used when a
     *                    paid booking is cancelled/refunded so the commission
     *                    stops accruing; a pending payout covering this booking
     *                    reconciles its amount against the still-eligible set
     *                    when TAP_Payouts::complete() runs).
     */
    public static function void_commission($booking_id, $force = false) {
        global $wpdb;
        $booking_id = intval($booking_id);
        if (!$booking_id) return;

        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT commission_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $booking_id
        ));

        $targets = ['owed', 'waiting'];
        if ($force) {
            $targets[] = 'paid';
        }

        if (in_array($current, $targets, true)) {
            $wpdb->update(
                $wpdb->prefix . 'tap_bookings',
                ['commission_status' => 'void'],
                ['id' => $booking_id]
            );
        }
    }

    /**
     * Auto-complete bookings whose stay has already ended so the flow
     * (reviews, settlement) can move on without manual agency action.
     */
    public static function complete_past_bookings() {
        if (get_option('tap_booking_auto_complete', '1') !== '1') {
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_bookings
             WHERE status = 'confirmed' AND payment_status = 'paid'
               AND check_out IS NOT NULL AND check_out < %s",
            current_time('Y-m-d')
        ));

        foreach ($ids as $id) {
            self::update_status(intval($id), 'completed');
        }
    }

    /**
     * Whether a booking's commission is payable right now
     * (paid by the client and not cancelled/refunded).
     */
    public static function is_commission_payable($booking) {
        if (!$booking) return false;
        if ($booking->commission_status !== 'owed') return false;
        if ($booking->payment_status !== 'paid') return false;
        return !in_array($booking->status, ['cancelled', 'refunded'], true);
    }

    /**
     * Updates the payment status of a booking.
     *
     * @return true|WP_Error
     */
    public static function update_payment_status($booking_id, $payment_status) {
        global $wpdb;

        $valid_statuses = ['pending', 'paid', 'partial', 'refunded', 'failed'];
        if (!in_array($payment_status, $valid_statuses, true)) {
            return new WP_Error('invalid_status', __('Invalid payment status', 'travel-agency-platform'));
        }

        if (!self::get_booking((int) $booking_id)) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['payment_status' => $payment_status],
            ['id' => $booking_id]
        );
        if (false === $updated) {
            return new WP_Error('db_error', __('No se pudo actualizar el estado del pago.', 'travel-agency-platform'));
        }

        return true;
    }

    /**
     * Record a captured online payment (PayPal capture completed).
     *
     * Atomic + idempotent: the row update only succeeds when the booking is
     * still unpaid, so a replayed webhook, ajax retry or booker double click
     * can never mark an already paid booking as paid again or re-fire the
     * 'tap_payment_completed' event (which re-earns the agency commission).
     *
     * @return true|WP_Error true when the booking is now fully paid; WP_Error
     *                       with a machine-readable code (not_found,
     *                       auto_refunded, already_paid, currency_mismatch,
     *                       partial, lost_race) otherwise.
     */
    public static function record_paid_capture($booking_id, $method, $capture_id = '', $details = null) {
        global $wpdb;

        $booking = self::get_booking((int) $booking_id);
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }

        // A charge arriving for a booking that the system already cancelled
        // means real money hit PayPal for a dead reservation: give it back
        // immediately instead of swallowing the capture.
        if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
            if ($capture_id) {
                self::auto_refund_capture(
                    (int) $booking_id,
                    $capture_id,
                    __('La reserva fue cancelada antes de procesar la captura; el pago fue reembolsado automáticamente.', 'travel-agency-platform')
                );
            }
            return new WP_Error('auto_refunded', __('La reserva ya no existe: el pago fue reembolsado automáticamente.', 'travel-agency-platform'));
        }

        if (in_array($booking->payment_status, ['paid', 'refunded'], true)) {
            return new WP_Error('already_paid', __('Esta reserva ya tiene un pago registrado.', 'travel-agency-platform'));
        }

        $table = $wpdb->prefix . 'tap_bookings';

        // Verify the captured amount and currency against the stored total.
        // A shortfall becomes a 'partial' payment (no full commission earned),
        // a currency mismatch is refunded back to the client on the spot.
        $expected = round((float) $booking->total_amount, 2);
        $captured = self::capture_amount($details);
        if ($captured) {
            $currency_ok = empty($captured['currency_code'])
                || strtoupper((string) $captured['currency_code']) === strtoupper((string) TAP_Currency::code());
            if (!$currency_ok) {
                self::auto_refund_capture(
                    (int) $booking_id,
                    $capture_id,
                    __('La moneda del pago capturado no coincide con la moneda configurada; el pago fue reembolsado automáticamente.', 'travel-agency-platform')
                );
                $wpdb->update($table, ['payment_status' => 'failed'], ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]);
                do_action('tap_payment_failed', (int) $booking_id, $method, $capture_id);
                return new WP_Error('currency_mismatch', __('La moneda del pago no coincide; el pago fue reembolsado automáticamente.', 'travel-agency-platform'));
            }

            if (null !== $captured['value'] && $captured['value'] < $expected - 0.005) {
                // Partial capture (funding shortfall / rounding): record the
                // details but keep the booking unpaid — no full commission —
                // unless the accumulated sum already covers the remainder.
                $paid_so_far = round((float) ($booking->amount_paid ?? 0) + $captured['value'], 2);
                if ($paid_so_far >= $expected - 0.005) {
                    $completed = $wpdb->update(
                        $table,
                        ['payment_status' => 'paid', 'amount_paid' => $expected],
                        ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]
                    );
                    if (1 !== $completed) {
                        return new WP_Error('lost_race', __('El pago se registró en otra petición.', 'travel-agency-platform'));
                    }
                    if ($capture_id) {
                        update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
                    }
                    if (null !== $details) {
                        $payload = is_string($details) ? $details : wp_json_encode($details, JSON_UNESCAPED_UNICODE);
                        update_post_meta((int) $booking_id, '_tap_payment_details', $payload);
                    }
                    self::update_status((int) $booking_id, 'confirmed');
                    do_action('tap_payment_completed', (int) $booking_id, $method, $capture_id);
                    return true;
                }
                $wpdb->update(
                    $table,
                    ['payment_status' => 'partial', 'amount_paid' => $paid_so_far],
                    ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]
                );
                if ($capture_id) {
                    update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
                }
                if (null !== $details) {
                    $payload = is_string($details) ? $details : wp_json_encode($details, JSON_UNESCAPED_UNICODE);
                    update_post_meta((int) $booking_id, '_tap_payment_details', $payload);
                }
                return new WP_Error('partial', __('Pago parcial recibido; la reserva aún no está pagada por completo.', 'travel-agency-platform'));
            }
        }

        $updated = $wpdb->update(
            $table,
            ['payment_status' => 'paid', 'amount_paid' => $expected],
            ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]
        );
        if (1 !== $updated) {
            return new WP_Error('lost_race', __('El pago se registró en otra petición.', 'travel-agency-platform'));
        }

        self::update_status((int) $booking_id, 'confirmed');
        if ($capture_id) {
            update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
        }
        if (null !== $details) {
            $payload = is_string($details) ? $details : wp_json_encode($details, JSON_UNESCAPED_UNICODE);
            update_post_meta((int) $booking_id, '_tap_payment_details', $payload);
        }
        do_action('tap_payment_completed', (int) $booking_id, $method, $capture_id);
        return true;
    }

    /**
     * Extract captured {value, currency_code} from either a full capture
     * response (AJAX) or a webhook capture resource.
     */
    public static function capture_amount($details) {
        if (is_string($details)) {
            $details = json_decode($details);
        }
        if (!is_object($details) && !is_array($details)) {
            return null;
        }
        $details = (object) $details;

        if (isset($details->amount) && isset($details->amount->value)) {
            return [
                'value'         => (float) $details->amount->value,
                'currency_code' => $details->amount->currency_code ?? '',
            ];
        }
        if (isset($details->purchase_units) && is_array($details->purchase_units) && isset($details->purchase_units[0])) {
            $capture = $details->purchase_units[0]->payments->captures[0] ?? null;
            if ($capture && isset($capture->amount) && isset($capture->amount->value)) {
                return [
                    'value'         => (float) $capture->amount->value,
                    'currency_code' => $capture->amount->currency_code ?? '',
                ];
            }
        }
        return null;
    }

    /**
     * Immediately return a capture that should never have been charged. Keeps
     * a record and flags the booking for nightly reconciliation when the
     * gateway refund itself fails.
     */
    private static function auto_refund_capture($booking_id, $capture_id, $reason) {
        if (!$capture_id || !class_exists('TAP_PayPal') || !TAP_PayPal::is_ready()) {
            return;
        }
        update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
        $result = TAP_PayPal::refund_capture($capture_id);
        if (is_wp_error($result)) {
            update_post_meta((int) $booking_id, '_tap_refund_error', '[' . $reason . '] ' . $result->get_error_message());
            update_post_meta((int) $booking_id, '_tap_refund_pending', current_time('mysql'));
            return;
        }
        update_post_meta((int) $booking_id, '_tap_paypal_refund_id', $result['refund_id']);
        // Success must mirror TAP_Refunds::execute_refund(): clear the error so
        // the nightly retry cron (retry_pending_refunds) never refunds this
        // capture a second time.
        delete_post_meta((int) $booking_id, '_tap_refund_pending');
        update_post_meta((int) $booking_id, '_tap_refund_error', '');
    }

    public static function get_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $booking_id
        ));
    }

    public static function get_booking_by_code($code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s",
            $code
        ));
    }

    public static function get_latest_bookings($limit = 8) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    /** Service types that accept a verified review from a client. */
    public static function reviewable_types() {
        return ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
    }

    /**
     * "type:id" markers of services the given user already reviewed. Cached
     * per request so the bookings list does not re-query per row.
     *
     * @return string[]
     */
    public static function reviewed_markers($user_id) {
        static $cache = [];
        $user_id = (int) $user_id;
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }
        global $wpdb;
        $cache[$user_id] = $wpdb->get_col($wpdb->prepare(
            "SELECT CONCAT(service_type, ':', service_id) FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d",
            $user_id
        ));
        return $cache[$user_id];
    }

    /** True when the client can still post a verified review for this booking. */
    public static function can_review_booking($booking, $markers = null) {
        $markers = (null === $markers) ? self::reviewed_markers(get_current_user_id()) : $markers;
        return 'completed' === $booking->status
            && in_array($booking->service_type, self::reviewable_types(), true)
            && get_post($booking->service_id)
            && !in_array($booking->service_type . ':' . $booking->service_id, $markers, true);
    }

    /** True when the client is allowed to cancel this booking. */
    public static function can_cancel_booking($booking) {
        return in_array($booking->status, ['pending', 'confirmed', 'request'], true)
            && (!$booking->check_in || $booking->check_in >= current_time('Y-m-d'));
    }

    /** True when the given user owns or manages the booking (client, admin or its agency). */
    public static function user_can_manage_booking($user_id, $booking) {
        $booking = (object) $booking;
        $user_id = (int) $user_id;
        $can = (int) $booking->client_id === $user_id || user_can($user_id, 'manage_options');
        if (!$can && !empty($booking->agency_id)) {
            $agency = self::get_agency_for_user($user_id);
            $can = $agency && (int) $agency === (int) $booking->agency_id;
        }
        return $can;
    }

    /** Amount already paid toward this booking (partial captures included). */
    public static function amount_already_paid($booking) {
        return round((float) ($booking->amount_paid ?? 0), 2);
    }

    /** Outstanding balance the client still owes for this booking. */
    public static function remaining_amount($booking) {
        return round(max(0.0, (float) $booking->total_amount - self::amount_already_paid($booking)), 2);
    }

    public static function get_client_bookings($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE client_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    public static function get_agency_bookings($agency_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $agency_id,
            $limit,
            $offset
        ));
    }

    public static function get_booking_items($booking_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_booking_items WHERE booking_id = %d",
            $booking_id
        ));
    }

    /**
     * Items for many bookings in one query, grouped by booking id (empty arrays
     * for bookings with no rows). Avoids the per-booking query in listing loops.
     *
     * @return array<int,array<int,object>>
     */
    public static function get_booking_items_for(array $booking_ids) {
        global $wpdb;
        $ids = [];
        foreach ($booking_ids as $id) {
            $id = (int) $id;
            if ($id > 0) $ids[$id] = $id;
        }
        if (!$ids) return [];
        $in = implode(',', $ids);
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}tap_booking_items WHERE booking_id IN ({$in}) ORDER BY booking_id ASC, id ASC"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->booking_id][] = $row;
        }
        return $out;
    }

    public static function get_agency_bookings_count($agency_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d",
            $agency_id
        ));
    }

    public static function get_client_bookings_count($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE client_id = %d",
            $user_id
        ));
    }

    public static function generate_booking_code() {
        // 12 chars + date ≈ 71 bits of entropy (vs the old 24-bit md5 prefix).
        $prefix = 'TAP';
        $timestamp = strtoupper(wp_generate_password(12, false, false));
        return $prefix . '-' . $timestamp . '-' . date('ymd');
    }

    /**
     * Returns a booking code that is not already used. UNIQUE constraint on
     * booking_code makes a race safe; here we only pre-empt the common case.
     */
    private static function unique_booking_code() {
        global $wpdb;
        for ($i = 0; $i < 8; $i++) {
            $code = self::generate_booking_code();
            $used = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s LIMIT 1",
                $code
            ));
            if (!$used) {
                return $code;
            }
        }
        return self::generate_booking_code();
    }

    private static function resolve_agency_id($service_type, $service_id) {
        $meta_key = TAP_Post_Types::agency_meta_key($service_type);

        if ($meta_key) {
            $agency_id = get_post_meta($service_id, $meta_key, true);
            if ($agency_id) return intval($agency_id);
        }

        return 0;
    }

    public static function get_agency_commission($agency_id) {
        if (!$agency_id) return 10;
        if (class_exists('TAP_Subscriptions')) {
            $rate = TAP_Subscriptions::commission_rate_for_agency($agency_id);
            if ($rate !== null) {
                return $rate;
            }
        }
        $commission = get_post_meta($agency_id, '_tap_agency_commission', true);
        if ($commission) {
            return floatval($commission);
        }
        // Falling back to the admin-configured default instead of a hardcoded
        // rate: agencies registered before a settings change pick it up too.
        $default = get_option('tap_commission_default', '');
        return (is_numeric($default) && (float) $default >= 0) ? (float) $default : 10;
    }


    /**
     * Find the tap_agency post linked to a WordPress user.
     *
     * Resolution order: the agency owner (`_tap_agency_user_id` post meta on
     * the agency), then an agency employee (`_tap_agency_id` user meta written
     * when the agency admin adds them to the team).
     */
    public static function get_agency_for_user($user_id = null) {
        if (!$user_id) $user_id = get_current_user_id();
        if (!$user_id) return 0;

        $posts = get_posts([
            'post_type'      => 'tap_agency',
            'post_status'    => 'any',
            'meta_key'       => '_tap_agency_user_id',
            'meta_value'     => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if ($posts) {
            return intval($posts[0]);
        }

        // Employee fallback: staff users carry the agency in their user meta.
        $staff_agency = (int) get_user_meta($user_id, '_tap_agency_id', true);
        if ($staff_agency > 0 && 'tap_agency' === get_post_type($staff_agency)) {
            return $staff_agency;
        }

        // Recovery for agency-role users whose agency lost the owner link
        // (legacy rows, agencies created by hand from wp-admin). Without this
        // they are locked out of their own panel. Match by authorship and heal
        // the missing `_tap_agency_user_id` so later calls take the fast path.
        $user = get_userdata($user_id);
        if ($user && array_intersect(['tap_agency_admin', 'tap_agency_employee'], (array) $user->roles)) {
            $owned = get_posts([
                'post_type'      => 'tap_agency',
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
                'author'         => $user_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            if ($owned) {
                $agency_id = (int) $owned[0];
                if ('' === (string) get_post_meta($agency_id, '_tap_agency_user_id', true)) {
                    update_post_meta($agency_id, '_tap_agency_user_id', $user_id);
                }
                return $agency_id;
            }
        }

        return 0;
    }

    /**
     * Staff users (employees) mapped to an agency via the `_tap_agency_id`
     * user meta. The agency owner is not part of this list.
     *
     * @return array<int, WP_User>
     */
    public static function get_staff_for_agency($agency_id) {
        $agency_id = (int) $agency_id;
        if (!$agency_id) {
            return [];
        }
        return get_users([
            'meta_key'   => '_tap_agency_id',
            'meta_value' => $agency_id,
            'number'     => 100,
            'orderby'    => 'display_name',
        ]);
    }
}
