<?php
/**
 * TAP_Dashboard_Money — admin pages of money: commissions and disputes.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Money {
    public static function commissions_page() {
        global $wpdb;
        $b_table = $wpdb->prefix . 'tap_bookings';
        $p_table = $wpdb->prefix . 'tap_commission_payments';

        if (isset($_POST['tap_pay_commissions']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_pay_commissions')) {
            $ids   = array_map('intval', (array) ($_POST['booking_ids'] ?? []));
            $note  = sanitize_textarea_field($_POST['note'] ?? '');
            $method = sanitize_key($_POST['method'] ?? 'bank_transfer');
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, agency_id, commission_amount FROM {$b_table}
                     WHERE id IN ({$placeholders})
                       AND commission_status = 'owed'
                       AND payment_status = 'paid'
                       AND status NOT IN ('cancelled', 'refunded')
                       AND NOT EXISTS (
                           SELECT 1 FROM {$p_table} p
                            WHERE p.status = 'pending'
                              AND FIND_IN_SET({$b_table}.id, p.booking_ids)
                       )",
                    $ids
                ));
                if ($rows) {
                    $by_agency = [];
                    foreach ($rows as $r) {
                        $by_agency[$r->agency_id] = ($by_agency[$r->agency_id] ?? 0) + (float) $r->commission_amount;
                    }
                    foreach ($by_agency as $agency_id => $amount) {
                        $agency_ids = array_column(array_filter($rows, fn($r) => (int) $r->agency_id === (int) $agency_id), 'id');
                        $wpdb->insert($p_table, [
                            'agency_id'  => $agency_id,
                            'amount'     => round($amount, 2),
                            'booking_ids' => implode(',', $agency_ids),
                            'method'     => $method,
                            'note'       => $note,
                            'source'     => 'admin',
                            'created_by' => get_current_user_id(),
                        ]);
                        $payment_id = $wpdb->insert_id;
                        $id_list = implode(',', $agency_ids);
                        $wpdb->query("UPDATE {$b_table} SET commission_status = 'paid' WHERE id IN ({$id_list})");
                        do_action('tap_commission_paid', $agency_id, $payment_id);
                    }
                    set_transient('tap_commission_notice', __('Liquidación registrada y comisiones marcadas como pagadas.', 'travel-agency-platform'), 60);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key($_POST['filter'] ?? 'all')));
            exit;
        }

        if (isset($_POST['tap_settle_booking']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_settle_booking')) {
            $booking_id = intval($_POST['booking_id'] ?? 0);
            $method = sanitize_key($_POST['method'] ?? 'bank_transfer');
            $note   = sanitize_textarea_field($_POST['note'] ?? '');
            $booking = $booking_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$b_table} WHERE id = %d AND commission_status = 'owed' AND payment_status = 'paid' AND commission_amount > 0 AND status NOT IN ('cancelled', 'refunded')", $booking_id)) : null;
            if ($booking) {
                $wpdb->insert($p_table, [
                    'agency_id'   => (int) $booking->agency_id,
                    'amount'      => (float) $booking->commission_amount,
                    'booking_ids' => (string) $booking_id,
                    'method'      => $method,
                    'note'        => $note ?: __('Liquidación individual', 'travel-agency-platform'),
                    'source'      => 'admin',
                    'created_by'  => get_current_user_id(),
                ]);
                $payment_id = $wpdb->insert_id;
                $wpdb->update($b_table, ['commission_status' => 'paid'], ['id' => $booking_id]);
                do_action('tap_commission_paid', (int) $booking->agency_id, $payment_id);
                set_transient('tap_commission_notice', __('Comisión liquidada individualmente.', 'travel-agency-platform'), 60);
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key($_POST['filter'] ?? 'all')));
            exit;
        }

        if (isset($_POST['tap_payout_action']) && isset($_POST['_wpnonce'])) {
            $payment_id = intval($_POST['payout_id'] ?? 0);
            $action     = sanitize_key($_POST['tap_payout_action'] ?? '');
            if ($payment_id && in_array($action, ['complete', 'cancel', 'failed'], true) && wp_verify_nonce($_POST['_wpnonce'], 'tap_payout_action_' . $payment_id)) {
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
            wp_safe_redirect(admin_url('admin.php?page=tap-commissions&status=' . sanitize_key($_POST['filter'] ?? 'all') . '&payout_status=' . sanitize_key($_POST['payout_filter'] ?? 'all')));
            exit;
        }

        $filter = sanitize_key($_GET['status'] ?? 'all');
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
        $commissions = $wpdb->get_results(
            "SELECT b.*, a.post_title as agency_name
             FROM {$b_table} b
             LEFT JOIN {$wpdb->posts} a ON b.agency_id = a.ID
             WHERE {$where}
             ORDER BY b.created_at DESC LIMIT 200"
        );
        $payout_filter = sanitize_key($_GET['payout_status'] ?? 'all');
        if (!in_array($payout_filter, ['all', 'pending', 'completed', 'cancelled', 'failed'], true)) {
            $payout_filter = 'all';
        }
        $pwhere = '1=1';
        if ('all' !== $payout_filter) {
            $pwhere = $wpdb->prepare("p.status = %s", $payout_filter);
        }
        $payments = $wpdb->get_results(
            "SELECT p.*, a.post_title agency_name
             FROM {$p_table} p
             LEFT JOIN {$wpdb->posts} a ON p.agency_id = a.ID
             WHERE {$pwhere}
             ORDER BY p.created_at DESC LIMIT 50"
        );
        $tab_counts = [
            'pending'   => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0,
        ];
        foreach ($wpdb->get_results("SELECT status, COUNT(*) c FROM {$p_table} GROUP BY status") as $trow) {
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
                            <td><?php echo esc_html($c->agency_name ?? 'N/A'); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->total_amount)); ?></td>
                            <td><?php echo esc_html($c->commission_percent); ?>%</td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->commission_amount)); ?></td>
                            <td><?php
                                $estado_label = [
                                    'owed'     => __('Por cobrar', 'travel-agency-platform'),
                                    'paid'     => __('Cobrada', 'travel-agency-platform'),
                                    'disputed' => __('En disputa', 'travel-agency-platform'),
                                    'void'     => __('Comisión no pagada', 'travel-agency-platform'),
                                ];
                                $estado_color = [
                                    'owed'     => '#b45309',
                                    'paid'     => '#047857',
                                    'disputed' => '#b91c1c',
                                    'void'     => '#64748b',
                                ];
                                echo '<span style="color:' . esc_attr($estado_color[$c->commission_status] ?? '#374151') . ';font-weight:600;">' . esc_html($estado_label[$c->commission_status] ?? $c->commission_status) . '</span>';
                            ?></td>
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
                        <td><textarea name="note" rows="2" class="widefat" placeholder="Referencia, comprobante, etc."></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="tap_pay_commissions" class="button button-primary" value="1"><?php esc_html_e('Registrar pago de comisiones seleccionadas', 'travel-agency-platform'); ?></button>
                </p>
            </form>

            <h2 style="margin-top:32px;"><?php esc_html_e('Historial de liquidaciones', 'travel-agency-platform'); ?></h2>
            <nav class="nav-tab-wrapper" style="margin-bottom:12px;">
                <?php $pay_tabs = [
                    'all'       => __('Todas', 'travel-agency-platform'),
                    'pending'   => __('Pendientes', 'travel-agency-platform'),
                    'completed' => __('Completadas', 'travel-agency-platform'),
                    'failed'    => __('Fallidas', 'travel-agency-platform'),
                    'cancelled' => __('Canceladas', 'travel-agency-platform'),
                ];
                foreach ($pay_tabs as $pk => $plabel):
                    $pcount = isset($tab_counts[$pk]) ? (int) $tab_counts[$pk] : 0;
                    $pclass = $payout_filter === $pk ? ' nav-tab-active' : ''; ?>
                    <a class="nav-tab<?php echo $pclass; ?>" href="<?php echo esc_url(admin_url('admin.php?page=tap-commissions&status=' . $filter . '&payout_status=' . $pk)); ?>"><?php echo esc_html($plabel); ?><?php if ($pcount > 0 && 'failed' === $pk): ?> <span class="update-plugins count-<?php echo $pcount; ?>" style="background:#b91c1c;border-radius:50%;color:#fff;font-size:11px;line-height:1.6;height:16px;min-width:16px;display:inline-block;text-align:center;"><span class="plugin-count"><?php echo $pcount; ?></span></span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>
            <?php $failed_count = $tab_counts['failed'] ?? 0;
            if ($failed_count > 0): ?>
                <div class="notice notice-error inline" style="margin:0 0 12px;"><p><strong><?php echo esc_html(sprintf(__('%d liquidación(es) marcada(s) como fallida(s): el pago no llegó a completarse. Revisa y vuelve a procesarlas.', 'travel-agency-platform'), $failed_count)); ?></strong></p></div>
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
                        <td><?php echo esc_html($p->agency_name ?? 'N/A'); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($p->amount)); ?></td>
                        <td><?php echo esc_html($p->method); ?></td>
                        <td><?php if ('agency' === ($p->source ?? 'admin')): ?><span class="button button-small" style="background:#fffbeb;border-color:#f59e0b;color:#92400e;"><?php esc_html_e('Sol. agencia', 'travel-agency-platform'); ?></span><?php else: ?><span class="button button-small" style="background:#eff6ff;border-color:#3b82f6;color:#1e40af;"><?php esc_html_e('Admin', 'travel-agency-platform'); ?></span><?php endif; ?></td>
                        <td><?php
                            $ids = array_filter(array_map('intval', explode(',', $p->booking_ids)));
                            if ($ids) {
                                $ph = implode(',', array_fill(0, count($ids), '%d'));
                                $codes = $wpdb->get_col($wpdb->prepare("SELECT booking_code FROM {$b_table} WHERE id IN ({$ph})", $ids));
                                echo esc_html(implode(', ', $codes ?: $ids));
                            } else {
                                echo '—';
                            }
                        ?></td>
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
                        <td><input type="text" name="note" class="widefat" placeholder="Comprobante / referencia"></td>
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
            var fmt = new Intl.NumberFormat('es-CR', {style:'currency', currency:'CRC', minimumFractionDigits:0});
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


    public static function disputes_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_disputes';

        if (isset($_POST['tap_resolve_dispute']) && isset($_POST['_wpnonce'])) {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            $outcome    = sanitize_key($_POST['outcome'] ?? '');
            if ($dispute_id && wp_verify_nonce($_POST['_wpnonce'], 'tap_resolve_dispute_' . $dispute_id)) {
                $note = sanitize_textarea_field(wp_unslash($_POST['resolution_note'] ?? ''));
                $res  = TAP_Disputes::resolve($dispute_id, $outcome, $note);
                if (is_wp_error($res)) {
                    set_transient('tap_dispute_notice', __($res->get_error_message(), 'travel-agency-platform'), 60);
                } else {
                    set_transient('tap_dispute_notice', __('Disputa resuelta. Comisión liberada o anulada según el resultado.', 'travel-agency-platform'), 60);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tap-disputes&dfilter=' . sanitize_key($_POST['dfilter'] ?? 'all')));
            exit;
        }

        $filter = sanitize_key($_GET['dfilter'] ?? 'all');
        $counts = $wpdb->get_row("SELECT
            COALESCE(SUM(status='open'),0) open_c,
            COALESCE(SUM(status!='open'),0) closed_c FROM {$table}");

        $where = '1=1';
        if ('open' === $filter) {
            $where = "status = 'open'";
        } elseif ('closed' === $filter) {
            $where = "status != 'open'";
        }
        $disputes = $wpdb->get_results(
            "SELECT d.*, b.booking_code, b.guest_name, b.guest_email AS bmail, a.post_title AS agency_name
             FROM {$table} d
             LEFT JOIN {$wpdb->prefix}tap_bookings b ON d.booking_id = b.id
             LEFT JOIN {$wpdb->posts} a ON d.agency_id = a.ID
             WHERE {$where}
             ORDER BY d.created_at DESC LIMIT 200"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Disputas de reservas', 'travel-agency-platform'); ?></h1>

            <?php $tap_dnotice = get_transient('tap_dispute_notice'); if ($tap_dnotice): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($tap_dnotice); ?></p></div>
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
                            <a href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($d->booking_code))); ?>" target="_blank"><?php echo esc_html($d->booking_code); ?></a>
                            #<?php echo (int) $d->booking_id; ?>
                        </td>
                        <td><?php echo esc_html($d->agency_name ?? 'N/A'); ?></td>
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
