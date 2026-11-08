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
    public static function update_status($trip_id, $status) {
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