<?php
/**
 * suite_front_editor.php — Fase 3 (producto para agencias): editor frontal.
 *
 * Covers:
 *  F3-1  featured image + gallery via remote URL sideloaded into the Media
 *        Library; empty URL clears the thumbnail; bad URLs fail silently.
 *  F3-2  full room editor save: description/excerpt, floor, amenities, beds
 *        builder (invalid types fall back to 'double', count<=0 dropped),
 *        room thumbnail and gallery.
 *  F3-3  money-integrity price guard in save_listing_data (price <= 0 is
 *        rejected for every type), employees cannot operate bookings even
 *        when linked to an agency, and the bo-operations table renders
 *        read-only (no action buttons) for employees.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;

$fe_users    = [];
$fe_agencies = [];
$fe_posts    = [];
$fe_atts     = [];

/** Agency + user with the given role; links the agency post to the user. */
function fe_make_agency($role = 'tap_agency_admin') {
    global $fe_users, $fe_agencies;
    $suffix = wp_generate_password(5, false);
    $uname  = 'fe_' . $suffix;
    $uid    = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => $role,
        'display_name' => 'FE ' . $role,
    ]);
    if (is_wp_error($uid) || !$uid) {
        return [0, 0];
    }
    $aid = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'FE Agency ' . $suffix]);
    if (!$aid || is_wp_error($aid)) {
        wp_delete_user($uid);
        return [0, 0];
    }
    update_post_meta($aid, '_tap_agency_user_id', (int) $uid);
    $fe_users[]    = (int) $uid;
    $fe_agencies[] = (int) $aid;
    return [(int) $uid, (int) $aid];
}

list($owner_uid, $owner_aid) = fe_make_agency('tap_agency_admin');
list($emp_uid, $emp_aid)     = fe_make_agency('tap_agency_employee');
tap_t_assert($owner_uid && $owner_aid && $emp_uid && $emp_aid, 'created agency admin + employee fixtures');

/** Tiny valid PNG (1x1) for sideloading; also a portable GD-free fallback. */
function fe_png_bytes() {
    static $png = null;
    if (null === $png) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
    return $png;
}

