<?php
/**
 * Plugin Name: Travel Agency Platform
 * Plugin URI: https://ivanchodev.com
 * Description: Multi-agency travel platform B2B & B2C. Manage accommodations, tours, transports, car rentals, boats and packages.
 * Version: 1.3.0
 * Author: IvanchoDev
 * Text Domain: travel-agency-platform
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('TAP_VERSION', '1.3.0');
define('TAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAP_PLUGIN_URL', plugin_dir_url(__FILE__));

final class TravelAgencyPlatform {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once TAP_PLUGIN_DIR . 'includes/class-installer.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-currency.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-post-types.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-taxonomies.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-roles.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-metaboxes.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-pricing.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-discounts.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-booking.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-api.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-shortcodes.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-dashboard.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-paypal.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-payment.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-emails.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-seo.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-subscriptions.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-promotions.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-analytics.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, ['TAP_Installer', 'activate']);
        register_deactivation_hook(__FILE__, ['TAP_Installer', 'deactivate']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', ['TAP_Post_Types', 'register'], 5);
        add_action('init', ['TAP_Taxonomies', 'register'], 6);
        add_action('init', ['TAP_Roles', 'setup'], 7);
        add_action('init', ['TAP_Shortcodes', 'init'], 8);
        add_action('init', ['TAP_Dashboard', 'init'], 9);
        add_action('init', ['TAP_Pricing', 'init'], 10);
        add_action('init', ['TAP_Discounts', 'init'], 11);
        add_action('init', ['TAP_Emails', 'init'], 12);
        add_action('init', ['TAP_SEO', 'init'], 13);
        add_action('plugins_loaded', [$this, 'maybe_update_tables'], 5);
        add_action('pre_get_posts', ['TAP_Ajax', 'filter_archive_query'], 10);
        add_filter('posts_clauses', ['TAP_Ajax', 'rating_sort_clauses'], 10, 2);
        add_filter('posts_clauses', ['TAP_Ajax', 'featured_sort_clauses'], 11, 2);
        add_action('init', function () {
            if (!wp_next_scheduled('tap_maintenance_hook')) {
                wp_schedule_event(time(), 'hourly', 'tap_maintenance_hook');
            }
        });
        add_action('tap_maintenance_hook', ['TAP_Booking', 'cancel_stale_bookings']);
        add_action('add_meta_boxes', ['TAP_Metaboxes', 'register']);
        add_action('save_post', ['TAP_Metaboxes', 'save'], 10, 2);
        add_action('rest_api_init', ['TAP_API', 'register_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_tap_booking_create', ['TAP_Ajax', 'create_booking']);
        add_action('wp_ajax_nopriv_tap_booking_create', ['TAP_Ajax', 'create_booking']);
        add_action('wp_ajax_tap_cancel_booking', ['TAP_Ajax', 'cancel_booking']);
        add_action('wp_ajax_nopriv_tap_cancel_booking', ['TAP_Ajax', 'cancel_booking']);
        add_action('wp_ajax_tap_search_services', ['TAP_Ajax', 'search_services']);
        add_action('wp_ajax_nopriv_tap_search_services', ['TAP_Ajax', 'search_services']);
        add_action('wp_ajax_tap_create_paypal_order', ['TAP_Ajax', 'create_paypal_order']);
        add_action('wp_ajax_nopriv_tap_create_paypal_order', ['TAP_Ajax', 'create_paypal_order']);
        add_action('wp_ajax_tap_capture_paypal_order', ['TAP_Ajax', 'capture_paypal_order']);
        add_action('wp_ajax_nopriv_tap_capture_paypal_order', ['TAP_Ajax', 'capture_paypal_order']);
        add_action('wp_ajax_tap_calculate_booking_total', ['TAP_Ajax', 'calculate_booking_total']);
        add_action('wp_ajax_nopriv_tap_calculate_booking_total', ['TAP_Ajax', 'calculate_booking_total']);
        add_action('wp_ajax_tap_get_rooms', ['TAP_Ajax', 'get_rooms']);
        add_action('wp_ajax_nopriv_tap_get_rooms', ['TAP_Ajax', 'get_rooms']);
        add_action('wp_ajax_tap_get_public_pricing', ['TAP_Ajax', 'get_public_pricing']);
        add_action('wp_ajax_nopriv_tap_get_public_pricing', ['TAP_Ajax', 'get_public_pricing']);
        add_action('wp_ajax_tap_search_suggestions', ['TAP_Ajax', 'search_suggestions']);
        add_action('wp_ajax_nopriv_tap_search_suggestions', ['TAP_Ajax', 'search_suggestions']);
        add_action('wp_ajax_tap_update_booking_status', ['TAP_Ajax', 'update_booking_status']);
        add_action('wp_ajax_tap_agency_register', ['TAP_Ajax', 'agency_register']);
        add_action('wp_ajax_nopriv_tap_agency_register', ['TAP_Ajax', 'agency_register']);
        add_action('wp_ajax_tap_agency_save_listing', ['TAP_Ajax', 'agency_save_listing']);
        add_action('wp_ajax_tap_agency_save_room', ['TAP_Ajax', 'agency_save_room']);
        add_action('wp_ajax_tap_agency_delete_room', ['TAP_Ajax', 'agency_delete_room']);
        add_action('wp_ajax_tap_toggle_favorite', ['TAP_Ajax', 'toggle_favorite']);
        add_action('wp_ajax_tap_agency_subscribe', ['TAP_Ajax', 'agency_subscribe']);
        add_action('wp_ajax_tap_subscribe_paypal', ['TAP_Ajax', 'subscribe_paypal']);
        add_action('wp_ajax_tap_capture_subscription_paypal', ['TAP_Ajax', 'capture_subscription_paypal']);
        add_action('wp_ajax_tap_promo_request', ['TAP_Ajax', 'promo_request']);
        add_action('wp_ajax_tap_promo_paypal', ['TAP_Ajax', 'promo_request_paypal']);
        add_action('wp_ajax_tap_capture_promo_paypal', ['TAP_Ajax', 'capture_promo_paypal']);
        add_action('init', ['TAP_Subscriptions', 'init'], 14);
        add_action('init', ['TAP_Promotions', 'init'], 15);
        add_action('init', ['TAP_Analytics', 'init'], 16);
        add_shortcode('tap_checkout', ['TAP_Shortcodes', 'checkout']);
    }

    public function maybe_update_tables() {
        if (get_option('tap_version') !== TAP_VERSION) {
            TAP_Installer::create_tables();
            update_option('tap_version', TAP_VERSION);
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('travel-agency-platform', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_public_assets() {
        wp_enqueue_style('tap-public', TAP_PLUGIN_URL . 'assets/css/public.css', [], TAP_VERSION);
        wp_enqueue_script('tap-public', TAP_PLUGIN_URL . 'assets/js/public.js', ['jquery'], TAP_VERSION, true);
        if (is_singular('tap_accommodation')) {
            wp_enqueue_script('tap-calendar', TAP_PLUGIN_URL . 'assets/js/public-calendar.js', ['jquery'], TAP_VERSION, true);
        }
        if (is_post_type_archive('tap_accommodation') || is_tax(['tap_property_type', 'tap_amenity'])) {
            wp_enqueue_style('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
            wp_enqueue_style('tap-leaflet-cluster', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.css', [], '1.5.3');
            wp_enqueue_style('tap-leaflet-cluster-default', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.default.css', [], '1.5.3');
            wp_enqueue_script('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', false);
            wp_enqueue_script('tap-leaflet-cluster', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.js', ['tap-leaflet'], '1.5.3', false);
            wp_enqueue_script('tap-map', TAP_PLUGIN_URL . 'assets/js/public-map.js', ['jquery', 'tap-leaflet', 'tap-leaflet-cluster'], TAP_VERSION, true);
            wp_localize_script('tap-map', 'tapMapData', [
                'pluginUrl' => TAP_PLUGIN_URL,
                'nonce'     => wp_create_nonce('tap_nonce'),
            ]);
        }
        wp_localize_script('tap-public', 'tap_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tap_nonce'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'agency_nonce' => wp_create_nonce('tap_agency_nonce'),
            'register_nonce' => wp_create_nonce('tap_register_nonce'),
            'listing_nonce' => wp_create_nonce('tap_agency_listing_nonce'),
            'currency_symbol' => TAP_Currency::symbol(),
            'currency_code' => TAP_Currency::code(),
            'currency_decimals' => TAP_Currency::decimals(),
            'is_logged_in' => is_user_logged_in() ? '1' : '0',
            'login_url' => wp_login_url(),
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if (false === strpos($hook, 'tap_')) return;
        wp_enqueue_style('tap-admin', TAP_PLUGIN_URL . 'assets/css/admin.css', [], TAP_VERSION);
        wp_enqueue_script('tap-admin', TAP_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], TAP_VERSION, true);
    }
}

function TAP() {
    return TravelAgencyPlatform::instance();
}

TAP();
