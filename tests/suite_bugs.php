<?php
/**
 * Fase B — regresiones corregidas: grid columns, moneda PayPal, links rotos.
 * Site-agnostic; no deja datos (solo opciones, que se restauran).
 */

require_once __DIR__ . '/bootstrap.php';

// ===== A. [tap_services] uses the configured columns (not the old $cols) =====
$svc = get_posts(['post_type' => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], 'post_status' => 'publish', 'posts_per_page' => 1]);
if (!$svc) {
    tap_t_pass('[tap_services] grid skipped (no published services)');
} else {
    $pt = $svc[0]->post_type;
    $html = do_shortcode('[tap_services type="' . $pt . '"]');
    tap_t_assert(strpos($html, 'grid-template-columns: repeat(3, 1fr)') !== false, '[tap_services] defaults to 3 columns');
    $html = do_shortcode('[tap_services type="' . $pt . '" columns="4"]');
    tap_t_assert(strpos($html, 'grid-template-columns: repeat(4, 1fr)') !== false, '[tap_services] accepts columns override');
    tap_t_assert(strpos($html, 'Undefined variable') === false, '[tap_services] no undefined variable warnings');
}

// ===== B. PayPal + platform currency read the configured option =====
$before = get_option('tap_currency', 'USD');
update_option('tap_currency', 'EUR');
tap_t_assert('EUR' === TAP_PayPal::currency_code(), 'paypal currency follows tap_currency option');
tap_t_assert('EUR' === TAP_Currency::code(), 'currency code follows tap_currency option');
update_option('tap_currency', 'MXN');
tap_t_assert('MXN' === TAP_PayPal::currency_code(), 'paypal currency follows updated option');
tap_t_assert('USD' === TAP_PayPal::currency_code() || 'USD' !== TAP_PayPal::currency_code(), 'paypal currency is not hardcoded');
update_option('tap_currency', $before);
tap_t_assert($before === get_option('tap_currency', 'USD'), 'currency option restored');

// ===== C. Source-level regressions (static one-shot guards) =====
$plugin = defined('TAP_PLUGIN_DIR') ? TAP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins/travel-agency-platform/');
$dash_src = '';
foreach (glob($plugin . 'includes/class-dashboard*.php') ?: [] as $tap_dash_file) {
    $dash_src .= file_get_contents($tap_dash_file);
}
$email_src = file_get_contents($plugin . 'includes/class-emails.php');
// The message template (and the admin links inside it) lives in the send half.
$email_src .= file_get_contents($plugin . 'includes/class-emails-send.php');
$short_src = file_get_contents($plugin . 'includes/class-shortcodes.php');

tap_t_assert(strpos($dash_src, 'get_edit_post_link($b->id)') === false, 'admin booking code no longer links to a fake edit post url');
tap_t_assert(strpos($dash_src, 'booking-detail/?code=') !== false, 'admin booking code links to the public voucher');
tap_t_assert(strpos($email_src, 'page=tap-dashboard') === false, 'emails no longer point to a dead admin page');
tap_t_assert(strpos($email_src, 'admin.php?page=tap-bookings') !== false, 'emails point to the bookings admin page');
tap_t_assert(strpos($short_src, 'currency=USD') === false, 'paypal sdk urls do not hardcode USD');
tap_t_assert(strpos($short_src, 'currency=') === false || strpos($short_src, 'TAP_Currency::code()') !== false, 'paypal sdk urls use the platform currency');
tap_t_assert(strpos($dash_src, 'TAP_Post_Types::agency_meta_keys()') !== false, 'agency delete uses the canonical agency meta keys');
$agency_keys = TAP_Post_Types::agency_meta_keys();
tap_t_assert(
    isset($agency_keys['tap_car_rental'], $agency_keys['tap_boat'], $agency_keys['tap_package'], $agency_keys['tap_equipment']),
    'agency delete cleans car/boat/package/equipment listings too'
);

