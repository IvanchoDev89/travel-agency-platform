<?php
/**
 * Shared helpers for TAP E2E suites.
 * Do not run directly — include from a suite XX.php that is executed via:
 *   wp --path="<site>/app/public" eval-file tests/<suite>.php
 */

if (!function_exists('tap_t_pass')) {
    $GLOBALS['tap_failures'] = 0;
    $GLOBALS['tap_passes']   = 0;

    function tap_t_pass($msg) {
        $GLOBALS['tap_passes']++;
        echo "[PASS] {$msg}\n";
    }

    function tap_t_fail($msg) {
        $GLOBALS['tap_failures']++;
        echo "[FAIL] {$msg}\n";
    }

    function tap_t_assert($cond, $msg) {
        $cond ? tap_t_pass($msg) : tap_t_fail($msg);
    }

    /** Run generated results; prints fail=N and exits non-zero on failure. */
    function tap_t_finish() {
        $f = $GLOBALS['tap_failures'];
        echo "fail={$f} done\n";
        exit($f ? 1 : 0);
    }

    /** Resolve booking ids referenced by a commission payment's booking_ids.',
     *  Deletes all rows we create during a suite so re-runs are idempotent. */
    function tap_t_cleanup_bookings(array $booking_ids) {
        global $wpdb;
        if (!$booking_ids) {
            return;
        }
        $in = implode(',', array_map('intval', $booking_ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_commission_payments WHERE booking_ids IN ({$in})");
    }

    function tap_t_seed_booking($overrides = []) {
        global $wpdb;
        $defaults = [
            'booking_code'     => 'TAP-E2E-' . wp_generate_password(6, false),
            'agency_id'        => tap_t_test_agency(),
            'service_type'     => 'tap_tour',
            'service_id'       => 75,
            'total_amount'     => 100,
            'commission_amount'=> 10,
            'commission_percent' => 10,
            'commission_status'=> 'owed',
            'status'           => 'confirmed',
            'created_at'       => current_time('mysql'),
        ];
        $wpdb->insert($wpdb->prefix . 'tap_bookings', array_merge($defaults, $overrides));
        return (int) $wpdb->insert_id;
    }

    /** Agency post id used as the default for seeded bookings. */
    function tap_t_test_agency() {
        $posts = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
        return $posts ? (int) $posts[0] : 68;
    }

    function tap_t_clear_views($listing_id = null) {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_listing_views';
        if (null === $listing_id) {
            $wpdb->query("TRUNCATE {$t}");
        } else {
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE listing_id = %d", $listing_id));
        }
    }
}

if (!function_exists('tap_t_find_service')) {
    /** First published service on the site: [id, type]. Prefer non-tour for bookings. */
    function tap_t_find_service($prefer_non_tour = true) {
        $order = $prefer_non_tour
            ? ['tap_package', 'tap_boat', 'tap_transport', 'tap_car_rental', 'tap_accommodation', 'tap_tour']
            : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        foreach ($order as $t) {
            $posts = get_posts(['post_type' => $t, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
            if ($posts) {
                return [(int) $posts[0], $t];
            }
        }
        return [0, ''];
    }

    /** Throwaway subscriber user; returns uid or 0. */
    function tap_t_make_subscriber() {
        $suffix = wp_generate_password(5, false);
        $uid = wp_insert_user([
            'user_login'   => 'tap_e2e_' . $suffix,
            'user_pass'    => wp_generate_password(12, false),
            'user_email'   => 'tap_e2e_' . $suffix . '@example.test',
            'role'         => 'subscriber',
            'display_name' => 'TAP E2E',
        ]);
        return (is_wp_error($uid) || !$uid) ? 0 : (int) $uid;
    }

    /** Install a pre_http_request mock for PayPal; responses keyed by URL shape. */
    function tap_t_install_paypal_mock() {
        $GLOBALS['tap_pp_log'] = [];
        $GLOBALS['tap_pp_mock'] = function ($pre, $args, $url) {
            $GLOBALS['tap_pp_log'][] = ['url' => $url, 'args' => $args];
            $json = function ($data) {
                return ['headers' => [], 'body' => json_encode($data), 'response' => ['code' => 200], 'cookies' => [], 'filename' => null];
            };
            if (strpos($url, '/v1/oauth2/token') !== false) {
                return $json(['access_token' => 'tok-test', 'token_type' => 'Bearer', 'expires_in' => 3600]);
            }
            if (preg_match('#/capture$#', $url)) {
                return $json([
                    'id'     => 'ORD-PP-1',
                    'status' => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id' => 'CAP-PP-1', 'status' => 'COMPLETED',
                            'seller_receivable_breakdown' => ['gross_amount' => ['value' => '10.00', 'currency_code' => 'USD']],
                        ]]],
                    ]],
                ]);
            }
            if (preg_match('#/refund$#', $url)) {
                return $json(['id' => 'REF-PP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]);
            }
            if (strpos($url, '/v2/checkout/orders') !== false) {
                return $json(['id' => 'ORD-PP-1', 'status' => 'CREATED', 'links' => [['rel' => 'payer-action', 'href' => 'https://pp.test/approve']]]);
            }
            if (strpos($url, '/verify-webhook-signature') !== false) {
                return $json(['verification_status' => 'SUCCESS']);
            }
            return $json(['message' => 'no mock route']);
        };
        add_filter('pre_http_request', $GLOBALS['tap_pp_mock'], 10, 3);
    }

    function tap_t_remove_paypal_mock() {
        if (!empty($GLOBALS['tap_pp_mock'])) {
            remove_filter('pre_http_request', $GLOBALS['tap_pp_mock'], 10);
        }
        $GLOBALS['tap_pp_mock'] = null;
        $GLOBALS['tap_pp_log'] = [];
    }

    /** Dispatch a REST request in-process; returns WP_REST_Response|WP_Error. */
    function tap_t_rest($method, $route, $params = []) {
        if (!did_action('rest_api_init')) {
            do_action('rest_api_init');
        }
        $req = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return rest_do_request($req);
    }

    /** REST error code regardless of whether it came back as WP_Error or as a formatted response. */
    function tap_t_rest_error_code($resp) {
        if (is_wp_error($resp)) {
            return $resp->get_error_code();
        }
        if ($resp instanceof WP_REST_Response && $resp->get_status() >= 400) {
            $d = $resp->get_data();
            if (is_array($d) && !empty($d['code'])) {
                return $d['code'];
            }
        }
        return '';
    }

    /** Normalized data from a REST response (object or array payloads). */
    function tap_t_rest_data($resp) {
        if (is_wp_error($resp)) return null;
        $d = $resp->get_data();
        return is_object($d) ? (array) $d : $d;
    }
}