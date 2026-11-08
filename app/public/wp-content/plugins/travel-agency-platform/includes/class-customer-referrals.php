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
        register_setting('tap_referral_group', 'tap_referral_manual_threshold', [
            'type' => 'number',
            'sanitize_callback' => [__CLASS__, 'sanitize_threshold'],
            'default' => 500,
        ]);
    }

    public static function sanitize_threshold($value) {
        $value = (int) $value;
        return $value < 0 ? 0 : $value;
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
        $threshold = (int) get_option('tap_referral_manual_threshold', 500);
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
                    <tr>
                        <th scope="row"><label for="tap_referral_manual_threshold"><?php esc_html_e('Umbral de revisión manual (puntos)', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="number" id="tap_referral_manual_threshold" name="tap_referral_manual_threshold" value="<?php echo esc_attr($threshold); ?>" min="0" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('Las recompensas con estos puntos o más quedan pendientes de aprobación manual. 0 desactiva el límite.', 'travel-agency-platform'); ?></p>
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

    /** Stable browser fingerprint used only to flag suspicious referrals. */
    public static function fingerprint() {
        if (!empty($_COOKIE['tap_ref_fp'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $cookie = sanitize_text_field(wp_unslash($_COOKIE['tap_ref_fp'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (preg_match('/^[a-f0-9]{32,64}$/', $cookie)) {
                return $cookie;
            }
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return hash('sha256', $ua . '|' . $ip);
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
        // Rate limit click tracking per IP to prevent row spam.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rl_key = 'tap_ref_rl_' . md5($ip);
        $rl = (int) get_transient($rl_key);
        if ($rl >= 10) {
            return;
        }
        set_transient($rl_key, $rl + 1, HOUR_IN_SECONDS);

        $fingerprint = self::fingerprint();
        setcookie('tap_ref_fp', $fingerprint, [
            'expires'  => time() + (30 * DAY_IN_SECONDS),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        setcookie('tap_referral_code', $code, [
            'expires'  => time() + (30 * DAY_IN_SECONDS),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        global $wpdb;
        $wpdb->insert(self::table(), [
            'referrer_user_id' => $referrer,
            'referred_user_id' => get_current_user_id() ? get_current_user_id() : null,
            'referral_code'    => $code,
            'status'           => 'clicked',
            'fingerprint'      => $fingerprint,
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
    public static function active_code($preferred = '') {
        $preferred = sanitize_text_field((string) $preferred);
        if ('' !== $preferred && self::referrer_by_code($preferred)) {
            return $preferred;
        }
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
    public static function resolve_for_booking($client_id, $total, $preferred_code = '', $guest_email = '') {
        $code = self::active_code($preferred_code);
        if ('' === $code) {
            return ['', 0, 0.0];
        }
        $referrer = self::referrer_by_code($code);
        if (!$referrer) {
            return ['', 0, 0.0];
        }
        // Anti-self-referral (logged-in user).
        if ($client_id && (int) $client_id === (int) $referrer) {
            return ['', 0, 0.0];
        }
        // Anti-self-referral (guest checkout using the referrer's own email).
        $guest_email = sanitize_email((string) $guest_email);
        if ($guest_email && is_email($guest_email)) {
            $referrer_user = get_userdata((int) $referrer);
            if ($referrer_user && strtolower($referrer_user->user_email) === strtolower($guest_email)) {
                return ['', 0, 0.0];
            }
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
        // Idempotency: do not duplicate a referral row for the same booking.
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE booking_id = %d", $booking_id));
        if ($existing) {
            return $existing;
        }
        $wpdb->insert(self::table(), [
            'referrer_user_id' => (int) $referrer,
            'referred_user_id' => $client_id ? (int) $client_id : null,
            'referral_code'    => $code,
            'booking_id'       => $booking_id,
            'status'           => 'booked',
            'discount_amount'  => (float) $discount,
            'fingerprint'      => self::fingerprint(),
        ]);
        update_post_meta($booking_id, '_tap_referral_code', $code);
        update_post_meta($booking_id, '_tap_referrer_user_id', (int) $referrer);
        update_post_meta($booking_id, '_tap_referral_discount', (float) $discount);
        $row_id = (int) $wpdb->insert_id;

        // Anti-fraud: several different invitees booked from the same browser
        // fingerprint within 24h. This only flags the rows for manual review;
        // it never blocks or rejects a booking.
        $fp = self::fingerprint();
        if ('' !== $fp) {
            $recent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT referred_user_id) FROM " . self::table() . "
                 WHERE fingerprint = %s AND booking_id IS NOT NULL AND created_at >= %s",
                $fp,
                gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            ));
            if ($recent >= 3) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . self::table() . " SET is_suspicious = 1 WHERE fingerprint = %s",
                    $fp
                ));
            }
        }
        return $row_id;
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
        if ($row && in_array($row->status, ['rewarded', 'rejected'], true)) {
            return;
        }
        $total = (float) ($booking->total_amount ?? 0);
        $points = (int) round($total * self::points_rate($referrer));

        // High-value payouts and flagged referrals need a human decision
        // before points are credited.
        $threshold   = (int) get_option('tap_referral_manual_threshold', 500);
        $suspicious  = $row ? (int) $row->is_suspicious : 0;
        $needs_review = $suspicious > 0 || ($threshold > 0 && $points >= $threshold);
        if ($needs_review) {
            if ($row) {
                $wpdb->update(self::table(), [
                    'status'         => 'pending_review',
                    'points_awarded' => 0,
                ], ['id' => (int) $row->id], ['%s', '%d'], ['%d']);
            } else {
                $code = (string) get_post_meta($booking_id, '_tap_referral_code', true);
                $wpdb->insert(self::table(), [
                    'referrer_user_id' => $referrer,
                    'referred_user_id' => (int) $booking->client_id,
                    'referral_code'    => $code,
                    'booking_id'       => $booking_id,
                    'status'           => 'pending_review',
                    'points_awarded'   => 0,
                    'fingerprint'      => self::fingerprint(),
                ]);
            }
            return;
        }

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
                'fingerprint'      => self::fingerprint(),
            ]);
        }
    }

    /** Referrals waiting for a manual payout decision. */
    public static function pending_review($limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'pending_review' ORDER BY created_at ASC LIMIT %d",
            (int) $limit
        )) ?: [];
    }

    /** Approve a pending referral: credit its points and mark it rewarded. */
    public static function approve_pending($row_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $row_id));
        if (!$row || 'pending_review' !== $row->status) {
            return false;
        }
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $row->booking_id));
        $total   = $booking ? (float) ($booking->total_amount ?? 0) : 0.0;
        $points  = (int) round($total * self::points_rate((int) $row->referrer_user_id));
        if (class_exists('TAP_Loyalty') && $points > 0) {
            TAP_Loyalty::award((int) $row->referrer_user_id, $points, 'referral', (int) $row->booking_id);
        }
        if (class_exists('TAP_Emails_Send') && $points > 0) {
            TAP_Emails_Send::send_referral_reward((int) $row->referrer_user_id, $points, (int) $row->booking_id);
        }
        $wpdb->update(self::table(), [
            'status'         => 'rewarded',
            'points_awarded' => $points,
        ], ['id' => (int) $row->id], ['%s', '%d'], ['%d']);
        return true;
    }

    /** Reject a pending referral without crediting points. */
    public static function reject_pending($row_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $row_id));
        if (!$row || 'pending_review' !== $row->status) {
            return false;
        }
        $wpdb->update(self::table(), [
            'status'         => 'rejected',
            'points_awarded' => 0,
        ], ['id' => (int) $row->id], ['%s', '%d'], ['%d']);
        return true;
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

        $tier = self::tier($user_id);
        $tiers = self::tiers();
        $count = self::rewarded_count($user_id);
        // Progress to next tier.
        $next_tier = null;
        $next_min = null;
        foreach ($tiers as $key => $info) {
            if ($info['min'] > $count) {
                $next_tier = $key;
                $next_min = $info['min'];
                break;
            }
        }
        $progress_pct = $next_min ? min(100, round(($count / $next_min) * 100)) : 100;

        ob_start();
        ?>
        <div class="tap-referral-dashboard tap-referral-dashboard--cool">
            <div class="tap-referral-hero">
                <div class="tap-referral-hero-text">
                    <span class="tap-referral-eyebrow"><?php esc_html_e('Programa de referidos', 'travel-agency-platform'); ?></span>
                    <h2><?php esc_html_e('Invita y gana', 'travel-agency-platform'); ?></h2>
                    <p><?php esc_html_e('Comparte tu código con amigos y familia. Cuando reserven y paguen, ganarás puntos canjeables.', 'travel-agency-platform'); ?></p>
                </div>
                <div class="tap-referral-tier-badge tap-referral-tier-<?php echo esc_attr($tier); ?>">
                    <span class="tap-referral-tier-label"><?php echo esc_html($tiers[$tier]['label']); ?></span>
                    <span class="tap-referral-tier-sub"><?php esc_html_e('Nivel actual', 'travel-agency-platform'); ?></span>
                </div>
            </div>

            <div class="tap-referral-stats">
                <div class="tap-referral-stat">
                    <span class="tap-referral-stat-value"><?php echo (int) $balance; ?></span>
                    <span class="tap-referral-stat-label"><?php esc_html_e('Puntos disponibles', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-referral-stat">
                    <span class="tap-referral-stat-value"><?php echo (int) $count; ?></span>
                    <span class="tap-referral-stat-label"><?php esc_html_e('Referidos completados', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-referral-stat">
                    <span class="tap-referral-stat-value"><?php echo number_format_i18n(self::discount_pct($user_id)); ?>%</span>
                    <span class="tap-referral-stat-label"><?php esc_html_e('Descuento invitado', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-referral-stat">
                    <span class="tap-referral-stat-value"><?php echo (int) ($tiers[$tier]['points_rate'] * 100); ?>%</span>
                    <span class="tap-referral-stat-label"><?php esc_html_e('Tasa de puntos', 'travel-agency-platform'); ?></span>
                </div>
            </div>

            <?php if ($next_tier): ?>
            <div class="tap-referral-progress">
                <div class="tap-referral-progress-head">
                    <span><?php printf(esc_html__('Progreso a %s', 'travel-agency-platform'), esc_html($tiers[$next_tier]['label'])); ?></span>
                    <span><?php echo (int) $count; ?> / <?php echo (int) $next_min; ?></span>
                </div>
                <div class="tap-referral-progress-bar"><div class="tap-referral-progress-fill" style="width: <?php echo (int) $progress_pct; ?>%;"></div></div>
            </div>
            <?php endif; ?>

            <div class="tap-referral-code-box">
                <div class="tap-referral-code-row">
                    <div>
                        <label><?php esc_html_e('Tu código', 'travel-agency-platform'); ?></label>
                        <div class="tap-referral-code"><?php echo esc_html($code); ?></div>
                    </div>
                    <div class="tap-referral-qr">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&amp;data=<?php echo urlencode($url); ?>" alt="<?php esc_attr_e('Código QR de tu enlace de referido', 'travel-agency-platform'); ?>" width="150" height="150" loading="lazy">
                    </div>
                </div>
                <label><?php esc_html_e('Tu enlace', 'travel-agency-platform'); ?></label>
                <div class="tap-referral-link-row">
                    <input type="text" readonly value="<?php echo esc_attr($url); ?>" class="tap-input tap-referral-link-input" onclick="this.select();">
                    <button type="button" class="tap-btn tap-btn-primary tap-referral-copy" data-copy="<?php echo esc_attr($url); ?>"><?php esc_html_e('Copiar', 'travel-agency-platform'); ?></button>
                </div>
                <div class="tap-referral-share">
                    <a class="tap-referral-share-btn tap-referral-share-wa" href="https://wa.me/?text=<?php echo urlencode($url); ?>" target="_blank" rel="noopener">WhatsApp</a>
                    <a class="tap-referral-share-btn tap-referral-share-mail" href="mailto:?subject=<?php echo urlencode(__('Mira este viaje', 'travel-agency-platform')); ?>&body=<?php echo urlencode($url); ?>">Email</a>
                </div>
            </div>

            <details class="tap-referral-rules">
                <summary><?php esc_html_e('Cómo funciona', 'travel-agency-platform'); ?></summary>
                <ul>
                    <li><?php esc_html_e('Comparte tu código o enlace con amigos y familia.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Tu invitado reserva usando tu código y recibe un descuento inmediato.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Cuando la reserva se paga y completa, tú ganas puntos canjeables.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Los puntos caducan a los 12 meses. Los auto-referidos no cuentan.', 'travel-agency-platform'); ?></li>
                    <li><?php esc_html_e('Más referidos completados = mayor nivel y mejores bonos.', 'travel-agency-platform'); ?></li>
                </ul>
            </details>

            <div class="tap-referral-list-wrap">
                <h3><?php esc_html_e('Tus referidos', 'travel-agency-platform'); ?></h3>
                <?php if ($rows): ?>
                    <ul class="tap-referral-list">
                        <?php foreach ($rows as $r): ?>
                            <li>
                                <div class="tap-referral-list-main">
                                    <span class="tap-referral-list-id">#<?php echo (int) $r->booking_id; ?></span>
                                    <span class="tap-referral-list-status tap-referral-status-<?php echo esc_attr($r->status); ?>"><?php echo esc_html($r->status); ?></span>
                                </div>
                                <?php if ((int) $r->points_awarded > 0): ?>
                                    <span class="tap-referral-list-points">+<?php echo (int) $r->points_awarded; ?> <?php esc_html_e('pts', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="tap-empty"><?php esc_html_e('Aún no tienes referidos. ¡Comparte tu enlace!', 'travel-agency-platform'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.tap-referral-copy').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var txt = btn.getAttribute('data-copy') || '';
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(txt);
                        btn.textContent = '<?php echo esc_js(__('Copiado', 'travel-agency-platform')); ?>';
                        setTimeout(function(){ btn.textContent = '<?php echo esc_js(__('Copiar', 'travel-agency-platform')); ?>'; }, 1500);
                    } else {
                        var inp = btn.previousElementSibling;
                        if (inp) { inp.select(); document.execCommand('copy'); }
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
