<?php
defined('ABSPATH') || exit;

/**
 * Reporting queries for the back office dashboard: aggregate booking\n * counters, the monthly series, the status distribution and the breakdown by\n * service type. Read-only (wpdb get_results/get_var), with no lifecycle or\n * money logic, so they are kept out of TAP_Booking.
 */
class TAP_Booking_Stats {
    public static function get_status_distribution() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table GROUP BY status");
        $out = ['pending' => 0, 'request' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r->status])) $out[$r->status] = (int) $r->cnt;
        }
        return $out;
    }

    public static function get_service_type_breakdown() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT service_type, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as revenue
            FROM {$wpdb->prefix}tap_bookings
            WHERE status NOT IN ('cancelled','refunded')
            GROUP BY service_type ORDER BY cnt DESC"
        );
        return $rows;
    }


    public static function get_monthly_series($months = 12) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $rows = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as bookings,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END), 0) as revenue,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') AND commission_status <> 'void' THEN commission_amount ELSE 0 END), 0) as commission,
                    COALESCE(SUM(CASE WHEN status IN ('cancelled','refunded') THEN 1 ELSE 0 END), 0) as cancelled
            FROM $table
            GROUP BY month ORDER BY month ASC"
        );
        $by_month = [];
        foreach ($rows as $r) $by_month[$r->month] = $r;

        $labels = [];
        $bookings = [];
        $revenue = [];
        $commission = [];
        $cancelled = [];
        $ref = new DateTime('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = (clone $ref)->modify("-{$i} months")->format('Y-m');
            $labels[] = date_i18n('M Y', strtotime($m . '-01'));
            $row = $by_month[$m] ?? null;
            $bookings[] = $row ? (int) $row->bookings : 0;
            $revenue[] = $row ? (float) $row->revenue : 0;
            $commission[] = $row ? (float) $row->commission : 0;
            $cancelled[] = $row ? (int) $row->cancelled : 0;
        }

        return [
            'labels'     => $labels,
            'bookings'   => $bookings,
            'revenue'    => $revenue,
            'commission' => $commission,
            'cancelled'  => $cancelled,
        ];
    }


    public static function get_booking_stats($agency_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_bookings';
        $where = $agency_id ? $wpdb->prepare('WHERE agency_id = %d', $agency_id) : 'WHERE 1=1';

        return [
            'total'      => $wpdb->get_var("SELECT COUNT(*) FROM $table $where"),
            'pending'    => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'pending'"),
            'request'    => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'request'"),
            'confirmed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'confirmed'"),
            'completed'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'completed'"),
            'cancelled'  => $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND status = 'cancelled'"),
            'revenue'    => $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'commission' => $wpdb->get_var("SELECT COALESCE(SUM(CASE WHEN commission_status <> 'void' THEN commission_amount ELSE 0 END), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'net'        => $wpdb->get_var("SELECT COALESCE(SUM(total_amount - COALESCE(booking_fee,0) - CASE WHEN commission_status <> 'void' THEN commission_amount ELSE 0 END), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
            'fees'       => $wpdb->get_var("SELECT COALESCE(SUM(booking_fee), 0) FROM $table $where AND status NOT IN ('cancelled', 'refunded')"),
        ];
    }
}
