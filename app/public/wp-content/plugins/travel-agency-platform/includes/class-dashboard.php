<?php
defined('ABSPATH') || exit;

class TAP_Dashboard {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 30);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [TAP_Dashboard_Bookings::class, 'maybe_export_bookings'], 5);
        add_filter('manage_tap_accommodation_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_accommodation_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_tour_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_tour_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_transport_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_transport_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_car_rental_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_car_rental_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_boat_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_boat_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_package_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_package_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
        add_filter('manage_tap_equipment_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_equipment_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);
    }

    public static function add_admin_menu() {
        add_menu_page(
            __('Travel Platform', 'travel-agency-platform'),
            __('Travel Platform', 'travel-agency-platform'),
            'manage_options',
            'travel-platform',
            [TAP_Dashboard_Bookings::class, 'dashboard_page'],
            'dashicons-admin-multisite',
            25
        );

        add_submenu_page(
            'travel-platform',
            __('Bookings', 'travel-agency-platform'),
            __('Bookings', 'travel-agency-platform'),
            'tap_manage_bookings',
            'tap-bookings',
            [TAP_Dashboard_Bookings::class, 'bookings_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Commissions', 'travel-agency-platform'),
            __('Commissions', 'travel-agency-platform'),
            'tap_manage_commissions',
            'tap-commissions',
            [TAP_Dashboard_Money::class, 'commissions_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Reviews', 'travel-agency-platform'),
            __('Reviews', 'travel-agency-platform'),
            'tap_manage_reviews',
            'tap-reviews',
            [TAP_Dashboard_Content::class, 'reviews_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Moderation', 'travel-agency-platform'),
            __('Moderation', 'travel-agency-platform'),
            'manage_options',
            'tap-moderation',
            [TAP_Dashboard_Content::class, 'moderation_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Disputes', 'travel-agency-platform'),
            __('Disputes', 'travel-agency-platform'),
            'tap_manage_disputes',
            'tap-disputes',
            [TAP_Dashboard_Money::class, 'disputes_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Agencies', 'travel-agency-platform'),
            __('Agencies', 'travel-agency-platform'),
            'tap_manage_agencies',
            'tap-agencies',
            [TAP_Dashboard_Content::class, 'agencies_page']
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
            __('Analytics', 'travel-agency-platform'),
            __('Analytics', 'travel-agency-platform'),
            'tap_view_reports',
            'tap-analytics',
            [__CLASS__, 'analytics_page']
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
            __('Promotions', 'travel-agency-platform'),
            __('Promotions', 'travel-agency-platform'),
            'manage_options',
            'tap-promotions',
            [__CLASS__, 'promotions_page']
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
        register_setting('tap_settings', 'tap_auto_payment_reminders');
        register_setting('tap_settings', 'tap_payment_reminder_hours', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(1, (int) $value);
            },
        ]);
        register_setting('tap_settings', 'tap_auto_prearrival');
        register_setting('tap_settings', 'tap_auto_review_request');
        register_setting('tap_settings', 'tap_auto_expiry_warnings');
        register_setting('tap_settings', 'tap_expiry_warn_days', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(1, (int) $value);
            },
        ]);
        register_setting('tap_settings', 'tap_auto_lead_ack');

        foreach (TAP_Booking::service_type_slugs() as $slug) {
            register_setting('tap_settings', 'tap_cancel_policy_' . $slug, [
                'type' => 'string',
                'sanitize_callback' => function ($value) {
                    return in_array($value, array_keys(TAP_Booking::cancellation_policies()), true) ? $value : 'flexible';
                },
            ]);
        }
        register_setting('tap_settings', 'tap_booking_auto_complete');
    }

    /**
     * Cached published-listing counts by post type (10 min transient) so the
     * dashboard does not run up to 13 wp_count_posts queries on every view.
     */
    public static function listing_counts() {
        $cached = get_transient('tap_listing_counts');
        if (is_array($cached)) {
            return $cached;
        }
        $types  = ['tap_agency', 'tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $result = [];
        $total  = 0;
        foreach ($types as $pt) {
            $result[$pt] = (int) wp_count_posts($pt)->publish;
            if ('tap_agency' !== $pt) {
                $total += $result[$pt];
            }
        }
        $result['total_services'] = $total;
        set_transient('tap_listing_counts', $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }

    public static function paginate($total, $per_page, $paged, $page_slug) {
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
            self::analytics_csv_export(sanitize_key($_GET['export']));
            exit;
        }

        $from = sanitize_text_field($_GET['tap_from'] ?? '');
        $to   = sanitize_text_field($_GET['tap_to'] ?? '');
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
        $from = sanitize_text_field($_GET['tap_from'] ?? '');
        $to   = sanitize_text_field($_GET['tap_to'] ?? '');
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
    public static function csv_cell($value) {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $line = (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+\-@\t\r]/', $line)) {
            $line = "'" . $line;
        }
        return $line;
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

    public static function promotions_page() {
        global $wpdb;
        $t_promo = $wpdb->prefix . 'tap_promos';
        $t_age   = $wpdb->prefix . 'tap_agencies';

        if (isset($_POST['tap_promo_activate']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_promo_activate_nonce')) {
            $months = min(24, max(1, (int) ($_POST['months'] ?? 1)));
            $result = class_exists('TAP_Promotions') ? TAP_Promotions::activate((int) ($_POST['promo_id'] ?? 0), get_current_user_id(), $months) : null;
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Promotion activated until ' . $result, 'travel-agency-platform') . '</p></div>';
            }
        }

        if (isset($_POST['tap_promo_expire']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tap_promo_expire_nonce')) {
            if (class_exists('TAP_Promotions')) {
                TAP_Promotions::expire((int) ($_POST['promo_id'] ?? 0));
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Promotion expired.', 'travel-agency-platform') . '</p></div>';
        }

        $promos = $wpdb->get_results(
            "SELECT p.*, a.name AS agency_name
             FROM $t_promo p
             LEFT JOIN $t_age a ON a.id = p.agency_id
             ORDER BY p.created_at DESC LIMIT 200"
        );
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_promo WHERE status = 'pending'");
        $active  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_promo WHERE status = 'active'");
        $confirmed_total = (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM $t_promo WHERE status = 'active'");
        $price = class_exists('TAP_Promotions') ? TAP_Promotions::get_price() : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Listado Promotions (Destacados)', 'travel-agency-platform') . '</h1>';
        echo '<p class="description">' . esc_html(sprintf(__('Precio por destacado: %s/mes por listado. Confirma el pago para activar la promoción hasta la fecha correspondiente.', 'travel-agency-platform'), TAP_Currency::fmt($price))) . '</p>';
        echo '<div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;">' . esc_html($active) . '</span><span class="tap-stat-label">' . esc_html__('Activos', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;">' . esc_html($pending) . '</span><span class="tap-stat-label">' . esc_html__('Pendientes de pago', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($confirmed_total)) . '</span><span class="tap-stat-label">' . esc_html__('Facturado en destacados', 'travel-agency-platform') . '</span></div>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Agencia', 'Listado', 'Meses', 'Monto', 'Estado', 'Hasta', 'Solicitado', 'Acciones'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$promos) {
            echo '<tr><td colspan="8">' . esc_html__('No promotions yet.', 'travel-agency-platform') . '</td></tr>';
        }
        foreach ($promos as $p) {
            $title = get_the_title($p->listing_id);
            if (empty($title)) {
                $title = __('Listado borrado', 'travel-agency-platform');
            }
            $status_color = 'active' === $p->status ? '#047857' : ('pending' === $p->status ? '#b45309' : '#94a3b8');
            echo '<tr>';
            echo '<td>' . esc_html($p->agency_name ?: ('#' . $p->agency_id)) . '</td>';
            echo '<td>' . esc_html($title) . ' <small>(' . esc_html(str_replace('tap_', '', (string) get_post_type($p->listing_id))) . ')</small></td>';
            echo '<td>' . esc_html($p->months) . '</td>';
            echo '<td>' . esc_html(TAP_Currency::fmt($p->amount)) . '</td>';
            echo '<td style="color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($p->status) . '</td>';
            echo '<td>' . esc_html($p->paid_until ?: '—') . '</td>';
            echo '<td>' . esc_html($p->created_at) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:8px;">';
            wp_nonce_field('tap_promo_activate_nonce');
            echo '<input type="hidden" name="promo_id" value="' . (int) $p->id . '">';
            echo '<input type="number" name="months" value="' . esc_attr($p->months) . '" min="1" max="24" style="width:60px;">';
            submit_button(__('Marcar activo', 'travel-agency-platform'), 'small', 'tap_promo_activate', false);
            echo '</form>';
            if ('expired' !== $p->status) {
                echo '<form method="post" style="display:inline-block;">';
                wp_nonce_field('tap_promo_expire_nonce');
                echo '<input type="hidden" name="promo_id" value="' . (int) $p->id . '">';
                submit_button(__('Expirar', 'travel-agency-platform'), 'small', 'tap_promo_expire', false);
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

                <h2 style="margin-top: 30px;"><?php esc_html_e('Cancellation policies (T7, booking lifecycle)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Definiciones', 'travel-agency-platform'); ?></th>
                        <td>
                            <ul style="margin:0;padding-left:18px;list-style:disc;">
                                <li><?php esc_html_e('Flexible — reembolso total si cancelas al menos 24h antes del check-in.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('Moderada — reembolso total 5 días antes; 50% entre 2 y 5 días; 0% menos de 2 días.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('Estricta — 50% de reembolso hasta 7 días antes; 0% después.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('No reembolsable — sin reembolso.', 'travel-agency-platform'); ?></li>
                            </ul>
                            <p class="description"><?php esc_html_e('La política efectiva de un listado se resuelve así: política propia del listado → política por defecto del tipo de servicio (abajo). La agencia puede fijar una política distinta por listado en el editor de su alojamiento/tour.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <?php foreach (TAP_Booking::service_type_slugs() as $slug) : ?>
                        <tr>
                            <th><label for="tap_cancel_policy_<?php echo esc_attr($slug); ?>"><?php echo esc_html(TAP_Booking::service_type_labels()[$slug] ?? $slug); ?></label></th>
                            <td>
                                <select id="tap_cancel_policy_<?php echo esc_attr($slug); ?>" name="tap_cancel_policy_<?php echo esc_attr($slug); ?>">
                                    <?php foreach (TAP_Booking::cancellation_policies() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, get_option('tap_cancel_policy_' . $slug, TAP_Booking::default_cancellation_policy($slug))); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><label for="tap_booking_auto_complete"><?php esc_html_e('Auto-completar reservas', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_booking_auto_complete" name="tap_booking_auto_complete" value="1" <?php checked('1', get_option('tap_booking_auto_complete', '1')); ?>>
                            <p class="description"><?php esc_html_e('Marcar automáticamente como "completada" toda reserva confirmada cuya fecha de salida ya pasó.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('Automations (T4, daily cron)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_auto_payment_reminders"><?php esc_html_e('Payment reminders', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_payment_reminders" name="tap_auto_payment_reminders" value="1" <?php checked('1', get_option('tap_auto_payment_reminders', '0')); ?>>
                            <p class="description"><?php esc_html_e('Email guests with pending/request bookings that never got paid.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_payment_reminder_hours"><?php esc_html_e('Remind after (hours)', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_payment_reminder_hours" name="tap_payment_reminder_hours" min="1" step="1" value="<?php echo esc_attr(get_option('tap_payment_reminder_hours', '12')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_prearrival"><?php esc_html_e('Pre-arrival message', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_prearrival" name="tap_auto_prearrival" value="1" <?php checked('1', get_option('tap_auto_prearrival', '0')); ?>>
                            <p class="description"><?php esc_html_e('Send guests a heads-up when their confirmed stay starts within 48h.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_review_request"><?php esc_html_e('Post-stay review request', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_review_request" name="tap_auto_review_request" value="1" <?php checked('1', get_option('tap_auto_review_request', '0')); ?>>
                            <p class="description"><?php esc_html_e('Ask guests to leave a review a few days after a completed stay.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_expiry_warnings"><?php esc_html_e('Expiry warnings', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_expiry_warnings" name="tap_auto_expiry_warnings" value="1" <?php checked('1', get_option('tap_auto_expiry_warnings', '0')); ?>>
                            <p class="description"><?php esc_html_e('Email agencies when their plan subscription or a featured listing is near expiry.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_expiry_warn_days"><?php esc_html_e('Warn days before expiry', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_expiry_warn_days" name="tap_expiry_warn_days" min="1" step="1" value="<?php echo esc_attr(get_option('tap_expiry_warn_days', '3')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_lead_ack"><?php esc_html_e('Contact auto-reply', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_lead_ack" name="tap_auto_lead_ack" value="1" <?php checked('1', get_option('tap_auto_lead_ack', '1')); ?>>
                            <p class="description"><?php esc_html_e('Send an acknowledgment email to people who use the contact form.', 'travel-agency-platform'); ?></p>
                        </td>
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
}
