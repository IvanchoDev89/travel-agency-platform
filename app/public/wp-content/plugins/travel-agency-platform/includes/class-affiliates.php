<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * External affiliates program (pilot).
 */
class TAP_Affiliates {

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_track_click'], 5);
        add_action('admin_menu', [__CLASS__, 'add_page'], 40);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 30, 1);
        // Daily release of pending commissions.
        add_action('tap_affiliate_release_hook', [__CLASS__, 'release_pending']);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_affiliates'; }
    public static function clicks_table() { global $wpdb; return $wpdb->prefix . 'tap_affiliate_clicks'; }
    public static function conv_table() { global $wpdb; return $wpdb->prefix . 'tap_affiliate_conversions'; }

    /** Track a click on an affiliate link. */
    public static function maybe_track_click() {
        if (empty($_GET['aff'])) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = sanitize_text_field(wp_unslash($_GET['aff'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $aff = self::by_code($code);
        if (!$aff) return;
        // Rate limit per IP.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rl = (int) get_transient('tap_aff_rl_' . md5($ip));
        if ($rl >= 20) return;
        set_transient('tap_aff_rl_' . md5($ip), $rl + 1, HOUR_IN_SECONDS);
        global $wpdb;
        $wpdb->insert(self::clicks_table(), [
            'affiliate_id' => (int) $aff->id,
            'ip' => $ip,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
        ]);
        setcookie('tap_affiliate_code', $code, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    /** Resolve active affiliate code from cookie/POST. */
    public static function active_code() {
        if (!empty($_POST['affiliate_code'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $code = sanitize_text_field(wp_unslash($_POST['affiliate_code'])); // phpcs:ignore
            if (self::by_code($code)) return $code;
        }
        if (!empty($_COOKIE['tap_affiliate_code'])) { // phpcs:ignore
            $code = sanitize_text_field(wp_unslash($_COOKIE['tap_affiliate_code'])); // phpcs:ignore
            if (self::by_code($code)) return $code;
        }
        return '';
    }

    /** Find affiliate by code. */
    public static function by_code($code) {
        global $wpdb;
        $code = sanitize_text_field((string) $code);
        if ('' === $code) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE code = %s AND status = 'active'", $code));
    }

    /** Generate unique code. */
    public static function generate_code($name) {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6));
        if ('' === $base) $base = 'AFF';
        $code = $base . wp_generate_password(4, false);
        global $wpdb;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE code = %s", $code))) {
            $code = $base . wp_generate_password(4, false);
        }
        return $code;
    }

    /** Create affiliate. */
    public static function create($data) {
        global $wpdb;
        $name = sanitize_text_field($data['name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        if ('' === $name || !is_email($email)) return 0;
        $code = !empty($data['code']) ? sanitize_text_field($data['code']) : self::generate_code($name);
        $wpdb->insert(self::table(), [
            'name' => $name,
            'email' => $email,
            'code' => $code,
            'commission_pct' => isset($data['commission_pct']) ? (float) $data['commission_pct'] : 5.00,
            'cap_per_booking' => isset($data['cap_per_booking']) ? (float) $data['cap_per_booking'] : 30.00,
            'status' => 'pending',
            'tax_form' => isset($data['tax_form']) ? sanitize_text_field($data['tax_form']) : null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Update affiliate. */
    public static function update($id, $data) {
        global $wpdb;
        $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        if (isset($data['name'])) $fields['name'] = sanitize_text_field($data['name']);
        if (isset($data['email'])) $fields['email'] = sanitize_email($data['email']);
        if (isset($data['commission_pct'])) $fields['commission_pct'] = (float) $data['commission_pct'];
        if (isset($data['cap_per_booking'])) $fields['cap_per_booking'] = (float) $data['cap_per_booking'];
        if (isset($data['status'])) $fields['status'] = sanitize_key($data['status']);
        if (isset($data['tax_form'])) $fields['tax_form'] = sanitize_text_field($data['tax_form']);
        if (empty($fields)) return false;
        $wpdb->update(self::table(), $fields, ['id' => $id]);
        return true;
    }

    /** List affiliates. */
    public static function all() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY created_at DESC") ?: [];
    }

    /** Get affiliate by id. */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id));
    }

    /** Register conversion on booking creation. */
    public static function on_booking_created($booking_id, $code) {
        $aff = self::by_code($code);
        if (!$aff) return 0;
        global $wpdb;
        $t = self::conv_table();
        // Idempotency.
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE booking_id = %d", (int) $booking_id));
        if ($existing) return $existing;
        $commission = min((float) $aff->cap_per_booking, round(0, 2)); // placeholder, updated on payment
        $wpdb->insert($t, [
            'affiliate_id' => (int) $aff->id,
            'booking_id' => (int) $booking_id,
            'commission_amount' => 0,
            'status' => 'pending_payment',
            'release_at' => gmdate('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Award commission on payment. */
    public static function on_payment_completed($booking_id) {
        $booking_id = (int) $booking_id;
        if (!$booking_id) return;
        global $wpdb;
        $t = self::conv_table();
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE booking_id = %d", $booking_id));
        if (!$conv || 'rewarded' === $conv->status) return;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) return;
        $aff = self::get((int) $conv->affiliate_id);
        if (!$aff || 'active' !== $aff->status) return;
        // Tax form required before payment.
        if (empty($aff->tax_form)) {
            $wpdb->update($t, ['status' => 'pending_tax_form'], ['id' => (int) $conv->id]);
            return;
        }
        $total = (float) $booking->total_amount;
        $commission = round($total * ((float) $aff->commission_pct / 100), 2);
        if ($commission > (float) $aff->cap_per_booking) {
            $commission = (float) $aff->cap_per_booking;
        }
        $wpdb->update($t, [
            'commission_amount' => $commission,
            'status' => 'pending_release',
            'release_at' => gmdate('Y-m-d H:i:s', strtotime('+14 days')),
        ], ['id' => (int) $conv->id]);
    }

    /** Release pending commissions (daily cron). */
    public static function release_pending() {
        global $wpdb;
        $t = self::conv_table();
        $wpdb->query("UPDATE {$t} SET status = 'released' WHERE status = 'pending_release' AND release_at <= NOW()");
    }

    /** Admin page. */
    public static function add_page() {
        add_submenu_page('travel-platform', __('Afiliados', 'travel-agency-platform'), __('Afiliados', 'travel-agency-platform'), 'manage_options', 'tap-affiliates', [__CLASS__, 'render_page']);
    }

    public static function handle_actions() {
        if (!isset($_POST['tap_affiliate_action'])) return;
        if (!current_user_can('manage_options')) wp_die('');
        check_admin_referer('tap_affiliate_nonce');
        $action = sanitize_key(wp_unslash($_POST['tap_affiliate_action']));
        if ('save' === $action) {
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $data = [
                'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                'commission_pct' => (float) ($_POST['commission_pct'] ?? 5),
                'cap_per_booking' => (float) ($_POST['cap_per_booking'] ?? 30),
                'status' => sanitize_key(wp_unslash($_POST['status'] ?? 'pending')),
                'tax_form' => sanitize_text_field(wp_unslash($_POST['tax_form'] ?? '')),
            ];
            if ($id) self::update($id, $data); else self::create($data);
            wp_safe_redirect(add_query_arg(['page' => 'tap-affiliates', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }
        if ('delete' === $action) {
            global $wpdb;
            $wpdb->delete(self::table(), ['id' => absint($_POST['id'] ?? 0)], ['%d']);
            wp_safe_redirect(add_query_arg(['page' => 'tap-affiliates', 'deleted' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $affiliates = self::all();
        $editing = isset($_GET['edit']) ? self::get(absint($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Afiliados externos', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>

            <h2><?php echo $editing ? esc_html__('Editar afiliado', 'travel-agency-platform') : esc_html__('Nuevo afiliado', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_affiliate_nonce'); ?>
                <input type="hidden" name="tap_affiliate_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th><td><input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Email', 'travel-agency-platform'); ?></th><td><input type="email" name="email" value="<?php echo esc_attr($editing->email ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th><td><input type="text" name="code" value="<?php echo esc_attr($editing->code ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('Auto-generado si se deja vacío', 'travel-agency-platform'); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Comisión (%)', 'travel-agency-platform'); ?></th><td><input type="number" name="commission_pct" value="<?php echo esc_attr($editing->commission_pct ?? 5); ?>" step="0.5" min="0" max="50" class="small-text"></td></tr>
                    <tr><th><?php esc_html_e('Tope por reserva', 'travel-agency-platform'); ?></th><td><input type="number" name="cap_per_booking" value="<?php echo esc_attr($editing->cap_per_booking ?? 30); ?>" step="1" min="0" class="small-text"></td></tr>
                    <tr><th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th><td>
                        <select name="status">
                            <option value="pending" <?php selected($editing->status ?? 'pending', 'pending'); ?>><?php esc_html_e('Pendiente (revisión)', 'travel-agency-platform'); ?></option>
                            <option value="active" <?php selected($editing->status ?? 'pending', 'active'); ?>><?php esc_html_e('Activo', 'travel-agency-platform'); ?></option>
                            <option value="suspended" <?php selected($editing->status ?? 'pending', 'suspended'); ?>><?php esc_html_e('Suspendido', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Formulario fiscal (W-9/W-8)', 'travel-agency-platform'); ?></th><td><input type="text" name="tax_form" value="<?php echo esc_attr($editing->tax_form ?? ''); ?>" class="regular-text" placeholder="W-9 / W-8BEN"></td></tr>
                </table>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Afiliados', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tope', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tax form', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($affiliates as $a): ?>
                        <tr>
                            <td><?php echo esc_html($a->name); ?><br><small><?php echo esc_html($a->email); ?></small></td>
                            <td><code><?php echo esc_html($a->code); ?></code><br><small><?php echo esc_url(home_url('/?aff=' . $a->code)); ?></small></td>
                            <td><?php echo esc_html($a->commission_pct); ?>%</td>
                            <td><?php echo esc_html(TAP_Currency::fmt($a->cap_per_booking)); ?></td>
                            <td><?php echo esc_html($a->status); ?></td>
                            <td><?php echo esc_html($a->tax_form ?: '—'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-affiliates', 'edit' => $a->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_affiliate_nonce'); ?>
                                    <input type="hidden" name="tap_affiliate_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$affiliates): ?>
                        <tr><td colspan="7"><?php esc_html_e('No hay afiliados.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}