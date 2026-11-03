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

    /** Discount percent granted to the invitee. */
    public static function discount_pct() {
        $pct = (float) get_option('tap_referral_discount_pct', 10);
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 50) {
            $pct = 50;
        }
        return $pct;
    }

    /** Points rate awarded to the referrer (fraction of booking total). */
    public static function points_rate() {
        $rate = (float) get_option('tap_referral_points_rate', 0.05);
        if ($rate < 0) {
            $rate = 0;
        }
        if ($rate > 0.5) {
            $rate = 0.5;
        }
        return $rate;
    }

    /** Compute the discount amount for a booking total. */
    public static function discount_for_total($total) {
        $total = (float) $total;
        if ($total <= 0) {
            return 0.0;
        }
        return round($total * (self::discount_pct() / 100), 2);
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
        $discount = self::discount_for_total($total);
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
        $points = (int) round($total * self::points_rate());
        if (class_exists('TAP_Loyalty') && $points > 0) {
            TAP_Loyalty::award($referrer, $points, 'referral', $booking_id);
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
            <p><?php printf(esc_html__('Descuento para tu invitado: %s%%. Tus puntos actuales: %s.', 'travel-agency-platform'), number_format_i18n(self::discount_pct()), number_format_i18n($balance)); ?></p>
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
