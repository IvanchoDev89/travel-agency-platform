<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
/**
 * Plugin Name: Travel Agency Platform
 * Plugin URI: https://ivanchodev.com
 * Description: Multi-agency travel platform B2B & B2C. Manage accommodations, tours, transports, car rentals, boats and packages.
 * Version:           1.5.53
 * Requires at least: 6.4
 * Requires PHP:      8.2.35
 * Author: IvanchoDev
 * Text Domain: travel-agency-platform
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('TAP_VERSION', '1.5.53');
define('TAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Composer classmap autoloader (see composer.json → autoload.classmap).
//
// Lookup order, most specific first:
//   1. A plugin-local vendor/ — the shape used when the plugin is shipped as a
//      standalone folder inside a regular WordPress install.
//   2. This repository's root vendor/ — the Local-by-Flywheel checkout, whose
//      composer.json lives at the repo root and points at the plugin's
//      includes/ directory.
$tap_autoload_candidates = [
    TAP_PLUGIN_DIR . 'vendor/autoload.php',
    dirname(__DIR__, 5) . '/vendor/autoload.php',
];
foreach ($tap_autoload_candidates as $tap_autoload) {
    if (file_exists($tap_autoload)) {
        require_once $tap_autoload;
        unset($tap_autoload);
        break;
    }
}
unset($tap_autoload_candidates);

// Trigger the autoloader once and check the class resolves. Without this the
// plugin would fatal later on the first TAP_* class it touches.
if (!class_exists('TAP_Booking')) {
    /**
     * The autoloader is what wires every class in includes/. Without it the
     * plugin cannot register anything, so surface the real cause instead of
     * letting WordPress die on the first missing class.
     */
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Travel Agency Platform:</strong> '
            . esc_html__('no se encontró el autoloader de Composer. Ejecuta "composer dump-autoload -o".', 'travel-agency-platform')
            . '</p></div>';
    });
    return;
}

