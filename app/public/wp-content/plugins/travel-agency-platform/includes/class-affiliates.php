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
        add_shortcode('tap_affiliate_portal', [__CLASS__, 'portal']);
        add_action('admin_post_tap_affiliate_payout_request', [__CLASS__, 'handle_payout_request']);
        add_action('admin_post_nopriv_tap_affiliate_payout_request', [__CLASS__, 'handle_payout_request']);
        add_action('admin_post_tap_affiliate_payout_status', [__CLASS__, 'handle_payout_status']);
    }

    public static function table() {
global $wpdb;
return $wpdb->prefix . 'tap_affiliates'; }
    public static function clicks_table() {
global $wpdb;
return $wpdb->prefix . 'tap_affiliate_clicks'; }
    public static function conv_table() {
global $wpdb;
return $wpdb->prefix . 'tap_affiliate_conversions'; }
    public static function payouts_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_affiliate_payouts';
    }
    public static function payout_items_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_affiliate_payout_items';
    }

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
            'status' => in_array(($data['status'] ?? 'pending'), ['pending', 'active', 'suspended'], true) ? $data['status'] : 'pending',
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
        if (isset($data['code'])) $fields['code'] = sanitize_text_field($data['code']);
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

    /** Affiliate linked to a WordPress user (explicit user_id or matching email). */
    public static function for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) { return null; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d LIMIT 1', $user_id
        ));
        if ($row) { return $row; }
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) { return null; }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE LOWER(email) = %s LIMIT 1',
            strtolower($user->user_email)
        ));
    }

    /** Aggregated metrics for an affiliate. */
    public static function stats($affiliate_id) {
        global $wpdb;
        $id = (int) $affiliate_id;
        $clicks = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::clicks_table() . ' WHERE affiliate_id = %d', $id
        ));
        $conv = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) c,
                    COALESCE(SUM(CASE WHEN status = "released" THEN commission_amount ELSE 0 END),0) released,
                    COALESCE(SUM(CASE WHEN status IN ("pending_payment","pending_tax_form","pending_release") THEN commission_amount ELSE 0 END),0) pending
             FROM ' . self::conv_table() . ' WHERE affiliate_id = %d', $id
        ));
        $paid = (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . self::payouts_table()
            . ' WHERE affiliate_id = %d AND status = "completed"', $id
        ));
        $requested = (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . self::payouts_table()
            . ' WHERE affiliate_id = %d AND status IN ("pending","processing")', $id
        ));
        return [
            'clicks'      => $clicks,
            'conversions' => (int) ($conv->c ?? 0),
            'released'    => round((float) ($conv->released ?? 0), 2),
            'pending'     => round((float) ($conv->pending ?? 0), 2),
            'paid'        => round($paid, 2),
            'requested'   => round($requested, 2),
            'available'   => round(max(0, (float) ($conv->released ?? 0) - $paid - $requested), 2),
        ];
    }

    /** Released conversions not yet included in a payout. */
    public static function available_conversions($affiliate_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.* FROM ' . self::conv_table() . ' c
             WHERE c.affiliate_id = %d AND c.status = "released"
               AND NOT EXISTS (
                 SELECT 1 FROM ' . self::payout_items_table() . ' i
                 WHERE i.conversion_id = c.id
               )
             ORDER BY c.created_at ASC',
            (int) $affiliate_id
        )) ?: [];
    }

    public static function conversions($affiliate_id, $limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, b.booking_code FROM ' . self::conv_table() . ' c
             LEFT JOIN ' . $wpdb->prefix . 'tap_bookings b ON b.id = c.booking_id
             WHERE c.affiliate_id = %d ORDER BY c.created_at DESC LIMIT %d',
            (int) $affiliate_id,
            max(1, (int) $limit)
        )) ?: [];
    }

    public static function payouts($affiliate_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::payouts_table() . ' WHERE affiliate_id = %d ORDER BY requested_at DESC LIMIT 50',
            (int) $affiliate_id
        )) ?: [];
    }

    /** Tracking links for the affiliate code. */
    public static function links($affiliate) {
        $code = rawurlencode((string) $affiliate->code);
        $base = home_url('/?aff=' . $code);
        return [
            ['label' => __('Inicio', 'travel-agency-platform'), 'url' => $base],
            ['label' => __('Buscar experiencias', 'travel-agency-platform'), 'url' => home_url('/search-results/?aff=' . $code)],
            ['label' => __('Tours', 'travel-agency-platform'), 'url' => home_url('/tours/?aff=' . $code)],
            ['label' => __('Alojamientos', 'travel-agency-platform'), 'url' => home_url('/accommodations/?aff=' . $code)],
        ];
    }

    /** Create a payout request from available released commissions. */
    public static function request_payout($affiliate_id, $user_id = 0) {
        global $wpdb;
        $affiliate = self::get($affiliate_id);
        if (!$affiliate || 'active' !== $affiliate->status) {
            return new WP_Error('invalid_affiliate', __('Afiliado no elegible.', 'travel-agency-platform'));
        }
        if ($user_id && !current_user_can('manage_options')) {
            $owner = self::for_user($user_id);
            if (!$owner || (int) $owner->id !== (int) $affiliate->id) {
                return new WP_Error('forbidden', __('No tienes permiso sobre este afiliado.', 'travel-agency-platform'));
            }
        }
        $lock = TAP_Payouts::acquire_lock('affiliate_payout', $affiliate->id);
        if ('' === $lock) {
            return new WP_Error('busy', __('Otra liquidación está en curso. Inténtalo de nuevo.', 'travel-agency-platform'));
        }
        // Re-read available conversions under the lock: a second request must
        // not claim the same conversions as the first one.
        $available = self::available_conversions((int) $affiliate->id);
        if (!$available) {
            TAP_Payouts::release_lock($lock);
            return new WP_Error('nothing_to_pay', __('No hay comisiones disponibles para liquidar.', 'travel-agency-platform'));
        }
        $amount = round(array_sum(array_map(static function ($c) {
            return (float) $c->commission_amount;
        }, $available)), 2);
        if ($amount <= 0) {
            TAP_Payouts::release_lock($lock);
            return new WP_Error('nothing_to_pay', __('No hay comisiones disponibles para liquidar.', 'travel-agency-platform'));
        }
        $wpdb->insert(self::payouts_table(), [
            'affiliate_id' => (int) $affiliate->id,
            'amount'       => $amount,
            'status'       => 'pending',
        ]);
        $payout_id = (int) $wpdb->insert_id;
        foreach ($available as $c) {
            $wpdb->insert(self::payout_items_table(), [
                'payout_id'     => $payout_id,
                'conversion_id' => (int) $c->id,
                'amount'        => (float) $c->commission_amount,
            ]);
            $wpdb->update(self::conv_table(), ['status' => 'payout_requested'], ['id' => (int) $c->id]);
        }
        self::notify_payout_request($payout_id);
        TAP_Payouts::release_lock($lock);
        return $payout_id;
    }

    private static function notify_payout_request($payout_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT p.*, a.name, a.email FROM ' . self::payouts_table() . ' p
             JOIN ' . self::table() . ' a ON a.id = p.affiliate_id WHERE p.id = %d',
            (int) $payout_id
        ));
        if (!$row || !class_exists('TAP_Emails_Send')) { return; }
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: affiliate name, 2: amount */
            __('El afiliado %1$s solicita una liquidación de %2$s.', 'travel-agency-platform'),
            $row->name,
            TAP_Currency::fmt((float) $row->amount)
        )) . '</p>';
        TAP_Emails_Send::send((string) get_option('admin_email'), __('Solicitud de liquidación de afiliado', 'travel-agency-platform'), $body);
    }

    /** Front-end payout request handler. */
    public static function handle_payout_request() {
        if (!is_user_logged_in()) { wp_die(esc_html__('Debes iniciar sesión.', 'travel-agency-platform'), '', ['response' => 403]); }
        $id = absint($_POST['affiliate_id'] ?? 0);
        check_admin_referer('tap_affiliate_payout_' . $id, 'tap_affiliate_nonce');
        $res = self::request_payout($id, get_current_user_id());
        $back = wp_get_referer() ?: home_url('/mi-cuenta/');
        if (is_wp_error($res)) { $back = add_query_arg('aff_error', $res->get_error_code(), $back); }
        else { $back = add_query_arg('aff_ok', '1', $back); }
        wp_safe_redirect($back);
        exit;
    }

    /** Admin settle/cancel handler. */
    public static function handle_payout_status() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $id = absint($_POST['payout_id'] ?? 0);
        check_admin_referer('tap_affiliate_payout_status_' . $id);
        $status = sanitize_key(wp_unslash($_POST['payout_status'] ?? ''));
        if ('completed' === $status) { $res = self::complete_payout($id); }
        elseif (in_array($status, ['cancelled', 'failed'], true)) { $res = self::release_payout($id, $status); }
        else { $res = new WP_Error('invalid_status', __('Estado no válido.', 'travel-agency-platform')); }
        $args = ['page' => 'tap-affiliates'];
        $args[is_wp_error($res) ? 'payout_error' : 'payout'] = is_wp_error($res) ? $res->get_error_code() : '1';
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Complete a pending affiliate payout, capped to the balance still due. */
    public static function complete_payout($payout_id) {
        global $wpdb;
        $payout_id = (int) $payout_id;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::payouts_table() . ' WHERE id = %d AND status = "pending"', $payout_id
        ));
        if (!$row) {
            return new WP_Error('invalid_payout', __('La liquidación no está pendiente.', 'travel-agency-platform'));
        }
        $stats = self::stats((int) $row->affiliate_id);
        $entitlement = round((float) $stats['released'] - (float) $stats['paid'], 2);
        $capped = min(round((float) $row->amount, 2), $entitlement);
        if ($capped <= 0) {
            return new WP_Error('no_balance', __('Ya no hay saldo para esta liquidación.', 'travel-agency-platform'));
        }
        if (!TAP_Payouts::transition($payout_id, ['pending'], 'completed', self::payouts_table())) {
            return new WP_Error('conflict', __('La liquidación cambió mientras se procesaba.', 'travel-agency-platform'));
        }
        $wpdb->update(self::payouts_table(), [
            'amount'  => $capped,
            'paid_at' => current_time('mysql'),
        ], ['id' => $payout_id]);
        return true;
    }

    /** Cancel/fail a payout and release its conversions for a future request. */
    public static function release_payout($payout_id, $status) {
        global $wpdb;
        $payout_id = (int) $payout_id;
        if (!in_array($status, ['cancelled', 'failed'], true)) {
            return new WP_Error('invalid_status', __('Estado no válido.', 'travel-agency-platform'));
        }
        if (!TAP_Payouts::transition($payout_id, ['pending'], $status, self::payouts_table())) {
            return new WP_Error('invalid_payout', __('La liquidación no está pendiente.', 'travel-agency-platform'));
        }
        $conversion_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT conversion_id FROM ' . self::payout_items_table() . ' WHERE payout_id = %d', $payout_id
        ));
        $wpdb->delete(self::payout_items_table(), ['payout_id' => $payout_id]);
        foreach ((array) $conversion_ids as $cid) {
            $cid = (int) $cid;
            $still_linked = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::payout_items_table() . ' WHERE conversion_id = %d', $cid
            ));
            if (!$still_linked) {
                $wpdb->update(self::conv_table(), ['status' => 'released'], ['id' => $cid, 'status' => 'payout_requested']);
            }
        }
        return true;
    }

    /** [tap_affiliate_portal] — self-service affiliate dashboard. */
    public static function portal() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Inicia sesión para ver tu portal de afiliado.', 'travel-agency-platform') . '</p>';
        }
        $affiliate = self::for_user(get_current_user_id());
        if (!$affiliate) {
            return '<p>' . esc_html__('Tu cuenta no está vinculada a ningún afiliado.', 'travel-agency-platform') . '</p>';
        }
        $stats = self::stats((int) $affiliate->id);
        $rows = self::conversions((int) $affiliate->id, 20);
        $payouts = self::payouts((int) $affiliate->id);
        ob_start();
        ?>
        <div class="tap-aff-portal">
            <h2><?php esc_html_e('Portal de afiliado', 'travel-agency-platform'); ?></h2>
            <p class="tap-aff-meta"><?php echo esc_html($affiliate->name); ?> · <code><?php echo esc_html($affiliate->code); ?></code> · <?php echo esc_html($affiliate->status); ?></p>
            <?php if (isset($_GET['aff_ok'])): ?>
                <div class="notice notice-success"><p><?php esc_html_e('Solicitud de liquidación enviada.', 'travel-agency-platform'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['aff_error'])): ?>
                <div class="notice notice-error"><p><?php esc_html_e('No se pudo solicitar la liquidación.', 'travel-agency-platform'); ?></p></div>
            <?php endif; ?>
            <?php if ('active' !== $affiliate->status): ?>
                <p class="tap-aff-note"><?php esc_html_e('Tu afiliado está en revisión; las comisiones se liquidan cuando esté activo.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>
            <div class="tap-aff-metrics">
                <div><span><?php echo (int) $stats['clicks']; ?></span><small><?php esc_html_e('Clics', 'travel-agency-platform'); ?></small></div>
                <div><span><?php echo (int) $stats['conversions']; ?></span><small><?php esc_html_e('Conversiones', 'travel-agency-platform'); ?></small></div>
                <div><span><?php echo esc_html(TAP_Currency::fmt($stats['available'])); ?></span><small><?php esc_html_e('Disponible', 'travel-agency-platform'); ?></small></div>
                <div><span><?php echo esc_html(TAP_Currency::fmt($stats['paid'])); ?></span><small><?php esc_html_e('Pagado', 'travel-agency-platform'); ?></small></div>
            </div>
            <h3><?php esc_html_e('Tus enlaces de seguimiento', 'travel-agency-platform'); ?></h3>
            <ul class="tap-aff-links">
                <?php foreach (self::links($affiliate) as $link): ?>
                    <li><strong><?php echo esc_html($link['label']); ?></strong>: <code><?php echo esc_url($link['url']); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <h3><?php esc_html_e('Conversiones', 'travel-agency-platform'); ?></h3>
            <table class="tap-aff-table"><thead><tr>
                <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td><?php echo esc_html($c->booking_code ?: ('#' . (int) $c->booking_id)); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt((float) $c->commission_amount)); ?></td>
                        <td><?php echo esc_html($c->status); ?></td>
                        <td><?php echo esc_html(substr((string) $c->created_at, 0, 10)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="4"><?php esc_html_e('Aún no hay conversiones.', 'travel-agency-platform'); ?></td></tr>
                <?php endif; ?>
            </tbody></table>
            <?php if ($stats['available'] > 0 && 'active' === $affiliate->status): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-aff-request">
                    <input type="hidden" name="action" value="tap_affiliate_payout_request">
                    <input type="hidden" name="affiliate_id" value="<?php echo (int) $affiliate->id; ?>">
                    <?php wp_nonce_field('tap_affiliate_payout_' . (int) $affiliate->id, 'tap_affiliate_nonce'); ?>
                    <p><?php echo esc_html(sprintf(__('Solicitar liquidación de %s', 'travel-agency-platform'), TAP_Currency::fmt($stats['available']))); ?></p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Solicitar pago', 'travel-agency-platform'); ?></button>
                </form>
            <?php endif; ?>
            <h3><?php esc_html_e('Historial de liquidaciones', 'travel-agency-platform'); ?></h3>
            <table class="tap-aff-table"><thead><tr>
                <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Solicitada', 'travel-agency-platform'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($payouts as $p): ?>
                    <tr><td><?php echo esc_html(TAP_Currency::fmt((float) $p->amount)); ?></td><td><?php echo esc_html($p->status); ?></td><td><?php echo esc_html(substr((string) $p->requested_at, 0, 10)); ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$payouts): ?>
                    <tr><td colspan="3"><?php esc_html_e('Aún no hay liquidaciones.', 'travel-agency-platform'); ?></td></tr>
                <?php endif; ?>
            </tbody></table>
        </div>
        <?php
        return ob_get_clean();
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
                'code'  => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
                'commission_pct' => (float) ($_POST['commission_pct'] ?? 5),
                'cap_per_booking' => (float) ($_POST['cap_per_booking'] ?? 30),
                'status' => sanitize_key(wp_unslash($_POST['status'] ?? 'pending')),
                'tax_form' => sanitize_text_field(wp_unslash($_POST['tax_form'] ?? '')),
            ];
            if ($id) self::update($id, $data);