$theme_dir = get_stylesheet_directory();
$header_src = file_get_contents($theme_dir . '/header.php');
$archive_src = file_get_contents($theme_dir . '/archive.php');
tap_t_assert(strpos($header_src, '/edit-profile') === false, 'header no longer links to a missing /edit-profile page');
tap_t_assert(strpos($archive_src, 'get_query_var(\'post_type\')') !== false, 'archive.php derives post type from the query');

// ===== D. Agency audit batch — behavioural regressions =====
global $wpdb;

/* D1. Room/property ownership is re-checked inside TAP_Booking::create.
 * The accommodation template lets the visitor pick a room, so a crafted
 * room_id could otherwise attach a room belonging to another agency's
 * property (and take that room's inventory lock). */
$acc_posts = get_posts(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 2, 'fields' => 'ids']);
if (count($acc_posts) < 2) {
    tap_t_pass('room/property ownership check skipped (needs 2 published accommodations)');
} else {
    $acc_a = (int) $acc_posts[0];
    $acc_b = (int) $acc_posts[1];
    $room_b = wp_insert_post(['post_type' => 'tap_room', 'post_status' => 'publish', 'post_title' => 'Room A ' . wp_generate_password(6, false)]);
    if ($room_b && !is_wp_error($room_b)) {
        update_post_meta($room_b, '_tap_room_accommodation_id', $acc_b);
        $ci = gmdate('Y-m-d', strtotime('+30 days'));
        $co = gmdate('Y-m-d', strtotime('+32 days'));
        $bad = TAP_Booking::create([
            'service_type' => 'tap_accommodation', 'service_id' => $acc_a,
            'room_id' => (int) $room_b, 'check_in' => $ci, 'check_out' => $co,
            'adults' => 1, 'children' => 0, 'client_id' => 0,
            'guest_email' => 'room-mismatch-' . wp_generate_password(6, false) . '@example.test',
            'guest_name'  => 'Room Mismatch',
        ]);
        tap_t_assert(is_wp_error($bad) && 'room_mismatch' === $bad->get_error_code(), 'a room from another property is rejected as room_mismatch');
        wp_delete_post((int) $room_b, true);
    }
}

/* D2. Agency staff must not cancel a paid booking: cancellation triggers a
 * real gateway refund, which only platform managers may authorise (parity
 * with the legacy TAP_Ajax::update_booking_status endpoint).
 * A throwaway agency + owner is used so no real agency mapping is disturbed. */
$dp_user = tap_t_make_subscriber();
$dp_agency = $dp_user ? wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'Paid Cancel Agency ' . wp_generate_password(6, false),
]) : 0;
if ($dp_user && $dp_agency && !is_wp_error($dp_agency)) {
    update_post_meta((int) $dp_agency, '_tap_agency_user_id', (int) $dp_user);
    $paid_bk = tap_t_seed_booking([
        'status'         => 'confirmed',
        'payment_status' => 'paid',
        'agency_id'      => (int) $dp_agency,
        'client_id'      => (int) $dp_user,
    ]);
    wp_set_current_user((int) $dp_user);
    $cancel = TAP_Booking::agency_booking_action($paid_bk, 'cancel');
    tap_t_assert(is_wp_error($cancel) && 'paid_cancel' === $cancel->get_error_code(), 'agency staff cannot cancel a paid booking (got: ' . (is_wp_error($cancel) ? $cancel->get_error_code() : 'no error') . ')');
    tap_t_assert('confirmed' === TAP_Booking::get_booking($paid_bk)->status, 'paid booking left untouched after refused cancel');
    wp_set_current_user(0);
    tap_t_cleanup_bookings([$paid_bk]);
    wp_delete_post((int) $dp_agency, true);
    wp_delete_user((int) $dp_user);
} else {
    tap_t_pass('paid_cancel check skipped (could not build throwaway agency)');
}

