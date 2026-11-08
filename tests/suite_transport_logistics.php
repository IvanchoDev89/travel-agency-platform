<?php
/**
 * suite_transport_logistics.php — Transport logistics E2E.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb, $created_posts;
$created_posts = [];

$agency = tap_t_test_agency();

// ---- Vehicles ----
$vid = TAP_Transport_Vehicles::create([
    'agency_id' => $agency,
    'type' => 'land',
    'plate' => 'TEST-' . wp_generate_password(4, false),
    'model' => 'Test Van',
    'capacity' => 8,
    'status' => 'active',
]);
tap_t_assert($vid > 0, 'vehicle created');
$v = TAP_Transport_Vehicles::get($vid);
tap_t_assert($v && $v->type === 'land', 'vehicle get works');
TAP_Transport_Vehicles::update($vid, ['status' => 'maintenance']);
$v2 = TAP_Transport_Vehicles::get($vid);
tap_t_assert($v2->status === 'maintenance', 'vehicle update works');

// ---- Drivers ----
$uid = wp_create_user('driver_e2e_' . wp_generate_password(4, false), 'pass1234', 'driver_' . wp_generate_password(4, false) . '@test.com');
$did = TAP_Transport_Drivers::create([
    'user_id' => $uid,
    'agency_id' => $agency,
    'license_number' => 'LIC-' . wp_generate_password(4, false),
    'license_type' => 'B1',
    'status' => 'active',
]);
tap_t_assert($did > 0, 'driver created');
$drv = TAP_Transport_Drivers::by_user($uid);
tap_t_assert($drv && (int) $drv->id === $did, 'driver by_user works');

// ---- Routes ----
$rid = TAP_Transport_Routes::create([
    'origin' => 'La Fortuna',
    'destination' => 'Arenal',
    'distance_km' => 15.5,
    'estimated_duration' => 30,
    'type' => 'land',
]);
tap_t_assert($rid > 0, 'route created');
$rt = TAP_Transport_Routes::get($rid);
tap_t_assert($rt && $rt->origin === 'La Fortuna', 'route get works');
$found = TAP_Transport_Routes::search('La Fortuna', 'Arenal', 'land');
tap_t_assert(count($found) >= 1, 'route search works');

// ---- Trips ----
$svc_id = wp_insert_post(['post_type' => 'tap_transport', 'post_status' => 'publish', 'post_title' => 'Transport E2E']);
$created_posts[] = (int) $svc_id;
update_post_meta($svc_id, '_tap_trans_agency_id', $agency);
$trip_id = TAP_Transport_Trips::create([
    'service_id' => $svc_id,
    'vehicle_id' => $vid,
    'driver_id' => $did,
    'route_id' => $rid,
    'departure_time' => gmdate('Y-m-d H:i:s', strtotime('+1 day 08:00')),
    'arrival_time' => gmdate('Y-m-d H:i:s', strtotime('+1 day 09:00')),
    'status' => 'scheduled',
]);
tap_t_assert($trip_id > 0, 'trip created');
$trip = TAP_Transport_Trips::get($trip_id);
tap_t_assert($trip && $trip->status === 'scheduled', 'trip get works');

// Status transitions
tap_t_assert(TAP_Transport_Trips::set_status($trip_id, 'boarding'), 'scheduled -> boarding');
tap_t_assert(!TAP_Transport_Trips::set_status($trip_id, 'completed'), 'boarding -> completed invalid');
tap_t_assert(TAP_Transport_Trips::set_status($trip_id, 'in_transit'), 'boarding -> in_transit');
tap_t_assert(TAP_Transport_Trips::set_status($trip_id, 'completed'), 'in_transit -> completed');
$trip2 = TAP_Transport_Trips::get($trip_id);
tap_t_assert($trip2->status === 'completed', 'trip status updated');

// ---- Passengers ----
$bid = TAP_Transport_Trips::add_passenger($trip_id, 999999, 'Test Passenger');
tap_t_assert($bid > 0, 'passenger added');
// Idempotency.
$bid2 = TAP_Transport_Trips::add_passenger($trip_id, 999999, 'Test Passenger');
tap_t_assert($bid2 === $bid, 'passenger idempotent');
$passengers = TAP_Transport_Trips::passengers($trip_id);
tap_t_assert(count($passengers) === 1, 'passengers list works');
TAP_Transport_Trips::check_in_passenger($trip_id, 999999);
$passengers2 = TAP_Transport_Trips::passengers($trip_id);
tap_t_assert($passengers2[0]->checked_in == 1, 'check-in works');

// ---- Tracking ----
$tid = TAP_Transport_Tracking::record($trip_id, 9.93, -84.08, 50.0, 180);
tap_t_assert($tid > 0, 'tracking recorded');
$latest = TAP_Transport_Tracking::latest($trip_id);
tap_t_assert($latest && (float) $latest->lat === 9.93, 'latest position works');
$hist = TAP_Transport_Tracking::history($trip_id, 5);
tap_t_assert(count($hist) >= 1, 'history works');

// ---- Cleanup ----
global $wpdb;
$wpdb->delete($wpdb->prefix . 'tap_trip_tracking', ['trip_id' => $trip_id], ['%d']);
$wpdb->delete($wpdb->prefix . 'tap_trip_passengers', ['trip_id' => $trip_id], ['%d']);
$wpdb->delete(TAP_Transport_Trips::table(), ['id' => $trip_id], ['%d']);
TAP_Transport_Routes::delete($rid);
TAP_Transport_Drivers::delete($did);
TAP_Transport_Vehicles::delete($vid);
wp_delete_user($uid);
foreach ($created_posts as $pid) wp_delete_post($pid, true);

tap_t_finish();