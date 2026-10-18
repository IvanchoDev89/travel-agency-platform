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
        $sql = 'SELECT * FROM ' . self::plans_table();
        if (!$include_inactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY price_monthly ASC, id ASC';
        return $wpdb->get_results($sql);
    }

    public static function get_plan($plan_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::plans_table() . ' WHERE id = %d',
            (int) $plan_id
        ));
    }

    /** Agency that owns the given subscription row (0 when unknown). */
    public static function agency_for_subscription($sub_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT agency_id FROM ' . self::subs_table() . ' WHERE id = %d',
            (int) $sub_id
        ));
    }

    public static function default_plan() {
        foreach (self::get_plans() as $plan) {
            if ((float) $plan->price_monthly <= 0) {
                return $plan;
            }
        }
        // No free plan is configured: fall back to the built-in free tier. Never
        // return the cheapest paid plan here — an agency with no active plan
        // would silently inherit its commission rate and listing limit for free.
        return (object) [
            'id' => 0, 'name' => __('Gratis', 'travel-agency-platform'), 'slug' => 'free',
            'price_monthly' => 0, 'commission_rate' => null, 'listing_limit' => 3,
            'featured_slots' => 0, 'features' => '',
        ];
    }

    public static function current_subscription($agency_id) {
        global $wpdb;
        $tplans = self::plans_table();
        return $wpdb->get_row($wpdb->prepare(
            'SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.price_monthly,
                    p.commission_rate, p.listing_limit, p.featured_slots
             FROM ' . self::subs_table() . " s
             LEFT JOIN $tplans p ON p.id = s.plan_id
             WHERE s.agency_id = %d
             ORDER BY s.created_at DESC LIMIT 1",
            (int) $agency_id
        ));
    }

    public static function active_plan($agency_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT s.*, p.id AS plan_id FROM ' . self::subs_table() . ' s
             JOIN ' . self::plans_table() . " p ON p.id = s.plan_id
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
        $total = 0;
        foreach (TAP_Post_Types::agency_meta_keys() as $post_type => $meta) {
            // Count via found_posts so we never load every listing id: the
            // previous -1 fetch pulled the full ID list just to count it.
            $query = new WP_Query([
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'meta_key'       => $meta,
                'meta_value'     => (int) $agency_id,
                'fields'         => 'ids',
                'posts_per_page' => 1,
            ]);
            $total += (int) $query->found_posts;
        }
        return $total;
    }

    /**
     * Listings an agency currently has live (is_active = 1), across every
     * service type. This is what a publish action consumes from the plan's
     * listing_limit; drafts that were never published are not counted.
     */
    public static function active_listing_count($agency_id) {
        $agency_id = (int) $agency_id;
        if ($agency_id < 1) {
            return 0;
        }
        $total = 0;
        foreach (TAP_Post_Types::agency_meta_keys() as $post_type => $meta) {
            $prefix = TAP_Post_Types::meta_prefix($post_type);
            if ($prefix === '') {
                continue;
            }
            $query = new WP_Query([
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'meta_query'     => [
                    ['key' => $meta, 'value' => $agency_id],
                    ['key' => '_tap_' . $prefix . '_is_active', 'value' => '1'],
                ],
                'fields'         => 'ids',
                'posts_per_page' => 1,
            ]);
            $total += (int) $query->found_posts;
        }
        return $total;
    }

    public static function subscribe($agency_id, $plan_id) {
        global $wpdb;
        // Tanda C (C14): only approved agencies may contract a plan; pending or
        // rejected ones must wait for the admin review.
        if (!TAP_Approval::is_approved((int) $agency_id)) {
            return new WP_Error('agency_not_approved', __('Tu agencia debe ser aprobada por un administrador antes de contratar un plan.', 'travel-agency-platform'));
        }
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
            'SELECT * FROM ' . self::subs_table() . ' WHERE id = %d',
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
        // A renewal arrives as a fresh pending row (see subscribe()), so this
        // row's own paid_until is null even though the agency still has an
        // active subscription with days left. Start from the latest remaining
        // paid_until — this row's own (re-confirmation) or another active one —
        // so a renewal never discards already-paid days.
        $candidates = [$sub->paid_until];
        $other_until = $wpdb->get_col($wpdb->prepare(
            'SELECT paid_until FROM ' . self::subs_table() . " WHERE agency_id = %d AND id != %d AND status = 'active' AND paid_until IS NOT NULL",
            (int) $sub->agency_id,
            (int) $sub_id
        ));
        foreach ($other_until as $cand) {
            $candidates[] = $cand;
        }
        foreach ($candidates as $cand) {
            if (!$cand) {
                continue;
            }
            $existing = DateTime::createFromFormat('Y-m-d', (string) $cand);
            if ($existing && $existing >= $base) {
                $base = $existing;
            }
        }
        $until = $base->add(new DateInterval('P' . $months . 'M'))->format('Y-m-d');

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::subs_table() . " SET status = 'expired' WHERE agency_id = %d AND id != %d AND status = 'active'",
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
                /* translators: 1: número de meses pagados, 2: fecha del pago */
                'notes'          => sprintf(__('Paid %d month(s) on %s', 'travel-agency-platform'), $months, current_time('Y-m-d')),
            ],
            ['id' => (int) $sub_id]
        );

        do_action('tap_subscription_paid', (int) $sub->agency_id, (int) $sub->plan_id, $until);
        return $until;
    }

    public static function expire_active() {
        global $wpdb;
        $wpdb->query('UPDATE ' . self::subs_table() . " SET status = 'expired' WHERE status = 'active' AND paid_until < CURDATE()");
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
