<?php
defined('ABSPATH') || exit;

/**
 * Cancellation policy and refund domain: policy lookup per service, the refund\n * percentage table, the amount a client is entitled to, the idempotent refund\n * execution and the summary shown to the client. TAP_Booking keeps delegating\n * wrappers so no caller changes.
 */
class TAP_Refunds {
    public static function refund_summary($booking) {
        $info = self::cancellation_refund($booking);
        $policies = self::cancellation_policies();
        $label = $policies[$info['policy']] ?? $info['policy'];
        $line = sprintf(
            /* translators: %s: nombre de la política de cancelación */
            __('Política de cancelación: %s', 'travel-agency-platform'),
            $label
        );
        $refund = isset($booking->refund_amount) && (float) $booking->refund_amount > 0
            ? floatval($booking->refund_amount)
            : $info['refund_amount'];
        if ($refund > 0) {
            $line .= ' · ' . sprintf(
                /* translators: 1: importe reembolsado, 2: porcentaje reembolsado */
                __('Reembolso: %s (remaining %d%%)', 'travel-agency-platform'),
                TAP_Currency::fmt($refund),
                $info['refund_percent']
            );
        } else {
            $line .= ' · ' . __('Sin reembolso', 'travel-agency-platform');
        }
        return $line;
    }


    public static function execute_refund($booking_id, $booking = null, $amount = null) {
        $booking_id = intval($booking_id);
        if (!$booking) {
            $booking = TAP_Booking::get_booking($booking_id);
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


    public static function cancellation_refund($booking) {
        $policy = self::cancellation_policy($booking->service_type ?? '', (int) ($booking->service_id ?? 0));

        $days_before = PHP_INT_MAX;
        if (!empty($booking->check_in)) {
            $check_in = strtotime((string) $booking->check_in);
            $days_before = $check_in ? (int) floor(($check_in - strtotime(current_time('Y-m-d'))) / DAY_IN_SECONDS) : PHP_INT_MAX;
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


    public static function cancellation_policies() {
        return [
            'flexible'       => __('Flexible — reembolso total hasta 24h antes', 'travel-agency-platform'),
            'moderate'       => __('Moderada — reembolso total 5 días antes, 50% hasta 2 días', 'travel-agency-platform'),
            'strict'         => __('Estricta — 50% de reembolso hasta 7 días antes', 'travel-agency-platform'),
            'non_refundable' => __('No reembolsable', 'travel-agency-platform'),
        ];
    }
}
