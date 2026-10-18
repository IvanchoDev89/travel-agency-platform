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
        $ids = array_values(array_unique(array_map('intval', $booking_ids)));
        $in  = implode(',', $ids);
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$in})");
        // A ledger row may group several bookings in one booking_ids cell
        // ("5,9"); IN() only matches single-id rows, so also drop any row whose
        // whole set is contained in the ids we are cleaning up.
        $commission_table = $wpdb->prefix . 'tap_commission_payments';
        foreach ($wpdb->get_results("SELECT id, booking_ids FROM {$commission_table}") as $row) {
            $parts = array_filter(array_map('intval', explode(',', (string) $row->booking_ids)));
            if ($parts && !array_diff($parts, $ids)) {
                $wpdb->delete($commission_table, ['id' => (int) $row->id]);
            }
        }
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
            'payment_status'   => 'paid',
            'status'           => 'confirmed',
            'created_at'       => current_time('mysql'),
        ];
        $wpdb->insert($wpdb->prefix . 'tap_bookings', array_merge($defaults, $overrides));
        return (int) $wpdb->insert_id;
    }

    /** Drop consent rows left behind by throwaway (@example.*) test users. */
    function tap_t_cleanup_test_consents() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}tap_consents
             WHERE email LIKE '%@example.test' OR email LIKE '%@example.invalid'"
        );
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

    /** Unique throwaway email for one suite run. */
    function tap_t_unique_email() {
        return 'tap_e2e_' . wp_generate_password(8, false) . '@example.test';
    }

    /** Unique username/label suffix for one suite run. */
    function tap_t_suffix() {
        return strtolower(wp_generate_password(8, false));
    }

    /** Throwaway strong password. */
    function tap_t_unique_pass() {
        return wp_generate_password(16, false);
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

    /** Like tap_t_find_service() but skips services that already carry reviews,
     *  so review suites never inherit junk left on a shared listing by an
     *  earlier failed run. Falls back to the first published service. */
    function tap_t_find_clean_service($prefer_non_tour = true) {
        global $wpdb;
        $order = $prefer_non_tour
            ? ['tap_package', 'tap_boat', 'tap_transport', 'tap_car_rental', 'tap_accommodation', 'tap_tour']
            : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $fallback = [0, ''];
        foreach ($order as $t) {
            $ids = get_posts(['post_type' => $t, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            foreach ($ids as $id) {
                $id = (int) $id;
                if (!$fallback[0]) {
                    $fallback = [$id, $t];
                }
                $has = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE service_type = %s AND service_id = %d",
                    $t,
                    $id
                ));
                if (!$has) {
                    return [$id, $t];
                }
            }
        }
        return $fallback;
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

    /** Temporary administrator (holds every TAP capability) for admin-page tests. */
    function tap_t_make_admin() {
        $suffix = wp_generate_password(5, false);
        $uid = wp_insert_user([
            'user_login'   => 'tap_admin_' . $suffix,
            'user_pass'    => wp_generate_password(12, false),
            'user_email'   => 'tap_admin_' . $suffix . '@example.test',
            'role'         => 'administrator',
            'display_name' => 'TAP Admin',
        ]);
        return (is_wp_error($uid) || !$uid) ? 0 : (int) $uid;
    }

    /** Install a pre_http_request mock for PayPal; responses keyed by URL shape. */
    function tap_t_install_paypal_mock() {
        $GLOBALS['tap_pp_log'] = [];
        $GLOBALS['tap_pp_counter'] = 0;
        $GLOBALS['tap_pp_amounts'] = [];
        $GLOBALS['tap_pp_mock'] = function ($pre, $args, $url) {
            $GLOBALS['tap_pp_log'][] = ['url' => $url, 'args' => $args];
            $json = function ($data) {
                return ['headers' => [], 'body' => json_encode($data), 'response' => ['code' => 200], 'cookies' => [], 'filename' => null];
            };
            if (strpos($url, '/v1/oauth2/token') !== false) {
                return $json(['access_token' => 'tok-test', 'token_type' => 'Bearer', 'expires_in' => 3600]);
            }
            if (preg_match('#/v2/checkout/orders/([^/]+)/capture$#', $url, $m)) {
                // Echo back the amount that was requested for THIS order, so a
                // capture is verifiable against the booking total (a real PayPal
                // capture resource always carries amount.value/currency_code).
                $amt = $GLOBALS['tap_pp_amounts'][$m[1]] ?? ['value' => '10.00', 'currency_code' => 'USD'];
                return $json([
                    'id'     => $m[1],
                    'status' => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id' => 'CAP-PP-1', 'status' => 'COMPLETED',
                            'amount' => $amt,
                            'seller_receivable_breakdown' => ['gross_amount' => $amt],
                        ]]],
                    ]],
                ]);
            }
            if (preg_match('#/refund$#', $url)) {
                return $json(['id' => 'REF-PP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]);
            }
            if (strpos($url, '/v2/checkout/orders') !== false) {
                // Real orders have unique ids — and booking coupons/orders are
                // now recorded in the payment ledger, so a reused id would
                // silently overwrite an existing ledger row.
                $GLOBALS['tap_pp_counter']++;
                $oid = 'ORD-PP-' . $GLOBALS['tap_pp_counter'];
                $body = json_decode($args['body'] ?? '', true);
                $amt = $body['purchase_units'][0]['amount'] ?? null;
                if (is_array($amt) && isset($amt['value'])) {
                    $GLOBALS['tap_pp_amounts'][$oid] = ['value' => (string) $amt['value'], 'currency_code' => (string) ($amt['currency_code'] ?? 'USD')];
                }
                return $json(['id' => $oid, 'status' => 'CREATED', 'links' => [['rel' => 'payer-action', 'href' => 'https://pp.test/approve']]]);
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

    /**
     * Remember the live PayPal credentials before a suite overwrites them.
     *
     * The suites swap in placeholder credentials so is_ready() lets the
     * checkout code run. The inline restore never happens if the suite dies
     * in between, which silently pointed a working site at `test-client` —
     * every PayPal payment then failed at the token request. Backing up here
     * and restoring from a shutdown function makes the swap unlosable.
     */
    function tap_t_guard_paypal_credentials() {
        foreach (['tap_paypal_client_id', 'tap_paypal_secret'] as $name) {
            if (!array_key_exists($name, (array) ($GLOBALS['tap_t_paypal_options'] ?? []))) {
                $GLOBALS['tap_t_paypal_options'][$name] = get_option($name, '');
            }
        }
    }

    /** Put the remembered credentials back (idempotent; safe to call twice). */
    function tap_t_restore_paypal_credentials() {
        foreach ((array) ($GLOBALS['tap_t_paypal_options'] ?? []) as $name => $value) {
            if ('' === $value || null === $value) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
    }
}

if (function_exists('tap_t_restore_paypal_credentials')) {
    register_shutdown_function('tap_t_restore_paypal_credentials');
}

// Tanda C/D: fresh public rate-limit buckets per suite run, so repeated
// in-hour battery runs never trip a per-IP limit from previous executions.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_tap_rl_%'
        OR option_name LIKE '_transient_timeout_tap_rl_%'
        OR option_name LIKE '_transient_tap_agency_reg_%'
        OR option_name LIKE '_transient_timeout_tap_agency_reg_%'
        OR option_name LIKE '_transient_tap_chat_%'
        OR option_name LIKE '_transient_timeout_tap_chat_%'"
);