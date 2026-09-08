<?php
defined('ABSPATH') || exit;

class TAP_Taxonomies {
    public static function register() {
        self::register_location();
        self::register_service_category();
        self::register_amenity();
        self::register_property_type();
        self::register_tour_type();
        self::register_vehicle_type();
        self::register_boat_type();
        self::register_tour_difficulty();
    }

    private static function register_location() {
        $labels = [
            'name'              => __('Destinations', 'travel-agency-platform'),
            'singular_name'     => __('Destination', 'travel-agency-platform'),
            'search_items'      => __('Search Destinations', 'travel-agency-platform'),
            'all_items'         => __('All Destinations', 'travel-agency-platform'),
            'parent_item'       => __('Parent Destination', 'travel-agency-platform'),
            'parent_item_colon' => __('Parent Destination:', 'travel-agency-platform'),
            'edit_item'         => __('Edit Destination', 'travel-agency-platform'),
            'update_item'       => __('Update Destination', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Destination', 'travel-agency-platform'),
            'new_item_name'     => __('New Destination Name', 'travel-agency-platform'),
            'menu_name'         => __('Destinations', 'travel-agency-platform'),
        ];

        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];

        register_taxonomy('tap_location', $types, [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'destination'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    public static function seed() {
        if (class_exists('TAP_Destinations')) {
            TAP_Destinations::seed_cr();
        }
        self::seed_tour_types();
    }

    private static function register_service_category() {
        $labels = [
            'name'              => __('Service Categories', 'travel-agency-platform'),
            'singular_name'     => __('Service Category', 'travel-agency-platform'),
            'search_items'      => __('Search Categories', 'travel-agency-platform'),
            'all_items'         => __('All Categories', 'travel-agency-platform'),
            'edit_item'         => __('Edit Category', 'travel-agency-platform'),
            'update_item'       => __('Update Category', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Category', 'travel-agency-platform'),
            'menu_name'         => __('Categories', 'travel-agency-platform'),
        ];

        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];

        register_taxonomy('tap_service_cat', $types, [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'service-category'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    private static function register_property_type() {
        $labels = [
            'name'              => __('Property Types', 'travel-agency-platform'),
            'singular_name'     => __('Property Type', 'travel-agency-platform'),
            'search_items'      => __('Search Property Types', 'travel-agency-platform'),
            'all_items'         => __('All Property Types', 'travel-agency-platform'),
            'edit_item'         => __('Edit Property Type', 'travel-agency-platform'),
            'update_item'       => __('Update Property Type', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Property Type', 'travel-agency-platform'),
            'menu_name'         => __('Property Types', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_property_type', ['tap_accommodation'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'property-type'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);

        $default_terms = [
            'hotel'    => 'Hotel',
            'hostel'   => 'Hostel',
            'resort'   => 'Resort',
            'villa'    => 'Villa',
            'apartment'=> 'Apartment',
            'cabin'    => 'Cabin',
            'boutique' => 'Boutique Hotel',
            'eco'      => 'Eco-Lodge',
            'guesthouse' => 'Guest House',
            'bedbreakfast' => 'Bed & Breakfast',
        ];
        foreach ($default_terms as $slug => $name) {
            if (!term_exists($slug, 'tap_property_type')) {
                wp_insert_term($name, 'tap_property_type', ['slug' => $slug]);
            }
        }
    }

    private static function register_amenity() {
        $labels = [
            'name'              => __('Amenities', 'travel-agency-platform'),
            'singular_name'     => __('Amenity', 'travel-agency-platform'),
            'search_items'      => __('Search Amenities', 'travel-agency-platform'),
            'all_items'         => __('All Amenities', 'travel-agency-platform'),
            'edit_item'         => __('Edit Amenity', 'travel-agency-platform'),
            'update_item'       => __('Update Amenity', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Amenity', 'travel-agency-platform'),
            'menu_name'         => __('Amenities', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_amenity', ['tap_accommodation', 'tap_room'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => false,
            'rewrite'      => ['slug' => 'amenity'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    private static function register_tour_type() {
        $labels = [
            'name'              => __('Tour Types', 'travel-agency-platform'),
            'singular_name'     => __('Tour Type', 'travel-agency-platform'),
            'search_items'      => __('Search Tour Types', 'travel-agency-platform'),
            'all_items'         => __('All Tour Types', 'travel-agency-platform'),
            'edit_item'         => __('Edit Tour Type', 'travel-agency-platform'),
            'update_item'       => __('Update Tour Type', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Tour Type', 'travel-agency-platform'),
            'menu_name'         => __('Tour Types', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_tour_type', ['tap_tour'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'tour-type'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    private static function register_vehicle_type() {
        $labels = [
            'name'              => __('Vehicle Types', 'travel-agency-platform'),
            'singular_name'     => __('Vehicle Type', 'travel-agency-platform'),
            'search_items'      => __('Search Vehicle Types', 'travel-agency-platform'),
            'all_items'         => __('All Vehicle Types', 'travel-agency-platform'),
            'edit_item'         => __('Edit Vehicle Type', 'travel-agency-platform'),
            'update_item'       => __('Update Vehicle Type', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Vehicle Type', 'travel-agency-platform'),
            'menu_name'         => __('Vehicle Types', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_vehicle_type', ['tap_transport', 'tap_car_rental'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'vehicle-type'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    private static function register_boat_type() {
        $labels = [
            'name'              => __('Boat Types', 'travel-agency-platform'),
            'singular_name'     => __('Boat Type', 'travel-agency-platform'),
            'search_items'      => __('Search Boat Types', 'travel-agency-platform'),
            'all_items'         => __('All Boat Types', 'travel-agency-platform'),
            'edit_item'         => __('Edit Boat Type', 'travel-agency-platform'),
            'update_item'       => __('Update Boat Type', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Boat Type', 'travel-agency-platform'),
            'menu_name'         => __('Boat Types', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_boat_type', ['tap_boat'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => ['slug' => 'boat-type'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);
    }

    private static function register_tour_difficulty() {
        $labels = [
            'name'              => __('Tour Difficulties', 'travel-agency-platform'),
            'singular_name'     => __('Tour Difficulty', 'travel-agency-platform'),
            'search_items'      => __('Search Difficulties', 'travel-agency-platform'),
            'all_items'         => __('All Difficulties', 'travel-agency-platform'),
            'edit_item'         => __('Edit Difficulty', 'travel-agency-platform'),
            'update_item'       => __('Update Difficulty', 'travel-agency-platform'),
            'add_new_item'      => __('Add New Difficulty', 'travel-agency-platform'),
            'menu_name'         => __('Difficulties', 'travel-agency-platform'),
        ];

        register_taxonomy('tap_tour_difficulty', ['tap_tour'], [
            'labels'       => $labels,
            'public'       => true,
            'hierarchical' => false,
            'rewrite'      => ['slug' => 'tour-difficulty'],
            'show_in_rest' => true,
            'show_admin_column' => true,
        ]);

        foreach (self::difficulty_terms() as $slug => $name) {
            if (!term_exists($slug, 'tap_tour_difficulty')) {
                wp_insert_term($name, 'tap_tour_difficulty', ['slug' => $slug]);
            }
        }
    }

    private static function difficulty_terms() {
        return [
            'easy'     => __('Fácil', 'travel-agency-platform'),
            'moderate' => __('Moderado', 'travel-agency-platform'),
            'hard'     => __('Difícil', 'travel-agency-platform'),
            'extreme'  => __('Extremo', 'travel-agency-platform'),
        ];
    }

    private static function seed_tour_types() {
        $terms = [
            'aventura'    => __('Aventura', 'travel-agency-platform'),
            'naturaleza'  => __('Naturaleza', 'travel-agency-platform'),
            'cultural'    => __('Cultural', 'travel-agency-platform'),
            'playa'       => __('Playa', 'travel-agency-platform'),
            'gastronomia' => __('Gastronómico', 'travel-agency-platform'),
            'relax'       => __('Relax', 'travel-agency-platform'),
            'aereo'       => __('Aéreo', 'travel-agency-platform'),
            'nautico'     => __('Náutico', 'travel-agency-platform'),
            'deportivo'   => __('Deportivo', 'travel-agency-platform'),
            'familiar'    => __('Familiar', 'travel-agency-platform'),
            'avistamiento'=> __('Avistamiento de fauna', 'travel-agency-platform'),
            'senderismo'  => __('Senderismo', 'travel-agency-platform'),
        ];
        foreach ($terms as $slug => $name) {
            if (!term_exists($slug, 'tap_tour_type')) {
                wp_insert_term($name, 'tap_tour_type', ['slug' => $slug]);
            }
        }
    }

    /**
     * One-time migration: `_tap_tour_difficulty` post-meta (old approach) → taxonomy term.
     */
    public static function migrate_tour_difficulty() {
        if (get_option('tap_difficulty_migrated')) {
            return;
        }
        $posts = get_posts([
            'post_type'      => 'tap_tour',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tap_tour_difficulty',
            'fields'         => 'ids',
        ]);
        foreach ($posts as $post_id) {
            $value = get_post_meta($post_id, '_tap_tour_difficulty', true);
            $term  = $value && term_exists($value, 'tap_tour_difficulty') ? $value : '';
            if ($term) {
                $term_id = (int) (is_array($tt = term_exists($term, 'tap_tour_difficulty')) ? $tt['term_id'] : $tt);
                wp_set_object_terms($post_id, [$term_id], 'tap_tour_difficulty', false);
            }
        }
        update_option('tap_difficulty_migrated', 1);
    }
}
