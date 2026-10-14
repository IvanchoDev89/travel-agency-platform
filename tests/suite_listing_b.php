<?php
/**
 * suite_listing_b.php — Tanda B: media reuse, save caps/type guards,
 * draft rooms, permanent room delete and the room-form markup.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_listing_b.php
 */
require __DIR__ . '/bootstrap.php';

add_filter('tap_listing_limit', function () { return -1; });

global $wpdb;

$sfx = wp_generate_password(5, false);
// eval-file runs the suite inside a function scope, so the shared registries
// must live in $GLOBALS to be visible both in helpers and at cleanup time.
$GLOBALS['sb_cleanup_posts'] = [];
$GLOBALS['sb_cleanup_users'] = [];

/**
 * Throwaway agency + agency-admin user. Returns [user_id, agency_id].
 */
function sb_make_agency($suffix, $tag) {
    $uname = 'tap_sb_' . $tag . '_' . strtolower($suffix);
    $uid   = wp_insert_user([
        'user_login'   => $uname,
        'user_pass'    => wp_generate_password(12, false),
        'user_email'   => $uname . '@example.test',
        'role'         => 'tap_agency_admin',
        'display_name' => 'TAP Suite B',
    ]);
    if (is_wp_error($uid) || !$uid) {
        return [0, 0];
    }
    $aid = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => 'TAP Suite B Agency ' . $tag]);
    if (!$aid || is_wp_error($aid)) {
        wp_delete_user($uid);
        return [0, 0];
    }
    update_post_meta($aid, '_tap_agency_user_id', (int) $uid);
    $GLOBALS['sb_cleanup_users'][] = (int) $uid;
    $GLOBALS['sb_cleanup_posts'][] = (int) $aid;
    return [(int) $uid, (int) $aid];
}

/** Minimal image attachment authored by a user. */
function sb_attach($author) {
    $id = wp_insert_post([
        'post_type'      => 'attachment',
        'post_status'     => 'inherit',
        'post_mime_type'  => 'image/jpeg',
        'post_title'      => 'Suite B att',
        'post_author'     => (int) $author,
    ]);
    $GLOBALS['sb_cleanup_posts'][] = (int) $id;
    return (int) $id;
}

list($ua, $aa) = sb_make_agency($sfx, 'a');
list($ub, $ab) = sb_make_agency($sfx, 'b');
tap_t_assert($ua > 0 && $ub > 0 && $aa !== $ab, 'two distinct throwaway agencies exist');

// Employee of agency A and two plain clients (one linked to agency A).
$ue = wp_insert_user([
    'user_login' => 'tap_sb_emp_' . strtolower($sfx),
    'user_pass'  => wp_generate_password(12, false),
    'user_email' => 'tap_sb_emp_' . strtolower($sfx) . '@example.test',
    'role'       => 'tap_agency_employee',
]);
$GLOBALS['sb_cleanup_users'][] = (int) $ue;
update_user_meta((int) $ue, '_tap_agency_id', (int) $aa);

$uc = wp_insert_user([
    'user_login' => 'tap_sb_client_' . strtolower($sfx),
    'user_pass'  => wp_generate_password(12, false),
    'user_email' => 'tap_sb_client_' . strtolower($sfx) . '@example.test',
    'role'       => 'tap_client',
]);
$GLOBALS['sb_cleanup_users'][] = (int) $uc;

$ul = wp_insert_user([
    'user_login' => 'tap_sb_linked_' . strtolower($sfx),
    'user_pass'  => wp_generate_password(12, false),
    'user_email' => 'tap_sb_linked_' . strtolower($sfx) . '@example.test',
    'role'       => 'tap_client',
]);
$GLOBALS['sb_cleanup_users'][] = (int) $ul;
update_user_meta((int) $ul, '_tap_agency_id', (int) $aa);

/* ══ 1) B1: usable_attachment_ids keeps safe media, blocks rivals ──── */
$att_own = sb_attach($ua);
$att_emp = sb_attach($ue);
$att_b1  = sb_attach($ub);
$att_b2  = sb_attach($ub);

wp_set_current_user($ua);
$r = TAP_Agency_Media::usable_attachment_ids([$att_own], 0, 0);
tap_t_assert(isset($r[$att_own]), 'own image is usable');
$r = TAP_Agency_Media::usable_attachment_ids([$att_emp], 0, 0);
tap_t_assert(isset($r[$att_emp]), "teammate's image from the same agency is usable");
$r = TAP_Agency_Media::usable_attachment_ids([$att_b1], 0, 0);
tap_t_assert(!isset($r[$att_b1]), "rival agency's image is blocked without context");

