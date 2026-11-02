<?php
defined('ABSPATH') || exit;

/**
 * Read helpers for the tour product.
 *
 * Tour operators think in structured duration (days/hours), spoken languages
 * and a day-by-day itinerary, not in a single free-text "duration" field. The
 * wizard writes those values and the public tour template reads them through
 * this class so both stay in sync.
 */
class TAP_Tour_Info {

    /** Native language names offered by the platform.\n     *\n     * @var array<int,string>\n     */
    private static $language_options = [
        'Español',
        'English',
        'Français',
        'Deutsch',
        'Italiano',
        'Português',
        'Nederlands',
        'Português (BR)',
        '日本語',
        '中文',
        '한국어',
        'Русский',
    ];

    /**
     * Native language names used by the wizard checkboxes.
     *
     * @return array<int,string>
     */
    public static function language_options() {
        return self::$language_options;
    }

    /**
     * Languages offered by a tour, as stored in _tap_tour_languages.
     *
     * @param int $listing_id Tour id.
     * @return array<int,string>
     */
    public static function languages($listing_id) {
        $raw = (string) get_post_meta((int) $listing_id, '_tap_tour_languages', true);
        if ('' === trim($raw)) {
            return [];
        }
        $langs = array_map('trim', explode(',', $raw));
        return array_values(array_filter($langs, static function ($lang) {
            return '' !== $lang;
        }));
    }

    /**
     * Human duration label built from days/hours, with the legacy free-text
     * `_tap_tour_duration` as a fallback for tours created before the split.
     *
     * @param int $listing_id Tour id.
     * @return string
     */
    public static function duration_label($listing_id) {
        $listing_id = (int) $listing_id;
        $days       = (int) get_post_meta($listing_id, '_tap_tour_duration_days', true);
        $hours      = (int) get_post_meta($listing_id, '_tap_tour_duration_hours', true);
        $parts      = [];

        if ($days > 0) {
            /* translators: %s: number of days */
            $parts[] = 1 === $days ? __('1 día', 'travel-agency-platform') : sprintf(__('%s días', 'travel-agency-platform'), number_format_i18n($days));
            if ($days > 1) {
                $nights = $days - 1;
                /* translators: %s: number of nights */
                $parts[] = 1 === $nights ? __('1 noche', 'travel-agency-platform') : sprintf(__('%s noches', 'travel-agency-platform'), number_format_i18n($nights));
            }
        }
        if ($hours > 0) {
            /* translators: %s: number of hours */
            $parts[] = 1 === $hours ? __('1 hora', 'travel-agency-platform') : sprintf(__('%s horas', 'travel-agency-platform'), number_format_i18n($hours));
        }

        if ($parts) {
            return implode(' · ', $parts);
        }

        return trim((string) get_post_meta($listing_id, '_tap_tour_duration', true));
    }

    /**
     * Parsed itinerary rows.
     *
     * Stored as one line per stop so it stays editable in wp-admin:
     *   Día 1 · 09:00 | Bienvenida | Nos reunimos en la oficina.
     *
     * @param int $listing_id Tour id.
     * @return array<int,array{day:int,time:string,title:string,desc:string}>
     */
    public static function itinerary_rows($listing_id) {
        $raw = (string) get_post_meta((int) $listing_id, '_tap_tour_itinerary', true);
        return self::parse_itinerary($raw);
    }

    /**
     * Parse the plain-text itinerary format into structured rows.
     *
     * @param string $raw Stored text.
     * @return array<int,array{day:int,time:string,title:string,desc:string}>
     */
    public static function parse_itinerary($raw) {
        $rows = [];
        $raw  = trim((string) $raw);
        if ('' === $raw) {
            return $rows;
        }

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));
            $head  = $parts[0];
            $title = isset($parts[1]) ? $parts[1] : '';
            $desc  = isset($parts[2]) ? $parts[2] : '';

            $day  = 1;
            $time = '';
            if (preg_match('/(?:d[ií]a|day)\s*(\d+)/iu', $head, $m)) {
                $day = max(1, (int) $m[1]);
            }
            if (preg_match('/(\d{1,2}:\d{2})/', $head, $m)) {
                $time = $m[1];
            }

            // Free-text itineraries (created before the builder) become the
            // description of a single stop instead of being lost.
            if ('' === $title && '' === $desc) {
                $desc = $head;
            }

            $rows[] = [
                'day'   => $day,
                'time'  => $time,
                'title' => $title,
                'desc'  => $desc,
            ];
        }

        return $rows;
    }

    /**
     * Serialize builder rows back to the stored plain-text format.
     *
     * @param array<int,array<string,mixed>> $rows Rows.
     * @return string
     */
    public static function itinerary_to_text($rows) {
        $lines = [];
        foreach ((array) $rows as $row) {
            $day   = max(1, (int) (isset($row['day']) ? $row['day'] : 1));
            $time  = trim((string) (isset($row['time']) ? $row['time'] : ''));
            $title = trim((string) (isset($row['title']) ? $row['title'] : ''));
            $desc  = trim((string) (isset($row['desc']) ? $row['desc'] : ''));
            if ('' === $title && '' === $desc) {
                continue;
            }
            $head = 'Día ' . $day;
            if ('' !== $time) {
                $head .= ' · ' . $time;
            }
            $lines[] = $head . ' | ' . $title . ' | ' . $desc;
        }
        return implode("\n", $lines);
    }

    /**
     * Number of distinct days covered by the itinerary.
     *
     * @param int $listing_id Tour id.
     * @return int
     */
    public static function itinerary_day_count($listing_id) {
        $days = 0;
        foreach (self::itinerary_rows($listing_id) as $row) {
            $days = max($days, (int) $row['day']);
        }
        return $days;
    }

    /**
     * Next bookable slot for a tour, used by the review step.
     *
     * @param int $listing_id Tour id.
     * @return object|null
     */
    public static function next_slot($listing_id) {
        if (!class_exists('TAP_Tour_Availability')) {
            return null;
        }
        $slots = TAP_Tour_Availability::slots_for('tap_tour', (int) $listing_id, gmdate('Y-m-d'));
        foreach ($slots as $slot) {
            if ((int) $slot->is_blocked === 1) {
                continue;
            }
            $capacity = (int) $slot->capacity;
            if ($capacity > 0 && (int) $slot->booked >= $capacity) {
                continue;
            }
            return $slot;
        }
        return null;
    }

    /**
     * Adult price of a tour (0 when not set).
     *
     * @param int $listing_id Tour id.
     * @return float
     */
    public static function adult_price($listing_id) {
        return (float) get_post_meta((int) $listing_id, '_tap_tour_price_adult', true);
    }
}
