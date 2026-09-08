<?php
/**
 * TAP_Approval — Fase 2: agency approval workflow + KYC.
 *
 * Every agency has a status: pending / approved / rejected (meta
 * _tap_agency_status). A listing (or agency profile) is only visible to the
 * public when its agency is approved AND active. Newly registered agencies
 * start pending; legacy agencies (no status meta) are treated as approved.
 */
defined('ABSPATH') || exit;

class TAP_Approval {

    const STATUS_META = '_tap_agency_status';
    const ACTIVE_META = '_tap_agency_is_active';
    const MIGRATED_OPTION = 'tap_agency_status_migrated';
    const KYC_PREFIX = '_tap_kyc_';

    const PENDING  = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';

    private static $excluded_cache = null;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_migrate_legacy'], 20);
    }

    /**
     * Backfill pre-existing published agencies as approved (one-time).
     */
    public static function maybe_migrate_legacy() {
        if (get_option(self::MIGRATED_OPTION)) {
            return;
        }
        $ids = get_posts([
            'post_type'      => 'tap_agency',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        foreach ((array) $ids as $aid) {
            if ('' === (string) get_post_meta($aid, self::STATUS_META, true)) {
                update_post_meta($aid, self::STATUS_META, self::APPROVED);
            }
        }
        update_option(self::MIGRATED_OPTION, '1');
    }

    /**
     * Current approval status. Agencies without a status meta are legacy =
     * approved.
     */
    public static function status($agency_id) {
        $agency_id = (int) $agency_id;
        if (!$agency_id) {
            return self::REJECTED;
        }
        $s = (string) get_post_meta($agency_id, self::STATUS_META, true);
        return '' === $s ? self::APPROVED : $s;
    }

    public static function is_approved($agency_id) {
        return self::status($agency_id) === self::APPROVED
            && '0' !== get_post_meta((int) $agency_id, self::ACTIVE_META, '1');
    }

    public static function is_pending($agency_id) {
        return self::status($agency_id) === self::PENDING;
    }

    public static function set_status($agency_id, $status) {
        $agency_id = (int) $agency_id;
        if (!get_post($agency_id) || !in_array($status, [self::PENDING, self::APPROVED, self::REJECTED], true)) {
            return false;
        }
        update_post_meta($agency_id, self::STATUS_META, $status);
        return true;
    }

    /**
     * Invalidate the per-request exclusion cache (called when agency status
     * changes so re-renders in the same request reflect the update).
     */
    public static function reset_cache() {
        self::$excluded_cache = null;
    }

    public static function approve($agency_id) {
        if (!self::set_status($agency_id, self::APPROVED)) {
            return false;
        }
        update_post_meta((int) $agency_id, self::ACTIVE_META, '1');
        self::reset_cache();
        do_action('tap_agency_approved', (int) $agency_id);
        return true;
    }

    public static function reject($agency_id) {
        if (!self::set_status($agency_id, self::REJECTED)) {
            return false;
        }
        update_post_meta((int) $agency_id, self::ACTIVE_META, '0');
        self::reset_cache();
        do_action('tap_agency_rejected', (int) $agency_id);
        return true;
    }

    public static function pending($agency_id) {
        if (!self::set_status($agency_id, self::PENDING)) {
            return false;
        }
        update_post_meta((int) $agency_id, self::ACTIVE_META, '0');
        self::reset_cache();
        return true;
    }

    /* -------------------------------------------------------------------------
     * KYC
     * ---------------------------------------------------------------------- */

    public static function kyc_fields() {
        return [
            'legal_name'    => __('Representante legal / razón social', 'travel-agency-platform'),
            'doc_type'      => __('Tipo de documento', 'travel-agency-platform'),
            'doc_number'    => __('Número de documento (cédula)', 'travel-agency-platform'),
            'legal_tax_id'  => __('Cédula jurídica', 'travel-agency-platform'),
        ];
    }

    public static function kyc($agency_id) {
        $data = [];
        foreach (array_keys(self::kyc_fields()) as $k) {
            $data[$k] = get_post_meta((int) $agency_id, self::KYC_PREFIX . $k, true);
        }
        return $data;
    }

    public static function save_kyc($agency_id, array $values) {
        $agency_id = (int) $agency_id;
        if (!$agency_id) {
            return;
        }
        $fields = [
            'legal_name', 'doc_type', 'doc_number', 'legal_tax_id',
        ];
        foreach ($fields as $k) {
            if (isset($values[$k])) {
                $v = sanitize_text_field((string) $values[$k]);
                if ($v !== '') {
                    update_post_meta($agency_id, self::KYC_PREFIX . $k, $v);
                }
            }
        }
        update_post_meta($agency_id, self::KYC_PREFIX . 'submitted_at', current_time('mysql'));
    }

    /* -------------------------------------------------------------------------
     * Public visibility gating
     * ---------------------------------------------------------------------- */

    /**
     * Agency id that owns the given service post, or null.
     */
    public static function listing_agency_id($post_id, $post_type = '') {
        $post_id = (int) $post_id;
        if (!$post_id || !method_exists('TAP_Post_Types', 'meta_prefix')) {
            return null;
        }
        $post_type = $post_type ?: (get_post($post_id)->post_type ?? '');
        $key = '_tap_' . TAP_Post_Types::meta_prefix($post_type) . '_agency_id';
        $aid = (int) get_post_meta($post_id, $key, true);
        return $aid ? $aid : null;
    }

    /**
     * True when a service belongs to an approved agency (or to no agency).
     */
    public static function is_service_visible($post_id, $post_type = '') {
        $aid = self::listing_agency_id($post_id, $post_type);
        return $aid === null ? true : self::is_approved($aid);
    }

    /**
     * Listing post ids owned by agencies that are NOT approved. Cached per
     * request since it is used from multiple listing queries.
     */
    public static function excluded_listing_ids() {
        if (self::$excluded_cache !== null) {
            return self::$excluded_cache;
        }
        global $wpdb;
        $out = [];
        $types = array_keys(TAP_Post_Types::get_service_types());
        $keys = [];
        foreach ($types as $pt) {
            if (method_exists('TAP_Post_Types', 'meta_prefix')) {
                $keys[] = '_tap_' . TAP_Post_Types::meta_prefix($pt) . '_agency_id';
            }
        }
        if (empty($keys)) {
            return self::$excluded_cache = [];
        }
        $in = "'" . implode("','", array_map('esc_sql', $keys)) . "'";
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in})"
        );
        foreach ((array) $rows as $r) {
            if (!self::is_approved((int) $r->meta_value)) {
                $out[] = (int) $r->post_id;
            }
        }
        return self::$excluded_cache = $out;
    }

    /**
     * Merge post__not_in with the excluded ids into query $args.
     */
    public static function exclude_from_query($args, array $post_types = []) {
        $excluded = self::excluded_listing_ids();
        if (!empty($excluded)) {
            $args['post__not_in'] = array_values(array_unique(array_merge(
                (array) ($args['post__not_in'] ?? []),
                $excluded
            )));
        }
        return $args;
    }
}