<?php
/**
 * suite_agency_calendar.php — B2B single-rental wizard: daily pricing/availability.
 *
 * Exercises the shared pricing core that both the admin metabox and the new
 * agency back-office calendar rely on: month_data() payloads (with booked
 * nights and inventory), save_cell() create/update/block/clear, and
 * can_manage_room() ownership rules across agencies and admins. Also verifies
 * that the agency front-end persists the accommodation policies fields.
 */
require_once __DIR__ . '/bootstrap.php';
global $wpdb;

// Bypass the plan listing limit so the suite can create freely.
add_filter('tap_listing_limit', function () { return -1; });

$created_agencies = [];
$created_users    = [];
$created_posts    = [];
$created_bookings = [];
$pricing_rows     = [];

function ac_find_agency_user() {
    $admin_users = get_users(['role__in' => ['tap_agency_admin', 'tap_agency_employee'], 'fields' => 'ID']);
    foreach ($admin_users as $uid) {
        $a = TAP_Booking::get_agency_for_user($uid);
        if ($a > 0) {
            return [(int) $uid, (int) $a];
        }
    }
    return [0, 0];
}

function ac_make_temp_agency() {
    global $created_agencies, $created_users;
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

function ac_cleanup_pricing_rows() {
    global $wpdb, $pricing_rows;
    if (!$pricing_rows) {
        return;
    }
    foreach (array_unique(array_map('intval', $pricing_rows)) as $room_id) {
        $wpdb->delete($wpdb->prefix . 'tap_daily_pricing', ['room_id' => $room_id]);
    }
}

// Discover owner agency + a second distinct agency for cross-agency checks.
list($owner_uid, $owner_agency) = ac_find_agency_user();
tap_t_assert($owner_uid > 0 && $owner_agency > 0, 'discovered owner agency user (uid=' . $owner_uid . ')');

$other_uid = 0;
$other_agency = 0;
$admin_uid = 0;
foreach (get_users(['role__in' => ['tap_agency_admin', 'tap_agency_employee', 'administrator'], 'fields' => 'ID']) as $uid) {
    $uid = (int) $uid;
    $is_admin = user_can($uid, 'manage_options');
    if ($is_admin && !$admin_uid) {
        $admin_uid = $uid;
    }
    if (!$is_admin && !$other_uid && $uid !== $owner_uid) {
        $a = TAP_Booking::get_agency_for_user($uid);
        if ($a > 0 && $a !== $owner_agency) {
            $other_uid    = $uid;
            $other_agency = (int) $a;
        }
    }
}
if (!$other_uid) {
    $pair = ac_make_temp_agency();
    $other_uid    = $pair[0];
    $other_agency = $pair[1];
}
tap_t_assert($other_uid > 0 && $other_agency > 0 && $other_agency !== $owner_agency, 'a second, distinct agency is available for cross-agency tests');
tap_t_assert($admin_uid > 0, 'an administrator user is available (manage_options)');

$suffix = wp_generate_password(5, false);
$dp     = $wpdb->prefix . 'tap_daily_pricing';

// 1) Owner accommodation created via the agency save path, with policies.
$res  = TAP_Ajax::save_listing_data($owner_uid, [
    'title'          => "ACAL acc {$suffix}",
    'description'    => 'wizard suite',
    'listing_type'   => 'tap_accommodation',
    'type'           => 'hotel',
    'price_per_night'=> '99.00',
    'is_active'      => '1',
    '_tap_acc_cancellation' => 'moderate',
    '_tap_acc_house_rules'  => 'No party policy.',
]);
$acc  = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $res && $acc > 0, "owner accommodation created (id={$acc})");
$created_posts[] = $acc;
tap_t_assert(get_post_meta($acc, '_tap_acc_cancellation', true) === 'moderate', 'agency save persists cancellation policy');
tap_t_assert(get_post_meta($acc, '_tap_acc_house_rules', true) === 'No party policy.', 'agency save persists house rules');
tap_t_assert((int) get_post_meta($acc, '_tap_acc_agency_id', true) === (int) $owner_agency, 'accommodation owned by agency ' . $owner_agency);

