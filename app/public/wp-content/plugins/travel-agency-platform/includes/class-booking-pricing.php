<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
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

    /**
     * The single money calculation shared by the quote endpoint and booking
     * creation. Ordering matters: discounts reduce the commission base, the
     * agency fee is a deduction from agency revenue (never a traveler charge)
     * and insurance is a separate line item.
     *
     * @param bool $commit_coupon Only booking creation may mark a coupon used.
     */
    public static function money_breakdown($subtotal, $base_total, $service_type, $service_id, $data = [], $commit_coupon = false) {
        $subtotal   = round(max(0.0, (float) $subtotal), 2);
        $base_total = $base_total > 0 ? round((float) $base_total, 2) : $subtotal;

        $booking_fee = class_exists('TAP_Booking') ? (float) TAP_Booking::get_booking_fee($subtotal) : 0.0;
        $booking_fee = round(max(0.0, $booking_fee), 2);

        $referral_code = '';
        $referral_referrer = 0;
        $referral_discount = 0.0;
        if (class_exists('TAP_Customer_Referrals')) {
            list($referral_code, $referral_referrer, $referral_discount) = TAP_Customer_Referrals::resolve_for_booking(
                (int) ($data['client_id'] ?? get_current_user_id()),
                $subtotal,
                sanitize_text_field((string) ($data['referral_code'] ?? '')),
                !empty($data['guest_email']) ? sanitize_email($data['guest_email']) : ''
            );
            $referral_discount = max(0.0, round((float) $referral_discount, 2));
        }

        $coupon_code   = sanitize_text_field(wp_unslash((string) ($data['coupon_code'] ?? '')));
        $coupon_discount = 0.0;
        $agency_key    = class_exists('TAP_Post_Types') ? TAP_Post_Types::agency_meta_key($service_type) : '';
        $agency_id     = $agency_key ? (int) get_post_meta($service_id, $agency_key, true) : 0;
        $commission_percent = class_exists('TAP_Booking') ? (float) TAP_Booking::get_agency_commission($agency_id) : 0.0;
        if ('' !== $coupon_code && class_exists('TAP_Coupons')) {
            $coupon = TAP_Coupons::validate($coupon_code, $subtotal);
            if ($coupon) {
                $coupon_discount = TAP_Coupons::effective_discount($coupon, $subtotal, $base_total, $commission_percent);
                $coupon_discount = max(0.0, round((float) $coupon_discount, 2));
                if ($coupon_discount > 0 && $commit_coupon) {
                    TAP_Coupons::mark_used($coupon_code);
                }
            }
        }

        $commission_base = max(0.0, round($subtotal - $referral_discount - $coupon_discount, 2));

        $insurance_premium = 0.0;
        $insurance_plan = sanitize_key((string) ($data['insurance_plan'] ?? ''));
        if ('' !== $insurance_plan && class_exists('TAP_Travel_Insurance')) {
            $insurance_premium = round((float) TAP_Travel_Insurance::quote($insurance_plan, $commission_base), 2);
        }

        return [
            'booking_fee'        => $booking_fee,
            'referral_code'      => $referral_code,
            'referral_referrer'  => (int) $referral_referrer,
            'referral_discount'  => $referral_discount,
            'coupon_code'        => $coupon_code,
            'coupon_discount'    => $coupon_discount,
            'commission_base'    => $commission_base,
            'insurance_premium'  => $insurance_premium,
            'commission_percent' => $commission_percent,
            'commission_amount'  => round($commission_base * ($commission_percent / 100), 2),
            'total'              => round($commission_base + $insurance_premium, 2),
        ];
    }

    /** Number of nights between two client dates; never throws on bad input. */
    public static function nights_between($check_in, $check_out) {
        $d1 = class_exists('TAP_Booking') ? TAP_Booking::parse_date($check_in) : null;
        $d2 = class_exists('TAP_Booking') ? TAP_Booking::parse_date($check_out) : null;
        return ($d1 && $d2) ? max(1, $d1->diff($d2)->days) : 1;
    }

    public static function calculate_price($service_type, $service_id, $check_in = '', $check_out = '', $adults = 1, $children = 0, $room_id = 0, $slot_id = 0) {
        $price_keys = [
            'tap_accommodation' => '_tap_acc_price_per_night',
            'tap_tour'          => '_tap_tour_price_adult',
            'tap_experience'    => '_tap_exp_price_adult',
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
            $d1 = class_exists('TAP_Booking') ? TAP_Booking::parse_date($check_in) : null;
            $d2 = class_exists('TAP_Booking') ? TAP_Booking::parse_date($check_out) : null;
            if ($d1 && $d2) {
                $nights = max(1, $d1->diff($d2)->days);
            }
            return $unit_price * $nights;
        }

        $price_key = $price_keys[$service_type] ?? '';
        if (!$price_key) return 0;

        if ($service_type === 'tap_equipment') {
            // Equipment may be priced per day and/or per hour; a valid hourly
            // price must not be short-circuited by a missing day price.
            $days = self::nights_between($check_in, $check_out);
            $day_price = floatval(get_post_meta($service_id, '_tap_eq_price_day', true));
            if ($day_price > 0) {
                $res = $day_price * $days;
            } else {
                $res = floatval(get_post_meta($service_id, '_tap_eq_price_hour', true)) * max($days, 1);
            }
            if (class_exists('TAP_Pricing_Rules') && $check_in) {
                $res = TAP_Pricing_Rules::quote($service_type, $service_id, $res, ['date' => $check_in, 'booking_date' => gmdate('Y-m-d'), 'guests' => (int) $adults + (int) $children]);
            }
            return $res;
        }

        $unit_price = floatval(get_post_meta($service_id, $price_key, true));
        if ($unit_price <= 0) return 0;

        // Slot-based price override for tours/experiences.
        if ($slot_id && class_exists('TAP_Tour_Availability') && in_array($service_type, ['tap_tour', 'tap_experience'], true)) {
            $slot = TAP_Tour_Availability::get_slot($slot_id);
            if ($slot && $slot->price !== null) {
                $unit_price = (float) $slot->price;
            }
        }

        $result = null;

        switch ($service_type) {
            case 'tap_accommodation':
                $nights = self::nights_between($check_in, $check_out);
                return $unit_price * $nights;

            case 'tap_tour':
                $adult_price = $unit_price;
                $child_price = floatval(get_post_meta($service_id, '_tap_tour_price_child', true)) ?: $adult_price * 0.7;
                $result = ($adult_price * $adults) + ($child_price * $children);
                break;
            case 'tap_experience':
                $adult_price = $unit_price;
                $child_price = floatval(get_post_meta($service_id, '_tap_exp_price_child', true)) ?: $adult_price * 0.5;
                $result = ($adult_price * $adults) + ($child_price * $children);
                break;

            case 'tap_transport':
                $result = $unit_price;
                break;

            case 'tap_car_rental':
                $days = self::nights_between($check_in, $check_out);
                $result = $unit_price * $days;
                break;

            case 'tap_boat':
                $result = $unit_price;
                break;

            case 'tap_package':
                $price_per_person = floatval(get_post_meta($service_id, '_tap_pkg_price_per_person', true));
                if ($price_per_person > 0) {
                    $result = $price_per_person * ($adults + $children);
                } else {
                    $result = $unit_price;
                }
                break;

            default:
                $result = $unit_price;
                break;
        }
        // Apply yield pricing rules (early bird, last minute, seasonal, etc.)
        if (class_exists('TAP_Pricing_Rules') && $check_in) {
            $ctx = [
                'date'         => $check_in,
                'booking_date' => gmdate('Y-m-d'),
                'guests'       => (int) $adults + (int) $children,
            ];
            $result = TAP_Pricing_Rules::quote($service_type, $service_id, $result, $ctx);
        }
        return $result;
    }

    public static function get_room_availability($room_id, $check_in, $check_out) {
        global $wpdb;
        $inventory = intval(get_post_meta($room_id, '_tap_room_inventory', true)) ?: 1;
        $over_pct = (int) get_post_meta($room_id, '_tap_room_overbooking_pct', true);
        if ($over_pct > 0) {
            $inventory = (int) ceil($inventory * (1 + $over_pct / 100));
        }

        $booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT b.id) FROM {$wpdb->prefix}tap_bookings b
            WHERE b.room_id = %d AND b.status NOT IN ('cancelled', 'refunded')
            AND b.check_in < %s AND b.check_out > %s",
            $room_id, $check_out, $check_in
        ));

        // Blocked nights live in tap_daily_pricing (the per-room calendar that
        // the agency back-office writes). tap_availability is a legacy table
        // from an earlier schema and is never written, so counting it here
        // always returned zero.
        $blocked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_daily_pricing
            WHERE room_id = %d AND date >= %s AND date < %s AND is_blocked = 1",
            $room_id, $check_in, $check_out
        ));

        return [
            'available'   => ($booked + $blocked) < $inventory,
            'total'       => $inventory,
            'booked'      => intval($booked),
            'blocked'     => $blocked,
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
                $nights = self::nights_between($check_in, $check_out);

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
