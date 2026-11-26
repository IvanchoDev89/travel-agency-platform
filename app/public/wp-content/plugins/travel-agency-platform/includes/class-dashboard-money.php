<?php
/**
 * TAP_Dashboard_Money — admin pages of money: commissions and disputes.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Money {
    /**
     * POST handling for the commissions screen. Every branch redirects and
     * exits, so the screen never falls through to the renderer from here.
     */
    public static function handle_commissions_post() {
        global $wpdb;
        $b_table = $wpdb->prefix . 'tap_bookings';
        $p_table = $wpdb->prefix . 'tap_commission_payments';

        if (TAP_Dashboard::post_verified('tap_pay_commissions', 'tap_pay_commissions')) {
            $ids   = array_map('intval', (array) ($_POST['booking_ids'] ?? []));
            $note  = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
            $method = sanitize_key(wp_unslash($_POST['method'] ?? 'bank_transfer'));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                // This first pass only discovers which agencies are involved.
                // The authoritative selection (excluding bookings already
                // frozen into a pending payout) is repeated under each agency
                // lock below: doing the NOT EXISTS check out here would leave a
                // window for an agency request to insert its pending row
                // between the SELECT and the INSERT, and both sides would pay
                // the same booking.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, agency_id FROM {$b_table}
                     WHERE id IN ({$placeholders})
                       AND commission_status = 'owed'
                       AND commission_amount > 0
                       AND payment_status = 'paid'
                       AND status NOT IN ('cancelled', 'refunded')",
                    $ids
                ));
                if ($rows) {
                    $candidates = [];
                    foreach ($rows as $r) {
                        $candidates[(int) $r->agency_id][] = (int) $r->id;
                    }
                    $settled = false;
                    foreach ($candidates as $agency_id => $candidate_ids) {
                        $agency_id = (int) $agency_id;
                        // Same per-agency lock as the agency self-service path so
                        // an admin settle cannot interleave with a concurrent
                        // agency payout request over the same bookings. If the
                        // lock cannot be taken we must not settle unprotected.
                        $lock   = 'tap_payout_request_' . $agency_id;
                        $locked = 1 === (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock));
                        if (!$locked) {
                            continue;
                        }
                        $c_placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
                        $fresh = $wpdb->get_results($wpdb->prepare(
                            "SELECT id, commission_amount FROM {$b_table}
                             WHERE agency_id = %d
                               AND id IN ({$c_placeholders})
                               AND commission_status = 'owed'
                               AND commission_amount > 0
                               AND payment_status = 'paid'
                               AND status NOT IN ('cancelled', 'refunded')
                               AND NOT EXISTS (
                                   SELECT 1 FROM {$p_table} p
                                    JOIN {$wpdb->prefix}tap_payout_bookings l ON l.payout_id = p.id
                                    WHERE p.status = 'pending'
                                      AND l.booking_id = {$b_table}.id
                               )",
                            array_merge([$agency_id], $candidate_ids)
                        ));
                        if (!$fresh) {
                            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
                            continue;
                        }
                        $agency_ids = array_map(static fn($r) => (int) $r->id, $fresh);
                        $amount     = round(array_sum(array_map(static fn($r) => (float) $r->commission_amount, $fresh)), 2);
                        // The admin settle records a transfer that already
                        // happened, and the bookings are flipped to 'paid' in
                        // the same step. Leaving the row 'pending' would let a
                        // later Cancel/Fail release those commissions back to
                        // 'owed' while the money is already gone — the agency
                        // could then request and be paid a second time. So the
                        // row is written as completed straight away.
                        $wpdb->insert($p_table, [
                            'agency_id'  => $agency_id,
                            'amount'     => $amount,
                            'booking_ids' => implode(',', $agency_ids),
                            'method'     => $method,
                            'note'       => $note,
                            'source'     => 'admin',
                            'status'     => 'completed',
                            'paid_at'    => current_time('mysql'),
                            'created_by' => get_current_user_id(),
                        ]);
                        $payment_id = (int) $wpdb->insert_id;
                        if ($payment_id) {
                            TAP_Payouts::set_links($payment_id, $agency_ids);
                        }
                        if (!$payment_id) {
                            // Never mark commissions paid without the ledger row
                            // that proves the transfer.
                            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
                            continue;
                        }
                        $id_list = implode(',', array_map('intval', $agency_ids));
                        // CAS: only bookings still 'owed' flip to 'paid', so a
                        // concurrent dispute/cancel can never be overwritten.
                        $wpdb->query("UPDATE {$b_table} SET commission_status = 'paid' WHERE id IN ({$id_list}) AND commission_status = 'owed'");
                        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
                        $settled = true;
                        do_action('tap_commission_paid', $agency_id, $payment_id);
                    }
                    if ($settled) {
                        set_transient('tap_commission_notice', __('Liquidación registrada y comisiones marcadas como pagadas.', 'travel-agency-platform'), 60);
                    }
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key(wp_unslash($_POST['filter'] ?? 'all'))));
            exit;
        }

        if (TAP_Dashboard::post_verified('tap_settle_booking', 'tap_settle_booking')) {
            $booking_id = intval($_POST['booking_id'] ?? 0);
            $method = sanitize_key(wp_unslash($_POST['method'] ?? 'bank_transfer'));
            $note   = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
            // Same pending-payout exclusion as the bulk path: a booking already
            // frozen inside an agency request must not be settled a second time.
            // That check is only safe under the per-agency lock — otherwise a
            // concurrent agency request could insert its pending row between the
            // SELECT and the INSERT, and both sides would pay the same booking.
            $booking = null;
            $lock    = '';
            $agency_for_booking = $booking_id
                ? (int) $wpdb->get_var($wpdb->prepare("SELECT agency_id FROM {$b_table} WHERE id = %d", $booking_id))
                : 0;
            if ($agency_for_booking > 0) {
                $lock   = 'tap_payout_request_' . $agency_for_booking;
                $locked = 1 === (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock));
                if ($locked) {
                    $booking = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$b_table} b
                          WHERE b.id = %d
                            AND b.commission_status = 'owed'
                            AND b.payment_status = 'paid'
                            AND b.commission_amount > 0
                            AND b.status NOT IN ('cancelled', 'refunded')
                            AND NOT EXISTS (
                                SELECT 1 FROM {$p_table} p
                                 JOIN {$wpdb->prefix}tap_payout_bookings l ON l.payout_id = p.id
                                 WHERE p.status = 'pending'
                                   AND l.booking_id = b.id
                            )",
                        $booking_id
                    ));
                } else {
                    set_transient('tap_commission_notice', __('Otro proceso está liquidando estas comisiones. Inténtalo de nuevo.', 'travel-agency-platform'), 60);
                }
            }
            if ($booking) {
                // Same reasoning as the bulk path: the transfer is recorded as
                // done and the commission is marked paid here, so the row must
                // not stay cancellable (cancelling would release a commission
                // whose money is already gone and let it be paid twice).
                $wpdb->insert($p_table, [
                    'agency_id'   => (int) $booking->agency_id,
                    'amount'      => (float) $booking->commission_amount,
                    'booking_ids' => (string) $booking_id,
                    'method'      => $method,
                    'note'        => $note ?: __('Liquidación individual', 'travel-agency-platform'),
                    'source'      => 'admin',
                    'status'      => 'completed',
                    'paid_at'     => current_time('mysql'),
                    'created_by'  => get_current_user_id(),
                ]);
                $payment_id = (int) $wpdb->insert_id;
                if ($payment_id) {
                    TAP_Payouts::set_links($payment_id, [$booking_id]);
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$b_table} SET commission_status = 'paid' WHERE id = %d AND commission_status = 'owed'",
                        $booking_id
                    ));
                    do_action('tap_commission_paid', (int) $booking->agency_id, $payment_id);
                    set_transient('tap_commission_notice', __('Comisión liquidada individualmente.', 'travel-agency-platform'), 60);
                } else {
                    set_transient('tap_commission_notice', __('No se pudo registrar la liquidación. Inténtalo de nuevo.', 'travel-agency-platform'), 60);
                }
            }
            if ('' !== $lock) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key(wp_unslash($_POST['filter'] ?? 'all'))));
            exit;
        }

        if (isset($_POST['tap_payout_action']) && isset($_POST['_wpnonce'])) {
            $payment_id = intval($_POST['payout_id'] ?? 0);
            $action     = sanitize_key(wp_unslash($_POST['tap_payout_action'] ?? ''));
            if ($payment_id && in_array($action, ['complete', 'cancel', 'failed'], true) && TAP_Dashboard::post_nonce_ok('tap_payout_action_' . $payment_id)) {
                if ('complete' === $action) {
                    TAP_Payouts::complete($payment_id);
                    set_transient('tap_commission_notice', __('Liquidación marcada como completada.', 'travel-agency-platform'), 60);
                } elseif ('cancel' === $action) {
                    TAP_Payouts::cancel($payment_id);
                    set_transient('tap_commission_notice', __('Liquidación cancelada y comisiones devueltas a "por cobrar".', 'travel-agency-platform'), 60);
                } elseif ('failed' === $action) {
                    $fail_note = sanitize_textarea_field(wp_unslash($_POST['fail_note'] ?? ''));
                    TAP_Payouts::fail($payment_id, $fail_note);
                    set_transient('tap_commission_notice', __('Liquidación marcada como fallida y comisiones liberadas.', 'travel-agency-platform'), 60);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key(wp_unslash($_POST['filter'] ?? 'all')) . '&payout_status=' . sanitize_key(wp_unslash($_POST['payout_filter'] ?? 'all'))));
            exit;
        }
    }

    public static function commissions_page() {
        if (!current_user_can('tap_manage_commissions') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        self::handle_commissions_post();

        global $wpdb;
        TAP_Payouts::sync_all_links();
        $b_table = $wpdb->prefix . 'tap_bookings';
        $p_table = $wpdb->prefix . 'tap_commission_payments';

        $filter = sanitize_key(wp_unslash($_GET['status'] ?? 'all'));
        if (!in_array($filter, ['all', 'owed', 'paid', 'disputed', 'void'], true)) {
            $filter = 'all';
        }
        $where = 'b.commission_amount > 0';
        if ('owed' === $filter) {
            $where .= " AND b.commission_status = 'owed'";
        } elseif ('paid' === $filter) {
            $where .= " AND b.commission_status = 'paid'";
        } elseif ('disputed' === $filter) {
            $where .= " AND b.commission_status = 'disputed'";
        } elseif ('void' === $filter) {
            $where .= " AND b.commission_status = 'void'";
        }
        $per_page = 50;
        $paged    = max(1, absint($_GET['paged'] ?? 1));
        $commission_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$b_table} b LEFT JOIN {$wpdb->posts} a ON b.agency_id = a.ID WHERE {$where}"
        );
        $commissions = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, a.post_title as agency_name
             FROM {$b_table} b
             LEFT JOIN {$wpdb->posts} a ON b.agency_id = a.ID
             WHERE {$where}
             ORDER BY b.created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            ($paged - 1) * $per_page
        ));
        $payout_filter = sanitize_key(wp_unslash($_GET['payout_status'] ?? 'all'));
        if (!in_array($payout_filter, ['all', 'pending', 'completed', 'cancelled', 'failed'], true)) {
            $payout_filter = 'all';
        }
        $pwhere = '1=1';
        if ('all' !== $payout_filter) {
            $pwhere = $wpdb->prepare('p.status = %s', $payout_filter);
        }
        $payout_per_page = 50;
        $payout_paged    = max(1, absint($_GET['payout_paged'] ?? 1));
        $payout_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p_table} p WHERE {$pwhere}");
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, a.post_title agency_name
             FROM {$p_table} p
             LEFT JOIN {$wpdb->posts} a ON p.agency_id = a.ID
             WHERE {$pwhere}
             ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
            $payout_per_page,
            ($payout_paged - 1) * $payout_per_page
        ));
        $tab_counts = [
            'pending'   => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0,
        ];
        foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) c FROM {$p_table} GROUP BY status") as $trow) {
            if (isset($tab_counts[$trow->status])) {
                $tab_counts[$trow->status] = (int) $trow->c;
            }
        }
        $totals = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(CASE WHEN commission_status = 'owed' THEN commission_amount END), 0) owed,
                COALESCE(SUM(CASE WHEN commission_status = 'paid' THEN commission_amount END), 0) paid,
                COALESCE(SUM(CASE WHEN commission_status = 'disputed' THEN commission_amount END), 0) disputed,
                COALESCE(SUM(CASE WHEN commission_status = 'void' THEN commission_amount END), 0) void
             FROM {$b_table} WHERE commission_amount > 0"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Commissions — Liquidación', 'travel-agency-platform'); ?></h1>
            <?php echo TAP_Dashboard::paginate($commission_total, $per_page, $paged); ?>

            <?php $tap_notice = get_transient('tap_commission_notice'); if ($tap_notice): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($tap_notice); ?></p></div>
                <?php delete_transient('tap_commission_notice'); ?>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="admin.php?page=tap-commissions&status=all" class="nav-tab <?php echo 'all' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Todas', 'travel-agency-platform'); ?></a>
                <a href="admin.php?page=tap-commissions&status=owed" class="nav-tab <?php echo 'owed' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?> (<?php echo esc_html(TAP_Currency::fmt($totals->owed)); ?>)</a>
                <a href="admin.php?page=tap-commissions&status=disputed" class="nav-tab <?php echo 'disputed' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?> (<?php echo esc_html(TAP_Currency::fmt($totals->disputed)); ?>)</a>
                <a href="admin.php?page=tap-commissions&status=paid" class="nav-tab <?php echo 'paid' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Pagadas', 'travel-agency-platform'); ?> (<?php echo esc_html(TAP_Currency::fmt($totals->paid)); ?>)</a>
                <a href="admin.php?page=tap-commissions&status=void" class="nav-tab <?php echo 'void' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Canceladas', 'travel-agency-platform'); ?> (<?php echo esc_html(TAP_Currency::fmt($totals->void)); ?>)</a>
            </nav>

            <form method="post" action="">
                <?php wp_nonce_field('tap_pay_commissions'); ?>
                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="manage-column check-column"><input type="checkbox" id="tap-check-all"></th>
                            <th><?php esc_html_e('Booking', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Agency', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Commission %', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Date', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$commissions): ?>
                            <tr><td colspan="8"><?php esc_html_e('No hay comisiones.', 'travel-agency-platform'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($commissions as $c): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr($c->id); ?>" <?php echo 'owed' === $c->commission_status ? '' : 'disabled'; ?>>
                            </th>
                            <td><?php echo esc_html($c->booking_code); ?></td>
                            <td><?php echo esc_html($c->agency_name ?? 'N/D'); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->total_amount)); ?></td>
                            <td><?php echo esc_html($c->commission_percent); ?>%</td>
                            <td>
                                <?php echo esc_html(TAP_Currency::fmt($c->commission_amount)); ?>
                                <?php if ('owed' === $c->commission_status): ?>
                                    <button type="button" class="button button-small" data-settle="<?php echo (int) $c->id; ?>" data-code="<?php echo esc_attr($c->booking_code); ?>" data-amount="<?php echo esc_attr($c->commission_amount); ?>"><?php esc_html_e('Liquidar', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                            </td>
                            <td>
                            <?php
                                $estado_label = [
                                    'owed'     => __('Por cobrar', 'travel-agency-platform'),
                                    'waiting'  => __('Pendiente de pago', 'travel-agency-platform'),
                                    'paid'     => __('Cobrada', 'travel-agency-platform'),
                                    'disputed' => __('En disputa', 'travel-agency-platform'),
                                    'void'     => __('Comisión no pagada', 'travel-agency-platform'),
                                ];
                                $estado_color = [
                                    'owed'     => '#b45309',
                                    'waiting'  => '#64748b',
                                    'paid'     => '#047857',
                                    'disputed' => '#b91c1c',
                                    'void'     => '#64748b',
                                ];
                                echo '<span style="color:' . esc_attr($estado_color[$c->commission_status] ?? '#374151') . ';font-weight:600;">' . esc_html($estado_label[$c->commission_status] ?? $c->commission_status) . '</span>';
								?>
                            </td>
                            <td><?php echo esc_html($c->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <table class="form-table" style="margin-top:16px;max-width:520px;">
                    <tr>
                        <th><?php esc_html_e('Método de pago', 'travel-agency-platform'); ?></th>
                        <td>
                            <select name="method">
                                <option value="bank_transfer"><?php esc_html_e('Transferencia bancaria', 'travel-agency-platform'); ?></option>
                                <option value="cash"><?php esc_html_e('Efectivo', 'travel-agency-platform'); ?></option>
                                <option value="paypal">PayPal</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                        <td><textarea name="note" rows="2" class="widefat" placeholder="<?php echo esc_attr__('Referencia, comprobante, etc.', 'travel-agency-platform'); ?>"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="tap_pay_commissions" class="button button-primary" value="1"><?php esc_html_e('Registrar pago de comisiones seleccionadas', 'travel-agency-platform'); ?></button>
                </p>
            </form>

            <h2 style="margin-top:32px;"><?php esc_html_e('Historial de liquidaciones', 'travel-agency-platform'); ?></h2>
            <?php echo TAP_Dashboard::paginate($payout_total, $payout_per_page, $payout_paged, 'payout_paged'); ?>
            <nav class="nav-tab-wrapper" style="margin-bottom:12px;">
                <?php
                $pay_tabs = [
                    'all'       => __('Todas', 'travel-agency-platform'),
                    'pending'   => __('Pendientes', 'travel-agency-platform'),
                    'completed' => __('Completadas', 'travel-agency-platform'),
                    'failed'    => __('Fallidas', 'travel-agency-platform'),
                    'cancelled' => __('Canceladas', 'travel-agency-platform'),
                ];
				foreach ($pay_tabs as $pk => $plabel):
					$pcount = isset($tab_counts[$pk]) ? (int) $tab_counts[$pk] : 0;
					$pclass = $payout_filter === $pk ? ' nav-tab-active' : '';
                    ?>
                    <a class="nav-tab<?php echo esc_attr($pclass); ?>" href="<?php echo esc_url(admin_url('admin.php?page=tap-commissions&status=' . $filter . '&payout_status=' . $pk)); ?>"><?php echo esc_html($plabel); ?><?php if ($pcount > 0 && 'failed' === $pk): ?> <span class="update-plugins count-<?php echo esc_attr($pcount); ?>" style="background:#b91c1c;border-radius:50%;color:#fff;font-size:11px;line-height:1.6;height:16px;min-width:16px;display:inline-block;text-align:center;"><span class="plugin-count"><?php echo esc_attr($pcount); ?></span></span><?php endif; ?></a>
								<?php endforeach; ?>
            </nav>
            <?php
            $failed_count = $tab_counts['failed'] ?? 0;
            if ($failed_count > 0):
				?>
                <div class="notice notice-error inline" style="margin:0 0 12px;"><p><strong><?php echo esc_html(sprintf(/* translators: %d: número de liquidaciones marcadas como fallidas */ __('%d liquidación(es) marcada(s) como fallida(s): el pago no llegó a completarse. Revisa y vuelve a procesarlas.', 'travel-agency-platform'), $failed_count)); ?></strong></p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Agency', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Origen', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="10"><?php esc_html_e('Aún no se han registrado liquidaciones.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>#<?php echo (int) $p->id; ?></td>
                        <td><?php echo esc_html($p->agency_name ?? 'N/D'); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($p->amount)); ?></td>
                        <td><?php echo esc_html($p->method); ?></td>
                        <td><?php if ('agency' === ($p->source ?? 'admin')): ?><span class="button button-small" style="background:#fffbeb;border-color:#f59e0b;color:#92400e;"><?php esc_html_e('Sol. agencia', 'travel-agency-platform'); ?></span><?php else: ?><span class="button button-small" style="background:#eff6ff;border-color:#3b82f6;color:#1e40af;"><?php esc_html_e('Admin', 'travel-agency-platform'); ?></span><?php endif; ?></td>
                        <td>
                        <?php
                            $ids = array_filter(array_map('intval', explode(',', $p->booking_ids)));
						if ($ids) {
							$ph = implode(',', array_fill(0, count($ids), '%d'));
							$codes = $wpdb->get_col($wpdb->prepare("SELECT booking_code FROM {$b_table} WHERE id IN ({$ph})", $ids));
							echo esc_html(implode(', ', $codes ?: $ids));
						} else {
							echo '—';
						}
                        ?>
                        </td>
                        <td><?php echo esc_html($p->note); ?></td>
                        <td><span style="font-weight:600;color:<?php echo 'pending' === $p->status ? '#b45309' : ('failed' === $p->status ? '#b91c1c' : ('completed' === $p->status ? '#047857' : '#64748b')); ?>;"><?php echo esc_html(TAP_Payouts::status_label($p->status)); ?></span><?php if ($p->paid_at): ?><br><span class="description"><?php echo esc_html__('Pagada el', 'travel-agency-platform') . ' ' . esc_html($p->paid_at); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($p->created_at); ?></td>
                        <td>
                            <?php if ('pending' === $p->status): ?>
                            <form method="post" style="display:inline-block;margin:0;">
                                <?php wp_nonce_field('tap_payout_action_' . (int) $p->id); ?>
                                <input type="hidden" name="payout_id" value="<?php echo (int) $p->id; ?>">
                                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                                <input type="hidden" name="payout_filter" value="<?php echo esc_attr($payout_filter); ?>">
                                <button type="submit" name="tap_payout_action" value="complete" class="button button-small button-primary"><?php esc_html_e('Marcar pagada', 'travel-agency-platform'); ?></button>
                            </form>
                            <form method="post" style="display:inline-block;margin:0 0 0 4px;">
                                <?php wp_nonce_field('tap_payout_action_' . (int) $p->id); ?>
                                <input type="hidden" name="payout_id" value="<?php echo (int) $p->id; ?>">
                                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                                <input type="hidden" name="payout_filter" value="<?php echo esc_attr($payout_filter); ?>">
                                <input type="text" name="fail_note" class="small-text" placeholder="<?php esc_attr_e('Motivo del fallo', 'travel-agency-platform'); ?>">
                                <button type="submit" name="tap_payout_action" value="failed" class="button button-small" style="color:#b91c1c;" onclick="return confirm('<?php echo esc_js(__('Marcar esta liquidación como fallida? Las comisiones volverán a estar por cobrar.', 'travel-agency-platform')); ?>');"><?php esc_html_e('Marcar fallida', 'travel-agency-platform'); ?></button>
                            </form>
                            <form method="post" style="display:inline-block;margin:0 0 0 4px;">
                                <?php wp_nonce_field('tap_payout_action_' . (int) $p->id); ?>
                                <input type="hidden" name="payout_id" value="<?php echo (int) $p->id; ?>">
                                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                                <input type="hidden" name="payout_filter" value="<?php echo esc_attr($payout_filter); ?>">
                                <button type="submit" name="tap_payout_action" value="cancel" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js(__('Cancelar esta liquidación y devolver las comisiones a por cobrar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                            </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tap-settle-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center;">
            <form method="post" action="" style="background:#fff;padding:22px;border-radius:10px;max-width:420px;width:92%;box-shadow:0 10px 40px rgba(0,0,0,.25);">
                <?php wp_nonce_field('tap_settle_booking'); ?>
                <input type="hidden" name="tap_settle_booking" value="1">
                <input type="hidden" name="booking_id" id="tap-settle-id" value="">
                <input type="hidden" name="filter" id="tap-settle-filter" value="<?php echo esc_attr($filter); ?>">
                <h3 style="margin-top:0;"><?php esc_html_e('Liquidar comisión', 'travel-agency-platform'); ?>: <code id="tap-settle-code"></code> — <strong id="tap-settle-amount"></strong></h3>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                        <td>
                            <select name="method">
                                <option value="bank_transfer"><?php esc_html_e('Transferencia bancaria', 'travel-agency-platform'); ?></option>
                                <option value="cash"><?php esc_html_e('Efectivo', 'travel-agency-platform'); ?></option>
                                <option value="paypal">PayPal</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                        <td><input type="text" name="note" class="widefat" placeholder="<?php echo esc_attr__('Comprobante / referencia', 'travel-agency-platform'); ?>"></td>
                    </tr>
                </table>
                <p class="submit" style="margin-bottom:0;">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Confirmar liquidación', 'travel-agency-platform'); ?></button>
                    <button type="button" class="button" id="tap-settle-close"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                </p>
            </form>
        </div>
        <script>
        (function(d){
            var a = d.getElementById('tap-check-all');
            if (a) a.addEventListener('change', function(){
                d.querySelectorAll('input[name="booking_ids[]"]').forEach(function(c){ c.checked = a.checked; });
            });
            var modal = d.getElementById('tap-settle-modal');
            var fmt = new Intl.NumberFormat('es-CR', {style:'currency', currency:'<?php echo esc_js(TAP_Currency::code()); ?>', minimumFractionDigits:0});
            d.querySelectorAll('[data-settle]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    d.getElementById('tap-settle-id').value = btn.getAttribute('data-settle');
                    d.getElementById('tap-settle-code').textContent = btn.getAttribute('data-code');
                    d.getElementById('tap-settle-amount').textContent = btn.getAttribute('data-amount');
                    modal.style.display = 'flex';
                });
            });
            d.getElementById('tap-settle-close').addEventListener('click', function(){ modal.style.display = 'none'; });
            modal.addEventListener('click', function(e){ if (e.target === modal) modal.style.display = 'none'; });
        })(document);
        </script>
        <?php
    }


    /**
     * POST handling for the disputes screen. Every branch redirects and exits,
     * so the screen never falls through to the renderer from here.
     */
    public static function handle_disputes_post() {
        if (isset($_POST['tap_resolve_dispute']) && isset($_POST['_wpnonce'])) {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            $outcome    = sanitize_key(wp_unslash($_POST['outcome'] ?? ''));
            if ($dispute_id && TAP_Dashboard::post_nonce_ok('tap_resolve_dispute_' . $dispute_id)) {
                $note = sanitize_textarea_field(wp_unslash($_POST['resolution_note'] ?? ''));
                $res  = TAP_Disputes::resolve($dispute_id, $outcome, $note);
                if (is_wp_error($res)) {
                    set_transient('tap_dispute_notice', ['error' => true, 'message' => $res->get_error_message()], 60);
                } else {
                    set_transient('tap_dispute_notice', ['error' => false, 'message' => __('Disputa resuelta. Comisión liberada o anulada según el resultado.', 'travel-agency-platform')], 60);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-disputes&dfilter=' . sanitize_key(wp_unslash($_POST['dfilter'] ?? 'all'))));
            exit;
        }
    }

    /** Full dispute case file for operators. */
    public static function dispute_detail($id) {
        if (!current_user_can('tap_manage_disputes') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare(
            "SELECT d.*, b.booking_code, b.guest_name, b.guest_email AS bmail,
                    b.total_amount, b.amount_paid, b.commission_amount, b.commission_status,
                    a.post_title agency_name
             FROM {$wpdb->prefix}tap_disputes d
             LEFT JOIN {$wpdb->prefix}tap_bookings b ON b.id = d.booking_id
             LEFT JOIN {$wpdb->posts} a ON a.ID = d.agency_id
             WHERE d.id = %d",
            (int) $id
        ));
        if (!$d) {
            return '<div class="wrap"><h1>' . esc_html__('Disputa no encontrada.', 'travel-agency-platform') . '</h1></div>';
        }
        ob_start();
        echo '<div class="wrap"><p><a href="' . esc_url(admin_url('admin.php?page=tap-disputes')) . '">&larr; '
            . esc_html__('Volver a disputas', 'travel-agency-platform') . '</a></p>';
        echo '<h1>' . esc_html(sprintf(__('Disputa #%d — %s', 'travel-agency-platform'), (int) $d->id, (string) $d->booking_code)) . '</h1>';
        echo '<p>' . esc_html(TAP_Disputes::status_label($d->status)) . ' · ' . esc_html($d->created_at) . '</p>';
        echo '<table class="form-table"><tbody>';
        foreach ([
            __('Reserva', 'travel-agency-platform') => $d->booking_code . ' #' . (int) $d->booking_id,
            __('Agencia', 'travel-agency-platform') => $d->agency_name ?: '#' . (int) $d->agency_id,
            __('Huésped', 'travel-agency-platform') => $d->guest_name ?: ($d->bmail ?: '—'),
            __('Correo', 'travel-agency-platform') => $d->bmail ?: $d->guest_email,
            __('Motivo', 'travel-agency-platform') => TAP_Disputes::reasons()[$d->reason] ?? $d->reason,
            __('Detalles', 'travel-agency-platform') => $d->details ?: '—',
            __('Importe de la reserva', 'travel-agency-platform') => TAP_Currency::fmt((float) $d->total_amount),
            __('Comisión retenida', 'travel-agency-platform') => TAP_Currency::fmt((float) $d->commission_amount) . ' (' . esc_html($d->prev_commission) . ')',
            __('Resolución', 'travel-agency-platform') => $d->resolution_note ?: '—',
            __('Resuelta', 'travel-agency-platform') => $d->resolved_at ?: '—',
        ] as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ('open' === $d->status) {
            echo '<h2>' . esc_html__('Resolver', 'travel-agency-platform') . '</h2>'
                . '<form method="post" action="' . esc_url(admin_url('admin.php?page=tap-disputes')) . '">'
                . '<input type="hidden" name="tap_resolve_dispute" value="1">'
                . '<input type="hidden" name="dispute_id" value="' . (int) $d->id . '">'
                . '<input type="hidden" name="dfilter" value="all">';
            wp_nonce_field('tap_resolve_dispute_' . (int) $d->id);
            echo '<select name="outcome">';
            foreach (TAP_Disputes::outcomes() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select><br><textarea name="resolution_note" rows="4" class="large-text" placeholder="'
                . esc_attr__('Motivo de la resolución', 'travel-agency-platform') . '"></textarea>'
                . '<button class="button button-primary">' . esc_html__('Guardar resolución', 'travel-agency-platform') . '</button></form>';
        }
        echo '<p><a class="button" href="' . esc_url(home_url('/booking-detail/?code=' . rawurlencode((string) $d->booking_code))) . '" target="_blank">'
            . esc_html__('Ver voucher', 'travel-agency-platform') . '</a></p></div>';
        return ob_get_clean();
    }

    public static function disputes_page() {
        if (!current_user_can('tap_manage_disputes') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        self::handle_disputes_post();

        global $wpdb;
        $table = $wpdb->prefix . 'tap_disputes';

        $view_id = absint($_GET['view'] ?? 0);
        if ($view_id) {
            echo self::dispute_detail($view_id);
            return;
        }
        $filter = sanitize_key(wp_unslash($_GET['dfilter'] ?? 'all'));
        $ds = isset($_GET['ds']) ? sanitize_text_field(wp_unslash($_GET['ds'])) : '';
        $dagency = absint($_GET['dagency'] ?? 0);
        $counts = $wpdb->get_row("SELECT
            COALESCE(SUM(status='open'),0) open_c,
            COALESCE(SUM(status!='open'),0) closed_c FROM {$table}");

        $where = '1=1';
        $where_params = [];
        if ('open' === $filter) {
            $where .= " AND d.status = 'open'";
        } elseif ('closed' === $filter) {
            $where .= " AND d.status != 'open'";
        }
        if ($dagency) {
            $where .= ' AND d.agency_id = %d';
            $where_params[] = $dagency;
        }
        if ('' !== $ds) {
            $where .= ' AND (d.reason LIKE %s OR d.details LIKE %s OR b.booking_code LIKE %s OR d.guest_email LIKE %s)';
            $like = '%' . $wpdb->esc_like($ds) . '%';
            $where_params = array_merge($where_params, [$like, $like, $like, $like]);
        }
        $where_sql = $where_params
            ? $wpdb->prepare($where, $where_params)
            : $where;
        $dispute_per_page = 50;
        $dispute_paged    = max(1, absint($_GET['paged'] ?? 1));
        $dispute_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} d
             LEFT JOIN {$wpdb->prefix}tap_bookings b ON d.booking_id = b.id
             LEFT JOIN {$wpdb->posts} a ON d.agency_id = a.ID
             WHERE {$where_sql}"
        );
        $disputes = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, b.booking_code, b.guest_name, b.guest_email AS bmail, a.post_title AS agency_name
             FROM {$table} d
             LEFT JOIN {$wpdb->prefix}tap_bookings b ON d.booking_id = b.id
             LEFT JOIN {$wpdb->posts} a ON d.agency_id = a.ID
             WHERE {$where_sql}
             ORDER BY d.created_at DESC LIMIT %d OFFSET %d",
            $dispute_per_page,
            ($dispute_paged - 1) * $dispute_per_page
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Disputas de reservas', 'travel-agency-platform'); ?></h1>
            <?php echo TAP_Dashboard::paginate($dispute_total, $dispute_per_page, $dispute_paged); ?>

            <?php $tap_dnotice = get_transient('tap_dispute_notice'); if ($tap_dnotice): ?>
                <?php $tap_dclass = !empty($tap_dnotice['error']) ? 'notice-error' : 'notice-success'; ?>
                <div class="notice <?php echo esc_attr($tap_dclass); ?> is-dismissible"><p><?php echo esc_html($tap_dnotice['message'] ?? ''); ?></p></div>
                <?php delete_transient('tap_dispute_notice'); ?>
            <?php endif; ?>

            <div class="notice notice-info inline" style="margin:10px 0;">
                <p><?php esc_html_e('Mientras una disputa está abierta, la comisión de la reserva queda retenida y no puede liquidarse. Al resolver a favor de la agencia la comisión se libera; en contra, se anula.', 'travel-agency-platform'); ?></p>
            </div>

            <nav class="nav-tab-wrapper">
                <a href="admin.php?page=tap-disputes&dfilter=all" class="nav-tab <?php echo 'all' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Todas', 'travel-agency-platform'); ?> (<?php echo (int) (($counts->open_c ?? 0) + ($counts->closed_c ?? 0)); ?>)</a>
                <a href="admin.php?page=tap-disputes&dfilter=open" class="nav-tab <?php echo 'open' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Abiertas', 'travel-agency-platform'); ?> (<?php echo (int) ($counts->open_c ?? 0); ?>)</a>
                <a href="admin.php?page=tap-disputes&dfilter=closed" class="nav-tab <?php echo 'closed' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Resueltas', 'travel-agency-platform'); ?> (<?php echo (int) ($counts->closed_c ?? 0); ?>)</a>
            </nav>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="page" value="tap-disputes">
                <input type="hidden" name="dfilter" value="<?php echo esc_attr($filter); ?>">
                <input type="search" name="ds" value="<?php echo esc_attr($ds); ?>" placeholder="<?php echo esc_attr__('Código, huésped, motivo o detalle', 'travel-agency-platform'); ?>" class="regular-text">
                <?php
                $agencies = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'post_title', 'order' => 'ASC']);
                ?>
                <select name="dagency"><option value="0"><?php esc_html_e('Todas las agencias', 'travel-agency-platform'); ?></option>
                    <?php foreach ($agencies as $ag): ?>
                        <option value="<?php echo (int) $ag->ID; ?>" <?php selected($dagency, $ag->ID); ?>><?php echo esc_html($ag->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button"><?php esc_html_e('Filtrar', 'travel-agency-platform'); ?></button>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Huésped', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Motivo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$disputes): ?>
                    <tr><td colspan="6"><em><?php esc_html_e('No hay disputas.', 'travel-agency-platform'); ?></em></td></tr>
                    <?php endif; ?>
                    <?php foreach ($disputes as $d): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-disputes&view=' . (int) $d->id)); ?>"><strong><?php echo esc_html($d->booking_code); ?></strong></a>
                            <br><small>#<?php echo (int) $d->booking_id; ?> · <a href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode((string) $d->booking_code))); ?>" target="_blank"><?php esc_html_e('Voucher', 'travel-agency-platform'); ?></a></small>
                        </td>
                        <td><?php echo esc_html($d->agency_name ?? 'N/D'); ?></td>
                        <td><?php echo esc_html($d->guest_name ?: $d->bmail ?: ($d->client_id ? '#' . (int) $d->client_id : '—')); ?></td>
                        <td><?php echo esc_html(TAP_Disputes::reasons()[$d->reason] ?? $d->reason); ?>
                            <?php if ($d->details): ?><div class="description" style="margin-top:4px;"><?php echo esc_html($d->details); ?></div><?php endif; ?>
                        </td>
                        <td><span class="tap-status <?php echo 'open' === $d->status ? 'tap-status-pending' : 'tap-status-confirmed'; ?>"><?php echo esc_html(TAP_Disputes::status_label($d->status)); ?></span></td>
                        <td><?php echo esc_html($d->created_at); ?></td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;"></td>
                        <td colspan="5" style="background:#f9fafb;">
                            <?php if ('open' === $d->status): ?>
                            <details>
                                <summary style="cursor:pointer;font-weight:600;color:#0a84ff;"><?php esc_html_e('Resolver disputa', 'travel-agency-platform'); ?></summary>
                                <form method="post" style="margin-top:8px;">
                                    <input type="hidden" name="tap_resolve_dispute" value="1">
                                    <input type="hidden" name="dispute_id" value="<?php echo (int) $d->id; ?>">
                                    <input type="hidden" name="dfilter" value="<?php echo esc_attr($filter); ?>">
                                    <?php wp_nonce_field('tap_resolve_dispute_' . $d->id); ?>
                                    <p>
                                        <label><input type="radio" name="outcome" value="for_agency" checked> <?php esc_html_e('A favor de la agencia (liberar comisión)', 'travel-agency-platform'); ?></label><br>
                                        <label><input type="radio" name="outcome" value="against_agency"> <?php esc_html_e('En contra de la agencia (comisión no pagada)', 'travel-agency-platform'); ?></label><br>
                                        <label><input type="radio" name="outcome" value="withdrawn"> <?php esc_html_e('Retirar disputa', 'travel-agency-platform'); ?></label>
                                    </p>
                                    <textarea name="resolution_note" rows="2" class="large-text" placeholder="<?php esc_attr_e('Nota de resolución', 'travel-agency-platform'); ?>"></textarea>
                                    <p>
                                        <button class="button button-primary"><?php esc_html_e('Guardar resolución', 'travel-agency-platform'); ?></button>
                                    </p>
                                </form>
                            </details>
                            <?php elseif ($d->resolution_note): ?>
                            <blockquote style="margin:6px 0;padding:8px 12px;border-left:4px solid #94a3b8;background:#fff;">
                                <?php echo nl2br(esc_html($d->resolution_note)); ?>
                                <div class="description" style="color:#64748b;"><?php echo esc_html((string) $d->resolved_at); ?></div>
                            </blockquote>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
