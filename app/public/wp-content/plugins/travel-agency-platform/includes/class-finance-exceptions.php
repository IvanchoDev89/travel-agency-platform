<?php
defined('ABSPATH') || exit;

/**
 * Financial exceptions: a single read-only view of every money anomaly that
 * needs a human decision. It never mutates data — the underlying ledgers stay
 * authoritative and this page only makes drift visible.
 */
class TAP_Finance_Exceptions {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page'], 38);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Excepciones financieras', 'travel-agency-platform'),
            __('Excepciones financieras', 'travel-agency-platform'),
            'tap_manage_commissions',
            'tap-finance-exceptions',
            [__CLASS__, 'render']
        );
    }

    /** All anomalies, each row with a type, booking and a short detail. */
    public static function issues() {
        global $wpdb;
        $b = $wpdb->prefix . 'tap_bookings';
        $out = [];

        // Invariants verified by the daily money reconciliation cron.
        if (class_exists('TAP_Money_Ledger')) {
            foreach ((array) TAP_Money_Ledger::stored_issues() as $issue) {
                $out[] = $issue;
            }
        }

        $meta_issues = [
            '_tap_overpayment'    => __('Sobrepago', 'travel-agency-platform'),
            '_tap_payment_mismatch' => __('Importe discrepante', 'travel-agency-platform'),
        ];
        foreach ($meta_issues as $key => $label) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, b.booking_code, b.total_amount, b.currency, pm.meta_value detail
                   FROM {$b} b
                   JOIN {$wpdb->postmeta} pm ON pm.post_id = b.id AND pm.meta_key = %s
                  ORDER BY b.id DESC LIMIT 50",
                $key
            ));
            foreach ((array) $rows as $r) {
                $out[] = [
                    'type'   => $label,
                    'booking'=> (int) $r->id,
                    'code'   => (string) $r->booking_code,
                    'detail' => (string) $r->detail,
                ];
            }
        }

        $failed = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id, b.booking_code, pm.meta_value detail
               FROM {$b} b
               JOIN {$wpdb->postmeta} pm ON pm.post_id = b.id AND pm.meta_key = '_tap_refund_error'
              WHERE pm.meta_value <> '' AND pm.meta_value IS NOT NULL
              ORDER BY b.id DESC LIMIT 50"
        ));
        foreach ((array) $failed as $r) {
            $out[] = [
                'type'   => __('Reembolso fallido', 'travel-agency-platform'),
                'booking'=> (int) $r->id,
                'code'   => (string) $r->booking_code,
                'detail' => (string) $r->detail,
            ];
        }

        $stale = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        $partial = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_code, amount_paid, total_amount FROM {$b}
              WHERE payment_status = 'partial' AND created_at < %s
              ORDER BY id DESC LIMIT 50",
            $stale
        ));
        foreach ((array) $partial as $r) {
            $out[] = [
                'type'   => __('Pago parcial antiguo', 'travel-agency-platform'),
                'booking'=> (int) $r->id,
                'code'   => (string) $r->booking_code,
                'detail' => TAP_Currency::fmt((float) $r->amount_paid, null, (string) ($r->currency ?? null))
                    . ' / ' . TAP_Currency::fmt((float) $r->total_amount, null, (string) ($r->currency ?? null)),
            ];
        }

        foreach ([
            [$wpdb->prefix . 'tap_commission_payments', 'created_at', __('Liquidación de comisión pendiente', 'travel-agency-platform')],
            [$wpdb->prefix . 'tap_agency_payouts', 'requested_at', __('Liquidación de agencia pendiente', 'travel-agency-platform')],
            [$wpdb->prefix . 'tap_affiliate_payouts', 'requested_at', __('Liquidación de afiliado pendiente', 'travel-agency-platform')],
        ] as $spec) {
            // Table and column names are internal constants derived from $wpdb->prefix.
            $table = $spec[0];
            $date_col = $spec[1];
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are internal
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, amount, {$date_col} AS created_at FROM {$table} WHERE status = 'pending' AND {$date_col} < %s ORDER BY id DESC LIMIT 50",
                $stale
            ));
            foreach ((array) $rows as $r) {
                $out[] = [
                    'type'   => $spec[2],
                    'booking'=> 0,
                    'code'   => '#' . (int) $r->id,
                    'detail' => TAP_Currency::fmt((float) $r->amount),
                ];
            }
        }

        return $out;
    }

    public static function render() {
        if (!current_user_can('tap_manage_commissions')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        $issues = self::issues();
        echo '<div class="wrap"><h1>' . esc_html__('Excepciones financieras', 'travel-agency-platform') . '</h1>';
        echo '<p class="description">' . esc_html__('Situaciones que requieren revisión humana. Esta vista no modifica ningún dato.', 'travel-agency-platform') . '</p>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Tipo', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Reserva / liquidación', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Detalle', 'travel-agency-platform') . '</th>'
            . '</tr></thead><tbody>';
        if (!$issues) {
            echo '<tr><td colspan="3">' . esc_html__('No hay excepciones pendientes.', 'travel-agency-platform') . '</td></tr>';
        }
        foreach ($issues as $issue) {
            echo '<tr><td>' . esc_html($issue['type']) . '</td><td>';
            if ($issue['booking']) {
                echo '<a href="' . esc_url(admin_url('post.php?post=' . (int) $issue['booking'] . '&action=edit')) . '">'
                    . esc_html($issue['code']) . '</a>';
            } else {
                echo esc_html($issue['code']);
            }
            echo '</td><td><code>' . esc_html(wp_trim_words($issue['detail'], 30)) . '</code></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
