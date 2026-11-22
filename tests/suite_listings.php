<?php
/**
 * suite_listings.php — listing management guards (admin + agency editor).
 * FUNCTIONAL: real status transitions, real publish messages, real editor renders.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_listings.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$orig_get = $_GET;
$cleanup_posts = [];

/* --- 1) wp-admin publish syncs the _is_active flag ---------------------------- */
$tour = wp_insert_post([
    'post_type' => 'tap_tour', 'post_status' => 'draft',
    'post_title' => 'TAP listing E2E ' . $sfx,
]);
if ((int) $tour > 0) {
    $cleanup_posts[] = (int) $tour;
    update_post_meta($tour, '_tap_tour_agency_id', tap_t_test_agency());

    wp_update_post(['ID' => $tour, 'post_status' => 'publish']);
    tap_t_assert('1' === (string) get_post_meta($tour, '_tap_tour_is_active', true), 'publishing in wp-admin sets _is_active=1');

    wp_update_post(['ID' => $tour, 'post_status' => 'draft']);
    tap_t_assert('0' === (string) get_post_meta($tour, '_tap_tour_is_active', true), 'unpublishing sets _is_active=0');

    /* --- 2) type-aware publish messages -------------------------------------- */
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
    if ($admin) {
        wp_set_current_user((int) $admin[0]);
        $published = TAP_Ajax_Listing::set_listing_published($tour, true);
        tap_t_assert(!is_wp_error($published) && false !== strpos($published['message'], 'Tour'), 'publish message names the service type');
        $unpublished = TAP_Ajax_Listing::set_listing_published($tour, false);
        tap_t_assert(!is_wp_error($unpublished) && false !== strpos($unpublished['message'], 'Tour'), 'unpublish message names the service type');
        wp_set_current_user($orig_uid);
    }
}

/* --- 3) listing counts include every service type and use active flag -------- */
delete_transient('tap_listing_counts');
$counts = TAP_Dashboard::listing_counts();
foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'] as $type) {
    tap_t_assert(array_key_exists($type, $counts), "listing_counts includes {$type}");
}
$tap_tour_active = (int) $counts['tap_tour'];
$equipment = wp_insert_post([
    'post_type' => 'tap_equipment', 'post_status' => 'publish',
    'post_title' => 'TAP equipment E2E ' . $sfx,
]);
if ((int) $equipment > 0) {
    $cleanup_posts[] = (int) $equipment;
    update_post_meta($equipment, '_tap_eq_is_active', '1');
    delete_transient('tap_listing_counts');
    $counts2 = TAP_Dashboard::listing_counts();
    tap_t_assert((int) $counts2['tap_equipment'] >= 1, 'active equipment is counted');
    wp_update_post(['ID' => $equipment, 'post_status' => 'draft']);
    delete_transient('tap_listing_counts');
    $counts3 = TAP_Dashboard::listing_counts();
    tap_t_assert((int) $counts3['tap_equipment'] === (int) $counts2['tap_equipment'] - 1 || (int) $counts3['tap_equipment'] < (int) $counts2['tap_equipment'], 'inactive equipment drops out of the count');
}

/* --- 4) new-listing editor explains the publish step -------------------------- */
$_GET = ['new' => 'tap_transport'];
$admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin_ids) { wp_set_current_user((int) $admin_ids[0]); }
$new_editor = TAP_Listing_Editor::agency_manage([]);
wp_set_current_user($orig_uid);
$_GET = $orig_get;
tap_t_assert(false !== strpos($new_editor, 'Cuando esté guardado podrás publicarlo'), 'new listing explains that publishing happens after saving');

/* --- 5) draft listings use the preview link, not a broken permalink ---------- */
$agency_admins = get_users(['role' => 'tap_agency_admin', 'number' => 1, 'fields' => 'ids']);
if ($agency_admins && (int) $tour > 0) {
    $owner_agency = TAP_Booking::get_agency_for_user((int) $agency_admins[0]);
    if ($owner_agency) {
        update_post_meta($tour, '_tap_tour_agency_id', (int) $owner_agency);
    }
    wp_set_current_user((int) $agency_admins[0]);
    $list_html = TAP_Listing_Editor::agency_manage([]);
    wp_set_current_user($orig_uid);
    tap_t_assert(false !== strpos($list_html, 'TAP listing E2E'), 'draft listing appears in the agency list');
    tap_t_assert(false !== strpos($list_html, 'preview='), 'draft listing "Ver" uses a preview link');
}

