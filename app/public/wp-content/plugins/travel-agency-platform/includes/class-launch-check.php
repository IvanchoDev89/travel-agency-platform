<?php
/**
 * TAP_Launch_Check — read-only launch readiness diagnostics for admins.
 *
 * Does not change any data. Surfaces configuration that must be set on the
 * production server (domain, real emails, HTTPS, SMTP, env hardening) so a
 * launch never ships localhost links or developer addresses to customers.
 */
defined('ABSPATH') || exit;

class TAP_Launch_Check {

    /** Return the list of pending launch tasks. */
    public static function issues() {
        $issues = [];

        $home = (string) get_option('home');
        if (preg_match('#localhost|127\.0\.0\.1|\.local#i', $home)) {
            $issues[] = [
                'label' => __('URLs del sitio en localhost', 'travel-agency-platform'),
                'detail' => sprintf(__('siteurl/home = %s. Configura el dominio real en producción (wp option update home … / siteurl …).', 'travel-agency-platform'), $home),
            ];
        }

        $contact = (string) get_option('tap_privacy_contact', '');
        $admin = (string) get_option('admin_email');
        if (!is_email($contact)) {
            $issues[] = [
                'label' => __('Correo de contacto legal no configurado', 'travel-agency-platform'),
                'detail' => sprintf(__('El pie legal de los correos usa admin_email (%s). Configura tap_privacy_contact con una casilla real y monitorizada.', 'travel-agency-platform'), $admin),
            ];
        }
        if (preg_match('#\.local$#i', $admin)) {
            $issues[] = [
                'label' => __('Email de administrador de desarrollo', 'travel-agency-platform'),
                'detail' => sprintf(__('admin_email = %s (no entregable). Cámbialo en producción.', 'travel-agency-platform'), $admin),
            ];
        }

        if (!is_ssl()) {
            $issues[] = [
                'label' => __('Sitio sin HTTPS', 'travel-agency-platform'),
                'detail' => __('Activa SSL y fuerza HTTPS antes de publicar.', 'travel-agency-platform'),
            ];
        }

        if (!defined('TAP_SMTP_HOST') || !TAP_SMTP_HOST) {
            $issues[] = [
                'label' => __('SMTP no configurado', 'travel-agency-platform'),
                'detail' => __('Define TAP_SMTP_HOST/USER/PASS en wp-config.php; sin ello wp_mail() depende del mail() del host y los correos pueden caer en spam.', 'travel-agency-platform'),
            ];
        }

        if (defined('WP_ENVIRONMENT_TYPE') && 'local' === WP_ENVIRONMENT_TYPE) {
            $issues[] = [
                'label' => __('Entorno local', 'travel-agency-platform'),
                'detail' => __('WP_ENVIRONMENT_TYPE=local: las constantes de producción (DISALLOW_FILE_EDIT/MODS, FORCE_SSL_ADMIN) no están activas.', 'travel-agency-platform'),
            ];
        }

        // Payment gateways: enabled but not ready means checkout is dead.
        // is_ready() accounts for credentials coming from wp-config constants.
        $gateways = [];
        if (class_exists('TAP_Onvo') && TAP_Onvo::is_ready()) $gateways[] = 'Onvo Pay';
        if (class_exists('TAP_PayPal') && TAP_PayPal::is_ready()) $gateways[] = 'PayPal';
        if (!$gateways) {
            $issues[] = [
                'label' => __('Ninguna pasarela de pago lista', 'travel-agency-platform'),
                'detail' => __('Configura Onvo o PayPal con credenciales reales (opciones o constantes TAP_*/ONVO_*) antes de publicar.', 'travel-agency-platform'),
            ];
        }

        // Secret fields must never contain URLs (seen in local fixtures).
        foreach (['tap_onvo_webhook_secret', 'tap_paypal_webhook_id'] as $secret) {
            $value = (string) get_option($secret, '');
            if ($value !== '' && preg_match('#^https?://#i', $value)) {
                $issues[] = [
                    'label' => sprintf(__('Secreto inválido en %s', 'travel-agency-platform'), $secret),
                    'detail' => __('El campo contiene una URL en lugar de una clave/ID secreto. Corrígelo antes de publicar.', 'travel-agency-platform'),
                ];
            }
        }

        return $issues;
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page']);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-agency-platform',
            __('Launch Check', 'travel-agency-platform'),
            __('Launch Check', 'travel-agency-platform'),
            'manage_options',
            'tap-launch-check',
            [__CLASS__, 'render']
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $issues = self::issues();
        echo '<div class="wrap"><h1>' . esc_html__('Launch Check', 'travel-agency-platform') . '</h1>';
        if (!$issues) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Todo listo para producción.', 'travel-agency-platform') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
                /* translators: %d: number of pending tasks */
                _n('%d tarea pendiente antes del lanzamiento.', '%d tareas pendientes antes del lanzamiento.', count($issues), 'travel-agency-platform'),
                count($issues)
            )) . '</p></div>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Revisión', 'travel-agency-platform') . '</th><th>' . esc_html__('Detalle', 'travel-agency-platform') . '</th></tr></thead><tbody>';
            foreach ($issues as $issue) {
                echo '<tr><td><strong>' . esc_html($issue['label']) . '</strong></td><td>' . esc_html($issue['detail']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
