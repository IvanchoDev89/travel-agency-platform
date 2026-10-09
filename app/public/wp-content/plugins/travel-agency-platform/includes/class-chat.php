<?php
defined('ABSPATH') || exit;

/**
 * Booking-scoped guest <-> agency chat.
 *
 * All rows live in tap_chat_messages. A thread belongs to one booking; the
 * guest side always writes as 'client' and the agency side as 'agency'
 * (author_type). Read flags are tracked per side: marking a thread as read
 * only clears messages written by the OTHER side.
 */
class TAP_Chat {
    const TABLE_SUFFIX = 'tap_chat_messages';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Append a message to a booking thread.
     *
     * @param int    $booking_id  Booking row id.
     * @param string $author_type 'client' | 'agency'.
     * @param int    $author_id   WP user id (client) or agency post id (agency).
     * @param string $author_name Display name, e.g. guest name / agency title.
     * @param string $message     Raw message text (stripped + length capped).
     * @return int|WP_Error New message id.
     */
    public static function send($booking_id, $author_type, $author_id, $author_name, $message) {
        global $wpdb;

        $booking_id  = (int) $booking_id;
        $author_type = in_array($author_type, ['client', 'agency'], true) ? $author_type : 'client';
        $message     = trim(wp_strip_all_tags((string) $message, true));
        if ('' === $message) {
            return new WP_Error('empty_message', __('El mensaje no puede estar vacío.', 'travel-agency-platform'));
        }
        $message = mb_substr($message, 0, 2000);
        $name    = trim(wp_strip_all_tags((string) $author_name, true));
        $name    = mb_substr($name, 0, 190);

        $inserted = $wpdb->insert(
            self::table(),
            [
                'booking_id'  => $booking_id,
                'author_type' => $author_type,
                'author_id'   => (int) $author_id,
                'author_name' => '' !== $name ? $name : ($author_type === 'agency' ? __('Agencia', 'travel-agency-platform') : __('Huésped', 'travel-agency-platform')),
                'message'     => $message,
                'is_read'     => 0,
                'created_at'  => current_time('mysql'),
            ]
        );
        if (!$inserted) {
            return new WP_Error('db_error', __('No se pudo guardar el mensaje.', 'travel-agency-platform'));
        }
        $message_id = (int) $wpdb->insert_id;
        do_action('tap_chat_message_sent', (int) $booking_id, $author_type, $message_id);
        return $message_id;
    }

    /** All messages of a thread, oldest first. */
    public static function messages($booking_id, $limit = 200) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE booking_id = %d ORDER BY id ASC LIMIT %d",
            (int) $booking_id,
            (int) $limit
        ));
    }

    /** Messages written by the OTHER side that the reader has not seen yet. */
    public static function unread_for($booking_id, $reader_type) {
        global $wpdb;
        $reader_type = in_array($reader_type, ['client', 'agency'], true) ? $reader_type : 'client';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . "
             WHERE booking_id = %d AND author_type <> %s AND is_read = 0",
            (int) $booking_id,
            $reader_type
        ));
    }

    /**
     * Unread guest messages across every booking that belongs to an agency.
     * Powers the global badge in the agency back-office.
     */
    public static function unread_total_for_agency($agency_id) {
        global $wpdb;
        $agency_id = (int) $agency_id;
        if ($agency_id <= 0) {
            return 0;
        }
        $bookings = $wpdb->prefix . 'tap_bookings';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(m.id) FROM " . self::table() . " m
             INNER JOIN {$bookings} b ON b.id = m.booking_id
             WHERE b.agency_id = %d AND m.author_type = 'client' AND m.is_read = 0",
            $agency_id
        ));
    }

    /** Last message id of a thread (0 when empty). Used by the polling clients. */
    public static function last_id($booking_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM " . self::table() . " WHERE booking_id = %d",
            (int) $booking_id
        ));
    }

    /** Mark the reader's perspective as seen (clears the other side's flags). */
    public static function mark_read($booking_id, $reader_type) {
        global $wpdb;
        $reader_type = in_array($reader_type, ['client', 'agency'], true) ? $reader_type : 'client';
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
                SET is_read = 1
              WHERE booking_id = %d AND author_type <> %s AND is_read = 0",
            (int) $booking_id,
            $reader_type
        ));
    }

    /**
     * Verify the client-side author may write on a booking thread.
     *
     * Mirrors the voucher access rules: the owner, an admin, the managing
     * agency, or a guest who proves ownership with the booking email.
     *
     * @return array [$ok, $author_name] — name is '' when not allowed.
     */
    public static function can_client_chat($booking, $uid, $guest_email = '') {
        $uid = (int) $uid;
        if ($uid) {
            $ok = (int) $booking->client_id === $uid || user_can($uid, 'manage_options');
            if (!$ok && !empty($booking->agency_id)) {
                $agency = TAP_Booking::get_agency_for_user($uid);
                $ok     = $agency && (int) $agency === (int) $booking->agency_id;
            }
            $author = $ok ? get_userdata($uid) : false;
            $name   = $author ? ($author->display_name ?: '') : '';
            return [$ok, $name];
        }
        $guest_email = sanitize_email($guest_email);
        $ok = (int) $booking->client_id === 0
            && $guest_email !== ''
            && strcasecmp($guest_email, (string) $booking->guest_email) === 0;
        return [$ok, $ok ? ($booking->guest_name ?: __('Huésped', 'travel-agency-platform')) : ''];
    }

    /** Render one message row (shared by client + agency UIs). */
    public static function row_markup($m, $side = 'client') {
        $mine = (string) $m->author_type === $side;
        $fallback = 'agency' === $m->author_type ? __('Agencia', 'travel-agency-platform') : __('Huésped', 'travel-agency-platform');
        return '<div class="tap-chat-msg tap-chat-' . ($mine ? 'mine' : 'theirs') . '">'
            . '<span class="tap-chat-author">' . esc_html($m->author_name ?: $fallback) . '</span>'
            . '<p class="tap-chat-text">' . esc_html($m->message) . '</p>'
            . '<span class="tap-chat-time">' . esc_html(mysql2date('d M H:i', $m->created_at)) . '</span>'
            . '</div>';
    }
}