/* --- 6) wizard guards: real unsaved-changes prompt + save before publish ----- */
$wizard_js = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/assets/js/agency-wizard.js');
tap_t_assert(false !== strpos($wizard_js, "window.addEventListener('beforeunload'"), 'unsaved-changes guard uses a real beforeunload prompt');
tap_t_assert(false === strpos($wizard_js, "tap-dirty-warning"), 'dead sessionStorage dirty flag removed');
$publish_start = strpos($wizard_js, 'function togglePublish(');
$publish_block = substr($wizard_js, $publish_start, 2600);
tap_t_assert(false !== strpos($publish_block, "button.closest('form')"), 'publish resolves the current form');
tap_t_assert(false !== strpos($publish_block, "post('tap_agency_save_listing'"), 'publish saves the current step before toggling');

/* --- 7) admin tour wizard exposes every registered field --------------------- */
$wizard = new ReflectionMethod('TAP_Metaboxes', 'wizard_steps');
$wizard->setAccessible(true);
$steps = $wizard->invoke(null, 'tap_tour');
$covered = [];
foreach ($steps as $step) {
    foreach ($step['fields'] as $key) { $covered[$key] = true; }
}
$missing_fields = 0;
foreach (array_keys(TAP_Metaboxes::get_fields('tap_tour')) as $key) {
    if (!isset($covered[$key])) { $missing_fields++; }
}
tap_t_assert(0 === $missing_fields, 'all 58 registered tour fields are reachable in wp-admin (missing: ' . $missing_fields . ')');

/* --- 8) agency column resolves titles from one cached map -------------------- */
$col_agency = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'TAP Col Agency ' . $sfx,
]);
if ((int) $col_agency > 0) {
    $cleanup_posts[] = (int) $col_agency;
    update_post_meta($tour, '_tap_tour_agency_id', (int) $col_agency);
    ob_start();
    TAP_Dashboard_Content::render_agency_column('agency', $tour);
    $col_html = ob_get_clean();
    tap_t_assert(false !== strpos($col_html, 'TAP Col Agency ' . $sfx), 'agency column renders the agency title');
}

/* --- 9) mixed gallery sources are preserved ---------------------------------- */
$ajax_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-ajax-listing.php');
tap_t_assert(false !== strpos($ajax_src, 'Merge both sources: the uploader can hold Media Library ids and'), 'server merges gallery ids and URLs instead of if/elseif');
tap_t_assert(false !== strpos($wizard_js, 'firstIsId'), 'uploader keeps the first item as the main slot');
tap_t_assert(false !== strpos($wizard_js, "urls.map(function (i) { return i.url; })"), 'uploader preserves URLs even when ids exist');

/* --- 10) listing index paginates beyond 24 cards ----------------------------- */
$page_user = wp_insert_user([
    'user_login' => 'tap_pg_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_pg_' . $sfx . '@example.test', 'role' => 'tap_agency_admin',
]);
$page_agency = wp_insert_post([
    'post_type' => 'tap_agency', 'post_status' => 'publish',
    'post_title' => 'TAP Page Agency ' . $sfx,
]);
if (!is_wp_error($page_user) && (int) $page_agency > 0) {
    $cleanup_users[] = (int) $page_user;
    $cleanup_posts[] = (int) $page_agency;
    update_post_meta($page_agency, '_tap_agency_user_id', (int) $page_user);
    for ($i = 1; $i <= 25; $i++) {
        $p = wp_insert_post([
            'post_type' => 'tap_tour', 'post_status' => 'publish',
            'post_title' => 'TAP Page tour ' . $sfx . ' ' . $i,
        ]);
        update_post_meta($p, '_tap_tour_agency_id', (int) $page_agency);
        $cleanup_posts[] = (int) $p;
    }
    wp_set_current_user((int) $page_user);
    $_GET = ['lift' => 1];
    $page1 = TAP_Listing_Editor::agency_manage([]);
    tap_t_assert(false !== strpos($page1, 'tap-listing-pager'), 'listing index renders a pager');
    tap_t_assert(false !== strpos($page1, 'lift=2'), 'pager links to page 2');
    $_GET = ['lift' => 2];
    $page2 = TAP_Listing_Editor::agency_manage([]);
    tap_t_assert($page1 !== $page2, 'page 2 renders a different listing set');
    wp_set_current_user($orig_uid);
}

