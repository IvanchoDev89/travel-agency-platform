<?php
/**
 * TAP E2E suite: auto-service listing manager (Fase 1 / P2).
 *
 * Exercises the generalized agency listing save handler (TAP_Ajax::save_listing_data)
 * across all six public service post types, ownership enforcement, and the legacy
 * accommodation short-name mapping. Site-agnostic: it discovers the agencies and
 * users present on the target install instead of hardcoding a particular site.
 */
require_once __DIR__ . '/bootstrap.php';

// Bypass the plan listing limit so the suite can create freely.
add_filter('tap_listing_limit', function () { return -1; });

/**
 * Safety net: remove any leftover "P2 " test listings from a previous aborted
 * run so the suite never leaves (or depends on) stale test data.
 */
function am_clean_previous() {
    $leftovers = get_posts([
        'post_type'   => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        's'           => 'P2 ',
    ]);
    foreach ($leftovers as $p) {
        wp_delete_post($p->ID, true);
    }
}
am_clean_previous();

/**
 * Discover a real agency-admin user + agency on the current install.
 * Returns [user_id, agency_id] or [0,0].
 */
function am_find_agency_user() {
    $admin_users = get_users(['role__in' => ['tap_agency_admin', 'tap_agency_employee'], 'fields' => 'ID']);
    foreach ($admin_users as $uid) {
        $a = TAP_Booking::get_agency_for_user($uid);
        if ($a > 0) {
            return [(int) $uid, (int) $a];
        }
    }
    return [0, 0];
}

$created_users = [];
$created_agencies = [];

/**
 * Create a throwaway agency + matching admin user for tests that need a
 * second agency. Returns [user_id, agency_id]; cleanup via am_cleanup_temp().
 */
function am_make_temp_agency() {
    global $created_users, $created_agencies;
    $suffix = wp_generate_password(5, false);
    $uname  = 'tap_e2e_' . $suffix;
    $uid    = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => 'tap_agency_admin',
        'display_name' => 'TAP E2E Temp',
    ]);
    if (is_wp_error($uid) || !$uid) {
        return [0, 0];
    }
    $aid = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'TAP E2E Agency ' . $suffix]);
    if (!$aid || is_wp_error($aid)) {
        wp_delete_user($uid);
        return [0, 0];
    }
    update_post_meta($aid, '_tap_agency_user_id', (int) $uid);
    $created_users[] = (int) $uid;
    $created_agencies[] = (int) $aid;
    return [(int) $uid, (int) $aid];
}

function am_cleanup_temp() {
    global $created_agencies, $created_users;
    foreach ($created_agencies as $aid) {
        wp_delete_post($aid, true);
    }
    foreach ($created_users as $uid) {
        wp_delete_user($uid);
    }
}

// Discover agency pair #1 (the "owner" agency) for this site.
list($owner_uid, $owner_agency) = am_find_agency_user();
tap_t_assert($owner_uid > 0 && $owner_agency > 0, 'discovered an agency-admin user with an agency (uid=' . $owner_uid . ', agency=' . $owner_agency . ')');

// Discover/dispense agency pair #2 (a DIFFERENT agency) for cross-agency tests.
// Collect all admin users; if only one distinct agency exists, create a temp one.
$admin_users   = get_users(['role__in' => ['tap_agency_admin', 'tap_agency_employee'], 'fields' => 'ID']);
$other_uid     = 0;
$other_agency  = 0;
$other_is_temp = false;
foreach ($admin_users as $uid) {
    $a = TAP_Booking::get_agency_for_user($uid);
    if ((int) $uid !== $owner_uid && $a > 0) {
        $other_uid    = (int) $uid;
        $other_agency = (int) $a;
        break;
    }
}
if ($other_uid === 0) {
    $pair = am_make_temp_agency();
    $other_uid    = $pair[0];
    $other_agency = $pair[1];
    $other_is_temp = true;
}
tap_t_assert($other_uid > 0 && $other_agency > 0 && $other_agency !== $owner_agency, 'a second, distinct agency is available for cross-agency tests' . ($other_is_temp ? ' (temporary)' : ''));

$suffix = wp_generate_password(5, false);
$created = [];

