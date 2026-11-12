<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Referral analytics (admin).
 */
class TAP_Referral_Reports {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_post_tap_export_referrals', [__CLASS__, 'export_csv']);
        add_action('admin_post_tap_referral_approve', [__CLASS__, 'handle_approve']);
        add_action('admin_post_tap_referral_reject', [__CLASS__, 'handle_reject']);
    }

    public static function handle_approve() {
        self::handle_decision('approve');
    }

    public static function handle_reject() {
        self::handle_decision('reject');
    }

    private static function handle_decision($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        check_admin_referer('tap_referral_' . $action);
        $row_id = isset($_GET['row']) ? absint($_GET['row']) : 0;
        if ($row_id) {
            if ('approve' === $action) {
                TAP_Customer_Referrals::approve_pending($row_id);
            } else {
                TAP_Customer_Referrals::reject_pending($row_id);
            }
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'tap-referral-reports', 'reviewed' => $action],
            admin_url('options-general.php')
        ));
        exit;
    }

    public static function add_page() {
        add_submenu_page(
            'options-general.php',
            __('Reportes de referidos', 'travel-agency-platform'),
            __('Referidos – Reportes', 'travel-agency-platform'),
            'manage_options',
            'tap-referral-reports',
            [__CLASS__, 'render_page']
        );
    }

    private static function date_filters() {
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : gmdate('Y-m-01');
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = gmdate('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = gmdate('Y-m-d');
        }
        return [$from, $to];
    }

    public static function metrics($from, $to) {
        global $wpdb;
        $t = TAP_Customer_Referrals::table();
        $b = $wpdb->prefix . 'tap_bookings';
        $l = $wpdb->prefix . 'tap_loyalty_points';

        $where = "WHERE r.created_at >= %s AND r.created_at <= %s";
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

        $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} r {$where}", $params));
        $booked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} r {$where} AND r.status IN ('booked','rewarded')", $params));
        $rewarded = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} r {$where} AND r.status = 'rewarded'", $params));
        $revenue = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(b.total_amount),0) FROM {$t} r LEFT JOIN {$b} b ON b.id = r.booking_id {$where} AND r.status = 'rewarded'",
            $params
        ));
        $points_awarded = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(r.points_awarded),0) FROM {$t} r {$where}", $params));
        $points_redeemed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(-points),0) FROM {$l} WHERE source = 'redemption' AND created_at >= %s AND created_at <= %s AND points < 0",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ));

        return [
            'clicks' => $clicks,
            'booked' => $booked,
            'rewarded' => $rewarded,
            'revenue' => $revenue,
            'points_awarded' => $points_awarded,
            'points_redeemed' => $points_redeemed,
        ];
    }

    public static function top_referrers($from, $to, $limit = 10) {
        global $wpdb;
        $t = TAP_Customer_Referrals::table();
        $b = $wpdb->prefix . 'tap_bookings';
        $sql = $wpdb->prepare(
            "SELECT r.referrer_user_id,
                    COUNT(*) AS referrals,
                    SUM(CASE WHEN r.status = 'rewarded' THEN 1 ELSE 0 END) AS rewarded,
                    COALESCE(SUM(CASE WHEN r.status = 'rewarded' THEN b.total_amount ELSE 0 END),0) AS revenue,
                    COALESCE(SUM(r.points_awarded),0) AS points
             FROM {$t} r
             LEFT JOIN {$b} b ON b.id = r.booking_id
             WHERE r.created_at >= %s AND r.created_at <= %s
             GROUP BY r.referrer_user_id
             ORDER BY revenue DESC, referrals DESC
             LIMIT %d",
            $from . ' 00:00:00', $to . ' 23:59:59', (int) $limit
        );
        return $wpdb->get_results($sql) ?: [];
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        list($from, $to) = self::date_filters();
        $m = self::metrics($from, $to);
        $top = self::top_referrers($from, $to);
        $conv_click_book = $m['clicks'] > 0 ? round(($m['booked'] / $m['clicks']) * 100, 1) : 0;
        $conv_book_reward = $m['booked'] > 0 ? round(($m['rewarded'] / $m['booked']) * 100, 1) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reportes de referidos', 'travel-agency-platform'); ?></h1>
            <form method="get" style="margin-bottom:16px;">
                <input type="hidden" name="page" value="tap-referral-reports">
                <label><?php esc_html_e('Desde', 'travel-agency-platform'); ?> <input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
                <label><?php esc_html_e('Hasta', 'travel-agency-platform'); ?> <input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
                <button class="button"><?php esc_html_e('Filtrar', 'travel-agency-platform'); ?></button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tap_export_referrals&from=' . urlencode($from) . '&to=' . urlencode($to)), 'tap_export_referrals')); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
            </form>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
                <div class="card"><h3><?php esc_html_e('Clics', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo (int) $m['clicks']; ?></p></div>
                <div class="card"><h3><?php esc_html_e('Reservas', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo (int) $m['booked']; ?></p><small><?php echo esc_html($conv_click_book); ?>% <?php esc_html_e('de clics', 'travel-agency-platform'); ?></small></div>
                <div class="card"><h3><?php esc_html_e('Completados', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo (int) $m['rewarded']; ?></p><small><?php echo esc_html($conv_book_reward); ?>% <?php esc_html_e('de reservas', 'travel-agency-platform'); ?></small></div>
                <div class="card"><h3><?php esc_html_e('Ingresos atribuidos', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo esc_html(TAP_Currency::fmt($m['revenue'])); ?></p></div>
                <div class="card"><h3><?php esc_html_e('Puntos otorgados', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo (int) $m['points_awarded']; ?></p></div>
                <div class="card"><h3><?php esc_html_e('Puntos canjeados', 'travel-agency-platform'); ?></h3><p style="font-size:24px;font-weight:700;"><?php echo (int) $m['points_redeemed']; ?></p></div>
            </div>

            <h2><?php esc_html_e('Top referidores', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Usuario', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Nivel', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Referidos', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Completados', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Ingresos', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Puntos', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($top): ?>
                        <?php foreach ($top as $row): ?>
                            <?php $u = get_userdata((int) $row->referrer_user_id); ?>
                            <tr>
                                <td><?php echo $u ? esc_html($u->display_name . ' (#' . $u->ID . ')') : esc_html('#' . $row->referrer_user_id); ?></td>
                                <td><?php echo esc_html(TAP_Customer_Referrals::tiers()[TAP_Customer_Referrals::tier((int) $row->referrer_user_id)]['label']); ?></td>
                                <td><?php echo (int) $row->referrals; ?></td>
                                <td><?php echo (int) $row->rewarded; ?></td>
                                <td><?php echo esc_html(TAP_Currency::fmt((float) $row->revenue)); ?></td>
                                <td><?php echo (int) $row->points; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><?php esc_html_e('Sin datos en el rango seleccionado.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $pending = TAP_Customer_Referrals::pending_review(); ?>
            <h2 style="margin-top:24px;"><?php esc_html_e('Pendientes de revisión', 'travel-agency-platform'); ?></h2>
            <p class="description"><?php esc_html_e('Recompensas de puntos altos o marcadas como sospechosas. Aprueba para acreditar los puntos o rechaza para descartarlas.', 'travel-agency-platform'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Referidor', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Motivo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pending): ?>
                        <?php foreach ($pending as $r): ?>
                            <?php
                            $u = get_userdata((int) $r->referrer_user_id);
                            $approve_url = wp_nonce_url(
                                admin_url('admin-post.php?action=tap_referral_approve&row=' . (int) $r->id),
                                'tap_referral_approve'
                            );
                            $reject_url = wp_nonce_url(
                                admin_url('admin-post.php?action=tap_referral_reject&row=' . (int) $r->id),
                                'tap_referral_reject'
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $r->created_at); ?></td>
                                <td><?php echo $u ? esc_html($u->display_name . ' (#' . $u->ID . ')') : esc_html('#' . $r->referrer_user_id); ?></td>
                                <td><?php echo $r->booking_id ? '#' . (int) $r->booking_id : '—'; ?></td>
                                <td><?php echo esc_html((string) $r->referral_code); ?></td>
                                <td><?php echo (int) $r->is_suspicious ? esc_html__('Sospechoso', 'travel-agency-platform') : esc_html__('Puntos altos', 'travel-agency-platform'); ?></td>
                                <td>
                                    <a class="button button-primary" href="<?php echo esc_url($approve_url); ?>"><?php esc_html_e('Aprobar', 'travel-agency-platform'); ?></a>
                                    <a class="button" href="<?php echo esc_url($reject_url); ?>"><?php esc_html_e('Rechazar', 'travel-agency-platform'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><?php esc_html_e('No hay recompensas pendientes.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('');
        }
        check_admin_referer('tap_export_referrals');
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : gmdate('Y-m-01');
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : gmdate('Y-m-d');
        $rows = self::top_referrers($from, $to, 1000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=referrals-' . $from . '-' . $to . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['user_id', 'display_name', 'tier', 'referrals', 'rewarded', 'revenue', 'points']);
        foreach ($rows as $row) {
            $u = get_userdata((int) $row->referrer_user_id);
            fputcsv($out, [
                (int) $row->referrer_user_id,
                $u ? $u->display_name : '',
                TAP_Customer_Referrals::tiers()[TAP_Customer_Referrals::tier((int) $row->referrer_user_id)]['label'],
                (int) $row->referrals,
                (int) $row->rewarded,
                (float) $row->revenue,
                (int) $row->points,
            ]);
        }
        fclose($out);
        exit;
    }
}