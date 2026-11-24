<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
        $today = gmdate('Ymd');
        // Order by the numeric suffix, not lexicographically: past 9999 the
        // string order would keep returning 9999 and collide forever.
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT invoice_number FROM {$t} WHERE invoice_number LIKE %s
             ORDER BY CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED) DESC LIMIT 1",
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
        $t = self::table();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE booking_id = %d", $booking_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($existing) return (int) $existing;

        // Concurrent payments can pick the same number: the UNIQUE key rejects
        // the loser, so retry with a fresh number instead of silently returning
        // invoice id 0 and skipping the PDF/email.
        $number = self::next_number();
        $inserted = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $wpdb->insert(self::table(), [
                'booking_id'     => $booking_id,
                'invoice_number' => $number,
                'amount'         => $booking->total_amount,
                'currency'       => $booking->currency ?? 'USD',
                'status'         => 'issued',
                // Freeze the billing details: bookings/listings are regularly
                // deleted in staging and an invoice must never lose its data.
                'snapshot'       => wp_json_encode(self::build_snapshot($booking)),
            ]);
            if (false !== $result) {
                $inserted = true;
                break;
            }
            $number = self::next_number();
        }
        if (!$inserted) {
            return new WP_Error('invoice_insert_failed', __('No se pudo emitir la factura.', 'travel-agency-platform'));
        }
        $inv_id = (int) $wpdb->insert_id;

        // Hacienda submission (if configured).
        if (get_option('tap_hacienda_enabled', '0') === '1') {
            $key = self::submit_to_hacienda($inv_id, $booking);
            if ($key) {
                $wpdb->update(self::table(), ['hacienda_key' => $key, 'hacienda_status' => 'accepted'], ['id' => $inv_id]);
            }
        }

        // Generate a downloadable PDF and store its URL.
        $pdf_url = self::generate_pdf($inv_id);
        if ('' !== $pdf_url) {
            $wpdb->update(self::table(), ['pdf_url' => $pdf_url], ['id' => $inv_id]);
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

    /** Generate the styled, self-contained invoice PDF and return its public URL. */
    public static function generate_pdf($inv_id) {
        $inv = self::get($inv_id);
        if (!$inv) { return ''; }
        $d = self::data_for($inv);
        $name = get_bloginfo('name');
        $missing = '' === $d['service_title'];

        $elements = [
            ['type' => 'brand', 'text' => $name],
            ['type' => 'spacer', 'h' => 18],
            ['type' => 'title', 'text' => __('Factura', 'travel-agency-platform')],
            ['type' => 'subtitle', 'text' => $inv->invoice_number . '  ·  ' . $inv->issued_at],
            ['type' => 'rule'],
            ['type' => 'h', 'text' => __('Datos de facturación', 'travel-agency-platform')],
            ['type' => 'row', 'label' => __('Agencia', 'travel-agency-platform'), 'value' => $d['agency_name'] ?: '—'],
            ['type' => 'row', 'label' => __('Cliente', 'travel-agency-platform'), 'value' => $d['guest_name'] ?: '—', 'strong' => true],
            ['type' => 'row', 'label' => __('Correo', 'travel-agency-platform'), 'value' => $d['guest_email'] ?: '—'],
            ['type' => 'row', 'label' => __('Reserva', 'travel-agency-platform'), 'value' => $d['booking_code'] ?: '—'],
            ['type' => 'spacer', 'h' => 10],
            ['type' => 'h', 'text' => __('Detalle del servicio', 'travel-agency-platform')],
            ['type' => 'band', 'text' => $d['service_title'] ?: __('Servicio no disponible', 'travel-agency-platform')],
        ];
        if ('' !== $d['check_in']) {
            $when = $d['check_in'] . ('' !== $d['check_out'] ? ' → ' . $d['check_out'] : '');
            if ($d['nights']) { $when .= '  ·  ' . $d['nights'] . ' ' . __('noches', 'travel-agency-platform'); }
            $elements[] = ['type' => 'row', 'label' => __('Fechas', 'travel-agency-platform'), 'value' => $when];
        }
        if ($d['adults'] || $d['children']) {
            $elements[] = [
                'type' => 'row',
                'label' => __('Viajeros', 'travel-agency-platform'),
                'value' => $d['adults'] . ' ' . __('adultos', 'travel-agency-platform') . ' · ' . $d['children'] . ' ' . __('niños', 'travel-agency-platform'),
            ];
        }
        $elements[] = ['type' => 'spacer', 'h' => 12];
        // total_amount already includes the platform fee: show the pre-fee
        // subtotal so subtotal + fee = total and the lines reconcile.
        $pdf_subtotal = max(0, round($d['total_amount'] - $d['booking_fee'], 2));
        $elements[] = ['type' => 'row', 'label' => __('Subtotal', 'travel-agency-platform'), 'value' => self::money($pdf_subtotal, $d, true)];
        if ($d['booking_fee']) {
            $elements[] = ['type' => 'row', 'label' => __('Tarifa de plataforma', 'travel-agency-platform'), 'value' => self::money($d['booking_fee'], $d, true)];
        }
        $elements[] = ['type' => 'total', 'label' => __('Total pagado', 'travel-agency-platform'), 'value' => self::money($d['total_amount'], $d, true)];
        $elements[] = ['type' => 'spacer', 'h' => 18];
        if ($missing) {
            $elements[] = ['type' => 'small', 'text' => __('Los datos de la reserva original ya no están disponibles.', 'travel-agency-platform')];
        }
        $elements[] = ['type' => 'small', 'text' => __('Gracias por tu reserva.', 'travel-agency-platform')];
        $elements[] = ['type' => 'small', 'text' => $name . '  ·  ' . $inv->invoice_number];

        $pdf = TAP_Simple_PDF::build($elements);
        return self::write_pdf($inv, $pdf);
    }

    /** Canonical, stable storage path for an invoice PDF (year/month of issue). */
    private static function pdf_target($inv) {
        $uploads = wp_upload_dir();
        $stamp = !empty($inv->issued_at) ? strtotime((string) $inv->issued_at) : time();
        $sub = gmdate('Y/m', $stamp ?: time());
        $file = sanitize_file_name('factura-' . $inv->invoice_number . '.pdf');
        return [
            'dir'  => trailingslashit($uploads['basedir']) . $sub,
            'path' => trailingslashit($uploads['basedir']) . $sub . '/' . $file,
            'url'  => trailingslashit($uploads['baseurl']) . $sub . '/' . $file,
            'file' => $file,
        ];
    }

    /**
     * Write the PDF to one stable path and remove previous copies. Using
     * wp_upload_bits() on every regeneration produced a new -N file each time
     * and left the old ones orphaned on disk.
     */
    private static function write_pdf($inv, $pdf) {
        $target = self::pdf_target($inv);
        if (!wp_mkdir_p($target['dir'])) { return ''; }
        $written = file_put_contents($target['path'], $pdf); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (false === $written) { return ''; }
        // Drop legacy suffixed copies of this invoice, keeping the canonical one.
        $pattern = $target['dir'] . '/' . sanitize_file_name('factura-' . $inv->invoice_number) . '*.pdf';
        foreach ((array) glob($pattern) as $old) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob
            if ($old !== $target['path'] && is_file($old)) { wp_delete_file($old); }
        }
        return $target['url'];
    }

    /** Agency title for a booking (best effort). */
    private static function booking_agency_name($booking) {
        if (!$booking) { return '—'; }
        $prefix = TAP_Post_Types::meta_prefix($booking->service_type);
        $agency_id = (int) get_post_meta($booking->service_id, '_tap_' . $prefix . '_agency_id', true);
        $agency = $agency_id ? get_post($agency_id) : null;
        return $agency ? $agency->post_title : '—';
    }

    /** Get invoice by id. */
    public static function get($id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    /**
     * One-shot backfill: freeze billing data for legacy invoices that still
     * have their booking, so a later deletion cannot empty them.
     */
    public static function backfill_snapshots() {
        global $wpdb;
        if (get_option('tap_invoice_snapshot_backfill')) { return 0; }
        $rows = $wpdb->get_results(
            "SELECT i.id AS invoice_id, b.* FROM {$wpdb->prefix}tap_invoices i
             JOIN {$wpdb->prefix}tap_bookings b ON i.booking_id = b.id
             WHERE i.snapshot IS NULL OR i.snapshot = ''"
        );
        $done = 0;
        foreach ((array) $rows as $row) {
            $snapshot = self::build_snapshot($row);
            if ($wpdb->update(self::table(), ['snapshot' => wp_json_encode($snapshot)], ['id' => (int) $row->invoice_id])) {
                ++$done;
            }
        }
        update_option('tap_invoice_snapshot_backfill', '1', false);
        return $done;
    }

    /** Get invoice by booking. */
    public static function by_booking($booking_id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE booking_id = %d", (int) $booking_id));
    }

    /** Freeze every field an invoice needs at issue time. */
    private static function build_snapshot($booking) {
        $prefix = TAP_Post_Types::meta_prefix($booking->service_type);
        $agency_id = (int) get_post_meta($booking->service_id, '_tap_' . $prefix . '_agency_id', true);
        if (!$agency_id) { $agency_id = (int) ($booking->agency_id ?? 0); }
        $agency = $agency_id ? get_post($agency_id) : null;
        $service = get_post($booking->service_id);
        return [
            'booking_code'      => (string) ($booking->booking_code ?? ''),
            'guest_name'        => (string) ($booking->guest_name ?? ''),
            'guest_email'       => (string) ($booking->guest_email ?? ''),
            'guest_phone'       => (string) ($booking->guest_phone ?? ''),
            'agency_name'       => $agency ? (string) $agency->post_title : '',
            'service_title'     => $service ? (string) $service->post_title : '',
            'service_type'      => (string) ($booking->service_type ?? ''),
            'check_in'          => (string) ($booking->check_in ?? ''),
            'check_out'         => (string) ($booking->check_out ?? ''),
            'adults'            => (int) ($booking->adults ?? 0),
            'children'          => (int) ($booking->children ?? 0),
            'nights'            => (int) ($booking->nights ?? 0),
            'total_amount'      => (float) ($booking->total_amount ?? 0),
            'booking_fee'       => (float) ($booking->booking_fee ?? 0),
            'commission_amount' => (float) ($booking->commission_amount ?? 0),
            'amount_paid'       => (float) ($booking->amount_paid ?? 0),
            'currency'          => (string) ($booking->currency ?? 'USD'),
        ];
    }

    /**
     * Normalized view data. The snapshot wins over live data (the invoice must
     * reflect what was billed), with the booking as a fallback for legacy rows.
     *
     * @return array<string,mixed>
     */
    private static function data_for($inv) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            (int) $inv->booking_id
        ));
        $snap = [];
        if (!empty($inv->snapshot)) {
            $decoded = json_decode((string) $inv->snapshot, true);
            if (is_array($decoded)) { $snap = $decoded; }
        }
        $get = static function ($key) use ($snap, $booking) {
            if (isset($snap[$key]) && '' !== $snap[$key] && 0 !== $snap[$key]) {
                return $snap[$key];
            }
            return $booking ? ($booking->{$key} ?? '') : '';
        };
        return [
            'booking_code'      => (string) $get('booking_code'),
            'guest_name'        => (string) $get('guest_name'),
            'guest_email'       => (string) $get('guest_email'),
            'guest_phone'       => (string) $get('guest_phone'),
            'agency_name'       => (string) $get('agency_name'),
            'service_title'     => (string) $get('service_title'),
            'check_in'          => (string) $get('check_in'),
            'check_out'         => (string) $get('check_out'),
            'adults'            => (int) $get('adults'),
            'children'          => (int) $get('children'),
            'nights'            => (int) $get('nights'),
            'total_amount'      => (float) ($get('total_amount') ?: $inv->amount),
            'booking_fee'       => (float) $get('booking_fee'),
            'commission_amount' => (float) $get('commission_amount'),
            'currency'          => (string) ($inv->currency ?: $get('currency') ?: 'USD'),
            'has_booking'       => (bool) $booking,
        ];
    }

    /** Currency formatter bound to this invoice's currency. */
    private static function money($amount, $data, $plain = false) {
        return $plain
            ? TAP_Currency::fmt_plain($amount, $data['currency'])
            : TAP_Currency::fmt($amount, null, $data['currency']);
    }

    /** Render invoice HTML (printable). */
    public static function render_html($inv_id) {
        $inv = self::get($inv_id);
        if (!$inv) return '';
        $d = self::data_for($inv);
        $name = get_bloginfo('name');

        ob_start();
        ?>
        <div class="tap-invoice">
            <div class="tap-invoice-head">
                <div>
                    <div class="tap-invoice-brand"><?php echo esc_html($name); ?></div>
                    <h1><?php esc_html_e('Factura', 'travel-agency-platform'); ?></h1>
                    <p style="margin:0;color:#64748b;font-size:13px;">
                        <?php echo esc_html($inv->invoice_number); ?> · <?php echo esc_html($inv->issued_at); ?>
                    </p>
                </div>
                <div class="tap-invoice-meta">
                    <strong><?php echo esc_html(strtoupper($d['currency'])); ?></strong><br>
                    <?php echo esc_html(ucfirst($inv->status)); ?>
                    <?php if ($inv->hacienda_status): ?>
                        <br><?php echo esc_html($inv->hacienda_status); ?>
                    <?php endif; ?>
                </div>
            </div>

            <h2><?php esc_html_e('Datos de facturación', 'travel-agency-platform'); ?></h2>
            <dl class="tap-invoice-details">
                <div><dt><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></dt><dd><?php echo esc_html($d['agency_name'] ?: '—'); ?></dd></div>
                <div><dt><?php esc_html_e('Cliente', 'travel-agency-platform'); ?></dt><dd><?php echo esc_html($d['guest_name'] ?: '—'); ?></dd></div>
                <div><dt><?php esc_html_e('Correo', 'travel-agency-platform'); ?></dt><dd><?php echo esc_html($d['guest_email'] ?: '—'); ?></dd></div>
                <div><dt><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></dt><dd><?php echo esc_html($d['booking_code'] ?: '—'); ?></dd></div>
            </dl>

            <h2><?php esc_html_e('Detalle del servicio', 'travel-agency-platform'); ?></h2>
            <table class="tap-invoice-table">
                <thead><tr>
                    <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                    <th class="num"><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td>
                            <?php echo esc_html($d['service_title'] ?: __('Servicio no disponible', 'travel-agency-platform')); ?>
                            <?php if ('' !== $d['check_in']): ?>
                                <br><small><?php echo esc_html($d['check_in'] . ('' !== $d['check_out'] ? ' → ' . $d['check_out'] : '')); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?php echo esc_html(self::money($d['total_amount'], $d)); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <?php $tap_subtotal = max(0, round($d['total_amount'] - $d['booking_fee'], 2)); ?>
                    <tr><td><?php esc_html_e('Subtotal', 'travel-agency-platform'); ?></td><td class="num"><?php echo esc_html(self::money($tap_subtotal, $d)); ?></td></tr>
                    <?php if ($d['booking_fee']): ?>
                        <tr><td><?php esc_html_e('Tarifa de plataforma', 'travel-agency-platform'); ?></td><td class="num"><?php echo esc_html(self::money($d['booking_fee'], $d)); ?></td></tr>
                    <?php endif; ?>
                    <tr class="tap-invoice-total"><td><?php esc_html_e('Total pagado', 'travel-agency-platform'); ?></td><td class="num amount"><?php echo esc_html(self::money($d['total_amount'], $d)); ?></td></tr>
                </tfoot>
            </table>

            <?php if ('' === $d['service_title']): ?>
                <p class="tap-invoice-note"><?php esc_html_e('Los datos de la reserva original ya no están disponibles; se muestra la información conservada en la factura.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>

            <p class="tap-invoice-footer"><?php echo esc_html($name . ' · ' . $inv->invoice_number); ?></p>
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
        add_action('admin_init', [__CLASS__, 'maybe_generate_pdf']);
        add_action('admin_init', [__CLASS__, 'maybe_download_pdf']);
    }

    /**
     * The PDF link always regenerates first, so older minimal PDFs are replaced
     * by the current styled layout instead of serving stale bytes forever.
     */
    public static function maybe_download_pdf() {
        if (!isset($_GET['download_pdf'])) { return; }
        if (!current_user_can('manage_options')) { return; }
        $id = absint($_GET['download_pdf']);
        check_admin_referer('tap_download_pdf_' . $id);
        $url = self::generate_pdf($id);
        if ('' === $url) {
            wp_safe_redirect(admin_url('admin.php?page=tap-invoices&pdf_done=0'));
            exit;
        }
        global $wpdb;
        $wpdb->update(self::table(), ['pdf_url' => $url], ['id' => $id]);
        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    /** Generate a missing PDF from the invoices list (cap + nonce guarded). */
    public static function maybe_generate_pdf() {
        if (!isset($_GET['gen_pdf'])) { return; }
        if (!current_user_can('manage_options')) { return; }
        $id = absint($_GET['gen_pdf']);
        check_admin_referer('tap_gen_pdf_' . $id);
        $url = self::generate_pdf($id);
        if ($url) {
            global $wpdb;
            $wpdb->update(self::table(), ['pdf_url' => $url], ['id' => $id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=tap-invoices&pdf_done=' . ($url ? '1' : '0')));
        exit;
    }

    public static function on_payment_completed($booking_id, $method, $capture_id) {
        // Issue invoice when payment is completed.
        self::issue((int) $booking_id);
    }
    /** Admin page listing invoices. */
    public static function invoices_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        global $wpdb;
        $t = self::table();
        $per_page = 50;
        $paged    = max(1, absint($_GET['paged'] ?? 1));
        $search   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $inv_status = isset($_GET['inv_status']) ? sanitize_key(wp_unslash($_GET['inv_status'])) : '';
        $where  = '1=1';
        $params = [];
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (invoice_number LIKE %s OR CAST(booking_id AS CHAR) LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ('' !== $inv_status) {
            $where .= ' AND status = %s';
            $params[] = $inv_status;
        }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where}", $params));
        $invoices = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE {$where} ORDER BY issued_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, ($paged - 1) * $per_page])
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Facturas', 'travel-agency-platform'); ?></h1>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:14px 0;">
                <input type="hidden" name="page" value="tap-invoices">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Número o reserva', 'travel-agency-platform'); ?>" class="regular-text">
                <select name="inv_status">
                    <option value=""><?php esc_html_e('Todos los estados', 'travel-agency-platform'); ?></option>
                    <?php foreach (['issued', 'void', 'refunded'] as $st): ?>
                        <option value="<?php echo esc_attr($st); ?>" <?php selected($inv_status, $st); ?>><?php echo esc_html($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Buscar', 'travel-agency-platform'); ?></button>
                <?php if ('' !== $search || '' !== $inv_status): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tap-invoices')); ?>" class="button button-link"><?php esc_html_e('Limpiar', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </form>
            <?php if (isset($_GET['pdf_done'])): ?>
                <div class="notice <?php echo '1' === sanitize_key($_GET['pdf_done']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p>
                        <?php
                        echo '1' === sanitize_key($_GET['pdf_done'])
                            ? esc_html__('PDF generado.', 'travel-agency-platform')
                            : esc_html__('No se pudo generar el PDF.', 'travel-agency-platform');
                        ?>
                    </p>
                </div>
            <?php endif; ?>
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
                            <?php if ($inv->pdf_url): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-invoices&download_pdf=' . (int) $inv->id), 'tap_download_pdf_' . (int) $inv->id)); ?>" class="button button-small" target="_blank" rel="noopener">PDF</a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-invoices&gen_pdf=' . (int) $inv->id), 'tap_gen_pdf_' . (int) $inv->id)); ?>" class="button button-small"><?php esc_html_e('Generar PDF', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
						<?php
                endforeach;
endif;
				?>
                </tbody>
            </table>
            <?php echo TAP_Dashboard::paginate($total, $per_page, $paged); ?>
            <?php
            $view = isset($_GET['view']) ? (int) $_GET['view'] : 0;
            if ($view) {
                echo '<div class="tap-print-bar" style="margin-bottom:16px;">'
                    . '<button type="button" class="button tap-invoice-print">'
                    . esc_html__('Imprimir factura', 'travel-agency-platform')
                    . '</button></div>';
                echo wp_kses_post(self::render_html($view));
                echo '<script>jQuery(function($){$(".tap-invoice-print").on("click",function(){window.print();});});</script>';
            }
            ?>
        </div>
        <?php
    }
}
