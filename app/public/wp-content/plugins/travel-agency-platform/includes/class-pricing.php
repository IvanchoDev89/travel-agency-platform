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
        $room_id = (int) $_POST['room_id'];
        $year    = (int) $_POST['year'];
        $month   = (int) $_POST['month'];

        $base_price = (float) get_post_meta($room_id, '_tap_room_price_per_night', true) ?: 0;
        $base_min   = (int) get_post_meta($room_id, '_tap_room_min_stay', true) ?: 1;

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
            $days[] = $day;
        }

        wp_send_json([
            'success'      => true,
            'days'         => $days,
            'base_price'   => $base_price,
            'base_min'     => $base_min,
            'year'         => $year,
            'month'        => $month,
            'days_in_month' => $days_in_month,
        ]);
    }

    public static function ajax_save_pricing() {
        check_ajax_referer('tap_pricing', 'nonce');
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            wp_send_json(['success' => false, 'message' => 'No autorizado']);
        }

        $room_id = (int) $_POST['room_id'];
        $date    = sanitize_text_field($_POST['date']);

        if (!$room_id || !$date) {
            wp_send_json(['success' => false, 'message' => 'Datos inválidos']);
        }

        // The pricing editor only manages rooms of advertisements the agency
        // owns; reject rooms that belong to someone else or to a non-accommodation.
        $acc_id = (int) get_post_meta($room_id, '_tap_room_accommodation_id', true);
        if (!$acc_id || get_post_type($acc_id) !== 'tap_accommodation') {
            wp_send_json(['success' => false, 'message' => 'Habitación inválida']);
        }
        if (!current_user_can('manage_options')) {
            $agency   = (int) get_post_meta($acc_id, '_tap_acc_agency_id', true);
            $my_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
            if (!$agency || !$my_agency || $my_agency !== $agency) {
                wp_send_json(['success' => false, 'message' => 'No autorizado']);
            }
        }

        $price    = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;
        $min_stay = isset($_POST['min_stay']) && $_POST['min_stay'] !== '' ? (int) $_POST['min_stay'] : null;
        $blocked  = isset($_POST['is_blocked']) ? (int) $_POST['is_blocked'] : 0;
        $label    = isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '';

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_daily_pricing WHERE room_id = %d AND date = %s",
            $room_id, $date
        ));

        if ($price === null && $min_stay === null && !$blocked && !$label) {
            if ($existing) {
                $wpdb->delete($wpdb->prefix . 'tap_daily_pricing', ['room_id' => $room_id, 'date' => $date]);
            }
            wp_send_json(['success' => true, 'action' => 'deleted']);
            return;
        }

        $data = [
            'room_id' => $room_id,
            'date'    => $date,
            'price'   => $price,
            'min_stay' => $min_stay,
            'is_blocked' => $blocked,
            'label'   => $label,
        ];

        if ($existing) {
            $wpdb->update($wpdb->prefix . 'tap_daily_pricing', $data, ['id' => $existing]);
        } else {
            $wpdb->insert($wpdb->prefix . 'tap_daily_pricing', $data);
        }

        wp_send_json(['success' => true, 'action' => $existing ? 'updated' : 'created', 'date' => $date]);
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
