<?php
defined('ABSPATH') || exit;

class TAP_Dashboard {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 30);
        add_action('admin_init', [TAP_Dashboard_Settings::class, 'register_settings']);
        // options.php hardcodes manage_options; align it with the page caps.
        add_filter('option_page_capability_tap_settings', static function () {
            return 'tap_manage_settings';
        });
        add_filter('option_page_capability_tap_privacy_group', static function () {
            return 'tap_manage_settings';
        });
        add_action('admin_init', [TAP_Dashboard_Bookings::class, 'maybe_export_bookings'], 5);
        add_action('admin_init', [TAP_Dashboard_Insights::class, 'maybe_export_analytics'], 5);
        add_action('admin_post_tap_destinations_save', [TAP_Dashboard_Destinations::class, 'handle_save']);
        add_action('admin_post_tap_destinations_add', [TAP_Dashboard_Destinations::class, 'handle_add']);
        add_action('admin_post_tap_landing_save', [TAP_Landing_Builder::class, 'handle_save']);
        add_action('wp_ajax_tap_landing_save_ajax', [TAP_Landing_Builder::class, 'ajax_save']);
        add_action('wp_ajax_tap_landing_preset', [TAP_Landing_Builder::class, 'ajax_preset']);
        TAP_Dashboard_Market::init();
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
        add_filter('manage_tap_experience_posts_columns', [TAP_Dashboard_Content::class, 'add_agency_column']);
        add_action('manage_tap_experience_posts_custom_column', [TAP_Dashboard_Content::class, 'render_agency_column'], 10, 2);

        // Bulk approve/unpublish for every service type.
        foreach (array_keys(TAP_Post_Types::get_service_types()) as $pt) {
            add_filter("bulk_actions-edit-{$pt}", [__CLASS__, 'add_bulk_actions']);
            add_filter("handle_bulk_actions-edit-{$pt}", [__CLASS__, 'handle_bulk_actions'], 10, 3);
        }
        add_action('admin_notices', [__CLASS__, 'bulk_admin_notice']);
    }

    /** Add publish/unpublish options to the CPT list-table bulk dropdown. */
    public static function add_bulk_actions($actions) {
        if (!current_user_can('publish_posts')) {
            return $actions;
        }
        $actions['tap_publish_listings'] = __('Publicar seleccionados', 'travel-agency-platform');
        $actions['tap_unpublish_listings'] = __('Despublicar seleccionados', 'travel-agency-platform');
        return $actions;
    }

    /** Apply the selected bulk action using the canonical publish helper. */
    public static function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if (!in_array($action, ['tap_publish_listings', 'tap_unpublish_listings'], true)) {
            return $redirect_to;
        }
        if (!current_user_can('publish_posts')) {
            return $redirect_to;
        }
        $publish = 'tap_publish_listings' === $action;
        $done = 0;
        foreach ((array) $post_ids as $id) {
            $post = get_post($id);
            if (!$post || !array_key_exists($post->post_type, TAP_Post_Types::get_service_types())) {
                continue;
            }
            $result = TAP_Ajax_Listing::set_listing_published($id, $publish);
            if (!is_wp_error($result)) {
                ++$done;
            }
        }
        return add_query_arg('tap_bulk_done', $done, $redirect_to);
    }

    /** One-line confirmation after a bulk publish/unpublish. */
    public static function bulk_admin_notice() {
        if (!isset($_GET['tap_bulk_done'])) {
            return;
        }
        $done = absint($_GET['tap_bulk_done']);
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html(sprintf(
                /* translators: %d: número de servicios actualizados */
                __('Se actualizaron %d servicios.', 'travel-agency-platform'),
                $done
            ))
            . '</p></div>';
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
            [TAP_Dashboard_Insights::class, 'reports_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Analytics', 'travel-agency-platform'),
            __('Analytics', 'travel-agency-platform'),
            'tap_view_reports',
            'tap-analytics',
            [TAP_Dashboard_Insights::class, 'analytics_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Invoices', 'travel-agency-platform'),
            __('Invoices', 'travel-agency-platform'),
            'manage_options',
            'tap-invoices',
            [TAP_Invoices::class, 'invoices_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Email campaigns', 'travel-agency-platform'),
            __('Email campaigns', 'travel-agency-platform'),
            'manage_options',
            'tap-email-campaigns',
            [TAP_Email_Campaigns::class, 'campaigns_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Plans', 'travel-agency-platform'),
            __('Plans', 'travel-agency-platform'),
            'manage_options',
            'tap-plans',
            [TAP_Dashboard_Settings::class, 'plans_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Subscriptions', 'travel-agency-platform'),
            __('Subscriptions', 'travel-agency-platform'),
            'manage_options',
            'tap-subscriptions',
            [TAP_Dashboard_Settings::class, 'subscriptions_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Promotions', 'travel-agency-platform'),
            __('Promotions', 'travel-agency-platform'),
            'manage_options',
            'tap-promotions',
            [TAP_Dashboard_Settings::class, 'promotions_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Settings', 'travel-agency-platform'),
            __('Settings', 'travel-agency-platform'),
            'tap_manage_settings',
            'tap-settings',
            [TAP_Dashboard_Settings::class, 'settings_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Destinations', 'travel-agency-platform'),
            __('Destinations', 'travel-agency-platform'),
            TAP_Dashboard_Destinations::CAP,
            'tap-destinations',
            [TAP_Dashboard_Destinations::class, 'render_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Landing Page', 'travel-agency-platform'),
            __('Landing Page', 'travel-agency-platform'),
            'manage_options',
            'tap-landing',
            [TAP_Landing_Builder::class, 'admin_page']
        );

        add_submenu_page(
            'travel-platform',
            __('Market tools', 'travel-agency-platform'),
            __('Market tools', 'travel-agency-platform'),
            'manage_options',
            'tap-market',
            [TAP_Dashboard_Market::class, 'render_page']
        );
    }

    /**
     * Keep the per-type "_is_active" flag in sync with post status. The public
     * archive filters on that flag, so publishing in wp-admin used to leave the
     * listing invisible. Also drops the cached KPI so counts update at once.
     */
    public static function sync_listing_active_on_status($new_status, $old_status, $post) {
        if (!$post || !array_key_exists($post->post_type, TAP_Post_Types::get_service_types())) {
            return;
        }
        $prefix = TAP_Post_Types::meta_prefix($post->post_type);
        if ('' === $prefix) {
            return;
        }
        update_post_meta($post->ID, '_tap_' . $prefix . '_is_active', 'publish' === $new_status ? '1' : '0');
        delete_transient('tap_listing_counts');
    }

    public static function listing_counts() {
        global $wpdb;
        $cached = get_transient('tap_listing_counts');
        if (is_array($cached)) {
            return $cached;
        }
        $result = [];
        $total  = 0;
        foreach (array_keys(TAP_Post_Types::get_service_types()) as $pt) {
            if ('tap_agency' === $pt) {
                continue;
            }
            $prefix = TAP_Post_Types::meta_prefix($pt);
            // "Active" means published AND flagged active, not merely published.
            $active = $prefix ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                   AND m.meta_key = %s AND m.meta_value = '1'
                 WHERE p.post_type = %s AND p.post_status = 'publish'",
                '_tap_' . $prefix . '_is_active',
                $pt
            )) : 0;
            $result[$pt] = $active;
            $total += $active;
        }
        $result['total_services'] = $total;
        set_transient('tap_listing_counts', $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }

    public static function paginate($total, $per_page, $paged) {
        $pages = (int) ceil($total / $per_page);
        if ($pages <= 1) return '';
        /* translators: %d: número total de elementos paginados */
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

    /**
     * Verify the nonce carried by an admin POST.
     *
     * Shared by every back-office form handler, which previously re-declared
     * the same isset/wp_unslash/sanitize/verify prologue nine times.
     */
    public static function post_nonce_ok($action) {
        if (!isset($_POST['_wpnonce'])) {
            return false;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        return (bool) wp_verify_nonce($nonce, $action);
    }

    /**
     * True when the form $trigger was submitted with a valid $action nonce.
     */
    public static function post_verified($trigger, $action) {
        if (!isset($_POST[$trigger])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }
        return self::post_nonce_ok($action);
    }
}
