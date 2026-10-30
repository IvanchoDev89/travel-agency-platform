<?php
defined('ABSPATH') || exit;

/**
 * TAP_Landing_Builder — lightweight landing page builder.
 *
 * Stores a JSON config in wp_options (tap_landing_config) with an ordered
 * list of sections. Each section has a type, enabled flag, order, and settings.
 * The front-end renders only enabled sections in order.
 *
 * Design goals:
 * - No page-builder overhead (no iframes, no shadow DOM, no React/Vue).
 * - Server-rendered (PHP) for SEO and performance.
 * - Minimal assets: only CSS/JS of enabled sections.
 * - Cache via transient, invalidated on save.
 */
class TAP_Landing_Builder {

    const OPTION = 'tap_landing_config';
    const CACHE  = 'tap_landing_rendered';
    const VERSION = 1;

    /**
     * Default section types available in the builder.
     */
    public static function section_types() {
        return [
            'hero'          => __('Hero', 'travel-agency-platform'),
            'categories'    => __('Categorías', 'travel-agency-platform'),
            'featured'      => __('Servicios destacados', 'travel-agency-platform'),
            'destinations'  => __('Destinos', 'travel-agency-platform'),
            'blog'          => __('Blog', 'travel-agency-platform'),
            'newsletter'    => __('Newsletter', 'travel-agency-platform'),
            'testimonials'  => __('Testimonios', 'travel-agency-platform'),
            'cta'           => __('CTA final', 'travel-agency-platform'),
            'stats'         => __('Estadísticas', 'travel-agency-platform'),
            'video'         => __('Video', 'travel-agency-platform'),
            'text'          => __('Texto libre', 'travel-agency-platform'),
            'gallery'       => __('Galería', 'travel-agency-platform'),
        ];
    }

    /**
     * Default config (migrates the current landing to the new structure).
     */
    public static function default_config() {
        return [
            'version'  => self::VERSION,
            'sections' => [
                ['type' => 'hero', 'enabled' => true, 'order' => 1, 'settings' => []],
                ['type' => 'categories', 'enabled' => true, 'order' => 2, 'settings' => []],
                ['type' => 'featured', 'enabled' => true, 'order' => 3, 'settings' => ['count' => 6, 'mode' => 'auto']],
                ['type' => 'destinations', 'enabled' => true, 'order' => 4, 'settings' => ['show_map' => true]],
                ['type' => 'blog', 'enabled' => true, 'order' => 5, 'settings' => ['count' => 3]],
                ['type' => 'newsletter', 'enabled' => true, 'order' => 6, 'settings' => []],
                ['type' => 'cta', 'enabled' => true, 'order' => 7, 'settings' => []],
            ],
        ];
    }

    /**
     * Load config from DB (with fallback to default).
     */
    public static function load() {
        $config = get_option(self::OPTION, null);
        if (!is_array($config) || empty($config['sections'])) {
            $config = self::default_config();
            update_option(self::OPTION, $config, false);
        }
        // Ensure version.
        if (!isset($config['version'])) {
            $config['version'] = self::VERSION;
        }
        // Sort sections by order.
        usort($config['sections'], function ($a, $b) {
            return (int) ($a['order'] ?? 0) - (int) ($b['order'] ?? 0);
        });
        return $config;
    }

    /**
     * Save config to DB.
     */
    public static function save($config) {
        if (!is_array($config) || empty($config['sections'])) {
            return false;
        }
        // Sanitize.
        $clean = [
            'version'  => self::VERSION,
            'sections' => [],
        ];
        $order = 1;
        foreach ($config['sections'] as $sec) {
            if (!isset($sec['type']) || !array_key_exists($sec['type'], self::section_types())) {
                continue;
            }
            $clean['sections'][] = [
                'type'     => sanitize_key($sec['type']),
                'enabled'  => !empty($sec['enabled']),
                'order'    => $order++,
                'settings' => is_array($sec['settings'] ?? null) ? $sec['settings'] : [],
            ];
        }
        update_option(self::OPTION, $clean, false);
        // Invalidate cache.
        delete_transient(self::CACHE);
        return true;
    }

