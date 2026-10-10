<?php
/**
 * TAP_Dashboard_Insights — admin pages of insights: reports, analytics
 * and CSV export.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Insights {
    public static function reports_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $monthly = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as bookings,
                    SUM(total_amount) as revenue,
                    SUM(commission_amount) as commission,
                    SUM(booking_fee) as fees
            FROM {$wpdb->prefix}tap_bookings
            WHERE status NOT IN ('cancelled', 'refunded')
            GROUP BY month ORDER BY month DESC LIMIT 12"
        );
        $totals = $wpdb->get_row(
            "SELECT COUNT(*) as bookings,
                    SUM(total_amount) as gmv,
                    SUM(commission_amount) as commission,
                    SUM(booking_fee) as fees
            FROM $table
            WHERE status NOT IN ('cancelled', 'refunded')"
        );
        $promo_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}tap_promos WHERE status = 'active'"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reports', 'travel-agency-platform'); ?></h1>
            <div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($totals->gmv ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('GMV', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($totals->commission ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisiones (plataforma)', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($totals->fees ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Booking fees (plataforma)', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($promo_revenue)); ?></span><span class="tap-stat-label"><?php esc_html_e('Destacados (confirmados)', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($totals->bookings ?? 0); ?></span><span class="tap-stat-label"><?php esc_html_e('Reservas', 'travel-agency-platform'); ?></span></div>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Month', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Revenue (GMV)', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Booking Fees', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $m): ?>
                    <tr>
                        <td><?php echo esc_html($m->month); ?></td>
                        <td><?php echo esc_html($m->bookings); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($m->revenue)); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($m->commission)); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($m->fees ?? 0)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px;"><?php esc_html_e('Atribución de leads', 'travel-agency-platform'); ?></h2>
            <?php
            $attr_rows = $wpdb->get_results(
                "SELECT l.id, l.name, l.email, l.created_at,
                        COALESCE(a.name, CONCAT('#', l.agency_id)) agencia,
                        b.booking_code, b.status booking_status, b.total_amount
                 FROM {$wpdb->prefix}tap_leads l
                 LEFT JOIN {$wpdb->prefix}tap_agencies a ON a.id = l.agency_id
                 LEFT JOIN {$wpdb->prefix}tap_bookings b ON b.lead_id = l.id
                 ORDER BY l.id DESC, (b.status = 'confirmed') DESC"
            );
            $attr_best = [];
            foreach ($attr_rows as $r) {
                $id = (int) $r->id;
                if (!isset($attr_best[$id])) {
                    $attr_best[$id] = $r;
                }
            }
            $n_leads = count($attr_best);
            $n_conv  = 0;
            foreach ($attr_best as $r) {
                if (isset($r->booking_status) && $r->booking_status === 'confirmed') $n_conv++;
            }
            $attr_rate = $n_leads > 0 ? ($n_conv / $n_leads) * 100 : 0;
            ?>
            <div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($n_leads); ?></span><span class="tap-stat-label"><?php esc_html_e('Lead', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html($n_conv); ?></span><span class="tap-stat-label"><?php esc_html_e('Conversiones (reservas confirmadas)', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format($attr_rate, 1) . '%'); ?></span><span class="tap-stat-label"><?php esc_html_e('Tasa de conversión', 'travel-agency-platform'); ?></span></div>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Correo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($attr_best, 0, 20) as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->name); ?></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html($r->agencia ?? '—'); ?></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td><?php echo $r->booking_code ? esc_html($r->booking_code) : esc_html__('Sin reserva vinculada', 'travel-agency-platform'); ?></td>
                        <td><?php echo $r->booking_status ? esc_html($r->booking_status) : '—'; ?></td>
                        <td><?php echo $r->booking_code ? esc_html(TAP_Currency::fmt($r->total_amount)) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    public static function analytics_page() {
        global $wpdb;

        if (isset($_GET['export']) && check_admin_referer('tap_analytics_export')) {
            self::analytics_csv_export(sanitize_key(wp_unslash($_GET['export'])));
            exit;
        }

        $from = sanitize_text_field(wp_unslash($_GET['tap_from'] ?? ''));
        $to   = sanitize_text_field(wp_unslash($_GET['tap_to'] ?? ''));
        if ($from && !preg_match('/^\d{4}-\d{2}$/', $from)) $from = '';
        if ($to && !preg_match('/^\d{4}-\d{2}$/', $to)) $to = '';
        $bwhere  = "b.status NOT IN ('cancelled','refunded')";
        $bparams = [];
        if ($from) { $bwhere .= $wpdb->prepare(" AND DATE_FORMAT(b.created_at,'%%Y-%%m') >= %s", $from); }
        if ($to)   { $bwhere .= $wpdb->prepare(" AND DATE_FORMAT(b.created_at,'%%Y-%%m') <= %s", $to); }

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS gmv,
                    COALESCE(SUM(b.commission_amount),0) AS commission, COALESCE(SUM(b.booking_fee),0) AS fees
             FROM {$wpdb->prefix}tap_bookings b WHERE {$bwhere}",
            ...$bparams
        ));

        $active_agencies = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_agencies WHERE is_active = 1");
        $total_listings = TAP_Dashboard::listing_counts()['total_services'];

        $mrr = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(p.price_monthly),0) FROM {$wpdb->prefix}tap_agency_subscriptions s
             JOIN {$wpdb->prefix}tap_plans p ON p.id = s.plan_id
             WHERE s.status = 'active'"
        );
        $promo_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}tap_promos WHERE status = 'active'"
        );

        $monthly = array_fill(0, 12, ['label' => '', 'bookings' => 0, 'gmv' => 0, 'commission' => 0, 'fees' => 0, 'subs' => 0, 'promos' => 0]);
        $base = new DateTime('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $m = clone $base;
            $m->modify("-$i months");
            $monthly[$i]['label'] = $m->format('Y-m');
        }

        $mbook = $wpdb->get_results("SELECT DATE_FORMAT(b.created_at,'%Y-%m') m, COUNT(*) c, SUM(b.total_amount) gmv, SUM(b.commission_amount) com, SUM(b.booking_fee) fee FROM {$wpdb->prefix}tap_bookings b WHERE b.status NOT IN ('cancelled','refunded') GROUP BY m");
        $msub  = $wpdb->get_results("SELECT DATE_FORMAT(s.created_at,'%Y-%m') m, SUM(p.price_monthly) amt FROM {$wpdb->prefix}tap_agency_subscriptions s JOIN {$wpdb->prefix}tap_plans p ON p.id=s.plan_id WHERE s.payment_status='paid' GROUP BY m");
        $mpromo = $wpdb->get_results("SELECT DATE_FORMAT(p.updated_at,'%Y-%m') m, SUM(p.amount) amt FROM {$wpdb->prefix}tap_promos p WHERE p.payment_status='paid' GROUP BY m");

        foreach ($monthly as $k => &$row) {
            foreach ($mbook as $r) { if ($r->m === $row['label']) { $row['bookings'] = (int) $r->c; $row['gmv'] = (float) $r->gmv; $row['commission'] = (float) $r->com; $row['fees'] = (float) $r->fee; } }
            foreach ($msub as $r) { if ($r->m === $row['label']) { $row['subs'] = (float) $r->amt; } }
            foreach ($mpromo as $r) { if ($r->m === $row['label']) { $row['promos'] = (float) $r->amt; } }
        }
        unset($row);

        $top_agencies = $wpdb->get_results($wpdb->prepare(
            "SELECT b.agency_id, COALESCE(ag.post_title, a.name, CONCAT('#', b.agency_id)) AS name,
                    COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS gmv,
                    COALESCE(SUM(b.commission_amount),0) AS commission, COALESCE(SUM(b.booking_fee),0) AS fees
             FROM {$wpdb->prefix}tap_bookings b
             LEFT JOIN {$wpdb->posts} ag ON ag.ID = b.agency_id AND ag.post_type = 'tap_agency'
             LEFT JOIN {$wpdb->prefix}tap_agencies a ON a.id = b.agency_id
             WHERE {$bwhere}
             GROUP BY b.agency_id, ag.post_title, a.name ORDER BY gmv DESC LIMIT 10",
            ...$bparams
        ));

        $top_listings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.service_id, b.service_type, p.post_title AS title,
                    COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS gmv
             FROM {$wpdb->prefix}tap_bookings b
             LEFT JOIN {$wpdb->posts} p ON p.ID = b.service_id
             WHERE {$bwhere}
             GROUP BY b.service_id, b.service_type, p.post_title ORDER BY gmv DESC LIMIT 10",
            ...$bparams
        ));
        $listing_views = class_exists('TAP_Analytics') ? TAP_Analytics::listing_views($from, $to) : [];

        krsort($monthly);
        $max_total = 1.0;
        foreach ($monthly as $m) {
            $max_total = max($max_total, $m['commission'] + $m['fees'] + $m['subs'] + $m['promos']);
        }

        $export_url = function ($type) use ($from, $to) {
            $args = ['page' => 'tap-analytics', 'export' => $type, '_wpnonce' => wp_create_nonce('tap_analytics_export')];
            if ($from) $args['tap_from'] = $from;
            if ($to) $args['tap_to'] = $to;
            return add_query_arg($args);
        };

        $avg = $totals->bookings > 0 ? $totals->gmv / $totals->bookings : 0;
        $year_rev = 0.0;
        foreach ($monthly as $m) { $year_rev += $m['commission'] + $m['fees'] + $m['subs'] + $m['promos']; }

        $views_total = class_exists('TAP_Analytics') ? TAP_Analytics::total_views($from, $to) : 0;
        $conversion  = 0.0;
        if ($views_total > 0 && $totals->bookings > 0) {
            $conversion = ($totals->bookings / $views_total) * 100;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Analytics', 'travel-agency-platform') . '</h1>';

        echo '<form method="get" style="margin-bottom:16px;">';
        echo '<input type="hidden" name="page" value="tap-analytics">';
        echo '<label>' . esc_html__('Desde', 'travel-agency-platform') . ' <input type="month" name="tap_from" value="' . esc_attr($from) . '"></label> ';
        echo '<label>' . esc_html__('Hasta', 'travel-agency-platform') . ' <input type="month" name="tap_to" value="' . esc_attr($to) . '"></label> ';
        submit_button(__('Filtrar', 'travel-agency-platform'), 'secondary', '', false);
        echo ' <a class="button" href="' . esc_url($export_url('bookings')) . '">' . esc_html__('Exportar reservas (CSV)', 'travel-agency-platform') . '</a>';
        echo ' <a class="button" href="' . esc_url($export_url('summary')) . '">' . esc_html__('Exportar resumen (CSV)', 'travel-agency-platform') . '</a>';
        echo '</form>';

        echo '<div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($year_rev)) . '</span><span class="tap-stat-label">' . esc_html__('Ingresos plataforma (12m)', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;">' . esc_html(TAP_Currency::fmt($mrr)) . '/mes</span><span class="tap-stat-label">' . esc_html__('MRR suscripciones', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($totals->gmv ?? 0)) . '</span><span class="tap-stat-label">' . esc_html__('GMV (filtro)', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($promo_revenue)) . '</span><span class="tap-stat-label">' . esc_html__('Destacados activos', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html($totals->bookings ?? 0) . '</span><span class="tap-stat-label">' . esc_html__('Reservas vigentes', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($avg)) . '</span><span class="tap-stat-label">' . esc_html__('Ticket promedio', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(number_format($views_total, 0)) . '</span><span class="tap-stat-label">' . esc_html__('Vistas (filtro)', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(number_format($conversion, 2) . '%') . '</span><span class="tap-stat-label">' . esc_html__('Conversión vistas→reserva', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html($active_agencies) . '</span><span class="tap-stat-label">' . esc_html__('Agencias activas', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html($total_listings) . '</span><span class="tap-stat-label">' . esc_html__('Listados publicados', 'travel-agency-platform') . '</span></div>';
        echo '</div>';

        $funnel = class_exists('TAP_Analytics') ? TAP_Analytics::booking_funnel($from, $to) : [];
        if ($funnel) {
            $top = max(1, (int) $funnel[0]['value']);
            echo '<h2>' . esc_html__('Embudo de reservas', 'travel-agency-platform') . '</h2>';
            echo '<table class="widefat striped" style="max-width:720px;margin-bottom:24px;"><thead><tr>';
            echo '<th>' . esc_html__('Etapa', 'travel-agency-platform') . '</th>';
            echo '<th>' . esc_html__('Cantidad', 'travel-agency-platform') . '</th>';
            echo '<th>' . esc_html__('Conversión de paso', 'travel-agency-platform') . '</th>';
            echo '<th>' . esc_html__('% del total', 'travel-agency-platform') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($funnel as $i => $stage) {
                $pct_total = (int) round($stage['value'] / $top * 100);
                echo '<tr>';
                echo '<td>' . esc_html($stage['label']) . '</td>';
                echo '<td>' . esc_html(number_format_i18n($stage['value'])) . '</td>';
                echo '<td>' . ($i === 0 ? '&mdash;' : esc_html(number_format_i18n($stage['rate'], 1) . '%')) . '</td>';
                echo '<td>' . esc_html($pct_total . '%') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Ingresos de la plataforma por mes (12 meses)', 'travel-agency-platform') . '</h2>';
        echo '<style>
            .tap-chart{display:flex;align-items:flex-end;gap:8px;height:220px;padding:12px 4px 24px;border-bottom:1px solid #e2e8f0;overflow-x:auto;}
            .tap-chart-col{display:flex;flex-direction:column;justify-content:flex-end;align-items:center;flex:1 0 46px;min-width:46px;}
            .tap-chart-bars{display:flex;align-items:flex-end;gap:2px;height:100%;width:100%;}
            .tap-bar{width:12px;border-radius:3px 3px 0 0;}
            .tap-bar-com{background:#0ea5e9}.tap-bar-fee{background:#22c55e}.tap-bar-sub{background:#8b5cf6}.tap-bar-promo{background:#f59e0b}
            .tap-chart-label{font-size:10px;color:#64748b;margin-top:6px;white-space:nowrap;}
            .tap-legend{display:flex;gap:16px;font-size:12px;color:#334155;margin:8px 0 4px;}
            .tap-legend span::before{content:"";display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;}
            .tap-legend .l-com::before{background:#0ea5e9}.tap-legend .l-fee::before{background:#22c55e}.tap-legend .l-sub::before{background:#8b5cf6}.tap-legend .l-promo::before{background:#f59e0b}
        </style>';
        echo '<div class="tap-legend"><span class="l-com">' . esc_html__('Comisiones', 'travel-agency-platform') . '</span><span class="l-fee">' . esc_html__('Booking fees', 'travel-agency-platform') . '</span><span class="l-sub">' . esc_html__('Suscripciones', 'travel-agency-platform') . '</span><span class="l-promo">' . esc_html__('Destacados', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-chart">';
        foreach ($monthly as $m) {
            $com = $m['commission'] / $max_total * 100;
            $fee = $m['fees'] / $max_total * 100;
            $sub = $m['subs'] / $max_total * 100;
            $pro = $m['promos'] / $max_total * 100;
            echo '<div class="tap-chart-col">';
            echo '<div class="tap-chart-bars">';
            foreach ([['com', $com], ['fee', $fee], ['sub', $sub], ['promo', $pro]] as [$cls, $h]) {
                if ($h > 0.3) {
                    echo '<div class="tap-bar tap-bar-' . esc_attr($cls) . '" style="height:' . esc_attr(min(100, $h)) . '%" title="' . esc_attr($m['label'] . ' ' . number_format($h / 100 * $max_total, 2)) . '"></div>';
                }
            }
            echo '</div>';
            echo '<div class="tap-chart-label">' . esc_html($m['label']) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<h2>' . esc_html__('Fuentes de ingreso (12 meses)', 'travel-agency-platform') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Fuente', 'Monto', '%'] as $h) { echo '<th>' . esc_html($h) . '</th>'; }
        echo '</tr></thead><tbody>';
        $source_rows = [
            __('Comisiones', 'travel-agency-platform') => array_sum(array_column($monthly, 'commission')),
            __('Booking fees', 'travel-agency-platform') => array_sum(array_column($monthly, 'fees')),
            __('Suscripciones', 'travel-agency-platform') => array_sum(array_column($monthly, 'subs')),
            __('Destacados', 'travel-agency-platform') => array_sum(array_column($monthly, 'promos')),
        ];
        foreach ($source_rows as $label => $amt) {
            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html(TAP_Currency::fmt($amt)) . '</td><td>' . esc_html($year_rev > 0 ? number_format($amt / $year_rev * 100, 1) . '%' : '—') . '</td></tr>';
        }
        echo '<tr><td><strong>' . esc_html__('Total', 'travel-agency-platform') . '</strong></td><td><strong>' . esc_html(TAP_Currency::fmt($year_rev)) . '</strong></td><td>100%</td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Top agencias', 'travel-agency-platform') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Agencia', 'Reservas', 'GMV', 'Comisión plataforma', 'Booking fees'] as $h) { echo '<th>' . esc_html($h) . '</th>'; }
        echo '</tr></thead><tbody>';
        if (!$top_agencies) { echo '<tr><td colspan="5">' . esc_html__('Sin datos en el periodo.', 'travel-agency-platform') . '</td></tr>'; }
        foreach ($top_agencies as $a) {
            echo '<tr><td>' . esc_html($a->name) . '</td><td>' . esc_html($a->bookings) . '</td><td>' . esc_html(TAP_Currency::fmt($a->gmv)) . '</td><td>' . esc_html(TAP_Currency::fmt($a->commission)) . '</td><td>' . esc_html(TAP_Currency::fmt($a->fees)) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Top listados', 'travel-agency-platform') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Listado', 'Tipo', 'Vistas', 'Reservas', 'Conversión', 'GMV'] as $h) { echo '<th>' . esc_html($h) . '</th>'; }
        echo '</tr></thead><tbody>';
        if (!$top_listings) { echo '<tr><td colspan="6">' . esc_html__('Sin datos en el periodo.', 'travel-agency-platform') . '</td></tr>'; }
        foreach ($top_listings as $l) {
            $views = isset($listing_views[(int) $l->service_id]) ? (float) $listing_views[(int) $l->service_id]->views : 0;
            $conv = $views > 0 ? ($l->bookings / $views) * 100 : 0;
            echo '<tr><td>' . esc_html($l->title ?: '# ' . $l->service_id) . '</td><td>' . esc_html(str_replace('tap_', '', $l->service_type)) . '</td>'
                . '<td>' . esc_html(number_format($views, 0)) . '</td><td>' . esc_html($l->bookings) . '</td>'
                . '<td>' . esc_html(number_format($conv, 2) . '%') . '</td><td>' . esc_html(TAP_Currency::fmt($l->gmv)) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }


    private static function analytics_csv_export($type) {
        global $wpdb;
        $from = sanitize_text_field(wp_unslash($_GET['tap_from'] ?? ''));
        $to   = sanitize_text_field(wp_unslash($_GET['tap_to'] ?? ''));
        if ($from && !preg_match('/^\d{4}-\d{2}$/', $from)) $from = '';
        if ($to && !preg_match('/^\d{4}-\d{2}$/', $to)) $to = '';

        $filename = 'tap_' . $type . ($from ? '_' . $from : '') . ($to ? '_' . $to : '') . '.csv';

        if ($type === 'bookings') {
            $where = "WHERE b.status NOT IN ('cancelled','refunded')";
            if ($from) { $where .= $wpdb->prepare(" AND DATE_FORMAT(b.created_at,'%%Y-%%m') >= %s", $from); }
            if ($to)   { $where .= $wpdb->prepare(" AND DATE_FORMAT(b.created_at,'%%Y-%%m') <= %s", $to); }
            $rows = $wpdb->get_results(
                "SELECT b.booking_code, DATE_FORMAT(b.created_at,'%Y-%m-%d') fecha, UPPER(b.status) estado,
                        COALESCE(ag.post_title, a.name, CONCAT('#', b.agency_id)) agencia,
                        COALESCE(p.post_title, CONCAT('#', b.service_id)) servicio, b.service_type tipo,
                        b.total_amount total, b.booking_fee fee, b.commission_amount comision, b.commission_percent comision_pct,
                        b.total_amount - COALESCE(b.booking_fee,0) - b.commission_amount neto,
                        COALESCE(b.guest_name,'') cliente, COALESCE(b.guest_email,'') email_cliente,
                        COALESCE(b.payment_method,'') metodo_pago, COALESCE(b.payment_status,'') pago
                 FROM {$wpdb->prefix}tap_bookings b
                 LEFT JOIN {$wpdb->posts} ag ON ag.ID = b.agency_id AND ag.post_type = 'tap_agency'
                 LEFT JOIN {$wpdb->prefix}tap_agencies a ON a.id = b.agency_id
                 LEFT JOIN {$wpdb->posts} p ON p.ID = b.service_id
                 {$where} ORDER BY b.created_at DESC"
            );
            $header = ['Codigo', 'Fecha', 'Estado', 'Agencia', 'Servicio', 'Tipo', 'Total', 'BookingFee', 'Comision', 'ComisionPct', 'Neto', 'Cliente', 'EmailCliente', 'MetodoPago', 'Pago'];
        } else {
            $bwhere  = "status NOT IN ('cancelled','refunded')";
            if ($from) { $bwhere .= $wpdb->prepare(" AND DATE_FORMAT(created_at,'%%Y-%%m') >= %s", $from); }
            if ($to)   { $bwhere .= $wpdb->prepare(" AND DATE_FORMAT(created_at,'%%Y-%%m') <= %s", $to); }
            $rows = $wpdb->get_results(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') mes,
                        COUNT(*) reservas, SUM(total_amount) gmv, SUM(commission_amount) comisiones, SUM(booking_fee) fees
                 FROM {$wpdb->prefix}tap_bookings WHERE {$bwhere} GROUP BY mes ORDER BY mes DESC"
            );
            $header = ['Mes', 'Reservas', 'GMV', 'Comisiones', 'Booking fees', 'Suscripciones', 'Destacados', 'Vistas'];
            $subs = $wpdb->get_results("SELECT DATE_FORMAT(s.created_at,'%Y-%m') mes, SUM(p.price_monthly) total FROM {$wpdb->prefix}tap_agency_subscriptions s JOIN {$wpdb->prefix}tap_plans p ON p.id=s.plan_id WHERE s.payment_status='paid' GROUP BY mes");
            $promos = $wpdb->get_results("SELECT DATE_FORMAT(updated_at,'%Y-%m') mes, SUM(amount) total FROM {$wpdb->prefix}tap_promos WHERE payment_status='paid' GROUP BY mes");
            $subs_map = []; foreach ($subs as $s) { $subs_map[$s->mes] = (float) $s->total; }
            $promos_map = []; foreach ($promos as $q) { $promos_map[$q->mes] = (float) $q->total; }
            $views_by_month = [];
            if (class_exists('TAP_Analytics')) {
                $vrows = $wpdb->get_results("SELECT DATE_FORMAT(view_date,'%Y-%m') mes, COALESCE(SUM(views),0) v FROM " . TAP_Analytics::views_table() . " GROUP BY mes");
                foreach ($vrows as $v) { $views_by_month[$v->mes] = (float) $v->v; }
            }
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            $line = (array) $row;
            if ($type === 'summary') {
                $line[] = $subs_map[$line['mes']] ?? 0;
                $line[] = $promos_map[$line['mes']] ?? 0;
                $line[] = $views_by_month[$line['mes']] ?? 0;
            }
            fputcsv($out, array_map([TAP_Dashboard::class, 'csv_cell'], $line));
        }
        fclose($out);
        exit;
    }

    /** Sheet-safe CSV cell: numeric stays untouched, anything the spreadsheet
     * would interpret as a formula gets neutralised with a leading quote. */

}
