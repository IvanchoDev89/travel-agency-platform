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

        $capacity = (int) $slot->capacity;
        if ($capacity <= 0) {
            $meta_key = $slot->service_type === 'tap_experience' ? '_tap_exp_capacity' : '_tap_tour_capacity';
            $capacity = (int) get_post_meta((int) $slot->service_id, $meta_key, true);
            if ($capacity <= 0) {
                $capacity = 999;
            }
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET booked = booked + %d WHERE id = %d AND (capacity > 0 AND booked + %d <= capacity) OR (capacity = 0 AND booked + %d <= %d)",
            $adults, $slot_id, $adults, $adults, $capacity
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
                // Running the generator twice must not duplicate slots.
                if (!self::slot_exists($service_type, $service_id, $date, $time_slot)) {
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
            }
            $cur->modify('+1 day');
        }
        return $count;
    }

    /** Whether a slot already exists for a date + time. */
    public static function slot_exists($service_type, $service_id, $date, $time_slot = '') {
        global $wpdb;
        $t = self::table();
        $time_slot = (string) $time_slot;
        if ('' === $time_slot) {
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t} WHERE service_type = %s AND service_id = %d AND date = %s AND (time_slot IS NULL OR time_slot = '') LIMIT 1",
                $service_type,
                (int) $service_id,
                $date
            ));
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE service_type = %s AND service_id = %d AND date = %s AND time_slot = %s LIMIT 1",
            $service_type,
            (int) $service_id,
            $date,
            $time_slot
        ));
    }

    /** True when the current user may manage a tour's slots. */
    public static function can_manage($service_id) {
        $service_id = (int) $service_id;
        if (!$service_id || !is_user_logged_in()) {
            return false;
        }
        if (current_user_can('manage_options')) {
            return true;
        }
        return class_exists('TAP_Listing_Access') && TAP_Listing_Access::agency_owns_listing($service_id, 'tap_tour');
    }

    /**
     * Upcoming slots as a JSON-friendly payload, shared by the wizard initial
     * render and the AJAX refresh.
     *
     * @param int $service_id Tour id.
     * @param int $limit      Max rows.
     * @return array<int,array<string,mixed>>
     */
    public static function slots_payload($service_id, $limit = 60) {
        $slots = self::slots_for('tap_tour', (int) $service_id, gmdate('Y-m-d'));
        $out   = [];
        foreach (array_slice($slots, 0, max(1, (int) $limit)) as $slot) {
            $out[] = self::slot_payload($slot);
        }
        return $out;
    }

    /**
     * Format one slot for the wizard list.
     *
     * @param object $slot Row from tap_daily_pricing.
     * @return array<string,mixed>
     */
    public static function slot_payload($slot) {
        $capacity = (int) $slot->capacity;
        $booked   = (int) $slot->booked;
        return [
            'id'          => (int) $slot->id,
            'date'        => (string) $slot->date,
            'date_label'  => date_i18n(get_option('date_format'), strtotime((string) $slot->date)),
            'time_slot'   => (string) ($slot->time_slot ?? ''),
            'time_end'    => (string) ($slot->time_end ?? ''),
            'capacity'    => $capacity,
            'booked'      => $booked,
            'price'       => null !== $slot->price ? (float) $slot->price : null,
            'price_label' => null !== $slot->price ? TAP_Currency::fmt((float) $slot->price) : '',
            'is_blocked'  => (int) $slot->is_blocked,
            'label'       => (string) ($slot->label ?? ''),
            'full'        => $capacity > 0 && $booked >= $capacity,
        ];
    }

    /** AJAX: generate recurring slots for a tour. */
    public static function ajax_schedule() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
        if (!self::can_manage($service_id)) {
            wp_send_json_error(['message' => __('No tienes permiso para gestionar este tour.', 'travel-agency-platform')], 403);
        }

        $start = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $days  = isset($_POST['days']) ? array_map('intval', (array) $_POST['days']) : [];
        $days  = array_values(array_filter($days, static function ($d) {
            return $d >= 1 && $d <= 7;
        }));

        $times = isset($_POST['times']) ? json_decode(wp_unslash((string) $_POST['times']), true) : [];
        $times = is_array($times) ? $times : [];
        $times = array_values(array_unique(array_filter(array_map(static function ($time) {
            $time = trim((string) $time);
            return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '';
        }, $times))));

        if (!$start || !$end || !$days || !$times) {
            wp_send_json_error(['message' => __('Indica el rango de fechas, los días y al menos un horario.', 'travel-agency-platform')]);
        }

        try {
            $from = new DateTime($start);
            $to   = new DateTime($end);
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Las fechas no son válidas.', 'travel-agency-platform')]);
            return;
        }
        if ($to < $from) {
            wp_send_json_error(['message' => __('La fecha final debe ser posterior a la inicial.', 'travel-agency-platform')]);
        }
        $max_days = 366;
        if ((int) $from->diff($to)->days > $max_days) {
            wp_send_json_error(['message' => __('Genera como máximo un año de disponibilidad por vez.', 'travel-agency-platform')]);
        }

        $capacity = isset($_POST['capacity']) ? max(0, absint($_POST['capacity'])) : 0;
        $price    = isset($_POST['price']) && '' !== $_POST['price'] ? (float) $_POST['price'] : null;
        $label    = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        $time_end = isset($_POST['time_end']) ? sanitize_text_field(wp_unslash($_POST['time_end'])) : '';

        $created = 0;
        foreach ($times as $time) {
            $created += self::generate_recurring('tap_tour', $service_id, [
                'start_date' => $start,
                'end_date'   => $end,
                'days'       => $days,
                'time_slot'  => $time,
                'time_end'   => $time_end,
                'capacity'   => $capacity,
                'price'      => null === $price ? '' : $price,
                'label'      => $label,
            ]);
        }

        wp_send_json_success([
            'message' => $created > 0
                /* translators: %d: number of slots created */
                ? sprintf(__('Se crearon %d horarios.', 'travel-agency-platform'), $created)
                : __('No había horarios nuevos que crear.', 'travel-agency-platform'),
            'created' => $created,
            'slots'   => self::slots_payload($service_id),
        ]);
    }

    /** AJAX: block, unblock or delete a single slot. */
    public static function ajax_slot() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
        $slot_id    = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;
        $op         = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $slot       = self::get_slot($slot_id);

        if (!$slot || (int) $slot->service_id !== $service_id || 'tap_tour' !== $slot->service_type || !self::can_manage($service_id)) {
            wp_send_json_error(['message' => __('Horario no encontrado.', 'travel-agency-platform')], 404);
        }

        if ('delete' === $op) {
            if (!self::delete_slot($slot_id)) {
                wp_send_json_error(['message' => __('No se puede eliminar un horario con reservas.', 'travel-agency-platform')]);
            }
            $message = __('Horario eliminado.', 'travel-agency-platform');
        } elseif ('block' === $op || 'unblock' === $op) {
            self::update_slot($slot_id, ['is_blocked' => 'block' === $op ? 1 : 0]);
            $message = 'block' === $op
                ? __('Horario bloqueado.', 'travel-agency-platform')
                : __('Horario disponible de nuevo.', 'travel-agency-platform');
        } else {
            wp_send_json_error(['message' => __('Acción no válida.', 'travel-agency-platform')]);
        }

        wp_send_json_success([
            'message' => $message,
            'slots'   => self::slots_payload($service_id),
        ]);
    }

    /** Get price for a slot (override or service base). */
    public static function slot_price($slot) {
        if (!$slot) return 0;
        if ($slot->price !== null) return (float) $slot->price;
        $meta_key = $slot->service_type === 'tap_experience' ? '_tap_exp_price_adult' : '_tap_tour_price_adult';
        return (float) get_post_meta((int) $slot->service_id, $meta_key, true);
    }
}