    /**
     * Get enabled sections in order.
     */
    public static function enabled_sections() {
        $config = self::load();
        $out = [];
        foreach ($config['sections'] as $sec) {
            if (!empty($sec['enabled'])) {
                $out[] = $sec;
            }
        }
        return $out;
    }

    /**
     * Render the landing page (returns HTML).
     */
    public static function render() {
        // Check cache first.
        $cached = get_transient(self::CACHE);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $sections = self::enabled_sections();
        ob_start();
        echo '<div class="tap-landing-builder">';
        foreach ($sections as $sec) {
            $type = $sec['type'];
            $settings = $sec['settings'] ?? [];
            $file = TAP_PLUGIN_DIR . '/templates/landing/' . $type . '.php';
            // Theme override.
            $theme_file = get_template_directory() . '/landing/' . $type . '.php';
            if (file_exists($theme_file)) {
                $file = $theme_file;
            }
            if (file_exists($file)) {
                include $file;
            }
        }
        echo '</div>';
        $html = ob_get_clean();

        // Cache for 1 hour.
        set_transient(self::CACHE, $html, HOUR_IN_SECONDS);
        return $html;
    }

    /**
     * Invalidate cache (call on config save).
     */
    public static function invalidate_cache() {
        delete_transient(self::CACHE);
    }

    /**
     * Check if a section type is enabled in the config.
     */
    public static function is_enabled($type) {
        $config = self::load();
        foreach ($config['sections'] as $sec) {
            if (($sec['type'] ?? '') === $type) {
                return !empty($sec['enabled']);
            }
        }
        return true; // default enabled if not in config
    }

    /**
     * Get sections in order (enabled only).
     */
    public static function ordered_sections() {
        return self::enabled_sections();
    }

    /**
     * Get a specific section's settings.
     */
    public static function section_settings($type) {
        $config = self::load();
        foreach ($config['sections'] as $sec) {
            if (($sec['type'] ?? '') === $type) {
                return is_array($sec['settings'] ?? null) ? $sec['settings'] : [];
            }
        }
        return [];
    }