// 2) Owner room via the shared room editor.
$room = TAP_Ajax::save_room_data($acc, 0, [
    'title'           => "Deluxe {$suffix}",
    'price_per_night' => 100,
    'min_stay'        => 1,
    'inventory'       => 1,
    'max_adults'      => 2,
    'max_occupancy'   => 2,
]);
tap_t_assert(is_int($room) && $room > 0, "owner room created (id={$room})");
$created_posts[] = $room;
$pricing_rows[]  = $room;

// 3) Cross-agency accommodation + room (owned by $other_agency).
$res2 = TAP_Ajax::save_listing_data($other_uid, [
    'title'        => "ACAL other {$suffix}",
    'description'  => 'x',
    'listing_type' => 'tap_accommodation',
    'type'         => 'hostel',
    'price_per_night' => '55.00',
    'is_active'    => '1',
]);
$acc2  = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $res2 && $acc2 > 0, "other-agency accommodation created (id={$acc2})");
$created_posts[] = $acc2;
$room2 = TAP_Ajax::save_room_data($acc2, 0, ['title' => "Shared {$suffix}", 'price_per_night' => 35, 'min_stay' => 1, 'inventory' => 1]);
tap_t_assert(is_int($room2) && $room2 > 0, "other-agency room created (id={$room2})");
$created_posts[] = $room2;
$pricing_rows[]  = $room2;

// 4) Ownership: can_manage_room().
tap_t_assert(true === TAP_Pricing::can_manage_room($room, $owner_uid), 'owner user manages owner room');
tap_t_assert(false === TAP_Pricing::can_manage_room($room, $other_uid), 'cross-agency user cannot manage owner room');
tap_t_assert(true === TAP_Pricing::can_manage_room($room2, $other_uid), 'other-agency user manages own room');
tap_t_assert(true === TAP_Pricing::can_manage_room($room2, $admin_uid), 'admin can manage any room');
tap_t_assert(false === TAP_Pricing::can_manage_room(0, $owner_uid), 'room id 0 rejected');

// 5) month_data() shape (next month, with booked info).
$dim_m = new DateTime('first day of next month');
$year  = (int) $dim_m->format('Y');
$month = (int) $dim_m->format('n');
$dim   = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$d1    = sprintf('%04d-%02d-01', $year, $month);
$d10   = sprintf('%04d-%02d-10', $year, $month);
$d15   = sprintf('%04d-%02d-15', $year, $month);
$dlast = sprintf('%04d-%02d-%02d', $year, $month, $dim);

$m = TAP_Pricing::month_data($room, $year, $month, true);
tap_t_assert($m['days_in_month'] === $dim && count($m['days']) === $dim, 'month_data returns correct number of days');
tap_t_assert($m['base_price'] == 100.0 && $m['base_min'] === 1 && $m['inventory'] === 1, 'month_data exposes base price/min-stay/inventory');
tap_t_assert($m['days'][0]['date'] === $d1, 'first day of the month is correct');
$ok_shape = true;
foreach ($m['days'] as $day) {
    foreach (['date', 'day', 'dow', 'price', 'min_stay', 'is_blocked', 'label', 'overridden', 'booked'] as $k) {
        if (!array_key_exists($k, $day)) { $ok_shape = false; }
    }
}
tap_t_assert($ok_shape, 'every day exposes date/day/dow/price/min_stay/is_blocked/label/overridden/booked');
$all_free = true;
foreach ($m['days'] as $day) { if ($day['booked'] !== 0) { $all_free = false; } }
tap_t_assert($all_free, 'month_data reports 0 booked nights before any booking');

// 6) save_cell(): create / update / block / clear + invalid dates.
$created = TAP_Pricing::save_cell($room, $d1, 150, 2, 0, 'Alta');
tap_t_assert($created['ok'] && $created['action'] === 'created', 'save_cell creates a price override');

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$dp} WHERE room_id = %d AND date = %s", $room, $d1));
tap_t_assert($row && (float) $row->price === 150.0 && (int) $row->min_stay === 2 && $row->label === 'Alta', 'override row persisted with price/min_stay/label');

