<?php
/**
 * suite_promotions.php — canonical meta keys + featured-first ordering (F-FF).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_promotions.php
 */
require __DIR__ . '/bootstrap.php';

// 1) canonical prefixes for every service type
$expected = [
    'tap_accommodation' => 'acc',
    'tap_tour'          => 'tour',
    'tap_transport'     => 'trans',
    'tap_car_rental'    => 'car',
    'tap_boat'          => 'boat',
    'tap_package'       => 'pkg',
];
foreach ($expected as $type => $prefix) {
    $got = TAP_Promotions::prefix_for_type($type);
    tap_t_assert($got === $prefix, "prefix_for_type({$type}) = {$prefix} (got " . var_export($got, true) . ")");
}

// 2) listing_meta_keys delegates to keys_for_type -> canonical keys
$expectedKey = ['tap_accommodation' => '_tap_acc_', 'tap_tour' => '_tap_tour_', 'tap_transport' => '_tap_trans_', 'tap_car_rental' => '_tap_car_', 'tap_boat' => '_tap_boat_', 'tap_package' => '_tap_pkg_'];
foreach ($expectedKey as $type => $prefix) {
    $keys = TAP_Promotions::keys_for_type($type);
    tap_t_assert($keys !== null && str_starts_with((string) $keys['flag_key'], $prefix), "keys_for_type({$type}) canonical flag key " . ($keys['flag_key'] ?? '?'));
    tap_t_assert($keys !== null && str_starts_with((string) $keys['active_key'], $prefix), "keys_for_type({$type}) canonical active key " . ($keys['active_key'] ?? '?'));
    tap_t_assert($keys !== null && str_starts_with((string) $keys['until_key'], $prefix), "keys_for_type({$type}) canonical until key " . ($keys['until_key'] ?? '?'));
}

// 3) featured status resolution for a known featured listing
// future-until featured listing must report is_featured() true
$featuredListing = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'meta_key' => '_tap_tour_is_featured', 'meta_value' => '1', 'posts_per_page' => 1, 'suppress_filters' => false]);
if ($featuredListing) {
    $p = $featuredListing[0];
    $until = get_post_meta($p->ID, '_tap_tour_featured_until', true);
    tap_t_assert(TAP_Promotions::is_featured($p->ID) === true, 'active featured listing reports is_featured true');
    if ($until && strtotime($until) >= current_time('timestamp')) {
        tap_t_assert(TAP_Promotions::is_featured($p->ID, ($until)) === true, 'is_featured true while within until window');
    }
    $q = new WP_Query([
        'post_type'      => 'tap_tour',
        'posts_per_page' => 20,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'ASC',
        'suppress_filters' => false,
    ]);
    // Featured-first ordering is a posts_clauses filter that deliberately only
    // touches the main query, so a secondary WP_Query is never reordered and
    // the old "index 0" assertion only passed when creation order happened to
    // line up. Exercise the clause builder directly instead, with the query
    // wired as the main one so the is_main_query() guard lets it through.
    global $wpdb, $wp_the_query;
    $prev_the_query = $wp_the_query;
    $wp_the_query   = $q;
    $feat_clauses   = TAP_Archive_Query::featured_sort_clauses(
        ['orderby' => "{$wpdb->posts}.post_date ASC"],
        $q
    );
    $wp_the_query = $prev_the_query;

    tap_t_assert(
        strpos((string) $feat_clauses['orderby'], "{$wpdb->posts}.ID IN (") !== false,
        'featured sort clauses pin featured listings first via the precomputed ID set'
    );
    tap_t_assert(
        strpos((string) $feat_clauses['orderby'], "{$wpdb->posts}.post_date ASC") !== false,
        'featured sort clauses keep the original orderby as tie-break'
    );
    // The featured listing must be part of the precomputed ordering set, so the
    // IN-expression promotes it ahead of the rest under ASC.
    tap_t_assert(
        in_array((int) $p->ID, TAP_Promotions::featured_ids(['tap_tour']), true),
        'featured listing id belongs to the precomputed ordering set'
    );
} else {
    tap_t_assert(true, 'no active featured listing present (seed one to test ordering)');
}

