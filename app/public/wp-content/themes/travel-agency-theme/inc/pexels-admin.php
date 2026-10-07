<?php
/**
 * Appearance → "Hero Landing (Pexels)" admin screen: search Pexels,
 * preview results and wire them into the landing hero (or the Media Library).
 */
defined('ABSPATH') || exit;

function tap_pexels_admin_page() {
    if (!current_user_can('edit_theme_options')) {
        wp_die(__('No tenés permisos para esto.', 'travel-agency-platform'));
    }

    $saved = '';
    $error = '';
    if (!empty($_POST['tap_pexels_save_key'])) {
        check_admin_referer('tap_pexels_save_key');
        $key = isset($_POST['tap_pexels_api_key']) ? trim((string) $_POST['tap_pexels_api_key']) : '';
        if ('' !== $key) {
            update_option('tap_pexels_api_key', sanitize_text_field($key));
            $saved = __('API key guardada.', 'travel-agency-platform');
        } else {
            $error = __('La API key no puede quedar vacía.', 'travel-agency-platform');
        }
    }

    $has_key = '' !== tap_pexels_get_key();
    $video   = get_theme_mod('tap_hero_video_url', '');
    ?>
    <div class="wrap tap-pexels-admin">
        <h1><?php esc_html_e('Hero Landing · Pexels', 'travel-agency-platform'); ?></h1>
        <p class="description">
            <?php esc_html_e('Buscá videos o fotos (p. ej. "Costa Rica"), previsualizalos y usá uno como fondo del hero de la landing.', 'travel-agency-platform'); ?>
            <?php esc_html_e('Las imágenes se descargan a tu biblioteca de medios; los videos se descargan al sitio o se enlazan directamente.', 'travel-agency-platform'); ?>
        </p>

        <?php if ($saved): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($saved); ?></p></div><?php endif; ?>
        <?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

        <div class="tap-pexels-keybox">
            <h2><?php esc_html_e('API key de Pexels', 'travel-agency-platform'); ?></h2>
            <p class="description">
                <?php
                if ($has_key) {
                    esc_html_e('Hay una API key guardada. Dejá el campo vacío para mantenerla.', 'travel-agency-platform');
                } else {
                    esc_html_e('Guardá tu API key para empezar a buscar.', 'travel-agency-platform');
                }
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('tap_pexels_save_key'); ?>
                <input type="password" name="tap_pexels_api_key" class="regular-text" placeholder="<?php echo $has_key ? '••••••••••••••••' : esc_attr__('pegá tu API key', 'travel-agency-platform'); ?>" autocomplete="off">
                <button type="submit" name="tap_pexels_save_key" value="1" class="button button-primary"><?php esc_html_e('Guardar key', 'travel-agency-platform'); ?></button>
            </form>
        </div>

        <div class="tap-pexels-toolbar">
            <form id="tap-pexels-form">
                <select name="type" id="tap-pexels-type">
                    <option value="videos"><?php esc_html_e('Videos', 'travel-agency-platform'); ?></option>
                    <option value="photos"><?php esc_html_e('Fotos', 'travel-agency-platform'); ?></option>
                </select>
                <input type="search" name="query" id="tap-pexels-query" value="Costa Rica" placeholder="<?php esc_attr_e('¿Qué buscás? p. ej. playa, costa, selva…', 'travel-agency-platform'); ?>">
                <select name="orientation" id="tap-pexels-orientation">
                    <option value="landscape"><?php esc_html_e('Horizontal', 'travel-agency-platform'); ?></option>
                    <option value="portrait"><?php esc_html_e('Vertical', 'travel-agency-platform'); ?></option>
                </select>
                <select name="quality" id="tap-pexels-quality">
                    <option value="auto"><?php esc_html_e('Calidad: liviana (~720p)', 'travel-agency-platform'); ?></option>
                    <option value="1080"><?php esc_html_e('Calidad: alta (~1080p)', 'travel-agency-platform'); ?></option>
                    <option value="max"><?php esc_html_e('Calidad: original', 'travel-agency-platform'); ?></option>
                </select>
                <label class="tap-pexels-dl">
                    <input type="checkbox" id="tap-pexels-download" checked>
                    <?php esc_html_e('Descargar al sitio (recomendado)', 'travel-agency-platform'); ?>
                </label>
                <button type="submit" class="button button-primary" id="tap-pexels-go"><?php esc_html_e('Buscar', 'travel-agency-platform'); ?></button>
            </form>
        </div>

        <div id="tap-pexels-msg" class="tap-pexels-msg" role="status" aria-live="polite"></div>
        <div id="tap-pexels-results" class="tap-pexels-grid"></div>

        <?php if ($video): ?>
        <p class="tap-pexels-current">
            <?php esc_html_e('Video actual del hero:', 'travel-agency-platform'); ?>
            <code><?php echo esc_html($video); ?></code>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=tap_landing_hero')); ?>" class="button button-secondary"><?php esc_html_e('Ajustar en Personalizar', 'travel-agency-platform'); ?></a>
        </p>
        <?php endif; ?>
    </div>
    <?php
}

function tap_pexels_admin_assets($hook) {
    if ('appearance_page_tap-pexels-hero' !== $hook) {
        return;
    }
    wp_enqueue_style('tap-pexels-admin', get_template_directory_uri() . '/assets/css/pexels-admin.css', [], TAT_VERSION);
    wp_enqueue_script('tap-pexels-admin', get_template_directory_uri() . '/assets/js/pexels-admin.js', [], TAT_VERSION, true);
    wp_localize_script('tap-pexels-admin', 'tapPexels', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('tap_pexels_admin'),
        'i18n'    => [
            'searching' => __('Buscando…', 'travel-agency-platform'),
            'empty'     => __('No se encontraron resultados. Probá con otra palabra.', 'travel-agency-platform'),
            'error'     => __('Algo salió mal.', 'travel-agency-platform'),
            'use'       => __('Usar como hero', 'travel-agency-platform'),
            'save'      => __('Guardar en medios', 'travel-agency-platform'),
            'saving'    => __('Guardando…', 'travel-agency-platform'),
            'copied'    => __('URL copiada.', 'travel-agency-platform'),
            'copy'      => __('Copiar URL', 'travel-agency-platform'),
        ],
    ]);
}

add_action('admin_menu', function () {
    add_theme_page(
        __('Hero Landing (Pexels)', 'travel-agency-platform'),
        __('Hero Landing (Pexels)', 'travel-agency-platform'),
        'edit_theme_options',
        'tap-pexels-hero',
        'tap_pexels_admin_page'
    );
});
add_action('admin_enqueue_scripts', 'tap_pexels_admin_assets');
