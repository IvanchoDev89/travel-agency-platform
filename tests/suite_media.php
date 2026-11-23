<?php
/**
 * suite_media.php — agency media library, alt text and delete guards.
 * FUNCTIONAL: real helpers with real attachments (the AJAX layer only adds
 * nonces and wp_send_json, so testing it in-process would die the suite).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_media.php
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$sfx = tap_t_suffix();
$orig_uid = get_current_user_id();
$cleanup_users = [];
$cleanup_att = [];
$cleanup_files = [];

$uid = wp_insert_user([
    'user_login' => 'tap_md_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_md_' . $sfx . '@example.test', 'role' => 'tap_agency_admin',
]);
$foreign = wp_insert_user([
    'user_login' => 'tap_mf_' . $sfx, 'user_pass' => tap_t_unique_pass(),
    'user_email' => 'tap_mf_' . $sfx . '@example.test', 'role' => 'subscriber',
]);
if (!is_wp_error($uid) && !is_wp_error($foreign)) {
    $cleanup_users[] = (int) $uid;
    $cleanup_users[] = (int) $foreign;

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
    $up = wp_upload_bits('tap-e2e-' . $sfx . '.png', null, $png);
    if (empty($up['error']) && $up['file']) {
        $cleanup_files[] = $up['file'];
        $att = wp_insert_attachment([
            'post_title' => 'TAP media ' . $sfx, 'post_mime_type' => 'image/png',
            'post_status' => 'inherit', 'post_author' => (int) $uid, 'guid' => $up['url'],
        ], $up['file']);
        $cleanup_att[] = (int) $att;
        tap_t_assert((int) $att > 0, 'fixture attachment created');

        wp_set_current_user((int) $uid);
        $library = TAP_Agency_Media::list_agency_media();
        $ids = array_column($library, 'id');
        tap_t_assert(in_array((int) $att, $ids, true), 'media library lists the agency image');
        tap_t_assert(true === TAP_Agency_Media::owns_attachment($att), 'owner can manage the attachment');

        TAP_Agency_Media::update_attachment_meta($att, ['alt' => 'Playa de prueba']);
        tap_t_assert('Playa de prueba' === get_post_meta($att, '_wp_attachment_image_alt', true), 'alt text is stored');

        wp_set_current_user((int) $foreign);
        tap_t_assert(false === TAP_Agency_Media::owns_attachment($att), 'foreign user cannot manage the attachment');
        tap_t_assert('Playa de prueba' === get_post_meta($att, '_wp_attachment_image_alt', true), 'foreign user leaves alt unchanged');

        wp_set_current_user((int) $uid);
        tap_t_assert((bool) TAP_Agency_Media::delete_owned_attachment($att), 'owner can delete the attachment');
        tap_t_assert(!get_post($att), 'attachment row is gone');
        $cleanup_att = [];
    }
}

/* --- shared validator + experience wiring ------------------------------------ */
$bad = TAP_Metaboxes::validate_listing_data('tap_tour', ['_tap_tour_price_adult' => -5]);
tap_t_assert(!empty($bad['errors']) && in_array('_tap_tour_price_adult', $bad['invalid'], true), 'shared validator rejects a negative price');
$coords = TAP_Metaboxes::validate_listing_data('tap_tour', ['_tap_tour_lat' => 120]);
tap_t_assert(!empty($coords['errors']), 'shared validator rejects out-of-range latitude');
$good = TAP_Metaboxes::validate_listing_data('tap_tour', ['_tap_tour_price_adult' => 25, '_tap_tour_lat' => 9.9]);
tap_t_assert(empty($good['errors']), 'shared validator accepts valid values');
tap_t_assert(false !== has_filter('manage_tap_experience_posts_columns', ['TAP_Dashboard_Content', 'add_agency_column']), 'tap_experience has the agency column');
$tax_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-taxonomies.php');
tap_t_assert(false !== strpos($tax_src, "'tap_tour', 'tap_experience'"), 'tap_experience is registered in the taxonomies');

/* --- JS/PHP wiring guards ----------------------------------------------------- */
$js = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/assets/js/agency-wizard.js');
tap_t_assert(false !== strpos($js, 'tap_agency_media_library'), 'uploader loads the media library');
tap_t_assert(false !== strpos($js, 'tap_agency_media_update'), 'uploader saves alt text');
tap_t_assert(false !== strpos($js, 'dragstart'), 'uploader supports drag reorder');
$php = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-acc-wizard-room-form.php');
tap_t_assert(false !== strpos($php, 'tap-uploader-library-toggle'), 'uploader renders the library button');

/* --- cleanup ----------------------------------------------------------------- */
wp_set_current_user($orig_uid);
foreach ($cleanup_att as $aid) { wp_delete_attachment($aid, true); }
foreach ($cleanup_files as $file) { @unlink($file); }
foreach ($cleanup_users as $user_id) { wp_delete_user($user_id); }
tap_t_finish();
