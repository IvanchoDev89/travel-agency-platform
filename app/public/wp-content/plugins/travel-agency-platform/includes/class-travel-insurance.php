<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Travel insurance: plans, quotes, issuance, commission.
 */
class TAP_Travel_Insurance {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_travel_insurance';
    }

    /** Available plans. */
    public static function plans() {
        return [
            'basic'    => ['label' => __('Básico', 'travel-agency-platform'), 'price_pct' => 3.0, 'coverage' => __('Cobertura médica básica, cancelación por enfermedad.', 'travel-agency-platform')],
            'standard' => ['label' => __('Estándar', 'travel-agency-platform'), 'price_pct' => 5.0, 'coverage' => __('Médica, cancelación, equipaje, vuelos.', 'travel-agency-platform')],
            'premium'  => ['label' => __('Premium', 'travel-agency-platform'), 'price_pct' => 8.0, 'coverage' => __('Cobertura completa: médica, cancelación, equipaje, vuelos, aventura.', 'travel-agency-platform')],
        ];
    }

    /** Quote a premium for a booking amount. */
    public static function quote($plan, $amount) {
        $plans = self::plans();
        if (!isset($plans[$plan])) return 0;
        $pct = (float) $plans[$plan]['price_pct'];
        return round((float) $amount * $pct / 100, 2);
    }

    /** Issue a policy for a booking. */
    public static function issue($booking_id, $plan) {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));

        $premium = self::quote($plan, $booking->total_amount);
        if ($premium <= 0) return new WP_Error('invalid_plan', __('Plan inválido.', 'travel-agency-platform'));

        // Commission for platform (20% of premium).
        $commission = round($premium * 0.20, 2);

        $wpdb->insert(self::table(), [
            'booking_id' => $booking_id,
            'plan'       => sanitize_key($plan),
            'provider'   => 'internal',
            'premium'    => $premium,
            'commission' => $commission,
            'status'     => 'quoted',
        ]);
        $id = (int) $wpdb->insert_id;

        // Generate policy number.
        $policy = 'POL-' . gmdate('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $wpdb->update(self::table(), ['policy_number' => $policy, 'status' => 'issued', 'issued_at' => current_time('mysql')], ['id' => $id]);

        return $id;
    }

    /** Get insurance for a booking. */
    public static function for_booking($booking_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE booking_id = %d", (int) $booking_id));
    }

    /** Render insurance options for checkout (shortcode/helper). */
    public static function render_options($booking_id) {
        $booking_id = (int) $booking_id;
        ob_start();
        ?>
        <div class="tap-insurance-options" style="margin:16px 0;padding:16px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);background:var(--tap-surface-muted);">
            <h4 style="margin:0 0 8px;"><?php esc_html_e('Seguro de viaje', 'travel-agency-platform'); ?></h4>
            <p style="margin:0 0 12px;font-size:var(--tap-text-sm);color:var(--tap-neutral-500);"><?php esc_html_e('Protege tu viaje con un seguro. Selecciona un plan:', 'travel-agency-platform'); ?></p>
            <?php foreach (self::plans() as $key => $plan): ?>
                <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;cursor:pointer;">
                    <input type="radio" name="insurance_plan" value="<?php echo esc_attr($key); ?>">
                    <span>
                        <strong><?php echo esc_html($plan['label']); ?></strong>
                        — <?php echo esc_html($plan['coverage']); ?>
                        <br><small style="color:var(--tap-primary-dark);font-weight:600;"><?php echo esc_html(TAP_Currency::fmt(self::quote($key, 100))); ?> por cada 100 de monto</small>
                    </span>
                </label>
            <?php endforeach; ?>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <input type="radio" name="insurance_plan" value="" checked>
                <span><?php esc_html_e('Sin seguro', 'travel-agency-platform'); ?></span>
            </label>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Init: REST endpoint to issue insurance. */
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('tap/v1', '/insurance/issue', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_issue'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ]);
        });
        add_action('tap_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
    }

    public static function on_booking_created($booking_id, $data) {
        if (empty($data['insurance_plan'])) return;
        $plan = sanitize_key($data['insurance_plan']);
        if (!isset(self::plans()[$plan])) return;
        self::issue((int) $booking_id, $plan);
    }

    public static function rest_issue($request) {
        $params = $request->get_params();
        $booking_id = absint($params['booking_id'] ?? 0);
        $plan       = sanitize_key($params['plan'] ?? '');
        if (!$booking_id || !isset(self::plans()[$plan])) {
            return new WP_Error('invalid', __('Datos inválidos.', 'travel-agency-platform'), ['status' => 400]);
        }
        $id = self::issue($booking_id, $plan);
        if (is_wp_error($id)) {
            return $id;
        }
        return rest_ensure_response(['id' => $id, 'policy' => 'POL-' . gmdate('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
    }
}
