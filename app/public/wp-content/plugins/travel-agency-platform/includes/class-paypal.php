<?php
defined('ABSPATH') || exit;

class TAP_PayPal {
    private static $client_id = '';
    private static $client_secret = '';
    private static $sandbox = true;

    public static function init() {
        self::$client_id     = get_option('tap_paypal_client_id', '');
        self::$client_secret = get_option('tap_paypal_secret', '');
        self::$sandbox       = get_option('tap_paypal_sandbox', '1') === '1';
    }

    public static function get_client_id() {
        self::init();
        return self::$client_id;
    }

    public static function is_ready() {
        self::init();
        return !empty(self::$client_id) && !empty(self::$client_secret);
    }

    public static function is_sandbox() {
        self::init();
        return self::$sandbox;
    }

    public static function currency_code() {
        return class_exists('TAP_Currency') ? TAP_Currency::code() : 'USD';
    }

    public static function get_base_url() {
        self::init();
        return self::$sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private static function get_access_token() {
        self::init();
        $url = self::get_base_url() . '/v1/oauth2/token';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(self::$client_id . ':' . self::$client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'grant_type=client_credentials',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paypal_token_error', $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body->access_token)) {
            return new WP_Error('paypal_token_error', __('Could not get PayPal access token', 'travel-agency-platform'));
        }

        return $body->access_token;
    }

    public static function create_order($booking_id, $return_url, $cancel_url) {
        $token = self::get_access_token();
        if (is_wp_error($token)) return $token;

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return new WP_Error('invalid_booking', __('Booking not found', 'travel-agency-platform'));

        $service = get_post($booking->service_id);
        $service_name = $service ? $service->post_title : __('Travel Service', 'travel-agency-platform');

        $url = self::get_base_url() . '/v2/checkout/orders';

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $booking->booking_code,
                'description'  => sprintf(__('Booking %s - %s', 'travel-agency-platform'), $booking->booking_code, $service_name),
                'amount' => [
                    'currency_code' => self::currency_code(),
                    'value'         => number_format(floatval($booking->total_amount), 2, '.', ''),
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => self::currency_code(),
                            'value'         => number_format(floatval($booking->total_amount), 2, '.', ''),
                        ]
                    ]
                ],
                'items' => [[
                    'name'     => $service_name,
                    'unit_amount' => [
                        'currency_code' => self::currency_code(),
                        'value'         => number_format(floatval($booking->total_amount), 2, '.', ''),
                    ],
                    'quantity' => '1',
                    'category' => 'DIGITAL_GOODS',
                ]],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => $return_url,
                        'cancel_url' => $cancel_url,
                        'user_action' => 'PAY_NOW',
                    ]
                ]
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paypal_order_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        if (empty($result->id)) {
            $error = $result->message ?? __('Could not create PayPal order', 'travel-agency-platform');
            return new WP_Error('paypal_order_error', $error);
        }

        update_post_meta($booking_id, '_tap_paypal_order_id', $result->id);

        // Keep every created order in the payment ledger (not only the latest
        // postmeta reference). Orders from abandoned checkouts must still
        // resolve to their booking so a later capture webhook credits the
        // right reservation instead of being dropped as an orphan.
        if (class_exists('TAP_Payment')) {
            TAP_Payment::record_order($result->id, 'booking', (int) $booking_id, (float) $booking->total_amount, 'created');
        }

        $approval_url = '';
        foreach ($result->links as $link) {
            if ($link->rel === 'payer-action') {
                $approval_url = $link->href;
                break;
            }
        }

        return [
            'order_id'     => $result->id,
            'approval_url' => $approval_url,
            'status'       => $result->status,
        ];
    }

    /**
     * Create a generic PayPal Checkout v2 order (used for subscription and
     * promotion payments in addition to bookings).
     *
     * @param float  $amount       Total to charge (USD).
     * @param string $description  Line description shown in the checkout.
     * @param string $reference_id Seller reference (e.g. sub/promo code).
     * @param string $object_type  Object kind recorded on payment ('subscription' | 'promotion').
     * @param int    $object_id    Object id recorded on payment.
     *
     * @return array|WP_Error { order_id, approval_url, status }
     */
    public static function create_order_generic($amount, $description, $reference_id, $object_type, $object_id) {
        $token = self::get_access_token();
        if (is_wp_error($token)) return $token;

        $url = self::get_base_url() . '/v2/checkout/orders';

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference_id,
                'description'  => $description,
                'amount' => [
                    'currency_code' => self::currency_code(),
                    'value'         => number_format((float) $amount, 2, '.', ''),
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => self::currency_code(),
                            'value'         => number_format((float) $amount, 2, '.', ''),
                        ]
                    ]
                ],
                'items' => [[
                    'name'        => $description,
                    'unit_amount' => [
                        'currency_code' => self::currency_code(),
                        'value'         => number_format((float) $amount, 2, '.', ''),
                    ],
                    'quantity' => '1',
                    'category' => 'DIGITAL_GOODS',
                ]],
            ]],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paypal_order_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        if (empty($result->id)) {
            $error = $result->message ?? __('Could not create PayPal order', 'travel-agency-platform');
            return new WP_Error('paypal_order_error', $error);
        }

        $approval_url = '';
        foreach ($result->links as $link) {
            if ($link->rel === 'payer-action') {
                $approval_url = $link->href;
                break;
            }
        }

        TAP_Payment::record_order($result->id, $object_type, (int) $object_id, $amount, 'created');

        return [
            'order_id'     => $result->id,
            'approval_url' => $approval_url,
            'status'       => $result->status,
        ];
    }

    public static function capture_order($paypal_order_id) {
        $token = self::get_access_token();
        if (is_wp_error($token)) return $token;

        $url = self::get_base_url() . '/v2/checkout/orders/' . $paypal_order_id . '/capture';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paypal_capture_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        if (empty($result->id)) {
            return new WP_Error('paypal_capture_error', __('Could not capture PayPal order', 'travel-agency-platform'));
        }

        $capture = $result->purchase_units[0]->payments->captures[0] ?? null;

        return [
            'status'          => $result->status,
            'capture_id'      => $capture->id ?? '',
            'capture_status'  => $capture->status ?? '',
            'seller_receivable' => $capture->seller_receivable_breakdown ?? null,
            'full_response'   => $result,
        ];
    }

    public static function verify_webhook($headers, $body) {
        self::init();
        $url = self::get_base_url() . '/v1/notifications/verify-webhook-signature';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(self::$client_id . ':' . self::$client_secret),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? '',
                'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? '',
                'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
                'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
                'webhook_id'        => get_option('tap_paypal_webhook_id', ''),
                'webhook_event'     => $body,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return false;

        $result = json_decode(wp_remote_retrieve_body($response));
        return ($result->verification_status ?? '') === 'SUCCESS';
    }

    public static function refund_capture($capture_id, $amount = null) {
        $token = self::get_access_token();
        if (is_wp_error($token)) return $token;

        $url = self::get_base_url() . '/v2/payments/captures/' . $capture_id . '/refund';

        $body = [];
        if ($amount) {
            $body['amount'] = [
                'currency_code' => self::currency_code(),
                'value'         => number_format(floatval($amount), 2, '.', ''),
            ];
        }

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paypal_refund_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        if (empty($result->id)) {
            return new WP_Error('paypal_refund_error', __('Could not process refund', 'travel-agency-platform'));
        }

        return [
            'refund_id' => $result->id,
            'status'    => $result->status,
            'amount'    => $result->amount->value ?? $amount,
        ];
    }
}
