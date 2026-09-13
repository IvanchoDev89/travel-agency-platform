<?php
defined('ABSPATH') || exit;

class TAP_Post_Types {
    public static function register() {
        self::register_agency();
        self::register_accommodation();
        self::register_room();
        self::register_tour();
        self::register_transport();
        self::register_car_rental();
        self::register_boat();
        self::register_package();
        self::register_equipment();
    }

    private static function register_agency() {
        $labels = [
            'name'               => __('Agencies', 'travel-agency-platform'),
            'singular_name'      => __('Agency', 'travel-agency-platform'),
            'add_new'            => __('Add Agency', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Agency', 'travel-agency-platform'),
            'edit_item'          => __('Edit Agency', 'travel-agency-platform'),
            'view_item'          => __('View Agency', 'travel-agency-platform'),
            'search_items'       => __('Search Agencies', 'travel-agency-platform'),
            'not_found'          => __('No agencies found', 'travel-agency-platform'),
            'all_items'          => __('All Agencies', 'travel-agency-platform'),
        ];

        register_post_type('tap_agency', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-building',
            'menu_position' => 20,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'agency'],
            'capability_type' => ['tap_agency', 'tap_agencies'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_accommodation() {
        $labels = [
            'name'               => __('Accommodations', 'travel-agency-platform'),
            'singular_name'      => __('Accommodation', 'travel-agency-platform'),
            'add_new'            => __('Add Accommodation', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Accommodation', 'travel-agency-platform'),
            'edit_item'          => __('Edit Accommodation', 'travel-agency-platform'),
            'view_item'          => __('View Accommodation', 'travel-agency-platform'),
            'search_items'       => __('Search Accommodations', 'travel-agency-platform'),
            'not_found'          => __('No accommodations found', 'travel-agency-platform'),
            'all_items'          => __('All Accommodations', 'travel-agency-platform'),
        ];

        register_post_type('tap_accommodation', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-building',
            'menu_position' => 21,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'accommodation'],
            'capability_type' => ['tap_accommodation', 'tap_accommodations'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_room() {
        $labels = [
            'name'               => __('Room Types', 'travel-agency-platform'),
            'singular_name'      => __('Room Type', 'travel-agency-platform'),
            'add_new'            => __('Add Room Type', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Room Type', 'travel-agency-platform'),
            'edit_item'          => __('Edit Room Type', 'travel-agency-platform'),
            'view_item'          => __('View Room Type', 'travel-agency-platform'),
            'search_items'       => __('Search Room Types', 'travel-agency-platform'),
            'not_found'          => __('No room types found', 'travel-agency-platform'),
            'all_items'          => __('All Room Types', 'travel-agency-platform'),
        ];

        register_post_type('tap_room', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => false,
            'show_in_menu' => 'edit.php?post_type=tap_accommodation',
            'menu_icon'    => 'dashicons-admin-home',
            'menu_position' => 1,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes'],
            'rewrite'      => ['slug' => 'room-type'],
            'capability_type' => ['tap_room', 'tap_rooms'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_tour() {
        $labels = [
            'name'               => __('Tours', 'travel-agency-platform'),
            'singular_name'      => __('Tour', 'travel-agency-platform'),
            'add_new'            => __('Add Tour', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Tour', 'travel-agency-platform'),
            'edit_item'          => __('Edit Tour', 'travel-agency-platform'),
            'view_item'          => __('View Tour', 'travel-agency-platform'),
            'search_items'       => __('Search Tours', 'travel-agency-platform'),
            'not_found'          => __('No tours found', 'travel-agency-platform'),
            'all_items'          => __('All Tours', 'travel-agency-platform'),
        ];

        register_post_type('tap_tour', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-palmtree',
            'menu_position' => 22,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'tour'],
            'capability_type' => ['tap_tour', 'tap_tours'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_transport() {
        $labels = [
            'name'               => __('Transports', 'travel-agency-platform'),
            'singular_name'      => __('Transport', 'travel-agency-platform'),
            'add_new'            => __('Add Transport', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Transport', 'travel-agency-platform'),
            'edit_item'          => __('Edit Transport', 'travel-agency-platform'),
            'view_item'          => __('View Transport', 'travel-agency-platform'),
            'search_items'       => __('Search Transports', 'travel-agency-platform'),
            'not_found'          => __('No transports found', 'travel-agency-platform'),
            'all_items'          => __('All Transports', 'travel-agency-platform'),
        ];

        register_post_type('tap_transport', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-car',
            'menu_position' => 23,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'transport'],
            'capability_type' => ['tap_transport', 'tap_transports'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_car_rental() {
        $labels = [
            'name'               => __('Car Rentals', 'travel-agency-platform'),
            'singular_name'      => __('Car Rental', 'travel-agency-platform'),
            'add_new'            => __('Add Car Rental', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Car Rental', 'travel-agency-platform'),
            'edit_item'          => __('Edit Car Rental', 'travel-agency-platform'),
            'view_item'          => __('View Car Rental', 'travel-agency-platform'),
            'search_items'       => __('Search Car Rentals', 'travel-agency-platform'),
            'not_found'          => __('No car rentals found', 'travel-agency-platform'),
            'all_items'          => __('All Car Rentals', 'travel-agency-platform'),
        ];

        register_post_type('tap_car_rental', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-car',
            'menu_position' => 24,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'car-rental'],
            'capability_type' => ['tap_car_rental', 'tap_car_rentals'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_boat() {
        $labels = [
            'name'               => __('Boats', 'travel-agency-platform'),
            'singular_name'      => __('Boat', 'travel-agency-platform'),
            'add_new'            => __('Add Boat', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Boat', 'travel-agency-platform'),
            'edit_item'          => __('Edit Boat', 'travel-agency-platform'),
            'view_item'          => __('View Boat', 'travel-agency-platform'),
            'search_items'       => __('Search Boats', 'travel-agency-platform'),
            'not_found'          => __('No boats found', 'travel-agency-platform'),
            'all_items'          => __('All Boats', 'travel-agency-platform'),
        ];

        register_post_type('tap_boat', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-money',
            'menu_position' => 25,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'boat'],
            'capability_type' => ['tap_boat', 'tap_boats'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_package() {
        $labels = [
            'name'               => __('Packages', 'travel-agency-platform'),
            'singular_name'      => __('Package', 'travel-agency-platform'),
            'add_new'            => __('Add Package', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Package', 'travel-agency-platform'),
            'edit_item'          => __('Edit Package', 'travel-agency-platform'),
            'view_item'          => __('View Package', 'travel-agency-platform'),
            'search_items'       => __('Search Packages', 'travel-agency-platform'),
            'not_found'          => __('No packages found', 'travel-agency-platform'),
            'all_items'          => __('All Packages', 'travel-agency-platform'),
        ];

        $package_supports = ['title', 'editor', 'thumbnail', 'custom-fields', 'comments'];

        register_post_type('tap_package', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-open-folder',
            'menu_position' => 26,
            'supports'     => $package_supports,
            'rewrite'      => ['slug' => 'package'],
            'capability_type' => ['tap_package', 'tap_packages'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    private static function register_equipment() {
        $labels = [
            'name'               => __('Equipment & Rentals', 'travel-agency-platform'),
            'singular_name'      => __('Equipment', 'travel-agency-platform'),
            'add_new'            => __('Add Equipment', 'travel-agency-platform'),
            'add_new_item'       => __('Add New Equipment', 'travel-agency-platform'),
            'edit_item'          => __('Edit Equipment', 'travel-agency-platform'),
            'view_item'          => __('View Equipment', 'travel-agency-platform'),
            'search_items'       => __('Search Equipment', 'travel-agency-platform'),
            'not_found'          => __('No equipment found', 'travel-agency-platform'),
            'all_items'          => __('All Equipment', 'travel-agency-platform'),
        ];

        register_post_type('tap_equipment', [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-hammer',
            'menu_position' => 27,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'rewrite'      => ['slug' => 'equipment'],
            'capability_type' => ['tap_equipment', 'tap_equipment_items'],
            'map_meta_cap' => true,
            'show_in_rest' => true,
        ]);
    }

    public static function get_service_types() {
        return [
            'tap_accommodation' => __('Accommodation', 'travel-agency-platform'),
            'tap_room'          => __('Room', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_transport'     => __('Transport', 'travel-agency-platform'),
            'tap_car_rental'    => __('Car Rental', 'travel-agency-platform'),
            'tap_boat'          => __('Boat', 'travel-agency-platform'),
            'tap_package'       => __('Package', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipment', 'travel-agency-platform'),
        ];
    }

    public static function get_post_type_slug($post_type) {
        $slugs = [
            'tap_agency'        => 'agency',
            'tap_accommodation'  => 'accommodation',
            'tap_room'          => 'room-type',
            'tap_tour'          => 'tour',
            'tap_transport'     => 'transport',
            'tap_car_rental'    => 'car-rental',
            'tap_boat'          => 'boat',
            'tap_package'       => 'package',
            'tap_equipment'     => 'equipment',
        ];
        return isset($slugs[$post_type]) ? $slugs[$post_type] : '';
    }

    /**
     * Resolve the per-type metadata prefix (without leading "_tap_").
     * Canonical map for all service types' meta keys.
     */
    public static function meta_prefix($post_type) {
        $prefixes = [
            'tap_accommodation' => 'acc',
            'tap_tour'          => 'tour',
            'tap_transport'     => 'trans',
            'tap_car_rental'    => 'car',
            'tap_boat'          => 'boat',
            'tap_package'       => 'pkg',
            'tap_equipment'     => 'eq',
        ];
        return isset($prefixes[$post_type]) ? $prefixes[$post_type] : '';
    }

    public static function get_accommodation_rooms($accommodation_id) {
        return get_posts([
            'post_type'      => 'tap_room',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tap_room_accommodation_id', 'value' => intval($accommodation_id)],
            ],
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);
    }

    public static function get_room_beds($room_id) {
        $beds = get_post_meta($room_id, '_tap_room_beds', true);
        if (is_string($beds)) $beds = json_decode($beds, true);
        return is_array($beds) ? $beds : [];
    }

    /** Bed types accepted for a room, keyed by machine name. */
    public static function bed_types() {
        return [
            'king'   => __('King', 'travel-agency-platform'),
            'queen'  => __('Queen', 'travel-agency-platform'),
            'double' => __('Double', 'travel-agency-platform'),
            'twin'   => __('Twin', 'travel-agency-platform'),
            'bunk'   => __('Bunk', 'travel-agency-platform'),
            'sofa'   => __('Sofa Bed', 'travel-agency-platform'),
            'crib'   => __('Crib', 'travel-agency-platform'),
            'murphy' => __('Murphy', 'travel-agency-platform'),
            'futon'  => __('Futon', 'travel-agency-platform'),
        ];
    }

    public static function get_room_amenities($room_id) {
        $amenities = get_post_meta($room_id, '_tap_room_amenities', true);
        if (is_string($amenities)) $amenities = json_decode($amenities, true);
        return is_array($amenities) ? $amenities : [];
    }

    /**
     * Resolve accommodation coordinates. Uses stored lat/lng if present,
     * otherwise geocodes the city+country via Nominatim and caches the result.
     */
    public static function get_accommodation_coords($accommodation_id) {
        $lat = get_post_meta($accommodation_id, '_tap_acc_lat', true);
        $lng = get_post_meta($accommodation_id, '_tap_acc_lng', true);

        if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
            return [str_replace(',', '.', $lat), str_replace(',', '.', $lng)];
        }

        $cached = get_post_meta($accommodation_id, '_tap_acc_geocoded', true);
        if ($cached) {
            $parts = explode(',', $cached);
            if (count($parts) === 2) return [$parts[0], $parts[1]];
        }

        $city = get_post_meta($accommodation_id, '_tap_acc_city', true);
        $country = get_post_meta($accommodation_id, '_tap_acc_country', true);
        if (!$city) return [null, null];

        $query = trim($city . ($country ? ', ' . $country : ''));
        $response = wp_remote_get('https://nominatim.openstreetmap.org/search', [
            'timeout' => 8,
            'user-agent' => 'TravelAgencyPlatform/' . TAP_VERSION . ' (contact@ivanchodev.com)',
            'body' => [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
            ],
        ]);

        if (is_wp_error($response)) return [null, null];
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (empty($data[0])) return [null, null];

        $found_lat = $data[0]['lat'] ?? null;
        $found_lng = $data[0]['lon'] ?? null;
        if ($found_lat === null || $found_lng === null) return [null, null];

        update_post_meta($accommodation_id, '_tap_acc_lat', $found_lat);
        update_post_meta($accommodation_id, '_tap_acc_lng', $found_lng);
        update_post_meta($accommodation_id, '_tap_acc_geocoded', $found_lat . ',' . $found_lng);

        return [$found_lat, $found_lng];
    }
}