// 1) Create one listing of each service type with a representative field.
$cases = [
    'tap_accommodation' => null, // handled specially via legacy short names
    'tap_tour'          => ['_tap_tour_price_adult' => '49.99', '_tap_tour_difficulty' => 'moderate'],
    'tap_transport'     => ['_tap_trans_price' => '25'],
    'tap_car_rental'    => ['_tap_car_price_per_day' => '60'],
    'tap_boat'          => ['_tap_boat_price' => '120'],
    'tap_package'       => ['_tap_pkg_price_adult' => '300'],
];
foreach ($cases as $type => $field) {
    $input = ['title' => "P2 {$type} {$suffix}", 'description' => 'test', 'listing_type' => $type];
    if ($type === 'tap_accommodation') {
        $input += ['type' => 'hostel', 'price_per_night' => '99.00', 'stars' => '3', 'is_active' => '1'];
    } else {
        $input += $field;
    }
    $res = TAP_Ajax::save_listing_data($owner_uid, $input);
    $id  = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
    tap_t_assert(true === $res && $id > 0, "create {$type} ok (id={$id})");
    $created[$type] = $id;

    $prefix = TAP_Post_Types::meta_prefix($type);
    tap_t_assert((int) get_post_meta($id, "_tap_{$prefix}_agency_id", true) === (int) $owner_agency, "{$type} owner matches agency (key _tap_{$prefix}_agency_id)");
    $wrong = '_tap_' . str_replace('tap_', '', $type) . '_agency_id';
    if ($wrong !== "_tap_{$prefix}_agency_id") {
        tap_t_assert(get_post_meta($id, $wrong, true) === '', "{$type} no stray owner on wrong key {$wrong}");
    }
    tap_t_assert(get_post_type($id) === $type, "{$type} post_type correct");
}

// 2) Legacy accommodation short names were mapped.
$acc = $created['tap_accommodation'];
tap_t_assert(get_post_meta($acc, '_tap_acc_type', true) === 'hostel', 'accommodation legacy type mapped');
tap_t_assert(get_post_meta($acc, '_tap_acc_price_per_night', true) === '99', 'accommodation legacy price mapped');
tap_t_assert(get_post_meta($acc, '_tap_acc_stars', true) === '3', 'accommodation legacy stars mapped');
tap_t_assert(get_post_meta($acc, '_tap_acc_is_active', true) === '1', 'accommodation legacy is_active mapped');
tap_t_assert((int) get_post_meta($acc, '_tap_acc_agency_id', true) === (int) $owner_agency, 'accommodation owner written to canonical _tap_acc_agency_id');

// 3) Edit an existing listing keeps owner and updates fields.
$tour = $created['tap_tour'];
$res  = TAP_Ajax::save_listing_data($owner_uid, ['listing_id' => $tour, 'title' => "P2 tour edit {$suffix}", 'description' => 'e', 'listing_type' => 'tap_tour', '_tap_tour_price_adult' => '77.50']);
$edit_id = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $res && $edit_id === $tour, 'tour edit ok (same id)');
tap_t_assert(get_post_meta($tour, '_tap_tour_price_adult', true) === '77.5', 'tour edit price updated');
tap_t_assert((int) get_post_meta($tour, '_tap_tour_agency_id', true) === (int) $owner_agency, 'tour owner preserved after edit');

// 4) Ownership enforcement: a different agency cannot edit a listing it does not own.
$r2 = TAP_Ajax::save_listing_data($other_uid, ['listing_id' => $tour, 'title' => 'hijack', 'description' => 'x', 'listing_type' => 'tap_tour']);
tap_t_assert(true !== $r2, 'cross-agency edit rejected');

// 5) A listing created by the second agency is owned by the second agency.
$r3         = TAP_Ajax::save_listing_data($other_uid, ['title' => "P2 owner-other {$suffix}", 'description' => 'c', 'listing_type' => 'tap_tour', '_tap_tour_price_adult' => '10']);
$id_other   = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $r3 && (int) get_post_meta($id_other, '_tap_tour_agency_id', true) === (int) $other_agency, "second agency owns its listing (agency {$other_agency})");

// 6) tap_room is not creatable as a listing type (fails back to accommodation).
$res = TAP_Ajax::save_listing_data($owner_uid, ['title' => "P2 fallback {$suffix}", 'description' => 'f', 'listing_type' => 'tap_room']);
$fid = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $res && get_post_type($fid) === 'tap_accommodation', 'tap_room listing_type falls back to accommodation (not creatable)');

// Cleanup all created listings + any temporary agency/user.
foreach ($created as $id) {
    wp_delete_post($id, true);
}
wp_delete_post($id_other, true);
wp_delete_post($fid, true);
am_cleanup_temp();

tap_t_finish();