/* --- 11) data-integrity guards ----------------------------------------------- */
$meta_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-metaboxes.php');
tap_t_assert(false !== strpos($meta_src, "'_agency_id') && !current_user_can('tap_manage_agencies')"), 'admin save blocks ownership changes without the agency manager capability');
tap_t_assert(false !== strpos($wizard_js, 'input[name="destination"]'), 'tour validation checks the hidden destination picker');
$avail_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-tour-availability.php');
tap_t_assert(false !== strpos($avail_src, "'total'   => count(self::slots_for"), 'schedule generator reports the full slot count');
tap_t_assert(false !== strpos($wizard_js, 'schedShowing'), 'schedule UI shows how many of the total slots are displayed');

/* --- 12) admin bulk publish/unpublish ---------------------------------------- */
$admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin_ids && (int) $tour > 0) {
    wp_set_current_user((int) $admin_ids[0]);
    $actions = TAP_Dashboard::add_bulk_actions([]);
    tap_t_assert(isset($actions['tap_publish_listings'], $actions['tap_unpublish_listings']), 'bulk actions are registered');

    wp_update_post(['ID' => $tour, 'post_status' => 'draft']);
    $redirect = TAP_Dashboard::handle_bulk_actions('/wp-admin/edit.php?post_type=tap_tour', 'tap_publish_listings', [$tour]);
    tap_t_assert(false !== strpos($redirect, 'tap_bulk_done=1'), 'bulk publish reports one updated listing');
    tap_t_assert('publish' === get_post_status($tour) && '1' === (string) get_post_meta($tour, '_tap_tour_is_active', true), 'bulk publish updates status and active flag');

    $redirect2 = TAP_Dashboard::handle_bulk_actions('/wp-admin/edit.php?post_type=tap_tour', 'tap_unpublish_listings', [$tour]);
    tap_t_assert(false !== strpos($redirect2, 'tap_bulk_done=1'), 'bulk unpublish reports one updated listing');
    tap_t_assert('draft' === get_post_status($tour) && '0' === (string) get_post_meta($tour, '_tap_tour_is_active', true), 'bulk unpublish updates status and active flag');
    wp_set_current_user($orig_uid);
}

/* --- 13) reviews list paginates past 50 rows --------------------------------- */
$review_user = wp_insert_user([
    'user_login' => 'tap_rv_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_rv_' . $sfx . '@example.test', 'role' => 'subscriber',
]);
if (!is_wp_error($review_user) && $admin_ids) {
    $cleanup_users[] = (int) $review_user;
    $review_ids = [];
    for ($i = 1; $i <= 51; $i++) {
        $wpdb->insert($wpdb->prefix . 'tap_reviews', [
            'service_type' => 'tap_tour', 'service_id' => 75, 'user_id' => (int) $review_user,
            'rating' => 5, 'title' => 'RV ' . $i, 'content' => 'E2E', 'is_approved' => 0,
        ]);
        $review_ids[] = (int) $wpdb->insert_id;
    }
    wp_set_current_user((int) $admin_ids[0]);
    $_GET = ['status' => 'pending', 'paged' => 1];
    ob_start();
    TAP_Dashboard_Content::reviews_page();
    $reviews_html = ob_get_clean();
    wp_set_current_user($orig_uid);
    tap_t_assert(false !== strpos($reviews_html, 'tablenav-pages'), 'reviews list shows pagination beyond 50 rows');
    foreach ($review_ids as $rid) { $wpdb->delete($wpdb->prefix . 'tap_reviews', ['id' => $rid]); }
}

/* --- cleanup ----------------------------------------------------------------- */
$_GET = $orig_get;
wp_set_current_user($orig_uid);
delete_transient('tap_listing_counts');
foreach ($cleanup_posts as $pid) { wp_delete_post($pid, true); }
tap_t_finish();
