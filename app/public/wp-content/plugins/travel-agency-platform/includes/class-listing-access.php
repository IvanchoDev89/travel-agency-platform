<?php
/**
 * TAP_Listing_Access — Shared listing ownership guards.
 *
 * Resolves the meta-key prefix for a service type and answers whether the
 * current agency may touch a given listing or accommodation. Both the AJAX
 * write layer and the media sideload need these, so they live here rather
 * than behind either of them.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Listing_Access {

    /**
     * Subscribe to a paid plan and charge via PayPal. Creates the pending
     * subscription, then a PayPal Checkout v2 order for 1 month.
     */
    /** Capture a completed subscription PayPal order and mark the sub paid. */
    /** Request a promotion and pay via PayPal. Creates the pending promo then an order. */
    /** Capture a completed promotion PayPal order and activate the promo. */
    /**
     * Resolve the per-type meta prefix from a listing post type.
     */
    public static function listing_prefix($type) {
        return TAP_Post_Types::meta_prefix($type);
    }


    public static function agency_owns_listing($listing_id, $type) {
        global $wpdb;
        $user   = wp_get_current_user();
        $agency = TAP_Ajax_Commerce::current_agency_for($user->ID);
        if (null === $agency) {
            return true;
        }
        $prefix  = self::listing_prefix($type);
        $owner   = (int) get_post_meta($listing_id, '_tap_' . $prefix . '_agency_id', true);
        return $owner > 0 && $owner === (int) $agency;
    }


    public static function agency_owns_accommodation($acc_id) {
        return self::agency_owns_listing($acc_id, 'tap_accommodation');
    }
}
