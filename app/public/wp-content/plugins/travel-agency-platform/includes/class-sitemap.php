<?php
defined('ABSPATH') || exit;

/**
 * Technical SEO: extends WordPress' native XML sitemaps so every platform
 * post type and taxonomy is covered, and ensures robots.txt references it.
 *
 * WP Core already serves /sitemap.xml and /wp-sitemap.xml; we simply add our
 * missing post types and taxonomies to its providers so Google indexes the
 * full travel directory without a custom rewrite (which would 301-redirect).
 */
class TAP_Sitemap {

    protected static $service_types = [
        'tap_accommodation',
        'tap_tour',
        'tap_transport',
        'tap_car_rental',
        'tap_boat',
        'tap_package',
    ];

    protected static $taxonomies = [
        'tap_location',
        'tap_service_cat',
        'tap_property_type',
        'tap_amenity',
        'tap_tour_type',
        'tap_vehicle_type',
        'tap_boat_type',
    ];

    public static function init() {
        add_filter('wp_sitemaps_post_types', [__CLASS__, 'post_types']);
        add_filter('wp_sitemaps_taxonomies', [__CLASS__, 'taxonomies']);
        add_action('do_robots', [__CLASS__, 'robots_sitemap_line']);
    }

    public static function post_types($post_types) {
        foreach (self::$service_types as $pt) {
            $obj = get_post_type_object($pt);
            if ($obj && $obj->public && !isset($post_types[$pt])) {
                $post_types[$pt] = $obj;
            }
        }
        return $post_types;
    }

    public static function taxonomies($taxonomies) {
        foreach (self::$taxonomies as $tax) {
            $obj = get_taxonomy($tax);
            if ($obj && $obj->public && !isset($taxonomies[$tax])) {
                $taxonomies[$tax] = $obj;
            }
        }
        return $taxonomies;
    }

    public static function robots_sitemap_line() {
        $sitemap = home_url('/wp-sitemap.xml');
        if (!wp_sitemaps_get_server()->sitemaps_enabled()) {
            $sitemap = home_url('/wp-sitemap.xml');
        }
        echo "\nSitemap: " . esc_url($sitemap) . "\n";
    }
}
