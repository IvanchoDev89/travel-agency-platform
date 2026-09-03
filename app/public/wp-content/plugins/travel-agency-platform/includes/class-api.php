<?php
defined('ABSPATH') || exit;

class TAP_API {
    public static function register_routes() {
        $namespace = 'tap/v1';

        register_rest_route($namespace, '/services', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_services'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/services/(?P<type>[a-z_]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_services_by_type'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/services/(?P<type>[a-z_]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_service_detail'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/agencies', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_agencies'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/agencies/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_agency_detail'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/search', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'search'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/booking', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create_booking'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route($namespace, '/booking/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_booking'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route($namespace, '/my-bookings', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'my_bookings'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route($namespace, '/locations', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_locations'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/reviews/(?P<type>[a-z_]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_reviews'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/review', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'submit_review'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route($namespace, '/availability/(?P<type>[a-z_]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_availability'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function check_auth() {
        return is_user_logged_in();
    }

    public static function get_services() {
        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $results = [];

        foreach ($types as $type) {
            $posts = get_posts([
                'post_type'      => $type,
                'posts_per_page' => 10,
                'post_status'    => 'publish',
                'meta_query'     => [
                    ['key' => '_tap_' . str_replace('tap_', '', $type) . '_is_active', 'value' => '1'],
                ],
            ]);

            foreach ($posts as $post) {
                $results[] = self::format_service($post, $type);
            }
        }

        return rest_ensure_response($results);
    }

    public static function get_services_by_type($request) {
        $type = $request->get_param('type');
        $valid_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        if (!in_array($type, $valid_types)) {
            return new WP_Error('invalid_type', __('Invalid service type', 'travel-agency-platform'), ['status' => 400]);
        }

        $per_page = $request->get_param('per_page') ?: 12;
        $paged = $request->get_param('page') ?: 1;
        $location = $request->get_param('location');

        $args = [
            'post_type'      => $type,
            'posts_per_page' => intval($per_page),
            'paged'          => intval($paged),
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tap_' . str_replace('tap_', '', $type) . '_is_active', 'value' => '1'],
            ],
        ];

        if ($location) {
            $args['tax_query'] = [
                ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => intval($location)],
            ];
        }

        $query = new WP_Query($args);
        $services = [];

        foreach ($query->posts as $post) {
            $services[] = self::format_service($post, $type);
        }

        return rest_ensure_response([
            'services'   => $services,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'current'    => intval($paged),
        ]);
    }

    public static function get_service_detail($request) {
        $type = $request->get_param('type');
        $id = $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== $type || $post->post_status !== 'publish') {
            return new WP_Error('not_found', __('Service not found', 'travel-agency-platform'), ['status' => 404]);
        }

        return rest_ensure_response(self::format_service($post, $type, true));
    }

    public static function get_agencies() {
        $agencies = get_posts([
            'post_type'      => 'tap_agency',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tap_agency_verified', 'value' => '1'],
            ],
        ]);

        $result = [];
        foreach ($agencies as $agency) {
            $result[] = self::format_agency($agency);
        }

        return rest_ensure_response($result);
    }

    public static function get_agency_detail($request) {
        $id = $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'tap_agency') {
            return new WP_Error('not_found', __('Agency not found', 'travel-agency-platform'), ['status' => 404]);
        }

        $agency = self::format_agency($post, true);

        $service_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $services = [];

        foreach ($service_types as $st) {
            $prefix = '_tap_' . str_replace('tap_', '', $st) . '_agency_id';
            $posts = get_posts([
                'post_type'      => $st,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_key'       => $prefix,
                'meta_value'     => $id,
            ]);

            foreach ($posts as $p) {
                $services[] = self::format_service($p, $st);
            }
        }

        $agency['services'] = $services;

        return rest_ensure_response($agency);
    }

