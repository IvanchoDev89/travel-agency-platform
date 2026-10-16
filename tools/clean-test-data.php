<?php
/**
 * clean-test-data.php — purge leftover E2E test fixtures from a dev/staging site.
 *
 * WordPress must be loaded, so run it through WP-CLI:
 *
 *   wp --path="/path/to/app/public" eval-file tools/clean-test-data.php            # dry-run
 *   TAP_CLEAN_APPLY=1 wp --path="/path/to/app/public" eval-file tools/clean-test-data.php
 *
 * Recognition is deliberately conservative: a row is only removed when it
 * carries an unambiguous test signature —
 *   • user_email / lead / consent emails ending in a reserved test domain
 *     (@example.test, @example.invalid, @example.com),
 *   • agencies + listings whose title uses a known fixture prefix,
 *   • booking codes prefixed TAP-E2E- or E2E-,
 *   • any content owned by a recognized test user/agency.
 * Real production data never carries those, so it is never touched.
 *
 * Always run the dry-run first: it prints a per-group report of what would be
 * deleted. Nothing is written unless TAP_CLEAN_APPLY=1 is set.
 */

if (!defined('ABSPATH') || !isset($GLOBALS['wpdb']) || !function_exists('wp_delete_post')) {
    fwrite(STDERR, "This script must run with WordPress loaded (wp eval-file).\n");
    exit(2);
}

$apply = getenv('TAP_CLEAN_APPLY') === '1';

echo "== TAP clean-test-data (" . ($apply ? 'APPLY' : 'DRY-RUN') . ") ==\n";

// Title families emitted by tests/bootstrap.php fixtures across the suites.
// "E2E" anywhere covers Hourly/Kayak/Views/PR/Pay E2E … families; the rest are
// explicit prefixes (case-insensitive under MySQL's default collation).
$FIXTURE_TITLE_RE = 'E2E|^(BO Agency|FE Agency|Team Agency|Promo |Attribution |Lead Test|Lead Tour|Agency RTB|RTB Agency|MDR Agency|DBG|Refund |RF Agency|Suite B|TAP PC|TAP Wizard|TAP Suite|Oak Route|Chestnut Route|FacetSvc|Pay E2E|hola agency)';
$LISTING_TYPES    = ['tap_tour', 'tap_package', 'tap_accommodation', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_room'];

$wpdb = $GLOBALS['wpdb'];
$post_statuses = "('publish','draft','pending','private','trash')";

/** Collect ids of test users by reserved email domain. */
$email_like = "(" . implode(' OR ', array_map(fn($d) => $wpdb->prepare('user_email LIKE %s', '%' . $d), ['@example.test', '@example.invalid', '@example.com'])) . ")";
$test_users = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} WHERE {$email_like}"));
$uid_in = $test_users ? implode(',', $test_users) : '0';

/** Test agencies: fixture title OR owned by a test user. */
$title_re   = $wpdb->prepare('post_title REGEXP %s', $FIXTURE_TITLE_RE);
$owned_meta = "ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_agency_user_id' AND meta_value IN ({$uid_in}))";
$test_agencies = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tap_agency' AND post_status IN {$post_statuses} AND ({$title_re} OR {$owned_meta})"
));
$aid_in = $test_agencies ? implode(',', $test_agencies) : '0';

/** Listings owned by a test agency (any *_agency_id meta) OR fixture-titled. */
$listing_owned  = $test_agencies
    ? "ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_tap\\_%\\_agency_id' AND meta_value IN ({$aid_in}))"
    : '1=0';
$listing_title_re = $wpdb->prepare('post_title REGEXP %s', $FIXTURE_TITLE_RE);
$owned_listings = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('" . implode("','", array_diff($LISTING_TYPES, ['tap_room'])) . "')
     AND post_status IN {$post_statuses}
     AND ({$listing_owned} OR {$listing_title_re})"
));
$owned_in = $owned_listings ? implode(',', $owned_listings) : '0';
// Rooms point at their accommodation (not the agency meta), so follow the link.
$room_owned = $owned_listings
    ? "ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_room_accommodation_id' AND meta_value IN ({$owned_in}))"
    : '1=0';
$orphan_rooms = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tap_room' AND post_status IN {$post_statuses}
     AND ({$room_owned} OR {$listing_title_re})"
));
$test_listings = array_values(array_unique(array_merge($owned_listings, $orphan_rooms)));
$lid_in = $test_listings ? implode(',', $test_listings) : '0';

