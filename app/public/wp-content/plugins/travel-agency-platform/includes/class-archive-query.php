<?php
defined('ABSPATH') || exit;

/**
 * Query filters for the public archive: main-query scoping and the\n * rating/featured sort clauses. They only mutate a foreign WP_Query, so they\n * are kept out of TAP_Ajax (which owns handlers that write).
 */
class TAP_Archive_Query {
    public static function featured_sort_clauses($clauses, $wp_query) {
        if (is_admin() || !$wp_query->is_main_query()) return $clauses;

        $types = (array) $wp_query->get('post_type');
        $types = array_filter($types);
        if (empty($types) && $wp_query->is_search()) {
            $types = ['tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
        }
        if (empty($types)) return $clauses;

        // Cheap path: order by precomputed featured IDs. The memoized set costs
        // one flat query per type instead of the two correlated postmeta
        // subqueries per type that used to execute for every candidate row.
        if (class_exists('TAP_Promotions')) {
            $featured_ids = TAP_Promotions::featured_ids($types);
            if (empty($featured_ids)) return $clauses;
            if (count($featured_ids) <= 1000) {
                global $wpdb;
                $in = implode(',', array_map('intval', $featured_ids));
                $clauses['orderby'] = "({$wpdb->posts}.ID IN ({$in})) = 0 ASC" . ($clauses['orderby'] ? ', ' . $clauses['orderby'] : '');
                return $clauses;
            }
        }

        // Fallback (pathologically large featured sets): original per-type guard.
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

        $featured = '(CASE WHEN ' . implode(' OR ', $guards) . ' THEN 0 ELSE 1 END)';
        $clauses['orderby'] = $featured . ' ASC' . ($clauses['orderby'] ? ', ' . $clauses['orderby'] : '');
        return $clauses;
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


    /**
     * `?destino=<slug>` narrows any service archive or search to one destination.
     *
     * The unified ficha links here ("Ver todos los tours en La Fortuna"), so it
     * must work for every service type, not just accommodations. Children are
     * included: a service tagged "La Fortuna" belongs to the San Carlos page.
     */
    private static function apply_destination_filter($query) {
        $slug = sanitize_title(wp_unslash($_GET['destino'] ?? ''));
        if ('' === $slug) {
            return;
        }

        $is_service_archive = false;
        foreach (array_keys(TAP_Post_Types::get_service_types()) as $type) {
            if ($query->is_post_type_archive($type)) {
                $is_service_archive = true;
                break;
            }
        }
        if (! $is_service_archive && ! $query->is_search()) {
            return;
        }

        $term = get_term_by('slug', $slug, 'tap_location');
        if (! $term instanceof WP_Term) {
            return;
        }

        $tax_query   = (array) $query->get('tax_query', []);
        $tax_query[] = [
            'taxonomy'         => 'tap_location',
            'field'            => 'term_id',
            'terms'            => (int) $term->term_id,
            'include_children' => true,
        ];
        $query->set('tax_query', $tax_query);
    }

    public static function filter_archive_query($query) {
        if (is_admin() || !$query->is_main_query()) return;

        self::apply_destination_filter($query);

        if ($query->is_search()) {
            $excluded = TAP_Approval::excluded_listing_ids();
            if (!empty($excluded)) {
                $query->set('post__not_in', $excluded);
            }
            // The accommodation archive hides inactive listings; a keyword
            // search must do the same or a listing the agency switched off
            // still appears in /?s= results and stays bookable. Other post
            // types carry no such meta, so they are matched by the NOT EXISTS
            // arm.
            $meta_query       = (array) $query->get('meta_query', []);
            $meta_query[]     = [
                'relation' => 'OR',
                ['key' => '_tap_acc_is_active', 'compare' => 'NOT EXISTS'],
                ['key' => '_tap_acc_is_active', 'value' => '1'],
            ];
            $query->set('meta_query', $meta_query);
            return;
        }

        if (!$query->is_post_type_archive('tap_accommodation')) return;

        $meta_query = $query->get('meta_query', []);
        $tax_query = $query->get('tax_query', []);

        // Keyword search (keyword => WordPress search 's')
        $keyword = sanitize_text_field(wp_unslash($_GET['keyword'] ?? ''));
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
        $type_slug = sanitize_text_field(wp_unslash($_GET['type'] ?? ''));
        if ($type_slug) {
            $tax_query[] = ['taxonomy' => 'tap_property_type', 'field' => 'slug', 'terms' => $type_slug];
        }

        // Amenities taxonomy
        $amenities = (array) wp_unslash($_GET['amenities'] ?? []);
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
        $sort = sanitize_text_field(wp_unslash($_GET['sort'] ?? ''));
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
}
