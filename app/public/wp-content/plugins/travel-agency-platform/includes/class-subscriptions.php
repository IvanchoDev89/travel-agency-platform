<?php
defined('ABSPATH') || exit;

class TAP_Subscriptions {
    public static function init() {
        add_action('init', [__CLASS__, 'maybe_expire']);
    }

    public static function plans_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_plans';
    }

    public static function subs_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_agency_subscriptions';
    }

    public static function get_plans($include_inactive = false) {
        global $wpdb;
        $sql = "SELECT * FROM " . self::plans_table();
        if (!$include_inactive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY price_monthly ASC, id ASC";
        return $wpdb->get_results($sql);
    }

    public static function get_plan($plan_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::plans_table() . " WHERE id = %d",
            (int) $plan_id
        ));
    }

    public static function default_plan() {
        foreach (self::get_plans() as $plan) {
            if ((float) $plan->price_monthly <= 0) {
                return $plan;
            }
        }
        $plans = self::get_plans();
        return $plans ? $plans[0] : (object) [
            'id' => 0, 'name' => __('Gratis', 'travel-agency-platform'), 'slug' => 'free',
            'price_monthly' => 0, 'commission_rate' => null, 'listing_limit' => 3,
            'featured_slots' => 0, 'features' => '',
        ];
    }

    public static function current_subscription($agency_id) {
        global $wpdb;
        $tplans = self::plans_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.price_monthly,
                    p.commission_rate, p.listing_limit, p.featured_slots
             FROM " . self::subs_table() . " s
             LEFT JOIN $tplans p ON p.id = s.plan_id
             WHERE s.agency_id = %d
             ORDER BY s.created_at DESC LIMIT 1",
            (int) $agency_id
        ));
    }

    public static function active_plan($agency_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.id AS plan_id FROM " . self::subs_table() . " s
             JOIN " . self::plans_table() . " p ON p.id = s.plan_id
             WHERE s.agency_id = %d AND s.status = 'active' AND s.paid_until >= CURDATE()
             ORDER BY s.paid_until DESC LIMIT 1",
            (int) $agency_id
        ));
        if (!$row) {
            return self::default_plan();
        }
        $plan         = self::get_plan($row->plan_id);
        $plan->sub_id = (int) $row->id;
        $plan->paid_until = $row->paid_until;
        return $plan;
    }

    public static function commission_rate_for_agency($agency_id) {
        $plan = self::active_plan($agency_id);
        if ($plan && null !== $plan->commission_rate && (float) $plan->commission_rate >= 0) {
            return (float) $plan->commission_rate;
        }
        return null;
    }

    public static function listing_limit($agency_id) {
        $limit = null;
        $plan  = self::active_plan($agency_id);
        if ($plan && isset($plan->listing_limit)) {
            $limit = (int) $plan->listing_limit;
        } else {
            $limit = (int) self::default_plan()->listing_limit;
        }
        /**
         * Filter the listing limit for an agency. Returns negative (< 0) to
         * indicate "unlimited". Useful for product bundles and for E2E tests.
         *
         * @param int      $limit     Resolved limit.
         * @param int      $agency_id Agency post ID.
         */
        return (int) apply_filters('tap_listing_limit', $limit, $agency_id);
    }

    public static function listing_count($agency_id) {
        $types = [
            'tap_accommodation' => '_tap_acc_agency_id',
            'tap_tour'          => '_tap_tour_agency_id',
            'tap_transport'     => '_tap_trans_agency_id',
            'tap_car_rental'    => '_tap_car_agency_id',
            'tap_boat'          => '_tap_boat_agency_id',
            'tap_package'       => '_tap_pkg_agency_id',
        ];
        $total = 0;
        foreach ($types as $post_type => $meta) {
            $ids = get_posts([
                'post_type'   => $post_type,
                'post_status' => 'any',
                'meta_key'    => $meta,
                'meta_value'  => (int) $agency_id,
                'fields'      => 'ids',
                'posts_per_page' => -1,
            ]);
            $total += count($ids);
        }
        return $total;
    }

    public static function subscribe($agency_id, $plan_id) {
        global $wpdb;
        $plan = self::get_plan($plan_id);
        if (!$plan || !(int) $plan->is_active) {
            return new WP_Error('plan_invalid', __('The selected plan is not available.', 'travel-agency-platform'));
        }
        $current = self::current_subscription($agency_id);
        if ($current && 'pending' === $current->status) {
            return new WP_Error('already_pending', __('You already have a pending subscription. An administrator will confirm the payment.', 'travel-agency-platform'));
        }
        $result = $wpdb->insert(
            self::subs_table(),
            [
                'agency_id'      => (int) $agency_id,
                'plan_id'        => (int) $plan_id,
                'status'         => 'pending',
                'paid_until'     => null,
                'payment_status' => 'pending',
                'payment_method' => 'manual',
                'notes'          => __('Awaiting manual payment confirmation', 'travel-agency-platform'),
            ]
        );
        if (!$result) {
            return new WP_Error('subscribe_failed', __('Could not start the subscription. Please try again.', 'travel-agency-platform'));
        }
        do_action('tap_subscription_requested', (int) $agency_id, (int) $plan_id);
        return (int) $wpdb->insert_id;
    }

    public static function mark_paid($sub_id, $admin_id = 0, $months = 1) {
        global $wpdb;
        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::subs_table() . " WHERE id = %d",
            (int) $sub_id
        ));
        if (!$sub) {
            return new WP_Error('sub_not_found', __('Subscription not found.', 'travel-agency-platform'));
        }

        // Already activated for this payment: a duplicated confirmation click
        // must never extend the same subscription a second time. Renewals come
        // through new pending rows (see subscribe()), never through re-activating.
        if ('active' === $sub->status && 'paid' === $sub->payment_status) {
            return (string) ($sub->paid_until ?: current_time('Y-m-d'));
        }

        $months = max(1, (int) $months);
        $base = new DateTime('today');
        if ('active' === $sub->status && $sub->paid_until) {
            $existing = DateTime::createFromFormat('Y-m-d', $sub->paid_until);
            if ($existing && $existing >= $base) {
                $base = $existing;
            }
        }
        $until = $base->add(new DateInterval('P' . $months . 'M'))->format('Y-m-d');

        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::subs_table() . " SET status = 'expired' WHERE agency_id = %d AND id != %d AND status = 'active'",
            (int) $sub->agency_id,
            (int) $sub_id
        ));

        $wpdb->update(
            self::subs_table(),
            [
                'status'         => 'active',
                'paid_until'     => $until,
                'payment_status' => 'paid',
                'payment_method' => $sub->payment_method ?: 'manual',
                'notes'          => sprintf(__('Paid %d month(s) on %s', 'travel-agency-platform'), $months, current_time('Y-m-d')),
            ],
            ['id' => (int) $sub_id]
        );

        do_action('tap_subscription_paid', (int) $sub->agency_id, (int) $sub->plan_id, $until);
        return $until;
    }

    public static function expire_active() {
        global $wpdb;
        $wpdb->query("UPDATE " . self::subs_table() . " SET status = 'expired' WHERE status = 'active' AND paid_until < CURDATE()");
    }

    public static function maybe_expire() {
        $key = 'tap_subscriptions_expiry_check';
        if (get_transient($key)) {
            return;
        }
        self::expire_active();
        set_transient($key, 1, 12 * HOUR_IN_SECONDS);
    }
}