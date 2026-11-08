<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Transport logistics: vehicles, drivers, routes, trips, passengers, tracking.
 */

class TAP_Transport_Vehicles {
    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_vehicles'; }
    public static function create($data) {
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'agency_id' => (int) ($data['agency_id'] ?? 0),
            'type' => sanitize_key($data['type'] ?? 'land'),
            'plate' => sanitize_text_field($data['plate'] ?? ''),
            'model' => sanitize_text_field($data['model'] ?? ''),
            'capacity' => (int) ($data['capacity'] ?? 0),
            'status' => sanitize_key($data['status'] ?? 'active'),
            'driver_id' => !empty($data['driver_id']) ? (int) $data['driver_id'] : null,
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function update($id, $data) {
        global $wpdb; $t = self::table(); $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        foreach (['agency_id','type','plate','model','capacity','status','driver_id'] as $k) {
            if (isset($data[$k])) {
                if (in_array($k, ['agency_id','capacity','driver_id'], true)) $fields[$k] = (int) $data[$k];
                else $fields[$k] = sanitize_text_field($data[$k]);
            }
        }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id]);
        return true;
    }
    public static function delete($id) { global $wpdb; $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']); return true; }
    public static function get($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id)); }
    public static function for_agency($agency_id) { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE agency_id = %d ORDER BY id DESC", (int) $agency_id)) ?: []; }
}

class TAP_Transport_Drivers {
    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_drivers'; }
    public static function create($data) {
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'user_id' => (int) ($data['user_id'] ?? 0),
            'agency_id' => (int) ($data['agency_id'] ?? 0),
            'license_number' => sanitize_text_field($data['license_number'] ?? ''),
            'license_type' => sanitize_text_field($data['license_type'] ?? 'B1'),
            'status' => sanitize_key($data['status'] ?? 'active'),
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function update($id, $data) {
        global $wpdb; $t = self::table(); $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        foreach (['user_id','agency_id','license_number','license_type','status'] as $k) {
            if (isset($data[$k])) {
                if (in_array($k, ['user_id','agency_id'], true)) $fields[$k] = (int) $data[$k];
                else $fields[$k] = sanitize_text_field($data[$k]);
            }
        }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id]);
        return true;
    }
    public static function delete($id) { global $wpdb; $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']); return true; }
    public static function get($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id)); }
    public static function for_agency($agency_id) { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE agency_id = %d ORDER BY id DESC", (int) $agency_id)) ?: []; }
    public static function by_user($user_id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE user_id = %d", (int) $user_id)); }
}

class TAP_Transport_Routes {
    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_routes'; }
    public static function create($data) {
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'origin' => sanitize_text_field($data['origin'] ?? ''),
            'destination' => sanitize_text_field($data['destination'] ?? ''),
            'distance_km' => isset($data['distance_km']) ? (float) $data['distance_km'] : null,
            'estimated_duration' => isset($data['estimated_duration']) ? (int) $data['estimated_duration'] : null,
            'type' => sanitize_key($data['type'] ?? 'land'),
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function update($id, $data) {
        global $wpdb; $t = self::table(); $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        foreach (['origin','destination','distance_km','estimated_duration','type'] as $k) {
            if (isset($data[$k])) {
                if ($k === 'distance_km') $fields[$k] = (float) $data[$k];
                elseif ($k === 'estimated_duration') $fields[$k] = (int) $data[$k];
                else $fields[$k] = sanitize_text_field($data[$k]);
            }
        }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id]);
        return true;
    }
    public static function delete($id) { global $wpdb; $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']); return true; }
    public static function get($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id)); }
    public static function search($origin, $destination, $type = '') {
        global $wpdb; $t = self::table();
        $where = 'WHERE 1=1';
        $params = [];
        if ($origin) { $where .= ' AND origin LIKE %s'; $params[] = '%' . $wpdb->esc_like($origin) . '%'; }
        if ($destination) { $where .= ' AND destination LIKE %s'; $params[] = '%' . $wpdb->esc_like($destination) . '%'; }
        if ($type) { $where .= ' AND type = %s'; $params[] = sanitize_key($type); }
        $sql = "SELECT * FROM {$t} {$where} ORDER BY id DESC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, $params)) ?: []) : ($wpdb->get_results($sql) ?: []);
    }
}

