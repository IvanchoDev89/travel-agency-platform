<?php
defined('ABSPATH') || exit;

class TAP_Shortcodes {
    public static function init() {
        $shortcodes = [
            'tap_search'        => [TAP_Shortcode_Search::class, 'search_form'],
            'tap_services'      => [TAP_Shortcode_Search::class, 'services_list'],
            'tap_service_detail' => [TAP_Shortcode_Search::class, 'service_detail'],
            'tap_agencies'      => [TAP_Shortcode_Search::class, 'agencies_list'],
            'tap_agency_detail' => [TAP_Shortcode_Search::class, 'agency_detail'],
            'tap_booking_form'  => [TAP_Shortcode_Booking::class, 'booking_form'],
            'tap_my_bookings'   => [TAP_Shortcode_Booking::class, 'my_bookings'],
            'tap_dashboard'     => [TAP_Shortcode_Account::class, 'dashboard'],
            'tap_agency_profile' => [TAP_Shortcode_Account::class, 'agency_profile'],
            'tap_featured'      => [TAP_Shortcode_Search::class, 'featured_services'],
            'tap_agency_services' => [TAP_Shortcode_Account::class, 'agency_services'],
            'tap_agency_register' => [TAP_Shortcode_Account::class, 'agency_register'],
            'tap_agency_manage'  => [TAP_Listing_Editor::class, 'agency_manage'],
            'tap_booking_detail' => [TAP_Shortcode_Booking::class, 'booking_detail'],
            'tap_favorites'      => [TAP_Shortcode_Booking::class, 'favorites'],
            'tap_plans'         => [TAP_Shortcode_Account::class, 'plans'],
            'tap_search_results' => [TAP_Shortcode_Search::class, 'search_results'],
            'tap_chatbot'       => [TAP_Shortcode_Account::class, 'chatbot'],
            'tap_destinations_map' => [TAP_Shortcode_Search::class, 'destinations_map'],
        ];

        foreach ($shortcodes as $tag => $callback) {
            add_shortcode($tag, $callback);
        }
    }

    public static function ids_by_meta(array $meta_keys, $value) {
        global $wpdb;
        $meta_keys = array_values(array_filter(array_map('strval', $meta_keys)));
        if (empty($meta_keys)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ($placeholders)",
            array_merge([(string) $value], $meta_keys)
        )));
    }

    public static function active_listing_ids(array $types) {
        $active_keys = [];
        foreach ($types as $pt) {
            $keys = TAP_Promotions::keys_for_type($pt);
            if ($keys) {
                $active_keys[] = $keys['active_key'];
            }
        }
        return self::exclude_pending(self::ids_by_meta($active_keys, '1'));
    }

    /**
     * Pending-agency listings are hidden by exclusion (post__in + post__not_in
     * cannot be combined in WP_Query, so the exclusion is folded into the id list).
     */
    public static function exclude_pending(array $ids) {
        $excluded = array_map('intval', TAP_Approval::excluded_listing_ids());
        if (empty($excluded)) {
            return $ids;
        }
        return array_values(array_diff($ids, $excluded));
    }
}
