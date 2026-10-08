<?php
/**
 * suite_roles.php — TAP_Roles: capability catalog per role, versioned setup
 * flag (idempotency) and subscriber → tap_client assignment on registration.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_roles.php
 */
require __DIR__ . '/bootstrap.php';

/* 1) versioned setup flag ---------------------------------------------------- */
$ver = (int) get_option('tap_roles_version', 0);
tap_t_assert($ver === TAP_Roles::SETUP_VERSION, 'tap_roles_version matches SETUP_VERSION (got ' . $ver . ')');

/* 2) roles exist with the expected capability split -------------------------- */
$agency_admin = get_role('tap_agency_admin');
$employee     = get_role('tap_agency_employee');
$client       = get_role('tap_client');
tap_t_assert($agency_admin !== null, 'tap_agency_admin role exists');
tap_t_assert($employee !== null, 'tap_agency_employee role exists');
tap_t_assert($client !== null, 'tap_client role exists');

tap_t_assert($agency_admin && !empty($agency_admin->capabilities['edit_tap_tour']), 'agency admin can edit tours');
tap_t_assert($agency_admin && !empty($agency_admin->capabilities['publish_tap_tour']), 'agency admin can publish tours');
tap_t_assert($agency_admin && !empty($agency_admin->capabilities['edit_tap_tours']), 'agency admin holds plural caps');
tap_t_assert($agency_admin && !empty($agency_admin->capabilities['upload_files']), 'agency admin can upload files');
tap_t_assert($agency_admin && empty($agency_admin->capabilities['manage_options']), 'agency admin is not an administrator');

tap_t_assert($employee && !empty($employee->capabilities['edit_tap_tour']), 'employee can edit tours');
tap_t_assert($employee && empty($employee->capabilities['publish_tap_tour']), 'employee cannot publish tours');
tap_t_assert($employee && empty($employee->capabilities['delete_posts']), 'employee cannot delete posts');
tap_t_assert($employee && !empty($employee->capabilities['upload_files']), 'employee can upload files');

tap_t_assert($client && isset($client->capabilities['read']), 'client can read');
tap_t_assert($client && count($client->capabilities) === 1, 'client role is minimal (read only)');

/* 3) administrator caps + misspelled plurals dropped ------------------------- */
$admin = get_role('administrator');
tap_t_assert($admin !== null, 'administrator role exists');
foreach (['tap_manage_bookings', 'tap_manage_agencies', 'tap_manage_commissions', 'tap_manage_reviews', 'tap_view_reports'] as $cap) {
    tap_t_assert($admin && !empty($admin->capabilities[$cap]), "administrator has {$cap}");
}
tap_t_assert($admin && !empty($admin->capabilities['edit_tap_agencies']), 'administrator has corrected edit_tap_agencies');
tap_t_assert($admin && empty($admin->capabilities['edit_tap_agencys']), 'misspelled edit_tap_agencys not granted');
tap_t_assert($admin && empty($admin->capabilities['edit_tap_tours2'] ?? null), 'no stray plural caps');

/* 4) setup() is idempotent on repeat runs ------------------------------------ */
$before = (int) get_option('tap_roles_version', 0);
TAP_Roles::setup();
$after = (int) get_option('tap_roles_version', 0);
tap_t_assert($before === $after, 'repeat setup() leaves version flag untouched');
tap_t_assert((bool) has_action('user_register', ['TAP_Roles', 'assign_client_role']), 'setup() registers user_register hook');

/* 5) subscriber → tap_client on registration --------------------------------- */
$uid = tap_t_make_subscriber();
tap_t_assert($uid > 0, 'throwaway subscriber created');
do_action('user_register', $uid);
$user = get_userdata($uid);
tap_t_assert($user && in_array('tap_client', (array) $user->roles, true), 'new subscriber converted to tap_client');

$admin_users = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admin_users) {
    TAP_Roles::assign_client_role((int) $admin_users[0]);
    $admin_user = get_userdata((int) $admin_users[0]);
    tap_t_assert($admin_user && in_array('administrator', (array) $admin_user->roles, true), 'assign_client_role leaves non-subscribers untouched');
} else {
    tap_t_assert(true, 'no administrator present to test assign_client_role passthrough');
}

/* cleanup -------------------------------------------------------------------- */
if ($uid) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($uid);
}

tap_t_finish();
