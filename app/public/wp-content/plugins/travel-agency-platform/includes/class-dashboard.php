<?php
defined('ABSPATH') || exit;

class TAP_Dashboard {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 30);
        add_action('admin_init', [TAP_Dashboard_Settings::class, 'register_settings']);
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
    }

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

    public static function paginate($total, $per_page, $paged) {
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
        return isset($_POST[$trigger]) && self::post_nonce_ok($action);
    }
}