/** Mock pre_http_request so download_url() succeeds with a real image body. */
function fe_install_http_ok(array $urls, &$calls) {
    $calls = [];
    $cb = function ($pre, $args, $url) use ($urls, &$calls) {
        $calls[] = $url;
        foreach ($urls as $u) {
            if (strpos($url, $u) !== false) {
                if (!empty($args['stream']) && !empty($args['filename'])) {
                    file_put_contents($args['filename'], fe_png_bytes());
                }
                return [
                    'headers'  => [],
                    'body'     => fe_png_bytes(),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        }
        return new WP_Error('no_route', 'no http mock route for this url');
    };
    add_filter('pre_http_request', $cb, 10, 3);
    return $cb;
}

function fe_remove_http_mock($cb) {
    if ($cb) {
        remove_filter('pre_http_request', $cb, 10);
    }
}

/* ══ 0) Pre-flight: beds vocabulary exists ─────────────────────────── */
$bed_types = TAP_Post_Types::bed_types();
$fe_tok = is_array($bed_types) && isset($bed_types['king'], $bed_types['queen'], $bed_types['double'], $bed_types['twin'], $bed_types['bunk'], $bed_types['sofa'], $bed_types['crib'], $bed_types['murphy'], $bed_types['futon']);
tap_t_assert($fe_tok, 'TAP_Post_Types::bed_types exposes the expected bed vocabulary');

/* ══ 1) F3-1: featured image + gallery sideload ───────────────────── */
tap_t_assert(0 === TAP_Ajax::sideload_image('https://'), 'sideload_image rejects an invalid URL with 0');
$fails = [];
$cb_fail = function ($pre, $args, $url) use (&$fails) {
    $fails[] = $url;
    return new WP_Error('http_fail', 'unreachable');
};
add_filter('pre_http_request', $cb_fail, 10, 3);
tap_t_assert(0 === TAP_Ajax::sideload_image('http://93.184.216.34/nope.png'), 'sideload_image degrades to 0 when the download fails');
remove_filter('pre_http_request', $cb_fail, 10);
tap_t_assert(count($fails) > 0, 'the download failure went through pre_http_request');

$http_calls = [];
$cb_ok = fe_install_http_ok(['img-a.png', 'img-b.png'], $http_calls);
$att_a = TAP_Ajax::sideload_image('http://93.184.216.34/media/img-a.png');
$att_b = TAP_Ajax::sideload_image('http://93.184.216.34/media/img-b.png');
tap_t_assert(is_int($att_a) && $att_a > 0 && is_int($att_b) && $att_b > 0 && $att_a !== $att_b, 'sideload_image created two distinct Media Library attachments');
$fe_atts[] = $att_a;
$fe_atts[] = $att_b;
tap_t_assert(count($http_calls) >= 2, 'the PNG downloads were served through the mocked HTTP layer');

wp_set_current_user($owner_uid);
$acc = TAP_Ajax::save_listing_data($owner_uid, [
    'title'               => 'FE Casa ' . wp_generate_password(4, false),
    'description'         => 'Casa de prueba',
    'listing_type'        => 'tap_accommodation',
    'type'                => 'hostel',
    'price_per_night'     => '85.50',
    'featured_image_url'  => 'http://93.184.216.34/media/img-a.png',
    'gallery_urls'        => "http://93.184.216.34/media/img-a.png\nhttp://93.184.216.34/media/img-b.png",
]);
$acc_id = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
$fe_posts[] = $acc_id;
tap_t_assert(true === $acc && $acc_id > 0, 'accommodation with featured + gallery saved');
tap_t_assert(get_post_thumbnail_id($acc_id) > 0, 'featured image attached as the post thumbnail');
$g = array_filter(array_map('intval', explode(',', (string) get_post_meta($acc_id, '_tap_acc_gallery', true))));
tap_t_assert(count($g) >= 2, 'gallery meta stores two distinct attachment ids');

// empty featured URL clears the thumbnail (edit)
$acc2 = TAP_Ajax::save_listing_data($owner_uid, [
    'listing_id'      => $acc_id,
    'title'           => get_the_title($acc_id),
    'description'     => 'edit',
    'listing_type'    => 'tap_accommodation',
    'featured_image_url' => '',
]);
tap_t_assert(true === $acc2 && !get_post_thumbnail_id($acc_id), 'clearing the featured URL removes the thumbnail');

// a bad image URL does not crash the save and leaves no thumbnail
$noimg = TAP_Ajax::save_listing_data($owner_uid, [
    'listing_id'       => $acc_id,
    'title'            => get_the_title($acc_id),
    'description'      => 'edit2',
    'listing_type'     => 'tap_accommodation',
    'featured_image_url' => 'http://93.184.216.34/media/missing.png',
]);
tap_t_assert(true === $noimg && !get_post_thumbnail_id($acc_id), 'a failing image URL saves the listing quietly (no thumbnail)');

/* ══ 2) F3-2: full room editor (beds, amenities, floor, media) ────── */
wp_set_current_user($owner_uid);
$room_post = [
    'accommodation_id'      => $acc_id,
    'room_id'               => 0,
    'title'                 => 'Habitación Ocean View',
    'price_per_night'       => '80.00',
    'description'           => 'Vista al mar, cama king.',
    'min_stay'              => '2',
    'max_adults'            => '2',
    'max_occupancy'         => '3',
    'inventory'             => '1',
    'floor'                 => '2',
    'is_active'             => '1',
    'amenities'             => 'Wifi, A/C, wifi, Balcón',
    '_tap_room_beds'        => wp_json_encode([
        ['type' => 'king',   'count' => 1],
        ['type' => 'hammock','count' => 2],
        ['type' => 'queen',  'count' => 0],
        ['type' => 'twin',   'count' => '3'],
    ]),
    'room_thumbnail_url'    => 'http://93.184.216.34/media/img-a.png',
    'gallery_urls'          => "http://93.184.216.34/media/img-a.png\nhttp://93.184.216.34/media/img-b.png",
];
$room_res = TAP_Ajax::save_room_data($acc_id, 0, $room_post);
$room_id  = is_int($room_res) ? $room_res : 0;
$fe_posts[] = $room_id;
tap_t_assert(is_int($room_res) && $room_id > 0, 'room editor core returns a room id');
tap_t_assert(get_post_field('post_excerpt', $room_id) === 'Vista al mar, cama king.', 'room description persisted as the post excerpt');
tap_t_assert((int) get_post_meta($room_id, '_tap_room_floor', true) === 2, 'room floor persisted');
$a = get_post_meta($room_id, '_tap_room_amenities', true);
$a = is_string($a) ? json_decode($a, true) : $a;
tap_t_assert(is_array($a) && in_array('wifi', $a, true) && in_array('A/C', $a, true) && in_array('Balcón', $a, true) && 3 <= count($a), 'amenities are de-duplicated exactly, trimmed and normalized');
$b = json_decode((string) get_post_meta($room_id, '_tap_room_beds', true), true);
tap_t_assert(is_array($b) && $b[0]['type'] === 'king' && (int) $b[0]['count'] === 1, 'king bed row persisted');
tap_t_assert(isset($b[1]) && $b[1]['type'] === 'double' && (int) $b[1]['count'] === 2, 'unknown bed type falls back to double');
tap_t_assert(count($b) === 3 && $b[2]['type'] === 'twin' && (int) $b[2]['count'] === 3, 'queen count=0 dropped, string counts cast to int');
tap_t_assert(get_post_thumbnail_id($room_id) > 0, 'room featured image attached');
$rg = array_filter(array_map('intval', explode(',', (string) get_post_meta($room_id, '_tap_room_gallery', true))));
tap_t_assert(count($rg) >= 2, 'room gallery persisted two attachments');
tap_t_assert((int) get_post_meta($room_id, '_tap_room_accommodation_id', true) === (int) $acc_id, 'room linked to its accommodation');
tap_t_assert(abs(80 - (float) get_post_meta($room_id, '_tap_room_price_per_night', true)) < 0.001, 'room price persisted');

// edit path updates the same room
$room_post['room_id']        = $room_id;
$room_post['title']          = 'Habitación Ocean View Deluxe';
$room_post['price_per_night']= '95.00';
$room_post['floor']          = '3';
$room_edit = TAP_Ajax::save_room_data($acc_id, $room_id, $room_post);
tap_t_assert((int) $room_edit === (int) $room_id, 'editing the room returns the same id');
tap_t_assert('Habitación Ocean View Deluxe' === get_the_title($room_id) && (int) get_post_meta($room_id, '_tap_room_floor', true) === 3, 'room edit persisted title + floor');

// room price <= 0 is rejected server-side
$room_post['room_id'] = $room_id;
$room_post['price_per_night'] = '0';
$bad_price = TAP_Ajax::save_room_data($acc_id, $room_id, $room_post);
tap_t_assert(is_wp_error($bad_price) && 'invalid_price' === $bad_price->get_error_code(), 'room price of 0 is rejected by the editor core');
// a room that belongs to another accommodation cannot be edited through its id
$other = TAP_Ajax::save_room_data(-1, $room_id, $room_post);
tap_t_assert(is_wp_error($other) && 'invalid_room' === $other->get_error_code(), 'room id mismatch with the accommodation is rejected');
fe_remove_http_mock($cb_ok);

/* ══ 3) F3-3: price guard on save_listing_data ────────────────────── */
$sz = strtolower(wp_generate_password(4, false));
$bad0 = TAP_Ajax::save_listing_data($owner_uid, ['title' => "FE zero {$sz}", 'description' => 'x', 'listing_type' => 'tap_tour', '_tap_tour_price_adult' => '0']);
tap_t_assert(true !== $bad0 && false !== strpos((string) $bad0, 'mayor que 0'), 'tour with price 0 is rejected before creating a post');
$bad1 = TAP_Ajax::save_listing_data($owner_uid, ['title' => "FE neg {$sz}", 'description' => 'x', 'listing_type' => 'tap_tour', '_tap_tour_price_adult' => '-5']);
tap_t_assert(true !== $bad1, 'tour with a negative price is rejected');
$bad2 = TAP_Ajax::save_listing_data($owner_uid, ['title' => "FE acc0 {$sz}", 'description' => 'x', 'listing_type' => 'tap_accommodation', 'price_per_night' => '0']);
tap_t_assert(true !== $bad2, 'accommodation legacy price of 0 is rejected');
$ok = TAP_Ajax::save_listing_data($owner_uid, ['title' => "FE tour ok {$sz}", 'description' => 'x', 'listing_type' => 'tap_tour', '_tap_tour_price_adult' => '49.90']);
$tour_id = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
$fe_posts[] = $tour_id;
tap_t_assert(true === $ok && $tour_id > 0, 'a valid positive price still creates the listing');

/* ══ 4) F3-3: employees cannot operate bookings (even when linked) ── */
$fe_bookings = [];
$fe_bookings[] = tap_t_seed_booking(['agency_id' => $emp_aid, 'status' => 'pending']);
wp_set_current_user($emp_uid);
$r = TAP_Booking::agency_booking_action($fe_bookings[0], 'confirm');
tap_t_assert(is_wp_error($r) && 'no_agency' === $r->get_error_code(), 'a linked employee is still denied booking operations');

// bo-operations renders read-only (no action buttons) for employees
$_GET['seccion'] = 'bo-operations';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'Solo lectura para empleados.'), 'employee sees the read-only marker in operations');
tap_t_assert(false === strpos($out, 'tap-bo-api') && false === strpos($out, 'Confirmar'), 'employee operations table hides every action button');

// the very same section still renders the buttons for the agency admin
$fe_bookings[] = tap_t_seed_booking(['agency_id' => $owner_aid, 'status' => 'pending']);
wp_set_current_user($owner_uid);
$_GET['seccion'] = 'bo-operations';
$out = do_shortcode('[tap_front_dash]');
unset($_GET['seccion']);
tap_t_assert(false !== strpos($out, 'tap-bo-api') && false !== strpos($out, 'Confirmar'), 'agency admin still gets the full action buttons');

/* ── cleanup ─────────────────────────────────────────────────────── */
wp_set_current_user(0);
tap_t_cleanup_bookings($fe_bookings);
foreach ($fe_atts as $att_id) {
    wp_delete_attachment($att_id, true);
}
foreach ($fe_posts as $pid) {
    wp_delete_post($pid, true);
}
foreach ($fe_agencies as $aid) {
    wp_delete_post($aid, true);
}
foreach ($fe_users as $uid) {
    wp_delete_user($uid);
}
tap_t_finish();