<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
defined('ABSPATH') || exit;

/**
 * ICal export for service availability.
 */
class TAP_ICal {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_export']);
    }

    public static function maybe_export() {
        if (empty($_GET['tap_ical'])) return;
        $service_type = sanitize_text_field(wp_unslash($_GET['service_type'] ?? ''));
        $service_id   = absint($_GET['service_id'] ?? 0);
        if (!$service_type || !$service_id) return;
        self::export($service_type, $service_id);
        exit;
    }

    public static function export($service_type, $service_id) {
        global $wpdb;
        $post = get_post($service_id);
        if (!$post || $post->post_type !== $service_type) {
            wp_die(__('Servicio no encontrado.', 'travel-agency-platform'));
        }

        // Build availability for next 90 days (accommodation rooms) or single events.
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Travel Agency Platform//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . self::escape($post->post_title);

        if ($service_type === 'tap_accommodation') {
            $rooms = TAP_Post_Types::get_accommodation_rooms($service_id);
            foreach ($rooms as $room) {
                $room_id = (int) $room->ID;
                // Blocked dates
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT date, is_blocked, price FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)",
                    $room_id
                ));
                foreach ($rows as $r) {
                    if (!$r->is_blocked) continue;
                    $d = str_replace('-', '', $r->date);
                    $lines[] = 'BEGIN:VEVENT';
                    $lines[] = 'UID:blocked-' . $room_id . '-' . $d . '@tap';
                    $lines[] = 'DTSTART;VALUE=DATE:' . $d;
                    $lines[] = 'DTEND;VALUE=DATE:' . $d;
                    $lines[] = 'SUMMARY:' . self::escape($room->post_title . ' - Bloqueado');
                    $lines[] = 'END:VEVENT';
                }
            }
        }

        // Bookings for this service
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE service_id = %d AND service_type = %s AND status NOT IN ('cancelled','refunded') AND check_in >= CURDATE()",
            $service_id, $service_type
        ));
        foreach ($bookings as $b) {
            $start = str_replace('-', '', $b->check_in);
            $end   = $b->check_out ? str_replace('-', '', $b->check_out) : $start;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:booking-' . $b->id . '@tap';
            $lines[] = 'DTSTART;VALUE=DATE:' . $start;
            $lines[] = 'DTEND;VALUE=DATE:' . $end;
            $lines[] = 'SUMMARY:' . self::escape('Reserva ' . $b->booking_code);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="tap-' . $service_id . '.ics"');
        echo implode("\r\n", $lines);
    }

    private static function escape($s) {
        return str_replace(["\n", "\r", ',', ';'], ['\\n', '', '\\,', '\\;'], (string) $s);
    }
}