// Each predicate only applies when the owning set is non-empty, so an empty
// set can never accidentally match orphaned rows whose id is 0.
$in_agencies = $test_agencies ? "agency_id IN ({$aid_in})" : '1=0';
$in_users    = $test_users    ? "user_id IN ({$uid_in})"   : '1=0';
$in_clients  = $test_users    ? "client_id IN ({$uid_in})" : '1=0';
$in_service  = $test_listings ? "service_id IN ({$lid_in})" : '1=0';
$in_room     = $test_listings ? "room_id IN ({$lid_in})"   : '1=0';

$report = [];
$report['users']         = $test_users;
$report['agencies']      = $test_agencies;
$report['listings']      = $test_listings;
$report['reviews']       = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_reviews WHERE {$in_users} OR ({$in_service} AND service_id > 0)"
));
$report['bookings']      = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_bookings WHERE booking_code LIKE 'TAP-E2E-%' OR booking_code LIKE 'E2E-%' OR {$in_agencies} OR {$in_clients}"
));
$report['commission_payments'] = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_commission_payments WHERE {$in_agencies}"
));
$report['registry']      = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_agencies WHERE {$in_users} OR email LIKE '%@example.test' OR name REGEXP " . $wpdb->prepare('%s', $FIXTURE_TITLE_RE)
));
$report['leads']         = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_leads WHERE email LIKE '%@example.test' OR email LIKE '%@example.invalid' OR {$in_agencies}"
));
$report['consents']      = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_consents WHERE email LIKE '%@example.test' OR email LIKE '%@example.invalid'"
));
$report['pricing_rows']  = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id IN ({$lid_in})"
));

WP_CLI::log('Cleanup plan (dry-run unless TAP_CLEAN_APPLY=1):');
foreach ($report as $what => $ids) {
    WP_CLI::log(sprintf('  %-20s %d', $what, count($ids)));
    if (count($ids) && count($ids) <= 8) {
        WP_CLI::log('      ids: ' . implode(',', $ids));
    }
}

if (!getenv('TAP_CLEAN_APPLY')) {
    WP_CLI::log('Dry-run only. Re-run with TAP_CLEAN_APPLY=1 to delete.');
    return;
}

/** DELETE ... WHERE id IN (…) for a set of ids; returns rows affected. */
$delete_in = function ($table, $column, array $ids) use ($wpdb) {
    if (!$ids) {
        return 0;
    }
    return (int) $wpdb->query("DELETE FROM {$table} WHERE {$column} IN (" . implode(',', array_map('intval', $ids)) . ")");
};

// 1) Leaf rows first (batched for speed).
foreach (['reviews' => 'tap_reviews', 'leads' => 'tap_leads', 'consents' => 'tap_consents'] as $key => $table) {
    $delete_in($wpdb->prefix . $table, 'id', $report[$key]);
}
$delete_in($wpdb->prefix . 'tap_booking_items', 'booking_id', $report['bookings']);
$delete_in($wpdb->prefix . 'tap_bookings', 'id', $report['bookings']);
$delete_in($wpdb->prefix . 'tap_commission_payments', 'id', $report['commission_payments']);
$delete_in($wpdb->prefix . 'tap_daily_pricing', 'id', $report['pricing_rows']);
$delete_in($wpdb->prefix . 'tap_agencies', 'id', $report['registry']);

// Posts: listings (incl. rooms) then agencies.
$listing_deleted = $test_listings ? (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$lid_in})") : 0;
if ($test_listings) {
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$lid_in})");
    $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$lid_in})");
}
$agency_deleted = $test_agencies ? (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$aid_in})") : 0;
if ($test_agencies) {
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$aid_in})");
    $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$aid_in})");
}

// Users last: drop usermeta then the rows. Their fixtures are already gone, so
// no content reassignment is needed (far faster than wp_delete_user per row).
$users_deleted = 0;
if ($test_users) {
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id IN ({$uid_in})");
    $users_deleted = (int) $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID IN ({$uid_in})");
}

// Ledger rows that grouped only cleaned bookings (IN() can't match "a,b").
foreach ($wpdb->get_results("SELECT id, booking_ids FROM {$wpdb->prefix}tap_commission_payments") as $row) {
    $parts = array_filter(array_map('intval', explode(',', (string) $row->booking_ids)));
    if ($parts && !array_diff($parts, $report['bookings'])) {
        $wpdb->delete($wpdb->prefix . 'tap_commission_payments', ['id' => (int) $row->id]);
    }
}

WP_CLI::success(sprintf(
    'Purged: %d users, %d agencies, %d listings, %d reviews, %d bookings, %d leads, %d consents, %d registry rows.',
    $users_deleted,
    $agency_deleted,
    $listing_deleted,
    count($report['reviews']),
    count($report['bookings']),
    count($report['leads']),
    count($report['consents']),
    count($report['registry'])
));
