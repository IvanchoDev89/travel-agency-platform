<?php
defined('ABSPATH') || exit;

class TAP_Payment {
    public static function orders_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_payment_orders';
    }

    public static function record_order($paypal_order_id, $object_type, $object_id, $amount, $status = 'created', $capture_id = null) {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::orders_table() . " WHERE paypal_order_id = %s", $paypal_order_id));
        if ($existing) {
            return $wpdb->update(self::orders_table(), [
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
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::orders_table() . " WHERE paypal_order_id = %s",
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
                'ready'   => TAP_PayPal::is_ready(),
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

        status_header(200);
        exit;
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
            $headers[$h] = $_SERVER['HTTP_' . str_replace('-', '_', $h)] ?? '';
        }

        return $headers;
    }

    private static function handle_order_approved($event) {
        $paypal_order_id = $event->resource->id ?? '';
        if (!$paypal_order_id) return;

        global $wpdb;
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));

        if ($booking_id) {
            do_action('tap_payment_approved', $booking_id, 'paypal', $paypal_order_id);
        }
    }

    private static function handle_capture_completed($event) {
        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        global $wpdb;
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));

        if ($booking_id) {
            $booking_id  = (int) $booking_id;
            $capture_id  = $capture->id ?? '';
            $prev_capture = get_post_meta($booking_id, '_tap_paypal_capture_id', true);
            if ($prev_capture && $capture_id && (string) $prev_capture === (string) $capture_id) {
                return;
            }
            TAP_Booking::record_paid_capture($booking_id, 'paypal', $capture_id, $capture);
            return;
        }

        // Non-booking payments (subscription / promotion)
        $order = self::resolve_order($paypal_order_id);
        if (!$order) return;

        self::record_order($paypal_order_id, $order->object_type, $order->object_id, $order->amount, 'completed', $capture->id ?? '');

        if ('subscription' === $order->object_type) {
            $sub_pay = TAP_Payment::confirm_subscription_payment((int) $order->object_id, 'paypal', $capture->id ?? '');
            do_action('tap_payment_completed', 0, 'paypal', $capture->id ?? '');
        } elseif ('promotion' === $order->object_type) {
            TAP_Payment::confirm_promotion_payment((int) $order->object_id, 'paypal', $capture->id ?? '');
            do_action('tap_payment_completed', 0, 'paypal', $capture->id ?? '');
        }
    }

    /** Mark a subscription paid after PayPal capture (1 month per charge). */
    public static function confirm_subscription_payment($sub_id, $method = 'paypal', $capture_id = '') {
        global $wpdb;
        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . 'tap_agency_subscriptions' . " WHERE id = %d AND status = 'pending' AND payment_status = 'pending'",
            (int) $sub_id
        ));
        if (!$sub) return;
        if ($capture_id) {
            $wpdb->update($wpdb->prefix . 'tap_agency_subscriptions', ['notes' => sprintf(__('Paid via PayPal (capture %s)', 'travel-agency-platform'), $capture_id)], ['id' => (int) $sub_id]);
        }
        $until = TAP_Subscriptions::mark_paid((int) $sub_id, get_current_user_id(), 1);
        return $until;
    }

    /** Activate a promotion after PayPal capture (1 month per charge). */
    public static function confirm_promotion_payment($promo_id, $method = 'paypal', $capture_id = '') {
        global $wpdb;
        $promo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . 'tap_promos' . " WHERE id = %d AND status = 'pending' AND payment_status = 'pending'",
            (int) $promo_id
        ));
        if (!$promo) return;
        if ($capture_id) {
            $wpdb->update($wpdb->prefix . 'tap_promos', ['notes' => sprintf(__('Paid via PayPal (capture %s)', 'travel-agency-platform'), $capture_id)], ['id' => (int) $promo_id]);
        }
        TAP_Promotions::activate((int) $promo_id);
        return true;
    }

    private static function handle_capture_denied($event) {
        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        global $wpdb;
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));

        if ($booking_id) {
            TAP_Booking::update_payment_status($booking_id, 'failed');
            do_action('tap_payment_failed', $booking_id, 'paypal', '');
        }
    }

    private static function handle_capture_refunded($event) {
        $capture = $event->resource;
        $paypal_order_id = $capture->supplementary_data->related_ids->order_id ?? '';

        if (!$paypal_order_id) return;

        global $wpdb;
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));

        if ($booking_id) {
            // Idempotent: never double-fire when cancellation already refunded.
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT payment_status FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
                $booking_id
            ));
            if ('refunded' !== $current) {
                TAP_Booking::update_payment_status($booking_id, 'refunded');
                do_action('tap_payment_refunded', $booking_id, 'paypal', $capture->id ?? '');
            }
        }
    }
}

add_action('rest_api_init', function () {
    register_rest_route('tap/v1', '/paypal-webhook', [
        'methods'             => 'POST',
        'callback'            => ['TAP_Payment', 'handle_paypal_webhook'],
        'permission_callback' => '__return_true',
    ]);
});
