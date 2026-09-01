<?php
defined('ABSPATH') || exit;

class TAP_Booking {
    public static function create($data) {
        global $wpdb;

        $booking_code = self::generate_booking_code();
        $agency_id = self::resolve_agency_id($data['service_type'], $data['service_id']);

        $commission_percent = self::get_agency_commission($agency_id);
        $total = isset($data['total_amount']) && $data['total_amount'] !== '' ? floatval($data['total_amount']) : self::calculate_price(
            sanitize_text_field($data['service_type']),
            intval($data['service_id']),
            $data['check_in'] ?? '',
            $data['check_out'] ?? '',
            intval($data['adults'] ?? 1),
            intval($data['children'] ?? 0),
            !empty($data['room_id']) ? intval($data['room_id']) : 0
        );
        $commission_amount = $total * ($commission_percent / 100);

        $nights = 0;
        if (!empty($data['check_in']) && !empty($data['check_out'])) {
            $d1 = new DateTime($data['check_in']);
            $d2 = new DateTime($data['check_out']);
            $nights = max(1, $d1->diff($d2)->days);
        }

        $room_check_in = !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : '';
        $room_check_out = !empty($data['check_out']) ? sanitize_text_field($data['check_out']) : '';

        $today = gmdate('Y-m-d');
        if ($room_check_in && $room_check_in < $today) {
            return new WP_Error('past_date', __('No puedes reservar para una fecha pasada. Revisa la fecha de inicio.', 'travel-agency-platform'));
        }
        if ($room_check_in && $room_check_out && $room_check_out <= $room_check_in) {
            return new WP_Error('bad_range', __('La fecha de salida debe ser posterior a la de entrada.', 'travel-agency-platform'));
        }

        if (!empty($data['room_id']) && $room_check_in && $room_check_out) {
            $table = $wpdb->prefix . 'tap_bookings';
            $overlap = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                 WHERE room_id = %d
                   AND status NOT IN ('cancelled', 'refunded')
                   AND check_in < %s AND check_out > %s",
                intval($data['room_id']),
                $room_check_out,
                $room_check_in
            ));

