<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for service type slugs.
 *
 * Historically five classes declared slightly different lists, producing
 "bookable but not reviewable" states. Every validator/query/renderer should
 * consume these constants instead of a local array.
 */
class TAP_Service_Types {

    const BOOKABLE = [
        'tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport',
        'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment',
    ];

    const REVIEWABLE = [
        'tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport',
        'tap_car_rental', 'tap_boat', 'tap_package',
    ];

    public static function all() {
        return self::BOOKABLE;
    }

    public static function bookable() {
        return self::BOOKABLE;
    }

    public static function reviewable() {
        return self::REVIEWABLE;
    }

    public static function is_bookable($type) {
        return in_array((string) $type, self::BOOKABLE, true);
    }

    /** Spanish label for a service type (msgids come from TAP_Post_Types). */
    public static function label($type) {
        $labels = [
            'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_experience'    => __('Experiencia', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Renta de auto', 'travel-agency-platform'),
            'tap_boat'          => __('Barco', 'travel-agency-platform'),
            'tap_package'       => __('Paquete', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipo', 'travel-agency-platform'),
        ];
        return $labels[(string) $type] ?? (string) $type;
    }
}
