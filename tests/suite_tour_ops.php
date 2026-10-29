<?php
/**
 * suite_tour_ops.php — Fase 6: tours availability, waivers, extras.
 * Self-contained: creates and removes its own posts/rows.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb, $created_posts;
$created_posts = [];
$dp_table = $wpdb->prefix . 'tap_daily_pricing';

function to_make_post($type, $meta = []) {
    global $created_posts;
    $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => 'TO-E2E']);
    if ($id && !is_wp_error($id)) {
        $created_posts[] = (int) $id;
        foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    }
    return $id;
}

// ---- 1) Tour availability: create slot ----
$tour_id = to_make_post('tap_tour', ['_tap_tour_price_adult' => 50, '_tap_tour_capacity' => 10, '_tap_tour_currency' => 'USD']);
tap_t_assert($tour_id > 0, 'created a tour post');

$slot_id = TAP_Tour_Availability::create_slot([
    'service_type' => 'tap_tour',
    'service_id'   => $tour_id,
    'date'         => '2026-12-10',
    'time_slot'    => '08:00:00',
    'time_end'     => '12:00:00',
    'capacity'     => 5,
    'price'        => 60,
    'label'        => 'Mañana',
]);
tap_t_assert($slot_id > 0, 'create_slot returns an id');

$slot = TAP_Tour_Availability::get_slot($slot_id);
tap_t_assert($slot && $slot->service_type === 'tap_tour', 'get_slot returns the slot');
tap_t_assert((int) $slot->capacity === 5, 'capacity stored');
tap_t_assert((float) $slot->price === 60.0, 'price override stored');

// ---- 2) slots_for range ----
$slots = TAP_Tour_Availability::slots_for('tap_tour', $tour_id, '2026-12-01', '2026-12-31');
tap_t_assert(count($slots) === 1, 'slots_for returns the created slot');

// ---- 3) is_available + book + release ----
tap_t_assert(TAP_Tour_Availability::is_available($slot_id, 2), 'slot available for 2');
tap_t_assert(TAP_Tour_Availability::book_slot($slot_id, 2), 'book_slot succeeds');
$slot2 = TAP_Tour_Availability::get_slot($slot_id);
tap_t_assert((int) $slot2->booked === 2, 'booked incremented to 2');
tap_t_assert(!TAP_Tour_Availability::is_available($slot_id, 4), 'slot full for 4 more (cap 5, booked 2, 2+4>5)');
tap_t_assert(TAP_Tour_Availability::is_available($slot_id, 3), 'slot available for 3 (2+3=5)');
tap_t_assert(TAP_Tour_Availability::book_slot($slot_id, 3), 'book remaining 3');
$slot3 = TAP_Tour_Availability::get_slot($slot_id);
tap_t_assert((int) $slot3->booked === 5, 'booked now 5 (full)');
tap_t_assert(!TAP_Tour_Availability::book_slot($slot_id, 1), 'cannot overbook');
TAP_Tour_Availability::release_slot($slot_id, 5);
$slot4 = TAP_Tour_Availability::get_slot($slot_id);
tap_t_assert((int) $slot4->booked === 0, 'release_slot decrements to 0');

// ---- 4) blocked slot ----
TAP_Tour_Availability::update_slot($slot_id, ['is_blocked' => 1]);
tap_t_assert(!TAP_Tour_Availability::is_available($slot_id, 1), 'blocked slot not available');
TAP_Tour_Availability::update_slot($slot_id, ['is_blocked' => 0]);

// ---- 5) slot_price (override + fallback) ----
$sp = TAP_Tour_Availability::slot_price($slot4);
tap_t_assert(abs($sp - 60.0) < 0.001, 'slot_price uses override');

// slot without price override falls back to service base
$slot_id2 = TAP_Tour_Availability::create_slot([
    'service_type' => 'tap_tour',
    'service_id'   => $tour_id,
    'date'         => '2026-12-11',
    'capacity'     => 4,
]);
$sp2 = TAP_Tour_Availability::slot_price(TAP_Tour_Availability::get_slot($slot_id2));
tap_t_assert(abs($sp2 - 50.0) < 0.001, 'slot_price falls back to service base price');

// ---- 6) delete_slot (only if no bookings) ----
TAP_Tour_Availability::book_slot($slot_id2, 1);
tap_t_assert(!TAP_Tour_Availability::delete_slot($slot_id2), 'delete_slot refused when booked > 0');
TAP_Tour_Availability::release_slot($slot_id2, 1);
tap_t_assert(TAP_Tour_Availability::delete_slot($slot_id2), 'delete_slot succeeds when empty');

// ---- 7) recurring generation ----
$n = TAP_Tour_Availability::generate_recurring('tap_tour', $tour_id, [
    'start_date' => '2026-12-01',
    'end_date'   => '2026-12-14',
    'days'       => [1, 3], // Mon, Wed
    'time_slot'  => '09:00:00',
    'time_end'   => '13:00:00',
    'capacity'   => 8,
    'price'      => 55,
    'label'      => 'Mañana',
]);
tap_t_assert($n > 0, sprintf('generate_recurring created %d slots', $n));
$rec = TAP_Tour_Availability::slots_for('tap_tour', $tour_id, '2026-12-01', '2026-12-14');
tap_t_assert(count($rec) >= $n, 'recurring slots listed');

// cleanup availability
foreach ($rec as $sl) TAP_Tour_Availability::delete_slot($sl->id);
TAP_Tour_Availability::delete_slot($slot_id);

// ---- 8) Waiver: required + text ----
$waiver_tour = to_make_post('tap_tour', ['_tap_tour_waiver' => 'Waiver text v1', '_tap_tour_waiver_required' => '1', '_tap_tour_waiver_min_age' => '18', '_tap_tour_price_adult' => 40]);
tap_t_assert(TAP_Waiver::is_required('tap_tour', $waiver_tour), 'waiver required');
tap_t_assert(TAP_Waiver::text_for('tap_tour', $waiver_tour) === 'Waiver text v1', 'waiver text stored');
tap_t_assert(TAP_Waiver::min_sign_age('tap_tour', $waiver_tour) === 18, 'min sign age 18');

// ---- 9) Waiver: sign + has_valid ----
$booking_res = TAP_Booking::create([
    'service_type' => 'tap_tour',
    'service_id'   => $waiver_tour,
    'check_in'     => gmdate('Y-m-d', strtotime('+20 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+21 days')),
    'adults'       => 1,
    'children'     => 0,
    'guest_name'   => 'Waiver Tester',
    'guest_email'  => 'waiver_e2e@example.test',
]);
$bid = is_wp_error($booking_res) ? 0 : (int) $booking_res['booking_id'];
tap_t_assert($bid > 0, 'booking created for waiver test');
tap_t_assert(!TAP_Waiver::has_valid($bid), 'no valid waiver before signing');

$sign = TAP_Waiver::sign($bid, 'Juan Perez', 'data:image/png;base64,AAAA');
tap_t_assert(!is_wp_error($sign) && (int) $sign > 0, 'sign returns id');
tap_t_assert(TAP_Waiver::has_valid($bid), 'has_valid true after signing');

$w = TAP_Waiver::for_booking($bid);
tap_t_assert($w && $w->signed_name === 'Juan Perez', 'waiver stored with name');
tap_t_assert($w->waiver_text_hash === TAP_Waiver::version_of('Waiver text v1'), 'hash matches version');

// version invalidation: change text
update_post_meta($waiver_tour, '_tap_tour_waiver', 'Waiver text v2');
tap_t_assert(!TAP_Waiver::has_valid($bid), 'waiver invalid after text change');

// sign with short name fails
$bid2_res = TAP_Booking::create([
    'service_type' => 'tap_tour',
    'service_id'   => $waiver_tour,
    'check_in'     => gmdate('Y-m-d', strtotime('+30 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+31 days')),
    'adults'       => 1,
    'children'     => 0,
    'guest_name'   => 'Waiver Tester2',
    'guest_email'  => 'waiver_e2e2@example.test',
]);
$bid2 = is_wp_error($bid2_res) ? 0 : (int) $bid2_res['booking_id'];
$bad = TAP_Waiver::sign($bid2, 'Jo', 'data:image/png;base64,AAAA');
tap_t_assert(is_wp_error($bad) && $bad->get_error_code() === 'bad_name', 'short name rejected');
// restore text
update_post_meta($waiver_tour, '_tap_tour_waiver', 'Waiver text v1');

// ---- 10) Extras: CRUD + total + render ----
$extra_tour = to_make_post('tap_tour', ['_tap_tour_price_adult' => 30, '_tap_tour_currency' => 'USD']);
$e1 = TAP_Service_Extras::create(['service_type' => 'tap_tour', 'service_id' => $extra_tour, 'name' => 'Transporte', 'description' => 'Traslado', 'price' => 15, 'currency' => 'USD', 'is_per_person' => 1, 'is_required' => 0]);
$e2 = TAP_Service_Extras::create(['service_type' => 'tap_tour', 'service_id' => $extra_tour, 'name' => 'Equipo', 'description' => 'Alquiler', 'price' => 10, 'currency' => 'USD', 'is_per_person' => 1, 'is_required' => 1]);
tap_t_assert($e1 > 0 && $e2 > 0, 'extras created');
$extras = TAP_Service_Extras::for_service('tap_tour', $extra_tour);
tap_t_assert(count($extras) === 2, 'for_service returns 2');

$total = TAP_Service_Extras::total_for([$e1, $e2], 2, 1);
tap_t_assert(abs($total - 75.0) < 0.001, sprintf('total_for per-person = 75 (got %s)', $total));

// flat extra
$e3 = TAP_Service_Extras::create(['service_type' => 'tap_tour', 'service_id' => $extra_tour, 'name' => 'Foto paquete', 'price' => 25, 'currency' => 'USD', 'is_per_person' => 0, 'is_required' => 0]);
$total2 = TAP_Service_Extras::total_for([$e3], 2, 1);
tap_t_assert(abs($total2 - 25.0) < 0.001, 'flat extra = 25 regardless of people');

$sel = TAP_Service_Extras::render_selector('tap_tour', $extra_tour);
tap_t_assert(strpos($sel, 'Transporte') !== false && strpos($sel, 'Equipo') !== false, 'render_selector includes extras');

// update
TAP_Service_Extras::update($e1, ['price' => 20, 'is_required' => 1]);
$upd = TAP_Service_Extras::get($e1);
tap_t_assert(abs((float)$upd->price - 20.0) < 0.001 && (int)$upd->is_required === 1, 'update works');

// delete
TAP_Service_Extras::delete($e2);
tap_t_assert(count(TAP_Service_Extras::for_service('tap_tour', $extra_tour)) === 2, 'delete removed one (3->2)');

// cleanup extras
foreach (TAP_Service_Extras::for_service('tap_tour', $extra_tour, false) as $ex) {
    TAP_Service_Extras::delete($ex->id);
}

// ---- 11) Cleanup ----
foreach ($created_posts as $pid) {
    wp_delete_post($pid, true);
}
tap_t_cleanup_bookings([$bid, $bid2]);

tap_t_finish();
