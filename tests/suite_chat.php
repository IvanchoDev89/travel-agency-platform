<?php
/**
 * suite_chat.php — Fase 3: booking-scoped guest <-> agency chat.
 * Covers TAP_Chat persistence, cross-side read flags, ownership guards
 * (owner / admin / agency / verified guest), and the voucher render.
 * Self-cleaning.
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

$c = $wpdb->prefix . 'tap_chat_messages';
$b = $wpdb->prefix . 'tap_bookings';

// --- schema -------------------------------------------------------------------------
$ct = $wpdb->get_var("SHOW TABLES LIKE '{$c}'");
tap_t_assert($ct === $c, 'tap_chat_messages table migrated');
$cols = $wpdb->get_col("DESCRIBE {$c}");
tap_t_assert(in_array('booking_id', $cols, true)
    && in_array('author_type', $cols, true)
    && in_array('author_name', $cols, true)
    && in_array('message', $cols, true)
    && in_array('is_read', $cols, true), 'chat schema columns migrated');
tap_t_assert(get_option('tap_version') === TAP_VERSION, 'tap_version option current');
tap_t_assert((bool) has_action('wp_ajax_tap_chat_client_thread', ['TAP_Ajax', 'chat_client_thread']), 'live chat: client thread endpoint registered');
tap_t_assert((bool) has_action('wp_ajax_nopriv_tap_chat_client_thread', ['TAP_Ajax', 'chat_client_thread']), 'live chat: guest thread endpoint registered');
tap_t_assert((bool) has_action('wp_ajax_tap_dash_chat_unread', ['TAP_Ajax_Dash', 'ajax_chat_unread']), 'live chat: agency unread endpoint registered');

// --- fixtures -----------------------------------------------------------------------
$u = tap_t_make_subscriber();
tap_t_assert($u > 0, 'created traveler');
list($fid, $ftype) = tap_t_find_service();
$bk  = tap_t_seed_booking(['client_id' => $u, 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'confirmed', 'payment_status' => 'paid']);
$gid = tap_t_seed_booking(['client_id' => 0, 'guest_email' => 'guest@example.com', 'guest_name' => 'Ana Viajera', 'service_type' => $ftype, 'service_id' => $fid, 'status' => 'pending', 'payment_status' => 'pending']);

// --- send() validation ----------------------------------------------------------------
$r = TAP_Chat::send($bk, 'client', $u, '', '   ');
tap_t_assert(is_wp_error($r) && 'empty_message' === $r->get_error_code(), 'blank message rejected');
$bad = TAP_Chat::send($bk, 'client', $u, 'X', '<script>alert(1)</script>Hola <b>mundo</b>');
tap_t_assert(is_int($bad) && $bad > 0, 'valid message accepted');

// --- persistence & ordering -----------------------------------------------------------
$m1 = TAP_Chat::send($bk, 'client', $u, 'Marcelo', '¿Podemos mover el check-in?');
$m2 = TAP_Chat::send($bk, 'agency', (int) tap_t_test_agency(), 'Agencia Test', 'Claro, mañana te confirmamos');
tap_t_assert(is_int($m1) && is_int($m2) && $m2 > $m1, 'client + agency messages stored');
$row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$c} WHERE id=%d", $m1));
tap_t_assert('client' === $row1->author_type && (int) $row1->author_id === $u && 'Marcelo' === $row1->author_name, 'client row persisted');
$row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$c} WHERE id=%d", $m2));
tap_t_assert('agency' === $row2->author_type && (int) $row2->author_id === (int) tap_t_test_agency(), 'agency row persisted');
tap_t_assert(0 === strpos($row2->message, 'Claro'), 'message stored plain (no markup)');
$msgs = TAP_Chat::messages($bk);
tap_t_assert(count($msgs) === 3 && (int) $msgs[0]->id === $bad && (int) $msgs[2]->id === $m2, 'messages ordered oldest first');

// --- default author name fallback ------------------------------------------------------
$m3 = TAP_Chat::send($bk, 'client', $u, ' ', 'Mensaje sin nombre');
tap_t_assert(is_int($m3) && $wpdb->get_var($wpdb->prepare("SELECT author_name FROM {$c} WHERE id=%d", $m3)) === 'Huésped', 'client default name Huésped');

// --- cross-side unread flags ------------------------------------------------------------
tap_t_assert((int) TAP_Chat::unread_for($bk, 'client') === 1, 'client sees the one agency reply as unread');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'agency') === 3, 'agency unread counts client messages');
TAP_Chat::mark_read($bk, 'agency');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'agency') === 0, 'agency read clears only client-authored');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'client') === 1, 'client unread counts agency reply');
TAP_Chat::mark_read($bk, 'client');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'client') === 0, 'client read clears agency-authored');

// --- global agency unread total (live nav badge) ----------------------------------------
$agency = (int) tap_t_test_agency();
$baseline = TAP_Chat::unread_total_for_agency($agency);
tap_t_assert(TAP_Chat::unread_total_for_agency(0) === 0, 'global unread total ignores invalid agency');
TAP_Chat::send($bk, 'client', $u, 'Marcelo', 'Mensaje A');
TAP_Chat::send($gid, 'client', 0, 'Ana Viajera', 'Mensaje B');
$total = TAP_Chat::unread_total_for_agency($agency);
tap_t_assert($total === $baseline + 2, 'global unread total counts new client messages across bookings');
TAP_Chat::mark_read($bk, 'agency');
TAP_Chat::mark_read($gid, 'agency');
tap_t_assert(TAP_Chat::unread_total_for_agency($agency) === $baseline, 'global unread total drops after reading the threads');
tap_t_assert(TAP_Chat::last_id($bk) > 0 && TAP_Chat::last_id($gid) > 0, 'last_id resolves per thread');

// --- batched unread (dashboard listing avoids the per-row N+1) ---------------------------
TAP_Chat::send($bk, 'client', $u, 'Marcelo', 'Batch 1');
TAP_Chat::send($bk, 'client', $u, 'Marcelo', 'Batch 2');
TAP_Chat::send($gid, 'client', 0, 'Ana Viajera', 'Batch 3');
$batch = TAP_Chat::unread_counts_for_bookings([$bk, $gid, 0, -4], 'agency');
tap_t_assert(($batch[$bk] ?? 0) === 2, 'batched unread counts client messages per booking');
tap_t_assert(($batch[$gid] ?? 0) === 1, 'batched unread covers every booking in the set');
tap_t_assert(!isset($batch[0]) && !isset($batch[-4]), 'batched unread ignores invalid booking ids');
tap_t_assert(TAP_Chat::unread_counts_for_bookings([], 'agency') === [], 'batched unread with no ids is empty');
tap_t_assert(($batch[$bk] ?? 0) === (int) TAP_Chat::unread_for($bk, 'agency'), 'batched count matches single-booking count');
TAP_Chat::mark_read($bk, 'agency');
TAP_Chat::mark_read($gid, 'agency');

// --- ownership guards --------------------------------------------------------------------
$wk = $wpdb->get_var($wpdb->prepare("SELECT guest_email FROM {$b} WHERE id=%d", $bk));
list($ok, $name) = TAP_Chat::can_client_chat((object) ['client_id' => $u, 'agency_id' => 0, 'guest_email' => (string) $wk], $u, '');
tap_t_assert($ok && $name !== '', 'owner can chat (has display name)');
list($ok, $name) = TAP_Chat::can_client_chat((object) ['client_id' => $u, 'agency_id' => 0, 'guest_email' => (string) $wk], $u + 999999, '');
tap_t_assert(!$ok, 'foreign user cannot chat');
list($ok, ) = TAP_Chat::can_client_chat((object) ['client_id' => $u, 'agency_id' => 0, 'guest_email' => (string) $wk], 0, 'wrong@email.com');
tap_t_assert(!$ok, 'guest with wrong email rejected');
list($ok, $name) = TAP_Chat::can_client_chat((object) ['client_id' => 0, 'agency_id' => 0, 'guest_email' => 'guest@example.com', 'guest_name' => 'Ana Viajera'], 0, 'guest@example.com');
tap_t_assert($ok && $name === 'Ana Viajera', 'verified guest can chat with booking email');
list($ok, ) = TAP_Chat::can_client_chat((object) ['client_id' => 0, 'agency_id' => 0, 'guest_email' => 'guest@example.com', 'guest_name' => 'Ana'], 0, '');
tap_t_assert(!$ok, 'guest without email rejected');

// --- voucher render marks client read + shows thread ----------------------------------------
TAP_Chat::send($bk, 'agency', (int) tap_t_test_agency(), 'Agencia Test', 'Nuevo aviso');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'client') === 1, 'client has unread before viewing');
$code = $wpdb->get_var($wpdb->prepare("SELECT booking_code FROM {$b} WHERE id=%d", $bk));
wp_set_current_user($u);
$_GET['code'] = $code;
$render = do_shortcode('[tap_booking_detail]');
wp_set_current_user(0);
tap_t_assert(false !== strpos($render, 'Mensajes con tu agencia'), 'voucher renders chat section');
tap_t_assert(false !== strpos($render, 'Claro, mañana te confirmamos'), 'voucher renders agency reply');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'client') === 0, 'rendering the voucher marks client messages read');
tap_t_assert((int) TAP_Chat::unread_for($bk, 'agency') === 0, 'rendering does not clear the agency side');

// --- acknowledgement callback ----------------------------------------------------------------
$fired = 0;
add_action('tap_chat_message_sent', function ($booking_id, $author_type, $message_id) use (&$fired) {
    $fired++;
}, 10, 3);
TAP_Chat::send($bk, 'agency', 0, 'Agencia', 'Callback');
tap_t_assert($fired === 1, 'tap_chat_message_sent action fired on send');

// --- cleanup ----------------------------------------------------------------------------------
$wpdb->query("DELETE FROM {$c} WHERE booking_id IN (" . implode(',', array_map('intval', [$bk, $gid])) . ")");
$wpdb->query("DELETE FROM {$b} WHERE id IN (" . implode(',', array_map('intval', [$bk, $gid])) . ")");
wp_delete_user($u);
tap_t_finish();