// A listing that already references the rival's image must keep it on save.
$tour_res = TAP_Ajax_Listing::save_listing_data($ua, ['title' => "SB tour {$sfx}", 'description' => 'x', 'listing_type' => 'tap_tour']);
$tour_id  = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $tour_res && $tour_id > 0, 'agency A listing created');
$GLOBALS['sb_cleanup_posts'][] = $tour_id;
// Synthetic attachments have no attached file, so set_post_thumbnail()
// would refuse them; write the thumbnail meta directly to simulate the
// legacy state where the listing already references the image.
update_post_meta($tour_id, '_thumbnail_id', $att_b1);
$r = TAP_Agency_Media::usable_attachment_ids([$att_b1], 0, $tour_id);
tap_t_assert(isset($r[$att_b1]), 'image already referenced by the listing is never dropped');
$r = TAP_Agency_Media::usable_attachment_ids([$att_b2], 0, $tour_id);
tap_t_assert(!isset($r[$att_b2]), 'unreferenced rival image stays blocked even with context');

wp_set_current_user($ub);
$r = TAP_Agency_Media::usable_attachment_ids([$att_own], 0, 0);
tap_t_assert(!isset($r[$att_own]), 'agency B cannot use agency A media');

/* ══ 2) B10: create/edit caps and post-type validation ─────────────── */
wp_set_current_user($uc);
$res = TAP_Ajax_Listing::save_listing_data($uc, ['title' => "SB client {$sfx}", 'description' => 'x', 'listing_type' => 'tap_tour']);
tap_t_assert(true !== $res && false !== strpos((string) $res, 'No tienes permiso para crear listados.'), 'a client without caps cannot create a listing');

$acc_res = TAP_Ajax_Listing::save_listing_data($ua, [
    'title' => "SB acc {$sfx}", 'description' => 'x', 'listing_type' => 'tap_accommodation',
    'type' => 'hotel', 'price_per_night' => '70.00',
]);
$acc_id = isset($GLOBALS['_tap_saved_listing']) ? (int) $GLOBALS['_tap_saved_listing'] : 0;
tap_t_assert(true === $acc_res && $acc_id > 0, 'agency A accommodation created');
$GLOBALS['sb_cleanup_posts'][] = $acc_id;

$res = TAP_Ajax_Listing::save_listing_data($ua, ['listing_id' => $acc_id, 'listing_type' => 'tap_tour', 'title' => 'type confusion', 'description' => 'x']);
tap_t_assert(true !== $res && false !== strpos((string) $res, 'Solo puedes gestionar'), 'editing with a mismatched listing_type is rejected');

wp_set_current_user($ul);
$res = TAP_Ajax_Listing::save_listing_data($ul, ['listing_id' => $tour_id, 'listing_type' => 'tap_tour', 'title' => 'linked hijack', 'description' => 'x']);
tap_t_assert(true !== $res && false !== strpos((string) $res, 'No tienes permiso para editar listados.'), 'a linked client without the CPT edit cap cannot edit the listing');

/* ══ 3) B11: rooms follow the accommodation visibility ─────────────── */
tap_t_assert('draft' === get_post_status($acc_id), 'accommodation starts as draft');
$room1 = TAP_Ajax_Listing::save_room_data($acc_id, 0, ['title' => "SB draft room {$sfx}", 'price_per_night' => 55]);
tap_t_assert(is_int($room1) && $room1 > 0, 'room created on the draft accommodation');
$GLOBALS['sb_cleanup_posts'][] = $room1;
tap_t_assert('draft' === get_post_status($room1), 'room of a draft accommodation is born draft');

wp_update_post(['ID' => $acc_id, 'post_status' => 'publish']);
$room2 = TAP_Ajax_Listing::save_room_data($acc_id, 0, ['title' => "SB live room {$sfx}", 'price_per_night' => 65]);
tap_t_assert(is_int($room2) && $room2 > 0, 'room created on the published accommodation');
$GLOBALS['sb_cleanup_posts'][] = $room2;
tap_t_assert('publish' === get_post_status($room2), 'room of a published accommodation is published');

