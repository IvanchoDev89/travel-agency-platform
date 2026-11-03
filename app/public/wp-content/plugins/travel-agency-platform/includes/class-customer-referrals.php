<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Customer referral program (B2C growth loop).
 *
 * Each logged-in traveler gets a unique referral code. Sharing it grants the
 * invitee a discount on their first paid booking; the referrer earns loyalty
 * points once that booking is paid and completed.
 */
class TAP_Customer_Referrals {

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_track_click'], 5);
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 25, 1);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_settings_page() {
        add_options_page(
            __('Referidos y puntos', 'travel-agency-platform'),
            __('Referidos', 'travel-agency-platform'),
            'manage_options',
            'tap-referrals',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('tap_referral_group', 'tap_referral_discount_pct', [
            'type' => 'number',
            'sanitize_callback' => [__CLASS__, 'sanitize_discount'],
            'default' => 10,
        ]);
        register_setting('tap_referral_group', 'tap_referral_points_rate', [
            'type' => 'number',
            'sanitize_callback' => [__CLASS__, 'sanitize_rate'],
            'default' => 0.05,
        ]);
    }

    public static function sanitize_discount($value) {
        $value = (float) $value;
        if ($value < 0) $value = 0;
        if ($value > 50) $value = 50;
        return $value;
    }

    public static function sanitize_rate($value) {
        $value = (float) $value;
        if ($value < 0) $value = 0;
        if ($value > 0.5) $value = 0.5;
        return $value;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $discount = (float) get_option('tap_referral_discount_pct', 10);
        $rate = (float) get_option('tap_referral_points_rate', 0.05);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Programa de referidos y puntos', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Configura el descuento que recibe el invitado y la tasa de puntos que gana el referidor. Los niveles de afiliado (Bronce/Plata/Oro) añaden bonos automáticamente.', 'travel-agency-platform'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('tap_referral_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tap_referral_discount_pct"><?php esc_html_e('Descuento para el invitado (%)', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="number" id="tap_referral_discount_pct" name="tap_referral_discount_pct" value="<?php echo esc_attr($discount); ?>" min="0" max="50" step="0.5" class="small-text">
                            <p class="description"><?php esc_html_e('Porcentaje de descuento aplicado a la primera reserva del invitado. Tope 50%.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tap_referral_points_rate"><?php esc_html_e('Tasa de puntos para el referidor', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="number" id="tap_referral_points_rate" name="tap_referral_points_rate" value="<?php echo esc_attr($rate); ?>" min="0" max="0.5" step="0.01" class="small-text">
                            <p class="description"><?php esc_html_e('Fracción del total de la reserva que se convierte en puntos para el referidor (ej. 0.05 = 5%). Tope 50%.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Niveles de afiliado', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Nivel', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Referidos completados', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Bono descuento invitado', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Tasa de puntos', 'travel-agency-platform'); ?></th></tr></thead>
                <tbody>
                    <?php foreach (self::tiers() as $key => $info): ?>
                        <tr>
                            <td><?php echo esc_html($info['label']); ?></td>
                            <td><?php echo (int) $info['min']; ?>+</td>
                            <td>+<?php echo (int) $info['discount']; ?>%</td>
                            <td><?php echo (int) ($info['points_rate'] * 100); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('Los bonos se suman automáticamente al nivel base según el número de referidos completados (reservas pagadas).', 'travel-agency-platform'); ?></p>
        </div>
        <?php
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_customer_referrals';
    }

    /** Generate or retrieve the referral code for a user. */
    public static function code_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return '';
        }
        $code = (string) get_user_meta($user_id, '_tap_referral_code', true);
        if ($code) {
            return $code;
        }
        $code = 'TAP' . strtoupper(substr(md5($user_id . wp_generate_password(8, false)), 0, 6));
        update_user_meta($user_id, '_tap_referral_code', $code);
        return $code;
    }

    /** Share URL for a user. */
    public static function share_url($user_id) {
        $code = self::code_for_user($user_id);
        return home_url('/?ref=' . rawurlencode($code));
    }

    /** Resolve a code to a referrer user id (0 if invalid). */
    public static function referrer_by_code($code) {
        $code = sanitize_text_field((string) $code);
        if ('' === $code) {
            return 0;
        }
        // Stored codes.
        $users = get_users([
            'meta_key'   => '_tap_referral_code',
            'meta_value' => $code,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        if (!empty($users)) {
            return (int) $users[0];
        }
        return 0;
    }

    /** Track a click on a referral link. */
    public static function maybe_track_click() {
        if (empty($_GET['ref'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $code = sanitize_text_field(wp_unslash($_GET['ref'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $referrer = self::referrer_by_code($code);
        if (!$referrer) {
            return;
        }
        setcookie('tap_referral_code', $code, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        global $wpdb;
        $wpdb->insert(self::table(), [
            'referrer_user_id' => $referrer,
            'referred_user_id' => get_current_user_id() ? get_current_user_id() : null,
            'referral_code'    => $code,
            'status'           => 'clicked',
        ]);
    }

    /** Affiliate tiers for referrers (based on rewarded referrals). */
    public static function tiers() {
        return [
            'bronze' => ['min' => 0, 'discount' => 0, 'points_rate' => 0.05, 'label' => __('Bronce', 'travel-agency-platform')],
            'silver' => ['min' => 5, 'discount' => 2, 'points_rate' => 0.07, 'label' => __('Plata', 'travel-agency-platform')],
            'gold'   => ['min' => 20, 'discount' => 5, 'points_rate' => 0.10, 'label' => __('Oro', 'travel-agency-platform')],
        ];
    }

    /** Number of rewarded referrals for a user. */
    public static function rewarded_count($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE referrer_user_id = %d AND status = 'rewarded'",
            $user_id
        ));
    }

    /** Tier key for a referrer. */
    public static function tier($user_id) {
        $count = self::rewarded_count($user_id);
        $tier = 'bronze';
        foreach (self::tiers() as $key => $info) {
            if ($count >= $info['min']) {
                $tier = $key;
            }
        }
        return $tier;
    }

    /** Discount percent granted to the invitee (base + tier bonus). */
    public static function discount_pct($referrer_user_id = 0) {
        $base = (float) get_option('tap_referral_discount_pct', 10);
        if ($base < 0) {
            $base = 0;
        }
        if ($base > 50) {
            $base = 50;
        }
        $bonus = 0;
        if ($referrer_user_id) {
            $tiers = self::tiers();
            $tier = self::tier($referrer_user_id);
            $bonus = (float) ($tiers[$tier]['discount'] ?? 0);
        }
        $total = $base + $bonus;
        if ($total > 50) {
            $total = 50;
        }
        return $total;
    }

    /** Points rate awarded to the referrer (base + tier bonus). */
    public static function points_rate($referrer_user_id = 0) {
        $base = (float) get_option('tap_referral_points_rate', 0.05);
        if ($base < 0) {
            $base = 0;
        }
        if ($base > 0.5) {
            $base = 0.5;
        }
        if ($referrer_user_id) {
            $tiers = self::tiers();
            $tier = self::tier($referrer_user_id);
            $base = (float) ($tiers[$tier]['points_rate'] ?? $base);
        }
        if ($base > 0.5) {
            $base = 0.5;
        }
        return $base;
    }

    /** Compute the discount amount for a booking total. */
    public static function discount_for_total($total, $referrer_user_id = 0) {
        $total = (float) $total;
        if ($total <= 0) {
            return 0.0;
        }
        return round($total * (self::discount_pct($referrer_user_id) / 100), 2);
    }

    /** Resolve the active referral code for the current request. */
    public static function active_code() {
        if (!empty($_POST['referral_code'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $code = sanitize_text_field(wp_unslash($_POST['referral_code'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (self::referrer_by_code($code)) {
                return $code;
            }
        }
        if (!empty($_COOKIE['tap_referral_code'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $code = sanitize_text_field(wp_unslash($_COOKIE['tap_referral_code'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (self::referrer_by_code($code)) {
                return $code;
            }
        }
        return '';
    }

    /** Apply a referral to a booking: returns [code, referrer_id, discount]. */
    public static function resolve_for_booking($client_id, $total) {
        $code = self::active_code();
        if ('' === $code) {
            return ['', 0, 0.0];
        }
        $referrer = self::referrer_by_code($code);
        if (!$referrer) {
            return ['', 0, 0.0];
        }
        // Anti-self-referral.
        if ($client_id && (int) $client_id === (int) $referrer) {
            return ['', 0, 0.0];
        }
        $discount = self::discount_for_total($total, $referrer);
        return [$code, $referrer, $discount];
    }

    /** Record a referral row when a booking is created. */
    public static function on_booking_created($booking_id, $client_id, $code, $referrer, $discount) {
        $booking_id = (int) $booking_id;
        if (!$booking_id || !$referrer) {
            return 0;
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'referrer_user_id' => (int) $referrer,
            'referred_user_id' => $client_id ? (int) $client_id : null,
            'referral_code'    => $code,
            'booking_id'       => $booking_id,
            'status'           => 'booked',
            'discount_amount'  => (float) $discount,
        ]);
        update_post_meta($booking_id, '_tap_referral_code', $code);
        update_post_meta($booking_id, '_tap_referrer_user_id', (int) $referrer);
        update_post_meta($booking_id, '_tap_referral_discount', (float) $discount);
        return (int) $wpdb->insert_id;
    }

    /** Award points to the referrer once the booking is paid. */
    public static function on_payment_completed($booking_id) {
        $booking_id = (int) $booking_id;
        if (!$booking_id) {
            return;
        }
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) {
            return;
        }
        $referrer = (int) get_post_meta($booking_id, '_tap_referrer_user_id', true);
        if (!$referrer) {
            return;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE booking_id = %d", $booking_id));
        if ($row && 'rewarded' === $row->status) {
            return;
        }
        $total = (float) ($booking->total_amount ?? 0);
        $points = (int) round($total * self::points_rate($referrer));
        if (class_exists('TAP_Loyalty') && $points > 0) {
            TAP_Loyalty::award($referrer, $points, 'referral', $booking_id);
        }
        if (class_exists('TAP_Emails_Send') && $points > 0) {
            TAP_Emails_Send::send_referral_reward($referrer, $points, $booking_id);
        }
        if ($row) {
            $wpdb->update(self::table(), [
                'status'          => 'rewarded',
                'points_awarded'  => $points,
            ], ['id' => (int) $row->id], ['%s', '%d'], ['%d']);
        } else {
            $code = (string) get_post_meta($booking_id, '_tap_referral_code', true);
            $wpdb->insert(self::table(), [
                'referrer_user_id' => $referrer,
                'referred_user_id' => (int) $booking->client_id,
                'referral_code'    => $code,
                'booking_id'       => $booking_id,
                'status'           => 'rewarded',
                'points_awarded'   => $points,
            ]);
        }
    }

    /** Shortcode: referral dashboard. */
    public static function shortcode_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p class="tap-empty">' . esc_html__('Inicia sesión para ver tu programa de referidos.', 'travel-agency-platform') . '</p>';
        }
        $user_id = get_current_user_id();
        $code = self::code_for_user($user_id);
        $url = self::share_url($user_id);
        $balance = class_exists('TAP_Loyalty') ? TAP_Loyalty::balance($user_id) : 0;
        $rows = [];
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE referrer_user_id = %d ORDER BY id DESC LIMIT 20", $user_id)) ?: [];

        ob_start();
        ?>
        <div class="tap-referral-dashboard">
            <h2><?php esc_html_e('Invita y gana', 'travel-agency-platform'); ?></h2>
            <p><?php esc_html_e('Comparte tu código con amigos y familia. Cuando reserven y paguen, ganarás puntos canjeables.', 'travel-agency-platform'); ?></p>
            <div class="tap-referral-code-box">
                <label><?php esc_html_e('Tu código', 'travel-agency-platform'); ?></label>
                <div class="tap-referral-code"><?php echo esc_html($code); ?></div>
                <label><?php esc_html_e('Tu enlace', 'travel-agency-platform'); ?></label>
                <input type="text" readonly value="<?php echo esc_attr($url); ?>" class="tap-input" onclick="this.select();">
                <div class="tap-referral-qr">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&amp;data=<?php echo urlencode($url); ?>" alt="<?php esc_attr_e('Código QR de tu enlace de referido', 'travel-agency-platform'); ?>" width="160" height="160" loading="lazy">
                </div>
            </div>
            <p><?php printf(esc_html__('Descuento para tu invitado: %s%%. Tus puntos actuales: %s.', 'travel-agency-platform'), number_format_i18n(self::discount_pct($user_id)), number_format_i18n($balance)); ?></p>
            <?php
            $tier = self::tier($user_id);
            $tiers = self::tiers();
            $count = self::rewarded_count($user_id);
            ?>
            <p><?php printf(esc_html__('Nivel de afiliado: %s (%d referidos completados).', 'travel-agency-platform'), esc_html($tiers[$tier]['label']), $count); ?></p>
            <div class="tap-referral-rules">
                <h4><?php esc_html_e('Cómo funciona', 'travel-agency-platform'); ?></h4>
                <ul>
                    <li><?php esc_html_e('Comparte tu código o enlace con amigos y familia.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Tu invitado reserva usando tu código y recibe un descuento inmediato.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Cuando la reserva se paga y completa, tú ganas puntos canjeables.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Los puntos caducan a los 12 meses. Los auto-referidos no cuentan.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Más referidos completados = mayor nivel y mejores bonos.', 'travel-agency-platform'); ?></li>
                </ul>
            </div>
            <?php if ($rows): ?>
                <h3><?php esc_html_e('Tus referidos', 'travel-agency-platform'); ?></h3>
                <ul class="tap-referral-list">
                    <?php foreach ($rows as $r): ?>
                        <li>
                            <span>#<?php echo (int) $r->booking_id; ?></span>
                            <span><?php echo esc_html($r->status); ?></span>
                            <?php if ((int) $r->points_awarded > 0): ?>
                                <span>+<?php echo (int) $r->points_awarded; ?> <?php esc_html_e('pts', 'travel-agency-platform'); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="tap-empty"><?php esc_html_e('Aún no tienes referidos. ¡Comparte tu enlace!', 'travel-agency-platform'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
