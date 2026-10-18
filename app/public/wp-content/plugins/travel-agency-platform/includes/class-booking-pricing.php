<?php
/**
 * TAP_Booking_Pricing — Price calculation and room availability.
 *
 * Computes the line total for a booking (per-service price keys, nightly
 * rates, seasonal adjustments via TAP_Pricing) and answers room-level
 * availability queries. Split out of TAP_Booking in 1.5.17.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Booking_Pricing {

    public static function calculate_price($service_type, $service_id, $check_in = '', $check_out = '', $adults = 1, $children = 0, $room_id = 0) {
        $price_keys = [
            'tap_accommodation' => '_tap_acc_price_per_night',
            'tap_tour'          => '_tap_tour_price_adult',
            'tap_transport'     => '_tap_trans_price',
            'tap_car_rental'    => '_tap_car_price_per_day',
            'tap_boat'          => '_tap_boat_price_half',
            'tap_package'       => '_tap_pkg_price',
            'tap_equipment'     => '_tap_eq_price_day',
        ];

        if ($service_type === 'tap_accommodation' && $room_id) {
            if (class_exists('TAP_Pricing') && $check_in && $check_out) {
                $total = TAP_Pricing::calculate_total($room_id, $check_in, $check_out);
                if ($total !== false) return $total;
                // The range is not priceable (a blocked date, or a night with
                // no price). Never fall back to the flat per-night rate here:
                // that would quote — and let create() book — blocked dates.
                return 0;
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

        if ($service_type === 'tap_equipment') {
            // Equipment may be priced per day and/or per hour; a valid hourly
            // price must not be short-circuited by a missing day price.
            $days = 1;
            if ($check_in && $check_out) {
                $d1 = new DateTime($check_in);
                $d2 = new DateTime($check_out);
                $days = max(1, $d1->diff($d2)->days);
            }
            $day_price = floatval(get_post_meta($service_id, '_tap_eq_price_day', true));
            if ($day_price > 0) {
                return $day_price * $days;
            }
            return floatval(get_post_meta($service_id, '_tap_eq_price_hour', true)) * max($days, 1);
        }

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
}
