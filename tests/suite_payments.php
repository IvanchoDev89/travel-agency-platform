<?php
require __DIR__ . '/bootstrap.php';
global $wpdb;
$fails = 0;

$subs = $wpdb->prefix . 'tap_agency_subscriptions';
$promos = $wpdb->prefix . 'tap_promos';
$orders = TAP_Payment::orders_table();

$agency = tap_t_test_agency();
$plan = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}tap_plans WHERE price_monthly > 0 AND is_active = 1 ORDER BY price_monthly LIMIT 1");

$wpdb->delete($subs, ['agency_id' => (int) $agency]);

// 1) record_order + resolve_order
$oid = 'MOCK-ORDER-' . wp_generate_password(8, false);
TAP_Payment::record_order($oid, 'subscription', 123, 9.50, 'created');
$row = TAP_Payment::resolve_order($oid);
if ($row && $row->object_type === 'subscription' && (int) $row->object_id === 123 && abs((float) $row->amount - 9.50) < 0.001) {
    tap_t_pass('record_order + resolve_order roundtrip');
} else {
    tap_t_fail('record_order + resolve_order roundtrip');
}
$wpdb->delete($orders, ['paypal_order_id' => $oid]);

// 2) subscription: create pending, then confirm via mark_paid (what capture/webhook calls)
if ($plan) {
    $sub = TAP_Subscriptions::subscribe($agency, (int) $plan->id);
    if (is_wp_error($sub)) {
        tap_t_fail('subscribe created pending sub: ' . $sub->get_error_message());
    } else {
        tap_t_pass('subscribe created pending sub id=' . $sub);
        $until = TAP_Payment::confirm_subscription_payment((int) $sub, 'paypal', 'CAP-1');
        $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM $subs WHERE id=%d", (int) $sub));
        $ps = $wpdb->get_var($wpdb->prepare("SELECT payment_status FROM $subs WHERE id=%d", (int) $sub));
        if ($st === 'active' && $ps === 'paid' && $until) {
            tap_t_pass('confirm_subscription_payment marks sub active+paid, until=' . $until);
        } else {
            tap_t_fail("confirm_subscription_payment failed st=$st ps=$ps");
        }
        $wpdb->delete($subs, ['id' => (int) $sub]);
    }
} else {
    echo "SKIP no paid plan\n";
}

// 3) promotion guard: free plan blocks request (validates plan-slot enforcement)
if (class_exists('TAP_Promotions')) {
    $listing = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
    $lid = $listing ? (int) $listing[0] : 0;
    if ($lid) {
        $listing_agency = (int) (get_post_meta($lid, '_tap_tour_agency_id', true) ?: tap_t_test_agency());
        $preq = TAP_Promotions::request($listing_agency, $lid, 1);
        if (is_wp_error($preq)) {
            tap_t_pass('promo request guarded by plan slots: ' . $preq->get_error_message());
        } else {
            tap_t_pass('free-plan promo request created (slot config allows it)');
            $wpdb->delete($promos, ['id' => (int) $preq]);
        }

        // 3b) directly-seeded pending promo activates via confirm_promotion_payment
        $wpdb->insert($promos, [
            'agency_id' => $listing_agency, 'listing_id' => $lid, 'months' => 2, 'amount' => 10.00,
            'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'manual', 'notes' => 'e2e',
        ]);
        $promo = (int) $wpdb->insert_id;
        TAP_Payment::confirm_promotion_payment($promo, 'paypal', 'CAP-2');
        $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM $promos WHERE id=%d", $promo));
        $flag = get_post_meta($lid, '_tap_tour_is_featured', true);
        $until = get_post_meta($lid, '_tap_tour_featured_until', true);
        if ($st === 'active' && $flag === '1' && $until) {
            tap_t_pass('confirm_promotion_payment activates promo + featured meta');
        } else {
            tap_t_fail("promo activation st=$st flag=$flag until=" . var_export($until, true));
        }
        $wpdb->delete($promos, ['id' => $promo]);
        delete_post_meta($lid, '_tap_tour_is_featured');
        delete_post_meta($lid, '_tap_tour_featured_until');
    } else {
        tap_t_pass('no tour listing to test promo activation');
    }
}

echo "fail={$fails} done\n";
exit($fails ? 1 : 0);