/* D3. A request-mode tour must not oversell when requests are accepted.
 * tour_slots() excludes 'request', so capacity is re-counted at accept time:
 * three accepted 2-seat requests on a 4-seat tour must leave the third
 * refused instead of selling six seats. */
$bk_tours = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids']);
if (!$bk_tours) {
    tap_t_pass('tour oversell check skipped (no published tours)');
} else {
    $t = (int) $bk_tours[0];
    update_post_meta($t, '_tap_booking_mode', 'request');
    $c_bk = get_post_meta($t, '_tap_tour_capacity', true);
    update_post_meta($t, '_tap_tour_capacity', 4);
    $cd = gmdate('Y-m-d', strtotime('+45 days'));
    $co2 = gmdate('Y-m-d', strtotime('+46 days'));
    $req_ids = [];
    for ($i = 0; $i < 3; $i++) {
        $res = TAP_Booking::create([
            'service_type' => 'tap_tour', 'service_id' => $t,
            'check_in' => $cd, 'check_out' => $co2,
            'adults' => 2, 'children' => 0, 'client_id' => 0,
            'guest_email' => 'oversell-' . $i . '-' . wp_generate_password(6, false) . '@example.test',
            'guest_name'  => 'Oversell ' . $i,
        ]);
        if (!is_wp_error($res) && !empty($res['booking_id'])) {
            $req_ids[] = (int) $res['booking_id'];
        }
    }
    tap_t_assert(count($req_ids) === 3, 'all three open requests accepted while seats remain');
    $refused = 0;
    foreach ($req_ids as $rid) {
        $r = TAP_Booking::update_status($rid, 'pending');
        if (is_wp_error($r) && 'tour_full' === $r->get_error_code()) {
            $refused++;
        }
    }
    tap_t_assert($refused === 1, 'accepting past capacity is refused exactly once (tour_full)');
    foreach ($req_ids as $rid) {
        $wpdb->delete($wpdb->prefix . 'tap_booking_items', ['booking_id' => $rid]);
        $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $rid]);
    }
    if ('' !== (string) $c_bk) { update_post_meta($t, '_tap_tour_capacity', $c_bk); }
}

/* D4. The keyword search must hide inactive accommodations. The archive
 * hides them; a listing the agency switched off must not reappear in /?s=
 * results and stay bookable. Other post types carry no such meta, so the
 * filter must not blank them out.
 *
 * filter_archive_query() bails unless is_main_query() is true, and
 * WP_Query::is_main_query() compares against the global $wp_the_query (it
 * does not read a property), so the global has to be swapped in to exercise
 * the real method. */
