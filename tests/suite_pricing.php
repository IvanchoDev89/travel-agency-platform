<?php
/**
 * suite_pricing.php — Fase C: price engine + currency.
 * TAP_Currency formatting, per-day room pricing overrides, discount quotes and
 * per-type price math for all six service types. Self-contained: creates and
 * removes its own posts/rows.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$created_posts = [];
$dp_table = $wpdb->prefix . 'tap_daily_pricing';

function pr_make_post($type, $meta = []) {
    global $created_posts;
    $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => 'PR-E2E']);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    }
    return $id;
}

// 1) currency formatting -------------------------------------------------------
$orig_cur = get_option('tap_currency', 'USD');
update_option('tap_currency', 'USD');
tap_t_assert('USD' === TAP_Currency::code(), 'currency code default USD');
tap_t_assert('$ 1,234.56' === TAP_Currency::fmt(1234.56), 'USD fmt uses $ + 2 decimals');
update_option('tap_currency', 'EUR');
tap_t_assert('&euro; 1,234.56' === TAP_Currency::fmt(1234.56), 'EUR fmt uses euro sign');
update_option('tap_currency', 'JPY');
tap_t_assert(TAP_Currency::decimals('JPY') === 0, 'JPY has zero decimals');
tap_t_assert('&yen; 1,235' === TAP_Currency::fmt0(1234.56), 'JPY fmt0 rounds to integer');
tap_t_assert('JPY' === TAP_Currency::code(), 'currency code follows option (JPY)');
update_option('tap_currency', $orig_cur);

// 2) per-day room pricing overrides -------------------------------------------
$room_id = pr_make_post('tap_room', ['_tap_room_price_per_night' => 100, '_tap_room_inventory' => 1, '_tap_room_is_active' => '1']);
tap_t_assert($room_id > 0, 'created a room post for pricing');
$d1 = gmdate('Y-m-d', strtotime('+25 days'));
$d2 = gmdate('Y-m-d', strtotime('+26 days'));
$d3 = gmdate('Y-m-d', strtotime('+27 days'));
$d0 = gmdate('Y-m-d', strtotime('+24 days'));
tap_t_assert(TAP_Pricing::get_price_for_night($room_id, $d0) === 100.0, 'base price for night');
$wpdb->insert($dp_table, ['room_id' => $room_id, 'date' => $d1, 'price' => 75, 'min_stay' => 2, 'is_blocked' => 0, 'label' => 'e2e']);
$wpdb->insert($dp_table, ['room_id' => $room_id, 'date' => $d2, 'price' => null, 'min_stay' => null, 'is_blocked' => 1, 'label' => '']);
tap_t_assert(TAP_Pricing::get_price_for_night($room_id, $d1) === 75.0, 'override price used');
tap_t_assert(false === TAP_Pricing::get_price_for_night($room_id, $d2), 'blocked night returns false');
tap_t_assert(TAP_Pricing::is_date_available($room_id, $d2) === false && TAP_Pricing::is_date_available($room_id, $d0) === true, 'date availability respects block');
$blocked_range = TAP_Pricing::get_prices_for_range($room_id, $d1, $d3);
tap_t_assert($blocked_range['available'] === false && $blocked_range['date'] === $d2, 'range over blocked night unavailable');
$range1 = TAP_Pricing::get_prices_for_range($room_id, $d0, $d2);
tap_t_assert($range1['available'] && abs($range1['total'] - 175.0) < 0.001, 'range total = base + override (100 + 75)');
tap_t_assert(TAP_Pricing::get_min_stay_for_date($room_id, $d1) === 2, 'min-stay override applied');
tap_t_assert(TAP_Pricing::get_max_min_stay_for_range($room_id, $d0, $d2) === 2, 'max min-stay honoured over range');
tap_t_assert(TAP_Pricing::calculate_total($room_id, $d0, $d2, false) === 175.0, 'calculate_total sums nights');
$wpdb->delete($dp_table, ['room_id' => $room_id]);

// 3) discount quote: last-minute 15% applies ----------------------------------
$orig_disc = get_option(TAP_Discounts::OPTION_KEY, []);
$disc = TAP_Discounts::get_settings();
$disc['last_enabled'] = '1';
$disc['last_days']    = 3;
$disc['last_percent'] = 15;
$disc['early_enabled']='0';
$disc['long_enabled'] = '0';
update_option(TAP_Discounts::OPTION_KEY, $disc);
$quote_in  = gmdate('Y-m-d', strtotime('+1 day'));
$quote_out = gmdate('Y-m-d', strtotime('+3 days'));
$quote = TAP_Discounts::get_quote($room_id, $quote_in, $quote_out);
tap_t_assert($quote['available'] === true, 'discount quote available');
tap_t_assert(abs($quote['base_total'] - 200.0) < 0.001, 'quote base = 100 x 2 nights');
tap_t_assert(abs($quote['savings'] - 30.0) < 0.001 && abs($quote['total'] - 170.0) < 0.001, 'last-minute 15% savings computed');
tap_t_assert(!empty($quote['discounts']), 'quote exposes discount breakdown');
update_option(TAP_Discounts::OPTION_KEY, $orig_disc);

// 4) calculate_price per service type ------------------------------------------
$in2  = gmdate('Y-m-d', strtotime('+30 days'));
$out2 = gmdate('Y-m-d', strtotime('+32 days'));

$acc_id = pr_make_post('tap_accommodation', ['_tap_acc_price_per_night' => 90]);
tap_t_assert(TAP_Booking::calculate_price('tap_accommodation', $acc_id, $in2, $out2) === 180.0, 'accommodation price = per-night x nights');

$tour_id = pr_make_post('tap_tour', ['_tap_tour_price_adult' => 100, '_tap_tour_price_child' => 60]);
tap_t_assert(TAP_Booking::calculate_price('tap_tour', $tour_id, $in2, $out2, 2, 1) === 260.0, 'tour price = adults + children');

$trans_id = pr_make_post('tap_transport', ['_tap_trans_price' => 50]);
tap_t_assert(TAP_Booking::calculate_price('tap_transport', $trans_id, $in2, $out2) === 50.0, 'transport price flat');

$car_id = pr_make_post('tap_car_rental', ['_tap_car_price_per_day' => 40]);
tap_t_assert(TAP_Booking::calculate_price('tap_car_rental', $car_id, $in2, $out2) === 80.0, 'car price = per-day x days');

$boat_id = pr_make_post('tap_boat', ['_tap_boat_price_half' => 120]);
tap_t_assert(TAP_Booking::calculate_price('tap_boat', $boat_id, $in2, $out2) === 120.0, 'boat price flat');

$pkg_id = pr_make_post('tap_package', ['_tap_pkg_price' => 55, '_tap_pkg_price_per_person' => 55]);
tap_t_assert(TAP_Booking::calculate_price('tap_package', $pkg_id, $in2, $out2, 2, 1) === 165.0, 'package price = per-person x people');
$pkg2 = pr_make_post('tap_package', ['_tap_pkg_price' => 300]);
tap_t_assert(TAP_Booking::calculate_price('tap_package', $pkg2, $in2, $out2, 2, 1) === 300.0, 'package falls back to flat price');

// 5) room-based accommodation price -------------------------------------------
tap_t_assert(TAP_Booking::calculate_price('tap_accommodation', $room_id, $in2, $out2, 1, 0, $room_id) === 200.0, 'room-based accommodation price uses room base');

foreach ($created_posts as $pid) {
    wp_delete_post($pid, true);
}
tap_t_finish();