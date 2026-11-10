<?php
defined('ABSPATH') || exit;

/**
 * Onvo Pay gateway integration.
 *
 * Mirrors TAP_PayPal but targets the Onvo REST API (Costa Rica).
 * Supports CRC/USD, SINPE Móvil, tarjetas internacionales, marketplaces
 * (connected accounts + onBehalfOf) y autorización/captura manual.
 *
 * Keys:
 *   - Secret key (servidor): constante ONVO_SECRET_KEY o opción tap_onvo_secret_key
 *   - Publishable key (cliente): constante ONVO_PUBLISHABLE_KEY o opción tap_onvo_publishable_key
 *   - Webhook secret: opción tap_onvo_webhook_secret (whsec_...)
 *
 * Sandbox se detecta por el prefijo de la key (onvo_test_ vs onvo_live_).
 */
class TAP_Onvo {
    private static $secret_key       = '';
    private static $publishable_key  = '';
    private static $webhook_secret   = '';

    public static function init() {
        if (defined('ONVO_SECRET_KEY')) {
            self::$secret_key = (string) ONVO_SECRET_KEY;
        } else {
            self::$secret_key = (string) get_option('tap_onvo_secret_key', '');
        }
        if (defined('ONVO_PUBLISHABLE_KEY')) {
            self::$publishable_key = (string) ONVO_PUBLISHABLE_KEY;
        } else {
            self::$publishable_key = (string) get_option('tap_onvo_publishable_key', '');
        }
        self::$webhook_secret = (string) get_option('tap_onvo_webhook_secret', '');
    }

    public static function get_secret_key() {
        self::init();
        return self::$secret_key;
    }

    public static function get_publishable_key() {
        self::init();
        return self::$publishable_key;
    }

    public static function get_webhook_secret() {
        self::init();
        return self::$webhook_secret;
    }

    public static function get_app_fee() {
        return (float) get_option('tap_onvo_app_fee', 5);
    }

    public static function is_ready() {
        self::init();
        return !empty(self::$secret_key);
    }

    public static function is_sandbox() {
        self::init();
        return strpos(self::$secret_key, 'onvo_test_') === 0;
    }

    public static function has_placeholder_credentials() {
        self::init();
        $placeholder = ['', 'test', 'YOUR_SECRET_KEY', 'onvo_test_secret_key_'];
        return in_array(trim(self::$secret_key), $placeholder, true)
            || (strpos(self::$secret_key, 'onvo_test_secret_key_') === 0 && strlen(self::$secret_key) < 40);
    }

    public static function get_base_url() {
        return 'https://api.onvopay.com/v1';
    }

    /**
     * Convierte un monto decimal a unidades mínimas (centavos) según la moneda.
     */
    public static function to_minor_units($amount, $currency = null) {
        $currency = $currency ?: (class_exists('TAP_Currency') ? TAP_Currency::code() : 'USD');
        $decimals = class_exists('TAP_Currency') ? TAP_Currency::decimals($currency) : 2;
        return (int) round((float) $amount * pow(10, $decimals));
    }

    /**
     * Convierte unidades mínimas a monto decimal.
     */
    public static function from_minor_units($minor, $currency = null) {
        $currency = $currency ?: (class_exists('TAP_Currency') ? TAP_Currency::code() : 'USD');
        $decimals = class_exists('TAP_Currency') ? TAP_Currency::decimals($currency) : 2;
        return round((int) $minor / pow(10, $decimals), $decimals);
    }

