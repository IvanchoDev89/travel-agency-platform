<?php
defined('ABSPATH') || exit;

/**
 * Yield pricing rules engine.
 *
 * A rule applies a modifier (percent or fixed amount) to a base price when its
 * condition matches (early bird, last minute, weekend, seasonal, occupancy).
 * Rules are evaluated by priority (higher first); the first matching rule wins.
 */
class TAP_Pricing_Rules {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_pricing_rules';
    }

    /** Rule types supported. */
    public static function rule_types() {
        return [
            'early_bird'   => __('Early bird (reserva con anticipación)', 'travel-agency-platform'),
            'last_minute'  => __('Last minute (reserva cercana a la fecha)', 'travel-agency-platform'),
            'weekend'      => __('Fin de semana', 'travel-agency-platform'),
            'seasonal'     => __('Temporada alta/baja', 'travel-agency-platform'),
            'occupancy'    => __('Ocupación (alta demanda)', 'travel-agency-platform'),
        ];
    }

    /** Get active rules for a service, ordered by priority desc. */
    public static function for_service($service_type, $service_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE service_type = %s AND service_id = %d AND is_active = 1 ORDER BY priority DESC, id ASC",
            $service_type, (int) $service_id
        ));
    }

    /** Create or update a rule. Returns rule id or WP_Error. */
    public static function save($data) {
        global $wpdb;
        $t = self::table();
        $row = [
            'service_type'   => sanitize_key($data['service_type'] ?? ''),
            'service_id'     => (int) ($data['service_id'] ?? 0),
            'rule_type'      => sanitize_key($data['rule_type'] ?? ''),
            'condition_json' => wp_json_encode($data['condition'] ?? []),
            'modifier_type'  => in_array(($data['modifier_type'] ?? 'percent'), ['percent', 'fixed'], true) ? $data['modifier_type'] : 'percent',
            'modifier_value' => (float) ($data['modifier_value'] ?? 0),
            'priority'       => (int) ($data['priority'] ?? 0),
            'is_active'      => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ];
        if (!$row['service_type'] || !$row['service_id'] || !isset(self::rule_types()[$row['rule_type']])) {
            return new WP_Error('invalid_rule', __('Regla inválida.', 'travel-agency-platform'));
        }
        if (isset($data['id']) && (int) $data['id'] > 0) {
            $wpdb->update($t, $row, ['id' => (int) $data['id']]);
            return (int) $data['id'];
        }
        $wpdb->insert($t, $row);
        return (int) $wpdb->insert_id;
    }

    /** Delete a rule. */
    public static function delete($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']);
    }

    /**
     * Evaluate rules for a given context and return the first matching modifier.
     *
     * @param string $service_type
     * @param int    $service_id
     * @param array  $context {date, booking_date, occupancy, ...}
     * @return array{type:string,value:float}|null
     */
    public static function evaluate($service_type, $service_id, array $context) {
        $rules = self::for_service($service_type, $service_id);
        foreach ($rules as $rule) {
            $cond = json_decode($rule->condition_json, true);
            if (!is_array($cond)) $cond = [];
            if (self::matches($rule->rule_type, $cond, $context)) {
                return ['type' => $rule->modifier_type, 'value' => (float) $rule->modifier_value];
            }
        }
        return null;
    }

    /** Apply a modifier to a base price. */
    public static function apply_modifier($base, $modifier) {
        if (!$modifier) return (float) $base;
        if ('percent' === $modifier['type']) {
            return round((float) $base * (1 + $modifier['value'] / 100), 2);
        }
        return round((float) $base + $modifier['value'], 2);
    }

    /** Quote a price for a service on a given date. */
    public static function quote($service_type, $service_id, $base_price, array $context = []) {
        $modifier = self::evaluate($service_type, $service_id, $context);
        return self::apply_modifier($base_price, $modifier);
    }

    /** Check if a rule condition matches the context. */
    private static function matches($type, array $cond, array $ctx) {
        switch ($type) {
            case 'early_bird':
                $days = (int) ($cond['days_before'] ?? 0);
                $booking_date = isset($ctx['booking_date']) ? strtotime($ctx['booking_date']) : time();
                $service_date = isset($ctx['date']) ? strtotime($ctx['date']) : time();
                $diff = (int) floor(($service_date - $booking_date) / DAY_IN_SECONDS);
                return $diff >= $days;
            case 'last_minute':
                $days = (int) ($cond['days_before'] ?? 0);
                $booking_date = isset($ctx['booking_date']) ? strtotime($ctx['booking_date']) : time();
                $service_date = isset($ctx['date']) ? strtotime($ctx['date']) : time();
                $diff = (int) floor(($service_date - $booking_date) / DAY_IN_SECONDS);
                return $diff <= $days && $diff >= 0;
            case 'weekend':
                $dow = (int) date('N', isset($ctx['date']) ? strtotime($ctx['date']) : time());
                return in_array($dow, [6, 7], true);
            case 'seasonal':
                $months = (array) ($cond['months'] ?? []);
                $m = (int) date('n', isset($ctx['date']) ? strtotime($ctx['date']) : time());
                return in_array($m, $months, true);
            case 'occupancy':
                $threshold = (float) ($cond['occupancy_pct'] ?? 80);
                $occ = (float) ($ctx['occupancy_pct'] ?? 0);
                return $occ >= $threshold;
        }
        return false;
    }
}
