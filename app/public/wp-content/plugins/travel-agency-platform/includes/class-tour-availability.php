<?php
defined('ABSPATH') || exit;

/**
 * Tour/experience availability: slots per date/time with capacity tracking.
 *
 * Reuses tap_daily_pricing extended with service_type/service_id/time_slot.
 * One row = one bookable slot (date + optional time window).
 */
class TAP_Tour_Availability {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_daily_pricing';
    }

    /** List slots for a service in a date range. */
    public static function slots_for($service_type, $service_id, $from = '', $to = '') {
        global $wpdb;
        $t = self::table();
        $service_type = sanitize_key($service_type);
        $service_id = (int) $service_id;
        if (!$service_type || !$service_id) return [];
        $where = 'WHERE service_type = %s AND service_id = %d';
        $params = [$service_type, $service_id];
        if ($from) { $where .= ' AND date >= %s';
$params[] = $from; }
        if ($to) { $where .= ' AND date <= %s';
$params[] = $to; }
        $sql = "SELECT * FROM {$t} {$where} ORDER BY date ASC, time_slot ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /** Get a single slot by id. */
    public static function get_slot($id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    /** Create a slot. Returns insert id or 0. */
    public static function create_slot($data) {
        global $wpdb;
        $t = self::table();
        $service_type = sanitize_key($data['service_type'] ?? '');
        $service_id = (int) ($data['service_id'] ?? 0);
        $date = sanitize_text_field($data['date'] ?? '');
        if (!$service_type || !$service_id || !$date) return 0;

        $capacity = isset($data['capacity']) ? (int) $data['capacity'] : 0;
        if ($capacity < 0) $capacity = 0;

        $wpdb->insert($t, [
            'room_id'      => 0,
            'service_type' => $service_type,
            'service_id'   => $service_id,
            'date'         => $date,
            'time_slot'    => !empty($data['time_slot']) ? sanitize_text_field($data['time_slot']) : null,
            'time_end'     => !empty($data['time_end']) ? sanitize_text_field($data['time_end']) : null,
            'price'        => isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null,
            'min_stay'     => isset($data['min_stay']) ? (int) $data['min_stay'] : null,
            'is_blocked'   => !empty($data['is_blocked']) ? 1 : 0,
            'label'        => isset($data['label']) ? sanitize_text_field($data['label']) : null,
            'capacity'     => $capacity,
            'booked'       => 0,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Update a slot. */
    public static function update_slot($id, $data) {
        global $wpdb;
        $t = self::table();
        $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        $formats = [];
        if (isset($data['date'])) { $fields['date'] = sanitize_text_field($data['date']);
$formats[] = '%s'; }
        if (isset($data['time_slot'])) { $fields['time_slot'] = $data['time_slot'] ? sanitize_text_field($data['time_slot']) : null;
$formats[] = '%s'; }
        if (isset($data['time_end'])) { $fields['time_end'] = $data['time_end'] ? sanitize_text_field($data['time_end']) : null;
$formats[] = '%s'; }
        if (array_key_exists('price', $data)) { $fields['price'] = ($data['price'] === '' || $data['price'] === null) ? null : (float) $data['price'];
$formats[] = '%f'; }
        if (isset($data['is_blocked'])) { $fields['is_blocked'] = !empty($data['is_blocked']) ? 1 : 0;
$formats[] = '%d'; }
        if (isset($data['label'])) { $fields['label'] = sanitize_text_field($data['label']);
$formats[] = '%s'; }
        if (isset($data['capacity'])) { $fields['capacity'] = (int) $data['capacity'];
$formats[] = '%d'; }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id], $formats, ['%d']);
        return true;
    }

    /** Delete a slot (only if no bookings). */
    public static function delete_slot($id) {
        global $wpdb;
        $t = self::table();
        $id = (int) $id;
        $slot = self::get_slot($id);
        if (!$slot) return false;
        if ((int) $slot->booked > 0) return false;
        $wpdb->delete($t, ['id' => $id], ['%d']);
        return true;
    }

    /** Check if a slot has enough capacity. */
    public static function is_available($slot_id, $adults = 1) {
        $slot = self::get_slot($slot_id);
        if (!$slot) return false;
        if ((int) $slot->is_blocked === 1) return false;
        $capacity = (int) $slot->capacity;
        if ($capacity <= 0) {
            // Fall back to service capacity.
            $meta_key = $slot->service_type === 'tap_experience' ? '_tap_exp_capacity' : '_tap_tour_capacity';
            $capacity = (int) get_post_meta((int) $slot->service_id, $meta_key, true);
            if ($capacity <= 0) $capacity = 999;
        }
        $booked = (int) $slot->booked;
        $over_pct = (int) get_post_meta((int) $slot->service_id, '_tap_tour_overbooking_pct', true);
        // overbooking for tours uses a generic meta; default 0.
        if ($over_pct > 0) $capacity = (int) ceil($capacity * (1 + $over_pct / 100));
        return ($booked + (int) $adults) <= $capacity;
    }

    /** Book a slot (increment booked). Returns true on success. */
    public static function book_slot($slot_id, $adults = 1) {
        global $wpdb;
        $t = self::table();
        $slot_id = (int) $slot_id;
        $adults = max(1, (int) $adults);
        $slot = self::get_slot($slot_id);
        if (!$slot || (int) $slot->is_blocked === 1) return false;
        if (!self::is_available($slot_id, $adults)) return false;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET booked = booked + %d WHERE id = %d AND booked + %d <= capacity OR (capacity = 0)",
            $adults, $slot_id, $adults
        ));
        // Simpler, safer: re-check after update.
        $updated = self::get_slot($slot_id);
        return $updated && (int) $updated->booked >= (int) $slot->booked + $adults;
    }

    /** Release a slot (decrement booked) on cancellation. */
    public static function release_slot($slot_id, $adults = 1) {
        global $wpdb;
        $t = self::table();
        $slot_id = (int) $slot_id;
        $adults = max(1, (int) $adults);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET booked = GREATEST(0, booked - %d) WHERE id = %d",
            $adults, $slot_id
        ));
        return true;
    }

    /** Generate recurring slots from a rule. */
    public static function generate_recurring($service_type, $service_id, $rule) {
        $service_type = sanitize_key($service_type);
        $service_id = (int) $service_id;
        if (!$service_type || !$service_id) return 0;

        $start = new DateTime($rule['start_date'] ?? 'today');
        $end = new DateTime($rule['end_date'] ?? '+3 months');
        $days_of_week = isset($rule['days']) && is_array($rule['days']) ? $rule['days'] : [1, 2, 3, 4, 5]; // Mon-Fri default
        $time_slot = sanitize_text_field($rule['time_slot'] ?? '');
        $time_end = sanitize_text_field($rule['time_end'] ?? '');
        $capacity = (int) ($rule['capacity'] ?? 0);
        $price = isset($rule['price']) && $rule['price'] !== '' ? (float) $rule['price'] : null;
        $label = sanitize_text_field($rule['label'] ?? '');

        $count = 0;
        $cur = clone $start;
        while ($cur <= $end) {
            $dow = (int) $cur->format('N'); // 1=Mon..7=Sun
            if (in_array($dow, $days_of_week, true)) {
                $date = $cur->format('Y-m-d');
                self::create_slot([
                    'service_type' => $service_type,
                    'service_id'   => $service_id,
                    'date'         => $date,
                    'time_slot'    => $time_slot,
                    'time_end'     => $time_end,
                    'capacity'     => $capacity,
                    'price'        => $price,
                    'label'        => $label,
                ]);
                ++$count;
            }
            $cur->modify('+1 day');
        }
        return $count;
    }

    /** Get price for a slot (override or service base). */
    public static function slot_price($slot) {
        if (!$slot) return 0;
        if ($slot->price !== null) return (float) $slot->price;
        $meta_key = $slot->service_type === 'tap_experience' ? '_tap_exp_price_adult' : '_tap_tour_price_adult';
        return (float) get_post_meta((int) $slot->service_id, $meta_key, true);
    }
}
