<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for status labels shown to users.
 *
 * Source strings are Spanish; catalogs translate them. Every renderer (admin
 * tables, voucher, checkout, metaboxes, emails) must use these helpers so a
 * new status is added once instead of drifting across a dozen templates.
 */
class TAP_Labels {

    public static function booking_statuses() {
        return [
            'pending'   => __('Pendiente', 'travel-agency-platform'),
            'request'   => __('Solicitud', 'travel-agency-platform'),
            'confirmed' => __('Confirmada', 'travel-agency-platform'),
            'cancelled' => __('Cancelada', 'travel-agency-platform'),
            'completed' => __('Completada', 'travel-agency-platform'),
            'refunded'  => __('Reembolsada', 'travel-agency-platform'),
            'no_show'   => __('No show', 'travel-agency-platform'),
        ];
    }

    public static function booking_status($status) {
        $labels = self::booking_statuses();
        return $labels[(string) $status] ?? (string) $status;
    }

    public static function payment_statuses() {
        return [
            'pending'  => __('Pendiente', 'travel-agency-platform'),
            'paid'     => __('Pagada', 'travel-agency-platform'),
            'partial'  => __('Parcial', 'travel-agency-platform'),
            'refunded' => __('Reembolsada', 'travel-agency-platform'),
            'failed'   => __('Fallida', 'travel-agency-platform'),
        ];
    }

    public static function payment_status($status) {
        $labels = self::payment_statuses();
        return $labels[(string) $status] ?? (string) $status;
    }
}
