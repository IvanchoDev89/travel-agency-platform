<?php
/**
 * Pexels integration for the landing hero.
 * Lets admins search Pexels videos/photos and use them as the hero
 * background (or grab images into the Media Library). The API key is
 * stored in the options table (never hardcoded) — set it on the admin
 * page or define TAP_PEXELS_API_KEY in wp-config.php.
 */
defined('ABSPATH') || exit;

function tap_pexels_get_key() {
    if (defined('TAP_PEXELS_API_KEY') && TAP_PEXELS_API_KEY) {
        return (string) TAP_PEXELS_API_KEY;
    }
    return (string) get_option('tap_pexels_api_key', '');
}

/**
 * Cached API GET for search endpoints: same path+args within 15 minutes reuse
 * the previous response (searches are the bulk of the Pexels quota and they
 * repeat constantly from the admin panel). Errors are not cached.
 */
function tap_pexels_cached($path, $args = []) {
    $key = 'tap_pexels_' . md5($path . '|' . wp_json_encode($args) . '|' . tap_pexels_get_key());
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $body = tap_pexels_api_get($path, $args);
    if (is_wp_error($body)) {
        return $body;
    }
    set_transient($key, $body, 15 * MINUTE_IN_SECONDS);
    return $body;
}

function tap_pexels_api_get($path, $args = []) {
    $key = tap_pexels_get_key();
    if ('' === $key) {
        return new WP_Error('tap_pexels_no_key', __('Falta la API key de Pexels.', 'travel-agency-platform'));
    }
    $url = add_query_arg(array_merge(['per_page' => 12], $args), 'https://api.pexels.com/' . ltrim($path, '/'));
    $res = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => ['Authorization' => $key],
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        $msg = isset($body['error']) ? (string) $body['error'] : 'HTTP ' . $code;
        return new WP_Error('tap_pexels_api', sprintf(__('Pexels respondió: %s', 'travel-agency-platform'), $msg));
    }
    return $body;
}

/** Search videos. Returns a normalized list or WP_Error. Results are cached
 * for 15 minutes to spare the Pexels quota on repeated searches. */
function tap_pexels_search_videos($query, $orientation = 'landscape', $per_page = 12) {
    $args = [
        'query'       => sanitize_text_field($query),
        'orientation' => sanitize_key($orientation),
        'per_page'    => max(1, min(24, (int) $per_page)),
    ];
    $body = tap_pexels_cached('videos/search', $args);
    if (is_wp_error($body)) {
        return $body;
    }
    $out = [];
    foreach ((array) ($body['videos'] ?? []) as $v) {
        $files = [];
        foreach ((array) ($v['video_files'] ?? []) as $f) {
            if (($f['file_type'] ?? '') !== 'video/mp4') { continue; }
            $files[] = [
                'quality' => (string) ($f['quality'] ?? ''),
                'width'   => (int) ($f['width'] ?? 0),
                'link'    => (string) ($f['link'] ?? ''),
            ];
        }
        usort($files, function ($a, $b) {
            return tap_pexels_compare_files($a, $b, 'auto');
        });
        $out[] = [
            'id'       => (int) ($v['id'] ?? 0),
            'duration' => (int) ($v['duration'] ?? 0),
            'image'    => (string) ($v['image'] ?? ''),
            'url'      => (string) ($v['url'] ?? ''),
            'width'    => (int) ($v['width'] ?? 0),
            'height'   => (int) ($v['height'] ?? 0),
            'files'    => array_slice($files, 0, 3),
        ];
    }
    return $out;
}

/** Search photos. Returns a normalized list or WP_Error. Results are cached
 * for 15 minutes to spare the Pexels quota on repeated searches. */
function tap_pexels_search_photos($query, $orientation = 'landscape', $per_page = 12) {
    $args = [
        'query'       => sanitize_text_field($query),
        'orientation' => sanitize_key($orientation),
        'per_page'    => max(1, min(24, (int) $per_page)),
    ];
    $body = tap_pexels_cached('v1/search', $args);
    if (is_wp_error($body)) {
        return $body;
    }
    $out = [];
    foreach ((array) ($body['photos'] ?? []) as $p) {
        $src = $p['src']['large2x'] ?? ($p['src']['large'] ?? ($p['src']['original'] ?? ''));
        $out[] = [
            'id'       => (int) ($p['id'] ?? 0),
            'image'    => (string) ($p['image'] ?? $src),
            'url'      => (string) ($p['url'] ?? ''),
            'width'    => (int) ($p['width'] ?? 0),
            'height'   => (int) ($p['height'] ?? 0),
            'alt'      => (string) ($p['alt'] ?? ''),
            'download' => (string) ($p['src']['original'] ?? ''),
        ];
    }
    return $out;
}

