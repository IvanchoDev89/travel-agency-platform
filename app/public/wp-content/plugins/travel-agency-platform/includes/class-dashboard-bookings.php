<?php
/**
 * TAP_Dashboard_Bookings — admin pages of bookings: overview tally,
 * bookings list and CSV export.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Bookings {
    public static function dashboard_page() {
        $stats = TAP_Booking::get_booking_stats();
        $monthly = TAP_Booking::get_monthly_series(12);
        $status = TAP_Booking::get_status_distribution();
        $breakdown = TAP_Booking::get_service_type_breakdown();
        $recent = TAP_Booking::get_latest_bookings(8);

        global $wpdb;
        $counts_cache = TAP_Dashboard::listing_counts();
        $total_agencies = $counts_cache['tap_agency'];
        $total_services = $counts_cache['total_services'];
        $currency = get_option('tap_currency', 'USD');

        $status_labels = ['pending' => __('Pending', 'travel-agency-platform'), 'request' => __('Solicitudes', 'travel-agency-platform'), 'confirmed' => __('Confirmed', 'travel-agency-platform'), 'completed' => __('Completed', 'travel-agency-platform'), 'cancelled' => __('Cancelled', 'travel-agency-platform'), 'refunded' => __('Refunded', 'travel-agency-platform')];

        $service_labels = ['tap_accommodation' => __('Accommodation', 'travel-agency-platform'), 'tap_tour' => __('Tour', 'travel-agency-platform'), 'tap_transport' => __('Transport', 'travel-agency-platform'), 'tap_car_rental' => __('Car Rental', 'travel-agency-platform'), 'tap_boat' => __('Boat', 'travel-agency-platform'), 'tap_package' => __('Package', 'travel-agency-platform')];

        wp_enqueue_style('tap-dash', TAP_PLUGIN_URL . 'assets/css/admin-dashboard.css', [], TAP_VERSION);
        wp_enqueue_script('tap-chart', TAP_PLUGIN_URL . 'assets/js/chart.umd.min.js', [], '4.4.1', false);
        wp_enqueue_script('tap-dash', TAP_PLUGIN_URL . 'assets/js/admin-dashboard.js', ['tap-chart'], TAP_VERSION, true);
        wp_localize_script('tap-dash', 'tapDash', [
            'currency' => $currency,
            'monthly' => $monthly,
            'status' => [array_values($status), array_keys($status)],
            'breakdown' => [
                'labels' => array_map(function ($r) use ($service_labels) { return $service_labels[$r->service_type] ?? $r->service_type; }, $breakdown),
                'data' => array_map(function ($r) { return (int) $r->cnt; }, $breakdown),
            ],
        ]);
        ?>
        <div class="wrap tap-dash">
            <h1><?php esc_html_e('Travel Platform Dashboard', 'travel-agency-platform'); ?></h1>
            <p class="tap-dash-sub"><?php esc_html_e('Resumen general de tu plataforma.', 'travel-agency-platform'); ?></p>

            <div class="tap-dash-cards">
                <div class="tap-dash-card tap-accent-teal">
                    <span class="tap-dash-card-label"><?php esc_html_e('Ingresos', 'travel-agency-platform'); ?></span>
                    <span class="tap-dash-card-value"><?php echo esc_html(TAP_Currency::fmt((float) $stats['revenue'])); ?></span>
                    <span class="tap-dash-card-foot"><?php echo esc_html(number_format_i18n((int) $stats['completed'] + (int) $stats['confirmed'])); ?> <?php esc_html_e('reservas efectivas', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-dash-card tap-accent-amber">
                    <span class="tap-dash-card-label"><?php esc_html_e('Reservas totales', 'travel-agency-platform'); ?></span>
                    <span class="tap-dash-card-value"><?php echo esc_html($stats['total']); ?></span>
                    <span class="tap-dash-card-foot"><?php echo esc_html($status['pending']); ?> <?php esc_html_e('pendientes', 'travel-agency-platform'); ?> · <?php echo esc_html($status['request']); ?> <?php esc_html_e('solicitudes', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-dash-card tap-accent-violet">
                    <span class="tap-dash-card-label"><?php esc_html_e('Comisiones', 'travel-agency-platform'); ?></span>
                    <span class="tap-dash-card-value"><?php echo esc_html(TAP_Currency::fmt((float) $stats['commission'])); ?></span>
                    <span class="tap-dash-card-foot"><?php esc_html_e('acumuladas', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-dash-card tap-accent-sky">
                    <span class="tap-dash-card-label"><?php esc_html_e('Agencias', 'travel-agency-platform'); ?></span>
                    <span class="tap-dash-card-value"><?php echo esc_html($total_agencies); ?></span>
                    <span class="tap-dash-card-foot"><?php echo esc_html($total_services); ?> <?php esc_html_e('servicios activos', 'travel-agency-platform'); ?></span>
                </div>
            </div>

            <div class="tap-dash-grid">
                <div class="tap-dash-panel tap-dash-panel-wide">
                    <h2><?php esc_html_e('Ingresos y reservas mensuales', 'travel-agency-platform'); ?></h2>
                    <div class="tap-chart-box"><canvas id="tap-chart-revenue"></canvas></div>
                </div>
                <div class="tap-dash-panel">
                    <h2><?php esc_html_e('Estados de reserva', 'travel-agency-platform'); ?></h2>
                    <div class="tap-chart-box tap-chart-donut"><canvas id="tap-chart-status"></canvas></div>
                </div>
                <div class="tap-dash-panel">
                    <h2><?php esc_html_e('Reservas por servicio', 'travel-agency-platform'); ?></h2>
                    <div class="tap-chart-box tap-chart-donut"><canvas id="tap-chart-breakdown"></canvas></div>
                </div>
            </div>

            <div class="tap-dash-panel">
                <h2><?php esc_html_e('Reservas recientes', 'travel-agency-platform'); ?></h2>
                <?php if (empty($recent)): ?>
                    <p class="tap-dash-empty"><?php esc_html_e('Aún no hay reservas.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped tap-dash-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Cliente', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $b):
                            $client = get_userdata($b->client_id);
                            $service = get_post($b->service_id);
                            $status_key = isset($status_labels[$b->status]) ? $b->status : 'pending';
                        ?>
                        <tr>
                            <td><a href="admin.php?page=tap-bookings"><?php echo esc_html($b->booking_code); ?></a></td>
                            <td><?php echo $client ? esc_html($client->display_name) : 'N/A'; ?></td>
                            <td><?php echo $service ? esc_html($service->post_title) : 'N/A'; ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($status_key); ?>"><?php echo esc_html(ucfirst($status_labels[$status_key])); ?></span></td>
                            <td><?php echo esc_html(mysql2date('d M Y H:i', $b->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    public static function maybe_export_bookings() {
        if (empty($_GET['page']) || $_GET['page'] !== 'tap-bookings' || empty($_GET['export'])) {
            return;
        }
        if (!current_user_can('tap_manage_bookings')) {
            return;
        }
        if (!check_admin_referer('tap_export_bookings')) {
            return;
        }
        global $wpdb;

        $f_status = sanitize_key(wp_unslash($_GET['f_status'] ?? ''));
        $f_type   = sanitize_key(wp_unslash($_GET['f_type'] ?? ''));
        $f_agency = intval($_GET['f_agency'] ?? 0);
        $s        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where    = '1=1';
        $params   = [];

        if (in_array($f_status, ['pending', 'request', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
            $where .= ' AND b.status = %s';
            $params[] = $f_status;
        }
        $valid_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        if (in_array($f_type, $valid_types, true)) {
            $where .= ' AND b.service_type = %s';
            $params[] = $f_type;
        }
        if ($f_agency) {
            $where .= ' AND b.agency_id = %d';
            $params[] = $f_agency;
        }
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $where .= ' AND (b.booking_code LIKE %s OR b.service_type LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $base = "FROM {$wpdb->prefix}tap_bookings b
                 LEFT JOIN {$wpdb->users} u ON b.client_id = u.ID";
        $sql  = $params ? $wpdb->prepare("SELECT b.*, u.display_name, u.user_email {$base} WHERE {$where}", $params) : "SELECT b.*, u.display_name, u.user_email {$base} WHERE {$where}";
        $rows = $wpdb->get_results($sql . ' ORDER BY b.created_at DESC');

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tap-bookings-' . gmdate('Ymd-His') . '.csv"');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Código', 'travel-agency-platform'), __('Estado', 'travel-agency-platform'), __('Pago', 'travel-agency-platform'), __('Cliente', 'travel-agency-platform'), __('Email', 'travel-agency-platform'), __('Agencia', 'travel-agency-platform'), __('Servicio', 'travel-agency-platform'), __('Título', 'travel-agency-platform'), __('Entrada', 'travel-agency-platform'), __('Salida', 'travel-agency-platform'), __('Adultos', 'travel-agency-platform'), __('Niños', 'travel-agency-platform'), __('Noches', 'travel-agency-platform'), __('Total', 'travel-agency-platform'), __('Comisión %', 'travel-agency-platform'), __('Comisión', 'travel-agency-platform'), __('Estado comisión', 'travel-agency-platform'), __('Creado', 'travel-agency-platform')]);
        foreach ($rows as $b) {
            $agency = get_post($b->agency_id);
            $service = get_post($b->service_id);
            fputcsv($out, array_map([TAP_Dashboard::class, 'csv_cell'], [
                $b->booking_code, $b->status, $b->payment_status, $b->display_name ?? '', $b->user_email ?? '',
                $agency ? $agency->post_title : '', $b->service_type, $service ? $service->post_title : '',
                $b->check_in, $b->check_out, $b->adults, $b->children, $b->nights,
                $b->total_amount, $b->commission_percent, $b->commission_amount, $b->commission_status, $b->created_at,
            ]));
        }
        fclose($out);
        exit;
    }


    public static function bookings_page() {
        global $wpdb;

        $f_status = sanitize_key(wp_unslash($_GET['f_status'] ?? ''));
        $f_type   = sanitize_key(wp_unslash($_GET['f_type'] ?? ''));
        $f_agency = intval($_GET['f_agency'] ?? 0);
        $s        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where    = '1=1';
        $params   = [];

        if (in_array($f_status, ['pending', 'request', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
            $where .= ' AND b.status = %s';
            $params[] = $f_status;
        }
        $valid_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        if (in_array($f_type, $valid_types, true)) {
            $where .= ' AND b.service_type = %s';
            $params[] = $f_type;
        }
        if ($f_agency) {
            $where .= ' AND b.agency_id = %d';
            $params[] = $f_agency;
        }
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $where .= ' AND (b.booking_code LIKE %s OR b.service_type LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $base = "FROM {$wpdb->prefix}tap_bookings b
                 LEFT JOIN {$wpdb->users} u ON b.client_id = u.ID";
        $sql  = $params ? $wpdb->prepare("SELECT b.*, u.display_name, u.user_email {$base} WHERE {$where}", $params) : "SELECT b.*, u.display_name, u.user_email {$base} WHERE {$where}";

        $paged    = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $total    = (int) $wpdb->get_var($params ? $wpdb->prepare("SELECT COUNT(*) {$base} WHERE {$where}", $params) : "SELECT COUNT(*) {$base} WHERE {$where}");
        $offset   = ($paged - 1) * $per_page;
        $bookings = $wpdb->get_results($sql . ' ORDER BY b.created_at DESC LIMIT ' . intval($per_page) . ' OFFSET ' . intval($offset));

        $agencies = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'tap_agency' AND post_status = 'publish' ORDER BY post_title");
        $export_url = wp_nonce_url(add_query_arg(array_filter([
            'page' => 'tap-bookings', 'export' => 1,
            'f_status' => $f_status, 'f_type' => $f_type, 'f_agency' => $f_agency ? $f_agency : null, 's' => $s !== '' ? $s : null,
        ]), admin_url('admin.php')), 'tap_export_bookings');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></h1>
            <form method="get" style="margin:8px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="page" value="tap-bookings">
                <input type="text" name="s" value="<?php echo esc_attr($s); ?>" placeholder="<?php esc_attr_e('Código, cliente o email', 'travel-agency-platform'); ?>">
                <select name="f_status">
                    <option value=""><?php esc_html_e('Todos los estados', 'travel-agency-platform'); ?></option>
                    <?php foreach (['pending', 'request', 'confirmed', 'completed', 'cancelled', 'refunded'] as $st): ?>
                    <option value="<?php echo esc_attr($st); ?>" <?php selected($f_status, $st); ?>><?php echo esc_html(ucfirst($st)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="f_type">
                    <option value=""><?php esc_html_e('Todos los tipos', 'travel-agency-platform'); ?></option>
                    <?php foreach ($valid_types as $vt): ?>
                    <option value="<?php echo esc_attr($vt); ?>" <?php selected($f_type, $vt); ?>><?php echo esc_html(str_replace('tap_', '', $vt)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="f_agency">
                    <option value="0"><?php esc_html_e('Todas las agencias', 'travel-agency-platform'); ?></option>
                    <?php foreach ($agencies as $ag): ?>
                    <option value="<?php echo (int) $ag->ID; ?>" <?php selected($f_agency, $ag->ID); ?>><?php echo esc_html($ag->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button"><?php esc_html_e('Filtrar', 'travel-agency-platform'); ?></button>
                <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
            </form>

            <?php echo TAP_Dashboard::paginate($total, $per_page, $paged, 'tap-bookings'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Code', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Client', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Service', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Agency', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fee', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Date', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$bookings): ?>
                        <tr><td colspan="9"><?php esc_html_e('No bookings found.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($bookings as $b):
                        $service = get_post($b->service_id);
                        $agency = get_post($b->agency_id);
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($b->booking_code))); ?>" target="_blank"><?php echo esc_html($b->booking_code); ?></a></td>
                        <td><?php echo $b->display_name ? esc_html($b->display_name) : 'N/A'; ?></td>
                        <td><?php echo $service ? esc_html($service->post_title) : esc_html($b->service_type); ?></td>
                        <td><?php echo $agency ? esc_html($agency->post_title) : 'N/A'; ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                        <td>
                            <span class="tap-status tap-status-<?php echo esc_attr($b->status); ?> tap-status-display"><?php echo esc_html(ucfirst($b->status)); ?></span>
                            <?php $next_statuses = TAP_Booking::$transitions[$b->status] ?? []; if ($next_statuses): ?>
                            <select class="tap-booking-status-select" data-booking-id="<?php echo (int) $b->id; ?>">
                                <option value="">&mdash; <?php esc_html_e('Cambiar estado', 'travel-agency-platform'); ?> &mdash;</option>
                                <?php foreach ($next_statuses as $ns): ?>
                                <option value="<?php echo esc_attr($ns); ?>"><?php echo esc_html(ucfirst($ns)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->commission_amount)) . ' <span style="color:#64748b;">(' . esc_html($b->commission_status) . ')</span>'; ?></td>
                        <td><?php echo esc_html((float) ($b->booking_fee ?? 0) > 0 ? TAP_Currency::fmt($b->booking_fee) : '&mdash;'); ?></td>
                        <td><?php echo esc_html($b->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo TAP_Dashboard::paginate($total, $per_page, $paged, 'tap-bookings'); ?>
        </div>
        <?php
    }


}
