<?php
defined('ABSPATH') || exit;

/**
 * Read-only public AJAX handlers.
 *
 * These endpoints are reachable by logged-out visitors (wp_ajax_nopriv_*) and
 * only read data: search, autocomplete, room availability and public pricing.
 * Keeping them apart from TAP_Ajax makes the state-changing handlers easier to
 * audit (every write stays in one file).
 */
class TAP_Ajax_Public {

    /** Reject a visitor who hammered a public read-only endpoint (5 min window). */
    private static function throttle($bucket, $limit) {
        if (TAP_Rate_Limit::too_many($bucket, $limit, 5 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(['message' => __('Demasiadas solicitudes. Esperá unos minutos e intentá de nuevo.', 'travel-agency-platform')], 429);
        }
    }

    public static function search_services() {
        check_ajax_referer('tap_nonce', 'nonce');
        self::throttle('search', 60);
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $type = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));
        $location = intval($_POST['location'] ?? 0);
        $difficulty = sanitize_title(wp_unslash($_POST['difficulty'] ?? ''));
        $tour_type = sanitize_title(wp_unslash($_POST['tour_type'] ?? ''));
        $check_in = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $guests = intval($_POST['guests'] ?? 1);

        if ($difficulty || $tour_type) {
            $type = 'tap_tour';
        }

        $types = $type ? [$type] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
        $results = [];

        foreach ($types as $pt) {
            $type_keys = TAP_Promotions::keys_for_type($pt);
            $args = [
                'post_type'      => $pt,
                'posts_per_page' => 20,
                'post_status'    => 'publish',
                's'              => $keyword,
                'meta_query'     => [
                    ['key' => $type_keys['active_key'], 'value' => '1'],
                ],
            ];

            $args = TAP_Approval::exclude_from_query($args, [$pt]);

            $tax_query = [];
            if ($location) {
                $tax_query[] = ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => $location];
            }
            if ($difficulty && $pt === 'tap_tour' && term_exists($difficulty, 'tap_tour_difficulty')) {
                $tax_query[] = ['taxonomy' => 'tap_tour_difficulty', 'field' => 'slug', 'terms' => $difficulty];
            }
            if ($tour_type && $pt === 'tap_tour' && term_exists($tour_type, 'tap_tour_type')) {
                $tax_query[] = ['taxonomy' => 'tap_tour_type', 'field' => 'slug', 'terms' => $tour_type];
            }
            if (!empty($tax_query)) {
                $args['tax_query'] = $tax_query;
            }

            $query = new WP_Query($args);

