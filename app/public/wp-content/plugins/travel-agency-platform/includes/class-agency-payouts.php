<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Agency net-earnings payout requests.
 *
 * Commission_amount is what the agency pays the platform, so the agency's
 * receivable is the booking total minus refunds, platform fee and commission.
 * Requests are server-computed and never double-count.
 */
class TAP_Agency_Payouts {

    public static function init() {
        add_action('admin_post_tap_agency_payout_request', [__CLASS__, 'handle_request']);
        add_action('admin_post_tap_agency_payout_status', [__CLASS__, 'handle_status']);
        add_action('admin_menu', [__CLASS__, 'add_page'], 37);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_agency_payouts';
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Liquidaciones de agencias', 'travel-agency-platform'),
            __('Liquidaciones de agencias', 'travel-agency-platform'),
            'tap_manage_commissions',
            'tap-agency-payouts',
            [__CLASS__, 'admin_page']
        );
    }

    /** Gross, fee, commission, net, already paid and available for an agency. */
    public static function earnings($agency_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(total_amount - COALESCE(refund_amount,0) - COALESCE(insurance_premium,0)),0) gross,
                COALESCE(SUM(COALESCE(booking_fee,0)),0) fees,
                COALESCE(SUM(" . TAP_Booking_Stats::commission_case() . "),0) commission
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d AND payment_status = 'paid'
               AND status NOT IN ('cancelled','refunded')",
            (int) $agency_id
        ));
        $gross = (float) ($row->gross ?? 0);
        $fees = (float) ($row->fees ?? 0);
        $commission = (float) ($row->commission ?? 0);
        $net = round($gross - $fees - $commission, 2);
        $paid = (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE agency_id = %d AND status = "completed"',
            (int) $agency_id
        ));
        $pending = (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE agency_id = %d AND status = "pending"',
            (int) $agency_id
        ));
        return [
            'gross' => round($gross, 2),
            'fees' => round($fees, 2),
            'commission' => round($commission, 2),
            'net' => $net,
            'paid' => round($paid, 2),
            'pending' => round($pending, 2),
            'available' => round(max(0, $net - $paid - $pending), 2),
        ];
    }

    public static function requests($agency_id, $limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE agency_id = %d ORDER BY requested_at DESC LIMIT %d',
            (int) $agency_id,
            max(1, (int) $limit)
        )) ?: [];
    }

    /** Create a request for the full available balance. */
    public static function request($agency_id, $user_id = 0) {
        global $wpdb;
        $agency_id = (int) $agency_id;
        $agency = get_post($agency_id);
        if (!$agency || 'tap_agency' !== $agency->post_type) {
            return new WP_Error('invalid_agency', __('Agencia no válida.', 'travel-agency-platform'));
        }
        if ($user_id) {
            $linked = (int) TAP_Booking::get_agency_for_user($user_id);
            if ($linked !== $agency_id) {
                return new WP_Error('forbidden', __('No tienes permiso sobre esta agencia.', 'travel-agency-platform'));
            }
        }
        // Two simultaneous requests must never grant the same balance twice.
        $lock = TAP_Payouts::acquire_lock('agency_payout', $agency_id);
        if ('' === $lock) {
            return new WP_Error('busy', __('Otra liquidación está en curso. Inténtalo de nuevo.', 'travel-agency-platform'));
        }
        // Re-read the balance INSIDE the lock; it may have changed between the
        // request arriving and acquiring the lock.
        $earnings = self::earnings($agency_id);
        if ($earnings['available'] <= 0) {
            TAP_Payouts::release_lock($lock);
            return new WP_Error('nothing_to_pay', __('No hay saldo disponible para liquidar.', 'travel-agency-platform'));
        }
        $wpdb->insert(self::table(), [
            'agency_id' => $agency_id,
            'amount' => $earnings['available'],
            'status' => 'pending',
        ]);
        $payout_id = (int) $wpdb->insert_id;
        TAP_Payouts::release_lock($lock);
        return $payout_id;
    }

    public static function handle_request() {
        if (!is_user_logged_in()) { wp_die(esc_html__('Debes iniciar sesión.', 'travel-agency-platform'), '', ['response' => 403]); }
        $id = absint($_POST['agency_id'] ?? 0);
        check_admin_referer('tap_agency_payout_' . $id, 'tap_agency_nonce');
        $res = self::request($id, get_current_user_id());
        $back = wp_get_referer() ?: home_url('/mi-cuenta/');
        if (is_wp_error($res)) { $back = add_query_arg('payout_error', $res->get_error_code(), $back); }
        else { $back = add_query_arg('payout_ok', '1', $back); }
        wp_safe_redirect($back);
        exit;
    }

    public static function handle_status() {
        if (!current_user_can('tap_manage_commissions')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $id = absint($_POST['payout_id'] ?? 0);
        check_admin_referer('tap_agency_payout_status_' . $id);
        $status = sanitize_key(wp_unslash($_POST['payout_status'] ?? ''));
        $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));
        if ('completed' === $status) { $res = self::complete($id); }
        elseif ('cancelled' === $status) { $res = self::cancel($id); }
        elseif ('failed' === $status) { $res = self::fail($id, $note); }
        else { $res = new WP_Error('invalid_status', __('Estado no válido.', 'travel-agency-platform')); }
        $args = ['page' => 'tap-agency-payouts'];
        $args[is_wp_error($res) ? 'payout_error' : 'updated'] = is_wp_error($res) ? $res->get_error_code() : '1';
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Atomically complete a pending request, capping it to the balance that
     * really exists at transfer time (bookings may have been refunded since). */
    public static function complete($payout_id) {
        global $wpdb;
        $payout_id = (int) $payout_id;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d AND status = "pending"', $payout_id
        ));
        if (!$row) {
            return new WP_Error('invalid_payout', __('La liquidación no está pendiente.', 'travel-agency-platform'));
        }
        $earnings = self::earnings((int) $row->agency_id);
        $entitlement = round((float) $earnings['net'] - (float) $earnings['paid'], 2);
        $capped = min(round((float) $row->amount, 2), $entitlement);
        if ($capped <= 0) {
            return new WP_Error('no_balance', __('Ya no hay saldo para esta liquidación; cancélala y vuelve a solicitarla.', 'travel-agency-platform'));
        }
        if (!TAP_Payouts::transition($payout_id, ['pending'], 'completed', self::table())) {
            return new WP_Error('conflict', __('La liquidación cambió mientras se procesaba.', 'travel-agency-platform'));
        }
        $note = (string) ($row->note ?? '');
        if ($capped < (float) $row->amount - 0.005) {
            $note .= ($note ? ' | ' : '') . sprintf(
                /* translators: %s: importe ajustado */
                __('Importe ajustado a %s por reembolsos posteriores.', 'travel-agency-platform'),
                TAP_Currency::fmt($capped, null, (string) ($row->currency ?? null))
            );
        }
        $wpdb->update(self::table(), [
            'amount'  => $capped,
            'note'    => $note,
            'paid_at' => current_time('mysql'),
        ], ['id' => $payout_id]);
        do_action('tap_agency_payout_completed', $payout_id, $capped);
        return true;
    }

    public static function cancel($payout_id) {
        if (!TAP_Payouts::transition((int) $payout_id, ['pending'], 'cancelled', self::table())) {
            return new WP_Error('invalid_payout', __('La liquidación no está pendiente.', 'travel-agency-platform'));
        }
        // The available balance is computed net of pending rows, so simply
        // leaving the pending state makes the amount available again.
        do_action('tap_agency_payout_cancelled', (int) $payout_id);
        return true;
    }

    public static function fail($payout_id, $note = '') {
        global $wpdb;
        $payout_id = (int) $payout_id;
        if (!TAP_Payouts::transition($payout_id, ['pending'], 'failed', self::table())) {
            return new WP_Error('invalid_payout', __('La liquidación no está pendiente.', 'travel-agency-platform'));
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT note FROM ' . self::table() . ' WHERE id = %d', $payout_id));
        $note = trim((string) $note) !== '' ? sanitize_text_field($note) : __('Transferencia no completada.', 'travel-agency-platform');
        $wpdb->update(self::table(), [
            'note' => ($row && $row->note) ? $row->note . ' | ' . $note : $note,
        ], ['id' => $payout_id]);
        do_action('tap_agency_payout_failed', $payout_id);
        return true;
    }

    public static function admin_page() {
        if (!current_user_can('tap_manage_commissions')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        global $wpdb;
        $pending = $wpdb->get_results(
            'SELECT p.*, a.post_title agency_name FROM ' . self::table() . ' p
             LEFT JOIN ' . $wpdb->posts . ' a ON a.ID = p.agency_id
             WHERE p.status = "pending" ORDER BY p.requested_at DESC LIMIT 50'
        );
        echo '<div class="wrap"><h1>' . esc_html__('Liquidaciones de agencias', 'travel-agency-platform') . '</h1>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Agencia', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Monto', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Fecha', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Acciones', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ((array) $pending as $p) {
            echo '<tr><td>' . esc_html($p->agency_name ?: ('#' . (int) $p->agency_id)) . '</td><td>'
                . esc_html(TAP_Currency::fmt((float) $p->amount)) . '</td><td>'
                . esc_html(substr((string) $p->requested_at, 0, 10)) . '</td><td>';
            foreach (['completed' => __('Marcar pagada', 'travel-agency-platform'), 'cancelled' => __('Cancelar', 'travel-agency-platform')] as $status => $label) {
                echo '<form method="post" style="display:inline;margin-right:6px;">'
                    . wp_nonce_field('tap_agency_payout_status_' . (int) $p->id, '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="tap_agency_payout_status">'
                    . '<input type="hidden" name="payout_id" value="' . (int) $p->id . '">'
                    . '<input type="hidden" name="payout_status" value="' . esc_attr($status) . '">'
                    . '<input type="text" name="note" class="small-text" placeholder="' . esc_attr__('Referencia', 'travel-agency-platform') . '">'
                    . '<button class="button button-small">' . esc_html($label) . '</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$pending) {
            echo '<tr><td colspan="4">' . esc_html__('No hay solicitudes pendientes.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