            $inventory = intval(get_post_meta(intval($data['room_id']), '_tap_room_inventory', true)) ?: 1;
            if (intval($overlap) >= $inventory) {
                return new WP_Error('room_unavailable', __('The room is not available for the selected dates.', 'travel-agency-platform'));
            }
        }

        if (!empty($data['room_id']) && $room_check_in && $room_check_out) {
            $avail = self::get_room_availability(intval($data['room_id']), $room_check_in, $room_check_out);
            if (!$avail['available']) {
                return new WP_Error('dates_full', __('No hay disponibilidad para las fechas seleccionadas.', 'travel-agency-platform'));
            }
        }

        if (sanitize_text_field($data['service_type']) === 'tap_tour') {
            $check_date = !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : '';
            $guests = intval($data['adults'] ?? 1) + intval($data['children'] ?? 0);
            $slots = self::tour_slots(intval($data['service_id']), $check_date);
            if ($slots['booked'] + $guests > $slots['capacity']) {
                return new WP_Error(
                    'tour_full',
                    sprintf(__('El tour está completo para la fecha seleccionada. Quedan %1$d cupos y solicitaste %2$d.', 'travel-agency-platform'), $slots['remaining'], $guests)
                );
            }
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'tap_bookings',
            [
                'booking_code'      => $booking_code,
                'agency_id'         => $agency_id,
                'client_id'         => get_current_user_id(),
                'service_type'      => sanitize_text_field($data['service_type']),
                'service_id'        => intval($data['service_id']),
                'room_id'           => !empty($data['room_id']) ? intval($data['room_id']) : null,
                'package_id'        => !empty($data['package_id']) ? intval($data['package_id']) : null,
                'check_in'          => !empty($data['check_in']) ? sanitize_text_field($data['check_in']) : null,
                'check_out'         => !empty($data['check_out']) ? sanitize_text_field($data['check_out']) : null,
                'adults'            => intval($data['adults'] ?? 1),
                'children'          => intval($data['children'] ?? 0),
                'nights'            => $nights,
                'total_amount'      => $total,
                'commission_amount' => $commission_amount,
                'commission_percent'=> $commission_percent,
                'status'            => 'pending',
                'payment_status'    => 'pending',
                'notes'             => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                'guest_name'        => !empty($data['guest_name']) ? sanitize_text_field($data['guest_name']) : null,
                'guest_email'       => !empty($data['guest_email']) ? sanitize_email($data['guest_email']) : null,
                'guest_phone'       => !empty($data['guest_phone']) ? sanitize_text_field($data['guest_phone']) : null,
            ]
        );

        if ($result === false) {
            return new WP_Error('booking_error', __('Could not create booking', 'travel-agency-platform'));
        }

        $booking_id = $wpdb->insert_id;

        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $wpdb->insert(
                    $wpdb->prefix . 'tap_booking_items',
                    [
                        'booking_id'   => $booking_id,
                        'service_type' => sanitize_text_field($item['service_type']),
                        'service_id'   => intval($item['service_id']),
                        'quantity'     => intval($item['quantity'] ?? 1),
                        'unit_price'   => floatval($item['unit_price']),
                        'subtotal'     => floatval($item['subtotal']),
                        'date_from'    => !empty($item['date_from']) ? sanitize_text_field($item['date_from']) : null,
                        'date_to'      => !empty($item['date_to']) ? sanitize_text_field($item['date_to']) : null,
                    ]
                );
            }
        }

        do_action('tap_booking_created', $booking_id, $data);

        if (get_option('tap_booking_auto_confirm', '0') === '1') {
            self::update_status($booking_id, 'confirmed');
        }

        return [
            'booking_id'   => $booking_id,
            'booking_code' => $booking_code,
            'message'      => __('Booking created successfully', 'travel-agency-platform'),
        ];
    }

    public static function cancel_stale_bookings() {
        global $wpdb;
        $table  = $wpdb->prefix . 'tap_bookings';
        $hours  = (int) apply_filters('tap_stale_booking_hours', get_option('tap_stale_booking_hours', 24));
        if ($hours < 1) return;

        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status = 'pending' AND created_at < %s",
            $cutoff
        ));

        foreach ($ids as $id) {
            self::update_status(intval($id), 'cancelled');
        }
    }

    public static function tour_slots($service_id, $date = '') {
        global $wpdb;
        $cap = max(1, intval(get_post_meta($service_id, '_tap_tour_capacity', true)));
        $booked = 0;
        if ($date) {
            $booked = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(adults + children), 0) FROM {$wpdb->prefix}tap_bookings WHERE service_type = 'tap_tour' AND service_id = %d AND check_in = %s AND status NOT IN ('cancelled', 'refunded')",
                $service_id, $date
            )));
        }
        return [
            'capacity'  => $cap,
            'booked'    => $booked,
            'remaining' => max(0, $cap - $booked),
        ];
    }

    public static function client_cancel_request($booking_id, $user_id = 0) {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return new WP_Error('no_user', __('Debes iniciar sesión.', 'travel-agency-platform'));
        }
        $booking = self::get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));
        }
        if ((int) $booking->client_id !== (int) $user_id) {
            return new WP_Error('forbidden', __('No tienes permiso para cancelar esta reserva.', 'travel-agency-platform'));
        }
        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return new WP_Error('bad_status', __('Esta reserva ya no puede cancelarse.', 'travel-agency-platform'));
        }
        if ($booking->check_in && $booking->check_in < gmdate('Y-m-d')) {
            return new WP_Error('started', __('La reserva ya comenzó.', 'travel-agency-platform'));
        }

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            [
                'status'               => 'cancelled',
                'cancel_requested_at'  => current_time('mysql'),
                'cancelled_by'         => 'client',
            ],
            ['id' => $booking_id]
        );

        if ('paid' === $booking->payment_status) {
            self::update_payment_status($booking_id, 'refunded');
        }

        do_action('tap_booking_status_updated', $booking_id, 'cancelled');
        return true;
    }

    public static function update_status($booking_id, $status) {
        global $wpdb;

        $valid_statuses = ['pending', 'confirmed', 'cancelled', 'completed', 'refunded'];
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', __('Invalid status', 'travel-agency-platform'));
        }

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['status' => $status],
            ['id' => $booking_id]
        );

        do_action('tap_booking_status_updated', $booking_id, $status);

        return true;
    }

    public static function update_payment_status($booking_id, $payment_status) {
        global $wpdb;

        $valid_statuses = ['pending', 'paid', 'partial', 'refunded', 'failed'];
        if (!in_array($payment_status, $valid_statuses)) {
            return new WP_Error('invalid_status', __('Invalid payment status', 'travel-agency-platform'));
        }

        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['payment_status' => $payment_status],
            ['id' => $booking_id]
        );

        return true;
    }

    public static function get_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $booking_id
        ));
    }

    public static function get_booking_by_code($code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s",
            $code
        ));
    }

    public static function get_latest_bookings($limit = 8) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    public static function get_client_bookings($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE client_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    public static function get_agency_bookings($agency_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $agency_id,
            $limit,
            $offset
        ));
    }

    public static function get_booking_items($booking_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_booking_items WHERE booking_id = %d",
            $booking_id
        ));
    }

    public static function get_agency_bookings_count($agency_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d",
            $agency_id
        ));
    }

    public static function get_client_bookings_count($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE client_id = %d",
            $user_id
        ));
    }

    private static function generate_booking_code() {
        $prefix = 'TAP';
        $timestamp = strtoupper(substr(md5(uniqid()), 0, 6));
        return $prefix . '-' . $timestamp . '-' . date('ymd');
    }

    private static function resolve_agency_id($service_type, $service_id) {
        $meta_key = '';

        switch ($service_type) {
            case 'tap_accommodation': $meta_key = '_tap_acc_agency_id'; break;
            case 'tap_tour':          $meta_key = '_tap_tour_agency_id'; break;
            case 'tap_transport':     $meta_key = '_tap_trans_agency_id'; break;
            case 'tap_car_rental':    $meta_key = '_tap_car_agency_id'; break;
            case 'tap_boat':          $meta_key = '_tap_boat_agency_id'; break;
            case 'tap_package':       $meta_key = '_tap_pkg_agency_id'; break;
        }

        if ($meta_key) {
            $agency_id = get_post_meta($service_id, $meta_key, true);
            if ($agency_id) return intval($agency_id);
        }

        return 0;
    }

    public static function get_agency_commission($agency_id) {
        if (!$agency_id) return 10;
        $commission = get_post_meta($agency_id, '_tap_agency_commission', true);
        return $commission ? floatval($commission) : 10;
    }

    public static function calculate_price($service_type, $service_id, $check_in = '', $check_out = '', $adults = 1, $children = 0, $room_id = 0) {
        $price_keys = [
            'tap_accommodation' => '_tap_acc_price_per_night',
            'tap_tour'          => '_tap_tour_price_adult',
            'tap_transport'     => '_tap_trans_price',
            'tap_car_rental'    => '_tap_car_price_per_day',
            'tap_boat'          => '_tap_boat_price_half',
            'tap_package'       => '_tap_pkg_price',
        ];

        if ($service_type === 'tap_accommodation' && $room_id) {
            if (class_exists('TAP_Pricing') && $check_in && $check_out) {
                $total = TAP_Pricing::calculate_total($room_id, $check_in, $check_out);
                if ($total !== false) return $total;
            }
            $unit_price = floatval(get_post_meta($room_id, '_tap_room_price_per_night', true));
            if ($unit_price <= 0) return 0;
            $nights = 1;
            if ($check_in && $check_out) {
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $nights = max(1, $d1->diff($d2)->days);
            }
            return $unit_price * $nights;
        }

        $price_key = $price_keys[$service_type] ?? '';
        if (!$price_key) return 0;

        $unit_price = floatval(get_post_meta($service_id, $price_key, true));
        if ($unit_price <= 0) return 0;

        switch ($service_type) {
            case 'tap_accommodation':
                $nights = 1;
                if ($check_in && $check_out) {
                    $d1 = new DateTime($check_in);
                    $d2 = new DateTime($check_out);
                    $nights = max(1, $d1->diff($d2)->days);
                }
                return $unit_price * $nights;

            case 'tap_tour':
                $adult_price = $unit_price;
                $child_price = floatval(get_post_meta($service_id, '_tap_tour_price_child', true)) ?: $adult_price * 0.7;
                return ($adult_price * $adults) + ($child_price * $children);

            case 'tap_transport':
                return $unit_price;

            case 'tap_car_rental':
                $days = 1;
                if ($check_in && $check_out) {
                    $d1 = new DateTime($check_in);
                    $d2 = new DateTime($check_out);
                    $days = max(1, $d1->diff($d2)->days);
                }
                return $unit_price * $days;

            case 'tap_boat':
                return $unit_price;

            case 'tap_package':
                $price_per_person = floatval(get_post_meta($service_id, '_tap_pkg_price_per_person', true));
                if ($price_per_person > 0) {
                    return $price_per_person * ($adults + $children);
                }
                return $unit_price;

            default:
                return $unit_price;
        }
    }

    public static function get_room_availability($room_id, $check_in, $check_out) {
        global $wpdb;
        $inventory = intval(get_post_meta($room_id, '_tap_room_inventory', true)) ?: 1;

        $booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT b.id) FROM {$wpdb->prefix}tap_bookings b
            WHERE b.room_id = %d AND b.status NOT IN ('cancelled', 'refunded')
            AND b.check_in < %s AND b.check_out > %s",
            $room_id, $check_out, $check_in
        ));

        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_availability
            WHERE service_type = 'tap_room' AND service_id = %d
            AND date >= %s AND date < %s AND is_blocked = 1",
            $room_id, $check_in, $check_out
        ));

        return [
            'available'   => ($booked + $blocked) < $inventory,
            'total'       => $inventory,
            'booked'      => intval($booked),
            'blocked'     => intval($blocked),
            'remaining'   => $inventory - $booked - $blocked,
        ];
    }

    public static function get_rooms_with_availability($accommodation_id, $check_in = '', $check_out = '', $adults = 1) {
        $rooms = TAP_Post_Types::get_accommodation_rooms($accommodation_id);
        $results = [];

        foreach ($rooms as $room) {
            $is_active = get_post_meta($room->ID, '_tap_room_is_active', true);
            if ($is_active === '0') continue;

            $max_occupancy = intval(get_post_meta($room->ID, '_tap_room_max_occupancy', true)) ?: 999;
            $max_adults = intval(get_post_meta($room->ID, '_tap_room_max_adults', true)) ?: 999;

            if ($adults > $max_adults || $adults > $max_occupancy) continue;

            $base_price = floatval(get_post_meta($room->ID, '_tap_room_price_per_night', true));
            $available = true;
            $nights = 0;
            $total = $base_price;
            $price_breakdown = [];
            $min_stay = 1;
            $base_total = $base_price;
            $savings = 0;
            $discounts = [];

            if ($check_in && $check_out) {
                $avail = self::get_room_availability($room->ID, $check_in, $check_out);
                $available = $avail['available'];
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $nights = max(1, $d1->diff($d2)->days);

                if (class_exists('TAP_Pricing')) {
                    $prices = TAP_Pricing::get_prices_for_range($room->ID, $check_in, $check_out);
                    if ($prices['available']) {
                        $price_breakdown = $prices['prices'];
                        $base_total = $prices['total'];
                        $total = $base_total;
                    } else {
                        $available = false;
                    }

                    if ($available) {
                        $quote = TAP_Pricing::get_quote($room->ID, $check_in, $check_out);
                        if ($quote['available']) {
                            $base_total = $quote['base_total'];
                            $total = $quote['total'];
                            $savings = $quote['savings'];
                            $discounts = $quote['discounts'];
                        }
                    }

                    $min_stay = TAP_Pricing::get_max_min_stay_for_range($room->ID, $check_in, $check_out);
                }
            }

            $beds = TAP_Post_Types::get_room_beds($room->ID);
            $gallery = get_post_meta($room->ID, '_tap_room_gallery', true);
            $gallery_ids = $gallery ? explode(',', $gallery) : [];

            $results[] = [
                'id'              => $room->ID,
                'title'           => $room->post_title,
                'description'     => $room->post_excerpt ?: wp_trim_words($room->post_content, 30),
                'price'           => $base_price,
                'total'           => $total,
                'base_total'      => $base_total,
                'savings'         => $savings,
                'discounts'       => $discounts,
                'nights'          => $nights,
                'min_stay'        => $min_stay,
                'price_breakdown' => $price_breakdown,
                'max_adults'    => $max_adults,
                'max_children'  => intval(get_post_meta($room->ID, '_tap_room_max_children', true)) ?: 0,
                'max_occupancy' => $max_occupancy,
                'inventory'     => intval(get_post_meta($room->ID, '_tap_room_inventory', true)) ?: 1,
                'size'          => get_post_meta($room->ID, '_tap_room_size', true),
                'view'          => get_post_meta($room->ID, '_tap_room_view', true),
                'floor'         => get_post_meta($room->ID, '_tap_room_floor', true),
                'beds'          => $beds,
                'bed_summary'   => self::format_beds($beds),
                'thumbnail'     => get_the_post_thumbnail_url($room->ID, 'medium'),
                'gallery'       => $gallery_ids,
                'available'     => $available,
                'amenities'     => TAP_Post_Types::get_room_amenities($room->ID),
            ];
        }

        return $results;
    }

    public static function format_beds($beds) {
        if (empty($beds)) return '';
        $labels = [
            'king' => __('King', 'travel-agency-platform'),
            'queen' => __('Queen', 'travel-agency-platform'),
            'double' => __('Double', 'travel-agency-platform'),
            'twin' => __('Twin', 'travel-agency-platform'),
            'bunk' => __('Bunk', 'travel-agency-platform'),
            'sofa' => __('Sofa Bed', 'travel-agency-platform'),
            'crib' => __('Crib', 'travel-agency-platform'),
            'murphy' => __('Murphy', 'travel-agency-platform'),
            'futon' => __('Futon', 'travel-agency-platform'),
        ];
        $parts = [];
        foreach ($beds as $bed) {
            $type = $labels[$bed['type']] ?? ucfirst($bed['type']);
            $parts[] = intval($bed['count']) . ' ' . $type . ($bed['count'] > 1 ? 's' : '');
        }
        return implode(', ', $parts);
    }

    public static function get_booking_stats($agency_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $where = $agency_id ? $wpdb->prepare("WHERE agency_id = %d", $agency_id) : 'WHERE 1=1';

        return [
            'total'      => $wpdb->get_var("SELECT COUNT(*) FROM $table $where"),
            'pending'    => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'pending'"),
            'confirmed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'confirmed'"),
            'completed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'completed'"),
            'cancelled'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'cancelled'"),
            'revenue'    => $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'commission' => $wpdb->get_var("SELECT COALESCE(SUM(commission_amount), 0) FROM $table $where"),
        ];
    }

    /**
     * Find the tap_agency post linked to a WordPress user.
     */
    public static function get_agency_for_user($user_id = null) {
        if (!$user_id) $user_id = get_current_user_id();
        if (!$user_id) return 0;

        $posts = get_posts([
            'post_type'      => 'tap_agency',
            'post_status'    => 'any',
            'meta_key'       => '_tap_agency_user_id',
            'meta_value'     => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return $posts ? intval($posts[0]) : 0;
    }

    /**
     * Generate a monthly series for charts over the last $months months.
     */
    public static function get_monthly_series($months = 12) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as bookings,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END), 0) as revenue,
                    COALESCE(SUM(commission_amount), 0) as commission,
                    COALESCE(SUM(CASE WHEN status IN ('cancelled','refunded') THEN 1 ELSE 0 END), 0) as cancelled
            FROM $table
            GROUP BY month ORDER BY month ASC"
        );
        $by_month = [];
        foreach ($rows as $r) $by_month[$r->month] = $r;

        $labels = [];
        $bookings = [];
        $revenue = [];
        $commission = [];
        $cancelled = [];
        $ref = new DateTime('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = (clone $ref)->modify("-{$i} months")->format('Y-m');
            $labels[] = date_i18n('M Y', strtotime($m . '-01'));
            $row = $by_month[$m] ?? null;
            $bookings[] = $row ? (int) $row->bookings : 0;
            $revenue[] = $row ? (float) $row->revenue : 0;
            $commission[] = $row ? (float) $row->commission : 0;
            $cancelled[] = $row ? (int) $row->cancelled : 0;
        }

        return [
            'labels'     => $labels,
            'bookings'   => $bookings,
            'revenue'    => $revenue,
            'commission' => $commission,
            'cancelled'  => $cancelled,
        ];
    }

    /**
     * Aggregate bookings by service type for a breakdown chart.
     */
    public static function get_service_type_breakdown() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT service_type, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as revenue
            FROM {$wpdb->prefix}tap_bookings
            WHERE status NOT IN ('cancelled','refunded')
            GROUP BY service_type ORDER BY cnt DESC"
        );
        return $rows;
    }

    /**
     * Booking status distribution (counts).
     */
    public static function get_status_distribution() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table GROUP BY status");
        $out = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r->status])) $out[$r->status] = (int) $r->cnt;
        }
        return $out;
    }
}