/* ══ 4) B12: deleting a room removes the post and its price meta ───── */
$GLOBALS['tap_sb_die_hit'] = false;
$sb_die_handler = function () {
    $GLOBALS['tap_sb_die_hit'] = true;
    throw new RuntimeException('tap_sb_die_captured');
};
$sb_die_filters = ['wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_rest_handler', 'wp_die_handler'];
foreach ($sb_die_filters as $filter) {
    add_filter($filter, $sb_die_handler);
}
add_filter('wp_doing_ajax', '__return_true');
wp_set_current_user($ua);
$_POST = [
    'action' => 'tap_agency_delete_room',
    'room_id' => $room2,
    'nonce' => wp_create_nonce('tap_agency_listing_nonce'),
];
$_REQUEST = $_POST;
ob_start();
try {
    TAP_Ajax_Listing::agency_delete_room();
    $died = false;
} catch (RuntimeException $e) {
    $died = 'tap_sb_die_captured' === $e->getMessage();
}
$sb_json = json_decode(trim(ob_get_clean()), true);
foreach ($sb_die_filters as $filter) {
    remove_filter($filter, $sb_die_handler);
}
remove_filter('wp_doing_ajax', '__return_true');
unset($_POST['action'], $_REQUEST['action']);

tap_t_assert($died && !empty($sb_json['success']), 'room delete endpoint reports success');
tap_t_assert(!get_post($room2), 'deleted room is permanently gone (no trash)');
tap_t_assert('' === (string) get_post_meta($room2, '_tap_room_price_per_night', true), 'deleted room leaves no price meta behind');

/* ══ 5) B13: room form markup carries its attributes on the tag ────── */
$html = TAP_Acc_Wizard_Room_Form::render_room_form(null, $acc_id, false);
$doc  = new DOMDocument();
libxml_use_internal_errors(true);
@$doc->loadHTML('<?xml encoding="utf-8" ?><div id="sb-wrap">' . $html . '</div>');
libxml_clear_errors();
$forms = $doc->getElementsByTagName('form');
tap_t_assert(1 === $forms->length, 'new room form renders exactly one form element');
$form = $forms->item(0);
tap_t_assert($form && $form->hasAttribute('hidden'), 'closed new room form carries the hidden attribute on the tag');
tap_t_assert($form && '1' === $form->getAttribute('data-new'), 'new room form carries data-new on the tag');
tap_t_assert(false === strpos($doc->textContent, 'data-inactive'), 'no stray data-inactive text leaked outside the tag');
tap_t_assert(false === strpos($doc->textContent, 'hidden>'), 'no stray hidden> text leaked outside the tag');

$active_meta = get_post_meta($room1, '_tap_room_is_active', true);
update_post_meta($room1, '_tap_room_is_active', '0');
$html = TAP_Acc_Wizard_Room_Form::render_room_form(get_post($room1), $acc_id, false);
$doc  = new DOMDocument();
@$doc->loadHTML('<?xml encoding="utf-8" ?><div id="sb-wrap">' . $html . '</div>');
libxml_clear_errors();
$forms = $doc->getElementsByTagName('form');
tap_t_assert(1 === $forms->length, 'existing room form renders exactly one form element');
$form = $forms->item(0);
tap_t_assert($form && '1' === $form->getAttribute('data-inactive'), 'inactive room form carries data-inactive on the tag');
tap_t_assert($form && !$form->hasAttribute('data-new'), 'existing room form has no data-new');
tap_t_assert(false === strpos($doc->textContent, 'data-inactive'), 'inactive room form leaks no attribute text');
if (null === $active_meta || '' === $active_meta) {
    delete_post_meta($room1, '_tap_room_is_active');
} else {
    update_post_meta($room1, '_tap_room_is_active', $active_meta);
}

/* ── Cleanup ────────────────────────────────────────────────────────── */
wp_set_current_user(0);
foreach (array_reverse($GLOBALS['sb_cleanup_posts']) as $pid) {
    if ($pid && get_post($pid)) {
        wp_delete_post($pid, true);
    }
}
foreach ($GLOBALS['sb_cleanup_users'] as $uid) {
    if ($uid && get_user_by('id', $uid)) {
        wp_delete_user($uid);
    }
}
$GLOBALS['sb_cleanup_posts'] = [];
$GLOBALS['sb_cleanup_users'] = [];

tap_t_finish();