/** Fetch full video data for one id (picks the best mp4 file). */
function tap_pexels_get_video($video_id) {
    $video_id = (int) $video_id;
    $body = tap_pexels_api_get('videos/videos/' . $video_id, ['per_page' => 1]);
    if (is_wp_error($body)) {
        return $body;
    }
    if (empty($body['video_files'])) {
        return new WP_Error('tap_pexels_no_file', __('Ese video no tiene archivos disponibles.', 'travel-agency-platform'));
    }
    return $body;
}

/**
 * Download a remote file into the Media Library.
 * Returns attachment id or WP_Error.
 */
function tap_pexels_sideload($url, $name) {
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $tmp = download_url($url, 180);
    if (is_wp_error($tmp)) {
        return $tmp;
    }
    $file_array = ['name' => sanitize_file_name($name), 'tmp_name' => $tmp];
    $att = media_handle_sideload($file_array, 0);
    if (is_wp_error($att)) {
        @unlink($tmp);
        return $att;
    }
    return (int) $att;
}

/**
 * Score an mp4 file for use as a web hero background: prefer HD quality,
 * prefer the biggest file that is still <= 1920px wide (1080p) so we do
 * not ship 40MB 4K files, and only fall back to 4K when nothing else fits.
 */
function tap_pexels_score_file($f, $mode = 'auto') {
    $w     = (int) ($f['width'] ?? 0);
    $hd    = 'hd' === strtolower((string) ($f['quality'] ?? ''));
    $cap   = '1080' === $mode ? 1920 : ('max' === $mode ? 99999 : 1280);
    $score = 0;
    if ($w > 0 && $w <= $cap) {
        $score = 2000 + $w;          // within cap, bigger is better
    } elseif ($w > $cap) {
        $score = 1000 - intdiv($w - $cap, 100); // over cap, penalised
    }
    if ($hd) { $score += 500; }
    return $score;
}

function tap_pexels_compare_files($a, $b, $mode = 'auto') {
    $sa = tap_pexels_score_file($a, $mode);
    $sb = tap_pexels_score_file($b, $mode);
    if ($sa === $sb) { return ((int) ($b['width'] ?? 0)) - ((int) ($a['width'] ?? 0)); }
    return $sb - $sa;
}

/**
 * Pick the best mp4 file for the hero.
 * $mode: auto (<=720p, light), 1080 (<=1080p), max (original).
 */
function tap_pexels_best_file($video, $mode = 'auto') {
    $files = [];
    foreach ((array) ($video['video_files'] ?? []) as $f) {
        if (($f['file_type'] ?? '') !== 'video/mp4') { continue; }
        if (empty($f['link'])) { continue; }
        $files[] = $f;
    }
    if (!$files) { return null; }
    usort($files, function ($a, $b) use ($mode) {
        return tap_pexels_compare_files($a, $b, $mode);
    });
    return $files[0];
}

/* ── AJAX ─────────────────────────────────────────────────────────── */

function tap_pexels_ajax_guard() {
    check_ajax_referer('tap_pexels_admin', 'nonce');
    if (!current_user_can('edit_theme_options')) {
        wp_send_json_error(['message' => __('No tenés permisos para esto.', 'travel-agency-platform')], 403);
    }
}

function tap_pexels_ajax_search() {
    tap_pexels_ajax_guard();
    $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'videos';
    $q    = isset($_POST['query']) ? trim((string) $_POST['query']) : '';
    $or   = isset($_POST['orientation']) ? sanitize_key($_POST['orientation']) : 'landscape';
    if ('' === $q) {
        wp_send_json_error(['message' => __('Escribí qué querés buscar.', 'travel-agency-platform')]);
    }
    $results = 'photos' === $type
        ? tap_pexels_search_photos($q, $or)
        : tap_pexels_search_videos($q, $or);
    if (is_wp_error($results)) {
        wp_send_json_error(['message' => $results->get_error_message()]);
    }
    wp_send_json_success(['results' => $results, 'type' => $type]);
}

/**
 * Resolve a Pexels video id into a usable background (URL + poster), optionally
 * downloading it into the Media Library. Shared by the hero and login actions.
 *
 * @return array{url:string,poster:string,note:string}|WP_Error
 */
function tap_pexels_resolve_media($id, $download, $mode) {
    $video = tap_pexels_get_video($id);
    if (is_wp_error($video)) {
        return $video;
    }
    $file = tap_pexels_best_file($video, $mode);
    if (!$file || empty($file['link'])) {
        return new WP_Error('tap_pexels_no_file', __('No se encontró un archivo mp4.', 'travel-agency-platform'));
    }

    $video_url = (string) $file['link'];
    $poster    = (string) ($video['image'] ?? '');
    $note      = '';

    if ($download) {
        if (function_exists('set_time_limit')) { @set_time_limit(300); }
        $att = tap_pexels_sideload($file['link'], 'pexels-video-' . $id . '.mp4');
        if (!is_wp_error($att)) {
            // Root-relative: survives the site host/port changing (Local).
            $video_url = (string) tap_media_url(wp_get_attachment_url($att));
        } else {
            $note = __('No se pudo descargar el video; se usará el enlace de Pexels.', 'travel-agency-platform');
        }
        if ($poster) {
            $pAtt = tap_pexels_sideload($poster, 'pexels-poster-' . $id . '.jpg');
            if (!is_wp_error($pAtt)) {
                $poster = (string) tap_media_url(wp_get_attachment_url($pAtt));
            }
        }
    }

    return ['url' => $video_url, 'poster' => $poster, 'note' => $note];
}

