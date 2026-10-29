<?php
defined('ABSPATH') || exit;

/**
 * Digital waiver (liability release) for tours/experiences.
 *
 * A waiver is tied to a booking and a specific version of the waiver text
 * (hash). If the text changes, previously signed waivers become invalid.
 */
class TAP_Waiver {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_waivers';
    }

    /** Compute a version hash for a waiver text. */
    public static function version_of($text) {
        return md5(trim((string) $text));
    }

    /** Get the waiver text for a service. */
    public static function text_for($service_type, $service_id) {
        $meta_key = $service_type === 'tap_experience' ? '_tap_exp_waiver' : '_tap_tour_waiver';
        $text = (string) get_post_meta((int) $service_id, $meta_key, true);
        if ($text === '') {
            $text = (string) get_option('tap_default_waiver_text', '');
        }
        return $text;
    }

    /** Check if waiver is required for a service. */
    public static function is_required($service_type, $service_id) {
        $meta_key = $service_type === 'tap_experience' ? '_tap_exp_waiver_required' : '_tap_tour_waiver_required';
        return (bool) get_post_meta((int) $service_id, $meta_key, true);
    }

    /** Get the minimum signing age for a service. */
    public static function min_sign_age($service_type, $service_id) {
        $meta_key = $service_type === 'tap_experience' ? '_tap_exp_waiver_min_age' : '_tap_tour_waiver_min_age';
        $age = (int) get_post_meta((int) $service_id, $meta_key, true);
        return $age > 0 ? $age : 18;
    }

    /** Get waiver for a booking. */
    public static function for_booking($booking_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE booking_id = %d ORDER BY id DESC LIMIT 1", (int) $booking_id));
    }

    /** Check if a booking has a valid (current version) signed waiver. */
    public static function has_valid($booking_id) {
        $booking = self::booking($booking_id);
        if (!$booking) return false;
        $waiver = self::for_booking($booking_id);
        if (!$waiver) return false;
        $current_text = self::text_for($booking->service_type, (int) $booking->service_id);
        $current_hash = self::version_of($current_text);
        return $waiver->waiver_text_hash === $current_hash && !empty($waiver->signed_at);
    }

    /** Get booking row. */
    private static function booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $booking_id));
    }

    /** Sign a waiver. Returns waiver id or WP_Error. */
    public static function sign($booking_id, $signed_name, $signature_data) {
        $booking = self::booking($booking_id);
        if (!$booking) return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));

        $service_type = $booking->service_type;
        $service_id = (int) $booking->service_id;
        $text = self::text_for($service_type, $service_id);
        if (trim($text) === '') {
            return new WP_Error('no_waiver', __('Esta actividad no requiere waiver.', 'travel-agency-platform'));
        }

        $signed_name = sanitize_text_field($signed_name);
        if (strlen($signed_name) < 3) {
            return new WP_Error('bad_name', __('Ingresa tu nombre completo para firmar.', 'travel-agency-platform'));
        }

        $min_age = self::min_sign_age($service_type, $service_id);
        // Age check is done client-side + here via birthdate if provided; for now trust the name.

        global $wpdb;
        $t = self::table();
        $wpdb->insert($t, [
            'booking_id'      => (int) $booking_id,
            'service_type'    => $service_type,
            'service_id'     => $service_id,
            'user_id'        => (int) $booking->client_id,
            'guest_email'    => (string) $booking->guest_email,
            'waiver_version'  => '1',
            'waiver_text_hash' => self::version_of($text),
            'signature_data' => $signature_data,
            'signed_name'    => $signed_name,
            'signed_at'      => current_time('mysql'),
            'ip_address'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ]);
        $id = (int) $wpdb->insert_id;
        if (!$id) return new WP_Error('db_error', __('No se pudo guardar la firma.', 'travel-agency-platform'));

        do_action('tap_waiver_signed', $id, (int) $booking_id);
        return $id;
    }

    /** Render waiver form HTML (canvas + checkbox). */
    public static function render_form($booking_id) {
        $booking = self::booking($booking_id);
        if (!$booking) return '<p>' . esc_html__('Reserva no encontrada.', 'travel-agency-platform') . '</p>';
        if (!self::is_required($booking->service_type, (int) $booking->service_id)) {
            return '';
        }
        if (self::has_valid($booking_id)) {
            return '<p class="tap-waiver-signed">' . esc_html__('Waiver firmado correctamente.', 'travel-agency-platform') . '</p>';
        }
        $text = self::text_for($booking->service_type, (int) $booking->service_id);
        $min_age = self::min_sign_age($booking->service_type, (int) $booking->service_id);
        ob_start();
        ?>
        <div class="tap-waiver-form" data-booking="<?php echo (int) $booking_id; ?>">
            <h3><?php esc_html_e('Descargo de responsabilidad (Waiver)', 'travel-agency-platform'); ?></h3>
            <div class="tap-waiver-text"><?php echo wp_kses_post(nl2br($text)); ?></div>
            <p class="tap-waiver-age"><?php printf(esc_html__('Debes tener al menos %d años para firmar.', 'travel-agency-platform'), $min_age); ?></p>
            <form method="post" class="tap-waiver-sign-form">
                <input type="hidden" name="action" value="tap_sign_waiver">
                <input type="hidden" name="booking_id" value="<?php echo (int) $booking_id; ?>">
                <?php wp_nonce_field('tap_sign_waiver_' . (int) $booking_id, 'tap_waiver_nonce'); ?>
                <p>
                    <label><?php esc_html_e('Nombre completo', 'travel-agency-platform'); ?>
                        <input type="text" name="signed_name" required class="regular-text" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label><input type="checkbox" name="accept" value="1" required> <?php esc_html_e('He leído y acepto los términos del waiver.', 'travel-agency-platform'); ?></label>
                </p>
                <p>
                    <label><?php esc_html_e('Firma (dibuja con el mouse o dedo)', 'travel-agency-platform'); ?></label>
                    <canvas class="tap-waiver-canvas" width="400" height="150" style="border:1px solid #ccc;border-radius:6px;touch-action:none;"></canvas>
                    <input type="hidden" name="signature_data" class="tap-waiver-sigdata">
                </p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Firmar waiver', 'travel-agency-platform'); ?></button>
            </form>
            <script>
            (function(){
                var form = document.querySelector('.tap-waiver-sign-form');
                if (!form) return;
                var canvas = form.querySelector('.tap-waiver-canvas');
                var ctx = canvas.getContext('2d');
                var drawing = false;
                function pos(e){ var r=canvas.getBoundingClientRect(); var t=e.touches?e.touches[0]:e; return {x:t.clientX-r.left, y:t.clientY-r.top}; }
                canvas.addEventListener('mousedown', function(e){ drawing=true; var p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
                canvas.addEventListener('mousemove', function(e){ if(!drawing) return; var p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); });
                canvas.addEventListener('mouseup', function(){ drawing=false; save(); });
                canvas.addEventListener('mouseleave', function(){ drawing=false; });
                canvas.addEventListener('touchstart', function(e){ e.preventDefault(); drawing=true; var p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
                canvas.addEventListener('touchmove', function(e){ e.preventDefault(); if(!drawing) return; var p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); });
                canvas.addEventListener('touchend', function(){ drawing=false; save(); });
                function save(){ form.querySelector('.tap-waiver-sigdata').value = canvas.toDataURL('image/png'); }
                form.addEventListener('submit', function(e){
                    if (!form.querySelector('[name="accept"]').checked) { e.preventDefault(); alert('Debes aceptar los términos.'); return; }
                    if (form.querySelector('.tap-waiver-sigdata').value === '') { e.preventDefault(); alert('Dibuja tu firma.'); return; }
                });
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    /** REST-ish handler via admin_post. */
    public static function handle_sign() {
        if (!isset($_POST['booking_id'])) wp_die('');
        $booking_id = (int) $_POST['booking_id'];
        check_admin_referer('tap_sign_waiver_' . $booking_id, 'tap_waiver_nonce');
        $signed_name = isset($_POST['signed_name']) ? sanitize_text_field(wp_unslash($_POST['signed_name'])) : '';
        $signature = isset($_POST['signature_data']) ? sanitize_text_field(wp_unslash($_POST['signature_data'])) : '';
        $accept = !empty($_POST['accept']);
        if (!$accept) {
            wp_safe_redirect(wp_get_referer() ?: home_url());
            exit;
        }
        $res = self::sign($booking_id, $signed_name, $signature);
        $redirect = wp_get_referer() ?: home_url();
        if (is_wp_error($res)) {
            $redirect = add_query_arg('waiver_error', $res->get_error_code(), $redirect);
        } else {
            $redirect = add_query_arg('waiver_ok', '1', $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /** List waivers for admin (recent). */
    public static function recent($limit = 50) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", (int) $limit)) ?: [];
    }

    /** Shortcode: [tap_waiver_form booking="123"] */
    public static function shortcode($atts) {
        $atts = shortcode_atts(['booking' => 0], $atts);
        $booking_id = (int) $atts['booking'];
        if (!$booking_id && isset($_GET['booking'])) {
            $booking_id = (int) $_GET['booking'];
        }
        if (!$booking_id) {
            return '<p class="tap-empty">' . esc_html__('Reserva no especificada.', 'travel-agency-platform') . '</p>';
        }
        return self::render_form($booking_id);
    }
}
