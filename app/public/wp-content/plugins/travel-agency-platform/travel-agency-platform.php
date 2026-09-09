<?php
/**
 * Plugin Name: Travel Agency Platform
 * Plugin URI: https://ivanchodev.com
 * Description: Multi-agency travel platform B2B & B2C. Manage accommodations, tours, transports, car rentals, boats and packages.
 * Version: 1.4.6
 * Author: IvanchoDev
 * Text Domain: travel-agency-platform
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('TAP_VERSION', '1.4.6');
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
        require_once TAP_PLUGIN_DIR . 'includes/class-localization.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-currency.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-post-types.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-taxonomies.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-destinations.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-approval.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-roles.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-metaboxes.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-pricing.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-discounts.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-booking.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-api.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-shortcodes.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-chatbot.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-moderation.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-reviews.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-payouts.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-disputes.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-dashboard.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-paypal.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-payment.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-emails.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-seo.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-subscriptions.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-promotions.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-leads.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-analytics.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-sitemap.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-attribution.php';
        require_once TAP_PLUGIN_DIR . 'includes/class-privacy.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, ['TAP_Installer', 'activate']);
        register_deactivation_hook(__FILE__, ['TAP_Installer', 'deactivate']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', ['TAP_Localization', 'init'], 1);
        add_filter('locale', ['TAP_Localization', 'filter_locale'], 1);
        add_filter('determine_locale', ['TAP_Localization', 'filter_determine_locale'], 1);
        add_action('init', ['TAP_Post_Types', 'register'], 5);
        add_action('init', ['TAP_Taxonomies', 'register'], 6);
        add_action('init', ['TAP_Taxonomies', 'seed'], 7);
        add_action('init', ['TAP_Taxonomies', 'migrate_tour_difficulty'], 8);
        add_action('init', ['TAP_Roles', 'setup'], 9);
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
        add_action('wp_ajax_tap_chatbot_message', ['TAP_Ajax', 'chatbot_message']);
        add_action('wp_ajax_nopriv_tap_chatbot_message', ['TAP_Ajax', 'chatbot_message']);
        add_action('admin_init', ['TAP_Moderation', 'handle_admin_actions']);
        add_action('init', ['TAP_Subscriptions', 'init'], 14);
        add_action('init', ['TAP_Promotions', 'init'], 15);
        add_action('init', ['TAP_Analytics', 'init'], 16);
        add_action('init', ['TAP_Sitemap', 'init'], 17);
        add_action('init', ['TAP_Leads', 'init'], 18);
        add_action('init', ['TAP_Approval', 'init'], 19);
        add_action('init', ['TAP_Attribution', 'init'], 20);
        add_action('init', ['TAP_Privacy', 'init'], 21);
        add_action('wp_ajax_tap_lead_submit', ['TAP_Ajax', 'submit_lead']);
        add_action('wp_ajax_nopriv_tap_lead_submit', ['TAP_Ajax', 'submit_lead']);
        add_action('admin_post_tap_export_leads', ['TAP_Leads', 'csv_download']);
        add_action('admin_post_nopriv_tap_export_leads', ['TAP_Leads', 'csv_download']);
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

        wp_localize_script('tap-public', 'tapI18n', [
            'resultsFound'   => __('results found', 'travel-agency-platform'),
            'noResults'      => __('No results found', 'travel-agency-platform'),
            'favAdded'       => __('Guardado en favoritos', 'travel-agency-platform'),
            'favRemoved'     => __('Quitado de favoritos', 'travel-agency-platform'),
            'reviewsEmpty'   => __('Aún no hay reseñas.', 'travel-agency-platform'),
            'reviewsEmptyShare' => __('Aún no hay reseñas. Sé el primero en compartir tu experiencia.', 'travel-agency-platform'),
            'reviewOne'      => __('reseña', 'travel-agency-platform'),
            'reviewMany'     => __('reseñas', 'travel-agency-platform'),
            'anonymous'      => __('Anónimo', 'travel-agency-platform'),
            'reviewsSending' => __('Enviando...', 'travel-agency-platform'),
            'reviewsSent'    => __('¡Reseña enviada! Pendiente de aprobación.', 'travel-agency-platform'),
            'reviewsError'   => __('Error al enviar', 'travel-agency-platform'),
            'reviewSubmitted'=> __('Review submitted', 'travel-agency-platform'),
            'reviewError2'   => __('Error al enviar la reseña', 'travel-agency-platform'),
            'reviewThanks'   => __('Gracias por tu estancia (%s). ¡Cuéntanos cómo fue tu experiencia!', 'travel-agency-platform'),
            'reviewVerified' => __('Reseña verificada', 'travel-agency-platform'),
            'capacityAvailable' => __('Cupos disponibles: %s de %s', 'travel-agency-platform'),
            'capacityFull'   => __('Cupo completo para esta fecha (%s/%s). Elige otra fecha.', 'travel-agency-platform'),
            'tourFull'       => __('El tour está completo para esta fecha.', 'travel-agency-platform'),
            'bookNow'        => __('Book Now', 'travel-agency-platform'),
            'errorGeneric'   => __('Error', 'travel-agency-platform'),
            'galleryLabel'   => __('Image gallery', 'travel-agency-platform'),
            'closeLabel'     => __('Close image gallery', 'travel-agency-platform'),
            'prevLabel'      => __('Previous image', 'travel-agency-platform'),
            'nextLabel'      => __('Next image', 'travel-agency-platform'),
            'chatLabel'      => __('Asistente de viajes', 'travel-agency-platform'),
            'chatOpen'       => __('Abrir asistente', 'travel-agency-platform'),
            'chatClose'      => __('Cerrar asistente', 'travel-agency-platform'),
            'chatPlaceholder' => __('Escribe tu pregunta…', 'travel-agency-platform'),
            'chatSend'       => __('Enviar', 'travel-agency-platform'),
            'chatIntro'      => __('¡Hola! Soy el asistente de viajes. Pregunta por alojamientos, tours, bonos o agencias, o toca una sugerencia.', 'travel-agency-platform'),
            'chatThinking'   => __('Pensando…', 'travel-agency-platform'),
            'chatError'      => __('Hubo un problema. Inténtalo de nuevo.', 'travel-agency-platform'),
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