function tap_pexels_ajax_use_video() {
    tap_pexels_ajax_guard();
    $id       = isset($_POST['video_id']) ? absint($_POST['video_id']) : 0;
    $download = !empty($_POST['download']);
    $mode     = isset($_POST['quality']) ? sanitize_key($_POST['quality']) : 'auto';
    if (!in_array($mode, ['auto', '1080', 'max'], true)) { $mode = 'auto'; }
    if (!$id) {
        wp_send_json_error(['message' => __('Video inválido.', 'travel-agency-platform')]);
    }

    $media = tap_pexels_resolve_media($id, $download, $mode);
    if (is_wp_error($media)) {
        wp_send_json_error(['message' => $media->get_error_message()]);
    }

    set_theme_mod('tap_hero_video_url', $media['url']);
    if ('' !== $media['poster']) {
        set_theme_mod('tap_hero_video_poster', $media['poster']);
    }

    wp_send_json_success([
        'message'   => __('Hero actualizado con el video de Pexels.', 'travel-agency-platform'),
        'note'      => $media['note'],
        'video'     => $media['url'],
        'poster'    => $media['poster'],
        'customizer'=> admin_url('customize.php?autofocus[section]=tap_landing_hero'),
    ]);
}

function tap_pexels_ajax_use_login_video() {
    tap_pexels_ajax_guard();
    $id       = isset($_POST['video_id']) ? absint($_POST['video_id']) : 0;
    $download = !empty($_POST['download']);
    $mode     = isset($_POST['quality']) ? sanitize_key($_POST['quality']) : 'auto';
    if (!in_array($mode, ['auto', '1080', 'max'], true)) { $mode = 'auto'; }
    if (!$id) {
        wp_send_json_error(['message' => __('Video inválido.', 'travel-agency-platform')]);
    }

    $media = tap_pexels_resolve_media($id, $download, $mode);
    if (is_wp_error($media)) {
        wp_send_json_error(['message' => $media->get_error_message()]);
    }

    set_theme_mod('tap_login_video_url', $media['url']);
    if ('' !== $media['poster']) {
        set_theme_mod('tap_login_video_poster', $media['poster']);
    }

    wp_send_json_success([
        'message'   => __('Login actualizado con el video de Pexels.', 'travel-agency-platform'),
        'note'      => $media['note'],
        'video'     => $media['url'],
        'poster'    => $media['poster'],
        'customizer'=> admin_url('customize.php?autofocus[section]=tap_login'),
    ]);
}

function tap_pexels_ajax_use_photo() {
    tap_pexels_ajax_guard();
    $id   = isset($_POST['photo_id']) ? absint($_POST['photo_id']) : 0;
    $url  = isset($_POST['photo_url']) ? esc_url_raw((string) $_POST['photo_url']) : '';
    if (!$id || !$url) {
        wp_send_json_error(['message' => __('Foto inválida.', 'travel-agency-platform')]);
    }
    // Only media straight from the Pexels CDN may be sideloaded: the URL comes
    // from the client, and an open download endpoint would double as a proxy.
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($host !== 'pexels.com' && !preg_match('/(^|\.)pexels\.com$/', $host)) {
        wp_send_json_error(['message' => __('Solo se puede descargar contenido de Pexels.', 'travel-agency-platform')]);
    }
    if (function_exists('set_time_limit')) { @set_time_limit(300); }
    $att = tap_pexels_sideload($url, 'pexels-photo-' . $id . '.jpg');
    if (is_wp_error($att)) {
        wp_send_json_error(['message' => $att->get_error_message()]);
    }
    wp_send_json_success([
        'message' => __('Imagen guardada en la biblioteca de medios.', 'travel-agency-platform'),
        'url'     => (string) wp_get_attachment_url($att),
        'edit'    => admin_url('post.php?post=' . $att . '&action=edit'),
    ]);
}

add_action('wp_ajax_tap_pexels_search', 'tap_pexels_ajax_search');
add_action('wp_ajax_tap_pexels_use_video', 'tap_pexels_ajax_use_video');
add_action('wp_ajax_tap_pexels_use_login_video', 'tap_pexels_ajax_use_login_video');
add_action('wp_ajax_tap_pexels_use_photo', 'tap_pexels_ajax_use_photo');