else self::create($data);
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

            <h2><?php esc_html_e('Solicitudes de liquidación', 'travel-agency-platform'); ?></h2>
            <?php
            $pending_payouts = $wpdb->get_results(
                'SELECT p.*, a.name affiliate_name FROM ' . self::payouts_table() . ' p
                 LEFT JOIN ' . self::table() . ' a ON a.id = p.affiliate_id
                 WHERE p.status = "pending" ORDER BY p.requested_at DESC LIMIT 50'
            );
            ?>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Afiliado', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
            </tr></thead><tbody>
                <?php foreach ((array) $pending_payouts as $p): ?>
                    <tr>
                        <td><?php echo esc_html($p->affiliate_name ?: ('#' . (int) $p->affiliate_id)); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt((float) $p->amount)); ?></td>
                        <td><?php echo esc_html(substr((string) $p->requested_at, 0, 10)); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('tap_affiliate_payout_status_' . (int) $p->id); ?>
                                <input type="hidden" name="action" value="tap_affiliate_payout_status">
                                <input type="hidden" name="payout_id" value="<?php echo (int) $p->id; ?>">
                                <input type="hidden" name="payout_status" value="completed">
                                <input type="text" name="note" class="small-text" placeholder="<?php echo esc_attr__('Referencia', 'travel-agency-platform'); ?>">
                                <button class="button button-small" type="submit"><?php esc_html_e('Marcar pagado', 'travel-agency-platform'); ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('tap_affiliate_payout_status_' . (int) $p->id); ?>
                                <input type="hidden" name="action" value="tap_affiliate_payout_status">
                                <input type="hidden" name="payout_id" value="<?php echo (int) $p->id; ?>">
                                <input type="hidden" name="payout_status" value="cancelled">
                                <button class="button button-small" type="submit"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pending_payouts): ?>
                    <tr><td colspan="4"><?php esc_html_e('No hay solicitudes pendientes.', 'travel-agency-platform'); ?></td></tr>
                <?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }
}