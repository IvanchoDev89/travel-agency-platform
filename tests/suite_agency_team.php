<?php
/**
 * suite_agency_team.php — mapeo empleado ↔ agencia (sección Equipo).
 *
 * Covers:
 *  - TAP_Ajax_Dash::team_add: alta de un usuario existente por email (rol
 *    tap_agency_employee + user meta _tap_agency_id), idempotencia, email
 *    inexistente, administradores de plataforma y usuarios ya vinculados a
 *    otra agencia.
 *  - TAP_Ajax_Dash::team_remove: limpia meta y rol; el dueño de la agencia
 *    no puede ser removido como empleado.
 *  - TAP_Booking::get_agency_for_user resuelve empleados por user meta.
 *  - TAP_Booking::get_staff_for_agency lista el equipo de la agencia.
 *  - Empleado vinculado: opera reservas de SU agencia, no de otras.
 *  - Empleado sin vincular: agency_booking_action lo niega (no_agency).
 */
require __DIR__ . '/bootstrap.php';

global $team_users, $team_agencies;

$team_users    = [];
$team_agencies = [];

/** Throwaway user with the given role; cleaned up at the end. */
function team_make_user($role = 'tap_client') {
    global $team_users;
    $suffix = wp_generate_password(5, false);
    $uname  = 'tm_' . $suffix;
    $uid    = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => $role,
        'display_name' => 'Team ' . $role,
    ]);
    if (is_wp_error($uid) || !$uid) {
        return 0;
    }
    $team_users[] = (int) $uid;
    return (int) $uid;
}

// Agency #1 + owner.
$owner_uid = team_make_user('tap_agency_admin');
$aid       = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Team Agency A ' . wp_generate_password(4, false)]);
tap_t_assert($owner_uid && $aid && !is_wp_error($aid), 'created agency #1 + owner fixtures');
update_post_meta($aid, '_tap_agency_user_id', $owner_uid);

// Agency #2 + owner (cross-agency cases).
$owner2_uid = team_make_user('tap_agency_admin');
$aid2       = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'Team Agency B ' . wp_generate_password(4, false)]);
tap_t_assert($owner2_uid && $aid2 && !is_wp_error($aid2), 'created agency #2 + owner fixtures');
update_post_meta($aid2, '_tap_agency_user_id', $owner2_uid);
$team_agencies = [(int) $aid, (int) $aid2];

/* ── 0) recovery: agency-role user whose agency lost the owner link ─── */
$orphan_uid = team_make_user('tap_agency_admin');
$orphan_aid = wp_insert_post([
    'post_type'   => 'tap_agency',
    'post_status' => 'draft',
    'post_title'  => 'Team Orphan Agency ' . wp_generate_password(4, false),
    'post_author' => $orphan_uid,
]);
tap_t_assert($orphan_aid && !is_wp_error($orphan_aid), 'created an agency-role user with an unlinked agency');
tap_t_assert((int) TAP_Booking::get_agency_for_user($orphan_uid) === (int) $orphan_aid, 'get_agency_for_user recovers an agency by authorship');
tap_t_assert((int) get_post_meta($orphan_aid, '_tap_agency_user_id', true) === (int) $orphan_uid, 'recovery heals the missing owner link');
$team_agencies[] = (int) $orphan_aid;

/* ── 1) team_add links an existing user by email ──────────────────── */
$staff_uid   = team_make_user('tap_client');
$staff_email = get_userdata($staff_uid)->user_email;
$res = TAP_Ajax_Dash::team_add($aid, $staff_email);
tap_t_assert(true === $res, 'team_add succeeds for an existing user by email');
tap_t_assert(user_can($staff_uid, 'tap_agency_employee'), 'staff user gains the tap_agency_employee role');
tap_t_assert((int) TAP_Booking::get_agency_for_user($staff_uid) === (int) $aid, 'get_agency_for_user resolves the staff user to the agency');
$team_ids = array_map(function ($u) { return (int) $u->ID; }, TAP_Booking::get_staff_for_agency($aid));
tap_t_assert(in_array($staff_uid, $team_ids, true), 'get_staff_for_agency lists the staff member');

/* ── 2) idempotent re-add ─────────────────────────────────────────── */
tap_t_assert(true === TAP_Ajax_Dash::team_add($aid, $staff_email), 'team_add is idempotent for an already-linked member');

/* ── 3) unknown email rejected ────────────────────────────────────── */
$bad = TAP_Ajax_Dash::team_add($aid, 'nobody_' . wp_generate_password(5, false) . '@example.test');
tap_t_assert(is_wp_error($bad), 'team_add rejects an unknown email');

/* ── 4) platform administrators refused ───────────────────────────── */
$platform_admin = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
$bad2 = TAP_Ajax_Dash::team_add($aid, get_userdata($platform_admin[0] ?? 0)->user_email ?? '');
tap_t_assert(is_wp_error($bad2) && 'is_admin' === $bad2->get_error_code(), 'team_add refuses platform administrators');

/* ── 5) user already linked to another agency refused ─────────────── */
$other_staff = team_make_user('tap_client');
update_user_meta($other_staff, '_tap_agency_id', (int) $aid2);
$bad3 = TAP_Ajax_Dash::team_add($aid, get_userdata($other_staff)->user_email);
tap_t_assert(is_wp_error($bad3) && 'other_agency' === $bad3->get_error_code(), 'team_add refuses users already linked to another agency');

/* ── 6) mapped staff operate their own agency bookings only ───────── */
$booking  = tap_t_seed_booking(['agency_id' => (int) $aid, 'status' => 'pending']);
$booking2 = tap_t_seed_booking(['agency_id' => (int) $aid2, 'status' => 'pending']);
wp_set_current_user($staff_uid);
$r = TAP_Booking::agency_booking_action($booking, 'confirm');
tap_t_assert(true === $r, 'mapped staff can confirm bookings of their agency');
$r2 = TAP_Booking::agency_booking_action($booking2, 'confirm');
tap_t_assert(is_wp_error($r2) && 'forbidden' === $r2->get_error_code(), 'staff cannot operate another agency bookings');

/* ── 7) unmapped employee denied ──────────────────────────────────── */
$lonely = team_make_user('tap_agency_employee');
wp_set_current_user($lonely);
$r3 = TAP_Booking::agency_booking_action($booking2, 'confirm');
tap_t_assert(is_wp_error($r3) && 'no_agency' === $r3->get_error_code(), 'an unmapped employee is denied booking operations');

/* ── 8) team_remove clears meta + role; owner is not removable ────── */
wp_set_current_user($owner_uid);
$res = TAP_Ajax_Dash::team_remove($aid, $staff_uid);
tap_t_assert(true === $res, 'team_remove succeeds for a staff member');
tap_t_assert((int) TAP_Booking::get_agency_for_user($staff_uid) === 0, 'staff user no longer resolves to the agency');
tap_t_assert(!user_can($staff_uid, 'tap_agency_employee'), 'staff role removed after team_remove');
$owner_rm = TAP_Ajax_Dash::team_remove($aid, $owner_uid);
tap_t_assert(is_wp_error($owner_rm), 'the agency owner cannot be removed as staff');

/* ── cleanup ──────────────────────────────────────────────────────── */
wp_set_current_user(0);
tap_t_cleanup_bookings([$booking, $booking2]);
foreach ($team_agencies as $x) {
    wp_delete_post($x, true);
}
foreach ($team_users as $uid) {
    wp_delete_user($uid);
}
tap_t_finish();
