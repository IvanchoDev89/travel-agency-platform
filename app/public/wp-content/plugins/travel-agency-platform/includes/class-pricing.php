<?php
defined('ABSPATH') || exit;

class TAP_Pricing {
    private static $table;

    public static function init() {
        global $wpdb;
        self::$table = $wpdb->prefix . 'tap_daily_pricing';

        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('wp_ajax_tap_save_pricing', [__CLASS__, 'ajax_save_pricing']);
        add_action('wp_ajax_tap_get_pricing', [__CLASS__, 'ajax_get_pricing']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Agency back-office calendar (front-end): reuses the same core.
        add_action('wp_ajax_tap_agency_get_pricing', [__CLASS__, 'ajax_agency_get_pricing']);
        add_action('wp_ajax_tap_agency_save_pricing', [__CLASS__, 'ajax_agency_save_pricing']);
    }

    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;
        if (get_post_type() !== 'tap_room') return;

        wp_enqueue_style('tap-pricing', TAP_PLUGIN_URL . 'assets/css/admin-pricing.css', [], TAP_VERSION);
        wp_enqueue_script('tap-pricing', TAP_PLUGIN_URL . 'assets/js/admin-pricing.js', ['jquery'], TAP_VERSION, true);
        wp_localize_script('tap-pricing', 'tapPricing', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tap_pricing'),
            'room_id'  => get_the_ID(),
        ]);
    }

    public static function add_metabox() {
        add_meta_box(
            'tap_pricing_calendar',
            'Calendario de Precios',
            [__CLASS__, 'render_calendar'],
            'tap_room',
            'normal',
            'high'
        );
    }

    public static function render_calendar($post) {
        ?>
        <div class="tap-pricing-wrap">
            <div class="tap-pricing-toolbar">
                <div class="tap-pricing-month-nav">
                    <button type="button" class="tap-pricing-nav-btn" data-dir="-1">&larr;</button>
                    <span class="tap-pricing-month-label">--</span>
                    <button type="button" class="tap-pricing-nav-btn" data-dir="1">&rarr;</button>
                </div>
                <div class="tap-pricing-bulk">
                    <button type="button" class="button tap-pricing-bulk-btn" data-action="set-season">Fijar temporada</button>
                    <button type="button" class="button tap-pricing-bulk-btn" data-action="clear-all">Limpiar todo</button>
                    <span class="tap-pricing-legend">
                        <span><span class="tap-pricing-swatch swatch-base"></span> Base</span>
                        <span><span class="tap-pricing-swatch swatch-override"></span> Modificado</span>
                        <span><span class="tap-pricing-swatch swatch-blocked"></span> Bloqueado</span>
                    </span>
                </div>
            </div>
            <div class="tap-pricing-calendar" data-room="<?php echo (int) $post->ID; ?>">
                <div class="tap-pricing-loading">Cargando calendario...</div>
            </div>
            <div class="tap-pricing-edit-panel" style="display:none;">
                <h4>Editar precio — <span class="tap-edit-date"></span></h4>
                <input type="hidden" class="tap-edit-room" value="<?php echo (int) $post->ID; ?>">
                <input type="hidden" class="tap-edit-date-input">
                <table class="tap-edit-table">
                    <tr>
                        <td><label>Precio ($)</label></td>
                        <td><input type="number" step="0.01" min="0" class="tap-edit-price tap-edit-field" placeholder="Usar base"></td>
                    </tr>
                    <tr>
                        <td><label>Estadía mínima (noches)</label></td>
                        <td><input type="number" min="0" step="1" class="tap-edit-minstay tap-edit-field" placeholder="Usar base"></td>
                    </tr>
                    <tr>
                        <td><label>Disponible</label></td>
                        <td>
                            <select class="tap-edit-available tap-edit-field">
                                <option value="">Usar base</option>
                                <option value="yes">Sí</option>
                                <option value="no">No (bloquear)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label>Etiqueta</label></td>
                        <td><input type="text" class="tap-edit-label tap-edit-field" placeholder="p.ej. Temporada Alta" maxlength="100"></td>
                    </tr>
                </table>
                <div class="tap-edit-actions">
                    <button type="button" class="button button-primary tap-edit-save">Guardar</button>
                    <button type="button" class="button tap-edit-delete">Eliminar</button>
                    <span class="tap-edit-msg"></span>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_get_pricing() {
        check_ajax_referer('tap_pricing', 'nonce');
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $year    = (int) ($_POST['year'] ?? 0);
        $month   = (int) ($_POST['month'] ?? 0);

        wp_send_json(array_merge(['success' => true], self::month_data($room_id, $year, $month)));
    }

    /**
     * Shared calendar payload for a room/month. Used by the admin metabox and
     * by the agency back-office so both views stay perfectly in sync.
     */
    public static function month_data($room_id, $year, $month, $with_booked = false) {
        $room_id = (int) $room_id;
        $year    = (int) $year;
        $month   = (int) $month;
        $return  = [
            'days'          => [],
            'base_price'    => 0,
            'base_min'      => 1,
            'inventory'     => 1,
            'year'          => $year,
            'month'         => $month,
            'days_in_month' => 0,
        ];

        if ($room_id <= 0 || $month < 1 || $month > 12 || $year < 1970 || $year > 2100) {
            return $return;
        }
        if (get_post_type($room_id) !== 'tap_room') {
            return $return;
        }

        $base_price = (float) get_post_meta($room_id, '_tap_room_price_per_night', true) ?: 0;
        $base_min   = (int) get_post_meta($room_id, '_tap_room_min_stay', true) ?: 1;
        $inventory  = max(1, (int) get_post_meta($room_id, '_tap_room_inventory', true) ?: 1);

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date, price, min_stay, is_blocked, label FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date BETWEEN %s AND %s",
            $room_id, $start, $end
        ));

        $overrides = [];
        foreach ($rows as $r) {
            $overrides[$r->date] = [
                'price'      => $r->price !== null ? (float) $r->price : null,
                'min_stay'   => $r->min_stay !== null ? (int) $r->min_stay : null,
                'is_blocked' => (int) $r->is_blocked,
                'label'      => $r->label,
            ];
        }

        // Booked nights per date (nights that overlap any active booking).
        $booked = [];
        if ($with_booked) {
            $month_end = gmdate('Y-m-t', strtotime($start));
            $next      = gmdate('Y-m-d', strtotime($month_end . ' +1 day'));
            $book_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT check_in, check_out FROM {$wpdb->prefix}tap_bookings
                 WHERE room_id = %d AND check_in < %s AND check_out > %s
                   AND status NOT IN ('cancelled','refunded')",
                $room_id, $next, $start
            ));
            foreach ($book_rows as $b) {
                $from = max($start, (string) $b->check_in);
                $to   = min($next, (string) $b->check_out);
                $d = new DateTime($from);
                $limit = new DateTime($to);
                while ($d < $limit) {
                    $y = (int) $d->format('Y');
                    $m = (int) $d->format('n');
                    $dm = (int) $d->format('j');
                    if ($y === $year && $m === $month) {
                        $booked[$d->format('Y-m-d')] = (int) ($booked[$d->format('Y-m-d')] ?? 0) + 1;
                    }
                    $d->modify('+1 day');
                }
            }
            // Normalize so every valid date of the month has a key.
            $booked_days = $booked;
            $booked = [];
            for ($d = 1; $d <= $days_in_month; $d++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $booked[$date] = (int) ($booked_days[$date] ?? 0);
            }
        }

        $days = [];
        for ($d = 1; $d <= $days_in_month; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $day_of_week = date('N', strtotime($date));

            $has_override = isset($overrides[$date]);
            $day = [
                'date'       => $date,
                'day'        => $d,
                'dow'        => $day_of_week,
                'price'      => $has_override && $overrides[$date]['price'] !== null ? $overrides[$date]['price'] : $base_price,
                'min_stay'   => $has_override && $overrides[$date]['min_stay'] !== null ? $overrides[$date]['min_stay'] : $base_min,
                'is_blocked' => $has_override ? $overrides[$date]['is_blocked'] : 0,
                'label'      => $has_override ? $overrides[$date]['label'] : '',
                'overridden' => $has_override,
            ];
            if ($with_booked) {
                $day['booked'] = $booked[$date];
            }
            $days[] = $day;
        }

        $return['days']          = $days;
        $return['base_price']    = $base_price;
        $return['base_min']      = $base_min;
        $return['inventory']     = $inventory;
        $return['days_in_month'] = $days_in_month;
        return $return;
    }

    public static function ajax_save_pricing() {
        check_ajax_referer('tap_pricing', 'nonce');
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            wp_send_json(['success' => false, 'message' => 'No autorizado']);
        }

        $room_id = (int) ($_POST['room_id'] ?? 0);
        $date    = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));

        if (!$room_id || !$date) {
            wp_send_json(['success' => false, 'message' => 'Datos inválidos']);
        }

        // The pricing editor only manages rooms of advertisements the agency
        // owns; reject rooms that belong to someone else or to a non-accommodation.
        if (!self::can_manage_room($room_id)) {
            wp_send_json(['success' => false, 'message' => 'No autorizado']);
        }

        $price    = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;
        $min_stay = isset($_POST['min_stay']) && $_POST['min_stay'] !== '' ? (int) $_POST['min_stay'] : null;
        $blocked  = isset($_POST['is_blocked']) ? (int) $_POST['is_blocked'] : 0;
        $label    = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';

        $result = self::save_cell($room_id, $date, $price, $min_stay, $blocked, $label);
        wp_send_json($result);
    }

    /**
     * True when the given user (default: current) may edit a room's daily
     * pricing. Admins always may; agency staff must own the room via the
     * accommodation's agency ownership meta.
     */
    public static function can_manage_room($room_id, $user_id = 0) {
        $room_id = (int) $room_id;
        $user_id = (int) $user_id ?: get_current_user_id();
        if (!$user_id || $room_id <= 0) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $acc_id = (int) get_post_meta($room_id, '_tap_room_accommodation_id', true);
        if (!$acc_id || get_post_type($acc_id) !== 'tap_accommodation') {
            return false;
        }
        $agency    = (int) get_post_meta($acc_id, '_tap_acc_agency_id', true);
        $my_agency = (int) TAP_Booking::get_agency_for_user($user_id);
        return $agency > 0 && $my_agency > 0 && $my_agency === $agency;
    }

    /**
     * Shared daily-cell upsert. Clears the override when every field is empty,
     * otherwise creates/updates the row. Returns a result array; never dies.
     */
    public static function save_cell($room_id, $date, $price, $min_stay, $blocked, $label) {
        $room_id = (int) $room_id;
        $date    = (string) $date;

        if (!$room_id || !$date || !preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            return ['ok' => false, 'success' => false, 'message' => 'Datos inválidos'];
        }
        $acc_id = (int) get_post_meta($room_id, '_tap_room_accommodation_id', true);
        if (!$acc_id || get_post_type($acc_id) !== 'tap_accommodation') {
            return ['ok' => false, 'success' => false, 'message' => 'Habitación inválida'];
        }

        $price    = $price !== null && $price !== '' ? (float) $price : null;
        $min_stay = $min_stay !== null && $min_stay !== '' ? max(0, (int) $min_stay) : null;
        $blocked  = (int) $blocked ? 1 : 0;
        $label    = sanitize_text_field((string) $label);
        if (mb_strlen($label) > 100) {
            $label = mb_substr($label, 0, 100);
        }

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s",
            $room_id, $date
        ));

        if ($price === null && $min_stay === null && !$blocked && '' === $label) {
            if ($existing) {
                $wpdb->delete($wpdb->prefix . 'tap_daily_pricing', ['room_id' => $room_id, 'date' => $date]);
                return ['ok' => true, 'success' => true, 'action' => 'deleted', 'date' => $date];
            }
            return ['ok' => true, 'success' => true, 'action' => 'noop', 'date' => $date];
        }

        $data = [
            'room_id'   => $room_id,
            'date'      => $date,
            'price'     => $price,
            'min_stay'  => $min_stay,
            'is_blocked'=> $blocked,
            'label'     => $label,
        ];

        if ($existing) {
            $wpdb->update($wpdb->prefix . 'tap_daily_pricing', $data, ['id' => $existing]);
            return ['ok' => true, 'success' => true, 'action' => 'updated', 'date' => $date];
        }
        $wpdb->insert($wpdb->prefix . 'tap_daily_pricing', $data);
        return ['ok' => true, 'success' => true, 'action' => 'created', 'date' => $date];
    }

    /**
     * Agency back-office calendar load. Same payload as the admin view plus
     * booked-night counts and room inventory so the grid is truly usable.
     */
    public static function ajax_agency_get_pricing() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'No autorizado']);
        }
        $room_id = (int) ($_POST['room_id'] ?? 0);
        if (!self::can_manage_room($room_id)) {
            wp_send_json_error(['message' => 'No autorizado']);
        }
        $year  = (int) ($_POST['year'] ?? 0);
        $month = (int) ($_POST['month'] ?? 0);
        wp_send_json_success(self::month_data($room_id, $year, $month, true));
    }

    /**
     * Agency back-office calendar save. Accepts either a single date-cell or a
     * bulk payload (bulk=1 + cells JSON: [{date,price,min_stay,is_blocked,label}]).
     * All writes go through save_cell() so admin and agency behave identically.
     */
    public static function ajax_agency_save_pricing() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'No autorizado']);
        }
        $room_id = (int) ($_POST['room_id'] ?? 0);
        if (!self::can_manage_room($room_id)) {
            wp_send_json_error(['message' => 'No autorizado']);
        }

        $applied = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0, 'failed' => 0];
        $bad     = [];

        $save_one = function ($date, $price, $min_stay, $blocked, $label) use ($room_id, &$applied, &$bad) {
            $date = sanitize_text_field((string) $date);
            $res  = self::save_cell($room_id, $date, $price, $min_stay, $blocked, $label);
            if ($res['ok']) {
                $applied[$res['action']]++;
                return true;
            }
            $bad[] = $date;
            $applied['failed']++;
            return false;
        };

        if (!empty($_POST['bulk']) && isset($_POST['cells'])) {
            $cells = json_decode(wp_unslash((string) $_POST['cells']), true);
            if (!is_array($cells)) {
                wp_send_json_error(['message' => 'Datos inválidos']);
            }
            $count = 0;
            foreach ($cells as $cell) {
                if (!is_array($cell) || !isset($cell['date'])) {
                    continue;
                }
                $save_one(
                    $cell['date'],
                    isset($cell['price']) && $cell['price'] !== '' ? (float) $cell['price'] : null,
                    isset($cell['min_stay']) && $cell['min_stay'] !== '' ? (int) $cell['min_stay'] : null,
                    isset($cell['is_blocked']) ? (int) $cell['is_blocked'] : 0,
                    isset($cell['label']) ? (string) $cell['label'] : ''
                );
                $count++;
            }
            $applied['cells'] = $count;
        } else {
            $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
            if (!$date) {
                wp_send_json_error(['message' => 'Datos inválidos']);
            }
            $save_one(
                $date,
                isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null,
                isset($_POST['min_stay']) && $_POST['min_stay'] !== '' ? (int) $_POST['min_stay'] : null,
                isset($_POST['is_blocked']) ? (int) $_POST['is_blocked'] : 0,
                isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : ''
            );
        }

        wp_send_json_success([
            'message' => $applied['failed'] > 0
                ? __('Algunas fechas no pudieron guardarse.', 'travel-agency-platform')
                : __('Guardado', 'travel-agency-platform'),
            'applied' => $applied,
        ]);
    }

    public static function get_price_for_night($room_id, $date) {
        global $wpdb;
        $base = (float) get_post_meta($room_id, '_tap_room_price_per_night', true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT price, is_blocked FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s",
            $room_id, $date
        ));
        if ($row) {
            if ($row->is_blocked) return false;
            return $row->price !== null ? (float) $row->price : $base;
        }
        return $base ?: false;
    }

    public static function get_prices_for_range($room_id, $check_in, $check_out) {
        $prices = [];
        $current = new DateTime($check_in);
        $end = new DateTime($check_out);

        while ($current < $end) {
            $date = $current->format('Y-m-d');
            $price = self::get_price_for_night($room_id, $date);
            if ($price === false) {
                return ['available' => false, 'date' => $date];
            }
            $prices[$date] = $price;
            $current->modify('+1 day');
        }

        return ['available' => true, 'prices' => $prices, 'total' => array_sum($prices)];
    }

    public static function calculate_total($room_id, $check_in, $check_out, $apply_discounts = true) {
        $result = self::get_prices_for_range($room_id, $check_in, $check_out);
        if (!$result['available']) return false;

        if ($apply_discounts && class_exists('TAP_Discounts')) {
            $quote = TAP_Discounts::get_quote($room_id, $check_in, $check_out);
            if ($quote['available']) return $quote['total'];
        }

        return $result['total'];
    }

    /**
     * Like calculate_total but returns full detail for display.
     */
    public static function get_quote($room_id, $check_in, $check_out) {
        if (!class_exists('TAP_Discounts')) {
            $result = self::get_prices_for_range($room_id, $check_in, $check_out);
            if (!$result['available']) return ['available' => false, 'date' => $result['date']];
            return [
                'available' => true,
                'base_total' => $result['total'],
                'total' => $result['total'],
                'savings' => 0,
                'discounts' => [],
                'nights' => count($result['prices']),
                'prices' => $result['prices'],
            ];
        }
        return TAP_Discounts::get_quote($room_id, $check_in, $check_out);
    }

    public static function get_min_stay_for_date($room_id, $date) {
        global $wpdb;
        $base = (int) get_post_meta($room_id, '_tap_room_min_stay', true) ?: 1;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT min_stay FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s AND min_stay IS NOT NULL",
            $room_id, $date
        ));
        return $row ? (int) $row : $base;
    }

    public static function get_max_min_stay_for_range($room_id, $check_in, $check_out) {
        $max = 0;
        $current = new DateTime($check_in);
        $end = new DateTime($check_out);
        while ($current < $end) {
            $ms = self::get_min_stay_for_date($room_id, $current->format('Y-m-d'));
            $max = max($max, $ms);
            $current->modify('+1 day');
        }
        return $max;
    }

    public static function is_date_available($room_id, $date) {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT is_blocked FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s",
            $room_id, $date
        ));
        if ($row !== null) return !$row;
        return true;
    }
}
