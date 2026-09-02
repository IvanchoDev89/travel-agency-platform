<?php
defined('ABSPATH') || exit;

class TAP_Dashboard {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 30);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_export_bookings'], 5);
        add_filter('manage_tap_accommodation_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_accommodation_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_tour_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_tour_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_transport_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_transport_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_car_rental_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_car_rental_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_boat_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_boat_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_package_posts_columns', [__CLASS__, 'add_agency_column']);
        add_action('manage_tap_package_posts_custom_column', [__CLASS__, 'render_agency_column'], 10, 2);
    }

    public static function add_admin_menu() {
        add_menu_page(
            __('Travel Platform', 'travel-agency-platform'),
            __('Travel Platform', 'travel-agency-platform'),
            'manage_options',
            'travel-platform',
            [__CLASS__, 'dashboard_page'],
            'dashicons-admin-multisite',
            25
        );

        add_submenu_page(
            'travel-platform',
            __('Bookings', 'travel-agency-platform'),
            __('Bookings', 'travel-agency-platform'),
            'tap_manage_bookings',
            'tap-bookings',
            [__CLASS__, 'bookings_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Commissions', 'travel-agency-platform'),
            __('Commissions', 'travel-agency-platform'),
            'tap_manage_commissions',
            'tap-commissions',
            [__CLASS__, 'commissions_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Reviews', 'travel-agency-platform'),
            __('Reviews', 'travel-agency-platform'),
            'tap_manage_reviews',
            'tap-reviews',
            [__CLASS__, 'reviews_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Agencies', 'travel-agency-platform'),
            __('Agencies', 'travel-agency-platform'),
            'tap_manage_agencies',
            'tap-agencies',
            [__CLASS__, 'agencies_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Reports', 'travel-agency-platform'),
            __('Reports', 'travel-agency-platform'),
            'tap_view_reports',
            'tap-reports',
            [__CLASS__, 'reports_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Plans', 'travel-agency-platform'),
            __('Plans', 'travel-agency-platform'),
            'manage_options',
            'tap-plans',
            [__CLASS__, 'plans_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Subscriptions', 'travel-agency-platform'),
            __('Subscriptions', 'travel-agency-platform'),
            'manage_options',
            'tap-subscriptions',
            [__CLASS__, 'subscriptions_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Settings', 'travel-agency-platform'),
            __('Settings', 'travel-agency-platform'),
            'tap_manage_settings',
            'tap-settings',
            [__CLASS__, 'settings_page']
        );
    }

    public static function register_settings() {
        register_setting('tap_settings', 'tap_commission_default');
        register_setting('tap_settings', 'tap_currency', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_key($value));
                return in_array($value, TAP_Currency::codes(), true) ? $value : 'USD';
            },
        ]);
        register_setting('tap_settings', 'tap_booking_auto_confirm');
        register_setting('tap_settings', 'tap_stale_booking_hours');
        register_setting('tap_settings', 'tap_payment_methods');
        register_setting('tap_settings', 'tap_terms_page');
        register_setting('tap_settings', 'tap_paypal_enabled');
        register_setting('tap_settings', 'tap_paypal_sandbox');
        register_setting('tap_settings', 'tap_paypal_client_id');
        register_setting('tap_settings', 'tap_paypal_secret');
        register_setting('tap_settings', 'tap_paypal_webhook_id');
        register_setting('tap_settings', 'tap_default_gateway');
        register_setting('tap_settings', 'tap_booking_fee_type', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['none', 'fixed', 'percent'], true) ? $value : 'none';
            },
        ]);
        register_setting('tap_settings', 'tap_booking_fee_value', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(0, floatval($value));
            },
        ]);
    }

    public static function dashboard_page() {
        $stats = TAP_Booking::get_booking_stats();
        $monthly = TAP_Booking::get_monthly_series(12);
        $status = TAP_Booking::get_status_distribution();
        $breakdown = TAP_Booking::get_service_type_breakdown();
        $recent = TAP_Booking::get_latest_bookings(8);

        global $wpdb;
        $total_agencies = wp_count_posts('tap_agency')->publish ?? 0;
        $total_services = 0;
        foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'] as $pt) {
            $counts = wp_count_posts($pt);
            $total_services += ($counts->publish ?? 0);
        }
        $currency = get_option('tap_currency', 'USD');

        $status_labels = ['pending' => __('Pending', 'travel-agency-platform'), 'confirmed' => __('Confirmed', 'travel-agency-platform'), 'completed' => __('Completed', 'travel-agency-platform'), 'cancelled' => __('Cancelled', 'travel-agency-platform'), 'refunded' => __('Refunded', 'travel-agency-platform')];

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
                    <span class="tap-dash-card-foot"><?php echo esc_html($status['pending']); ?> <?php esc_html_e('pendientes', 'travel-agency-platform'); ?></span>
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

    private static function paginate($total, $per_page, $paged, $page_slug) {
        $pages = (int) ceil($total / $per_page);
        if ($pages <= 1) return '';
        return '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . sprintf(esc_html__('%d items', 'travel-agency-platform'), (int) $total) . '</span>'
            . paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => max(1, (int) $paged),
                'total'     => $pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ])
            . '</div></div>';
    }

    public static function maybe_export_bookings() {
        if (empty($_GET['page']) || $_GET['page'] !== 'tap-bookings' || empty($_GET['export'])) {
            return;
        }
        if (!current_user_can('tap_manage_bookings')) {
            return;
        }
        global $wpdb;

        $f_status = sanitize_key($_GET['f_status'] ?? '');
        $f_type   = sanitize_key($_GET['f_type'] ?? '');
        $f_agency = intval($_GET['f_agency'] ?? 0);
        $s        = sanitize_text_field($_GET['s'] ?? '');
        $where    = '1=1';
        $params   = [];

        if (in_array($f_status, ['pending', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
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
        fputcsv($out, ['Código', 'Estado', 'Pago', 'Cliente', 'Email', 'Agencia', 'Servicio', 'Título', 'Entrada', 'Salida', 'Adultos', 'Niños', 'Noches', 'Total', 'Comisión %', 'Comisión', 'Estado comisión', 'Creado']);
        foreach ($rows as $b) {
            $agency = get_post($b->agency_id);
            $service = get_post($b->service_id);
            fputcsv($out, [
                $b->booking_code, $b->status, $b->payment_status, $b->display_name ?? '', $b->user_email ?? '',
                $agency ? $agency->post_title : '', $b->service_type, $service ? $service->post_title : '',
                $b->check_in, $b->check_out, $b->adults, $b->children, $b->nights,
                $b->total_amount, $b->commission_percent, $b->commission_amount, $b->commission_status, $b->created_at,
            ]);
        }
        fclose($out);
        exit;
    }

    public static function bookings_page() {
        global $wpdb;

        $f_status = sanitize_key($_GET['f_status'] ?? '');
        $f_type   = sanitize_key($_GET['f_type'] ?? '');
        $f_agency = intval($_GET['f_agency'] ?? 0);
        $s        = sanitize_text_field($_GET['s'] ?? '');
        $where    = '1=1';
        $params   = [];

        if (in_array($f_status, ['pending', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
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
        $export_url = add_query_arg(array_filter([
            'page' => 'tap-bookings', 'export' => 1,
            'f_status' => $f_status, 'f_type' => $f_type, 'f_agency' => $f_agency ? $f_agency : null, 's' => $s !== '' ? $s : null,
        ]), admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></h1>
            <form method="get" style="margin:8px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="page" value="tap-bookings">
                <input type="text" name="s" value="<?php echo esc_attr($s); ?>" placeholder="<?php esc_attr_e('Código, cliente o email', 'travel-agency-platform'); ?>">
                <select name="f_status">
                    <option value=""><?php esc_html_e('Todos los estados', 'travel-agency-platform'); ?></option>
                    <?php foreach (['pending', 'confirmed', 'completed', 'cancelled', 'refunded'] as $st): ?>
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

            <?php echo self::paginate($total, $per_page, $paged, 'tap-bookings'); ?>
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
                        <td><a href="<?php echo esc_url(get_edit_post_link($b->id)); ?>"><?php echo esc_html($b->booking_code); ?></a></td>
                        <td><?php echo $b->display_name ? esc_html($b->display_name) : 'N/A'; ?></td>
                        <td><?php echo $service ? esc_html($service->post_title) : esc_html($b->service_type); ?></td>
                        <td><?php echo $agency ? esc_html($agency->post_title) : 'N/A'; ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                        <td><?php echo esc_html(ucfirst($b->status)); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->commission_amount)) . ' <span style="color:#64748b;">(' . esc_html($b->commission_status) . ')</span>'; ?></td>
                        <td><?php echo esc_html((float) ($b->booking_fee ?? 0) > 0 ? TAP_Currency::fmt($b->booking_fee) : '&mdash;'); ?></td>
                        <td><?php echo esc_html($b->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo self::paginate($total, $per_page, $paged, 'tap-bookings'); ?>
        </div>
        <?php
    }

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
                    "SELECT id, agency_id, commission_amount FROM {$b_table} WHERE id IN ({$placeholders}) AND commission_status = 'owed'",
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

        $filter = sanitize_key($_GET['status'] ?? 'all');
        if (!in_array($filter, ['all', 'owed', 'paid'], true)) {
            $filter = 'all';
        }
        $where = 'b.commission_amount > 0';
        if ('owed' === $filter) {
            $where .= " AND b.commission_status = 'owed'";
        } elseif ('paid' === $filter) {
            $where .= " AND b.commission_status = 'paid'";
        }
        $commissions = $wpdb->get_results(
            "SELECT b.*, a.post_title as agency_name
             FROM {$b_table} b
             LEFT JOIN {$wpdb->posts} a ON b.agency_id = a.ID
             WHERE {$where}
             ORDER BY b.created_at DESC LIMIT 200"
        );
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, a.post_title agency_name
             FROM {$p_table} p
             LEFT JOIN {$wpdb->posts} a ON p.agency_id = a.ID
             ORDER BY p.created_at DESC LIMIT 50"
        ));
        $totals = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(CASE WHEN commission_status = 'owed' THEN commission_amount END), 0) owed,
                COALESCE(SUM(CASE WHEN commission_status = 'paid' THEN commission_amount END), 0) paid
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
                <a href="admin.php?page=tap-commissions&status=paid" class="nav-tab <?php echo 'paid' === $filter ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Pagadas', 'travel-agency-platform'); ?> (<?php echo esc_html(TAP_Currency::fmt($totals->paid)); ?>)</a>
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
                                <input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr($c->id); ?>" <?php disabled('paid', $c->commission_status); ?>>
                            </th>
                            <td><?php echo esc_html($c->booking_code); ?></td>
                            <td><?php echo esc_html($c->agency_name ?? 'N/A'); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->total_amount)); ?></td>
                            <td><?php echo esc_html($c->commission_percent); ?>%</td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->commission_amount)); ?></td>
                            <td><?php echo 'paid' === $c->commission_status ? '<span style="color:#047857;font-weight:600;">' . esc_html__('Cobrada', 'travel-agency-platform') . '</span>' : '<span style="color:#b45309;font-weight:600;">' . esc_html__('Por cobrar', 'travel-agency-platform') . '</span>'; ?></td>
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
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Agency', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="7"><?php esc_html_e('Aún no se han registrado liquidaciones.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>#<?php echo (int) $p->id; ?></td>
                        <td><?php echo esc_html($p->agency_name ?? 'N/A'); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($p->amount)); ?></td>
                        <td><?php echo esc_html($p->method); ?></td>
                        <td><?php echo esc_html($p->booking_ids); ?></td>
                        <td><?php echo esc_html($p->note); ?></td>
                        <td><?php echo esc_html($p->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        (function(d){
            var a = d.getElementById('tap-check-all');
            if (a) a.addEventListener('change', function(){
                d.querySelectorAll('input[name="booking_ids[]"]').forEach(function(c){ c.checked = a.checked; });
            });
        })(document);
        </script>
        <?php
    }

    public static function reviews_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_reviews';

        if (isset($_POST['tap_review_reply_nonce']) && isset($_POST['review_id'])) {
            $rid = intval($_POST['review_id']);
            if ($rid && wp_verify_nonce($_POST['tap_review_reply_nonce'], 'tap_review_reply_' . $rid)) {
                $reply = sanitize_textarea_field(wp_unslash($_POST['reply'] ?? ''));
                $wpdb->update($table, [
                    'reply'        => $reply,
                    'reply_author' => wp_get_current_user()->display_name,
                    'reply_at'     => current_time('mysql'),
                ], ['id' => $rid]);
            }
        }

        if (isset($_GET['tap_action'], $_GET['review_id']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'tap_review_' . $_GET['tap_action'] . '_' . intval($_GET['review_id']))) {
                $rid = intval($_GET['review_id']);
                if ('approve' === $_GET['tap_action']) {
                    $wpdb->update($table, ['is_approved' => 1], ['id' => $rid]);
                } elseif ('unapprove' === $_GET['tap_action']) {
                    $wpdb->update($table, ['is_approved' => 0], ['id' => $rid]);
                } elseif ('delete' === $_GET['tap_action']) {
                    $wpdb->delete($table, ['id' => $rid]);
                }
                wp_safe_redirect(admin_url('admin.php?page=tap-reviews&status=' . urlencode($_GET['status'] ?? 'all')));
                exit;
            }
        }

        $filter = sanitize_key($_GET['status'] ?? 'all');
        $where  = 'all' === $filter ? '1=1' : 'r.is_approved = ' . ($filter === 'pending' ? '0' : '1');

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_approved = 0");
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name AS user_name
            FROM $table r
            JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT 100",
            []
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reviews', 'travel-agency-platform'); ?></h1>
            <div class="notice notice-info inline" style="margin:10px 0;">
                <p><?php printf(esc_html__('Reseñas pendientes de aprobación: %d', 'travel-agency-platform'), $pending); ?> —
                <?php esc_html_e('Solo las aprobadas se muestran en el sitio público.', 'travel-agency-platform'); ?></p>
            </div>
            <nav class="nav-tab-wrapper">
                <?php foreach (['all' => __('Todas', 'travel-agency-platform'), 'pending' => __('Pendientes', 'travel-agency-platform'), 'approved' => __('Aprobadas', 'travel-agency-platform')] as $k => $label):
                    $class = $filter === $k ? ' nav-tab-active' : ''; ?>
                    <a class="nav-tab<?php echo $class; ?>" href="<?php echo esc_url(admin_url('admin.php?page=tap-reviews&status=' . $k)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:110px;"><?php esc_html_e('Rating', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reseña', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Usuario', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$reviews): ?>
                    <tr><td colspan="6"><em><?php esc_html_e('No hay reseñas.', 'travel-agency-platform'); ?></em></td></tr>
                    <?php endif; ?>
                    <?php foreach ($reviews as $r):
                        $service = get_post($r->service_id);
                    ?>
                    <tr>
                        <td><span class="tap-admin-stars" style="color:#f59e0b;"><?php echo str_repeat('★', intval($r->rating)); ?></span> <span class="description"><?php echo esc_html($r->rating); ?></span></td>
                        <td>
                            <?php if ($r->title): ?><strong><?php echo esc_html($r->title); ?></strong><br><?php endif; ?>
                            <?php echo esc_html($r->content); ?>
                        </td>
                        <td><?php echo $service ? esc_html($service->post_title) : esc_html($r->service_type . ' #' . $r->service_id); ?></td>
                        <td><?php echo esc_html($r->user_name); ?> <span class="description">#<?php echo (int) $r->user_id; ?></span></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td>
                            <?php if (!$r->is_approved): ?>
                                <a class="button button-primary button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=approve&review_id=' . $r->id . '&status=' . $filter), 'tap_review_approve_' . $r->id)); ?>"><?php esc_html_e('Aprobar', 'travel-agency-platform'); ?></a>
                            <?php else: ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=unapprove&review_id=' . $r->id . '&status=' . $filter), 'tap_review_unapprove_' . $r->id)); ?>"><?php esc_html_e('Desaprobar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <a class="button button-link-delete button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=delete&review_id=' . $r->id . '&status=' . $filter), 'tap_review_delete_' . $r->id)); ?>" onclick="return confirm('Delete this review?');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;"></td>
                        <td colspan="5" style="background:#f9fafb;">
                            <details>
                                <summary style="cursor:pointer;font-weight:600;color:#0a84ff;">
                                    <?php $r->reply ? esc_html_e('Ver respuesta de la agencia', 'travel-agency-platform') : esc_html_e('Responder a esta reseña', 'travel-agency-platform'); ?>
                                </summary>
                                <?php if ($r->reply): ?>
                                    <blockquote style="margin:10px 0;padding:10px 14px;border-left:4px solid #0a84ff;background:#fff;">
                                        <?php echo nl2br(esc_html($r->reply)); ?>
                                        <div class="description" style="margin-top:6px;color:#64748b;">
                                            <?php echo esc_html((string) $r->reply_author) . ' · ' . esc_html((string) $r->reply_at); ?>
                                        </div>
                                    </blockquote>
                                <?php endif; ?>
                                <form method="post" style="margin-top:8px;">
                                    <input type="hidden" name="review_id" value="<?php echo (int) $r->id; ?>">
                                    <?php wp_nonce_field('tap_review_reply_' . $r->id, 'tap_review_reply_nonce'); ?>
                                    <textarea name="reply" rows="3" placeholder="<?php esc_attr_e('Escribe tu respuesta como agencia…', 'travel-agency-platform'); ?>" class="large-text"><?php echo esc_textarea($r->reply ?? ''); ?></textarea>
                                    <button class="button button-primary"><?php esc_html_e('Guardar respuesta', 'travel-agency-platform'); ?></button>
                                </form>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function agencies_page() {
        global $wpdb;
        self::sync_agency_registry();

        if (isset($_GET['tap_action'], $_GET['agency_id'], $_GET['_wpnonce'])) {
            $aid   = intval($_GET['agency_id']);
            $nonce = sanitize_key($_GET['tap_action']) . '_' . $aid;
            if ($aid && wp_verify_nonce($_GET['_wpnonce'], $nonce)) {
                self::handle_agency_action($_GET['tap_action'], $aid, $_GET);
                wp_safe_redirect(admin_url('admin.php?page=tap-agencies'));
                exit;
            }
        }

        $agencies = get_posts([
            'post_type'  => 'tap_agency',
            'post_status' => ['publish', 'trash'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $visible = array_values(array_filter($agencies, function ($a) { return 'trash' !== $a->post_status; }));

        $verified = 0;
        $active   = 0;
        foreach ($visible as $a) {
            if ('1' === get_post_meta($a->ID, '_tap_agency_verified', true)) $verified++;
            if ('0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1')) $active++;
        }
        $rows_rev = $wpdb->get_results("SELECT agency_id, COUNT(*) AS c, SUM(total_amount) AS rev, SUM(commission_amount) AS comm FROM {$wpdb->prefix}tap_bookings WHERE status NOT IN ('cancelled','refunded') GROUP BY agency_id");
        $revenue_map = [];
        foreach ($rows_rev as $r) { $revenue_map[(int) $r->agency_id] = $r; }
        $listing_count = $wpdb->get_results(
            "SELECT meta_value, COUNT(*) AS c FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_tap_acc_agency_id','_tap_tour_agency_id','_tap_trans_agency_id')
            GROUP BY meta_value"
        );
        $listings_map = [];
        foreach ($listing_count as $l) { $listings_map[(int) $l->meta_value] = (int) $l->c; }
        $total_commission = $wpdb->get_var("SELECT SUM(commission_amount) FROM {$wpdb->prefix}tap_bookings WHERE status NOT IN ('cancelled','refunded')");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Agencies', 'travel-agency-platform'); ?></h1>
            <div class="notice notice-info inline" style="margin:10px 0;">
                <p><?php
                    printf(esc_html__('Total: %1$d · Verificadas: %2$d · Activas: %3$d · Comisiones acumuladas: %4$s', 'travel-agency-platform'),
                        count($visible), $verified, $active, esc_html(TAP_Currency::fmt((float) $total_commission)));
                    ?>
                    <span style="opacity:.7"> — <?php esc_html_e('El registro se sincroniza automáticamente al abrir esta página.', 'travel-agency-platform'); ?></span>
                </p>
            </div>
            <?php
            $paged    = max(1, intval($_GET['paged'] ?? 1));
            $per_page = 20;
            $page_set = array_slice($visible, ($paged - 1) * $per_page, $per_page);
            echo self::paginate(count($visible), $per_page, $paged, 'tap-agencies');
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Listados', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reservas', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($page_set as $a):
                        if ('trash' === $a->post_status) continue;
                        $is_verified = '1' === get_post_meta($a->ID, '_tap_agency_verified', true);
                        $is_active = '0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1');
                        $commission = get_post_meta($a->ID, '_tap_agency_commission', true);
                        $commission = '' === (string) $commission ? '10' : $commission;
                        $uid   = get_post_meta($a->ID, '_tap_agency_user_id', true);
                        $u     = $uid ? get_userdata($uid) : null;
                        $email = get_post_meta($a->ID, '_tap_agency_email', true);
                        if (!$email && $u) $email = $u->user_email;
                        $rev      = $revenue_map[$a->ID] ?? null;
                        $listings = $listings_map[$a->ID] ?? 0;
                        $verify_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=' . ($is_verified ? 'unverify' : 'verify') . '&agency_id=' . $a->ID), ($is_verified ? 'unverify' : 'verify') . '_' . $a->ID);
                        $active_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=' . ($is_active ? 'deactivate' : 'activate') . '&agency_id=' . $a->ID), ($is_active ? 'deactivate' : 'activate') . '_' . $a->ID);
                        $delete_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=delete&agency_id=' . $a->ID), 'delete_' . $a->ID);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($a->post_title); ?></strong>
                            <?php if ($is_verified): ?><span class="dashicons dashicons-yes" style="color:#16a34a;" title="<?php esc_attr_e('Verificada', 'travel-agency-platform'); ?>"></span><?php endif; ?>
                            <div class="description"><?php echo esc_html($a->post_excerpt ?: ('#' . $a->ID)); ?></div>
                        </td>
                        <td>
                            <?php if ($u): echo esc_html($u->display_name) . ' <span class="description">@' . esc_html($u->user_login) . '</span><br>'; endif; ?>
                            <?php echo esc_html($email); ?>
                        </td>
                        <td><?php echo (int) $listings; ?></td>
                        <td>
                            <?php if ($rev): ?>
                                <?php echo (int) $rev->c; ?> · <strong><?php echo esc_html(TAP_Currency::fmt((float) $rev->rev)); ?></strong><br>
                                <span class="description"><?php esc_html_e('comisión', 'travel-agency-platform'); ?> <?php echo esc_html(TAP_Currency::fmt((float) $rev->comm)); ?></span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td>
                            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline-flex;gap:4px;align-items:center;">
                                <input type="hidden" name="page" value="tap-agencies">
                                <input type="hidden" name="tap_action" value="commission">
                                <input type="hidden" name="agency_id" value="<?php echo (int) $a->ID; ?>">
                                <?php wp_nonce_field('commission_' . $a->ID); ?>
                                <input type="number" name="commission" value="<?php echo esc_attr($commission); ?>" min="0" max="100" step="0.01" style="width:70px;">
                                <button class="button button-small">%</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($is_verified): ?>
                                <span class="dashicons dashicons-shield" style="color:#0d9488;vertical-align:middle;"></span> <?php esc_html_e('Verificada', 'travel-agency-platform'); ?>
                            <?php else: ?><span style="color:#dc2626;"><?php esc_html_e('Pendiente', 'travel-agency-platform'); ?></span><?php endif; ?>
                            <br>
                            <?php if ($is_active): ?><span style="color:#16a34a;"><?php esc_html_e('Activa', 'travel-agency-platform'); ?></span><?php else: ?><span style="color:#64748b;"><?php esc_html_e('Desactivada', 'travel-agency-platform'); ?></span><?php endif; ?>
                        </td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($verify_url); ?>"><?php echo $is_verified ? esc_html__('Quitar verificación', 'travel-agency-platform') : esc_html__('Verificar', 'travel-agency-platform'); ?></a>
                            <a class="button button-small" href="<?php echo esc_url($active_url); ?>"><?php echo $is_active ? esc_html__('Desactivar', 'travel-agency-platform') : esc_html__('Activar', 'travel-agency-platform'); ?></a>
                            <a class="button button-link-delete button-small" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Borrar esta agencia y desvincular sus listados?');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo self::paginate(count($visible), $per_page, $paged, 'tap-agencies'); ?>
        </div>
        <?php
    }

    private static function handle_agency_action($action, $agency_id, $params) {
        $wpdb = $GLOBALS['wpdb'];
        switch ($action) {
            case 'verify':
                update_post_meta($agency_id, '_tap_agency_verified', '1');
                break;
            case 'unverify':
                update_post_meta($agency_id, '_tap_agency_verified', '');
                break;
            case 'activate':
                update_post_meta($agency_id, '_tap_agency_is_active', '1');
                break;
            case 'deactivate':
                update_post_meta($agency_id, '_tap_agency_is_active', '0');
                break;
            case 'commission':
                $c = isset($params['commission']) ? floatval($params['commission']) : 0;
                update_post_meta($agency_id, '_tap_agency_commission', $c);
                break;
            case 'delete':
                $listing_ids = get_posts(['post_type' => ['tap_accommodation', 'tap_tour', 'tap_transport'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['relation' => 'OR', ['key' => '_tap_acc_agency_id', 'value' => $agency_id], ['key' => '_tap_tour_agency_id', 'value' => $agency_id], ['key' => '_tap_trans_agency_id', 'value' => $agency_id]]]]);
                foreach ($listing_ids as $lid) {
                    foreach (['_tap_acc_agency_id', '_tap_tour_agency_id', '_tap_trans_agency_id'] as $mk) {
                        if ((string) get_post_meta($lid, $mk, true) === (string) $agency_id) {
                            delete_post_meta($lid, $mk);
                        }
                    }
                }
                $uid = get_post_meta($agency_id, '_tap_agency_user_id', true);
                if ($uid) {
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}tap_agencies SET is_active = 0 WHERE user_id = %d", $uid));
                }
                wp_trash_post($agency_id);
                break;
        }
        self::sync_agency_registry();
    }

    private static function sync_agency_registry() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_agencies';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) return;

        $agencies = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($agencies as $a) {
            $uid     = (int) get_post_meta($a->ID, '_tap_agency_user_id', true);
            $email   = get_post_meta($a->ID, '_tap_agency_email', true);
            $phone   = get_post_meta($a->ID, '_tap_agency_phone', true);
            $whats   = get_post_meta($a->ID, '_tap_agency_whatsapp', true);
            $web     = get_post_meta($a->ID, '_tap_agency_website', true);
            $addr    = get_post_meta($a->ID, '_tap_agency_address', true);
            $city    = get_post_meta($a->ID, '_tap_agency_city', true);
            $country = get_post_meta($a->ID, '_tap_agency_country', true);
            $comm    = get_post_meta($a->ID, '_tap_agency_commission', true);
            $comm    = '' === (string) $comm ? 10 : (float) $comm;
            $isv     = '1' === get_post_meta($a->ID, '_tap_agency_verified', true) ? 1 : 0;
            $isa     = '0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1') ? 1 : 0;

            if (!$uid) {
                $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE slug = %s OR name = %s", $a->post_name, $a->post_title));
            }
            if (!$uid) continue;

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d", $uid));
            $data = [
                'name' => $a->post_title,
                'slug' => $a->post_name,
                'description' => $a->post_excerpt ?: '',
                'email' => $email,
                'phone' => $phone,
                'whatsapp' => $whats,
                'website' => $web,
                'address' => $addr,
                'city' => $city,
                'country' => $country,
                'commission_percent' => $comm,
                'is_verified' => $isv,
                'is_active' => $isa,
                'user_id' => $uid,
            ];
            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }

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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reports', 'travel-agency-platform'); ?></h1>
            <div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($totals->gmv ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('GMV', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($totals->commission ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisiones (plataforma)', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($totals->fees ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Booking fees (plataforma)', 'travel-agency-platform'); ?></span></div>
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
        </div>
        <?php
    }

    public static function plans_page() {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_plans';

        if (isset($_POST['tap_save_plans']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_save_plans')) {
            foreach ((array) ($_POST['tap_plans'] ?? []) as $id => $row) {
                $id = (int) $id;
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
                if (!$existing) {
                    continue;
                }
                $wpdb->update(
                    $t,
                    [
                        'name'           => sanitize_text_field($row['name'] ?? $existing->name),
                        'price_monthly'  => max(0, floatval($row['price_monthly'] ?? $existing->price_monthly)),
                        'commission_rate'=> (isset($row['commission_rate']) && $row['commission_rate'] !== '') ? max(0, floatval($row['commission_rate'])) : null,
                        'listing_limit'  => (int) ($row['listing_limit'] ?? $existing->listing_limit),
                        'featured_slots' => max(0, (int) ($row['featured_slots'] ?? $existing->featured_slots)),
                        'features'       => sanitize_textarea_field($row['features'] ?? ''),
                        'is_active'      => !empty($row['is_active']) ? 1 : 0,
                    ],
                    ['id' => $id]
                );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plans saved.', 'travel-agency-platform') . '</p></div>';
        }

        echo '<div class="wrap"><h1>' . esc_html__('Subscription Plans', 'travel-agency-platform') . '</h1>';
        echo '<p class="description">' . esc_html__('Commission rate (%) is used while the plan is active; leave blank to keep the agency/global commission. Listing limit -1 = unlimited.', 'travel-agency-platform') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('tap_save_plans');
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Plan', 'Precio/mes', 'Comisión %', 'Límite listados', 'Destacados', 'Features', 'Activo'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($wpdb->get_results("SELECT * FROM $t ORDER BY price_monthly ASC, id ASC") as $p) {
            $features_txt = implode("\n", (array) json_decode($p->features ?: '', true)) ?: $p->features;
            echo '<tr>';
            echo '<td><input type="text" name="tap_plans[' . (int) $p->id . '][name]" value="' . esc_attr($p->name) . '" class="regular-text"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][price_monthly]" value="' . esc_attr($p->price_monthly) . '" min="0" step="0.01" style="width:90px;"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][commission_rate]" value="' . esc_attr($p->commission_rate === null ? '' : $p->commission_rate) . '" min="0" step="0.01" style="width:70px;" placeholder="estándar"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][listing_limit]" value="' . esc_attr($p->listing_limit) . '" style="width:80px;"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][featured_slots]" value="' . esc_attr($p->featured_slots) . '" min="0" style="width:70px;"></td>';
            echo '<td><textarea name="tap_plans[' . (int) $p->id . '][features]" rows="3" cols="30" placeholder="una por línea">' . esc_textarea($features_txt) . '</textarea></td>';
            echo '<td><input type="checkbox" name="tap_plans[' . (int) $p->id . '][is_active]" value="1" ' . checked(1, $p->is_active, false) . '></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Save plans', 'travel-agency-platform'), 'primary', 'tap_save_plans');
        echo '</form></div>';
    }

    public static function subscriptions_page() {
        global $wpdb;
        $t_sub  = $wpdb->prefix . 'tap_agency_subscriptions';
        $t_plan = $wpdb->prefix . 'tap_plans';
        $t_age  = $wpdb->prefix . 'tap_agencies';

        if (isset($_POST['tap_mark_paid']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_mark_subscription_paid')) {
            $sub_id = (int) ($_POST['sub_id'] ?? 0);
            $months = (int) ($_POST['months'] ?? 1);
            $result = TAP_Subscriptions::mark_paid($sub_id, get_current_user_id(), $months);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Subscription marked as paid until ' . $result, 'travel-agency-platform') . '</p></div>';
            }
        }

        if (isset($_POST['tap_expire_sub']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_expire_subscription')) {
            $wpdb->update($t_sub, ['status' => 'expired'], ['id' => (int) ($_POST['sub_id'] ?? 0)]);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Subscription expired.', 'travel-agency-platform') . '</p></div>';
        }

        $subs = $wpdb->get_results(
            "SELECT s.*, a.name AS agency_name, p.name AS plan_name, p.price_monthly
             FROM $t_sub s
             LEFT JOIN $t_age a ON a.id = s.agency_id
             LEFT JOIN $t_plan p ON p.id = s.plan_id
             ORDER BY s.created_at DESC LIMIT 200"
        );
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_sub WHERE status = 'pending'");
        $active  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_sub WHERE status = 'active'");

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agency Subscriptions', 'travel-agency-platform') . '</h1>';
        echo '<div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html($active) . '</span><span class="tap-stat-label">' . esc_html__('Activas', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;">' . esc_html($pending) . '</span><span class="tap-stat-label">' . esc_html__('Pendientes de pago', 'travel-agency-platform') . '</span></div>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Agencia', 'Plan', 'Precio', 'Estado', 'Pagado hasta', 'Pago', 'Creado', 'Acciones'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$subs) {
            echo '<tr><td colspan="8">' . esc_html__('No subscriptions yet.', 'travel-agency-platform') . '</td></tr>';
        }
        foreach ($subs as $s) {
            $status_color = 'active' === $s->status ? '#047857' : ('pending' === $s->status ? '#b45309' : '#94a3b8');
            echo '<tr>';
            echo '<td>' . esc_html($s->agency_name ?: ('#' . $s->agency_id)) . '</td>';
            echo '<td>' . esc_html($s->plan_name ?: '—') . '</td>';
            echo '<td>' . esc_html(TAP_Currency::fmt($s->price_monthly)) . '/mes</td>';
            echo '<td style="color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($s->status) . '</td>';
            echo '<td>' . esc_html($s->paid_until ?: '—') . '</td>';
            echo '<td>' . esc_html($s->payment_method . ' · ' . $s->payment_status) . '</td>';
            echo '<td>' . esc_html($s->created_at) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:8px;">';
            wp_nonce_field('tap_mark_subscription_paid');
            echo '<input type="hidden" name="sub_id" value="' . (int) $s->id . '">';
            echo '<input type="number" name="months" value="1" min="1" max="24" style="width:60px;">';
            submit_button(__('Marcar pagado', 'travel-agency-platform'), 'small', 'tap_mark_paid', false);
            echo '</form>';
            if ('expired' !== $s->status) {
                echo '<form method="post" style="display:inline-block;">';
                wp_nonce_field('tap_expire_subscription');
                echo '<input type="hidden" name="sub_id" value="' . (int) $s->id . '">';
                submit_button(__('Expirar', 'travel-agency-platform'), 'small', 'tap_expire_sub', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Travel Platform Settings', 'travel-agency-platform'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('tap_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_commission_default"><?php esc_html_e('Default Commission %', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_commission_default" name="tap_commission_default" value="<?php echo esc_attr(get_option('tap_commission_default', '10')); ?>" step="0.01" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_currency"><?php esc_html_e('Default Currency', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_currency" name="tap_currency">
                                <?php foreach (TAP_Currency::codes() as $tap_code): ?>
                                <option value="<?php echo esc_attr($tap_code); ?>" <?php selected(TAP_Currency::code(), $tap_code); ?>>
                                    <?php echo esc_html($tap_code . ' (' . TAP_Currency::symbol($tap_code) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_booking_auto_confirm"><?php esc_html_e('Auto-confirm Bookings', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_booking_auto_confirm" name="tap_booking_auto_confirm" value="1" <?php checked('1', get_option('tap_booking_auto_confirm', '0')); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="tap_stale_booking_hours"><?php esc_html_e('Cancelar pendientes tras (horas)', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_stale_booking_hours" name="tap_stale_booking_hours" value="<?php echo esc_attr(get_option('tap_stale_booking_hours', '24')); ?>" min="1" step="1" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_terms_page"><?php esc_html_e('Terms & Conditions Page', 'travel-agency-platform'); ?></label></th>
                        <td><?php wp_dropdown_pages(['name' => 'tap_terms_page', 'selected' => get_option('tap_terms_page', ''), 'show_option_none' => __('-- Select --', 'travel-agency-platform')]); ?></td>
                    </tr>
                    <tr>
                        <th><label for="tap_default_gateway"><?php esc_html_e('Default Payment Gateway', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_default_gateway" name="tap_default_gateway">
                                <option value="paypal" <?php selected('paypal', get_option('tap_default_gateway', 'paypal')); ?>><?php esc_html_e('PayPal', 'travel-agency-platform'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('Booking Fee (client)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_booking_fee_type"><?php esc_html_e('Fee Type', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_booking_fee_type" name="tap_booking_fee_type">
                                <option value="none" <?php selected('none', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('No fee', 'travel-agency-platform'); ?></option>
                                <option value="fixed" <?php selected('fixed', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('Fixed amount', 'travel-agency-platform'); ?></option>
                                <option value="percent" <?php selected('percent', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('Percentage of total', 'travel-agency-platform'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Charged to the client on top of the booking total. This revenue belongs to the platform (not the agency).', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_booking_fee_value"><?php esc_html_e('Fee Value', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_booking_fee_value" name="tap_booking_fee_value" min="0" step="0.01" value="<?php echo esc_attr(get_option('tap_booking_fee_value', '0')); ?>" class="regular-text" style="width: 140px;"></td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('PayPal Settings', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_paypal_enabled"><?php esc_html_e('Enable PayPal', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_paypal_enabled" name="tap_paypal_enabled" value="1" <?php checked('1', get_option('tap_paypal_enabled', '0')); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_sandbox"><?php esc_html_e('Sandbox Mode', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_paypal_sandbox" name="tap_paypal_sandbox" value="1" <?php checked('1', get_option('tap_paypal_sandbox', '1')); ?>> <span class="description"><?php esc_html_e('Enable to test payments in PayPal Sandbox', 'travel-agency-platform'); ?></span></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_client_id"><?php esc_html_e('Client ID', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="tap_paypal_client_id" name="tap_paypal_client_id" value="<?php echo esc_attr(get_option('tap_paypal_client_id', '')); ?>" class="regular-text" style="width: 400px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_secret"><?php esc_html_e('Secret Key', 'travel-agency-platform'); ?></label></th>
                        <td><input type="password" id="tap_paypal_secret" name="tap_paypal_secret" value="<?php echo esc_attr(get_option('tap_paypal_secret', '')); ?>" class="regular-text" style="width: 400px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_webhook_id"><?php esc_html_e('Webhook ID', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="tap_paypal_webhook_id" name="tap_paypal_webhook_id" value="<?php echo esc_attr(get_option('tap_paypal_webhook_id', '')); ?>" class="regular-text" style="width: 400px;">
                        <p class="description"><?php esc_html_e('Webhook URL:', 'travel-agency-platform'); ?> <code><?php echo esc_url(home_url('/wp-json/tap/v1/paypal-webhook')); ?></code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function add_agency_column($columns) {
        $columns['agency'] = __('Agency', 'travel-agency-platform');
        return $columns;
    }

    public static function render_agency_column($column, $post_id) {
        if ($column !== 'agency') return;
        $post_type = get_post_type($post_id);
        $prefix = '_tap_' . str_replace('tap_', '', $post_type) . '_agency_id';
        $agency_id = get_post_meta($post_id, $prefix, true);
        if ($agency_id) {
            $agency = get_post($agency_id);
            echo $agency ? esc_html($agency->post_title) : 'N/A';
        } else {
            echo '—';
        }
    }
}
