<?php
defined('ABSPATH') || exit;

/**
 * Invoicing for bookings (electronic invoice ready).
 * Generates an invoice record + HTML invoice. Hacienda (CR) integration is
 * a hook point: if credentials are configured, submits the XML to Hacienda.
 */
class TAP_Invoices {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_invoices';
    }

    /** Generate invoice number: TAP-YYYYMMDD-XXXX. */
    public static function next_number() {
        global $wpdb;
        $t = self::table();
        $today = date('Ymd');
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT invoice_number FROM {$t} WHERE invoice_number LIKE %s ORDER BY id DESC LIMIT 1",
            'TAP-' . $today . '-%'
        ));
        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return 'TAP-' . $today . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** Issue an invoice for a booking. */
    public static function issue($booking_id) {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) return new WP_Error('not_found', __('Reserva no encontrada.', 'travel-agency-platform'));

        // Avoid duplicate.
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE booking_id = %d", $booking_id));
        if ($existing) return (int) $existing;

        $number = self::next_number();
        $wpdb->insert(self::table(), [
            'booking_id'     => $booking_id,
            'invoice_number' => $number,
            'amount'         => $booking->total_amount,
            'currency'       => $booking->currency ?? 'USD',
            'status'         => 'issued',
        ]);
        $inv_id = (int) $wpdb->insert_id;

        // Hacienda submission (if configured).
        if (get_option('tap_hacienda_enabled', '0') === '1') {
            $key = self::submit_to_hacienda($inv_id, $booking);
            if ($key) {
                $wpdb->update(self::table(), ['hacienda_key' => $key, 'hacienda_status' => 'accepted'], ['id' => $inv_id]);
            }
        }

        // Email the invoice.
        self::email_invoice($inv_id);

        return $inv_id;
    }

    /** Submit to Hacienda (placeholder - requires cert + credentials). */
    private static function submit_to_hacienda($inv_id, $booking) {
        // Hacienda integration requires:
        // - Digital certificate (p12)
        // - API credentials (client_id, client_secret)
        // - XML generation per Hacienda schema
        // This is a hook point; implement with a library like 'php-hacienda' or
        // a custom XML builder + cURL to Hacienda's API.
        return null;
    }

    /** Get invoice by id. */
    public static function get($id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    /** Get invoice by booking. */
    public static function by_booking($booking_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE booking_id = %d", (int) $booking_id));
    }

    /** Render invoice HTML (printable). */
    public static function render_html($inv_id) {
        $inv = self::get($inv_id);
        if (!$inv) return '';
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $inv->booking_id));
        $service = $booking ? get_post($booking->service_id) : null;
        $agency  = $booking ? get_post((int) get_post_meta($booking->service_id, '_tap_' . TAP_Post_Types::meta_prefix($booking->service_type) . '_agency_id', true)) : null;

        ob_start();
        ?>
        <div class="tap-invoice" style="max-width:720px;margin:0 auto;padding:32px;font-family:sans-serif;">
            <h1 style="margin:0 0 8px;"><?php esc_html_e('Factura', 'travel-agency-platform'); ?></h1>
            <p style="margin:0 0 16px;color:#64748b;"><?php echo esc_html($inv->invoice_number); ?> · <?php echo esc_html($inv->issued_at); ?></p>
            <hr style="border:0;border-top:1px solid #e2e8f0;margin:16px 0;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <strong><?php esc_html_e('Agencia', 'travel-agency-platform'); ?>:</strong><br>
                    <?php echo esc_html($agency ? $agency->post_title : '—'); ?>
                </div>
                <div>
                    <strong><?php esc_html_e('Cliente', 'travel-agency-platform'); ?>:</strong><br>
                    <?php echo esc_html($booking ? $booking->guest_name : '—'); ?><br>
                    <small><?php echo esc_html($booking ? $booking->guest_email : ''); ?></small>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <thead><tr style="background:#f1f5f9;">
                    <th style="padding:8px;text-align:left;"><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                    <th style="padding:8px;text-align:right;"><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td style="padding:8px;"><?php echo esc_html($service ? $service->post_title : __('Listado eliminado', 'travel-agency-platform')); ?></td>
                        <td style="padding:8px;text-align:right;"><?php echo esc_html(TAP_Currency::fmt($inv->amount)); ?></td>
                    </tr>
                </tbody>
                <tfoot><tr style="font-weight:700;">
                    <td style="padding:8px;"><?php esc_html_e('Total', 'travel-agency-platform'); ?></td>
                    <td style="padding:8px;text-align:right;"><?php echo esc_html(TAP_Currency::fmt($inv->amount)); ?></td>
                </tr></tfoot>
            </table>
            <p style="font-size:12px;color:#64748b;margin-top:24px;"><?php esc_html_e('Gracias por tu reserva.', 'travel-agency-platform'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Email the invoice to the guest. */
    public static function email_invoice($inv_id) {
        $inv = self::get($inv_id);
        if (!$inv) return false;
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $inv->booking_id));
        if (!$booking || !$booking->guest_email) return false;
        $html = self::render_html($inv_id);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return (bool) wp_mail($booking->guest_email, __('Tu factura', 'travel-agency-platform') . ' ' . $inv->invoice_number, $html, $headers);
    }

    /** Init: hook into payment completion. */
    public static function init() {
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 10, 3);
    }

    public static function on_payment_completed($booking_id, $method, $capture_id) {
        // Issue invoice when payment is completed.
        self::issue((int) $booking_id);
    }
    /** Admin page listing invoices. */
    public static function invoices_page() {
        if (!current_user_can('manage_options')) wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        global $wpdb;
        $t = self::table();
        $invoices = $wpdb->get_results("SELECT * FROM {$t} ORDER BY issued_at DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Facturas', 'travel-agency-platform'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Número', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Hacienda', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$invoices): ?>
                    <tr><td colspan="7"><?php esc_html_e('Sin facturas.', 'travel-agency-platform'); ?></td></tr>
                <?php else: foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?php echo esc_html($inv->invoice_number); ?></td>
                        <td>#<?php echo (int) $inv->booking_id; ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($inv->amount)); ?></td>
                        <td><?php echo esc_html($inv->status); ?></td>
                        <td><?php echo esc_html($inv->hacienda_status ?: '—'); ?></td>
                        <td><?php echo esc_html($inv->issued_at); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-invoices&view=' . (int) $inv->id)); ?>" class="button button-small"><?php esc_html_e('Ver', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php
            $view = isset($_GET['view']) ? (int) $_GET['view'] : 0;
            if ($view) {
                echo self::render_html($view);
            }
            ?>
        </div>
        <?php
    }

}
