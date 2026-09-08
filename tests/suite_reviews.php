<?php
/**
 * suite_reviews.php — Fase C: review lifecycle via REST tap/v1/review.
 * submit -> pending -> hidden from reads -> approve -> rating aggregation.
 * Duplicate/missing-field/anonymous guards. Self-cleaning (rows + users).
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
tap_t_assert($u1 > 0 && $u2 > 0, 'created two reviewers');

// Fase 5: reviews are verified — each author holds a confirmed booking.
$b1 = tap_t_seed_booking(['client_id' => $u1, 'service_type' => $ftype, 'service_id' => $fid]);
$b2 = tap_t_seed_booking(['client_id' => $u2, 'service_type' => $ftype, 'service_id' => $fid]);
tap_t_assert($b1 > 0 && $b2 > 0, 'seeded confirmed bookings for reviewers');

// 1) pending review is stored but hidden ------------------------------------------
wp_set_current_user($u1);
$r1 = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 4.5, 'title' => 'Great trip', 'content' => 'Loved it e2e']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($r1) === '', 'review submission accepted');
$r1_data = tap_t_rest_data($r1);
$r1_id = (int) $r1_data['review_id'];
tap_t_assert($r1_id > 0, 'review id returned');
$stored = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rev_table WHERE id=%d", $r1_id));
tap_t_assert($stored && (int) $stored->is_approved === 0, 'review stored pending approval');
tap_t_assert((int) $stored->user_id === $u1 && abs((float) $stored->rating - 4.5) < 0.001, 'review author + rating persisted');

$read = tap_t_rest('GET', "/tap/v1/reviews/{$ftype}/{$fid}");
tap_t_assert(!is_wp_error($read) && $read->get_data() === [], 'unapproved review hidden from public reads');

$stats = TAP_API::get_rating_stats($ftype, $fid);
tap_t_assert($stats['count'] === 0 && $stats['avg'] === 0.0, 'rating stats ignore pending reviews');

// 2) guard rails --------------------------------------------------------------------
wp_set_current_user($u1);
$dup = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'content' => 'again']);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($dup) === 'duplicate', 'duplicate review rejected (409)');
wp_set_current_user($u1);
$missing = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5]);
wp_set_current_user(0);
tap_t_assert(tap_t_rest_error_code($missing) === 'missing_field', 'review without content rejected');
$anon = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 5, 'content' => 'x']);
tap_t_assert(tap_t_rest_error_code($anon) === 'rest_forbidden', 'anonymous review rejected');

// 3) approval drives aggregation ---------------------------------------------------------
wp_set_current_user($u2);
$r2 = tap_t_rest('POST', '/tap/v1/review', ['service_type' => $ftype, 'service_id' => $fid, 'rating' => 3.5, 'content' => 'ok e2e']);
wp_set_current_user(0);
$r2_data = tap_t_rest_data($r2);
$r2_id = (int) $r2_data['review_id'];
tap_t_assert($r2_id > 0, 'second review submitted');

$wpdb->update($rev_table, ['is_approved' => 1], ['id' => $r1_id]);
$wpdb->update($rev_table, ['is_approved' => 1], ['id' => $r2_id]);

wp_cache_delete('tap_rating_' . $ftype . '_' . $fid, 'tap_ratings');
$stats_after = TAP_API::get_rating_stats($ftype, $fid);
tap_t_assert($stats_after['count'] === 2, 'approved reviews counted');
tap_t_assert(abs($stats_after['avg'] - 4.0) < 0.001, 'average rating = (4.5 + 3.5) / 2');

$read2 = tap_t_rest('GET', "/tap/v1/reviews/{$ftype}/{$fid}");
$rows2 = $read2->get_data();
tap_t_assert(count($rows2) === 2, 'approved reviews visible publicly');
tap_t_assert(!empty($rows2[0]->user_name), 'review join exposes author name');

// cleanup -------------------------------------------------------------------------------
$wpdb->query($wpdb->prepare("DELETE FROM $rev_table WHERE id IN (%d,%d)", $r1_id, $r2_id));
wp_cache_delete('tap_rating_' . $ftype . '_' . $fid, 'tap_ratings');
tap_t_cleanup_bookings([$b1, $b2]);
wp_delete_user($u1);
wp_delete_user($u2);
tap_t_finish();