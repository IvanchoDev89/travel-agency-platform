<?php
defined('ABSPATH') || exit;

class TAP_Ajax {
    public static function create_booking() {
        // Two front-end booking forms historically send distinct nonces:
        // the accommodation template sends a 'tap_booking_nonce' nonce, while the
        // [tap_booking_form] shortcode sends tap_ajax.nonce (action 'tap_nonce').
        // Accept either to keep both forms working.
        $raw = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        $valid = $raw !== '' && (wp_verify_nonce($raw, 'tap_nonce') || wp_verify_nonce($raw, 'tap_booking_nonce'));
        if (!$valid) {
            wp_send_json_error(['message' => __('Security check failed.', 'travel-agency-platform')], 403);
        }

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['fake' => true]);
        }

        // Guest checkout: logged-out visitors book with client_id = 0 and their
        // contact data in the guest_* columns. Emails are spam-guarded by a
        // per-IP+email rate limit, then a short-lived token allows paying on /checkout.
        $guest_name  = sanitize_text_field($_POST['guest_name'] ?? '');
        $guest_email = sanitize_email($_POST['guest_email'] ?? '');

        if (!is_user_logged_in()) {
            if ('' === $guest_name || !is_email($guest_email)) {
                wp_send_json_error(['message' => __('Indica tu nombre y un correo electrónico válido para completar la reserva.', 'travel-agency-platform')]);
            }

            if (self::guest_book_rate_blocked($guest_email)) {
                wp_send_json_error(['message' => __('Demasiadas reservas en poco tiempo. Intenta nuevamente más tarde.', 'travel-agency-platform')]);
            }
        }

        $required = ['service_type', 'service_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(['message' => sprintf(__('Field %s is required', 'travel-agency-platform'), $field)]);
            }
        }

        $room_id  = !empty($_POST['room_id']) ? intval($_POST['room_id']) : 0;
        $check_in = sanitize_text_field($_POST['check_in'] ?? '');
        $check_out = sanitize_text_field($_POST['check_out'] ?? '');
        $adults   = intval($_POST['adults'] ?? 1);
        $children = intval($_POST['children'] ?? 0);

        $total = TAP_Booking::calculate_price(
            sanitize_text_field($_POST['service_type']),
            intval($_POST['service_id']),
            $check_in, $check_out, $adults, $children, $room_id
        );

        $data = [
            'service_type' => sanitize_text_field($_POST['service_type']),
            'service_id'   => intval($_POST['service_id']),
            'room_id'      => $room_id,
            'check_in'     => $check_in,
            'check_out'    => $check_out,
            'adults'       => $adults,
            'children'     => $children,
            'total_amount' => $total,
            'notes'        => sanitize_textarea_field($_POST['notes'] ?? ''),
            'guest_name'   => sanitize_text_field($_POST['guest_name'] ?? ''),
            'guest_email'  => sanitize_email($_POST['guest_email'] ?? ''),
            'guest_phone'  => sanitize_text_field($_POST['guest_phone'] ?? ''),
        ];

        $result = TAP_Booking::create($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (!is_user_logged_in() && is_email($guest_email)) {
            self::guest_book_rate_bump($guest_email);
            self::set_guest_pay_token($result['booking_code']);
        }

        wp_send_json_success($result);
    }

    /* ===== Guest checkout helpers (testable, no die) ===== */

    public static function guest_bucket($email) {
        return 'tap_guest_book_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower((string) $email));
    }

    public static function guest_book_rate_blocked($email) {
        return (int) get_transient(self::guest_bucket((string) $email)) >= 5;
    }

    public static function guest_book_rate_bump($email) {
        $key = self::guest_bucket((string) $email);
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
    }

    public static function set_guest_pay_token($booking_code) {
        set_transient('tap_guest_pay_' . (string) $booking_code, 1, 30 * MINUTE_IN_SECONDS);
    }

    public static function get_guest_pay_token($booking_code) {
        return (bool) get_transient('tap_guest_pay_' . (string) $booking_code);
    }

    public static function cancel_booking() {
        check_ajax_referer('tap_booking_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('Reserva inválida.', 'travel-agency-platform')]);
        }

        $uid = get_current_user_id();
        $guest_email = $uid ? '' : sanitize_email($_POST['guest_email'] ?? '');

        $result = TAP_Booking::client_cancel_request($booking_id, $uid, $guest_email);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Tu reserva ha sido cancelada.', 'travel-agency-platform')]);
    }

    public static function search_services() {
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? '');
        $location = intval($_POST['location'] ?? 0);
        $difficulty = sanitize_title($_POST['difficulty'] ?? '');
        $tour_type = sanitize_title($_POST['tour_type'] ?? '');
        $check_in = sanitize_text_field($_POST['check_in'] ?? '');
        $check_out = sanitize_text_field($_POST['check_out'] ?? '');
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

    public static function update_booking_status() {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $status     = sanitize_text_field($_POST['status'] ?? '');

        $current_user = is_user_logged_in() ? wp_get_current_user() : null;
        $is_admin  = current_user_can('manage_options') || current_user_can('tap_manage_bookings');
        $is_agency = $current_user &&
            (in_array('tap_agency_admin', (array) $current_user->roles, true) ||
             in_array('tap_agency_employee', (array) $current_user->roles, true));

        if (!$is_admin && !$is_agency) {
            wp_send_json_error(['message' => __('Unauthorized', 'travel-agency-platform')]);
        }

        if ($is_agency && !$is_admin) {
            check_ajax_referer('tap_agency_nonce', 'nonce');

            $agency_id = TAP_Booking::get_agency_for_user($current_user->ID);
            if (!$agency_id) {
                wp_send_json_error(['message' => __('No agency linked to your account.', 'travel-agency-platform')]);
            }

            $booking = TAP_Booking::get_booking($booking_id);
            if (!$booking || intval($booking->agency_id) !== $agency_id) {
                wp_send_json_error(['message' => __('You can only manage bookings from your own agency.', 'travel-agency-platform')]);
            }

            if (!in_array($status, ['confirmed', 'completed', 'cancelled'], true)) {
                wp_send_json_error(['message' => __('Invalid status.', 'travel-agency-platform')]);
            }
        }

        $result = TAP_Booking::update_status($booking_id, $status);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Status updated', 'travel-agency-platform')]);
    }

    public static function create_paypal_order() {
        check_ajax_referer('tap_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking', 'travel-agency-platform')]);
        }

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        $uid = get_current_user_id();
        $owner_ok = $uid && (int) $booking->client_id === $uid;
        $guest_ok = !$uid && (int) $booking->client_id === 0 && self::get_guest_pay_token($booking->booking_code);
        if (!$owner_ok && !$guest_ok) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        if ($booking->payment_status === 'paid') {
            wp_send_json_error(['message' => __('Booking already paid', 'travel-agency-platform')]);
        }

        $return_url = home_url('/checkout?code=' . $booking->booking_code . '&status=success');
        $cancel_url = home_url('/checkout?code=' . $booking->booking_code . '&status=cancel');

        $result = TAP_PayPal::create_order($booking_id, $return_url, $cancel_url);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public static function capture_paypal_order() {
        check_ajax_referer('tap_nonce', 'nonce');

        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid PayPal order', 'travel-agency-platform')]);
        }

        global $wpdb;
        $booking_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
            $paypal_order_id
        ));

        if ($booking_id) {
            $booking = TAP_Booking::get_booking((int) $booking_id);
            $uid = get_current_user_id();
            $owner_ok = $uid && $booking && (int) $booking->client_id === $uid;
            $guest_ok = !$uid && $booking && (int) $booking->client_id === 0 && self::get_guest_pay_token($booking->booking_code);
            if ($booking && !$owner_ok && !$guest_ok) {
                wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
            }
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($booking_id && $result['capture_status'] === 'COMPLETED') {
            TAP_Booking::update_payment_status($booking_id, 'paid');
            TAP_Booking::update_status($booking_id, 'confirmed');
            update_post_meta($booking_id, '_tap_paypal_capture_id', $result['capture_id']);
            update_post_meta($booking_id, '_tap_payment_details', json_encode($result['full_response']));
        }

        wp_send_json_success([
            'status'       => $result['status'],
            'capture_id'   => $result['capture_id'],
            'booking_id'   => $booking_id,
            'message'      => __('Payment completed successfully!', 'travel-agency-platform'),
        ]);
    }

    public static function calculate_booking_total() {
        $service_id   = intval($_POST['service_id'] ?? 0);
        $service_type = sanitize_text_field($_POST['service_type'] ?? '');
        $room_id      = intval($_POST['room_id'] ?? 0);
        $check_in     = sanitize_text_field($_POST['check_in'] ?? '');
        $check_out    = sanitize_text_field($_POST['check_out'] ?? '');
        $adults       = intval($_POST['adults'] ?? 1);
        $children     = intval($_POST['children'] ?? 0);

        if (!$service_id || !$service_type) {
            wp_send_json_error(['message' => __('Invalid request', 'travel-agency-platform')]);
        }

        $total  = 0;
        $nights = 0;
        $min_stay = 1;

        if ($check_in && $check_out) {
            $d1 = new DateTime($check_in);
            $d2 = new DateTime($check_out);
            $nights = max(1, $d1->diff($d2)->days);
        }

        if ($service_type === 'tap_accommodation' && $room_id && class_exists('TAP_Pricing')) {
            $quote = TAP_Pricing::get_quote($room_id, $check_in, $check_out);
            if ($quote['available']) {
                $total = $quote['total'];
            } else {
                $total = TAP_Booking::calculate_price($service_type, $service_id, $check_in, $check_out, $adults, $children, $room_id);
            }
            $min_stay = TAP_Pricing::get_max_min_stay_for_range($room_id, $check_in, $check_out);
        } else {
            $total = TAP_Booking::calculate_price($service_type, $service_id, $check_in, $check_out, $adults, $children, $room_id);
        }

        $price_breakdown = [];
        $base_total = $total;
        $savings = 0;
        $discounts = [];
        if ($service_type === 'tap_accommodation' && $room_id && class_exists('TAP_Pricing')) {
            $range_result = TAP_Pricing::get_prices_for_range($room_id, $check_in, $check_out);
            if ($range_result['available']) {
                $price_breakdown = $range_result['prices'];
            }
            $quote = TAP_Pricing::get_quote($room_id, $check_in, $check_out);
            if ($quote['available']) {
                $base_total = $quote['base_total'];
                $total = $quote['total'];
                $savings = $quote['savings'];
                $discounts = $quote['discounts'];
            }
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
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $year    = (int) ($_POST['year'] ?? 0);
        $month   = (int) ($_POST['month'] ?? 0);

        if (!$room_id || !$year || !$month) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        $base_price = (float) get_post_meta($room_id, '_tap_room_price_per_night', true) ?: 0;
        $base_min   = (int) get_post_meta($room_id, '_tap_room_min_stay', true) ?: 1;
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date, price, min_stay, is_blocked, label FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date BETWEEN %s AND %s",
            $room_id, $start, $end
        ));

        $overrides = [];
        foreach ($rows as $r) {
            $overrides[$r->date] = [
                'price'      => $r->price !== null ? (float) $r->price : null,
                'min_stay'   => $r->min_stay !== null ? (int) $r->min_stay : null,
                'is_blocked' => (int) $r->is_blocked,
                'label'      => $r->label,
            ];
        }

        $days = [];
        for ($d = 1; $d <= $days_in_month; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $has = isset($overrides[$date]);
            $day = $has ? $overrides[$date] : [];
            $days[] = [
                'date'       => $date,
                'day'        => $d,
                'price'      => $has && $day['price'] !== null ? $day['price'] : $base_price,
                'is_blocked' => $has ? $day['is_blocked'] : 0,
                'label'      => $has ? $day['label'] : '',
            ];
        }

        wp_send_json_success([
            'days'       => $days,
            'base_price' => $base_price,
            'base_min'   => $base_min,
            'month'      => $month,
            'year'       => $year,
        ]);
    }

    public static function filter_archive_query($query) {
        if (is_admin() || !$query->is_main_query()) return;

        if ($query->is_search()) {
            $excluded = TAP_Approval::excluded_listing_ids();
            if (!empty($excluded)) {
                $query->set('post__not_in', $excluded);
            }
            return;
        }

        if (!$query->is_post_type_archive('tap_accommodation')) return;

        $meta_query = $query->get('meta_query', []);
        $tax_query = $query->get('tax_query', []);

        // Keyword search (keyword => WordPress search 's')
        $keyword = sanitize_text_field($_GET['keyword'] ?? '');
        if ($keyword !== '') {
            $query->set('s', $keyword);
        }

        // Stars filter
        $stars = intval($_GET['stars'] ?? 0);
        if ($stars > 0) {
            $meta_query[] = ['key' => '_tap_acc_stars', 'value' => $stars, 'type' => 'NUMERIC', 'compare' => '>='];
        }

        // Price range
        $min_price = floatval($_GET['min_price'] ?? 0);
        $max_price = floatval($_GET['max_price'] ?? 0);
        if ($min_price > 0 || $max_price > 0) {
            $price_q = ['key' => '_tap_acc_price_per_night', 'type' => 'NUMERIC'];
            if ($min_price > 0 && $max_price > 0) {
                $price_q['value'] = [$min_price, $max_price];
                $price_q['compare'] = 'BETWEEN';
            } elseif ($min_price > 0) {
                $price_q['value'] = $min_price;
                $price_q['compare'] = '>=';
            } else {
                $price_q['value'] = $max_price;
                $price_q['compare'] = '<=';
            }
            $meta_query[] = $price_q;
        }

        // Guest capacity
        $guests = intval($_GET['guests'] ?? 0);
        if ($guests > 0) {
            $meta_query[] = ['key' => '_tap_acc_capacity', 'value' => $guests, 'type' => 'NUMERIC', 'compare' => '>='];
        }

        // Property type taxonomy
        $type_slug = sanitize_text_field($_GET['type'] ?? '');
        if ($type_slug) {
            $tax_query[] = ['taxonomy' => 'tap_property_type', 'field' => 'slug', 'terms' => $type_slug];
        }

        // Amenities taxonomy
        $amenities = $_GET['amenities'] ?? [];
        if (!empty($amenities) && is_array($amenities)) {
            $tax_query[] = ['taxonomy' => 'tap_amenity', 'field' => 'slug', 'terms' => array_map('sanitize_text_field', $amenities), 'operator' => 'AND'];
        }

        // Filter active only
        $meta_query[] = ['key' => '_tap_acc_is_active', 'value' => '1'];

        $excluded = TAP_Approval::excluded_listing_ids();
        if (!empty($excluded)) {
            $query->set('post__not_in', $excluded);
        }

        // Sorting
        $sort = sanitize_text_field($_GET['sort'] ?? '');
        switch ($sort) {
            case 'price_asc':
                $query->set('orderby', 'meta_value_num');
                $query->set('meta_key', '_tap_acc_price_per_night');
                $query->set('order', 'ASC');
                break;
            case 'price_desc':
                $query->set('orderby', 'meta_value_num');
                $query->set('meta_key', '_tap_acc_price_per_night');
                $query->set('order', 'DESC');
                break;
            case 'name':
                $query->set('orderby', 'title');
                $query->set('order', 'ASC');
                break;
            case 'rating':
                $query->set('tap_sort_rating', 1);
                break;
        }

        $query->set('meta_query', $meta_query);
        if (!empty($tax_query)) $query->set('tax_query', $tax_query);
    }

    public static function rating_sort_clauses($clauses, $wp_query) {
        if (is_admin() || !$wp_query->is_main_query()) return $clauses;
        if (!$wp_query->is_post_type_archive('tap_accommodation')) return $clauses;
        if (!$wp_query->get('tap_sort_rating')) return $clauses;
        global $wpdb;
        $sub = "(SELECT AVG(r.rating) FROM {$wpdb->prefix}tap_reviews r WHERE r.service_type = 'tap_accommodation' AND r.service_id = {$wpdb->posts}.ID AND r.is_approved = 1)";
        $clauses['orderby'] = $sub . ' DESC, ' . $wpdb->posts . '.ID DESC';
        return $clauses;
    }

    public static function featured_sort_clauses($clauses, $wp_query) {
        if (is_admin() || !$wp_query->is_main_query()) return $clauses;

        $types = (array) $wp_query->get('post_type');
        $types = array_filter($types);
        if (empty($types) && $wp_query->is_search()) {
            $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
        }
        if (empty($types)) return $clauses;

        $guards = [];
        foreach ($types as $type) {
            $keys = class_exists('TAP_Promotions') ? TAP_Promotions::keys_for_type($type) : null;
            if (!$keys) continue;
            global $wpdb;
            $flag = "(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '{$keys['flag_key']}' LIMIT 1)";
            $until = "(SELECT pu.meta_value FROM {$wpdb->postmeta} pu WHERE pu.post_id = {$wpdb->posts}.ID AND pu.meta_key = '{$keys['until_key']}' LIMIT 1)";
            $guards[] = "(COALESCE({$flag}, '') = '1' AND COALESCE({$until}, '') >= CURDATE())";
        }
        if (empty($guards)) return $clauses;

        $featured = '(CASE WHEN ' . implode(' OR ', $guards) . " THEN 0 ELSE 1 END)";
        $clauses['orderby'] = $featured . ' ASC' . ($clauses['orderby'] ? ', ' . $clauses['orderby'] : '');
        return $clauses;
    }

    public static function search_suggestions() {
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
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
                'type' => 'Alojamiento',
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
            AND meta_value LIKE %s LIMIT 4", '%' . $wpdb->esc_like($keyword) . '%'
        ));
        foreach ($cities as $city) {
            $results[] = [
                'type' => 'Destino',
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
        $accommodation_id = intval($_POST['accommodation_id'] ?? 0);
        $check_in  = sanitize_text_field($_POST['check_in'] ?? '');
        $check_out = sanitize_text_field($_POST['check_out'] ?? '');
        $adults    = intval($_POST['adults'] ?? 1);

        if (!$accommodation_id) {
            wp_send_json_error(['message' => __('Invalid accommodation', 'travel-agency-platform')]);
        }

        $rooms = TAP_Booking::get_rooms_with_availability($accommodation_id, $check_in, $check_out, $adults);
        wp_send_json_success(['rooms' => $rooms]);
    }

    public static function agency_register() {
        check_ajax_referer('tap_register_nonce', 'nonce');

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['message' => __('Gracias por tu interés.', 'travel-agency-platform')]);
        }

        $fullname    = sanitize_text_field($_POST['agency_name'] ?? '');
        $username    = sanitize_user($_POST['username'] ?? '', true);
        $email       = sanitize_email($_POST['email'] ?? '');
        $password    = (string) ($_POST['password'] ?? '');
        $phone       = sanitize_text_field($_POST['phone'] ?? '');
        $whatsapp    = sanitize_text_field($_POST['whatsapp'] ?? '');
        $website     = esc_url_raw($_POST['website'] ?? '');
        $address     = sanitize_textarea_field($_POST['address'] ?? '');
        $city        = sanitize_text_field($_POST['city'] ?? '');
        $country     = sanitize_text_field($_POST['country'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');

        $kyc = [
            'legal_name'   => sanitize_text_field($_POST['kyc_legal_name'] ?? ''),
            'doc_type'     => in_array((string) ($_POST['kyc_doc_type'] ?? ''), ['fisica', 'juridica'], true) ? sanitize_text_field($_POST['kyc_doc_type']) : '',
            'doc_number'   => sanitize_text_field($_POST['kyc_doc_number'] ?? ''),
            'legal_tax_id' => sanitize_text_field($_POST['kyc_legal_tax_id'] ?? ''),
        ];
        $kyc_accept = !empty($_POST['kyc_accept']);

        if (empty($fullname)) {
            wp_send_json_error(['message' => __('Agency name is required.', 'travel-agency-platform')]);
        }
        if (empty($username) || !validate_username($username)) {
            wp_send_json_error(['message' => __('Please enter a valid username (letters and numbers only).', 'travel-agency-platform')]);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'travel-agency-platform')]);
        }
        if (strlen($password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters.', 'travel-agency-platform')]);
        }
        if (username_exists($username)) {
            wp_send_json_error(['message' => __('That username is already taken.', 'travel-agency-platform')]);
        }
        if (email_exists($email)) {
            wp_send_json_error(['message' => __('That email is already registered.', 'travel-agency-platform')]);
        }

        if (empty($kyc['legal_name'])) {
            wp_send_json_error(['message' => __('Indica el nombre del representante legal o de la empresa.', 'travel-agency-platform')]);
        }
        if (!in_array($kyc['doc_type'], ['fisica', 'juridica'], true) || empty($kyc['doc_number'])) {
            wp_send_json_error(['message' => __('Indica el tipo y número de documento (cédula).', 'travel-agency-platform')]);
        }
        if ($kyc['doc_type'] === 'juridica' && empty($kyc['legal_tax_id'])) {
            wp_send_json_error(['message' => __('Para persona jurídica indica la cédula jurídica.', 'travel-agency-platform')]);
        }
        if (!$kyc_accept) {
            wp_send_json_error(['message' => __('Debes aceptar los términos y condiciones.', 'travel-agency-platform')]);
        }

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $fullname,
            'first_name'   => $fullname,
            'role'         => 'tap_agency_admin',
            'user_url'     => $website,
        ]);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        $user_id = (int) $user_id;
        $agency_id = wp_insert_post([
            'post_type'    => 'tap_agency',
            'post_status'  => 'publish',
            'post_title'   => $fullname,
            'post_name'    => sanitize_title($username),
            'post_content' => $description,
        ]);

        if (is_wp_error($agency_id) || !$agency_id) {
            wp_delete_user($user_id);
            wp_send_json_error(['message' => __('Could not create the agency. Please try again.', 'travel-agency-platform')]);
        }

        $commission = get_option('tap_commission_default', 10);

        $metas = [
            '_tap_agency_user_id'    => $user_id,
            '_tap_agency_email'      => $email,
            '_tap_agency_phone'      => $phone,
            '_tap_agency_whatsapp'   => $whatsapp,
            '_tap_agency_website'    => $website,
            '_tap_agency_address'    => $address,
            '_tap_agency_city'       => $city,
            '_tap_agency_country'    => $country,
            '_tap_agency_commission' => $commission,
            '_tap_agency_verified'   => '',
            '_tap_agency_is_active'  => '0',
            '_tap_agency_status'     => TAP_Approval::PENDING,
        ];
        foreach ($metas as $key => $value) {
            update_post_meta($agency_id, $key, $value);
        }
        TAP_Approval::save_kyc($agency_id, $kyc);

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'tap_agencies',
            [
                'user_id'            => $user_id,
                'name'               => $fullname,
                'slug'               => get_post_field('post_name', $agency_id),
                'description'        => $description,
                'email'              => $email,
                'phone'              => $phone,
                'whatsapp'           => $whatsapp,
                'website'            => $website,
                'address'            => $address,
                'city'               => $city,
                'country'            => $country,
                'commission_percent' => $commission,
                'is_verified'        => 0,
                'is_active'          => 0,
            ]
        );

        do_action('tap_agency_registered', $user_id, $agency_id);

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        wp_send_json_success([
            'message'  => __('Tu agencia está en revisión. Te avisaremos por correo cuando sea aprobada.', 'travel-agency-platform'),
            'redirect' => home_url('/dashboard/'),
        ]);
    }

    public static function submit_lead() {
        check_ajax_referer('tap_lead_nonce', 'nonce');

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['message' => __('Gracias por tu mensaje.', 'travel-agency-platform')]);
        }

        $result = TAP_Leads::submit([
            'name'       => $_POST['tap_name'] ?? '',
            'email'      => $_POST['tap_email'] ?? '',
            'phone'      => $_POST['tap_phone'] ?? '',
            'message'    => $_POST['tap_message'] ?? '',
            'agency_id'  => (int) ($_POST['agency_id'] ?? 0),
            'service_id' => (int) ($_POST['service_id'] ?? 0),
            'source'     => isset($_POST['service_id']) && (int) $_POST['service_id'] > 0 ? 'service' : 'agency',
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Mensaje enviado. La agencia te contactará pronto.', 'travel-agency-platform')]);
    }

    public static function agency_subscribe() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can subscribe to a plan.', 'travel-agency-platform')]);
        }
        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        if (!$plan_id) {
            wp_send_json_error(['message' => __('Invalid plan.', 'travel-agency-platform')]);
        }
        $result = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'message' => __('Subscription requested. An administrator will confirm the payment to activate your plan.', 'travel-agency-platform'),
            'status'  => 'pending',
        ]);
    }

    /**
     * Subscribe to a paid plan and charge via PayPal. Creates the pending
     * subscription, then a PayPal Checkout v2 order for 1 month.
     */
    public static function subscribe_paypal() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        if (!TAP_PayPal::is_ready()) {
            wp_send_json_error(['message' => __('Online payment is not configured.', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can subscribe to a plan.', 'travel-agency-platform')]);
        }
        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        $plan    = class_exists('TAP_Subscriptions') ? TAP_Subscriptions::get_plan($plan_id) : null;
        if (!$plan || !(int) $plan->is_active || (float) $plan->price_monthly <= 0) {
            wp_send_json_error(['message' => __('Invalid plan.', 'travel-agency-platform')]);
        }

        $sub_id = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($sub_id)) {
            wp_send_json_error(['message' => $sub_id->get_error_message()]);
        }

        $result = TAP_PayPal::create_order_generic(
            (float) $plan->price_monthly,
            sprintf(__('%s — suscripción mensual (agencia)', 'travel-agency-platform'), $plan->name),
            'SUB-' . $sub_id,
            'subscription',
            (int) $sub_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'order_id' => $result['order_id'],
            'message'  => __('Completa el pago con PayPal para activar tu plan.', 'travel-agency-platform'),
        ]);
    }

    /** Capture a completed subscription PayPal order and mark the sub paid. */
    public static function capture_subscription_paypal() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid order', 'travel-agency-platform')]);
        }

        $order = TAP_Payment::resolve_order($paypal_order_id);
        if (!$order || 'subscription' !== $order->object_type) {
            wp_send_json_error(['message' => __('Order not found', 'travel-agency-platform')]);
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result['capture_status'] === 'COMPLETED') {
            TAP_Payment::record_order($paypal_order_id, 'subscription', (int) $order->object_id, $order->amount, 'completed', $result['capture_id']);
            TAP_Payment::confirm_subscription_payment((int) $order->object_id, 'paypal', $result['capture_id']);
            wp_send_json_success([
                'message' => __('Pago recibido. Tu plan está activo.', 'travel-agency-platform'),
                'status'  => 'paid',
            ]);
        }

        wp_send_json_error(['message' => __('The payment could not be completed.', 'travel-agency-platform')]);
    }

    /** Request a promotion and pay via PayPal. Creates the pending promo then an order. */
    public static function promo_request_paypal() {
        check_ajax_referer('tap_agency_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        if (!TAP_PayPal::is_ready()) {
            wp_send_json_error(['message' => __('Online payment is not configured.', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can promote listings.', 'travel-agency-platform')]);
        }
        $listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;
        $months     = isset($_POST['months']) ? max(1, min(24, intval($_POST['months']))) : 1;
        if (!$listing_id || !class_exists('TAP_Promotions')) {
            wp_send_json_error(['message' => __('Invalid listing.', 'travel-agency-platform')]);
        }
        if ((int) TAP_Promotions::agency_of_listing($listing_id) !== (int) $agency) {
            wp_send_json_error(['message' => __('You can only promote your own listings.', 'travel-agency-platform')]);
        }

        $promo_id = TAP_Promotions::request($agency, $listing_id, $months);
        if (is_wp_error($promo_id)) {
            wp_send_json_error(['message' => $promo_id->get_error_message()]);
        }

        $amount = round(TAP_Promotions::get_price() * $months, 2);
        $result = TAP_PayPal::create_order_generic(
            $amount,
            sprintf(__('Destacado %d mes(es) — listing #%d', 'travel-agency-platform'), $months, $listing_id),
            'PROMO-' . $promo_id,
            'promotion',
            (int) $promo_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'order_id' => $result['order_id'],
            'message'  => __('Completa el pago con PayPal para activar el destacado.', 'travel-agency-platform'),
        ]);
    }

    /** Capture a completed promotion PayPal order and activate the promo. */
    public static function capture_promo_paypal() {
        check_ajax_referer('tap_agency_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid order', 'travel-agency-platform')]);
        }

        $order = TAP_Payment::resolve_order($paypal_order_id);
        if (!$order || 'promotion' !== $order->object_type) {
            wp_send_json_error(['message' => __('Order not found', 'travel-agency-platform')]);
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result['capture_status'] === 'COMPLETED') {
            TAP_Payment::record_order($paypal_order_id, 'promotion', (int) $order->object_id, $order->amount, 'completed', $result['capture_id']);
            TAP_Payment::confirm_promotion_payment((int) $order->object_id, 'paypal', $result['capture_id']);
            wp_send_json_success([
                'message' => __('Pago recibido. Tu listing está destacado.', 'travel-agency-platform'),
                'status'  => 'active',
            ]);
        }

        wp_send_json_error(['message' => __('The payment could not be completed.', 'travel-agency-platform')]);
    }

    private static function current_agency_for( $user_id ) {        if ( user_can( $user_id, 'manage_options' ) ) {
            return null;
        }
        return TAP_Booking::get_agency_for_user( $user_id );
    }

    public static function promo_request() {
        check_ajax_referer( 'tap_agency_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required', 'travel-agency-platform' ) ] );
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for( $user->ID );
        if ( ! $agency ) {
            wp_send_json_error( [ 'message' => __( 'Only agencies can promote listings.', 'travel-agency-platform' ) ] );
        }
        $listing_id = isset( $_POST['listing_id'] ) ? intval( $_POST['listing_id'] ) : 0;
        $months     = isset( $_POST['months'] ) ? max(1, intval( $_POST['months'] )) : 1;
        if ( ! $listing_id || ! class_exists( 'TAP_Promotions' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid listing.', 'travel-agency-platform' ) ] );
        }
        if ( (int) TAP_Promotions::agency_of_listing( $listing_id ) !== (int) $agency ) {
            wp_send_json_error( [ 'message' => __( 'You can only promote your own listings.', 'travel-agency-platform' ) ] );
        }
        $result = TAP_Promotions::request( $agency, $listing_id, $months );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [
            'message' => __( 'Solicitud de destacado enviada. Un administrador confirmará el pago para activarlo.', 'travel-agency-platform' ),
            'status'  => 'pending',
            'promo_id' => (int) $result,
        ] );
    }

    /**
     * Resolve the per-type meta prefix from a listing post type.
     */
    private static function listing_prefix($type) {
        return TAP_Post_Types::meta_prefix($type);
    }

    private static function agency_owns_listing($listing_id, $type) {
        global $wpdb;
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (null === $agency) {
            return true;
        }
        $prefix  = self::listing_prefix($type);
        $owner   = (int) get_post_meta($listing_id, '_tap_' . $prefix . '_agency_id', true);
        return $owner > 0 && $owner === (int) $agency;
    }

    private static function agency_owns_accommodation($acc_id) {
        return self::agency_owns_listing($acc_id, 'tap_accommodation');
    }

    public static function agency_save_listing() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }

        $result = self::save_listing_data(get_current_user_id(), $_POST);
        if (true === $result) {
            $id = (int) $GLOBALS['_tap_saved_listing'];
            wp_send_json_success([
                'message'    => isset($_POST['listing_id']) ? __('Listing updated', 'travel-agency-platform') : __('Listing created', 'travel-agency-platform'),
                'listing_id' => $id,
                'edit_url'   => home_url('/manage-listing/?id=' . $id),
            ]);
        } else {
            wp_send_json_error(['message' => $result]);
        }
    }

    /**
     * Core, testable listing save. Returns TRUE on success (listing id in
     * $GLOBALS['_tap_saved_listing']) or an error message string. Never dies,
     * so it can be exercised directly under wp-cli.
     */
    public static function save_listing_data($user_id, array $input) {
        wp_set_current_user($user_id);
        $agency = self::current_agency_for($user_id);

        $service_types = array_keys(TAP_Post_Types::get_service_types());
        $service_types = array_values(array_filter($service_types, function ($t) {
            return $t !== 'tap_room';
        }));
        $listing_type  = isset($input['listing_type']) ? sanitize_key($input['listing_type']) : 'tap_accommodation';
        if (!in_array($listing_type, $service_types, true)) {
            $listing_type = 'tap_accommodation';
        }
        $prefix = self::listing_prefix($listing_type);

        $listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
        if ($listing_id && !self::agency_owns_listing($listing_id, $listing_type)) {
            return __('You can only manage your own listings.', 'travel-agency-platform');
        }

        $title = isset($input['title']) ? sanitize_text_field(wp_unslash($input['title'])) : '';
        if ('' === $title) {
            return __('Name is required', 'travel-agency-platform');
        }

        if (null !== $agency && class_exists('TAP_Subscriptions')) {
            $limit = TAP_Subscriptions::listing_limit($agency);
            if ($limit >= 0 && !$listing_id && TAP_Subscriptions::listing_count($agency) >= $limit) {
                return sprintf(__('Your plan allows a maximum of %d listings. Upgrading your plan unlocks more.', 'travel-agency-platform'), $limit);
            }
        }

        $post = [
            'post_type'    => $listing_type,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => isset($input['description']) ? wp_kses_post(wp_unslash($input['description'])) : '',
        ];

        if ($listing_id) {
            $post['ID'] = $listing_id;
            $new_id     = wp_update_post($post, true);
        } else {
            $new_id = wp_insert_post($post, true);
        }

        if (is_wp_error($new_id)) {
            return $new_id->get_error_message();
        }
        if (!$new_id) {
            return __('Could not save the listing', 'travel-agency-platform');
        }

        if (null !== $agency) {
            update_post_meta($new_id, '_tap_' . $prefix . '_agency_id', (int) $agency);
        }

        // Legacy short input-name → meta-key map for the accommodation form.
        $legacy_map = [
            'tap_accommodation' => [
                'type' => '_tap_acc_type', 'stars' => '_tap_acc_stars',
                'price_per_night' => '_tap_acc_price_per_night', 'capacity' => '_tap_acc_capacity',
                'bedrooms' => '_tap_acc_bedrooms', 'bathrooms' => '_tap_acc_bathrooms',
                'checkin_time' => '_tap_acc_checkin_time', 'checkout_time' => '_tap_acc_checkout_time',
                'lat' => '_tap_acc_lat', 'lng' => '_tap_acc_lng', 'currency' => '_tap_acc_currency',
                'is_active' => '_tap_acc_is_active',
            ],
        ];

        $fields = TAP_Metaboxes::get_fields($listing_type);
        foreach ($fields as $meta_key => $cfg) {
            if (strpos($meta_key, '_tap_' . $prefix . '_agency_id') !== false) {
                continue;
            }
            if ($meta_key === '_tap_seo_title' || $meta_key === '_tap_seo_description') {
                continue;
            }

            $input_name = $meta_key;
            $raw = isset($input[$input_name]) ? $input[$input_name] : null;

            if ($raw === null && $listing_type === 'tap_accommodation' && isset($legacy_map['tap_accommodation'][substr($meta_key, strlen('_tap_acc_'))])) {
                $legacy_name = array_search($meta_key, $legacy_map['tap_accommodation'], true);
                $raw = isset($input[$legacy_name]) ? $input[$legacy_name] : null;
            }

            if ($raw === null) {
                continue;
            }

            $ftype = isset($cfg['type']) ? $cfg['type'] : 'text';
            switch ($ftype) {
                case 'number':
                    $value = isset($cfg['step']) && strpos($cfg['step'], '.') !== false ? (string) floatval($raw) : (string) intval($raw);
                    break;
                case 'checkbox':
                    $value = '1' === (string) $raw ? '1' : '0';
                    break;
                case 'select':
                    $value = sanitize_key($raw);
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field(wp_unslash((string) $raw));
                    break;
                case 'email':
                    $value = sanitize_email($raw);
                    break;
                case 'url':
                    $value = esc_url_raw($raw);
                    break;
                default:
                    $value = sanitize_text_field(wp_unslash((string) $raw));
            }
            update_post_meta($new_id, $meta_key, $value);
        }

        $GLOBALS['_tap_saved_listing'] = (int) $new_id;

        if (isset($input['destination'])) {
            $dest  = absint($input['destination']);
            $valid = $dest && term_exists($dest, 'tap_location');
            wp_set_object_terms($new_id, $valid ? [$dest] : [], 'tap_location', false);
        }

        return true;
    }

    public static function agency_save_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required', 'travel-agency-platform' ) ] );
        }

        $acc_id   = isset( $_POST['accommodation_id'] ) ? intval( $_POST['accommodation_id'] ) : 0;
        if ( ! $acc_id || ! self::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        if ( $room_id && (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true ) !== $acc_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid room', 'travel-agency-platform' ) ] );
        }

        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        if ( '' === $title ) {
            wp_send_json_error( [ 'message' => __( 'Room name is required', 'travel-agency-platform' ) ] );
        }
        $price = isset( $_POST['price_per_night'] ) ? floatval( $_POST['price_per_night'] ) : 0;
        if ( $price <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Price per night must be greater than 0', 'travel-agency-platform' ) ] );
        }

        $post = [
            'post_type'   => 'tap_room',
            'post_status' => 'publish',
            'post_title'  => $title,
        ];

        if ( $room_id ) {
            $post['ID'] = $room_id;
            $new_id     = wp_update_post( $post, true );
        } else {
            $new_id     = wp_insert_post( $post, true );
        }

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            wp_send_json_error( [ 'message' => __( 'Could not save the room', 'travel-agency-platform' ) ] );
        }

        $fields = [
            '_tap_room_accommodation_id' => $acc_id,
            '_tap_room_price_per_night'  => $price,
            '_tap_room_min_stay'         => isset( $_POST['min_stay'] ) ? intval( $_POST['min_stay'] ) : 1,
            '_tap_room_currency'         => isset( $_POST['currency'] ) ? sanitize_text_field( $_POST['currency'] ) : 'USD',
            '_tap_room_max_adults'       => isset( $_POST['max_adults'] ) ? intval( $_POST['max_adults'] ) : 2,
            '_tap_room_max_children'    => isset( $_POST['max_children'] ) ? intval( $_POST['max_children'] ) : 0,
            '_tap_room_max_occupancy'    => isset( $_POST['max_occupancy'] ) ? intval( $_POST['max_occupancy'] ) : 2,
            '_tap_room_inventory'        => isset( $_POST['inventory'] ) ? intval( $_POST['inventory'] ) : 1,
            '_tap_room_size'             => isset( $_POST['size'] ) ? sanitize_text_field( $_POST['size'] ) : '',
            '_tap_room_view'             => isset( $_POST['view'] ) ? sanitize_text_field( $_POST['view'] ) : '',
            '_tap_room_is_active'        => isset( $_POST['is_active'] ) && '1' === $_POST['is_active'] ? '1' : '0',
        ];

        foreach ( $fields as $key => $value ) {
            update_post_meta( $new_id, $key, $value );
        }

        wp_send_json_success( [
            'message' => $room_id ? __( 'Room updated', 'travel-agency-platform' ) : __( 'Room created', 'travel-agency-platform' ),
            'room_id' => (int) $new_id,
        ] );
    }

    public static function agency_delete_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        if ( ! $room_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid room', 'travel-agency-platform' ) ] );
        }
        $acc_id = (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true );
        if ( ! $acc_id || ! self::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        global $wpdb;
        $future = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE room_id = %d AND check_out > %s AND status NOT IN ('cancelled','refunded')",
            $room_id,
            gmdate( 'Y-m-d' )
        ) );
        if ( $future > 0 ) {
            wp_send_json_error( [ 'message' => __( 'This room has future bookings and cannot be deleted.', 'travel-agency-platform' ) ] );
        }

        wp_delete_post( $room_id, false );
        wp_send_json_success( [ 'message' => __( 'Room deleted', 'travel-agency-platform' ) ] );
    }

    public static function toggle_favorite() {
        check_ajax_referer('tap_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['needs_login' => true, 'message' => __('Please log in to save favorites.', 'travel-agency-platform')]);
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !in_array(get_post_type($post_id), ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], true)) {
            wp_send_json_error(['message' => __('Invalid service.', 'travel-agency-platform')]);
        }

        $user_id  = get_current_user_id();
        $favorites = get_user_meta($user_id, 'tap_favorites', true);
        if (!is_array($favorites)) {
            $favorites = [];
        }

        $added = false;
        if (in_array($post_id, $favorites, true)) {
            $favorites = array_values(array_diff($favorites, [$post_id]));
        } else {
            $favorites[] = $post_id;
            $added = true;
        }

        update_user_meta($user_id, 'tap_favorites', array_map('intval', array_unique($favorites)));

        wp_send_json_success([
            'added'      => $added,
            'count'      => count($favorites),
            'favorites'  => array_map('intval', $favorites),
        ]);
    }

    /** Fase 4 — Support chat: answer a visitor message (rate-limited). */
    public static function chatbot_message() {
        if (!wp_verify_nonce(isset($_POST['nonce']) ? (string) $_POST['nonce'] : '', 'tap_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'travel-agency-platform')], 403);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
        if (TAP_Chatbot::rate_limited($ip)) {
            wp_send_json_error(['message' => __('Too many messages. Try again in a moment.', 'travel-agency-platform')], 429);
        }

        $message = sanitize_text_field($_POST['message'] ?? '');
        if ('' === trim($message)) {
            wp_send_json_error(['message' => __('Type your question...', 'travel-agency-platform')], 400);
        }

        $answer = TAP_Chatbot::answer($message);

        $lang = TAP_Localization::current_lang();
        TAP_Chatbot::log_event($answer['intent'], $lang);

        wp_send_json_success($answer);
    }
}