            foreach ($query->posts as $post) {
                $results[] = [
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'type'      => $pt,
                    'type_name' => TAP_Post_Types::get_service_types()[$pt] ?? $pt,
                    'permalink' => get_permalink($post->ID),
                    'thumbnail' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
                    'excerpt'   => get_the_excerpt($post),
                ];
            }
        }

        usort($results, function ($a, $b) {
            $fa = (int) TAP_Promotions::is_featured($a['id']);
            $fb = (int) TAP_Promotions::is_featured($b['id']);
            return $fb - $fa;
        });

        wp_send_json_success([
            'results' => $results,
            'count'   => count($results),
        ]);
    }


    public static function calculate_booking_total() {
        check_ajax_referer('tap_nonce', 'nonce');
        self::throttle('pricing', 120);
        $service_id   = intval($_POST['service_id'] ?? 0);
        $service_type = sanitize_text_field(wp_unslash($_POST['service_type'] ?? ''));
        $room_id      = intval($_POST['room_id'] ?? 0);
        $check_in     = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out    = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $adults       = intval($_POST['adults'] ?? 1);
        $children     = intval($_POST['children'] ?? 0);

        if (!$service_id || !$service_type) {
            wp_send_json_error(['message' => __('Invalid request', 'travel-agency-platform')]);
        }

        $total  = 0;
        $nights = 0;
        $min_stay = 1;
        $price_breakdown = [];
        $base_total = 0;
        $savings = 0;
        $discounts = [];

        if ($check_in && $check_out) {
            $d1 = new DateTime($check_in);
            $d2 = new DateTime($check_out);
            $nights = max(1, $d1->diff($d2)->days);
        }

        if ($service_type === 'tap_accommodation' && $room_id && class_exists('TAP_Pricing')) {
            // A single get_quote() drives both the price and the breakdown:
            // it was being fetched twice with the same arguments, the second
            // call simply overwriting the first.
            $quote = TAP_Pricing::get_quote($room_id, $check_in, $check_out);
            if ($quote['available']) {
                $base_total = $quote['base_total'];
                $total      = $quote['total'];
                $savings    = $quote['savings'];
                $discounts  = $quote['discounts'];
            } else {
                $total      = TAP_Booking_Pricing::calculate_price($service_type, $service_id, $check_in, $check_out, $adults, $children, $room_id);
                $base_total = $total;
            }
            $range_result = TAP_Pricing::get_prices_for_range($room_id, $check_in, $check_out);
            if ($range_result['available']) {
                $price_breakdown = $range_result['prices'];
            }
            $min_stay = TAP_Pricing::get_max_min_stay_for_range($room_id, $check_in, $check_out);
        } else {
            $total      = TAP_Booking_Pricing::calculate_price($service_type, $service_id, $check_in, $check_out, $adults, $children, $room_id);
            $base_total = $total;
        }

        $fee = TAP_Booking::get_booking_fee($total);

        wp_send_json_success([
            'total'            => round($total + $fee, 2),
            'subtotal'         => round($total, 2),
            'fee'              => $fee,
            'base_total'       => $base_total,
            'savings'          => $savings,
            'discounts'        => $discounts,
            'nights'           => $nights,
            'min_stay'         => $min_stay,
            'price_breakdown'  => $price_breakdown,
            'capacity'         => $service_type === 'tap_tour' && $check_in ? TAP_Booking::tour_slots($service_id, $check_in) : null,
        ]);
    }


    public static function get_public_pricing() {
        check_ajax_referer('tap_nonce', 'nonce');
        self::throttle('pricing', 120);
        $year     = intval($_POST['year'] ?? 0);
        $month    = intval($_POST['month'] ?? 0);
        $single   = intval($_POST['room_id'] ?? 0);
        $room_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['room_ids'] ?? ''))), function ($id) {
            return $id > 0;
        })));
        if ($single) {
            $room_ids[] = $single;
        }
        $room_ids = array_values(array_unique($room_ids));

        if (!$year || !$month || empty($room_ids)) {
            wp_send_json_error(['message' => __('Parámetros inválidos', 'travel-agency-platform')]);
        }

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($room_ids), '%d'));
        $pricing_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT room_id, date, price, min_stay, is_blocked, label FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id IN ($placeholders) AND date BETWEEN %s AND %s ORDER BY room_id, date",
            array_merge($room_ids, [$start, $end])
        ));

        $overrides = [];
        foreach ($pricing_rows as $r) {
            $overrides[$r->room_id][$r->date] = [
                'price'      => $r->price !== null ? (float) $r->price : null,
                'min_stay'   => $r->min_stay !== null ? (int) $r->min_stay : null,
                'is_blocked' => (int) $r->is_blocked,
                'label'      => $r->label,
            ];
        }

        // Prefetch base price / min-stay for every requested room in one pass.
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key IN ('_tap_room_price_per_night', '_tap_room_min_stay')",
            $room_ids
        ));
        $base_meta = [];
        foreach ($meta_rows as $m) {
            $base_meta[$m->post_id][$m->meta_key] = $m->meta_value;
        }

        $out = [];
        foreach ($room_ids as $rid) {
            $base_price = isset($base_meta[$rid]['_tap_room_price_per_night']) ? (float) $base_meta[$rid]['_tap_room_price_per_night'] : 0;
            $base_min   = isset($base_meta[$rid]['_tap_room_min_stay']) ? (int) $base_meta[$rid]['_tap_room_min_stay'] : 1;
            $days = [];
            for ($d = 1; $d <= $days_in_month; $d++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $has = isset($overrides[$rid][$date]);
                $day = $has ? $overrides[$rid][$date] : [];
                $days[] = [
                    'date'       => $date,
                    'day'        => $d,
                    'price'      => $has && $day['price'] !== null ? $day['price'] : $base_price,
                    'is_blocked' => $has ? $day['is_blocked'] : 0,
                    'label'      => $has ? $day['label'] : '',
                ];
            }
            $out[$rid] = [
                'days'       => $days,
                'base_price' => $base_price,
                'base_min'   => $base_min,
                'month'      => $month,
                'year'       => $year,
            ];
        }

        if ($single) {
            // Backward-compatible single-room response.
            wp_send_json_success($out[$single]);
        }
        wp_send_json_success(['rooms' => $out, 'month' => $month, 'year' => $year]);
    }


    public static function search_suggestions() {
        check_ajax_referer('tap_nonce', 'nonce');
        self::throttle('search', 60);
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        if (strlen($keyword) < 2) wp_send_json_success(['results' => []]);

        $results = [];

        // Accommodations
        $acc = get_posts([
            'post_type' => 'tap_accommodation',
            'post_status' => 'publish',
            'posts_per_page' => 6,
            's' => $keyword,
            'meta_query' => [['key' => '_tap_acc_is_active', 'value' => '1']],
        ]);
        foreach ($acc as $p) {
            $city = get_post_meta($p->ID, '_tap_acc_city', true);
            $results[] = [
                'type' => __('Alojamiento', 'travel-agency-platform'),
                'label' => $p->post_title . ($city ? ", $city" : ''),
                'url' => get_permalink($p->ID),
                'img' => get_the_post_thumbnail_url($p->ID, 'thumbnail'),
            ];
        }

        // Cities (from accommodation meta)
        global $wpdb;
        $cities = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_tap_acc_city' AND p.post_status = 'publish' AND p.post_type = 'tap_accommodation'
            AND meta_value LIKE %s LIMIT 4", $wpdb->esc_like($keyword) . '%'
        ));
        foreach ($cities as $city) {
            $results[] = [
                'type' => __('Destino', 'travel-agency-platform'),
                'label' => $city,
                'url' => get_post_type_archive_link('tap_accommodation') . '?keyword=' . urlencode($city),
                'img' => '',
            ];
        }

        // Tours, transports, etc.
        foreach (['tap_tour' => 'Tour', 'tap_car_rental' => 'Auto'] as $pt => $label) {
            $posts = get_posts(TAP_Approval::exclude_from_query([
                'post_type' => $pt,
                'post_status' => 'publish',
                'posts_per_page' => 3,
                's' => $keyword,
            ], [$pt]));
            foreach ($posts as $p) {
                $results[] = [
                    'type' => $label,
                    'label' => $p->post_title,
                    'url' => get_permalink($p->ID),
                    'img' => get_the_post_thumbnail_url($p->ID, 'thumbnail'),
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }


    public static function get_rooms() {
        check_ajax_referer('tap_nonce', 'nonce');
        self::throttle('pricing', 120);
        $accommodation_id = intval($_POST['accommodation_id'] ?? 0);
        $check_in  = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $adults    = intval($_POST['adults'] ?? 1);

        if (!$accommodation_id) {
            wp_send_json_error(['message' => __('Invalid accommodation', 'travel-agency-platform')]);
        }

        $rooms = TAP_Booking_Pricing::get_rooms_with_availability($accommodation_id, $check_in, $check_out, $adults);
        wp_send_json_success(['rooms' => $rooms]);
    }
}
