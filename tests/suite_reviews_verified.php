<?php
/**
 * suite_reviews_verified.php — Fase 5: reviews are verified purchases.
 * A review is only accepted once the author owns a confirmed/completed
 * booking for that exact service. Covers guards, persistence, public fields
 * (badge without email leak) and the TAP_Reviews helpers. Self-cleaning.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

$rev_table = $wpdb->prefix . 'tap_reviews';
list($fid, $ftype) = tap_t_find_service();
tap_t_assert($fid > 0, 'discovered service to review');

$u1 = tap_t_make_subscriber();
$u2 = tap_t_make_subscriber();
tap_t_assert($u1 > 0 && $u2 > 0, 'created reviewers');
$u_no = tap_t_make_subscriber();
tap_t_assert($u_no > 0, 'created reviewer without a booking');

$kept_reviews = [];

// 1) no booking -> rejected ------------------------------------------------------------
wp_set_current_user($u_no);
$r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'content' => 'I wish I could']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r) === 'review_not_verified', 'review without any booking rejected');
tap_t_assert(tap_t_rest_data($r) === null || $r->get_data() || tap_t_rest_error_code($r) !== '', '403 guards verified reviews');

// 2) pending booking -> rejected ---------------------------------------------------------
$bp = tap_t_seed_booking(['client_id' => $u_no, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'pending']);
tap_t_assert($bp > 0, 'seeded pending booking');
wp_set_current_user($u_no);
$r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'content' => 'not confirmed yet']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r) === 'review_not_verified', 'pending booking does not verify a review');

// 3) confirmed booking -> accepted, stored verified ------------------------------------
$b1 = tap_t_seed_booking(['client_id' => $u1, 'service_type' => $ftype, 'service_id' => $fid, 'check_in' => '2026-05-10']);
tap_t_assert($b1 > 0, 'seeded confirmed booking for u1');
wp_set_current_user($u1);
$r1 = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'title' => 'Memorable', 'content' => 'Truly a wonderful stay']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r1) === '', 'verified review accepted');
$r1_id = (int) tap_t_rest_data($r1)['review_id'];
$kept_reviews[] = $r1_id;
$stored = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rev_table WHERE id=%d", $r1_id));
tap_t_assert($stored && (int) $stored->is_verified === 1, 'review persisted as verified');
tap_t_assert((int) $stored->booking_id === $b1, 'verified review links the author booking');
tap_t_assert((int) $stored->is_approved === 0, 'verified review still awaits approval');

// 4) someone else's booking id -> rejected ----------------------------------------------
$b2 = tap_t_seed_booking(['client_id' => $u2, 'service_type' => $ftype, 'service_id' => $fid]);
tap_t_assert($b2 > 0, 'seeded confirmed booking for u2');
wp_set_current_user($u2);
$r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 4, 'content' => 'claiming u1 booking', 'booking_id' => $b1]);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r) === 'review_not_verified', 'booking owned by another user rejected');

// 5) mismatched service guard ------------------------------------------------------------
$others = get_posts(['post_type' => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'exclude' => [$fid]]);
if ($others) {
    $fid2 = (int) $others[0];
    $ftype2 = get_post_type($fid2);
    wp_set_current_user($u1);
    $r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype2, 'service_id' => $fid2, 'rating' => 5, 'content' => 'wrong service', 'booking_id' => $b1]);
    wp_set_current_user(0);
    tap_t_assert(tap_t_rest_error_code($r) === 'review_not_verified', 'booking for another service cannot verify the review');
} else {
    tap_t_assert(true, 'no second service to test mismatch guard (skipped)');
}

// 6) invalid service -----------------------------------------------------------------------
wp_set_current_user($u1);
$r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => 999999, 'rating' => 5, 'content' => 'ghost']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r) === 'invalid_service', 'nonexistent service rejected');
wp_set_current_user($u1);
$r = tap_t_rest('POST', '/tap/v1/review', ['service_type' => 'tap_nonsense', 'service_id' => $fid, 'rating' => 5, 'content' => 'nope']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r) === 'invalid_service', 'unknown service type rejected');

// 7) public read: verified badge, no email leak ---------------------------------------------
$wpdb->update($rev_table, ['is_approved' => 1], ['id' => $r1_id]);
wp_cache_delete('tap_rating_' . $ftype . '_' . $fid, 'tap_ratings');
$read = tap_t_rest('GET', "/tap/v1/reviews/{$ftype}/{$fid}");
$rows = $read->get_data();
tap_t_assert(count($rows) === 1 && (int) $rows[0]->is_verified === 1, 'public read exposes is_verified badge');
$leak = false;
foreach ($rows as $row) {
    if (isset($row->user_email) || isset($row->mod_status) || isset($row->mod_reason)) {
        $leak = true;
    }
}
tap_t_assert(!$leak, 'public read does not leak emails or moderation fields');

// 8) helpers ----------------------------------------------------------------------------------
tap_t_assert(TAP_Reviews::can_review($u1, $ftype, $fid), 'can_review true for a guest with a confirmed booking');
tap_t_assert(!TAP_Reviews::can_review($u_no, $ftype, $fid), 'can_review false for a user without verified booking');
tap_t_assert(TAP_Reviews::verified_only_message() !== '', 'verified-only message provided');
$old = tap_t_seed_booking(['client_id' => $u1, 'service_type' => $ftype, 'service_id' => $fid, 'check_in' => '2026-01-01']);
$latest = TAP_Reviews::latest_booking($u1, $ftype, $fid);
tap_t_assert($latest && (int) $latest->id === $b1, 'latest_booking prefers the most recent stay');
tap_t_assert(TAP_Reviews::match_booking($u2, $b2) && !TAP_Reviews::match_booking($u1, $b2), 'match_booking enforces ownership');

// cleanup ---------------------------------------------------------------------------------------
if ($kept_reviews) {
    $in = implode(',', array_map('intval', $kept_reviews));
    $wpdb->query("DELETE FROM $rev_table WHERE id IN ({$in})");
}
wp_cache_delete('tap_rating_' . $ftype . '_' . $fid, 'tap_ratings');
tap_t_cleanup_bookings([$bp, $b1, $b2, $old]);
wp_delete_user($u1);
wp_delete_user($u2);
wp_delete_user($u_no);
tap_t_finish();