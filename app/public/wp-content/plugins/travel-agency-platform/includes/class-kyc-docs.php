<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * KYC identity documents for agencies: private uploads, operator review and
 * a resubmission flow after rejection. No public URLs for identity files.
 */
class TAP_KYC_Docs {

    const MAX_BYTES = 5242880; // 5 MB

    public static function init() {
        add_action('admin_post_tap_kyc_doc_status', [__CLASS__, 'handle_status']);
        add_action('admin_post_tap_kyc_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_tap_kyc_serve', [__CLASS__, 'serve']);
        add_action('admin_post_nopriv_tap_kyc_serve', [__CLASS__, 'serve']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_kyc_documents';
    }

    /** Document types required for verification. */
    public static function types() {
        return [
            'id_front' => __('Identificación del representante (frente)', 'travel-agency-platform'),
            'id_back'  => __('Identificación del representante (reverso)', 'travel-agency-platform'),
            'tax_id'   => __('Cédula jurídica / Registro', 'travel-agency-platform'),
            'address'  => __('Comprobante de domicilio', 'travel-agency-platform'),
        ];
    }

    public static function for_agency($agency_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE agency_id = %d ORDER BY uploaded_at DESC',
            (int) $agency_id
        )) ?: [];
    }

    public static function latest_by_type($agency_id) {
        $out = [];
        foreach (self::for_agency($agency_id) as $doc) {
            if (!isset($out[$doc->doc_type])) { $out[$doc->doc_type] = $doc; }
        }
        return $out;
    }

    /** Private directory for identity files (outside the web-root-facing tree). */
    public static function upload_dir() {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'tap-private/kyc';
        wp_mkdir_p($dir);
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "deny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        return $dir;
    }

    /** Derive the AES key from WordPress salts (and an optional env constant). */
    public static function encryption_key() {
        $extra = defined('TAP_KYC_KEY') ? (string) TAP_KYC_KEY : '';
        return hash('sha256', wp_salt('auth') . '|tap-kyc|' . $extra, true);
    }

    /** Encrypt bytes with a random IV. Returns false if openssl is unavailable. */
    public static function encrypt($plain) {
        if (!function_exists('openssl_encrypt')) { return false; }
        $iv = openssl_random_pseudo_bytes(16);
        if (false === $iv) { return false; }
        $cipher = openssl_encrypt((string) $plain, 'aes-256-cbc', self::encryption_key(), 0, $iv);
        if (false === $cipher) { return false; }
        return 'TAPKYC1:' . base64_encode($iv) . ':' . base64_encode($cipher);
    }

    /** Decrypt bytes; plain legacy content is returned unchanged. */
    public static function decrypt($data) {
        $data = (string) $data;
        if (0 !== strpos($data, 'TAPKYC1:')) { return $data; }
        if (!function_exists('openssl_decrypt')) { return false; }
        $parts = explode(':', $data, 3);
        if (3 !== count($parts)) { return false; }
        $iv = base64_decode($parts[1], true);
        $cipher = base64_decode($parts[2], true);
        if (false === $iv || false === $cipher) { return false; }
        return openssl_decrypt($cipher, 'aes-256-cbc', self::encryption_key(), 0, $iv);
    }

    /** Gradually encrypt legacy uploads (bounded so a request stays fast). */
    public static function encrypt_legacy($limit = 20) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, file_path, is_encrypted FROM ' . self::table() . ' WHERE is_encrypted = 0 ORDER BY id ASC LIMIT %d',
            max(1, (int) $limit)
        ));
        $done = 0;
        foreach ((array) $rows as $doc) {
            if (!is_readable($doc->file_path)) { continue; }
            $plain = self::decrypt(file_get_contents($doc->file_path)); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if (false === $plain) { continue; }
            $encrypted = self::encrypt($plain);
            if (false === $encrypted) { continue; }
            if (false !== file_put_contents($doc->file_path, $encrypted) // phpcs:ignore WordPress.WP.AlternativeFunctions
                && $wpdb->update(self::table(), ['is_encrypted' => 1], ['id' => (int) $doc->id])) {
                ++$done;
            }
        }
        return $done;
    }

    /** Validate and store one uploaded file for an agency. Returns id or WP_Error. */
    public static function store($agency_id, $file, $doc_type) {
        $agency_id = (int) $agency_id;
        $post = get_post($agency_id);
        if (!$post || 'tap_agency' !== $post->post_type) {
            return new WP_Error('invalid_agency', __('Agencia no válida.', 'travel-agency-platform'));
        }
        if (!array_key_exists($doc_type, self::types())) {
            return new WP_Error('invalid_type', __('Tipo de documento no válido.', 'travel-agency-platform'));
        }
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('no_file', __('No se recibió ningún archivo.', 'travel-agency-platform'));
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return new WP_Error('too_large', __('El archivo supera los 5 MB.', 'travel-agency-platform'));
        }
        $checked = wp_check_filetype_and_ext($file['tmp_name'], (string) ($file['name'] ?? ''));
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $ext = strtolower((string) ($checked['ext'] ?? ''));
        if (!isset($allowed[$ext]) || ($checked['type'] ?? '') !== $allowed[$ext]) {
            return new WP_Error('bad_type', __('Solo se permiten JPG, PNG o PDF.', 'travel-agency-platform'));
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = trailingslashit(self::upload_dir()) . $name;
        $plain = file_get_contents($file['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (false === $plain) {
            return new WP_Error('read_failed', __('No se pudo leer el archivo.', 'travel-agency-platform'));
        }
        $encrypted = self::encrypt($plain);
        if (false === $encrypted) {
            return new WP_Error('no_crypto', __('El servidor no puede cifrar el documento; contacta a soporte.', 'travel-agency-platform'));
        }
        if (false === file_put_contents($path, $encrypted) // phpcs:ignore WordPress.WP.AlternativeFunctions
            || !file_exists($path)) {
            return new WP_Error('move_failed', __('No se pudo guardar el archivo.', 'travel-agency-platform'));
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'agency_id' => $agency_id,
            'doc_type' => $doc_type,
            'file_path' => $path,
            'file_name' => sanitize_text_field((string) $file['name']),
            'mime' => (string) $checked['type'],
            'file_size' => (int) $file['size'],
            'is_encrypted' => 1,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Operator decision for one document. */
    public static function review($doc_id, $status, $note = '', $user_id = 0) {
        global $wpdb;
        $doc = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $doc_id));
        if (!$doc) { return new WP_Error('not_found', __('Documento no encontrado.', 'travel-agency-platform')); }
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return new WP_Error('bad_status', __('Estado no válido.', 'travel-agency-platform'));
        }
        if ('rejected' === $status && '' === trim((string) $note)) {
            return new WP_Error('reason_required', __('Indica el motivo del rechazo.', 'travel-agency-platform'));
        }
        $wpdb->update(self::table(), [
            'status' => $status,
            'reviewer_note' => '' !== trim((string) $note) ? sanitize_text_field($note) : null,
            'reviewed_by' => (int) $user_id,
            'reviewed_at' => current_time('mysql'),
        ], ['id' => (int) $doc_id]);
        return true;
    }

    /** Agency may submit again only after a rejection, or to add missing docs. */
    public static function mark_resubmitted($agency_id) {
        TAP_Approval::pending($agency_id);
        delete_post_meta($agency_id, 'tap_kyc_rejection_reason');
        update_post_meta($agency_id, 'tap_kyc_resubmitted_at', current_time('mysql'));
        global $wpdb;
        $wpdb->update(self::table(), ['status' => 'pending', 'reviewer_note' => null], ['agency_id' => (int) $agency_id]);
    }

    public static function handle_status() {
        if (!current_user_can('manage_options') && !current_user_can('tap_manage_agencies')) {
            wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform'));
        }
        $doc_id = absint($_POST['doc_id'] ?? 0);
        check_admin_referer('tap_kyc_doc_' . $doc_id);
        $status = sanitize_key(wp_unslash($_POST['kyc_status'] ?? ''));
        $note = sanitize_text_field(wp_unslash($_POST['kyc_note'] ?? ''));
        $res = self::review($doc_id, $status, $note, get_current_user_id());
        $back = wp_get_referer() ?: admin_url('admin.php?page=tap-agencies');
        if (is_wp_error($res)) { $back = add_query_arg('kyc_error', $res->get_error_code(), $back); }
        else {
            global $wpdb;
            $doc = $wpdb->get_row($wpdb->prepare('SELECT agency_id FROM ' . self::table() . ' WHERE id = %d', $doc_id));
            if ($doc && class_exists('TAP_Audit_Log')) {
                TAP_Audit_Log::log('kyc_' . $status, 'agency', (int) $doc->agency_id, $note, (int) $doc->agency_id);
            }
        }
        wp_safe_redirect($back);
        exit;
    }

    public static function handle_upload() {
        if (!is_user_logged_in()) { wp_die(esc_html__('Debes iniciar sesión.', 'travel-agency-platform'), '', ['response' => 403]); }
        $agency_id = absint($_POST['agency_id'] ?? 0);
        check_admin_referer('tap_kyc_upload_' . $agency_id, 'tap_kyc_nonce');
        $linked = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        if ($linked !== $agency_id) { wp_die(esc_html__('No tienes permiso sobre esta agencia.', 'travel-agency-platform'), '', ['response' => 403]); }
        $type = sanitize_key(wp_unslash($_POST['doc_type'] ?? ''));
        $res = self::store($agency_id, $_FILES['kyc_file'] ?? null, $type);
        $back = wp_get_referer() ?: home_url('/mi-cuenta/');
        if (is_wp_error($res)) { $back = add_query_arg('kyc_error', $res->get_error_code(), $back); }
        else {
            self::mark_resubmitted($agency_id);
            $back = add_query_arg('kyc_ok', '1', $back);
        }
        wp_safe_redirect($back);
        exit;
    }

    /** Stream a private document only to its owner or a platform operator. */
    public static function serve() {
        if (!is_user_logged_in()) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $doc_id = absint($_GET['doc_id'] ?? 0);
        check_admin_referer('tap_kyc_serve_' . $doc_id);
        global $wpdb;
        $doc = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $doc_id));
        if (!$doc || !is_readable($doc->file_path)) { wp_die(esc_html__('Documento no encontrado.', 'travel-agency-platform'), '', ['response' => 404]); }
        $allowed = current_user_can('manage_options') || current_user_can('tap_manage_agencies')
            || (int) TAP_Booking::get_agency_for_user(get_current_user_id()) === (int) $doc->agency_id;
        if (!$allowed) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $bytes = file_get_contents($doc->file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $bytes = self::decrypt($bytes);
        if (false === $bytes) { wp_die(esc_html__('No se pudo abrir el documento.', 'travel-agency-platform'), '', ['response' => 500]); }
        nocache_headers();
        header('Content-Type: ' . $doc->mime);
        header('Content-Disposition: inline; filename="' . sanitize_file_name($doc->file_name) . '"');
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary document
        exit;
    }

    /** Upload form for the agency panel (only while approval is not final). */
    public static function render_upload_form($agency_id, $status) {
        $agency_id = (int) $agency_id;
        $docs = self::latest_by_type($agency_id);
        $reason = (string) get_post_meta($agency_id, 'tap_kyc_rejection_reason', true);
        ob_start();
        if ('' !== $reason) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Motivo del rechazo:', 'travel-agency-platform')
                . '</strong> ' . esc_html($reason) . '</p></div>';
        }
        echo '<div class="tap-kyc-uploads"><h3>' . esc_html__('Documentos de verificación', 'travel-agency-platform') . '</h3>';
        foreach (self::types() as $key => $label) {
            $doc = $docs[$key] ?? null;
            echo '<p><strong>' . esc_html($label) . '</strong>: ';
            if ($doc) {
                $url = wp_nonce_url(admin_url('admin-post.php?action=tap_kyc_serve&doc_id=' . (int) $doc->id), 'tap_kyc_serve_' . (int) $doc->id);
                echo '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($doc->file_name) . '</a> · ' . esc_html($doc->status);
                if ('rejected' === $doc->status && $doc->reviewer_note) {
                    echo ' <small style="color:#dc2626;">' . esc_html($doc->reviewer_note) . '</small>';
                }
            } else {
                echo esc_html__('Sin subir', 'travel-agency-platform');
            }
            echo '</p>';
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="tap_kyc_upload">'
            . '<input type="hidden" name="agency_id" value="' . $agency_id . '">';
        wp_nonce_field('tap_kyc_upload_' . $agency_id, 'tap_kyc_nonce');
        echo '<select name="doc_type">';
        foreach (self::types() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> <input type="file" name="kyc_file" accept=".jpg,.jpeg,.png,.pdf" required>';
        echo ' <button class="button">' . esc_html__('Subir documento', 'travel-agency-platform') . '</button></form></div>';
        return ob_get_clean();
    }
}
