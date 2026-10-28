<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Shuttle matching: group passengers by route and date, dynamic pricing.
 */
class TAP_Shuttle {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_shuttle_groups';
    }

    /** Route key from a transport service. */
    public static function route_key($service_id) {
        $from = (string) get_post_meta($service_id, '_tap_trans_from', true);
        $to   = (string) get_post_meta($service_id, '_tap_trans_to', true);
        return sanitize_title($from . '-' . $to);
    }

    /** Find or create a shuttle group for a service/date. */
    public static function get_or_create($service_id, $travel_date) {
        global $wpdb;
        $t = self::table();
        $route = self::route_key($service_id);
        $max   = (int) get_post_meta($service_id, '_tap_trans_capacity', true);
        if ($max <= 0) $max = 10;
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE service_id = %d AND travel_date = %s AND status = 'open' LIMIT 1",
            (int) $service_id, $travel_date
        ));
        if ($group) return $group;
        $wpdb->insert($t, [
            'service_id'       => (int) $service_id,
            'route_key'        => $route,
            'travel_date'      => $travel_date,
            'max_passengers'   => $max,
            'booked_passengers'=> 0,
            'status'           => 'open',
            'price_per_seat'   => (float) get_post_meta($service_id, '_tap_trans_price', true),
        ]);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $wpdb->insert_id));
    }

    /** Add a passenger to a shuttle group. Returns true if added. */
    public static function add_passenger($group_id, $adults = 1) {
        global $wpdb;
        $t = self::table();
        $g = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $group_id));
        if (!$g || $g->status !== 'open') return false;
        $new = (int) $g->booked_passengers + (int) $adults;
        if ($new > (int) $g->max_passengers) return false;
        $wpdb->update($t, [
            'booked_passengers' => $new,
            'status'            => ($new >= (int) $g->max_passengers) ? 'full' : 'open',
        ], ['id' => (int) $group_id]);
        return true;
    }

    /** Dynamic price per seat based on occupancy. */
    public static function price_per_seat($group) {
        if (!$group) return 0;
        $base = (float) $group->price_per_seat;
        if ($base <= 0) return 0;
        $max = (int) $group->max_passengers;
        $booked = (int) $group->booked_passengers;
        if ($max <= 0) return $base;
        $occ = $booked / $max;
        // Higher occupancy → higher price (up to +30%).
        if ($occ >= 0.8) return round($base * 1.30, 2);
        if ($occ >= 0.5) return round($base * 1.15, 2);
        return $base;
    }

    /** Find matching shuttles for a route/date. */
    public static function find($from, $to, $date) {
        global $wpdb;
        $t = self::table();
        $route = sanitize_title($from . '-' . $to);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE route_key = %s AND travel_date = %s AND status IN ('open','full') ORDER BY created_at ASC",
            $route, $date
        ));
    }

    /** Init: REST endpoints. */
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('tap/v1', '/shuttle/find', [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'rest_find'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('tap/v1', '/shuttle/join', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_join'],
                'permission_callback' => function () {
					return is_user_logged_in(); },
            ]);
        });
    }

    public static function rest_find($request) {
        $params = $request->get_params();
        $from = sanitize_text_field($params['from'] ?? '');
        $to   = sanitize_text_field($params['to'] ?? '');
        $date = sanitize_text_field($params['date'] ?? '');
        if (!$from || !$to || !$date) {
            return new WP_Error('invalid', __('Parámetros inválidos.', 'travel-agency-platform'), ['status' => 400]);
        }
        $groups = self::find($from, $to, $date);
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'id'                => (int) $g->id,
                'service_id'        => (int) $g->service_id,
                'max_passengers'    => (int) $g->max_passengers,
                'booked_passengers' => (int) $g->booked_passengers,
                'available'         => (int) $g->max_passengers - (int) $g->booked_passengers,
                'price_per_seat'    => self::price_per_seat($g),
                'status'            => $g->status,
            ];
        }
        return rest_ensure_response($out);
    }

    public static function rest_join($request) {
        $params = $request->get_params();
        $group_id = absint($params['group_id'] ?? 0);
        $adults   = max(1, absint($params['adults'] ?? 1));
        if (!$group_id) {
            return new WP_Error('invalid', __('Grupo inválido.', 'travel-agency-platform'), ['status' => 400]);
        }
        $ok = self::add_passenger($group_id, $adults);
        if (!$ok) {
            return new WP_Error('full', __('El shuttle está completo.', 'travel-agency-platform'), ['status' => 400]);
        }
        return rest_ensure_response(['ok' => true]);
    }
}