class TAP_Transport_Trips {
    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_trips'; }
    public static function create($data) {
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'service_id' => (int) ($data['service_id'] ?? 0),
            'vehicle_id' => !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'driver_id' => !empty($data['driver_id']) ? (int) $data['driver_id'] : null,
            'route_id' => !empty($data['route_id']) ? (int) $data['route_id'] : null,
            'departure_time' => sanitize_text_field($data['departure_time'] ?? ''),
            'arrival_time' => !empty($data['arrival_time']) ? sanitize_text_field($data['arrival_time']) : null,
            'status' => sanitize_key($data['status'] ?? 'scheduled'),
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function update($id, $data) {
        global $wpdb; $t = self::table(); $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        foreach (['service_id','vehicle_id','driver_id','route_id','departure_time','arrival_time','status','notes'] as $k) {
            if (isset($data[$k])) {
                if (in_array($k, ['service_id','vehicle_id','driver_id','route_id'], true)) $fields[$k] = (int) $data[$k];
                elseif ($k === 'notes') $fields[$k] = sanitize_textarea_field($data[$k]);
                else $fields[$k] = sanitize_text_field($data[$k]);
            }
        }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id]);
        return true;
    }
    public static function delete($id) { global $wpdb; $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']); return true; }
    public static function get($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id)); }
    public static function for_agency($agency_id, $from = '', $to = '') {
        global $wpdb; $t = self::table();
        $where = "WHERE service_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tap_transport')";
        // Filter by agency via service meta.
        $services = get_posts(['post_type'=>'tap_transport','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_tap_trans_agency_id','meta_value'=>(int)$agency_id]);
        if (empty($services)) return [];
        $ids = implode(',', array_map('intval', $services));
        $where = "WHERE service_id IN ({$ids})";
        $params = [];
        if ($from) { $where .= ' AND departure_time >= %s'; $params[] = $from; }
        if ($to) { $where .= ' AND departure_time <= %s'; $params[] = $to; }
        $sql = "SELECT * FROM {$t} {$where} ORDER BY departure_time ASC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, $params)) ?: []) : ($wpdb->get_results($sql) ?: []);
    }
    public static function for_driver($driver_id, $from = '', $to = '') {
        global $wpdb; $t = self::table();
        $where = "WHERE driver_id = %d";
        $params = [(int) $driver_id];
        if ($from) { $where .= ' AND departure_time >= %s'; $params[] = $from; }
        if ($to) { $where .= ' AND departure_time <= %s'; $params[] = $to; }
        $sql = "SELECT * FROM {$t} {$where} ORDER BY departure_time ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }
    public static function add_passenger($trip_id, $booking_id, $name) {
        global $wpdb; $t = $wpdb->prefix . 'tap_trip_passengers';
        // Idempotency.
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE trip_id = %d AND booking_id = %d", (int) $trip_id, (int) $booking_id));
        if ($existing) return $existing;
        $wpdb->insert($t, [
            'trip_id' => (int) $trip_id,
            'booking_id' => (int) $booking_id,
            'passenger_name' => sanitize_text_field($name),
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function check_in_passenger($trip_id, $booking_id) {
        global $wpdb; $t = $wpdb->prefix . 'tap_trip_passengers';
        $wpdb->update($t, ['checked_in' => 1, 'checked_in_at' => current_time('mysql')], ['trip_id' => (int) $trip_id, 'booking_id' => (int) $booking_id], ['%d','%s'], ['%d','%d']);
        return true;
    }
    public static function set_status($trip_id, $status) {
        $allowed = ['scheduled','boarding','in_transit','completed','cancelled'];
        if (!in_array($status, $allowed, true)) return false;
        // Validate transition.
        $trip = self::get($trip_id);
        if (!$trip) return false;
        $transitions = [
            'scheduled' => ['boarding','cancelled'],
            'boarding' => ['in_transit','cancelled'],
            'in_transit' => ['completed','cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        if (!in_array($status, $transitions[$trip->status] ?? [], true)) return false;
        global $wpdb;
        $wpdb->update(self::table(), ['status' => $status], ['id' => (int) $trip_id], ['%s'], ['%d']);
        do_action('tap_trip_status_updated', (int) $trip_id, $status, $trip->status);
        return true;
    }
    public static function passengers($trip_id) {
        global $wpdb; $t = $wpdb->prefix . 'tap_trip_passengers';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE trip_id = %d ORDER BY id ASC", (int) $trip_id)) ?: [];
    }
}

// AJAX handlers for transport (registered in plugin main).
class TAP_Transport_Ajax {
    public static function update_status() {
        check_ajax_referer('tap_transport_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('No autorizado.', 'travel-agency-platform')], 403);
        $trip_id = absint($_POST['trip_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $trip = TAP_Transport_Trips::get($trip_id);
        if (!$trip) wp_send_json_error(['message' => __('Viaje no encontrado.', 'travel-agency-platform')], 404);
        // Permission: admin or assigned driver.
        $uid = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $driver = TAP_Transport_Drivers::by_user($uid);
        if (!$is_admin && (!$driver || (int) $driver->id !== (int) $trip->driver_id)) {
            wp_send_json_error(['message' => __('Sin permiso.', 'travel-agency-platform')], 403);
        }
        $ok = TAP_Transport_Trips::set_status($trip_id, $status);
        if (!$ok) wp_send_json_error(['message' => __('Transición no válida.', 'travel-agency-platform')]);
        wp_send_json_success(['message' => __('Estado actualizado.', 'travel-agency-platform'), 'status' => $status]);
    }

    public static function update_position() {
        check_ajax_referer('tap_transport_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('No autorizado.', 'travel-agency-platform')], 403);
        $trip_id = absint($_POST['trip_id'] ?? 0);
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        $speed = isset($_POST['speed']) ? (float) $_POST['speed'] : null;
        $heading = isset($_POST['heading']) ? (int) $_POST['heading'] : null;
        $trip = TAP_Transport_Trips::get($trip_id);
        if (!$trip) wp_send_json_error(['message' => __('Viaje no encontrado.', 'travel-agency-platform')], 404);
        $uid = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $driver = TAP_Transport_Drivers::by_user($uid);
        if (!$is_admin && (!$driver || (int) $driver->id !== (int) $trip->driver_id)) {
            wp_send_json_error(['message' => __('Sin permiso.', 'travel-agency-platform')], 403);
        }
        TAP_Transport_Tracking::record($trip_id, $lat, $lng, $speed, $heading);
        wp_send_json_success(['message' => 'ok']);
    }

    public static function checkin() {
        check_ajax_referer('tap_transport_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('No autorizado.', 'travel-agency-platform')], 403);
        $trip_id = absint($_POST['trip_id'] ?? 0);
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $trip = TAP_Transport_Trips::get($trip_id);
        if (!$trip) wp_send_json_error(['message' => __('Viaje no encontrado.', 'travel-agency-platform')], 404);
        $uid = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $driver = TAP_Transport_Drivers::by_user($uid);
        if (!$is_admin && (!$driver || (int) $driver->id !== (int) $trip->driver_id)) {
            wp_send_json_error(['message' => __('Sin permiso.', 'travel-agency-platform')], 403);
        }
        TAP_Transport_Trips::check_in_passenger($trip_id, $booking_id);
        wp_send_json_success(['message' => __('Check-in realizado.', 'travel-agency-platform')]);
    }

    public static function live() {
        check_ajax_referer('tap_transport_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('No autorizado.', 'travel-agency-platform')], 403);
        $trip_id = absint($_POST['trip_id'] ?? 0);
        $trip = TAP_Transport_Trips::get($trip_id);
        if (!$trip) wp_send_json_error(['message' => __('Viaje no encontrado.', 'travel-agency-platform')], 404);
        $pos = TAP_Transport_Tracking::latest($trip_id);
        $passengers = TAP_Transport_Trips::passengers($trip_id);
        wp_send_json_success([
            'trip' => $trip,
            'position' => $pos,
            'passengers' => $passengers,
        ]);
    }
}

// Driver dashboard shortcode.
class TAP_Transport_Driver_Dashboard {
    public static function init() {
        add_shortcode('tap_driver_dashboard', [__CLASS__, 'render']);
    }
    public static function render($atts = []) {
        if (!is_user_logged_in()) {
            return '<p class="tap-empty">' . esc_html__('Debes iniciar sesión.', 'travel-agency-platform') . '</p>';
        }
        $uid = get_current_user_id();
        $driver = TAP_Transport_Drivers::by_user($uid);
        if (!$driver) {
            return '<p class="tap-empty">' . esc_html__('No tienes un perfil de conductor.', 'travel-agency-platform') . '</p>';
        }
        $today = gmdate('Y-m-d');
        $trips = TAP_Transport_Trips::for_driver((int) $driver->id, $today . ' 00:00:00', $today . ' 23:59:59');
        $upcoming = TAP_Transport_Trips::for_driver((int) $driver->id, gmdate('Y-m-d', strtotime('+1 day')) . ' 00:00:00', gmdate('Y-m-d', strtotime('+7 days')) . ' 23:59:59');
        $completed = TAP_Transport_Trips::for_driver((int) $driver->id, gmdate('Y-m-d', strtotime('-30 days')) . ' 00:00:00', gmdate('Y-m-d', strtotime('-1 day')) . ' 23:59:59');
        // Find vehicle assigned to this driver.
        $vehicle = null;
        global $wpdb;
        $vid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TAP_Transport_Vehicles::table() . " WHERE driver_id = %d LIMIT 1", (int) $driver->id));
        if ($vid) $vehicle = TAP_Transport_Vehicles::get($vid);
        ob_start();
        ?>
        <div class="tap-driver-dashboard" id="tap-driver-dashboard">
            <div class="tap-driver-header">
                <h2><?php esc_html_e('Mi panel de conductor', 'travel-agency-platform'); ?></h2>
                <p class="tap-agency-meta"><?php echo esc_html($driver->license_number); ?> · <?php echo esc_html($driver->license_type); ?> · <?php echo $vehicle ? esc_html($vehicle->model . ' (' . $vehicle->plate . ')') : esc_html__('Sin vehículo asignado', 'travel-agency-platform'); ?></p>
            </div>

            <!-- Summary cards -->
            <div class="tap-driver-summary">
                <div class="tap-driver-summary-card">
                    <span class="tap-driver-summary-value"><?php echo count($trips); ?></span>
                    <span class="tap-driver-summary-label"><?php esc_html_e('Viajes hoy', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-driver-summary-card">
                    <span class="tap-driver-summary-value"><?php echo count($upcoming); ?></span>
                    <span class="tap-driver-summary-label"><?php esc_html_e('Próximos', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-driver-summary-card">
                    <span class="tap-driver-summary-value"><?php echo count($completed); ?></span>
                    <span class="tap-driver-summary-label"><?php esc_html_e('Completados (30d)', 'travel-agency-platform'); ?></span>
                </div>
                <div class="tap-driver-summary-card">
                    <span class="tap-driver-summary-value"><?php echo $driver->status === 'active' ? esc_html__('Activo', 'travel-agency-platform') : esc_html__('Inactivo', 'travel-agency-platform'); ?></span>
                    <span class="tap-driver-summary-label"><?php esc_html_e('Estado', 'travel-agency-platform'); ?></span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tap-driver-tabs">
                <button type="button" class="tap-driver-tab is-active" data-tab="today"><?php esc_html_e('Hoy', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-driver-tab" data-tab="upcoming"><?php esc_html_e('Próximos', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-driver-tab" data-tab="history"><?php esc_html_e('Historial', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-driver-tab" data-tab="profile"><?php esc_html_e('Perfil', 'travel-agency-platform'); ?></button>
            </div>

            <!-- Today -->
            <div class="tap-driver-tab-content" data-tab="today">
                <h3><?php esc_html_e('Mis viajes de hoy', 'travel-agency-platform'); ?></h3>
                <?php if (!$trips): ?>
                    <p class="tap-empty"><?php esc_html_e('No tienes viajes asignados para hoy.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                    <?php foreach ($trips as $trip): ?>
                        <?php $passengers = TAP_Transport_Trips::passengers((int) $trip->id); ?>
                        <div class="tap-driver-trip" data-trip="<?php echo (int) $trip->id; ?>">
                            <div class="tap-driver-trip-header">
                                <h4><?php echo esc_html(get_the_title((int) $trip->service_id)); ?></h4>
                                <span class="tap-status tap-status-<?php echo esc_attr($trip->status); ?>"><?php echo esc_html($trip->status); ?></span>
                            </div>
                            <div class="tap-driver-trip-info">
                                <span>🕐 <?php echo esc_html(substr($trip->departure_time, 11, 5)); ?> → <?php echo esc_html($trip->arrival_time ? substr($trip->arrival_time, 11, 5) : '—'); ?></span>
                                <?php if ($trip->route_id): ?>
                                    <?php $route = TAP_Transport_Routes::get((int) $trip->route_id); ?>
                                    <?php if ($route): ?>
                                        <span>📍 <?php echo esc_html($route->origin); ?> → <?php echo esc_html($route->destination); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div id="tap-trip-map-<?php echo (int) $trip->id; ?>" style="height:200px;border-radius:8px;margin:10px 0;" class="tap-driver-map"></div>
                            <div class="tap-driver-passengers">
                                <h5><?php esc_html_e('Pasajeros', 'travel-agency-platform'); ?> (<?php echo count($passengers); ?>)</h5>
                                <?php if (!$passengers): ?>
                                    <p class="tap-empty"><?php esc_html_e('Sin pasajeros.', 'travel-agency-platform'); ?></p>
                                <?php else: ?>
                                    <ul class="tap-driver-passenger-list">
                                        <?php foreach ($passengers as $ps): ?>
                                            <li>
                                                <span><?php echo esc_html($ps->passenger_name); ?></span>
                                                <?php if ($ps->checked_in): ?>
                                                    <span class="tap-checkin-done">✓ <?php echo esc_html($ps->checked_in_at); ?></span>
                                                <?php else: ?>
                                                    <button type="button" class="tap-btn tap-btn-sm tap-checkin" data-trip="<?php echo (int) $trip->id; ?>" data-booking="<?php echo (int) $ps->booking_id; ?>"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></button>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <div class="tap-driver-actions">
                                <?php if ('scheduled' === $trip->status): ?>
                                    <button type="button" class="tap-btn tap-btn-primary tap-trip-status" data-trip="<?php echo (int) $trip->id; ?>" data-status="boarding"><?php esc_html_e('Iniciar abordaje', 'travel-agency-platform'); ?></button>
                                <?php elseif ('boarding' === $trip->status): ?>
                                    <button type="button" class="tap-btn tap-btn-primary tap-trip-status" data-trip="<?php echo (int) $trip->id; ?>" data-status="in_transit"><?php esc_html_e('Iniciar viaje', 'travel-agency-platform'); ?></button>
                                <?php elseif ('in_transit' === $trip->status): ?>
                                    <button type="button" class="tap-btn tap-btn-primary tap-trip-status" data-trip="<?php echo (int) $trip->id; ?>" data-status="completed"><?php esc_html_e('Completar viaje', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                                <button type="button" class="tap-btn tap-btn-outline tap-btn-sm tap-update-position" data-trip="<?php echo (int) $trip->id; ?>"><?php esc_html_e('Actualizar posición', 'travel-agency-platform'); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Upcoming -->
            <div class="tap-driver-tab-content" data-tab="upcoming" style="display:none;">
                <h3><?php esc_html_e('Próximos viajes', 'travel-agency-platform'); ?></h3>
                <?php if (!$upcoming): ?>
                    <p class="tap-empty"><?php esc_html_e('No tienes viajes programados.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                    <ul class="tap-driver-trip-list">
                        <?php foreach ($upcoming as $trip): ?>
                            <li>
                                <strong><?php echo esc_html(get_the_title((int) $trip->service_id)); ?></strong>
                                <span><?php echo esc_html($trip->departure_time); ?></span>
                                <span class="tap-status tap-status-<?php echo esc_attr($trip->status); ?>"><?php echo esc_html($trip->status); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- History -->
            <div class="tap-driver-tab-content" data-tab="history" style="display:none;">
                <h3><?php esc_html_e('Historial (últimos 30 días)', 'travel-agency-platform'); ?></h3>
                <?php if (!$completed): ?>
                    <p class="tap-empty"><?php esc_html_e('No hay viajes completados.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                    <ul class="tap-driver-trip-list">
                        <?php foreach ($completed as $trip): ?>
                            <li>
                                <strong><?php echo esc_html(get_the_title((int) $trip->service_id)); ?></strong>
                                <span><?php echo esc_html($trip->departure_time); ?></span>
                                <span class="tap-status tap-status-completed"><?php esc_html_e('Completado', 'travel-agency-platform'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Profile -->
            <div class="tap-driver-tab-content" data-tab="profile" style="display:none;">
                <h3><?php esc_html_e('Mi perfil', 'travel-agency-platform'); ?></h3>
                <div class="tap-driver-profile">
                    <p><strong><?php esc_html_e('Licencia:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($driver->license_number); ?> (<?php echo esc_html($driver->license_type); ?>)</p>
                    <p><strong><?php esc_html_e('Estado:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($driver->status); ?></p>
                    <?php if ($vehicle): ?>
                        <p><strong><?php esc_html_e('Vehículo:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($vehicle->model); ?> (<?php echo esc_html($vehicle->plate); ?>) — <?php echo (int) $vehicle->capacity; ?> <?php esc_html_e('pasajeros', 'travel-agency-platform'); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('Sin vehículo asignado. Contacta a tu agencia.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                    <p><strong><?php esc_html_e('Agencia:', 'travel-agency-platform'); ?></strong> <?php echo esc_html(get_the_title((int) $driver->agency_id)); ?></p>
                </div>
            </div>

            <script>
            (function(){
                var nonce = '<?php echo esc_js(wp_create_nonce('tap_transport_nonce')); ?>';
                // Tabs
                document.querySelectorAll('.tap-driver-tab').forEach(function(tab){
                    tab.addEventListener('click', function(){
                        document.querySelectorAll('.tap-driver-tab').forEach(function(t){ t.classList.remove('is-active'); });
                        document.querySelectorAll('.tap-driver-tab-content').forEach(function(c){ c.style.display = 'none'; });
                        tab.classList.add('is-active');
                        var target = document.querySelector('.tap-driver-tab-content[data-tab="' + tab.getAttribute('data-tab') + '"]');
                        if (target) target.style.display = '';
                    });
                });
                // Status buttons
                document.querySelectorAll('.tap-trip-status').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tid = btn.getAttribute('data-trip');
                        var st = btn.getAttribute('data-status');
                        var fd = new FormData();
                        fd.append('action', 'tap_transport_status');
                        fd.append('trip_id', tid);
                        fd.append('status', st);
                        fd.append('nonce', nonce);
                        fetch(ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){ if (res && res.success) location.reload(); else alert(res.data && res.data.message ? res.data.message : 'Error'); });
                    });
                });
                // Check-in buttons
                document.querySelectorAll('.tap-checkin').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tid = btn.getAttribute('data-trip');
                        var bid = btn.getAttribute('data-booking');
                        var fd = new FormData();
                        fd.append('action', 'tap_transport_checkin');
                        fd.append('trip_id', tid);
                        fd.append('booking_id', bid);
                        fd.append('nonce', nonce);
                        fetch(ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){ if (res && res.success) location.reload(); else alert(res.data && res.data.message ? res.data.message : 'Error'); });
                    });
                });
                // Manual position update
                document.querySelectorAll('.tap-update-position').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if (!navigator.geolocation) { alert('Geolocalización no disponible.'); return; }
                        var tid = btn.getAttribute('data-trip');
                        navigator.geolocation.getCurrentPosition(function(pos){
                            var fd = new FormData();
                            fd.append('action', 'tap_transport_position');
                            fd.append('trip_id', tid);
                            fd.append('lat', pos.coords.latitude);
                            fd.append('lng', pos.coords.longitude);
                            if (pos.coords.speed) fd.append('speed', pos.coords.speed);
                            if (pos.coords.heading) fd.append('heading', pos.coords.heading);
                            fd.append('nonce', nonce);
                            fetch(ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                            .then(function(r){ return r.json(); })
                            .then(function(res){ alert(res && res.success ? 'Posición actualizada.' : 'Error'); });
                        });
                    });
                });
                // Load maps
                if (typeof L !== 'undefined') {
                    document.querySelectorAll('.tap-driver-map').forEach(function(el){
                        var tripId = el.id.replace('tap-trip-map-', '');
                        var map = L.map(el).setView([9.93, -84.08], 8);
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap'}).addTo(map);
                        var fd = new FormData();
                        fd.append('action', 'tap_transport_live');
                        fd.append('trip_id', tripId);
                        fd.append('nonce', nonce);
                        fetch(ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success && res.data.position) {
                                var pos = res.data.position;
                                L.marker([pos.lat, pos.lng]).addTo(map).bindPopup('Tu posición');
                                map.setView([pos.lat, pos.lng], 12);
                            }
                        });
                    });
                }
                // Auto-track position every 30s for in-transit trips
                if (navigator.geolocation) {
                    setInterval(function(){
                        var tripEl = document.querySelector('.tap-driver-trip');
                        if (!tripEl) return;
                        var tid = tripEl.getAttribute('data-trip');
                        navigator.geolocation.getCurrentPosition(function(pos){
                            var fd = new FormData();
                            fd.append('action', 'tap_transport_position');
                            fd.append('trip_id', tid);
                            fd.append('lat', pos.coords.latitude);
                            fd.append('lng', pos.coords.longitude);
                            if (pos.coords.speed) fd.append('speed', pos.coords.speed);
                            if (pos.coords.heading) fd.append('heading', pos.coords.heading);
                            fd.append('nonce', nonce);
                            fetch(ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'});
                        });
                    }, 30000);
                }
            })();
            </script>
        </div>
        <style>
        .tap-driver-dashboard { max-width: 900px; margin: 0 auto; }
        .tap-driver-header { margin-bottom: 20px; }
        .tap-driver-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
        .tap-driver-tab { padding: 8px 16px; border: none; background: none; cursor: pointer; font-weight: 600; color: #64748b; border-radius: 8px; transition: all .15s ease; }
        .tap-driver-tab.is-active { background: #0f766e; color: #fff; }
        .tap-driver-trip { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .tap-driver-trip-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .tap-driver-trip-header h4 { margin: 0; font-size: 18px; font-weight: 700; color: #1e293b; }
        .tap-driver-trip-info { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: #475569; margin-bottom: 12px; }
        .tap-driver-passengers h5 { margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #334155; }
        .tap-driver-passenger-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .tap-driver-passenger-list li { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-radius: 8px; font-size: 13px; }
        .tap-checkin-done { color: #0f766e; font-weight: 600; font-size: 12px; }
        .tap-driver-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .tap-driver-trip-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .tap-driver-trip-list li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 14px; background: #f8fafc; border-radius: 10px; font-size: 13px; }
        </style>
        <?php
        return ob_get_clean();
    }
}

class TAP_Transport_Tracking {
    public static function table() { global $wpdb; return $wpdb->prefix . 'tap_trip_tracking'; }
    public static function record($trip_id, $lat, $lng, $speed = null, $heading = null) {
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'trip_id' => (int) $trip_id,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'speed' => $speed !== null ? (float) $speed : null,
            'heading' => $heading !== null ? (int) $heading : null,
        ]);
        return (int) $wpdb->insert_id;
    }
    public static function latest($trip_id) {
        global $wpdb; $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE trip_id = %d ORDER BY created_at DESC LIMIT 1", (int) $trip_id));
    }
    public static function history($trip_id, $limit = 50) {
        global $wpdb; $t = self::table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE trip_id = %d ORDER BY created_at DESC LIMIT %d", (int) $trip_id, (int) $limit)) ?: [];
    }
}