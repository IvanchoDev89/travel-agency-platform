<?php
/**
 * suite_rest.php — Fase C: REST API tap/v1 via in-process dispatcher.
 * Public read endpoints, auth-gated booking endpoints, search and availability.
 * REST errors may surface as WP_Error or as formatted responses; assertions use
 * tap_t_rest_error_code to be robust to either.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

// 1) public reads -----------------------------------------------------------------
$svc = tap_t_rest('GET', '/tap/v1/services');
tap_t_assert(!is_wp_error($svc), 'GET /tap/v1/services ok');
$data = $svc->get_data();
tap_t_assert(is_array($data), 'services payload is an array');
list($fid, $ftype) = tap_t_find_service();
tap_t_assert($fid > 0, 'discovered a published service');

$by_type = tap_t_rest('GET', '/tap/v1/services/tap_accommodation');
tap_t_assert(!is_wp_error($by_type), 'GET /services/{type} ok');
$by_type_data = $by_type->get_data();
tap_t_assert(is_array($by_type_data) && isset($by_type_data['services'], $by_type_data['total']), 'services type payload shaped');

$bad_type = tap_t_rest('GET', '/tap/v1/services/nonsense');
tap_t_assert(tap_t_rest_error_code($bad_type) === 'invalid_type', 'invalid service type rejected');

$detail = tap_t_rest('GET', "/tap/v1/services/{$ftype}/{$fid}");
$ddata = tap_t_rest_data($detail);
tap_t_assert(is_array($ddata) && (($ddata['id'] ?? '') === $fid), 'service detail returns the service');

$agencies = tap_t_rest('GET', '/tap/v1/agencies');
tap_t_assert(!is_wp_error($agencies) && is_array($agencies->get_data()), 'GET /agencies ok');

$locs = tap_t_rest('GET', '/tap/v1/locations');
tap_t_assert(!is_wp_error($locs) && is_array($locs->get_data()), 'GET /locations ok');

// search matches an ACTIVE service's title keyword
$active_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
$active_svc = 0;
foreach ($active_types as $t) {
    $found = get_posts([
        'post_type' => $t, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [['key' => '_tap_' . TAP_Post_Types::meta_prefix($t) . '_is_active', 'value' => '1']],
    ]);
    if ($found) { $active_svc = (int) $found[0]; break; }
}
if ($active_svc) {
    $title = get_the_title($active_svc);
    $kw = null;
    foreach (preg_split('/\s+/', trim($title)) as $w) {
        if (strlen($w) >= 3) { $kw = $w; break; }
    }
    if ($kw) {
        $sr = tap_t_rest('GET', '/tap/v1/search', ['keyword' => $kw]);
        tap_t_assert(!is_wp_error($sr), 'search endpoint ok');
        $sres = $sr->get_data();
        $hay = array_map(fn($h) => (int) $h['id'], $sres['results'] ?? []);
        tap_t_assert(in_array($active_svc, $hay, true), "search finds active service by keyword '{$kw}'");
    } else {
        tap_t_pass('active service title has no usable keyword (skip search hit assert)');
    }
} else {
    tap_t_pass('no active service published on this site (skip search hit assert)');
}

$avail = tap_t_rest('GET', "/tap/v1/availability/{$ftype}/{$fid}");
tap_t_assert(!is_wp_error($avail) && is_array($avail->get_data()), 'availability endpoint ok');

// 2) auth-gated booking endpoints --------------------------------------------------
$anon = tap_t_rest('POST', '/tap/v1/booking', ['service_type' => $ftype, 'service_id' => $fid, 'total_amount' => 10]);
tap_t_assert(tap_t_rest_error_code($anon) === 'rest_forbidden', 'anonymous cannot create a booking');

$uid = tap_t_make_subscriber();
tap_t_assert($uid > 0, 'created client user');
wp_set_current_user($uid);
$in  = gmdate('Y-m-d', strtotime('+50 days'));
$out = gmdate('Y-m-d', strtotime('+52 days'));
$bk = tap_t_rest('POST', '/tap/v1/booking', [
    'service_type' => $ftype,
    'service_id'   => $fid,
    'total_amount' => 75,
    'check_in'     => $in,
    'check_out'    => $out,
    'adults'       => 2,
]);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($bk) === '', 'authenticated booking created via REST');
$bk_data = tap_t_rest_data($bk);
tap_t_assert(!empty($bk_data['booking_id']) && !empty($bk_data['booking_code']), 'booking payload has id + code');
$bid = (int) $bk_data['booking_id'];

wp_set_current_user($uid);
$mine = tap_t_rest('GET', '/tap/v1/my-bookings', ['limit' => 50]);
$mine_ids = array_map(fn($b) => (int) $b->id, (array) $mine->get_data());
tap_t_assert(in_array($bid, $mine_ids, true), 'own booking appears in my-bookings');
$mine_check = tap_t_rest('GET', "/tap/v1/booking/{$bid}");
$mine_raw = tap_t_rest_data($mine_check);
tap_t_assert(tap_t_rest_error_code($mine_check) === '' && (int) $mine_raw['id'] === $bid, 'owner can read own booking via REST');
wp_set_current_user(0);

$stranger = tap_t_make_subscriber();
wp_set_current_user($stranger);
$forbidden = tap_t_rest('GET', "/tap/v1/booking/{$bid}");
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($forbidden) === 'forbidden', 'another user cannot read someone else booking');

// cleanup ---------------------------------------------------------------------------
tap_t_cleanup_bookings([$bid]);
wp_delete_user($uid);
wp_delete_user($stranger);
tap_t_finish();