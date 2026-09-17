<?php
require __DIR__ . '/bootstrap.php';

global $wpdb;

$pay_ids = [
    'plans'    => [],
    'subs'     => [],
    'promos'   => [],
    'orders'   => [],
    'posts'    => [],
    'meta'     => [],
];

$pay_cleanup = function () use (&$pay_ids) {
    global $wpdb;
    foreach ($pay_ids['meta'] as $m) {
        delete_post_meta((int) $m['post_id'], (string) $m['key']);
    }
    foreach ($pay_ids['promos'] as $id) {
        $wpdb->delete($wpdb->prefix . 'tap_promos', ['id' => (int) $id]);
    }
    foreach ($pay_ids['subs'] as $id) {
        $wpdb->delete($wpdb->prefix . 'tap_agency_subscriptions', ['id' => (int) $id]);
    }
    foreach ($pay_ids['plans'] as $id) {
        $wpdb->delete($wpdb->prefix . 'tap_plans', ['id' => (int) $id]);
    }
    foreach ($pay_ids['orders'] as $oid) {
        $wpdb->delete(TAP_Payment::orders_table(), ['paypal_order_id' => (string) $oid]);
    }
    foreach (array_reverse($pay_ids['posts']) as $id) {
        wp_delete_post((int) $id, true);
    }
    foreach ($pay_ids as $bucket => $ids) {
        $pay_ids[$bucket] = [];
    }
};
$pay_track = function (string $bucket, $id) use (&$pay_ids) {
    $pay_ids[$bucket][] = $id;
};

register_shutdown_function($pay_cleanup);

$pay_block_mail = function () {
    $cb = function () { return true; };
    add_filter('pre_wp_mail', $cb, 10, 2);
    return $cb;
};
$unblock = null;

try {
    $unblock = $pay_block_mail();

    tap_t_assert(class_exists('TAP_Payment'), 'TAP_Payment loaded');

    $agency = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Pay E2E Agency ' . wp_generate_password(6, false)]);
    if (is_wp_error($agency) || !$agency) {
        $agency = 0;
    } else {
        $pay_track('posts', (int) $agency);
        update_post_meta($agency, '_tap_agency_status', TAP_Approval::APPROVED);
        update_post_meta($agency, '_tap_agency_is_active', '1');
        update_post_meta($agency, '_tap_agency_email', 'pay-e2e-agency@example.test');
    }
    tap_t_assert($agency > 0, 'owned agency fixture created');

    $tour = 0;
    if ($agency) {
        $tour = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Pay E2E Tour ' . wp_generate_password(6, false)]);
        if (is_wp_error($tour) || !$tour) {
            $tour = 0;
        } else {
            $pay_track('posts', (int) $tour);
            update_post_meta($tour, '_tap_tour_is_active', '1');
            update_post_meta($tour, '_tap_tour_agency_id', (int) $agency);
        }
    }
    tap_t_assert($tour > 0, 'owned published tour fixture linked to owned agency');

    $plan_id = 0;
    $plan = null;
    if ($agency) {
        $wpdb->insert($wpdb->prefix . 'tap_plans', [
            'name'           => 'Pay E2E Plan ' . wp_generate_password(6, false),
            'slug'           => 'pay-e2e-' . wp_generate_password(8, false),
            'price_monthly'  => 9.50,
            'commission_rate'=> 10.00,
            'listing_limit'  => 5,
            'featured_slots' => 0,
            'is_active'      => 1,
        ]);
        $plan_id = (int) $wpdb->insert_id;
        if ($plan_id > 0) {
            $pay_track('plans', $plan_id);
            $plan = TAP_Subscriptions::get_plan($plan_id);
        }
    }
    tap_t_assert($plan_id > 0 && $plan && (float) $plan->price_monthly > 0, 'owned paid plan fixture created');

    $oid = 'MOCK-ORDER-' . wp_generate_password(8, false);
    $pay_track('orders', $oid);
    TAP_Payment::record_order($oid, 'subscription', 123, 9.50, 'created');
    $row = TAP_Payment::resolve_order($oid);
    if ($row && $row->object_type === 'subscription' && (int) $row->object_id === 123 && abs((float) $row->amount - 9.50) < 0.001) {
        tap_t_pass('record_order + resolve_order roundtrip');
    } else {
        tap_t_fail('record_order + resolve_order roundtrip');
    }

    if ($plan) {
        $sub = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($sub)) {
            tap_t_fail('subscribe created pending sub: ' . $sub->get_error_message());
        } else {
            $pay_track('subs', (int) $sub);
            tap_t_pass('subscribe created pending sub id=' . $sub);
            $until = TAP_Payment::confirm_subscription_payment((int) $sub, 'paypal', 'CAP-1');
            $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_agency_subscriptions WHERE id=%d", (int) $sub));
            $ps = $wpdb->get_var($wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}tap_agency_subscriptions WHERE id=%d", (int) $sub));
            if ($st === 'active' && $ps === 'paid' && $until) {
                tap_t_pass('confirm_subscription_payment marks sub active+paid, until=' . $until);
            } else {
                tap_t_fail("confirm_subscription_payment failed st=$st ps=$ps");
            }
        }
    }

    if (class_exists('TAP_Promotions') && $tour && $agency) {
        $preq = TAP_Promotions::request($agency, $tour, 1);
        if (is_wp_error($preq) && $preq->get_error_code() === 'no_slots') {
            tap_t_pass('promo request guarded by plan slots: ' . $preq->get_error_message());
        } else {
            if (!is_wp_error($preq)) {
                $pay_track('promos', (int) $preq);
            }
            tap_t_fail('free-plan promo request not rejected with no_slots');
        }

        $promo = 0;
        if (is_wp_error($preq)) {
            $wpdb->insert($wpdb->prefix . 'tap_promos', [
                'agency_id' => (int) $agency, 'listing_id' => (int) $tour, 'months' => 2, 'amount' => 10.00,
                'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'manual', 'notes' => 'e2e',
            ]);
            $promo = (int) $wpdb->insert_id;
            if ($promo > 0) {
                $pay_track('promos', $promo);
            }
        }
        tap_t_assert($promo > 0, 'owned pending promotion fixture created');
        if ($promo) {
            TAP_Payment::confirm_promotion_payment($promo, 'paypal', 'CAP-2');
            $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tap_promos WHERE id=%d", $promo));
            $flag = get_post_meta($tour, '_tap_tour_is_featured', true);
            $until = get_post_meta($tour, '_tap_tour_featured_until', true);
            if ($st === 'active' && $flag === '1' && $until) {
                tap_t_pass('confirm_promotion_payment activates promo + featured meta');
            } else {
                tap_t_fail("promo activation st=$st flag=$flag until=" . var_export($until, true));
            }
        }
    } else {
        tap_t_fail('promotion fixtures missing for promo activation test');
    }
} catch (Throwable $e) {
    tap_t_fail('exception during suite: ' . $e->getMessage());
} finally {
    if ($unblock) {
        remove_filter('pre_wp_mail', $unblock, 10);
    }
    $pay_cleanup();
}

tap_t_finish();