/* ── 4) panel Destacados (bo-promos): compra reactivada ───────────── */

global $wpdb;
// promo_make_user() mutates this through `global`; bind the top-level var to
// the same global so the cleanup section sees it (eval-file scope).
global $promo_users;

$promo_users  = [];
$promo_posts  = [];
$promo_plan_id = 0;

function promo_make_user($role) {
    global $promo_users;
    $uname = 'promo_' . wp_generate_password(5, false);
    $uid   = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => $role,
        'display_name' => 'Promo ' . $role,
    ]);
    if (is_wp_error($uid) || !$uid) {
        return 0;
    }
    $promo_users[] = (int) $uid;
    return (int) $uid;
}

$po_owner = promo_make_user('tap_agency_admin');
$po_agency = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'Promo Agency ' . wp_generate_password(4, false),
]);
$promo_posts[] = (int) $po_agency;
tap_t_assert($po_owner && $po_agency && !is_wp_error($po_agency), 'created promo agency + owner fixtures');
update_post_meta($po_agency, '_tap_agency_user_id', $po_owner);

// Plan with featured slots + active subscription (featured_slots else => 0).
$po_suffix = wp_generate_password(6, false);
$wpdb->insert($wpdb->prefix . 'tap_plans', [
    'name'           => 'Promo Plan ' . $po_suffix,
    'slug'           => 'promo-plan-' . $po_suffix,
    'price_monthly'  => 10,
    'commission_rate'=> 10,
    'listing_limit'  => -1,
    'featured_slots' => 2,
    'is_active'      => 1,
]);
$promo_plan_id = (int) $wpdb->insert_id;
tap_t_assert($promo_plan_id > 0, 'seeded plan with featured_slots');
$wpdb->insert($wpdb->prefix . 'tap_agency_subscriptions', [
    'agency_id'      => (int) $po_agency,
    'plan_id'        => $promo_plan_id,
    'status'         => 'active',
    'payment_status' => 'paid',
    'paid_until'     => gmdate('Y-m-d', strtotime('+30 days')),
]);
tap_t_assert((int) $wpdb->insert_id > 0, 'seeded active subscription for promo agency');

// Published listing owned by the agency + one pending promo request.
$po_listing = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'Promo Tour ' . $po_suffix,
]);
$promo_posts[] = (int) $po_listing;
update_post_meta($po_listing, TAP_Post_Types::agency_meta_key('tap_tour'), (int) $po_agency);
$req = TAP_Promotions::request((int) $po_agency, (int) $po_listing, 3);
tap_t_assert(!is_wp_error($req), 'TAP_Promotions::request creates a pending promo when the plan has slots');

// Second listing, no promo yet — this is the row that can still be purchased.
$po_listing2 = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'Promo Tour Clean ' . $po_suffix,
]);
$promo_posts[] = (int) $po_listing2;
update_post_meta($po_listing2, TAP_Post_Types::agency_meta_key('tap_tour'), (int) $po_agency);

// Employee (mapped via team_add) is denied the section.
$po_emp = promo_make_user('tap_client');
$added  = TAP_Ajax_Dash::team_add((int) $po_agency, get_userdata($po_emp)->user_email);
tap_t_assert(true === $added, 'employee fixture mapped to the promo agency');
$_GET['seccion'] = 'bo-promos';
wp_set_current_user($po_emp);
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'gestiona los destacados') !== false, 'bo-promos: employee is denied (admin-only section)');

