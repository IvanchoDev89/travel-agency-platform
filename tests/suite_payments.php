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
    'bookings' => [],
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
    foreach ($pay_ids['bookings'] as $id) {
        tap_t_cleanup_bookings([(int) $id]);
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

    // ── renewal keeps the remaining paid days (a fresh pending row extends) ──
    if ($plan && isset($sub) && !is_wp_error($sub)) {
        $sub_renew = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($sub_renew)) {
            tap_t_fail('renewal subscribe created pending sub: ' . $sub_renew->get_error_message());
        } else {
            $pay_track('subs', (int) $sub_renew);
            $until_renew = TAP_Payment::confirm_subscription_payment((int) $sub_renew, 'paypal', 'CAP-RENEW');
            tap_t_assert(
                $until_renew && strtotime((string) $until_renew) > strtotime((string) $until),
                'renewal extends the existing paid_until instead of restarting from today'
            );
        }
    }

    // ── a pending row is a reusable draft, never a lock ──────────────────────
    // subscribe_paypal() inserts the row before PayPal answers, so a timeout or
    // an abandoned checkout must leave the agency free to try again.
    if ($plan) {
        $sub_draft = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($sub_draft)) {
            tap_t_fail('draft subscription could not be opened: ' . $sub_draft->get_error_message());
        } else {
            $pay_track('subs', (int) $sub_draft);
            $sub_retry = TAP_Subscriptions::subscribe($agency, $plan_id);
            tap_t_assert(
                !is_wp_error($sub_retry) && (int) $sub_retry === (int) $sub_draft,
                'a repeated subscribe() for the same plan reuses the pending row instead of failing'
            );

            $wpdb->insert($wpdb->prefix . 'tap_plans', [
                'name'            => 'Pay E2E Switch ' . wp_generate_password(6, false),
                'slug'            => 'pay-e2e-switch-' . wp_generate_password(8, false),
                'price_monthly'   => 19.95,
                'commission_rate' => 6.00,
                'listing_limit'   => 50,
                'featured_slots'  => 2,
                'is_active'       => 1,
            ]);
            $plan_switch = (int) $wpdb->insert_id;
            if ($plan_switch > 0) {
                $pay_track('plans', $plan_switch);
            }

            $sub_switch = TAP_Subscriptions::subscribe($agency, $plan_switch);
            tap_t_assert(
                !is_wp_error($sub_switch) && (int) $sub_switch > 0 && (int) $sub_switch !== (int) $sub_draft,
                'picking another plan while a draft is open starts a new subscription'
            );
            if (!is_wp_error($sub_switch)) {
                $pay_track('subs', (int) $sub_switch);
            }
            $old_status = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . TAP_Subscriptions::subs_table() . ' WHERE id = %d',
                (int) $sub_draft
            ));
            tap_t_assert(
                'pending' !== (string) $old_status,
                'the superseded draft no longer sits in pending state'
            );
            $pay_cur = TAP_Subscriptions::current_subscription($agency);
            tap_t_assert(
                $pay_cur && (int) $pay_cur->plan_id === $plan_switch,
                'current_subscription resolves the plan that replaced the draft'
            );
            tap_t_assert(
                1 === (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . TAP_Subscriptions::subs_table() . " WHERE agency_id = %d AND status = 'pending'",
                    (int) $agency
                )),
                'the agency is left with exactly one pending draft'
            );
        }
    }

    // ── default_plan() never falls back to a paid plan ───────────────────────
    $plans_table = TAP_Subscriptions::plans_table();
    $plan_active_backup = $wpdb->get_results("SELECT id, is_active FROM {$plans_table}");
    $wpdb->query("UPDATE {$plans_table} SET is_active = 0 WHERE price_monthly <= 0");
    $def_plan = TAP_Subscriptions::default_plan();
    foreach ($plan_active_backup as $pb) {
        $wpdb->update($plans_table, ['is_active' => (int) $pb->is_active], ['id' => (int) $pb->id]);
    }
    tap_t_assert((float) $def_plan->price_monthly <= 0, 'default_plan stays free when no free plan is active');

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

    // ── partial capture tracks the paid amount; a retry charges the balance ──
    $pay_b = tap_t_seed_booking(['total_amount' => 100, 'payment_status' => 'pending', 'commission_amount' => 10]);
    $pay_track('bookings', $pay_b);

    $partial = (object) ['id' => 'CAP-PAY-PART-1', 'amount' => (object) ['value' => '30.00', 'currency_code' => TAP_Currency::code()]];
    $pr = TAP_Booking::record_paid_capture($pay_b, 'paypal', 'CAP-PAY-PART-1', $partial);
    $pb = TAP_Booking::get_booking($pay_b);
    tap_t_assert(is_wp_error($pr) && 'partial' === $pr->get_error_code(), 'shortfall capture marked partial');
    tap_t_assert($pb && $pb->payment_status === 'partial', 'booking payment_status becomes partial');
    tap_t_assert($pb && round((float) $pb->amount_paid, 2) === 30.0, 'amount_paid records the captured shortfall');
    tap_t_assert($pb && round(TAP_Booking::remaining_amount($pb), 2) === 70.0, 'remaining balance is total minus paid');

    $balance = (object) ['id' => 'CAP-PAY-PART-2', 'amount' => (object) ['value' => '70.00', 'currency_code' => TAP_Currency::code()]];
    $ok = TAP_Booking::record_paid_capture($pay_b, 'paypal', 'CAP-PAY-PART-2', $balance);
    $pb2 = TAP_Booking::get_booking($pay_b);
    tap_t_assert(true === $ok, 'retry capture that clears the balance completes');
    tap_t_assert($pb2 && $pb2->payment_status === 'paid', 'retry payment flips booking to paid');
    tap_t_assert($pb2 && round((float) $pb2->amount_paid, 2) === 100.0, 'amount_paid equals total after retry');

    // ── full online payment (mocked PayPal HTTP) earns the commission ─────────
    // Drives the real chain the frontend uses: create_order -> capture_order ->
    // record_paid_capture, proving the commission flips waiting -> owed on a
    // genuine capture and that a replay cannot re-earn it.
    $orig_cid = get_option('tap_paypal_client_id', '');
    $orig_sec = get_option('tap_paypal_secret', '');
    tap_t_guard_paypal_credentials();
    update_option('tap_paypal_client_id', 'test-client');
    update_option('tap_paypal_secret', 'test-secret');
    tap_t_install_paypal_mock();

    $pay_full = tap_t_seed_booking([
        'total_amount' => 99, 'commission_amount' => 9.9,
        'payment_status' => 'pending', 'status' => 'pending', 'commission_status' => 'waiting',
    ]);
    $pay_track('bookings', $pay_full);

    $order = TAP_PayPal::create_order($pay_full, home_url('/checkout'), home_url('/'));
    tap_t_assert(is_array($order) && !empty($order['order_id']), 'E2E: create_order returns an order id');
    $oid = $order['order_id'];
    $pay_track('orders', $oid);

    $ledger = TAP_Payment::resolve_order($oid);
    tap_t_assert($ledger && 'booking' === $ledger->object_type && (int) $ledger->object_id === $pay_full, 'E2E: order recorded in the payment ledger for the booking');
    tap_t_assert((int) TAP_Payment::find_booking_by_order($oid) === $pay_full, 'E2E: find_booking_by_order resolves the booking via the ledger');

    $cap = TAP_PayPal::capture_order($oid);
    tap_t_assert(is_array($cap) && 'COMPLETED' === $cap['capture_status'], 'E2E: capture completes');

    $done = TAP_Booking::record_paid_capture($pay_full, 'paypal', $cap['capture_id'], $cap['full_response']);
    $row = TAP_Booking::get_booking($pay_full);
    tap_t_assert(true === $done, 'E2E: record_paid_capture succeeds');
    tap_t_assert($row && 'paid' === $row->payment_status && 'confirmed' === $row->status, 'E2E: booking becomes paid + confirmed');
    tap_t_assert($row && round((float) $row->amount_paid, 2) === 99.0, 'E2E: amount_paid equals the order total');
    tap_t_assert($row && 'owed' === $row->commission_status, 'E2E: capture earns the commission (waiting -> owed)');

    $replay = TAP_Booking::record_paid_capture($pay_full, 'paypal', $cap['capture_id'], $cap['full_response']);
    tap_t_assert(is_wp_error($replay) && 'already_paid' === $replay->get_error_code(), 'E2E: replayed capture is idempotent (already_paid)');

    // Webhook-shaped capture resource (amount at the top level) earns it too.
    $wh = tap_t_seed_booking([
        'total_amount' => 40, 'commission_amount' => 4,
        'payment_status' => 'pending', 'status' => 'pending', 'commission_status' => 'waiting',
    ]);
    $pay_track('bookings', $wh);
    $whres = TAP_Booking::record_paid_capture($wh, 'paypal', 'CAP-WH-1', (object) ['id' => 'CAP-WH-1', 'amount' => (object) ['value' => '40.00', 'currency_code' => TAP_Currency::code()]]);
    $whrow = TAP_Booking::get_booking($wh);
    tap_t_assert(true === $whres && $whrow && 'owed' === $whrow->commission_status, 'E2E: webhook-shaped capture earns the commission too');

    tap_t_remove_paypal_mock();
    update_option('tap_paypal_client_id', $orig_cid);
    update_option('tap_paypal_secret', $orig_sec);

    $pay_done = tap_t_seed_booking(['total_amount' => 60, 'payment_status' => 'paid', 'commission_amount' => 6]);
    $pay_track('bookings', $pay_done);
    $no_order = TAP_PayPal::create_order($pay_done, 'https://example.local/return', 'https://example.local/cancel');
    tap_t_assert(is_wp_error($no_order) && 'already_paid' === $no_order->get_error_code(), 'create_order refuses a fully paid booking before fetching a token');

    // ── webhook event dispatch (PayPal -> REST -> handler) ────────────────────
    // handle_paypal_webhook() reads php://input and exit()s, so it cannot be
    // driven in-process. These cover the dispatch it performs on a verified
    // event: invoke the private handlers directly (reflection, no production
    // change) and assert the booking/ledger side effects for each event type.
    $wh_call = function (string $method, $event) {
        $m = new ReflectionMethod('TAP_Payment', $method);
        $m->setAccessible(true);
        return $m->invoke(null, $event);
    };

    // PAYMENT.CAPTURE.COMPLETED -> booking paid for the full amount + commission earned
    $whc = tap_t_seed_booking(['total_amount' => 150, 'commission_amount' => 15, 'payment_status' => 'pending', 'status' => 'pending', 'commission_status' => 'waiting']);
    $pay_track('bookings', $whc);
    $pay_track('meta', ['post_id' => $whc, 'key' => '_tap_paypal_capture_id']);
    $pay_track('meta', ['post_id' => $whc, 'key' => '_tap_payment_details']);
    $wh_ok_order = 'ORD-WH-OK-' . wp_generate_password(6, false);
    $pay_track('orders', $wh_ok_order);
    TAP_Payment::record_order($wh_ok_order, 'booking', $whc, 150, 'created');
    $ev_ok = (object) [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource'   => (object) [
            'id'     => 'CAP-WH-OK',
            'amount' => (object) ['value' => '150.00', 'currency_code' => TAP_Currency::code()],
            'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $wh_ok_order]],
        ],
    ];
    $wh_call('handle_capture_completed', $ev_ok);
    $wh_row = TAP_Booking::get_booking($whc);
    tap_t_assert($wh_row && 'paid' === $wh_row->payment_status && round((float) $wh_row->amount_paid, 2) === 150.0, 'webhook CAPTURE.COMPLETED marks the booking paid for the full amount');
    tap_t_assert($wh_row && 'owed' === $wh_row->commission_status, 'webhook CAPTURE.COMPLETED earns the commission (waiting -> owed)');
    $wh_led = TAP_Payment::resolve_order($wh_ok_order);
    tap_t_assert($wh_led && 'completed' === $wh_led->status && 'CAP-WH-OK' === $wh_led->capture_id, 'webhook CAPTURE.COMPLETED updates the payment ledger');
    $wh_call('handle_capture_completed', $ev_ok);
    $wh_row2 = TAP_Booking::get_booking($whc);
    tap_t_assert($wh_row2 && 'owed' === $wh_row2->commission_status && round((float) $wh_row2->amount_paid, 2) === 150.0, 'webhook CAPTURE.COMPLETED replay does not double-credit');

    // PAYMENT.CAPTURE.DENIED -> payment failed
    $whd = tap_t_seed_booking(['total_amount' => 80, 'commission_amount' => 8, 'payment_status' => 'pending', 'status' => 'pending', 'commission_status' => 'waiting']);
    $pay_track('bookings', $whd);
    $wh_den_order = 'ORD-WH-DEN-' . wp_generate_password(6, false);
    $pay_track('orders', $wh_den_order);
    TAP_Payment::record_order($wh_den_order, 'booking', $whd, 80, 'created');
    $ev_den = (object) [
        'event_type' => 'PAYMENT.CAPTURE.DENIED',
        'resource'   => (object) ['id' => 'CAP-WH-DEN', 'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $wh_den_order]]],
    ];
    $wh_call('handle_capture_denied', $ev_den);
    $wh_den_row = TAP_Booking::get_booking($whd);
    tap_t_assert($wh_den_row && 'failed' === $wh_den_row->payment_status, 'webhook CAPTURE.DENIED marks the booking payment failed');

    // PAYMENT.CAPTURE.REFUNDED -> refunded + commission voided + refund id kept
    $whr = tap_t_seed_booking(['total_amount' => 60, 'commission_amount' => 6, 'payment_status' => 'paid', 'status' => 'confirmed', 'commission_status' => 'owed']);
    $pay_track('bookings', $whr);
    $pay_track('meta', ['post_id' => $whr, 'key' => '_tap_paypal_refund_id']);
    $wh_ref_order = 'ORD-WH-REF-' . wp_generate_password(6, false);
    $pay_track('orders', $wh_ref_order);
    TAP_Payment::record_order($wh_ref_order, 'booking', $whr, 60, 'completed');
    $ev_ref = (object) [
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'resource'   => (object) ['id' => 'CAP-WH-REF', 'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $wh_ref_order]]],
    ];
    $wh_call('handle_capture_refunded', $ev_ref);
    $wh_ref_row = TAP_Booking::get_booking($whr);
    tap_t_assert($wh_ref_row && 'refunded' === $wh_ref_row->payment_status, 'webhook CAPTURE.REFUNDED marks the booking refunded');
    tap_t_assert($wh_ref_row && 'void' === $wh_ref_row->commission_status, 'webhook CAPTURE.REFUNDED voids the earned commission');
    tap_t_assert('CAP-WH-REF' === get_post_meta($whr, '_tap_paypal_refund_id', true), 'webhook CAPTURE.REFUNDED records the refund id');
    $wh_call('handle_capture_refunded', $ev_ref);
    $wh_ref_row2 = TAP_Booking::get_booking($whr);
    tap_t_assert($wh_ref_row2 && 'refunded' === $wh_ref_row2->payment_status && 'void' === $wh_ref_row2->commission_status, 'webhook CAPTURE.REFUNDED replay is idempotent');

    // PAYMENT.CAPTURE.REFUNDED (partial) -> money partly back, booking NOT fully
    // refunded and the earned commission is NOT voided.
    $whp = tap_t_seed_booking(['total_amount' => 100, 'amount_paid' => 100, 'commission_amount' => 10, 'payment_status' => 'paid', 'status' => 'confirmed', 'commission_status' => 'owed']);
    $pay_track('bookings', $whp);
    $pay_track('meta', ['post_id' => $whp, 'key' => '_tap_paypal_refund_id']);
    $wh_part_order = 'ORD-WH-PART-' . wp_generate_password(6, false);
    $pay_track('orders', $wh_part_order);
    TAP_Payment::record_order($wh_part_order, 'booking', $whp, 100, 'completed');
    $ev_part = (object) [
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'resource'   => (object) ['id' => 'CAP-WH-PART', 'amount' => (object) ['value' => '30.00', 'currency_code' => TAP_Currency::code()], 'supplementary_data' => (object) ['related_ids' => (object) ['order_id' => $wh_part_order]]],
    ];
    $wh_call('handle_capture_refunded', $ev_part);
    $wh_part_row = TAP_Booking::get_booking($whp);
    tap_t_assert($wh_part_row && 'paid' === $wh_part_row->payment_status, 'partial webhook refund keeps the booking paid');
    tap_t_assert($wh_part_row && 'owed' === $wh_part_row->commission_status, 'partial webhook refund does NOT void the commission');
    tap_t_assert('CAP-WH-PART' === get_post_meta($whp, '_tap_paypal_refund_id', true), 'partial webhook refund records the refund id');

    // CHECKOUT.ORDER.APPROVED -> fires tap_payment_approved for the booking
    $wha = tap_t_seed_booking(['total_amount' => 20, 'payment_status' => 'pending', 'status' => 'pending', 'commission_status' => 'waiting']);
    $pay_track('bookings', $wha);
    $wh_app_order = 'ORD-WH-APP-' . wp_generate_password(6, false);
    $pay_track('orders', $wh_app_order);
    TAP_Payment::record_order($wh_app_order, 'booking', $wha, 20, 'created');
    $approved_hits = [];
    $appr_cb = function ($bid, $method, $oid) use (&$approved_hits) {
        $approved_hits[] = [(int) $bid, (string) $method, (string) $oid];
    };
    add_action('tap_payment_approved', $appr_cb, 10, 3);
    $wh_call('handle_order_approved', (object) ['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => (object) ['id' => $wh_app_order]]);
    remove_action('tap_payment_approved', $appr_cb, 10);
    tap_t_assert($approved_hits && $approved_hits[0][0] === $wha && $approved_hits[0][1] === 'paypal' && $approved_hits[0][2] === $wh_app_order, 'webhook CHECKOUT.ORDER.APPROVED fires tap_payment_approved for the booking');
} catch (Throwable $e) {
    tap_t_fail('exception during suite: ' . $e->getMessage());
} finally {
    if ($unblock) {
        remove_filter('pre_wp_mail', $unblock, 10);
    }
    $pay_cleanup();
}

tap_t_finish();
