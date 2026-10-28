<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * ICal import: fetch a remote .ics feed and block dates in tap_daily_pricing.
 *
 * @package TravelAgencyPlatform
 */
class TAP_ICal_Import {


    /** Fetch and parse an iCal feed, return array of blocked date ranges. */
    public static function fetch($url) {
        $url = esc_url_raw($url);
        if (!$url) return [];
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) return [];
        $body = wp_remote_retrieve_body($resp);
        if (!$body) return [];
        return self::parse($body);
    }

    /** Parse iCal text into array of {start, end} date ranges. */
    public static function parse($ics) {
        $ranges = [];
        $lines = preg_split('/\r\n|\r|\n/', $ics);
        $in_event = false;
        $start = null;
		$end = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') { $in_event = true;
				$start = null;
				$end = null;
				continue; }
            if ($line === 'END:VEVENT') {
                if ($in_event && $start) {
                    if (!$end) $end = $start;
                    $ranges[] = ['start' => $start, 'end' => $end];
                }
                $in_event = false;
                continue;
            }
            if (!$in_event) continue;
            if (strpos($line, 'DTSTART') === 0) {
                $start = self::parse_date($line);
            } elseif (strpos($line, 'DTEND') === 0) {
                $end = self::parse_date($line);
            }
        }
        return $ranges;
    }

    private static function parse_date($line) {
        // DTSTART:20260101 or DTSTART;VALUE=DATE:20260101
        if (preg_match('/:(\d{8})/', $line, $m)) {
            $d = $m[1];
            return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
        }
        if (preg_match('/:(\d{4}-\d{2}-\d{2})/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Apply blocked ranges to a room (block dates in tap_daily_pricing). */
    public static function apply_to_room($room_id, $ranges) {
        global $wpdb;
        $room_id = (int) $room_id;
        if (!$room_id) return 0;
        $count = 0;
        foreach ($ranges as $r) {
            $start = new DateTime($r['start']);
            $end   = new DateTime($r['end']);
            // iCal DTEND is exclusive for all-day events
            $end->modify('-1 day');
            $cur = clone $start;
            while ($cur <= $end) {
                $date = $cur->format('Y-m-d');
                // upsert blocked
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s",
                    $room_id, $date
                ));
                if ($existing) {
                    $wpdb->update("{$wpdb->prefix}tap_daily_pricing", ['is_blocked' => 1], ['id' => (int) $existing]);
                } else {
                    $wpdb->insert("{$wpdb->prefix}tap_daily_pricing", [
                        'room_id'    => $room_id,
                        'date'       => $date,
                        'price'      => null,
                        'is_blocked' => 1,
                    ]);
                }
                ++$count;
                $cur->modify('+1 day');
            }
        }
        return $count;
    }

    /** Init: register REST endpoint. */
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('tap/v1', '/ical/import', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_import'],
                'permission_callback' => function () {
					return current_user_can('edit_posts'); },
            ]);
        });
    }

    public static function rest_import($request) {
        $params = $request->get_params();
        $url    = esc_url_raw($params['url'] ?? '');
        $room_id = absint($params['room_id'] ?? 0);
        if (!$url || !$room_id) {
            return new WP_Error('invalid', __('URL y room_id requeridos.', 'travel-agency-platform'), ['status' => 400]);
        }
        $ranges = self::fetch($url);
        if (!$ranges) {
            return new WP_Error('no_data', __('No se pudieron obtener eventos del feed.', 'travel-agency-platform'), ['status' => 400]);
        }
        $count = self::apply_to_room($room_id, $ranges);
        return rest_ensure_response(['imported' => $count, 'ranges' => count($ranges)]);
    }
}