    public static function search($request) {
        $keyword = $request->get_param('keyword');
        $type = $request->get_param('type');
        $location = $request->get_param('location');
        $check_in = $request->get_param('check_in');
        $check_out = $request->get_param('check_out');
        $guests = $request->get_param('guests');
        $min_price = $request->get_param('min_price');
        $max_price = $request->get_param('max_price');

        $types = $type ? [$type] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        $results = [];

        foreach ($types as $pt) {
            $args = [
                'post_type'      => $pt,
                'posts_per_page' => 20,
                'post_status'    => 'publish',
                's'              => $keyword,
                'meta_query'     => [
                    ['key' => '_tap_' . str_replace('tap_', '', $pt) . '_is_active', 'value' => '1'],
                ],
            ];

            if ($location) {
                $args['tax_query'] = [
                    ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => intval($location)],
                ];
            }

            if ($min_price) {
                $price_key = self::get_price_key($pt);
                if ($price_key) {
                    $args['meta_query'][] = ['key' => $price_key, 'value' => floatval($min_price), 'type' => 'NUMERIC', 'compare' => '>='];
                }
            }

            if ($max_price) {
                $price_key = self::get_price_key($pt);
                if ($price_key) {
                    $args['meta_query'][] = ['key' => $price_key, 'value' => floatval($max_price), 'type' => 'NUMERIC', 'compare' => '<='];
                }
            }

            $query = new WP_Query($args);

            foreach ($query->posts as $post) {
                $results[] = self::format_service($post, $pt);
            }
        }

