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

    /**
     * Supported cancellation policies with their human labels.
     */
    public static function cancellation_policies() {
        return [
            'flexible'       => __('Flexible — reembolso total hasta 24h antes', 'travel-agency-platform'),
            'moderate'       => __('Moderada — reembolso total 5 días antes, 50% hasta 2 días', 'travel-agency-platform'),
            'strict'         => __('Estricta — 50% de reembolso hasta 7 días antes', 'travel-agency-platform'),
            'non_refundable' => __('No reembolsable', 'travel-agency-platform'),
        ];
    }

    /**
     * Default policy per service type (overridable via tap_cancel_policy_* options).
     */
    public static function default_cancellation_policy($service_type = '') {
        $map = [
            'tap_accommodation' => 'flexible',
            'tap_tour'          => 'strict',
            'tap_transport'     => 'moderate',
            'tap_car_rental'    => 'moderate',
            'tap_boat'          => 'strict',
            'tap_package'       => 'strict',
            'tap_equipment'     => 'flexible',
        ];
        return $map[$service_type] ?? 'flexible';
    }

    /**
     * Resolve the effective cancellation policy for a service.
     * Priority: listing meta -> accommodation legacy meta -> per-type option -> 'flexible'.
     */
    public static function cancellation_policy($service_type = '', $service_id = 0) {
        $service_id = intval($service_id);
        $policies = array_keys(self::cancellation_policies());

        if ($service_id && get_post_type($service_id) === $service_type) {
            $meta = get_post_meta($service_id, '_tap_cancellation_policy', true);
            if ($meta && in_array($meta, $policies, true)) {
                return $meta;
            }
            if ($service_type === 'tap_accommodation') {
                $legacy = get_post_meta($service_id, '_tap_acc_cancellation', true);
                if ($legacy && in_array($legacy, $policies, true)) {
                    return $legacy;
                }
            }
        }

        $opt = $service_type ? get_option('tap_cancel_policy_' . $service_type, '') : '';
        if ($opt && in_array($opt, $policies, true)) {
            return $opt;
        }

        return self::default_cancellation_policy($service_type);
    }

    /**
     * Refund percentage (0-100) for a policy given whole days before check-in.
     */
    public static function refund_percent($policy, $days_before) {
        $days_before = intval($days_before);
        switch ($policy) {
            case 'moderate':
                if ($days_before >= 5) return 100;
                if ($days_before >= 2) return 50;
                return 0;
            case 'strict':
                return $days_before >= 7 ? 50 : 0;
            case 'non_refundable':
                return 0;
            case 'flexible':
            default:
                return $days_before >= 1 ? 100 : 0;
        }
    }

    /**
     * Compute the refund a client is entitled to for a (paid) booking,
     * based on the service's cancellation policy and the days left to check-in.
     *
     * @return array{policy:string,days_before:int,refund_percent:int,refund_amount:float}
     */
    public static function cancellation_refund($booking) {
        $policy = self::cancellation_policy($booking->service_type ?? '', (int) ($booking->service_id ?? 0));

        $days_before = PHP_INT_MAX;
        if (!empty($booking->check_in)) {
            $check_in = strtotime((string) $booking->check_in);
            $days_before = $check_in ? (int) floor(($check_in - strtotime(gmdate('Y-m-d'))) / DAY_IN_SECONDS) : PHP_INT_MAX;
            $days_before = max(0, $days_before);
        }

        $pct  = self::refund_percent($policy, $days_before);
        $total = floatval($booking->total_amount ?? 0);

        return [
            'policy'         => $policy,
            'days_before'    => $days_before,
            'refund_percent' => $pct,
            'refund_amount'  => round($total * ($pct / 100), 2),
        ];
    }

    /**
     * Execute a real money refund for a paid booking when the cancellation
     * policy requires it.
     *
     * - Bookings with no stored PayPal capture are treated as offline/manual
     *   payments: there is no gateway amount to reverse, so the ledger simply
     *   records the intended refund (the caller flips payment_status).
     * - Online captures are actually refunded through PayPal so the client's
     *   money really comes back. The result is persisted in
     *   `_tap_paypal_refund_id` or `_tap_refund_error`.
     *
     * @return true|WP_Error  true when refunded (or handled offline);
     *                        WP_Error when the gateway refund failed.
     */
    public static function execute_refund($booking_id, $booking = null, $amount = null) {
        $booking_id = intval($booking_id);
        if (!$booking) {
            $booking = self::get_booking($booking_id);
        }
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }
        if ('paid' !== $booking->payment_status) {
            return true;
        }

        $refund_amount = $amount !== null ? round((float) $amount, 2) : round((float) ($booking->refund_amount ?? 0), 2);
        if ($refund_amount <= 0) {
            update_post_meta($booking_id, '_tap_refund_error', '');
            return true;
        }

        $capture_id = get_post_meta($booking_id, '_tap_paypal_capture_id', true);
        if (!$capture_id) {
            // Payment was not captured online (office/cash/manual): nothing to
            // reverse at the gateway. Treat it as an offline refund.
            delete_post_meta($booking_id, '_tap_refund_error');
            return true;
        }

        $refunded = TAP_PayPal::refund_capture($capture_id, $refund_amount);
        if (is_wp_error($refunded)) {
            update_post_meta($booking_id, '_tap_refund_error', $refunded->get_error_message());
            return $refunded;
        }

        update_post_meta($booking_id, '_tap_paypal_refund_id', $refunded['refund_id']);
        update_post_meta($booking_id, '_tap_refund_error', '');
        return true;
    }

    /**
     * Short human summary of a refund decision (used in emails / dashboards).
     */
    public static function refund_summary($booking) {
        $info = self::cancellation_refund($booking);
        $policies = self::cancellation_policies();
        $label = $policies[$info['policy']] ?? $info['policy'];
        $line = sprintf(
            __('Política de cancelación: %s', 'travel-agency-platform'),
            $label
        );
        $refund = isset($booking->refund_amount) && (float) $booking->refund_amount > 0
            ? floatval($booking->refund_amount)
            : $info['refund_amount'];
        if ($refund > 0) {
            $line .= ' · ' . sprintf(
                __('Reembolso: %s (remaining %d%%)', 'travel-agency-platform'),
                TAP_Currency::fmt($refund),
                $info['refund_percent']
            );
        } else {
            $line .= ' · ' . __('Sin reembolso', 'travel-agency-platform');
        }
        return $line;
    }

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
        $total = self::calculate_price(
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

        $today = gmdate('Y-m-d');
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
                    $avail = self::get_room_availability(intval($data['room_id']), $room_check_in, $room_check_out);
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
                ]
            );

            if ($result === false) {
                    $create_error = new WP_Error('booking_error', __('Could not create booking', 'travel-agency-platform'));
                } else {
                    $booking_id = $wpdb->insert_id;

                    if (!empty($data['items']) && is_array($data['items'])) {
                        foreach ($data['items'] as $item) {
                            $wpdb->insert(
                                $wpdb->prefix . 'tap_booking_items',
                                [
                                    'booking_id'   => $booking_id,
                                    'service_type' => sanitize_text_field($item['service_type']),
                                    'service_id'   => intval($item['service_id']),
                                    'quantity'     => intval($item['quantity'] ?? 1),
                                    'unit_price'   => floatval($item['unit_price']),
                                    'subtotal'     => floatval($item['subtotal']),
                                    'date_from'    => !empty($item['date_from']) ? sanitize_text_field($item['date_from']) : null,
                                    'date_to'      => !empty($item['date_to']) ? sanitize_text_field($item['date_to']) : null,
                                ]
                            );
                        }
                    }
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

        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status = 'pending' AND created_at < %s",
            $cutoff
        ));

        foreach ($ids as $id) {
            self::update_status(intval($id), 'cancelled');
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
        if ($booking->check_in && $booking->check_in < gmdate('Y-m-d')) {
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
    private static function apply_cancellation($booking_id, $booking, $cancelled_by, $target_status = 'cancelled') {
        global $wpdb;

        $prev_status = $booking->status;
        $refund = self::cancellation_refund($booking);

        // Refund the money for real before marking the ledger as refunded.
        // When the gateway refund fails the booking stays cancelled but the
        // payment_status remains 'paid' and _tap_refund_error/_tap_refund_pending
        // record why so the nightly cron can retry and reconcile.
        $refund_success = true;
        if ('paid' === $booking->payment_status && (float) $refund['refund_amount'] > 0) {
            $refund_result = self::execute_refund($booking_id, $booking, $refund['refund_amount']);
            if (is_wp_error($refund_result)) {
                $refund_success = false;
                update_post_meta($booking_id, '_tap_refund_pending', current_time('mysql'));
            } else {
                delete_post_meta($booking_id, '_tap_refund_pending');
            }
        }

        $money_returned = 'paid' === $booking->payment_status && (float) $refund['refund_amount'] > 0 && $refund_success;
        $final_status   = ('refunded' === $target_status)
            ? ($money_returned ? 'refunded' : 'cancelled')
            : 'cancelled';

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            [
                'status'               => $final_status,
                'cancel_requested_at'  => current_time('mysql'),
                'cancelled_by'         => $cancelled_by,
                'cancellation_policy'  => $refund['policy'],
                'refund_amount'        => round($refund['refund_amount'], 2),
                'refund_percent'       => $refund['refund_percent'],
                'refunded_at'          => $money_returned ? current_time('mysql') : null,
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
    public static function agency_booking_action($booking_id, $action, $user_id = 0) {
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
        $is_employee = in_array('tap_agency_employee', (array) $user->roles, true);
        if ($is_employee && !$is_admin) {
            return new WP_Error('no_agency', __('Solo cuentas de agencia pueden operar reservas.', 'travel-agency-platform'));
        }
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
                if ('confirmed' !== $booking->status) {
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
                    ['payment_status' => 'paid'],
                    ['id' => $booking->id, 'status' => 'confirmed', 'payment_status' => $booking->payment_status]
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
                if ($booking->check_in && $booking->check_in < gmdate('Y-m-d')) {
                    return new WP_Error('started', __('La reserva ya comenzó.', 'travel-agency-platform'));
                }
                return self::apply_cancellation($booking->id, $booking, 'agency');
            default:
                return new WP_Error('invalid_action', __('Operación inválida.', 'travel-agency-platform'));
        }
    }

    public static function update_status($booking_id, $status) {
        global $wpdb;

        $valid_statuses = ['pending', 'request', 'confirmed', 'cancelled', 'completed', 'refunded'];
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', __('Invalid status', 'travel-agency-platform'));
        }

        $prev = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        $booking = $prev ? TAP_Booking::get_booking($booking_id) : null;
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
                    __('No se puede pasar el estado de "%1$s" a "%2$s".', 'travel-agency-platform'),
                    $prev,
                    $status
                )
            );
        }

        // Money-aware cancellation: a paid booking must never be cancelled on
        // paper without returning the client's money first. This routes the
        // whole cancellation (policy refund + ledger + commission voiding)
        // through apply_cancellation() so no caller can skip the refund.
        if (in_array($status, ['cancelled', 'refunded'], true) && 'paid' === $booking->payment_status) {
            return self::apply_cancellation($booking_id, $booking, 'system', $status);
        }

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['status' => $status],
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
                $amount = self::cancellation_refund($booking)['refund_amount'];
            }
            if ($amount <= 0) {
                continue;
            }

            $result = self::execute_refund($booking_id, $booking, $amount);
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
     * @param bool $force When true, also voids 'paid' commissions (used when a
     *                    paid booking is cancelled/refunded so the commission
     *                    stops accruing; the payout amount is reconciled by
     *                    TAP_Payouts::complete() for pending payouts).
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
            gmdate('Y-m-d')
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

    public static function update_payment_status($booking_id, $payment_status) {
        global $wpdb;

        $valid_statuses = ['pending', 'paid', 'partial', 'refunded', 'failed'];
        if (!in_array($payment_status, $valid_statuses)) {
            return new WP_Error('invalid_status', __('Invalid payment status', 'travel-agency-platform'));
        }

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['payment_status' => $payment_status],
            ['id' => $booking_id]
        );

        return true;
    }

    /**
     * Record a captured online payment (PayPal capture completed).
     *
     * Atomic + idempotent: the row update only succeeds when the booking is
     * still unpaid, so a replayed webhook, ajax retry or booker double click
     * can never mark an already paid booking as paid again or re-fire the
     * 'tap_payment_completed' event (which re-earns the agency commission).
     */
    public static function record_paid_capture($booking_id, $method, $capture_id = '', $details = null) {
        global $wpdb;

        $booking = self::get_booking((int) $booking_id);
        if (!$booking) {
            return false;
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
            return false;
        }

        if (in_array($booking->payment_status, ['paid', 'refunded'], true)) {
            return false;
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
                return false;
            }

            if (null !== $captured['value'] && $captured['value'] < $expected - 0.005) {
                // Partial capture (funding shortfall / rounding): record the
                // details but keep the booking unpaid — no full commission.
                if ($capture_id) {
                    update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
                }
                if (null !== $details) {
                    $payload = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE);
                    update_post_meta((int) $booking_id, '_tap_payment_details', $payload);
                }
                $wpdb->update($table, ['payment_status' => 'partial'], ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]);
                return false;
            }
        }

        $updated = $wpdb->update(
            $table,
            ['payment_status' => 'paid'],
            ['id' => (int) $booking_id, 'payment_status' => $booking->payment_status]
        );
        if (1 !== $updated) {
            return false;
        }

        self::update_status((int) $booking_id, 'confirmed');
        if ($capture_id) {
            update_post_meta((int) $booking_id, '_tap_paypal_capture_id', $capture_id);
        }
        if (null !== $details) {
            $payload = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE);
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
        update_post_meta((int) $booking_id, '_tap_refund_error', $reason);
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
        $meta_key = '';

        switch ($service_type) {
            case 'tap_accommodation': $meta_key = '_tap_acc_agency_id'; break;
            case 'tap_tour':          $meta_key = '_tap_tour_agency_id'; break;
            case 'tap_transport':     $meta_key = '_tap_trans_agency_id'; break;
            case 'tap_car_rental':    $meta_key = '_tap_car_agency_id'; break;
            case 'tap_boat':          $meta_key = '_tap_boat_agency_id'; break;
            case 'tap_package':       $meta_key = '_tap_pkg_agency_id'; break;
            case 'tap_equipment':     $meta_key = '_tap_eq_agency_id'; break;
        }

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
        return $commission ? floatval($commission) : 10;
    }

    public static function calculate_price($service_type, $service_id, $check_in = '', $check_out = '', $adults = 1, $children = 0, $room_id = 0) {
        $price_keys = [
            'tap_accommodation' => '_tap_acc_price_per_night',
            'tap_tour'          => '_tap_tour_price_adult',
            'tap_transport'     => '_tap_trans_price',
            'tap_car_rental'    => '_tap_car_price_per_day',
            'tap_boat'          => '_tap_boat_price_half',
            'tap_package'       => '_tap_pkg_price',
            'tap_equipment'     => '_tap_eq_price_day',
        ];

        if ($service_type === 'tap_accommodation' && $room_id) {
            if (class_exists('TAP_Pricing') && $check_in && $check_out) {
                $total = TAP_Pricing::calculate_total($room_id, $check_in, $check_out);
                if ($total !== false) return $total;
            }
            $unit_price = floatval(get_post_meta($room_id, '_tap_room_price_per_night', true));
            if ($unit_price <= 0) return 0;
            $nights = 1;
            if ($check_in && $check_out) {
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $nights = max(1, $d1->diff($d2)->days);
            }
            return $unit_price * $nights;
        }

        $price_key = $price_keys[$service_type] ?? '';
        if (!$price_key) return 0;

        if ($service_type === 'tap_equipment') {
            // Equipment may be priced per day and/or per hour; a valid hourly
            // price must not be short-circuited by a missing day price.
            $days = 1;
            if ($check_in && $check_out) {
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $days = max(1, $d1->diff($d2)->days);
            }
            $day_price = floatval(get_post_meta($service_id, '_tap_eq_price_day', true));
            if ($day_price > 0) {
                return $day_price * $days;
            }
            return floatval(get_post_meta($service_id, '_tap_eq_price_hour', true)) * max($days, 1);
        }

        $unit_price = floatval(get_post_meta($service_id, $price_key, true));
        if ($unit_price <= 0) return 0;

        switch ($service_type) {
            case 'tap_accommodation':
                $nights = 1;
                if ($check_in && $check_out) {
                    $d1 = new DateTime($check_in);
                    $d2 = new DateTime($check_out);
                    $nights = max(1, $d1->diff($d2)->days);
                }
                return $unit_price * $nights;

            case 'tap_tour':
                $adult_price = $unit_price;
                $child_price = floatval(get_post_meta($service_id, '_tap_tour_price_child', true)) ?: $adult_price * 0.7;
                return ($adult_price * $adults) + ($child_price * $children);

            case 'tap_transport':
                return $unit_price;

            case 'tap_car_rental':
                $days = 1;
                if ($check_in && $check_out) {
                    $d1 = new DateTime($check_in);
                    $d2 = new DateTime($check_out);
                    $days = max(1, $d1->diff($d2)->days);
                }
                return $unit_price * $days;

            case 'tap_boat':
                return $unit_price;

            case 'tap_package':
                $price_per_person = floatval(get_post_meta($service_id, '_tap_pkg_price_per_person', true));
                if ($price_per_person > 0) {
                    return $price_per_person * ($adults + $children);
                }
                return $unit_price;

            default:
                return $unit_price;
        }
    }

    public static function get_room_availability($room_id, $check_in, $check_out) {
        global $wpdb;
        $inventory = intval(get_post_meta($room_id, '_tap_room_inventory', true)) ?: 1;

        $booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT b.id) FROM {$wpdb->prefix}tap_bookings b
            WHERE b.room_id = %d AND b.status NOT IN ('cancelled', 'refunded')
            AND b.check_in < %s AND b.check_out > %s",
            $room_id, $check_out, $check_in
        ));

        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_availability
            WHERE service_type = 'tap_room' AND service_id = %d
            AND date >= %s AND date < %s AND is_blocked = 1",
            $room_id, $check_in, $check_out
        ));

        return [
            'available'   => ($booked + $blocked) < $inventory,
            'total'       => $inventory,
            'booked'      => intval($booked),
            'blocked'     => intval($blocked),
            'remaining'   => $inventory - $booked - $blocked,
        ];
    }

    public static function get_rooms_with_availability($accommodation_id, $check_in = '', $check_out = '', $adults = 1) {
        $rooms = TAP_Post_Types::get_accommodation_rooms($accommodation_id);
        $results = [];

        foreach ($rooms as $room) {
            $is_active = get_post_meta($room->ID, '_tap_room_is_active', true);
            if ($is_active === '0') continue;

            $max_occupancy = intval(get_post_meta($room->ID, '_tap_room_max_occupancy', true)) ?: 999;
            $max_adults = intval(get_post_meta($room->ID, '_tap_room_max_adults', true)) ?: 999;

            if ($adults > $max_adults || $adults > $max_occupancy) continue;

            $base_price = floatval(get_post_meta($room->ID, '_tap_room_price_per_night', true));
            $available = true;
            $nights = 0;
            $total = $base_price;
            $price_breakdown = [];
            $min_stay = 1;
            $base_total = $base_price;
            $savings = 0;
            $discounts = [];

            if ($check_in && $check_out) {
                $avail = self::get_room_availability($room->ID, $check_in, $check_out);
                $available = $avail['available'];
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $nights = max(1, $d1->diff($d2)->days);

                if (class_exists('TAP_Pricing')) {
                    $prices = TAP_Pricing::get_prices_for_range($room->ID, $check_in, $check_out);
                    if ($prices['available']) {
                        $price_breakdown = $prices['prices'];
                        $base_total = $prices['total'];
                        $total = $base_total;
                    } else {
                        $available = false;
                    }

                    if ($available) {
                        $quote = TAP_Pricing::get_quote($room->ID, $check_in, $check_out);
                        if ($quote['available']) {
                            $base_total = $quote['base_total'];
                            $total = $quote['total'];
                            $savings = $quote['savings'];
                            $discounts = $quote['discounts'];
                        }
                    }

                    $min_stay = TAP_Pricing::get_max_min_stay_for_range($room->ID, $check_in, $check_out);
                }
            }

            $beds = TAP_Post_Types::get_room_beds($room->ID);
            $gallery = get_post_meta($room->ID, '_tap_room_gallery', true);
            $gallery_ids = $gallery ? explode(',', $gallery) : [];

            $results[] = [
                'id'              => $room->ID,
                'title'           => $room->post_title,
                'description'     => $room->post_excerpt ?: wp_trim_words($room->post_content, 30),
                'price'           => $base_price,
                'total'           => $total,
                'base_total'      => $base_total,
                'savings'         => $savings,
                'discounts'       => $discounts,
                'nights'          => $nights,
                'min_stay'        => $min_stay,
                'price_breakdown' => $price_breakdown,
                'max_adults'    => $max_adults,
                'max_children'  => intval(get_post_meta($room->ID, '_tap_room_max_children', true)) ?: 0,
                'max_occupancy' => $max_occupancy,
                'inventory'     => intval(get_post_meta($room->ID, '_tap_room_inventory', true)) ?: 1,
                'size'          => get_post_meta($room->ID, '_tap_room_size', true),
                'view'          => get_post_meta($room->ID, '_tap_room_view', true),
                'floor'         => get_post_meta($room->ID, '_tap_room_floor', true),
                'beds'          => $beds,
                'bed_summary'   => self::format_beds($beds),
                'thumbnail'     => get_the_post_thumbnail_url($room->ID, 'medium'),
                'gallery'       => $gallery_ids,
                'available'     => $available,
                'amenities'     => TAP_Post_Types::get_room_amenities($room->ID),
            ];
        }

        return $results;
    }

    public static function format_beds($beds) {
        if (empty($beds)) return '';
        $labels = [
            'king' => __('King', 'travel-agency-platform'),
            'queen' => __('Queen', 'travel-agency-platform'),
            'double' => __('Double', 'travel-agency-platform'),
            'twin' => __('Twin', 'travel-agency-platform'),
            'bunk' => __('Bunk', 'travel-agency-platform'),
            'sofa' => __('Sofa Bed', 'travel-agency-platform'),
            'crib' => __('Crib', 'travel-agency-platform'),
            'murphy' => __('Murphy', 'travel-agency-platform'),
            'futon' => __('Futon', 'travel-agency-platform'),
        ];
        $parts = [];
        foreach ($beds as $bed) {
            $type = $labels[$bed['type']] ?? ucfirst($bed['type']);
            $parts[] = intval($bed['count']) . ' ' . $type . ($bed['count'] > 1 ? 's' : '');
        }
        return implode(', ', $parts);
    }

    public static function get_booking_stats($agency_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $where = $agency_id ? $wpdb->prepare("WHERE agency_id = %d", $agency_id) : 'WHERE 1=1';

        return [
            'total'      => $wpdb->get_var("SELECT COUNT(*) FROM $table $where"),
            'pending'    => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'pending'"),
            'request'    => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'request'"),
            'confirmed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'confirmed'"),
            'completed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'completed'"),
            'cancelled'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'cancelled'"),
            'revenue'    => $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'commission' => $wpdb->get_var("SELECT COALESCE(SUM(commission_amount), 0) FROM $table $where"),
            'net'        => $wpdb->get_var("SELECT COALESCE(SUM(total_amount - booking_fee - commission_amount), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'fees'       => $wpdb->get_var("SELECT COALESCE(SUM(booking_fee), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
        ];
    }

    /**
     * Find the tap_agency post linked to a WordPress user.
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

        return $posts ? intval($posts[0]) : 0;
    }

    /**
     * Generate a monthly series for charts over the last $months months.
     */
    public static function get_monthly_series($months = 12) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as bookings,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END), 0) as revenue,
                    COALESCE(SUM(commission_amount), 0) as commission,
                    COALESCE(SUM(CASE WHEN status IN ('cancelled','refunded') THEN 1 ELSE 0 END), 0) as cancelled
            FROM $table
            GROUP BY month ORDER BY month ASC"
        );
        $by_month = [];
        foreach ($rows as $r) $by_month[$r->month] = $r;

        $labels = [];
        $bookings = [];
        $revenue = [];
        $commission = [];
        $cancelled = [];
        $ref = new DateTime('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = (clone $ref)->modify("-{$i} months")->format('Y-m');
            $labels[] = date_i18n('M Y', strtotime($m . '-01'));
            $row = $by_month[$m] ?? null;
            $bookings[] = $row ? (int) $row->bookings : 0;
            $revenue[] = $row ? (float) $row->revenue : 0;
            $commission[] = $row ? (float) $row->commission : 0;
            $cancelled[] = $row ? (int) $row->cancelled : 0;
        }

        return [
            'labels'     => $labels,
            'bookings'   => $bookings,
            'revenue'    => $revenue,
            'commission' => $commission,
            'cancelled'  => $cancelled,
        ];
    }

    /**
     * Aggregate bookings by service type for a breakdown chart.
     */
    public static function get_service_type_breakdown() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT service_type, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as revenue
            FROM {$wpdb->prefix}tap_bookings
            WHERE status NOT IN ('cancelled','refunded')
            GROUP BY service_type ORDER BY cnt DESC"
        );
        return $rows;
    }

    /**
     * Booking status distribution (counts).
     */
    public static function get_status_distribution() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table GROUP BY status");
        $out = ['pending' => 0, 'request' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r->status])) $out[$r->status] = (int) $r->cnt;
        }
        return $out;
    }
}
