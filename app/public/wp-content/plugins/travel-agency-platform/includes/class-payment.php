<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
defined('ABSPATH') || exit;

class TAP_Payment {

    /** Set by webhook handlers when a booking was actually resolved. */
    private static $webhook_resolved = false;
    public static function orders_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_payment_orders';
    }

    public static function record_order($paypal_order_id, $object_type, $object_id, $amount, $status = 'created', $capture_id = null) {
        global $wpdb;
        $table = self::orders_table();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE paypal_order_id = %s", $paypal_order_id));
        if ($existing) {
            return $wpdb->update($table, [
                'status'     => $status,
                'capture_id' => $capture_id,
            ], ['id' => (int) $existing]);
        }
        return $wpdb->insert(self::orders_table(), [
            'paypal_order_id' => $paypal_order_id,
            'object_type'     => $object_type,
            'object_id'       => (int) $object_id,
            'amount'          => (float) $amount,
            'status'          => $status,
            'capture_id'      => $capture_id,
        ]);
    }

    /** Resolve recorded object for a completed PayPal order (sub/promo). */
    public static function resolve_order($paypal_order_id) {
        global $wpdb;
        $table = self::orders_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE paypal_order_id = %s",
            $paypal_order_id
        ));
    }

    public static function process_booking_payment($booking_id) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return new WP_Error('invalid_booking', __('Booking not found', 'travel-agency-platform'));

        $method = get_option('tap_default_gateway', 'paypal');

        do_action('tap_process_payment_' . $method, $booking_id);

        return true;
    }

    public static function get_available_gateways() {
        $gateways = [
            'paypal' => [
                'id'      => 'paypal',
                'name'    => __('PayPal', 'travel-agency-platform'),
                'enabled' => get_option('tap_paypal_enabled', '0') === '1',
                'ready'   => class_exists('TAP_PayPal') && TAP_PayPal::is_ready(),
            ],
            'sinpe' => [
                'id'      => 'sinpe',
                'name'    => __('SINPE Móvil', 'travel-agency-platform'),
                'enabled' => get_option('tap_sinpe_enabled', '0') === '1',
                'ready'   => (bool) get_option('tap_sinpe_phone', ''),
            ],
            'manual' => [
                'id'      => 'manual',
                'name'    => __('Transferencia bancaria', 'travel-agency-platform'),
                'enabled' => get_option('tap_manual_payment_enabled', '0') === '1',
                'ready'   => (bool) get_option('tap_manual_bank_info', ''),
            ],
        ];

        return apply_filters('tap_payment_gateways', $gateways);
    }

    public static function get_active_gateway() {
        $gateways = self::get_available_gateways();
        foreach ($gateways as $gw) {
            if ($gw['enabled'] && $gw['ready']) return $gw;
        }
        return null;
    }

    public static function handle_paypal_webhook() {
        $body = file_get_contents('php://input');
        $headers = self::get_webhook_headers();

        if (empty($body)) {
            status_header(400);
            exit;
        }

        $event = json_decode($body);
        if (!$event || empty($event->event_type)) {
            status_header(400);
            exit;
        }

        $verified = TAP_PayPal::verify_webhook($headers, $body);
        if (!$verified) {
            status_header(403);
            exit;
        }

        $event_id = (string) ($event->id ?? '');
        if ('' !== $event_id && self::event_processed($event_id)) {
            status_header(200);
            exit;
        }
        self::$webhook_resolved = false;

        switch ($event->event_type) {
            case 'CHECKOUT.ORDER.APPROVED':
                self::handle_order_approved($event);
                break;

            case 'PAYMENT.CAPTURE.COMPLETED':
                self::handle_capture_completed($event);
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                self::handle_capture_denied($event);
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
                self::handle_capture_refunded($event);
                break;
        }

        if (self::$webhook_resolved && '' !== $event_id) {
            self::mark_event_processed($event_id);
        } elseif (!self::$webhook_resolved) {
            // Let PayPal retry: the event arrived before its booking/order was
            // visible. Do NOT mark it processed in that case.
            status_header(500);
            exit;
        }
        status_header(200);
        exit;
    }

    /**
     * Webhook handler for Onvo Pay.
     *
     * Verifies HMAC signature, processes payment_intent.succeeded / failed.
     */
    public static function handle_onvo_webhook() {
        $raw_body = file_get_contents('php://input');

        if (empty($raw_body)) {
            status_header(400);
            exit;
        }

        $signature = isset($_SERVER['HTTP_X_ONVO_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ONVO_SIGNATURE'])) : '';

        $verified = TAP_Onvo::verify_webhook($raw_body, $signature);
        if (!$verified) {
            status_header(403);
            exit;
        }

        $event = json_decode($raw_body);
        if (!$event || empty($event->type)) {
            status_header(400);
            exit;
        }

        // Idempotency: skip if already processed. A bounded table replaces the
        // per-event transient that bloated wp_options with expired rows.
        $event_id = $event->id ?? '';
        if (!empty($event_id) && self::event_processed($event_id)) {
            status_header(200);
            exit;
        }

        $type = $event->type;
        $intent = $event->data->object ?? null;

        switch ($type) {
            case 'payment_intent.succeeded':
                if ($intent && !empty($intent->id)) {
                    self::$webhook_resolved = (bool) self::handle_onvo_payment_succeeded($intent);
                }
                break;

            case 'payment_intent.payment_failed':
                if ($intent && !empty($intent->id)) {
                    self::handle_onvo_payment_failed($intent);
                    self::$webhook_resolved = true;
                }
                break;
        }

        // Only acknowledge events that actually resolved a booking; otherwise
        // leave them unprocessed so the provider retries after reconciliation.
        if (self::$webhook_resolved && !empty($event_id)) {
            self::mark_event_processed($event_id);
        } elseif (!self::$webhook_resolved) {
            status_header(500);
            exit;
        }

        status_header(200);
        exit;
    }

    /** Bounded webhook idempotency store (pruned, not per-event transients). */
    private static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_processed_events';
    }

    private static function event_processed($event_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::events_table() . ' WHERE event_id = %s',
            (string) $event_id
        )) > 0;
    }

    private static function mark_event_processed($event_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::events_table() . ' (event_id) VALUES (%s)',
            (string) $event_id
        ));
        // Keep the table bounded: drop events older than a week.
        $wpdb->query("DELETE FROM " . self::events_table() . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }

    /**
     * Handle Onvo payment_intent.succeeded.
     */
    private static function handle_onvo_payment_succeeded($intent) {
        $intent_id = $intent->id;
        $booking_id = self::find_booking_by_onvo_intent($intent_id, $intent);
        if (!$booking_id) {
            return false;
        }

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return false;
        }

        // Reconcile against what was actually charged, not the full booking
        // total: partial payments and retries are a supported flow.
        $intent_amount = isset($intent->amount) ? TAP_Onvo::from_minor_units($intent->amount, $intent->currency ?? 'USD') : 0;
        $expected = (float) get_post_meta($booking_id, '_tap_onvo_expected_amount', true);
        if ($expected <= 0) { $expected = TAP_Booking::remaining_amount($booking); }
        if ($expected <= 0) { $expected = (float) $booking->total_amount; }
        if ($intent_amount > 0 && abs($intent_amount - $expected) > 0.01) {
            // Record a reviewable exception. Retrying the webhook cannot change
            // the amount, so acknowledge it instead of looping forever.
            update_post_meta($booking_id, '_tap_payment_mismatch', wp_json_encode([
                'expected'   => round($expected, 2),
                'received'   => round($intent_amount, 2),
                'intent_id'  => (string) $intent_id,
                'gateway'    => 'onvo',
                'recorded_at'=> current_time('mysql'),
            ], JSON_UNESCAPED_UNICODE));
            if (class_exists('TAP_Audit_Log')) {
                TAP_Audit_Log::log('payment_amount_mismatch', 'booking', $booking_id, null, $booking->agency_id, 0);
            }
            do_action('tap_payment_amount_mismatch', $booking_id, 'onvo', (string) $intent_id, $expected, $intent_amount);
            return true;
        }

        // record_paid_capture() writes amount_paid, confirms the booking and
        // fires tap_payment_completed exactly once. update_payment_status()
        // only flipped the flag and left amount_paid at zero, so revenue and
        // remaining_amount() were wrong for every Onvo payment.
        $details = ['amount' => [
            'value'         => (float) $intent_amount,
            'currency_code' => (string) ($intent->currency ?? 'USD'),
        ],];
        $result = TAP_Booking::record_paid_capture($booking_id, 'onvo', '', $details);
        if (is_wp_error($result)) {
            return false;
        }

        // Store the payment intent ID for refunds.
        update_post_meta($booking_id, '_tap_onvo_payment_intent_id', $intent_id);

        // Mark the checkout-session order as completed and keep the intent id
        // as its capture reference (no duplicate ledger row).
        $session_id = (string) get_post_meta($booking_id, '_tap_onvo_checkout_session_id', true);
        if ('' !== $session_id) {
            self::record_order($session_id, 'booking', (int) $booking_id, (float) $intent_amount, 'completed', $intent_id);
        }

        // Store processor fee (Onvo: 3.5% + 2% retention).
        $processor_fee = round($intent_amount * 0.055, 2);
        $net_received = round($intent_amount - $processor_fee, 2);
        update_post_meta($booking_id, '_tap_processor_fee', $processor_fee);
        update_post_meta($booking_id, '_tap_net_received', $net_received);
        update_post_meta($booking_id, '_tap_payment_gateway', 'onvo');
        return true;
    }

    /**
     * Handle Onvo payment_intent.payment_failed.
     */
    private static function handle_onvo_payment_failed($intent) {
        $intent_id = $intent->id;
        $booking_id = self::find_booking_by_onvo_intent($intent_id, $intent);
        if (!$booking_id) {
            return;
        }
        TAP_Booking::update_payment_status($booking_id, 'failed');
        do_action('tap_payment_failed', $booking_id, 'onvo', $intent_id);
    }

    /**
     * Resolve the booking that owns an Onvo payment intent id.
     */
    public static function find_booking_by_onvo_intent($intent_id, $intent = null) {
        global $wpdb;

        // Onvo sends the booking metadata supplied at session creation; use it
        // first so a new payment intent always resolves to its booking.
        if ($intent) {
            if (!empty($intent->metadata->booking_id)) {
                $from_meta = absint($intent->metadata->booking_id);
                if ($from_meta) { return $from_meta; }
            }
            if (!empty($intent->metadata->booking_code)) {
                $code = sanitize_text_field((string) $intent->metadata->booking_code);
                $by_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s LIMIT 1", $code
                ));
                if ($by_code) { return (int) $by_code; }
            }
            $session = $intent->checkout_session ?? null;
            $session_id = is_object($session) ? ($session->id ?? '') : (is_string($session) ? $session : '');
            if ('' !== $session_id) {
                $by_session = $wpdb->get_var($wpdb->prepare(
                    "SELECT object_id FROM {$wpdb->prefix}tap_payment_orders
                     WHERE paypal_order_id = %s AND object_type = 'booking' AND object_id > 0 LIMIT 1",
                    $session_id
                ));
                if ($by_session) { return (int) $by_session; }
            }
        }

        $table = self::orders_table();
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT object_id FROM {$table}
             WHERE paypal_order_id = %s AND object_type = 'booking' AND object_id > 0 LIMIT 1",
            $intent_id
        ));
        if ($booking_id) {
            return (int) $booking_id;
        }

        // Legacy fallback: postmeta reference.
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_onvo_payment_intent_id' AND meta_value = %s",
            $intent_id
        ));
        return $booking_id ? (int) $booking_id : 0;
    }

    private static function get_webhook_headers() {
        $headers = [];
        $paypal_headers = [
            'PAYPAL-AUTH-ALGO',
            'PAYPAL-CERT-URL',
            'PAYPAL-TRANSMISSION-ID',
            'PAYPAL-TRANSMISSION-SIG',
            'PAYPAL-TRANSMISSION-TIME',
        ];

        foreach ($paypal_headers as $h) {
            $key = 'HTTP_' . str_replace('-', '_', $h);
            $headers[$h] = isset($_SERVER[$key]) ? sanitize_text_field(wp_unslash($_SERVER[$key])) : '';
        }

        return $headers;
    }

    /**
     * Resolve the booking that owns a PayPal order id.
     *
     * Ledger-first: every order created for a booking is recorded in
     * tap_payment_orders, so multi-order bookings (retries, abandoned
     * checkouts) still map each order back to their booking. Falls back to
     * the legacy single postmeta reference for bookings created before the
     * ledger existed.
     */
    public static function find_booking_by_order($paypal_order_id) {
        global $wpdb;

        $table = self::orders_table();
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT object_id FROM {$table}
             WHERE paypal_order_id = %s AND object_type = 'booking' AND object_id > 0 LIMIT 1",
            $paypal_order_id
        ));
        if ($booking_id) {
            return (int) $booking_id;
        }

        // Legacy fallback: single postmeta reference written by create_order.
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));
        return $booking_id ? (int) $booking_id : 0;
    }

    private static function handle_order_approved($event) {
        $paypal_order_id = $event->resource->id ?? '';
        if (!$paypal_order_id) return;

        $booking_id = self::find_booking_by_order($paypal_order_id);
        if ($booking_id) {
            self::$webhook_resolved = true;
            do_action('tap_payment_approved', $booking_id, 'paypal', $paypal_order_id);
        }
    }

    private static function handle_capture_completed($event) {
        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        $booking_id = self::find_booking_by_order($paypal_order_id);
        if ($booking_id) {
            $booking_id  = (int) $booking_id;
            $capture_id  = $capture->id ?? '';
            $prev_capture = get_post_meta($booking_id, '_tap_paypal_capture_id', true);
            if ($prev_capture && $capture_id && (string) $prev_capture === (string) $capture_id) {
                self::$webhook_resolved = true;
                return;
            }
            $booking = TAP_Booking::get_booking($booking_id);
            self::record_order($paypal_order_id, 'booking', $booking_id, $booking ? (float) $booking->total_amount : 0, 'completed', $capture_id);

            // Mirror the AJAX path: a capture that could not be recorded must
            // never be silently acknowledged as a completed payment.
            $recorded = TAP_Booking::record_paid_capture($booking_id, 'paypal', $capture_id, $capture);
            if (is_wp_error($recorded)) {
                $code = $recorded->get_error_code();
                // AJAX capture normally wins the race against the webhook.
                // 'already_paid' is that benign replay, not a failed capture.
                if ('already_paid' !== $code) {
                    update_post_meta($booking_id, '_tap_capture_issue', $code . ': ' . $recorded->get_error_message());
                    do_action('tap_payment_capture_failed', $booking_id, 'paypal', $capture_id, $code);
                }
                // A lost race is resolved by the winning request; let PayPal
                // retry so that the replay sees 'already_paid'.
                if ('lost_race' !== $code) {
                    self::$webhook_resolved = true;
                }
                return;
            }
            self::$webhook_resolved = true;
            return;
        }

        // Non-booking payments (subscription / promotion)
        $order = self::resolve_order($paypal_order_id);
        if (!$order) return;

        // A webhook from PayPal is authentic, but the captured amount can still
        // differ from the order (price change, stale approval). Never activate
        // a paid period on a mismatched charge.
        $captured_value = isset($capture->amount->value) ? (float) $capture->amount->value : 0.0;
        $order_amount = (float) $order->amount;
        if ($captured_value > 0 && $order_amount > 0 && abs($captured_value - $order_amount) > 0.01) {
            self::record_order($paypal_order_id, $order->object_type, (int) $order->object_id, $order_amount, 'mismatch', $capture->id ?? '');
            if (class_exists('TAP_Audit_Log')) {
                TAP_Audit_Log::log('payment_amount_mismatch', $order->object_type, (int) $order->object_id, null, 0, 0);
            }
            do_action('tap_payment_amount_mismatch', 0, 'paypal', (string) ($capture->id ?? ''), $order_amount, $captured_value);
            return;
        }

        self::record_order($paypal_order_id, $order->object_type, $order->object_id, $order->amount, 'completed', $capture->id ?? '');

        if ('subscription' === $order->object_type) {
            $sub_pay = self::confirm_subscription_payment((int) $order->object_id, 'paypal', $capture->id ?? '');
            do_action('tap_payment_completed', 0, 'paypal', $capture->id ?? '');
        } elseif ('promotion' === $order->object_type) {
            self::confirm_promotion_payment((int) $order->object_id, 'paypal', $capture->id ?? '');
            do_action('tap_payment_completed', 0, 'paypal', $capture->id ?? '');
        }
    }

    /** Mark a subscription paid after PayPal capture (1 month per charge). */
    public static function confirm_subscription_payment($sub_id, $method = 'paypal', $capture_id = '') {
        global $wpdb;
        $sub = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'tap_agency_subscriptions' . " WHERE id = %d AND status = 'pending' AND payment_status = 'pending'",
            (int) $sub_id
        ));
        if (!$sub) return;
        if ($capture_id) {
            /* translators: %s: identificador de la captura de PayPal */
            $wpdb->update($wpdb->prefix . 'tap_agency_subscriptions', ['notes' => sprintf(__('Paid via PayPal (capture %s)', 'travel-agency-platform'), $capture_id)], ['id' => (int) $sub_id]);
        }
        $until = TAP_Subscriptions::mark_paid((int) $sub_id, get_current_user_id(), 1);
        return $until;
    }

    /** Activate a promotion after PayPal capture (1 month per charge). */
    public static function confirm_promotion_payment($promo_id, $method = 'paypal', $capture_id = '') {
        global $wpdb;
        $promo = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'tap_promos' . " WHERE id = %d AND status = 'pending' AND payment_status = 'pending'",
            (int) $promo_id
        ));
        if (!$promo) return;
        if ($capture_id) {
            /* translators: %s: identificador de la captura de PayPal */
            $wpdb->update($wpdb->prefix . 'tap_promos', ['notes' => sprintf(__('Paid via PayPal (capture %s)', 'travel-agency-platform'), $capture_id)], ['id' => (int) $promo_id]);
        }
        TAP_Promotions::activate((int) $promo_id);
        return true;
    }

    private static function handle_capture_denied($event) {
        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        $booking_id = self::find_booking_by_order($paypal_order_id);

        if ($booking_id) {
            self::$webhook_resolved = true;
            TAP_Booking::update_payment_status($booking_id, 'failed');
            do_action('tap_payment_failed', $booking_id, 'paypal', '');
        }
    }

    private static function handle_capture_refunded($event) {
        global $wpdb;

        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        $booking_id = self::find_booking_by_order($paypal_order_id);

        if ($booking_id) {
            self::$webhook_resolved = true;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, payment_status, amount_paid, total_amount, refund_amount, refunded_at
                   FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
                $booking_id
            ));
            if (!$row) {
                return;
            }

            $refund_id = (string) ($capture->id ?? '');

            // Ledger of every refund id already applied. Checking only the last
            // one let a redelivered earlier refund be subtracted twice, and
            // made two different partial refunds overwrite each other.
            if ('' !== $refund_id) {
                $seen = (array) get_post_meta($booking_id, '_tap_paypal_refund_seen');
                if (in_array($refund_id, $seen, true)) {
                    return;
                }
                add_post_meta($booking_id, '_tap_paypal_refund_seen', $refund_id);
                update_post_meta($booking_id, '_tap_paypal_refund_id', $refund_id);
            }

            $refund_value = isset($capture->amount->value) ? (float) $capture->amount->value : null;
            $original = max((float) $row->total_amount, (float) $row->amount_paid + (float) ($row->refund_amount ?? 0));
            $cumulative = round((float) ($row->refund_amount ?? 0) + (float) $refund_value, 2);

            // A refund below the original captured amount is PARTIAL: the
            // traveler still received part of the service, so the booking is
            // not fully refunded and earned commission is not voided yet.
            $partial = null !== $refund_value && $original > 0 && $cumulative + 0.005 < $original;
            if ($partial) {
                update_post_meta($booking_id, '_tap_refund_amount', $cumulative);
                $wpdb->update(
                    $wpdb->prefix . 'tap_bookings',
                    [
                        'refund_amount'  => $cumulative,
                        'refund_percent' => $original > 0 ? round(($cumulative / $original) * 100, 2) : 0,
                        'amount_paid'    => round(max(0.0, $original - $cumulative), 2),
                    ],
                    ['id' => (int) $booking_id]
                );
                TAP_Booking::prorate_commission_after_refund($booking_id, $cumulative);
                do_action('tap_payment_partially_refunded', $booking_id, 'paypal', $refund_id, $refund_value);
                return;
            }

            // Cumulative refunds now cover the capture: close the booking.
            $wpdb->update(
                $wpdb->prefix . 'tap_bookings',
                [
                    'refund_amount'  => $cumulative,
                    'refund_percent' => 100,
                    'amount_paid'    => 0,
                ],
                ['id' => (int) $booking_id]
            );
            if ('refunded' !== $row->payment_status) {
                TAP_Booking::update_payment_status($booking_id, 'refunded');
                do_action('tap_payment_refunded', $booking_id, 'paypal', $refund_id);
            }

            // Money is back with the client: keep the ledger consistent by
            // stamping refunded_at (for an already-cancelled row) and voiding
            // any commission this capture had earned.
            if (empty($row->refunded_at) && ('cancelled' === $row->status || 'refunded' === $row->status)) {
                $wpdb->update($wpdb->prefix . 'tap_bookings', ['refunded_at' => current_time('mysql')], ['id' => $booking_id]);
            }
            TAP_Booking::void_commission($booking_id, true);
        }
    }
}

add_action('rest_api_init', function () {
    register_rest_route('tap/v1', '/paypal-webhook', [
        'methods'             => 'POST',
        'callback'            => ['TAP_Payment', 'handle_paypal_webhook'],
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('tap/v1', '/onvo-webhook', [
        'methods'             => 'POST',
        'callback'            => ['TAP_Payment', 'handle_onvo_webhook'],
        'permission_callback' => '__return_true',
    ]);
});
