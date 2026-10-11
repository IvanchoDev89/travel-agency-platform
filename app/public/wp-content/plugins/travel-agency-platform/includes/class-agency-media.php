<?php
defined('ABSPATH') || exit;

/**
 * Agency listing media: the upload handler, the media sideload used by the\n * listing and room savers, and the attachment/url helpers behind them. Split\n * from TAP_Ajax so the write handlers stay in one auditable file.
 */
class TAP_Agency_Media {
    public static function agency_upload_image() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión para subir imágenes.', 'travel-agency-platform')], 403);
        }
        $user_id = get_current_user_id();
        if (!user_can($user_id, 'upload_files') && !user_can($user_id, 'manage_options')) {
            wp_send_json_error(['message' => __('Tu cuenta no tiene permiso para subir imágenes.', 'travel-agency-platform')], 403);
        }

        $listing_type = isset($_POST['listing_type']) ? sanitize_key(wp_unslash($_POST['listing_type'])) : 'tap_accommodation';
        $listing_id   = isset($_POST['listing_id']) ? absint($_POST['listing_id']) : 0;
        if ($listing_id && !TAP_Ajax::agency_owns_listing($listing_id, $listing_type)) {
            wp_send_json_error(['message' => __('Solo puedes adjuntar imágenes a tus propios listados.', 'travel-agency-platform')], 403);
        }

        // Unit images are parented to the unit, but ownership is always checked
        // against the accommodation they belong to.
        $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
        if ($parent_id && $parent_id !== $listing_id) {
            $parent_ok = 'tap_room' === get_post_type($parent_id)
                && (int) get_post_meta($parent_id, '_tap_room_accommodation_id', true) === $listing_id;
            if (!$parent_ok) {
                wp_send_json_error(['message' => __('No puedes adjuntar imágenes a esa unidad.', 'travel-agency-platform')], 403);
            }
        } else {
            $parent_id = $listing_id;
        }

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            wp_send_json_error(['message' => __('No se recibió ningún archivo.', 'travel-agency-platform')]);
        }

        $error = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => __('La imagen supera el tamaño permitido por el servidor.', 'travel-agency-platform'),
                UPLOAD_ERR_FORM_SIZE  => __('La imagen supera el tamaño permitido.', 'travel-agency-platform'),
                UPLOAD_ERR_PARTIAL    => __('La imagen se subió incompleta. Intenta de nuevo.', 'travel-agency-platform'),
                UPLOAD_ERR_NO_FILE    => __('No se seleccionó ningún archivo.', 'travel-agency-platform'),
                UPLOAD_ERR_NO_TMP_DIR => __('Error del servidor: no hay carpeta temporal.', 'travel-agency-platform'),
                UPLOAD_ERR_CANT_WRITE => __('Error del servidor: no se pudo escribir el archivo.', 'travel-agency-platform'),
                UPLOAD_ERR_EXTENSION  => __('Una extensión de PHP bloqueó la subida.', 'travel-agency-platform'),
            ];
            wp_send_json_error(['message' => $messages[$error] ?? __('No se pudo subir la imagen.', 'travel-agency-platform')]);
        }

        $result = self::store_uploaded_image($_FILES['file'], $parent_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }


    public static function store_uploaded_image($file, $parent_id = 0) {
        $file = (array) $file;
        $name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
        $tmp  = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        if ('' === $name || '' === $tmp || !file_exists($tmp)) {
            return new WP_Error('tap_upload_missing', __('No se recibió ningún archivo.', 'travel-agency-platform'));
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::allowed_image_extensions(), true)) {
            return new WP_Error('tap_upload_type', __('Formato no permitido. Usa JPG, PNG, WEBP o GIF.', 'travel-agency-platform'));
        }

        $max   = self::agency_image_max_bytes();
        $bytes = isset($file['size']) && (int) $file['size'] > 0 ? (int) $file['size'] : (int) filesize($tmp);
        if ($max > 0 && $bytes > $max) {
            return new WP_Error('tap_upload_size', sprintf(
                /* translators: %s: formatted size limit */
                __('La imagen supera el tamaño máximo permitido (%s).', 'travel-agency-platform'),
                size_format($max)
            ));
        }

        // Verify it really is an image (blocks renamed executables).
        $check = wp_check_filetype_and_ext($tmp, $name);
        $mime  = !empty($check['type']) ? $check['type'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return new WP_Error('tap_upload_invalid', __('El archivo no es una imagen válida.', 'travel-agency-platform'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $parent_id  = (int) $parent_id;
        $file_array = [
            'name'     => $name,
            'type'     => $mime,
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => $bytes,
        ];

        $overrides = ['test_form' => false];
        if (function_exists('is_uploaded_file') && is_uploaded_file($tmp)) {
            $handled = wp_handle_upload($file_array, $overrides);
            if (isset($handled['error']) && !is_numeric($handled['error'])) {
                return $handled;
            }
            $file_array['tmp_name'] = $handled['file'];
        }

        $att_id = media_handle_sideload($file_array, $parent_id);
        if (is_wp_error($att_id)) {
            return $att_id;
        }

        $meta = wp_get_attachment_metadata($att_id);
        $url  = wp_get_attachment_url($att_id);
        return [
            'attachment_id' => (int) $att_id,
            'url'           => $url ? $url : '',
            'thumb'         => wp_get_attachment_image_url($att_id, 'medium') ?: $url,
            'width'         => isset($meta['width']) ? (int) $meta['width'] : 0,
            'height'        => isset($meta['height']) ? (int) $meta['height'] : 0,
        ];
    }


    public static function agency_image_max_bytes() {
        return (int) apply_filters('tap_agency_image_max_bytes', 8 * MB_IN_BYTES);
    }


    public static function allowed_image_extensions() {
        return apply_filters('tap_allowed_image_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
    }


    public static function sideload_image($url) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url = esc_url_raw(trim((string) $url));
        if (!$url || !self::url_is_public_host($url)) {
            return 0;
        }

        $tmp = download_url($url, 15);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $parse = wp_parse_url($url);
        $name  = !empty($parse['path']) ? basename($parse['path']) : '';
        $file_array = [
            'name'     => $name ? sanitize_file_name($name) : 'tap-image.jpg',
            'tmp_name' => $tmp,
        ];

        $att_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return 0;
        }
        return (int) $att_id;
    }


    public static function is_public_ip($ip) {
        $long = ip2long((string) $ip);
        if (false === $long) {
            return false;
        }
        // 100.64.0.0/10 (CGNAT / cloud metadata) is not in the standard ranges.
        if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
            return false;
        }
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }


    public static function url_is_public_host($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        if (!$host || !wp_http_validate_url((string) $url)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip) {
            return self::is_public_ip($ip);
        }

        $resolved = gethostbynamel((string) $host);
        if (!$resolved || !is_array($resolved)) {
            return false;
        }
        foreach ($resolved as $address) {
            if (!self::is_public_ip($address)) {
                return false;
            }
        }
        return true;
    }


    public static function attachments_by_url($ids) {
        $map = [];
        foreach ((array) $ids as $id) {
            $url = wp_get_attachment_url((int) $id);
            if ($url) {
                $map[$url] = (int) $id;
            }
        }
        return $map;
    }


    public static function usable_attachment_ids($raw, $limit = 0) {
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
        $uid = get_current_user_id();
        $is_admin = user_can($uid, 'manage_options');
        $out = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if (!$id || isset($out[$id])) {
                continue;
            }
            $att = get_post($id);
            if (!$att || 'attachment' !== $att->post_type) {
                continue;
            }
            if (0 !== strpos((string) get_post_mime_type($id), 'image/')) {
                continue;
            }
            if (!$is_admin && (int) $att->post_author !== $uid) {
                continue;
            }
            $out[$id] = $id;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

}