        return rest_ensure_response([
            'results' => $results,
            'total'   => count($results),
        ]);
    }

    public static function create_booking($request) {
        $params = $request->get_params();

        $required = ['service_type', 'service_id', 'total_amount'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', sprintf(__('Field %s is required', 'travel-agency-platform'), $field), ['status' => 400]);
            }
        }

        $booking = TAP_Booking::create($params);

        if (is_wp_error($booking)) {
            return $booking;
        }

        return rest_ensure_response($booking);
    }

    public static function get_booking($request) {
        $booking = TAP_Booking::get_booking($request->get_param('id'));

        if (!$booking) {
            return new WP_Error('not_found', __('Booking not found', 'travel-agency-platform'), ['status' => 404]);
        }

        if ($booking->client_id !== get_current_user_id() && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('Access denied', 'travel-agency-platform'), ['status' => 403]);
        }

        $booking->items = TAP_Booking::get_booking_items($booking->id);

        return rest_ensure_response($booking);
    }

    public static function my_bookings() {
        $user_id = get_current_user_id();
        $bookings = TAP_Booking::get_client_bookings($user_id);

        foreach ($bookings as &$booking) {
            $service = get_post($booking->service_id);
            $booking->service_title = $service ? $service->post_title : 'N/A';
            $booking->items = TAP_Booking::get_booking_items($booking->id);
        }

        return rest_ensure_response($bookings);
    }

    public static function get_locations() {
        $terms = get_terms([
            'taxonomy'   => 'tap_location',
            'hide_empty' => false,
            'orderby'    => 'name',
        ]);

        $locations = [];
        foreach ($terms as $term) {
            $locations[] = [
                'id'       => $term->term_id,
                'name'     => $term->name,
                'slug'     => $term->slug,
                'count'    => $term->count,
                'parent'   => $term->parent,
            ];
        }

        return rest_ensure_response($locations);
    }

    public static function get_reviews($request) {
        global $wpdb;
        $type = $request->get_param('type');
        $id = $request->get_param('id');

        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as user_name, u.user_email
            FROM {$wpdb->prefix}tap_reviews r
            JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE r.service_type = %s AND r.service_id = %d AND r.is_approved = 1
            ORDER BY r.created_at DESC",
            $type,
            $id
        ));

        return rest_ensure_response($reviews);
    }

    public static function submit_review($request) {
        global $wpdb;

        $params = $request->get_params();
        $required = ['service_type', 'service_id', 'rating', 'content'];

        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', sprintf(__('Field %s is required', 'travel-agency-platform'), $field), ['status' => 400]);
            }
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d AND service_type = %s AND service_id = %d",
            get_current_user_id(),
            $params['service_type'],
            $params['service_id']
        ));

        if ($existing > 0) {
            return new WP_Error('duplicate', __('You already reviewed this service', 'travel-agency-platform'), ['status' => 409]);
        }

        $wpdb->insert(
            $wpdb->prefix . 'tap_reviews',
            [
                'service_type' => sanitize_text_field($params['service_type']),
                'service_id'   => intval($params['service_id']),
                'user_id'      => get_current_user_id(),
                'booking_id'   => !empty($params['booking_id']) ? intval($params['booking_id']) : null,
                'rating'       => floatval($params['rating']),
                'title'        => !empty($params['title']) ? sanitize_text_field($params['title']) : '',
                'content'      => sanitize_textarea_field($params['content']),
                'is_approved'  => 0,
            ]
        );

        return rest_ensure_response([
            'message' => __('Review submitted and pending approval', 'travel-agency-platform'),
            'review_id' => $wpdb->insert_id,
        ]);
    }

    public static function get_availability($request) {
        global $wpdb;
        $type = $request->get_param('type');
        $id = $request->get_param('id');
        $from = $request->get_param('from');
        $to = $request->get_param('to');

        $where = "service_type = %s AND service_id = %d";
        $params = [$type, $id];

        if ($from) {
            $where .= " AND date >= %s";
            $params[] = $from;
        }
        if ($to) {
            $where .= " AND date <= %s";
            $params[] = $to;
        }

        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_availability WHERE $where ORDER BY date ASC",
            $params
        ));

        return rest_ensure_response($availability);
    }

    private static function format_service($post, $type, $detailed = false) {
        $prefix = '_tap_' . str_replace('tap_', '', $type);
        $price_key = self::get_price_key($type);

        $data = [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'slug'          => $post->post_slug ?? $post->post_name,
            'type'          => $type,
            'type_label'    => TAP_Post_Types::get_service_types()[$type] ?? $type,
            'excerpt'       => get_the_excerpt($post),
            'thumbnail'     => get_the_post_thumbnail_url($post->ID, 'medium'),
            'thumbnail_large' => get_the_post_thumbnail_url($post->ID, 'large'),
            'permalink'     => get_permalink($post->ID),
            'price'         => $price_key ? self::get_meta_float($post->ID, $price_key) : 0,
            'currency'      => get_post_meta($post->ID, $prefix . '_currency', true) ?: 'USD',
            'is_active'     => get_post_meta($post->ID, $prefix . '_is_active', true) === '1',
            'agency_id'     => intval(get_post_meta($post->ID, $prefix . '_agency_id', true)),
            'created_at'    => $post->post_date,
        ];

        $locations = wp_get_post_terms($post->ID, 'tap_location');
        $data['locations'] = [];
        foreach ($locations as $loc) {
            $data['locations'][] = ['id' => $loc->term_id, 'name' => $loc->name, 'slug' => $loc->slug];
        }

        $cats = wp_get_post_terms($post->ID, 'tap_service_cat');
        $data['categories'] = [];
        foreach ($cats as $cat) {
            $data['categories'][] = ['id' => $cat->term_id, 'name' => $cat->name];
        }

        if (!$detailed) {
            return $data;
        }

        $data['content'] = apply_filters('the_content', $post->post_content);
        $data['meta'] = self::get_all_meta($post->ID, $prefix);

        if ($agency_id = $data['agency_id']) {
            $agency = get_post($agency_id);
            $data['agency'] = $agency ? [
                'id'    => $agency->ID,
                'name'  => $agency->post_title,
                'url'   => get_permalink($agency->ID),
            ] : null;
        }

        $data['average_rating'] = self::get_average_rating($type, $post->ID);
        $data['review_count'] = self::get_review_count($type, $post->ID);

        return $data;
    }

    private static function format_agency($post, $detailed = false) {
        $data = [
            'id'           => $post->ID,
            'name'         => $post->post_title,
            'slug'         => $post->post_name,
            'description'  => apply_filters('the_content', $post->post_content),
            'excerpt'      => get_the_excerpt($post),
            'logo'         => get_the_post_thumbnail_url($post->ID, 'medium'),
            'email'        => get_post_meta($post->ID, '_tap_agency_email', true),
            'phone'        => get_post_meta($post->ID, '_tap_agency_phone', true),
            'whatsapp'     => get_post_meta($post->ID, '_tap_agency_whatsapp', true),
            'website'      => get_post_meta($post->ID, '_tap_agency_website', true),
            'address'      => get_post_meta($post->ID, '_tap_agency_address', true),
            'city'         => get_post_meta($post->ID, '_tap_agency_city', true),
            'country'      => get_post_meta($post->ID, '_tap_agency_country', true),
            'commission'   => get_post_meta($post->ID, '_tap_agency_commission', true) ?: 10,
            'is_verified'  => get_post_meta($post->ID, '_tap_agency_verified', true) === '1',
            'permalink'    => get_permalink($post->ID),
        ];

        if ($detailed) {
            $data['service_count'] = self::count_agency_services($post->ID);
        }

        return $data;
    }

    public static function platform_support_phone() {
        return (string) get_option('tap_support_phone', '');
    }

    public static function get_price_key($type) {
        $keys = [
            'tap_accommodation' => '_tap_acc_price_per_night',
            'tap_tour'          => '_tap_tour_price_adult',
            'tap_transport'     => '_tap_trans_price',
            'tap_car_rental'    => '_tap_car_price_per_day',
            'tap_boat'          => '_tap_boat_price_half',
            'tap_package'       => '_tap_pkg_price',
        ];
        return $keys[$type] ?? null;
    }

    private static function get_all_meta($post_id, $prefix) {
        global $wpdb;
        $metas = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $post_id,
            $prefix . '%'
        ));

        $result = [];
        foreach ($metas as $meta) {
            $key = str_replace($prefix . '_', '', $meta->meta_key);
            $result[$key] = maybe_unserialize($meta->meta_value);
        }

        return $result;
    }

    private static function get_meta_float($post_id, $key) {
        return floatval(get_post_meta($post_id, $key, true));
    }

    private static function count_agency_services($agency_id) {
        $count = 0;
        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        foreach ($types as $type) {
            $prefix = '_tap_' . str_replace('tap_', '', $type) . '_agency_id';
            $count += count(get_posts([
                'post_type'      => $type,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'meta_key'       => $prefix,
                'meta_value'     => $agency_id,
            ]));
        }

        return $count;
    }

    public static function get_rating_stats($type, $service_id) {
        global $wpdb;
        $key   = 'tap_rating_' . $type . '_' . (int) $service_id;
        $cached = wp_cache_get($key, 'tap_ratings');
        if (false !== $cached) {
            return $cached;
        }
        $stats = [
            'avg'   => floatval($wpdb->get_var($wpdb->prepare(
                "SELECT AVG(rating) FROM {$wpdb->prefix}tap_reviews WHERE service_type = %s AND service_id = %d AND is_approved = 1",
                $type, $service_id
            ))),
            'count' => intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE service_type = %s AND service_id = %d AND is_approved = 1",
                $type, $service_id
            ))),
        ];
        wp_cache_set($key, $stats, 'tap_ratings', 300);
        return $stats;
    }

    public static function get_favorites($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) {
            return [];
        }
        $favorites = get_user_meta($user_id, 'tap_favorites', true);
        return is_array($favorites) ? array_map('intval', $favorites) : [];
    }

    public static function is_favorite($post_id) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }
        return in_array((int) $post_id, self::get_favorites($user_id), true);
    }

    private static function get_average_rating($type, $service_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT AVG(rating) FROM {$wpdb->prefix}tap_reviews WHERE service_type = %s AND service_id = %d AND is_approved = 1",
            $type,
            $service_id
        )));
    }

    private static function get_review_count($type, $service_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE service_type = %s AND service_id = %d AND is_approved = 1",
            $type,
            $service_id
        )));
    }
}
