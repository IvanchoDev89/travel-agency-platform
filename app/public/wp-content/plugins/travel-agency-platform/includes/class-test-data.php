<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * In-sandbox cleanup of E2E artifacts so dashboards can be trusted during
 * user testing. Dry-run by default; deletes only rows clearly marked as test
 * data. Never use on production.
 */
class TAP_Test_Data {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page'], 38);
        add_action('admin_post_tap_test_data_cleanup', [__CLASS__, 'handle_cleanup']);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Datos de prueba', 'travel-agency-platform'),
            __('Datos de prueba', 'travel-agency-platform'),
            'manage_options',
            'tap-test-data',
            [__CLASS__, 'render']
        );
    }

    /** Counters shared by the preview and the cleanup action. */
    public static function counts() {
        global $wpdb;
        $b = $wpdb->prefix . 'tap_bookings';
        $i = $wpdb->prefix . 'tap_invoices';
        $out = [
            'bookings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$b} WHERE booking_code LIKE 'TAP-E2E-%' OR guest_email LIKE '%@example.test' OR guest_email LIKE '%@example.invalid'"),
            'invoices_orphan' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$i} i LEFT JOIN {$b} b ON b.id = i.booking_id WHERE b.id IS NULL"),
            'consents' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_consents WHERE email LIKE '%@example.test' OR email LIKE '%@example.invalid'"),
            'reviews' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE content LIKE '%TAP Suite%' OR content LIKE '%E2E%'"),
        ];
        $out['posts'] = (int) (array_sum($out));
        return $out;
    }

    public static function handle_cleanup() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        check_admin_referer('tap_test_data_cleanup');
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            wp_safe_redirect(add_query_arg('tap_clean', 'debug', admin_url('admin.php?page=tap-test-data')));
            exit;
        }
        global $wpdb;
        $b = $wpdb->prefix . 'tap_bookings';
        $ids = $wpdb->get_col("SELECT id FROM {$b} WHERE booking_code LIKE 'TAP-E2E-%' OR guest_email LIKE '%@example.test' OR guest_email LIKE '%@example.invalid'");
        foreach ($ids as $bid) {
            // Cascade every booking-linked child before removing the parent,
            // otherwise the cleanup itself creates the orphans it claims to fix.
            foreach ([
                'tap_invoices', 'tap_chat_messages', 'tap_travel_insurance',
                'tap_waivers', 'tap_legal_acceptances', 'tap_disputes',
                'tap_booking_items',
            ] as $child) {
                $wpdb->delete($wpdb->prefix . $child, ['booking_id' => (int) $bid]);
            }
            $wpdb->delete($wpdb->prefix . 'tap_payout_bookings', ['booking_id' => (int) $bid]);
            $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) $bid]);
        }
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_payout_bookings WHERE booking_id NOT IN (SELECT id FROM {$b})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_payout_bookings WHERE payout_id NOT IN (SELECT id FROM {$wpdb->prefix}tap_commission_payments)");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_invoices WHERE booking_id NOT IN (SELECT id FROM {$b})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_consents WHERE email LIKE '%@example.test' OR email LIKE '%@example.invalid'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}tap_reviews WHERE content LIKE '%TAP Suite%' OR content LIKE '%E2E%'");
        wp_safe_redirect(add_query_arg('tap_clean', '1', admin_url('admin.php?page=tap-test-data')));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $counts = self::counts();
        echo '<div class="wrap"><h1>' . esc_html__('Datos de prueba', 'travel-agency-platform') . '</h1>';
        echo '<div class="notice notice-warning"><p>' . esc_html__('Esta herramienta es solo para entornos de desarrollo/pruebas. Nunca ejecutarla en producción.', 'travel-agency-platform') . '</p></div>';
        if (isset($_GET['tap_clean'])) {
            if ('debug' === sanitize_key($_GET['tap_clean'])) {
                echo '<div class="notice notice-error"><p>' . esc_html__('WP_DEBUG debe estar activo para ejecutar la limpieza.', 'travel-agency-platform') . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__('Datos de prueba eliminados.', 'travel-agency-platform') . '</p></div>';
            }
        }
        echo '<p>' . esc_html__('Vista previa de los datos que se eliminarían:', 'travel-agency-platform') . '</p>';
        echo '<table class="wp-list-table widefat fixed striped" style="max-width:600px;"><tbody>';
        foreach ([
            __('Reservas de prueba', 'travel-agency-platform') => $counts['bookings'],
            __('Facturas huérfanas', 'travel-agency-platform') => $counts['invoices_orphan'],
            __('Consentimientos de prueba', 'travel-agency-platform') => $counts['consents'],
            __('Reseñas de prueba', 'travel-agency-platform') => $counts['reviews'],
        ] as $label => $value) {
            echo '<tr><td>' . esc_html($label) . '</td><td>' . (int) $value . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        echo '<input type="hidden" name="action" value="tap_test_data_cleanup">';
        wp_nonce_field('tap_test_data_cleanup');
        echo '<button class="button button-secondary" onclick="return confirm(\'' . esc_js(__('¿Eliminar todos los datos de prueba listados? Esta acción no se puede deshacer.', 'travel-agency-platform')) . '\');">'
            . esc_html__('Eliminar datos de prueba', 'travel-agency-platform') . '</button></form>';
        echo '</div>';
    }
}