$md = TAP_Pricing::month_data($room, $year, $month);
$d1day = null;
foreach ($md['days'] as $day) { if ($day['date'] === $d1) $d1day = $day; }
tap_t_assert($d1day && $d1day['price'] == 150.0 && $d1day['min_stay'] === 2 && $d1day['overridden'] === true && $d1day['label'] === 'Alta', 'overridden day reflected in month_data');

$upd = TAP_Pricing::save_cell($room, $d1, 160, null, 0, 'Alta');
tap_t_assert($upd['ok'] && $upd['action'] === 'updated', 'save_cell updates an existing override');
tap_t_assert(TAP_Pricing::get_price_for_night($room, $d1) === 160.0, 'price engine reads updated override');

$blk = TAP_Pricing::save_cell($room, $d15, null, null, 1, 'Mantenimiento');
tap_t_assert($blk['ok'] && $blk['action'] === 'created', 'save_cell creates a block');
tap_t_assert(false === TAP_Pricing::get_price_for_night($room, $d15), 'engine treats blocked night as unavailable');
tap_t_assert(false === TAP_Pricing::is_date_available($room, $d15) && true === TAP_Pricing::is_date_available($room, $d10), 'is_date_available reflects block');

$del = TAP_Pricing::save_cell($room, $d1, '', '', 0, '');
tap_t_assert($del['ok'] && $del['action'] === 'deleted', 'save_cell clears an override when all fields are empty');
tap_t_assert(TAP_Pricing::get_price_for_night($room, $d1) === 100.0, 'cleared day falls back to the base price');

$inv = TAP_Pricing::save_cell($room, '2026-13-99', 100, 1, 0, '');
tap_t_assert($inv['ok'] === false, 'save_cell rejects an invalid date');

// 7) Booked nights: a confirmed booking marks its nights occupied.
$bk_code = 'TAP-E2E-' . wp_generate_password(6, false);
$wpdb->insert($wpdb->prefix . 'tap_bookings', [
    'booking_code'       => $bk_code,
    'agency_id'          => $owner_agency,
    'client_id'          => 0,
    'service_type'       => 'tap_accommodation',
    'service_id'         => $acc,
    'room_id'            => $room,
    'check_in'           => $d10,
    'check_out'          => $dlast,
    'adults'             => 2,
    'children'           => 0,
    'nights'             => $dim - 9,
    'total_amount'       => 200,
    'commission_amount'  => 0,
    'commission_percent' => 0,
    'status'             => 'confirmed',
    'payment_status'     => 'paid',
    'created_at'         => current_time('mysql'),
]);
$bk_id = (int) $wpdb->insert_id;
$created_bookings[] = $bk_id;
tap_t_assert($bk_id > 0, 'seeded a confirmed room booking');

$m2 = TAP_Pricing::month_data($room, $year, $month, true);
$dates_map = [];
foreach ($m2['days'] as $day) { $dates_map[$day['date']] = $day; }
tap_t_assert($dates_map[$d10]['booked'] === 1, 'first night of booking counted as booked');
tap_t_assert($dates_map[$d15]['booked'] === 1, 'mid-stay night counted as booked');
tap_t_assert($dates_map[$dlast]['booked'] === 0, 'check-out day is not counted as a booked night');

// 8) Bulk semantics on the server (bull payload is a loop of save_cell calls).
$ok_bulk = true;
foreach ([$d1, $d10, $d15] as $bkdate) {
    $r = TAP_Pricing::save_cell($room, $bkdate, 140, 1, 0, 'E2E');
    if (!$r['ok']) { $ok_bulk = false; }
}
tap_t_assert($ok_bulk, 'bulk-style sequential saves all succeed');
$m3 = TAP_Pricing::month_data($room, $year, $month);
$cnt = 0;
foreach ($m3['days'] as $day) { if ($day['overridden']) { $cnt++; } }
tap_t_assert($cnt === 3, 'bulk saves produced exactly 3 overridden days');

// Cleanup.
ac_cleanup_pricing_rows();
foreach ($created_bookings as $bid) {
    $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => $bid]);
}
foreach ($created_posts as $pid) {
    wp_delete_post($pid, true);
}
foreach ($created_agencies as $aid) {
    wp_delete_post($aid, true);
}
foreach ($created_users as $uid) {
    wp_delete_user($uid);
}

tap_t_finish();