// Agency admin sees the table: listing, pending status and purchase form.
wp_set_current_user($po_owner);
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'Promo Tour') !== false, 'bo-promos: agency admin sees own listing in the table');
tap_t_assert(strpos($out, 'Pendiente de pago') !== false, 'bo-promos: pending promo row shows Pendiente de pago');
tap_t_assert(strpos($out, 'tap-promo-form') !== false || strpos($out, 'tap-promo-paypal-form') !== false, 'bo-promos: purchase form renders (admin-confirm or PayPal path)');
tap_t_assert(strpos($out, 'tap-promo-months') !== false, 'bo-promos: months selector renders');

// Second agency without a featured plan hits the upgrade note instead.
$po_owner2 = promo_make_user('tap_agency_admin');
$po_agency2 = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'publish',
    'post_title'  => 'Promo Agency Free ' . wp_generate_password(4, false),
]);
$promo_posts[] = (int) $po_agency2;
update_post_meta($po_agency2, '_tap_agency_user_id', $po_owner2);
wp_set_current_user($po_owner2);
$out = do_shortcode('[tap_front_dash]');
tap_t_assert(strpos($out, 'no incluye listados destacados') !== false, 'bo-promos: plan without slots renders the upgrade note');

// Activation re-checks the plan slots, records payment and features the listing.
$act1 = TAP_Promotions::activate((int) $req);
tap_t_assert(!is_wp_error($act1) && '' !== (string) $act1, 'activate() activates a pending promo when a slot is free');
$row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_promos WHERE id = %d", (int) $req));
tap_t_assert($row1 && 'active' === $row1->status && 'paid' === $row1->payment_status, 'activated promo is active + paid');
tap_t_assert('1' === (string) get_post_meta($po_listing, '_tap_tour_is_featured', true), 'activated promo features its listing');

// The plan has two slots, so a second promo still activates.
$req2 = TAP_Promotions::request((int) $po_agency, (int) $po_listing2, 1);
tap_t_assert(!is_wp_error($req2), 'a second promo request fits the remaining slot');
$act2 = is_wp_error($req2) ? $req2 : TAP_Promotions::activate((int) $req2);
tap_t_assert(!is_wp_error($act2), 'second promo activates while a slot remains');

// With the plan full, a queued payment must be recorded but NOT activated.
$po_listing3 = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Promo Tour Overflow ' . $po_suffix]);
$promo_posts[] = (int) $po_listing3;
update_post_meta($po_listing3, TAP_Post_Types::agency_meta_key('tap_tour'), (int) $po_agency);
$wpdb->insert($wpdb->prefix . 'tap_promos', [
    'agency_id' => (int) $po_agency, 'listing_id' => (int) $po_listing3, 'months' => 1, 'amount' => 10.00,
    'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'paypal', 'notes' => 'overflow',
]);
$overflow = (int) $wpdb->insert_id;
$bad_act = TAP_Promotions::activate($overflow);
tap_t_assert(is_wp_error($bad_act) && 'slots_full' === $bad_act->get_error_code(), 'activating past the plan slots is refused with slots_full');
$ov_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_promos WHERE id = %d", $overflow));
tap_t_assert($ov_row && 'pending' === $ov_row->status && 'paid' === $ov_row->payment_status, 'refused promo keeps the captured payment recorded (pending status)');
tap_t_assert('' === (string) get_post_meta($po_listing3, '_tap_tour_is_featured', true), 'refused promo does not feature its listing');

// Cleanup fixtures (promo rows, sub, plan, posts, users).
wp_set_current_user(0);
unset($_GET['seccion']);
$wpdb->delete($wpdb->prefix . 'tap_promos', ['agency_id' => (int) $po_agency]);
$wpdb->delete($wpdb->prefix . 'tap_agency_subscriptions', ['agency_id' => (int) $po_agency]);
$wpdb->delete($wpdb->prefix . 'tap_plans', ['id' => $promo_plan_id]);
foreach ($promo_posts as $ppid) {
    wp_delete_post($ppid, true);
}
foreach ($promo_users as $puid) {
    wp_delete_user($puid);
}

tap_t_finish();