final class TravelAgencyPlatform {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, ['TAP_Installer', 'activate']);
        register_activation_hook(__FILE__, ['TAP_Requirements', 'block_activation']);
        register_deactivation_hook(__FILE__, ['TAP_Installer', 'deactivate']);

        add_action('admin_notices', ['TAP_Requirements', 'admin_notice']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', ['TAP_Localization', 'init'], 1);
        add_filter('locale', ['TAP_Localization', 'filter_locale'], 1);
        add_filter('determine_locale', ['TAP_Localization', 'filter_determine_locale'], 1);
        add_action('init', ['TAP_Post_Types', 'register'], 5);
        add_action('init', ['TAP_Destination_Spots', 'register'], 5);
        add_action('init', ['TAP_Taxonomies', 'register'], 6);
        add_action('init', ['TAP_Taxonomies', 'seed'], 7);
        add_action('init', ['TAP_Destination_Spots', 'seed_types'], 7);
        add_action('init', ['TAP_Destination_Spots', 'seed'], 8);
        add_action('init', ['TAP_Destination_Spots', 'sync_extra_spots'], 9);
        add_action('init', ['TAP_Destination_Spots', 'enrich_existing'], 10);
        add_action('init', ['TAP_Destination_Spots', 'link_locations'], 11);
        add_action('init', ['TAP_Destination_Spots', 'ensure_page'], 30);
        add_action('template_redirect', ['TAP_Destination_Spots', 'redirect_linked_terms']);
        add_action('init', ['TAP_Destinations', 'init'], 7);
        add_action('init', ['TAP_Taxonomies', 'migrate_tour_difficulty'], 8);
        add_action('init', ['TAP_Roles', 'setup'], 9);
        add_action('init', ['TAP_Shortcodes', 'init'], 8);
        add_shortcode('tap_currency_selector', ['TAP_Currency', 'render_selector']);
        add_action('init', ['TAP_Dashboard', 'init'], 9);
        add_action('init', ['TAP_Pricing', 'init'], 10);
        add_action('init', ['TAP_Discounts', 'init'], 11);
        add_action('init', ['TAP_Referrals', 'init'], 12);
        add_action('init', ['TAP_ICal', 'init'], 13);
        add_action('init', ['TAP_ICal_Import', 'init'], 14);
        add_action('init', ['TAP_Shuttle', 'init'], 17);
        add_action('init', ['TAP_Forum', 'init'], 18);
        add_action('init', ['TAP_Itinerary_Collab', 'init'], 16);
        add_action('init', ['TAP_Travel_Insurance', 'init'], 19);
        add_action('init', ['TAP_Invoices', 'init'], 17);
        add_action('init', ['TAP_Push', 'init'], 15);
        // TAP_Waitlist has no init (static methods only).
        add_action('admin_post_tap_sign_waiver', ['TAP_Waiver', 'handle_sign']);
        add_action('admin_post_nopriv_tap_sign_waiver', ['TAP_Waiver', 'handle_sign']);
        add_action('init', ['TAP_Email_Campaigns', 'init'], 14);
        add_action('init', ['TAP_Installer', 'migrate_experiences_to_tours'], 25);
        add_action('template_redirect', ['TAP_Post_Types', 'redirect_experience_to_tour']);
        add_action('init', ['TAP_Translations', 'init'], 15);
        add_shortcode('tap_lang_selector', ['TAP_Translations', 'render_selector']);
        add_shortcode('tap_push_subscribe', ['TAP_Push', 'render_subscribe_button']);
        add_action('init', ['TAP_Loyalty', 'init'], 13);
        add_action('init', ['TAP_Emails', 'init'], 12);
        add_action('init', ['TAP_SEO', 'init'], 13);
        add_action('init', ['TAP_Itinerary', 'init'], 14);
        add_action('init', ['TAP_Front_Dash', 'init'], 15);
        add_action('plugins_loaded', [$this, 'maybe_update_tables'], 5);
        add_action('pre_get_posts', ['TAP_Archive_Query', 'filter_archive_query'], 10);
        add_filter('posts_clauses', ['TAP_Archive_Query', 'rating_sort_clauses'], 10, 2);
        add_filter('posts_clauses', ['TAP_Archive_Query', 'featured_sort_clauses'], 11, 2);
        add_action('init', function () {
            if (!wp_next_scheduled('tap_maintenance_hook')) {
                wp_schedule_event(time(), 'hourly', 'tap_maintenance_hook');
            }
            if (!wp_next_scheduled('tap_refund_retry_hook')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tap_refund_retry_hook');
            }
        });
        add_action('tap_maintenance_hook', ['TAP_Booking', 'cancel_stale_bookings']);
        add_action('tap_maintenance_hook', ['TAP_Booking', 'complete_past_bookings']);
        add_action('tap_refund_retry_hook', ['TAP_Booking', 'retry_pending_refunds']);
        add_action('tap_payment_completed', ['TAP_Booking', 'mark_commission_owed'], 20, 1);
        add_action('add_meta_boxes', ['TAP_Metaboxes', 'register']);
        add_filter('default_hidden_meta_boxes', ['TAP_Metaboxes', 'unhide_tour_metabox'], 10, 2);
        add_action('save_post', ['TAP_Metaboxes', 'save'], 10, 2);
        // Tanda D: drop the tap_agencies registry row when an agency is hard-deleted.
        add_action('deleted_post', ['TAP_Dashboard_Content', 'on_agency_deleted'], 10, 2);
        add_action('rest_api_init', ['TAP_API', 'register_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_tap_booking_create', ['TAP_Ajax', 'create_booking']);
        add_action('wp_ajax_nopriv_tap_booking_create', ['TAP_Ajax', 'create_booking']);
        add_action('wp_ajax_tap_cancel_booking', ['TAP_Ajax', 'cancel_booking']);
        add_action('wp_ajax_nopriv_tap_cancel_booking', ['TAP_Ajax', 'cancel_booking']);
        add_action('wp_ajax_tap_chat_client_send', ['TAP_Ajax', 'chat_client_send']);
        add_action('wp_ajax_nopriv_tap_chat_client_send', ['TAP_Ajax', 'chat_client_send']);
        add_action('wp_ajax_tap_chat_client_thread', ['TAP_Ajax', 'chat_client_thread']);
        add_action('wp_ajax_nopriv_tap_chat_client_thread', ['TAP_Ajax', 'chat_client_thread']);
        add_action('wp_ajax_tap_search_services', ['TAP_Ajax_Public', 'search_services']);
        add_action('wp_ajax_nopriv_tap_search_services', ['TAP_Ajax_Public', 'search_services']);
        add_action('wp_ajax_tap_create_paypal_order', ['TAP_Ajax_Commerce', 'create_paypal_order']);
        add_action('wp_ajax_nopriv_tap_create_paypal_order', ['TAP_Ajax_Commerce', 'create_paypal_order']);
        add_action('wp_ajax_tap_capture_paypal_order', ['TAP_Ajax_Commerce', 'capture_paypal_order']);
        add_action('wp_ajax_nopriv_tap_capture_paypal_order', ['TAP_Ajax_Commerce', 'capture_paypal_order']);
        add_action('wp_ajax_tap_calculate_booking_total', ['TAP_Ajax_Public', 'calculate_booking_total']);
        add_action('wp_ajax_nopriv_tap_calculate_booking_total', ['TAP_Ajax_Public', 'calculate_booking_total']);
        add_action('wp_ajax_tap_set_currency', ['TAP_Ajax_Public', 'set_currency']);
        add_action('wp_ajax_nopriv_tap_set_currency', ['TAP_Ajax_Public', 'set_currency']);
        add_action('wp_ajax_tap_push_subscribe_client', ['TAP_Ajax_Public', 'push_subscribe_client']);
        add_action('wp_ajax_tap_submit_question', ['TAP_Ajax_Public', 'submit_question']);
        add_action('wp_ajax_nopriv_tap_submit_question', ['TAP_Ajax_Public', 'submit_question']);
        add_action('wp_ajax_tap_answer_question', ['TAP_Ajax_Public', 'answer_question']);
        add_action('wp_ajax_tap_get_rooms', ['TAP_Ajax_Public', 'get_rooms']);
        add_action('wp_ajax_nopriv_tap_get_rooms', ['TAP_Ajax_Public', 'get_rooms']);
        add_action('wp_ajax_tap_get_public_pricing', ['TAP_Ajax_Public', 'get_public_pricing']);
        add_action('wp_ajax_nopriv_tap_get_public_pricing', ['TAP_Ajax_Public', 'get_public_pricing']);
        add_action('wp_ajax_tap_search_suggestions', ['TAP_Ajax_Public', 'search_suggestions']);
        add_action('wp_ajax_nopriv_tap_search_suggestions', ['TAP_Ajax_Public', 'search_suggestions']);
        add_action('wp_ajax_tap_update_booking_status', ['TAP_Ajax', 'update_booking_status']);
        add_action('wp_ajax_tap_agency_register', ['TAP_Ajax', 'agency_register']);
        add_action('wp_ajax_nopriv_tap_agency_register', ['TAP_Ajax', 'agency_register']);
        add_action('wp_ajax_tap_agency_save_listing', ['TAP_Ajax_Listing', 'agency_save_listing']);
        add_action('wp_ajax_tap_agency_save_room', ['TAP_Ajax_Listing', 'agency_save_room']);
        add_action('wp_ajax_tap_agency_delete_room', ['TAP_Ajax_Listing', 'agency_delete_room']);
        add_action('wp_ajax_tap_agency_upload_image', ['TAP_Agency_Media', 'agency_upload_image']);
        add_action('wp_ajax_tap_agency_toggle_publish', ['TAP_Ajax_Listing', 'agency_toggle_publish']);
        add_action('wp_ajax_tap_toggle_favorite', ['TAP_Ajax', 'toggle_favorite']);
        add_action('wp_ajax_tap_agency_subscribe', ['TAP_Ajax_Commerce', 'agency_subscribe']);
        add_action('wp_ajax_tap_subscribe_paypal', ['TAP_Ajax_Commerce', 'subscribe_paypal']);
        add_action('wp_ajax_tap_capture_subscription_paypal', ['TAP_Ajax_Commerce', 'capture_subscription_paypal']);
        add_action('wp_ajax_tap_promo_request', ['TAP_Ajax_Commerce', 'promo_request']);
        add_action('wp_ajax_tap_promo_paypal', ['TAP_Ajax_Commerce', 'promo_request_paypal']);
        add_action('wp_ajax_tap_capture_promo_paypal', ['TAP_Ajax_Commerce', 'capture_promo_paypal']);
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
        add_action('init', ['TAP_Automations', 'init'], 22);
        add_action('wp_ajax_tap_lead_submit', ['TAP_Ajax', 'submit_lead']);
        add_action('wp_ajax_nopriv_tap_lead_submit', ['TAP_Ajax', 'submit_lead']);
        add_action('admin_post_tap_export_leads', ['TAP_Leads', 'csv_download']);
        add_action('admin_post_tap_export_bookings', ['TAP_Front_Dash', 'bookings_csv_download']);
        add_action('admin_post_tap_export_finances', ['TAP_Front_Dash', 'finances_csv_download']);
        add_action('admin_post_tap_email_campaign_save', ['TAP_Email_Campaigns_Admin', 'handle_save']);
        add_action('admin_post_tap_email_campaign_delete', ['TAP_Email_Campaigns_Admin', 'handle_delete']);
        add_action('admin_post_tap_email_campaign_send', ['TAP_Email_Campaigns_Admin', 'handle_send']);
        add_action('admin_post_tap_email_campaign_schedule', ['TAP_Email_Campaigns_Admin', 'handle_schedule']);
        // Kept on purpose: the handler enforces login + nonce + agency ownership
        // and this lets an expired session get the friendly /login/ redirect
        // instead of a blank admin-post response.
        add_action('admin_post_nopriv_tap_export_leads', ['TAP_Leads', 'csv_download']);
        add_shortcode('tap_checkout', [TAP_Shortcode_Booking::class, 'checkout']);
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
        wp_enqueue_style('tap-tokens', TAP_PLUGIN_URL . 'assets/css/tokens.css', [], TAP_VERSION);
        wp_enqueue_style('tap-public', TAP_PLUGIN_URL . 'assets/css/public.css', ['tap-tokens'], TAP_VERSION);
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
            'rest_url' => esc_url_raw(rest_url('tap/v1/')),
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
            /* translators: %s: nombre del servicio */
            'reviewThanks'   => __('Gracias por tu estancia (%s). ¡Cuéntanos cómo fue tu experiencia!', 'travel-agency-platform'),
            'reviewVerified' => __('Reseña verificada', 'travel-agency-platform'),
            /* translators: 1: cupos disponibles, 2: cupos totales */
            'capacityAvailable' => __('Cupos disponibles: %s de %s', 'travel-agency-platform'),
            /* translators: 1: cupos ocupados, 2: cupos totales */
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
        $is_tap_page = false !== strpos($hook, 'tap_');
        if (!$is_tap_page) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $pt     = $screen ? ($screen->post_type ?? '') : '';
            $is_tap_page = $pt && in_array($pt, array_keys(TAP_Post_Types::get_service_types()), true);
        }
        if (!$is_tap_page) return;
        wp_enqueue_style('tap-admin', TAP_PLUGIN_URL . 'assets/css/admin.css', [], TAP_VERSION);
        wp_enqueue_script('tap-admin', TAP_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], TAP_VERSION, true);
        wp_localize_script('tap-admin', 'tapAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tap_admin_booking'),
        ]);
    }
}

TravelAgencyPlatform::instance();