    /**
     * Admin page: tap-landing (visual editor with drag & drop).
     */
    public static function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permiso.', 'travel-agency-platform'));
        }
        $config = self::load();
        $types = self::section_types();
        $saved = isset($_GET['saved']);
        $error = isset($_GET['error']);
        ?>
        <div class="wrap tap-landing-builder-admin">
            <h1><?php esc_html_e('Landing Page Builder', 'travel-agency-platform'); ?></h1>
            <p class="description"><?php esc_html_e('Arrastra las secciones para reordenarlas. Activa/desactiva con el toggle. Haz clic en "Editar" para configurar cada sección.', 'travel-agency-platform'); ?></p>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Configuración guardada.', 'travel-agency-platform'); ?></p></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php esc_html_e('Error al guardar.', 'travel-agency-platform'); ?></p></div>
            <?php endif; ?>

            <div class="tap-landing-toolbar">
                <button type="button" class="button button-primary" id="tap-lb-save"><?php esc_html_e('Guardar cambios', 'travel-agency-platform'); ?></button>
                <button type="button" class="button" id="tap-lb-add"><?php esc_html_e('+ Agregar sección', 'travel-agency-platform'); ?></button>
                <span id="tap-lb-status" style="margin-left:12px;color:#666;"></span>
            </div>

            <div id="tap-lb-sections" class="tap-lb-sections">
                <?php foreach ($config['sections'] as $i => $sec): ?>
                    <div class="tap-lb-section" data-type="<?php echo esc_attr($sec['type']); ?>" data-index="<?php echo (int) $i; ?>">
                        <div class="tap-lb-handle">☰</div>
                        <div class="tap-lb-info">
                            <strong><?php echo esc_html($types[$sec['type']] ?? $sec['type']); ?></strong>
                            <span class="tap-lb-type"><?php echo esc_html($sec['type']); ?></span>
                        </div>
                        <div class="tap-lb-actions">
                            <label class="tap-lb-toggle">
                                <input type="checkbox" class="tap-lb-enabled" <?php checked(!empty($sec['enabled'])); ?>>
                                <?php esc_html_e('Activa', 'travel-agency-platform'); ?>
                            </label>
                            <button type="button" class="button button-small tap-lb-edit"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></button>
                            <button type="button" class="button button-small tap-lb-remove"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                        </div>
                        <div class="tap-lb-settings" style="display:none;">
                            <?php self::render_section_settings($sec['type'], $sec['settings'] ?? []); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="tap-lb-add-panel" style="display:none;margin-top:16px;padding:16px;border:1px solid #ddd;border-radius:8px;background:#f9f9f9;">
                <label><?php esc_html_e('Tipo de sección:', 'travel-agency-platform'); ?></label>
                <select id="tap-lb-new-type">
                    <?php foreach ($types as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" id="tap-lb-confirm-add"><?php esc_html_e('Agregar', 'travel-agency-platform'); ?></button>
                <button type="button" class="button" id="tap-lb-cancel-add"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
            </div>
        </div>

        <!-- SortableJS -->
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <script>
        (function(){
            var list = document.getElementById('tap-lb-sections');
            if (!list) return;
            var sortable = new Sortable(list, {
                handle: '.tap-lb-handle',
                animation: 150,
                ghostClass: 'tap-lb-ghost',
            });

            // Toggle settings panel.
            list.addEventListener('click', function(e){
                if (e.target.classList.contains('tap-lb-edit')) {
                    var sec = e.target.closest('.tap-lb-section');
                    var panel = sec.querySelector('.tap-lb-settings');
                    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
                }
                if (e.target.classList.contains('tap-lb-remove')) {
                    if (confirm('<?php echo esc_js(__('¿Eliminar esta sección?', 'travel-agency-platform')); ?>')) {
                        e.target.closest('.tap-lb-section').remove();
                    }
                }
            });

            // Add section.
            var addBtn = document.getElementById('tap-lb-add');
            var addPanel = document.getElementById('tap-lb-add-panel');
            if (addBtn && addPanel) {
                addBtn.addEventListener('click', function(){ addPanel.style.display = 'block'; });
                document.getElementById('tap-lb-cancel-add').addEventListener('click', function(){ addPanel.style.display = 'none'; });
                document.getElementById('tap-lb-confirm-add').addEventListener('click', function(){
                    var type = document.getElementById('tap-lb-new-type').value;
                    var label = document.getElementById('tap-lb-new-type').selectedOptions[0].text;
                    var div = document.createElement('div');
                    div.className = 'tap-lb-section';
                    div.setAttribute('data-type', type);
                    div.innerHTML = '<div class="tap-lb-handle">☰</div>'
                        + '<div class="tap-lb-info"><strong>' + label + '</strong><span class="tap-lb-type">' + type + '</span></div>'
                        + '<div class="tap-lb-actions">'
                        + '<label class="tap-lb-toggle"><input type="checkbox" class="tap-lb-enabled" checked> <?php echo esc_js(__('Activa', 'travel-agency-platform')); ?></label>'
                        + '<button type="button" class="button button-small tap-lb-edit"><?php echo esc_js(__('Editar', 'travel-agency-platform')); ?></button>'
                        + '<button type="button" class="button button-small tap-lb-remove"><?php echo esc_js(__('Eliminar', 'travel-agency-platform')); ?></button>'
                        + '</div>'
                        + '<div class="tap-lb-settings" style="display:none;"></div>';
                    list.appendChild(div);
                    addPanel.style.display = 'none';
                });
            }

            // Save via AJAX.
            document.getElementById('tap-lb-save').addEventListener('click', function(){
                var status = document.getElementById('tap-lb-status');
                status.textContent = '<?php echo esc_js(__('Guardando...', 'travel-agency-platform')); ?>';
                var sections = [];
                list.querySelectorAll('.tap-lb-section').forEach(function(sec, i){
                    var settings = {};
                    sec.querySelectorAll('.tap-lb-settings input, .tap-lb-settings select, .tap-lb-settings textarea').forEach(function(inp){
                        if (inp.name) {
                            if (inp.type === 'checkbox') settings[inp.name] = inp.checked ? '1' : '0';
                            else settings[inp.name] = inp.value;
                        }
                    });
                    sections.push({
                        type: sec.getAttribute('data-type'),
                        enabled: sec.querySelector('.tap-lb-enabled').checked,
                        order: i + 1,
                        settings: settings,
                    });
                });
                var data = new FormData();
                data.append('action', 'tap_landing_save_ajax');
                data.append('nonce', '<?php echo wp_create_nonce('tap_landing_save_ajax'); ?>');
                data.append('config', JSON.stringify({version: <?php echo (int) self::VERSION; ?>, sections: sections}));

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) {
                            status.textContent = '<?php echo esc_js(__('Guardado.', 'travel-agency-platform')); ?>';
                            status.style.color = 'green';
                        } else {
                            status.textContent = res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error.', 'travel-agency-platform')); ?>';
                            status.style.color = 'red';
                        }
                    })
                    .catch(function(){
                        status.textContent = '<?php echo esc_js(__('Error de conexión.', 'travel-agency-platform')); ?>';
                        status.style.color = 'red';
                    });
            });
        })();
        </script>

        <style>
        .tap-lb-sections { margin-top: 16px; }
        .tap-lb-section { display: flex; align-items: center; gap: 12px; padding: 12px 16px; margin-bottom: 8px; background: #fff; border: 1px solid #ddd; border-radius: 8px; cursor: move; transition: box-shadow .2s; }
        .tap-lb-section:hover { box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .tap-lb-ghost { opacity: .4; background: #f0f0f0; }
        .tap-lb-handle { cursor: grab; font-size: 18px; color: #999; padding: 4px; }
        .tap-lb-handle:active { cursor: grabbing; }
        .tap-lb-info { flex: 1; }
        .tap-lb-info strong { display: block; font-size: 14px; }
        .tap-lb-type { font-size: 12px; color: #888; }
        .tap-lb-actions { display: flex; align-items: center; gap: 8px; }
        .tap-lb-toggle { display: flex; align-items: center; gap: 4px; font-size: 13px; }
        .tap-lb-settings { flex-basis: 100%; margin-top: 12px; padding: 12px; border-top: 1px solid #eee; }
        .tap-lb-settings label { display: block; margin-bottom: 8px; font-size: 13px; }
        .tap-lb-settings input, .tap-lb-settings select, .tap-lb-settings textarea { width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; }
        .tap-lb-toolbar { margin: 16px 0; display: flex; align-items: center; gap: 8px; }
        </style>
        <?php
    }

    /**
     * Render settings fields for a section type.
     */
    public static function render_section_settings($type, $settings) {
        switch ($type) {
            case 'hero':
                echo '<label>' . esc_html__('Título', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="title" value="' . esc_attr($settings['title'] ?? '') . '">';
                echo '<label>' . esc_html__('Subtítulo', 'travel-agency-platform') . '</label>';
                echo '<textarea name="subtitle" rows="2">' . esc_textarea($settings['subtitle'] ?? '') . '</textarea>';
                echo '<label>' . esc_html__('Imagen de fondo (URL)', 'travel-agency-platform') . '</label>';
                echo '<input type="url" name="background" value="' . esc_attr($settings['background'] ?? '') . '">';
                echo '<label><input type="checkbox" name="show_search" value="1" ' . checked(1, $settings['show_search'] ?? 1, false) . '> ' . esc_html__('Mostrar buscador', 'travel-agency-platform') . '</label>';
                break;
            case 'featured':
                echo '<label>' . esc_html__('Título', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="title" value="' . esc_attr($settings['title'] ?? '') . '">';
                echo '<label>' . esc_html__('Cantidad', 'travel-agency-platform') . '</label>';
                echo '<input type="number" name="count" value="' . esc_attr($settings['count'] ?? 6) . '" min="3" max="12">';
                echo '<label>' . esc_html__('Modo', 'travel-agency-platform') . '</label>';
                echo '<select name="mode"><option value="auto"' . selected('auto', $settings['mode'] ?? 'auto', false) . '>Automático (por rating)</option><option value="manual"' . selected('manual', $settings['mode'] ?? 'auto', false) . '>Manual</option></select>';
                break;
            case 'blog':
                echo '<label>' . esc_html__('Título', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="title" value="' . esc_attr($settings['title'] ?? '') . '">';
                echo '<label>' . esc_html__('Cantidad de posts', 'travel-agency-platform') . '</label>';
                echo '<input type="number" name="count" value="' . esc_attr($settings['count'] ?? 3) . '" min="1" max="6">';
                break;
            case 'cta':
                echo '<label>' . esc_html__('Título', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="title" value="' . esc_attr($settings['title'] ?? '') . '">';
                echo '<label>' . esc_html__('Descripción', 'travel-agency-platform') . '</label>';
                echo '<textarea name="description" rows="2">' . esc_textarea($settings['description'] ?? '') . '</textarea>';
                echo '<label>' . esc_html__('Texto del botón', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="button_text" value="' . esc_attr($settings['button_text'] ?? '') . '">';
                echo '<label>' . esc_html__('URL del botón', 'travel-agency-platform') . '</label>';
                echo '<input type="url" name="button_url" value="' . esc_attr($settings['button_url'] ?? '') . '">';
                break;
            case 'newsletter':
                echo '<label>' . esc_html__('Título', 'travel-agency-platform') . '</label>';
                echo '<input type="text" name="title" value="' . esc_attr($settings['title'] ?? '') . '">';
                echo '<label>' . esc_html__('Descripción', 'travel-agency-platform') . '</label>';
                echo '<textarea name="description" rows="2">' . esc_textarea($settings['description'] ?? '') . '</textarea>';
                break;
            case 'destinations':
                echo '<label><input type="checkbox" name="show_map" value="1" ' . checked(1, $settings['show_map'] ?? 1, false) . '> ' . esc_html__('Mostrar mapa', 'travel-agency-platform') . '</label>';
                break;
            default:
                echo '<p class="description">' . esc_html__('Sin opciones adicionales.', 'travel-agency-platform') . '</p>';
        }
    }

    /**
     * AJAX handler for saving landing config (drag & drop).
     */
    public static function ajax_save() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permiso.', 'travel-agency-platform')]);
        }
        check_ajax_referer('tap_landing_save_ajax', 'nonce');

        $raw = isset($_POST['config']) ? wp_unslash($_POST['config']) : '';
        $config = json_decode($raw, true);
        if (!is_array($config) || empty($config['sections'])) {
            wp_send_json_error(['message' => __('Configuración inválida.', 'travel-agency-platform')]);
        }

        if (self::save($config)) {
            wp_send_json_success(['message' => __('Guardado.', 'travel-agency-platform')]);
        } else {
            wp_send_json_error(['message' => __('Error al guardar.', 'travel-agency-platform')]);
        }
    }

    /**
     * Handle save from admin page.
     */
    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permiso.', 'travel-agency-platform'));
        }
        check_admin_referer('tap_landing_save', 'tap_landing_nonce');

        $types = isset($_POST['landing_type']) && is_array($_POST['landing_type']) ? (array) wp_unslash($_POST['landing_type']) : [];
        $orders = isset($_POST['landing_order']) && is_array($_POST['landing_order']) ? (array) wp_unslash($_POST['landing_order']) : [];
        $enabled = isset($_POST['landing_enabled']) && is_array($_POST['landing_enabled']) ? (array) wp_unslash($_POST['landing_enabled']) : [];

        $sections = [];
        foreach ($types as $i => $type) {
            $type = sanitize_key($type);
            if (!array_key_exists($type, self::section_types())) continue;
            $sections[] = [
                'type'     => $type,
                'enabled'  => isset($enabled[$i]),
                'order'    => isset($orders[$i]) ? (int) $orders[$i] : ($i + 1),
                'settings' => [],
            ];
        }

        // Add new section if requested.
        if (!empty($_POST['new_section_type'])) {
            $new_type = sanitize_key(wp_unslash($_POST['new_section_type']));
            if (array_key_exists($new_type, self::section_types())) {
                $sections[] = [
                    'type'     => $new_type,
                    'enabled'  => true,
                    'order'    => count($sections) + 1,
                    'settings' => [],
                ];
            }
        }

        if (self::save(['version' => self::VERSION, 'sections' => $sections])) {
            wp_safe_redirect(admin_url('admin.php?page=tap-landing&saved=1'));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=tap-landing&error=1'));
        }
        exit;
    }

    /**
     * Admin menu registration (called from TAP_Dashboard).
     */
    public static function register_admin_menu() {
        add_submenu_page(
            'travel-platform',
            __('Landing Page', 'travel-agency-platform'),
            __('Landing Page', 'travel-agency-platform'),
            'manage_options',
            'tap-landing',
            [__CLASS__, 'admin_page']
        );
    }
}