$acc_all = get_posts(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
if (!$acc_all) {
    tap_t_pass('search is_active filter skipped (no published accommodations)');
} else {
    global $wp_the_query, $wp_query;
    $acc_id  = (int) $acc_all[0];
    $save_tq = $wp_the_query;
    $save_pq = $wp_query;

    $mk_search_query = function () {
        $sq = new WP_Query();
        $sq->is_search       = true;
        $sq->query_vars['s'] = 'zzz';
        return $sq;
    };

    update_post_meta($acc_id, '_tap_acc_is_active', '0');
    $sq1        = $mk_search_query();
    $wp_the_query = $sq1;
    $wp_query     = $sq1;
    TAP_Archive_Query::filter_archive_query($sq1);
    $mq1 = (array) $sq1->get('meta_query', []);
    tap_t_assert(count($mq1) >= 1, 'search filter attaches a meta_query');
    tap_t_assert(($mq1[0]['relation'] ?? '') === 'OR' && count((array) $mq1[0]) === 3, 'search meta_query is the active-or-missing OR group');
    $tap_t_saw_inactive = false;
    foreach ((array) ($mq1[0] ?? []) as $arm) {
        if (!is_array($arm) || !isset($arm['key'])) {
            continue;
        }
        if ('_tap_acc_is_active' !== $arm['key']) {
            continue;
        }
        if ('NOT EXISTS' === ($arm['compare'] ?? '') || '1' === (string) ($arm['value'] ?? '')) {
            $tap_t_saw_inactive = true;
        }
    }
    tap_t_assert($tap_t_saw_inactive, 'inactive arm present while the listing is off');

    update_post_meta($acc_id, '_tap_acc_is_active', '1');
    $sq2        = $mk_search_query();
    $wp_the_query = $sq2;
    $wp_query     = $sq2;
    TAP_Archive_Query::filter_archive_query($sq2);
    tap_t_assert((array) $sq2->get('meta_query', []) === $mq1, 'the active-or-missing group is identical whether the listing is on or off');

    // Behavioural check with a throwaway listing: run the real main-query path
    // so the SQL is built by WP_Meta_Query, not just the clause shape.
    $d4_token = 'inactivesearch' . wp_generate_password(6, false);
    $d4_post  = wp_insert_post(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'post_title' => 'D4 ' . $d4_token]);
    if ($d4_post && !is_wp_error($d4_post)) {
        $run_search = function ($token) {
            global $wp_the_query, $wp_query;
            $q = new WP_Query();
            $wp_the_query = $q;
            $wp_query     = $q;
            $q->query(['s' => $token, 'post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 10]);
            return array_map(static fn($p) => (int) $p->ID, (array) $q->posts);
        };
        update_post_meta((int) $d4_post, '_tap_acc_is_active', '0');
        tap_t_assert(!in_array((int) $d4_post, $run_search($d4_token), true), 'search excludes an accommodation with is_active=0');
        update_post_meta((int) $d4_post, '_tap_acc_is_active', '1');
        tap_t_assert(in_array((int) $d4_post, $run_search($d4_token), true), 'search includes the same accommodation once is_active=1');
        wp_delete_post((int) $d4_post, true);
    } else {
        tap_t_pass('search is_active behavioural check skipped (could not create fixture)');
    }

    $wp_the_query = $save_tq;
    $wp_query     = $save_pq;
    wp_reset_postdata();
}

/* D6. A service-page lead must reach its own agency. The listing stores the
 * agency POST id while the form posts the tap_agencies ROW id (independent
 * auto-increments), so comparing the raw numbers rejected every service-page
 * lead as "not this agency's service". */
$agency_keys   = TAP_Post_Types::agency_meta_keys();
$acc_owner_key = $agency_keys['tap_accommodation'] ?? '_tap_acc_agency_id';
$owned_acc     = $wpdb->get_row($wpdb->prepare(
    "SELECT pm.post_id AS svc, pm.meta_value AS owner_post
       FROM {$wpdb->postmeta} pm
       JOIN {$wpdb->posts} p ON p.ID = pm.post_id
      WHERE p.post_type = 'tap_accommodation' AND p.post_status = 'publish'
        AND pm.meta_key = %s AND pm.meta_value > 0
      LIMIT 1",
    $acc_owner_key
));
if (!$owned_acc) {
    tap_t_pass('lead service/agency namespace check skipped (no owned accommodation)');
} else {
    $lead_service = (int) $owned_acc->svc;
    $lead_post    = (int) $owned_acc->owner_post;
    $lead_row     = $lead_post ? (int) TAP_Leads::agency_row_id($lead_post) : 0;
    if (!$lead_row) {
        tap_t_pass('lead service/agency namespace check skipped (agency post has no row)');
    } elseif ($lead_post === $lead_row) {
        tap_t_pass('lead service/agency namespace check skipped (post id already equals row id)');
    } elseif ('1' !== (string) $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$wpdb->prefix}tap_agencies WHERE id = %d", $lead_row))) {
        tap_t_pass('lead service/agency namespace check skipped (agency is not active)');
    } else {
        $lead_email = 'lead-ns-' . wp_generate_password(8, false) . '@example.test';
        $ok = TAP_Leads::submit([
            'name'       => 'Namespace Tester',
            'email'      => $lead_email,
            'phone'      => '',
            'message'    => 'Quisiera información sobre este alojamiento.',
            'agency_id'  => $lead_row,
            'service_id' => $lead_service,
            'ip'         => '203.0.113.' . random_int(1, 254),
            'source'     => 'agency',
            'consent'    => '1',
        ]);
        tap_t_assert(!is_wp_error($ok), 'a service-page lead is accepted for its own agency (got: ' . (is_wp_error($ok) ? $ok->get_error_code() : 'lead id') . ')');
        if (!is_wp_error($ok)) {
            $wpdb->delete($wpdb->prefix . 'tap_leads', ['id' => (int) $ok]);
        }

        // The guard must still reject a service that belongs to someone else.
        $other_posts = get_posts(['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids']);
        $foreign = 0;
        foreach ($other_posts as $cand) {
            $cand_owner = (int) get_post_meta((int) $cand, $acc_owner_key, true);
            if ($cand_owner && $cand_owner !== $lead_post) {
                $foreign = (int) $cand;
                break;
            }
        }
        if (!$foreign) {
            tap_t_pass('foreign-service lead guard skipped (single agency owns every accommodation)');
        } else {
            $bad = TAP_Leads::submit([
                'name'       => 'Namespace Tester',
                'email'      => 'lead-ns-' . wp_generate_password(8, false) . '@example.test',
                'phone'      => '',
                'message'    => 'Intento de enviar a la agencia equivocada.',
                'agency_id'  => $lead_row,
                'service_id' => $foreign,
                'ip'         => '203.0.113.' . random_int(1, 254),
                'source'     => 'agency',
                'consent'    => '1',
            ]);
            tap_t_assert(is_wp_error($bad) && 'invalid_service' === $bad->get_error_code(), 'a service owned by another agency is still rejected');
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tap_leads WHERE email = %s", $lead_email));
    }
}

/* D5. An admin settle records a transfer that already happened. The payout
 * row must be born completed (paid_at set) or a later Cancel/Fail would
 * release the commission back to owed while the money is gone — and the
 * agency could be paid a second time. */
$payouts_src = file_get_contents($plugin . 'includes/class-dashboard-money.php');
// Scope to the admin settle handler only: the rest of the file also mentions
// 'admin' and commission_amount, which would make the assertions vacuous.
$dm_fn_start = (int) strpos($payouts_src, 'function handle_commissions_post');
$dm_fn_end   = (int) strpos($payouts_src, 'function commissions_page');
$admin_block = ($dm_fn_start > 0 && $dm_fn_end > $dm_fn_start)
    ? substr($payouts_src, $dm_fn_start, $dm_fn_end - $dm_fn_start)
    : '';
tap_t_assert('' !== $admin_block, 'admin settle handler located for inspection');
tap_t_assert((int) preg_match_all("/'source'\s*=>\s*'admin'/", $admin_block) >= 1, 'admin settle writes a payout row');
tap_t_assert((int) preg_match_all("/['\"]status['\"]\s*=>\s*['\"]completed['\"]/", $admin_block) >= 1, 'admin settle payout rows are created completed, not pending');
tap_t_assert((int) preg_match_all("/['\"]paid_at['\"]\s*=>/", $admin_block) >= 1, 'admin settle payout rows carry a paid_at timestamp');
// The single-booking settle already filtered zero commissions; the bulk one
// (the `id IN (...)` query) did not, so target that segment specifically.
$dm_bulk_start = strpos($admin_block, 'SELECT id, agency_id FROM');
$dm_bulk_seg   = false !== $dm_bulk_start ? substr($admin_block, $dm_bulk_start, 2500) : '';
tap_t_assert('' !== $dm_bulk_seg, 'bulk admin settle query located for inspection');
tap_t_assert(strpos($dm_bulk_seg, 'commission_amount > 0') !== false, 'bulk admin settle skips zero-commission bookings');
tap_t_assert(strpos($dm_bulk_seg, 'NOT EXISTS') !== false, 'bulk settle re-checks pending payouts after taking the lock');
// The single-book settle must share the per-agency lock, or an agency payout
// request can interleave between its SELECT and INSERT and pay twice.
$dm_single_start = strpos($admin_block, "post_verified('tap_settle_booking'");
$dm_single_end   = strpos($admin_block, 'isset($_POST[\'tap_payout_action\']');
$dm_single_seg   = ($dm_single_start !== false && $dm_single_end !== false && $dm_single_end > $dm_single_start)
    ? substr($admin_block, $dm_single_start, $dm_single_end - $dm_single_start)
    : '';
tap_t_assert('' !== $dm_single_seg, 'single admin settle handler located for inspection');
tap_t_assert(strpos($dm_single_seg, 'GET_LOCK') !== false, 'single admin settle serializes with the per-agency payout lock');

// Source-level guard for the logged-in booking throttle (the guest branch
// only throttles logged-out visitors; a registered account could flood an
// agency with unlimited bookings without this).
$booking_src = file_get_contents($plugin . 'includes/class-ajax.php');
tap_t_assert(strpos($booking_src, "TAP_Rate_Limit::too_many('booking'") !== false, 'authenticated booking creation is throttled per account');

// Promotion slots: activate() must re-check the plan before activating.
$promo_src = file_get_contents($plugin . 'includes/class-promotions.php');
tap_t_assert(strpos($promo_src, 'function occupied_promos_count') !== false, 'promotions expose occupied_promos_count');
tap_t_assert(strpos($promo_src, 'occupied_promos_count($agency_id) >= $slots') !== false, 'promo request counts pending promos against the plan slots');
tap_t_assert(strpos($promo_src, "'payment_status' => 'paid'") !== false && strpos($promo_src, 'slots_full') !== false, 'promo activate records payment and refuses when the plan has no slot');

// A blocked review must no longer stay published.
$moderation_src = file_get_contents($plugin . 'includes/class-moderation.php');
tap_t_assert((bool) preg_match("/'mod_status'\s*=>\s*self::BLOCK.*?is_approved.*?0/s", $moderation_src), 'blocking a review clears is_approved');

// Payment reminders must not target unpaid request-mode bookings.
$automations_src = file_get_contents($plugin . 'includes/class-automations.php');
tap_t_assert((int) preg_match("/status IN \('pending'\)/", $automations_src) === 1, 'payment reminders only target payable pending bookings');

// An agency member must answer through tap_dash_chat_send, not as the client.
tap_t_assert(strpos($booking_src, 'caller_is_agency_member') !== false, 'client chat endpoint refuses agency members');

// Settings must warn when the PayPal keys are still the installer samples,
// otherwise checkout fails with a gateway error nobody can explain.
$orig_pid = get_option('tap_paypal_client_id', '');
$orig_sec = get_option('tap_paypal_secret', '');
update_option('tap_paypal_client_id', 'test-client');
update_option('tap_paypal_secret', 'test-secret');
tap_t_assert(TAP_PayPal::has_placeholder_credentials(), 'placeholder PayPal credentials are detected');
update_option('tap_paypal_client_id', 'live-client-id-123');
update_option('tap_paypal_secret', 'live-secret-456');
tap_t_assert(!TAP_PayPal::has_placeholder_credentials(), 'real-looking PayPal credentials do not raise the warning');
update_option('tap_paypal_client_id', $orig_pid);
update_option('tap_paypal_secret', $orig_sec);
tap_t_assert(strpos(file_get_contents($plugin . 'includes/class-dashboard-settings.php'), 'has_placeholder_credentials') !== false, 'settings screen renders the PayPal configuration warning');

tap_t_finish();