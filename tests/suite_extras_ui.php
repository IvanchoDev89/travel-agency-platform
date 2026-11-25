<?php
/**
 * suite_extras_ui.php — agency-managed add-ons, featured picker and mobile nav.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$orig_uid = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin) { wp_set_current_user((int) ($admin[0] ?? 0)); }
$sfx = tap_t_suffix();

$agency_id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Extras E2E ' . $sfx]);
update_post_meta($agency_id, '_tap_agency_user_id', (int) ($admin[0] ?? 0));
$service_id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Tour Extras ' . $sfx]);
update_post_meta($service_id, '_tap_tour_agency_id', (int) $agency_id);

/* --- extras CRUD -------------------------------------------------------------- */
tap_t_assert(true === TAP_Service_Extras::owns('tap_tour', $service_id), 'agency owns its tour');
$extra_id = TAP_Service_Extras::create([
    'service_type' => 'tap_tour', 'service_id' => (int) $service_id,
    'name' => 'Almuerzo ' . $sfx, 'price' => 12.5, 'is_per_person' => 1,
]);
tap_t_assert($extra_id > 0, 'extra created by agency');
$extra = TAP_Service_Extras::get($extra_id);
tap_t_assert('Almuerzo ' . $sfx === $extra->name && 1 === (int) $extra->is_per_person, 'extra fields persisted');
tap_t_assert(abs(25.0 - TAP_Service_Extras::total_for([$extra_id], 2, 0, 'tap_tour', $service_id)) < 0.01, 'per-person extra totals by guests');
TAP_Service_Extras::update($extra_id, ['name' => 'Cena ' . $sfx, 'price' => 20]);
tap_t_assert('Cena ' . $sfx === TAP_Service_Extras::get($extra_id)->name, 'extra updated');
$count_before = count(TAP_Service_Extras::for_service('tap_tour', $service_id));
TAP_Service_Extras::delete($extra_id);
tap_t_assert(count(TAP_Service_Extras::for_service('tap_tour', $service_id)) === $count_before - 1, 'extra deleted');

/* --- not owner ---------------------------------------------------------------- */
$other = get_users(['role' => 'subscriber', 'number' => 1, 'fields' => 'ids']);
if ($other) {
    wp_set_current_user((int) $other[0]);
    tap_t_assert(false === TAP_Service_Extras::owns('tap_tour', $service_id), 'unrelated user cannot manage the extras');
    wp_set_current_user((int) ($admin[0] ?? 0));
}

/* --- wiring ------------------------------------------------------------------- */
foreach (['save', 'delete'] as $action) {
    tap_t_assert(false !== has_action('admin_post_tap_extra_' . $action), 'admin_post_tap_extra_' . $action . ' is registered');
}
$wizard = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-tour-wizard-steps.php');
tap_t_assert(false !== strpos($wizard, 'name="action" value="tap_extra_save"'), 'tour wizard has an add-extra form');
tap_t_assert(false !== strpos($wizard, 'name="action" value="tap_extra_delete"'), 'tour wizard has a delete-extra form');
tap_t_assert(false !== strpos($wizard, 'tap_extra_0'), 'add form is nonce protected');

$dash = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-front-dash.php');
tap_t_assert(false === strpos($dash, 'count($rows) >= 15'), 'featured picker no longer stops at 15 listings');
tap_t_assert(false !== strpos($dash, "'fpaged'"), 'featured picker is paginated');

$css = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/assets/css/dashboard.css');
tap_t_assert(false === strpos($css, 'clip: rect(0, 0, 0, 0);'), 'mobile nav no longer hides labels');
tap_t_assert(false !== strpos($css, 'font-size: 10px;'), 'mobile nav uses compact labels');

/* --- cleanup ----------------------------------------------------------------- */
$wpdb->delete($wpdb->prefix . 'tap_service_extras', ['service_id' => $service_id]);
$wpdb->delete($wpdb->prefix . 'tap_agencies', ['name' => 'Extras E2E ' . $sfx]);
wp_delete_post($service_id, true);
wp_delete_post($agency_id, true);
tap_t_cleanup_test_consents();
wp_set_current_user($orig_uid);
tap_t_finish();
