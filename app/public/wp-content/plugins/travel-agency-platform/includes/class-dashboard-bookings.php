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
        if (!current_user_can('tap_manage_bookings') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        $stats = TAP_Booking_Stats::get_booking_stats();
        $monthly = TAP_Booking_Stats::get_monthly_series(12);
        $status = TAP_Booking_Stats::get_status_distribution();
        $breakdown = TAP_Booking_Stats::get_service_type_breakdown();
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
                'labels' => array_map(function ($r) use ($service_labels) {
					return $service_labels[$r->service_type] ?? $r->service_type; }, $breakdown),
                'data' => array_map(function ($r) {
					return (int) $r->cnt; }, $breakdown),
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
                        <?php
                        foreach ($recent as $b):
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
        $view     = sanitize_key(wp_unslash($_GET['view'] ?? 'list'));
        if (!in_array($view, ['list', 'calendar'], true)) {
            $view = 'list';
        }
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

        $view = 'list';

        // Bulk actions.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tap_bulk_action'])) {
            check_admin_referer('tap_bulk_bookings_nonce');
            if (!current_user_can('tap_manage_bookings') && !current_user_can('manage_options')) {
                wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
            }
            $bulk_action = sanitize_key(wp_unslash($_POST['tap_bulk_action']));
            $ids = array_map('intval', (array) ($_POST['booking_ids'] ?? []));
            $ids = array_filter($ids);
            $allowed = ['confirmed', 'completed', 'cancelled'];
            if (in_array($bulk_action, $allowed, true) && $ids) {
                $user_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
                $is_admin = current_user_can('manage_options');
                foreach ($ids as $bid) {
                    $bk = TAP_Booking::get_booking($bid);
                    if (!$bk) continue;
                    if (!$is_admin && $user_agency && (int) $bk->agency_id !== $user_agency) continue;
                    // Do not cancel paid bookings as non-admin.
                    if (in_array($bulk_action, ['cancelled'], true) && !$is_admin && $bk->payment_status === 'paid') continue;
                    TAP_Booking::update_status($bid, $bulk_action);
                }
            }
            // Redirect to avoid resubmission.
            $redirect = add_query_arg(['page' => 'tap-bookings', 'bulk_done' => '1'], admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

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

            <?php if (isset($_GET['bulk_done'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Acción masiva aplicada.', 'travel-agency-platform'); ?></p></div>
            <?php endif; ?>
            <div style="margin:8px 0;display:flex;gap:8px;align-items:center;">
                <a class="button <?php echo 'calendar' === $view ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('view', 'calendar')); ?>"><?php esc_html_e('Calendario', 'travel-agency-platform'); ?></a>
                <a class="button <?php echo 'list' === $view ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('view', 'list')); ?>"><?php esc_html_e('Lista', 'travel-agency-platform'); ?></a>
            </div>

            <?php if ('calendar' === $view):
                $cal_month = isset($_GET['cal_month']) ? sanitize_text_field(wp_unslash($_GET['cal_month'])) : gmdate('Y-m');
                if (!preg_match('/^\d{4}-\d{2}$/', $cal_month)) $cal_month = gmdate('Y-m');
                list($cal_y, $cal_m) = explode('-', $cal_month);
                $cal_y = (int) $cal_y; $cal_m = (int) $cal_m;
                $first_day = mktime(0, 0, 0, $cal_m, 1, $cal_y);
                $days_in_month = (int) date('t', $first_day);
                $start_dow = (int) date('w', $first_day);
                $prev_month = date('Y-m', strtotime('-1 month', $first_day));
                $next_month = date('Y-m', strtotime('+1 month', $first_day));
                $month_label = date_i18n('F Y', $first_day);
                $from = sprintf('%04d-%02d-01', $cal_y, $cal_m);
                $to = sprintf('%04d-%02d-%02d', $cal_y, $cal_m, $days_in_month);
                $cal_bookings = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.id, b.booking_code, b.status, b.check_in, b.service_type, s.post_title AS service_title
                     FROM {$wpdb->prefix}tap_bookings b
                     LEFT JOIN {$wpdb->posts} s ON s.ID = b.service_id
                     WHERE b.check_in >= %s AND b.check_in <= %s
                     ORDER BY b.check_in ASC",
                    $from, $to
                ));
                $by_date = [];
                foreach ($cal_bookings as $cb) {
                    $by_date[$cb->check_in][] = $cb;
                }
            ?>
            <div class="tap-admin-calendar" style="margin-bottom:20px;">
                <div class="tap-avcal-header">
                    <div class="tap-avcal-nav">
                        <a href="<?php echo esc_url(add_query_arg('cal_month', $prev_month)); ?>" class="tap-avcal-nav-btn">&larr;</a>
                        <span class="tap-avcal-label"><?php echo esc_html($month_label); ?></span>
                        <a href="<?php echo esc_url(add_query_arg('cal_month', $next_month)); ?>" class="tap-avcal-nav-btn">&rarr;</a>
                    </div>
                </div>
                <div class="tap-avcal-grid">
                    <?php
                    $weekdays = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
                    foreach ($weekdays as $wd) echo '<div class="tap-avcal-weekday">' . esc_html($wd) . '</div>';
                    for ($p = 0; $p < $start_dow; $p++) echo '<div class="tap-avcal-day disabled"></div>';
                    for ($d = 1; $d <= $days_in_month; $d++) {
                        $date = sprintf('%04d-%02d-%02d', $cal_y, $cal_m, $d);
                        $day_bookings = isset($by_date[$date]) ? $by_date[$date] : [];
                        $cls = 'tap-avcal-day' . ($day_bookings ? ' available' : ' disabled');
                        echo '<div class="' . esc_attr($cls) . '">';
                        echo '<span class="tap-avcal-num">' . (int) $d . '</span>';
                        foreach ($day_bookings as $cb) {
                            echo '<div class="tap-avcal-slot"><a href="' . esc_url(admin_url('admin.php?page=tap-booking-detail&code=' . rawurlencode($cb->booking_code))) . '" style="font-size:10px;color:inherit;text-decoration:none;">' . esc_html($cb->booking_code) . '</a></div>';
                        }
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
            <?php else: ?>
            <form method="post" id="tap-bulk-form">
                <?php wp_nonce_field('tap_bulk_bookings_nonce'); ?>
                <div style="margin:8px 0;display:flex;gap:8px;align-items:center;">
                    <select name="tap_bulk_action">
                        <option value=""><?php esc_html_e('Acciones masivas', 'travel-agency-platform'); ?></option>
                        <option value="confirmed"><?php esc_html_e('Confirmar', 'travel-agency-platform'); ?></option>
                        <option value="completed"><?php esc_html_e('Completar', 'travel-agency-platform'); ?></option>
                        <option value="cancelled"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></option>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('Aplicar', 'travel-agency-platform'); ?></button>
                </div>
            <?php echo TAP_Dashboard::paginate($total, $per_page, $paged); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="tap-bulk-check-all"></th>
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
                    <?php
                    foreach ($bookings as $b):
                        $service = get_post($b->service_id);
                        $agency = get_post($b->agency_id);
						?>
                    <tr>
                        <td><input type="checkbox" name="booking_ids[]" value="<?php echo (int) $b->id; ?>"></td>
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
            <?php echo TAP_Dashboard::paginate($total, $per_page, $paged); ?>
            </form>
            <script>
            (function(){
                var all = document.getElementById('tap-bulk-check-all');
                if (all) {
                    all.addEventListener('change', function(){
                        document.querySelectorAll('input[name="booking_ids[]"]').forEach(function(cb){ cb.checked = all.checked; });
                    });
                }
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