    /**
     * Helper HTTP genérico para la API de Onvo.
     *
     * @return array|WP_Error  Decoded body on success, WP_Error on failure.
     */
    private static function request($method, $path, $body = null, $headers = []) {
        self::init();
        if (empty(self::$secret_key)) {
            return new WP_Error('onvo_not_configured', __('Onvo Pay no está configurado.', 'travel-agency-platform'));
        }

        $url = self::get_base_url() . $path;
        $args = [
            'method'  => strtoupper($method),
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . self::$secret_key,
                'Content-Type'  => 'application/json',
            ], $headers),
            'timeout' => 30,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('onvo_request_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response));

        if ($code < 200 || $code >= 300) {
            $msg = '';
            if (is_object($result)) {
                $msg = $result->message ?? ($result->error ?? '');
            }
            if (empty($msg)) {
                $msg = sprintf(__('Error de Onvo Pay (HTTP %d)', 'travel-agency-platform'), $code);
            }
            return new WP_Error('onvo_api_error', $msg, ['status' => $code, 'body' => $result]);
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Payment Intents                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Crea una intención de pago.
     *
     * @param float  $amount       Monto decimal.
     * @param string $currency     Código de moneda (CRC, USD).
     * @param string $description  Descripción.
     * @param string $on_behalf_of  Connected account ID (marketplace).
     * @param array  $metadata     Metadata interna.
     * @param string $capture_method 'automatic' (default) o 'manual'.
     *
     * @return array|WP_Error { id, amount, currency, status, ... }
     */
    public static function create_payment_intent($amount, $currency = null, $description = '', $on_behalf_of = '', $metadata = [], $capture_method = 'automatic') {
        $currency = $currency ?: (class_exists('TAP_Currency') ? TAP_Currency::code() : 'USD');
        $body = [
            'amount'         => self::to_minor_units($amount, $currency),
            'currency'       => strtoupper($currency),
            'description'    => $description,
            'captureMethod'  => $capture_method,
        ];
        if (!empty($on_behalf_of)) {
            $body['onBehalfOf'] = $on_behalf_of;
        }
        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        return self::request('POST', '/payment-intents', $body);
    }

    /**
     * Crea un método de pago de tipo tarjeta (client-side con publishable key,
     * pero este helper permite crearlo server-side si se envían datos tokenizados).
     *
     * NOTA: Para reducir alcance PCI, preferir el SDK web de Onvo en el cliente.
     *
     * @return array|WP_Error
     */
    public static function create_payment_method_card($card, $billing = [], $customer = []) {
        $body = [
            'type' => 'card',
            'card' => $card,
        ];
        if (!empty($billing)) {
            $body['billing'] = $billing;
        }
        if (!empty($customer)) {
            $body['customer'] = $customer;
        }
        return self::request('POST', '/payment-methods', $body);
    }

    /**
     * Crea un método de pago SINPE Móvil.
     *
     * @param string $identification     Cédula (formato con guiones).
     * @param int    $identification_type Tipo (0–9).
     * @param string $mobile_number      Número SINPE del comprador.
     * @param array  $billing            Datos de facturación.
     *
     * @return array|WP_Error
     */
    public static function create_payment_method_mobile($identification, $identification_type, $mobile_number, $billing = []) {
        $body = [
            'type' => 'mobile_number',
            'mobileNumber' => [
                'identification'     => $identification,
                'identificationType' => (int) $identification_type,
                'number'             => $mobile_number,
            ],
        ];
        if (!empty($billing)) {
            $body['billing'] = $billing;
        }
        return self::request('POST', '/payment-methods', $body);
    }

    /**
     * Confirma una intención de pago con un método de pago.
     *
     * @return array|WP_Error
     */
    public static function confirm_payment_intent($intent_id, $payment_method_id, $return_url = '') {
        $body = [
            'paymentMethodId' => $payment_method_id,
        ];
        if (!empty($return_url)) {
            $body['returnUrl'] = $return_url;
        }
        return self::request('POST', '/payment-intents/' . urlencode($intent_id) . '/confirm', $body);
    }

    /**
     * Captura una intención autorizada (manual capture).
     *
     * @return array|WP_Error
     */
    public static function capture_payment_intent($intent_id, $amount = null) {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = self::to_minor_units($amount);
        }
        return self::request('POST', '/payment-intents/' . urlencode($intent_id) . '/capture', $body);
    }

    /**
     * Cancela una intención de pago.
     *
     * @return array|WP_Error
     */
    public static function cancel_payment_intent($intent_id) {
        return self::request('POST', '/payment-intents/' . urlencode($intent_id) . '/cancel', []);
    }

    /**
     * Obtiene una intención de pago por ID.
     *
     * @return array|WP_Error
     */
    public static function get_payment_intent($intent_id) {
        return self::request('GET', '/payment-intents/' . urlencode($intent_id));
    }

    /* ------------------------------------------------------------------ */
    /* Checkout (hosted)                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Crea una sesión de Checkout hospedada por Onvo.
     *
     * @param array  $line_items   [['quantity'=>1,'unitAmount'=>10000,'currency'=>'USD','description'=>'...']]
     * @param string $redirect_url URL de éxito.
     * @param string $cancel_url   URL de cancelación.
     * @param string $on_behalf_of Connected account ID (marketplace).
     * @param array  $metadata     Metadata interna.
     *
     * @return array|WP_Error  Incluye 'url' para redirigir al comprador.
     */
    public static function create_checkout_session($line_items, $redirect_url, $cancel_url, $on_behalf_of = '', $metadata = []) {
        $body = [
            'redirectUrl' => $redirect_url,
            'cancelUrl'   => $cancel_url,
            'lineItems'   => $line_items,
        ];
        if (!empty($on_behalf_of)) {
            $body['onBehalfOf'] = $on_behalf_of;
        }
        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }
        return self::request('POST', '/checkout/sessions/one-time-link', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Marketplaces / Connected Accounts                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Crea una cuenta conectada para una agencia (marketplace).
     *
     * @param string $business_name Nombre del negocio.
     * @param float  $app_fee       Comisión de la plataforma (porcentaje).
     *
     * @return array|WP_Error  Incluye 'id' y 'onboardingUrl'.
     */
    public static function create_connected_account($business_name, $app_fee = 0) {
        $body = [
            'businessName'      => substr(trim($business_name), 0, 50),
            'marketplaceAppFee' => (float) $app_fee,
        ];
        return self::request('POST', '/connected-accounts', $body);
    }

    /**
     * Lista cuentas conectadas.
     *
     * @return array|WP_Error
     */
    public static function list_connected_accounts($status = '', $limit = 10) {
        $query = '?limit=' . (int) $limit;
        if (!empty($status)) {
            $query .= '&status=' . urlencode($status);
        }
        return self::request('GET', '/connected-accounts' . $query);
    }

    /**
     * Obtiene una cuenta conectada por ID.
     *
     * @return array|WP_Error
     */
    public static function get_connected_account($id) {
        return self::request('GET', '/connected-accounts/' . urlencode($id));
    }

    /**
     * Actualiza una cuenta conectada.
     *
     * @return array|WP_Error
     */
    public static function update_connected_account($id, $business_name = '', $app_fee = null) {
        $body = [];
        if (!empty($business_name)) {
            $body['businessName'] = substr(trim($business_name), 0, 50);
        }
        if ($app_fee !== null) {
            $body['marketplaceAppFee'] = (float) $app_fee;
        }
        if (empty($body)) {
            return new WP_Error('onvo_empty_update', __('No hay campos para actualizar.', 'travel-agency-platform'));
        }
        return self::request('POST', '/connected-accounts/' . urlencode($id), $body);
    }

    /**
     * Regenera el enlace de onboarding para una cuenta conectada.
     *
     * @return array|WP_Error  Incluye 'onboardingUrl'.
     */
    public static function create_onboarding_link($connected_account_id) {
        return self::request('POST', '/connected-accounts/' . urlencode($connected_account_id) . '/onboarding-link', []);
    }

    /* ------------------------------------------------------------------ */
    /* Refunds                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Crea un reembolso.
     *
     * @param string $payment_intent_id ID de la intención de pago.
     * @param float  $amount            Monto a reembolsar (null = total).
     * @param string $currency          Moneda.
     *
     * @return array|WP_Error
     */
    public static function refund($payment_intent_id, $amount = null, $currency = null) {
        $currency = $currency ?: (class_exists('TAP_Currency') ? TAP_Currency::code() : 'USD');
        $body = [
            'paymentIntentId' => $payment_intent_id,
        ];
        if ($amount !== null) {
            $body['amount'] = self::to_minor_units($amount, $currency);
        }
        return self::request('POST', '/refunds', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Webhook verification (HMAC-SHA256)                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Verifica la firma de un webhook de Onvo.
     *
     * Algoritmo: HMAC-SHA256 de "t.rawBody" con el webhook secret.
     * Tolerancia: 300 segundos.
     *
     * @param string $raw_body Cuerpo crudo de la petición.
     * @param string $header   Valor del header X-Onvo-Signature.
     * @param string $secret   Webhook secret (whsec_...).
     * @param int    $now      Timestamp actual (para testing).
     *
     * @return bool
     */
    public static function verify_webhook($raw_body, $header, $secret = '', $now = null) {
        if (empty($secret)) {
            $secret = self::get_webhook_secret();
        }
        if (empty($secret) || empty($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = array_pad(explode('=', trim($part), 2), 2, null);
            if ($kv[0] === 't' && ctype_digit((string) $kv[1])) {
                $timestamp = (int) $kv[1];
            }
            if ($kv[0] === 'v1' && is_string($kv[1])) {
                $signatures[] = $kv[1];
            }
        }

        $now = $now ?? time();
        if ($timestamp === null || abs($now - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);

        foreach ($signatures as $candidate) {
            if (preg_match('/^[0-9a-f]{64}$/i', $candidate) && hash_equals($expected, strtolower($candidate))) {
                return true;
            }
        }
        return false;
    }
}

// Register Onvo Pay as a payment gateway.
add_filter('tap_payment_gateways', function ($gateways) {
    $gateways['onvo'] = [
        'id'      => 'onvo',
        'name'    => __('Onvo Pay (tarjetas + SINPE)', 'travel-agency-platform'),
        'enabled' => get_option('tap_onvo_enabled', '0') === '1',
        'ready'   => class_exists('TAP_Onvo') && TAP_Onvo::is_ready(),
    ];
    return $gateways;
});
