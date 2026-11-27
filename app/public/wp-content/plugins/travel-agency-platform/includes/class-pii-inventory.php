<?php
defined('ABSPATH') || exit;

/**
 * Read-only personal-data inventory.
 *
 * Lists where the plugin stores PII, why it stores it and for how long, and
 * verifies that the declared columns still exist so the map cannot drift.
 */
class TAP_PII_Inventory {

    public static function map() {
        return [
            [
                'table'   => 'tap_bookings',
                'columns' => ['guest_name', 'guest_email', 'guest_phone', 'booking_code'],
                'purpose' => __('Gestión de la reserva, voucher y comunicaciones del viaje.', 'travel-agency-platform'),
                'retention' => __('Operativa; se anonimiza al solicitar borrado ARCO.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_leads',
                'columns' => ['name', 'email', 'message'],
                'purpose' => __('Atención de consultas comerciales.', 'travel-agency-platform'),
                'retention' => __('Hasta resolver la consulta.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_consents',
                'columns' => ['email', 'ip'],
                'purpose' => __('Prueba de consentimiento informado.', 'travel-agency-platform'),
                'retention' => __('Conservado como evidencia legal.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_disputes',
                'columns' => ['guest_email', 'details'],
                'purpose' => __('Tramitación de disputas.', 'travel-agency-platform'),
                'retention' => __('Mientras el caso esté abierto y su plazo de revisión.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_chat_messages',
                'columns' => ['author_id', 'message'],
                'purpose' => __('Conversación entre viajero y agencia.', 'travel-agency-platform'),
                'retention' => __('Mientras la reserva esté activa.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_privacy_requests',
                'columns' => ['email', 'details'],
                'purpose' => __('Gestión de derechos ARCO.', 'travel-agency-platform'),
                'retention' => __('Registro de la solicitud y su resolución.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_customer_referrals',
                'columns' => ['referrer_user_id', 'referred_user_id', 'fingerprint'],
                'purpose' => __('Programa de referidos y prevención de fraude.', 'travel-agency-platform'),
                'retention' => __('Mientras la cuenta esté activa.', 'travel-agency-platform'),
            ],
            [
                'table'   => 'tap_affiliates',
                'columns' => ['email', 'tax_form'],
                'purpose' => __('Liquidación de comisiones a afiliados.', 'travel-agency-platform'),
                'retention' => __('Mientras el afiliado esté activo y obligaciones fiscales lo requieran.', 'travel-agency-platform'),
            ],
        ];
    }

    /** Verify declared tables/columns exist. @return array */
    public static function issues() {
        global $wpdb;
        $issues = [];
        foreach (self::map() as $entry) {
            $table = $wpdb->prefix . $entry['table'];
            $cols = $wpdb->get_col("DESCRIBE {$table}");
            if (!$cols) {
                $issues[] = ['label' => $entry['table'], 'detail' => __('La tabla declarada ya no existe.', 'travel-agency-platform')];
                continue;
            }
            foreach ((array) $entry['columns'] as $col) {
                if (!in_array($col, $cols, true)) {
                    $issues[] = [
                        'label'  => $entry['table'] . '.' . $col,
                        'detail' => __('La columna declarada no existe; revisa el inventario.', 'travel-agency-platform'),
                    ];
                }
            }
        }
        return $issues;
    }

    public static function render() {
        if (!current_user_can('tap_manage_settings')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('Inventario de datos personales', 'travel-agency-platform') . '</h1>';
        $issues = self::issues();
        if ($issues) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('El inventario no coincide con la base de datos:', 'travel-agency-platform') . '</p><ul>';
            foreach ($issues as $issue) {
                echo '<li><code>' . esc_html($issue['label']) . '</code> — ' . esc_html($issue['detail']) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Tabla', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Datos', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Finalidad', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Retención', 'travel-agency-platform') . '</th>'
            . '</tr></thead><tbody>';
        foreach (self::map() as $entry) {
            echo '<tr><td><code>' . esc_html($entry['table']) . '</code></td><td><code>'
                . esc_html(implode(', ', $entry['columns'])) . '</code></td><td>'
                . esc_html($entry['purpose']) . '</td><td>' . esc_html($entry['retention']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
