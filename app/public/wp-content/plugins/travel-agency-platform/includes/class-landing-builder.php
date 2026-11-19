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
    const VERSION = 3;

    /**
     * Default section types available in the builder.
     */
    public static function section_types() {
        return [
            'hero'          => __('Hero', 'travel-agency-platform'),
            'stats'         => __('Estadísticas', 'travel-agency-platform'),
            'facts'         => __('Datos de Costa Rica', 'travel-agency-platform'),
            'destinations'  => __('Destinos', 'travel-agency-platform'),
            'interests'     => __('Intereses (chips)', 'travel-agency-platform'),
            'promoted'      => __('Tours destacados', 'travel-agency-platform'),
            'featured'      => __('Servicios destacados', 'travel-agency-platform'),
            'pillars'       => __('Pilares del blog', 'travel-agency-platform'),
            'referrals'     => __('CTA referidos', 'travel-agency-platform'),
            'agencies_cta'  => __('CTA agencias', 'travel-agency-platform'),
            'itinerary'     => __('CTA Itinerario', 'travel-agency-platform'),
            'agencies'      => __('Agencias verificadas', 'travel-agency-platform'),
            'testimonials'  => __('Testimonios', 'travel-agency-platform'),
            'blog'          => __('Blog', 'travel-agency-platform'),
            'faq'           => __('FAQ', 'travel-agency-platform'),
            'cta'           => __('CTA final', 'travel-agency-platform'),
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
                ['type' => 'stats', 'enabled' => true, 'order' => 2, 'settings' => []],
                ['type' => 'facts', 'enabled' => true, 'order' => 3, 'settings' => []],
                ['type' => 'destinations', 'enabled' => true, 'order' => 4, 'settings' => ['show_map' => true]],
                ['type' => 'interests', 'enabled' => true, 'order' => 5, 'settings' => []],
                ['type' => 'promoted', 'enabled' => true, 'order' => 6, 'settings' => []],
                ['type' => 'featured', 'enabled' => true, 'order' => 7, 'settings' => ['count' => 6, 'mode' => 'auto']],
                ['type' => 'pillars', 'enabled' => true, 'order' => 8, 'settings' => []],
                ['type' => 'referrals', 'enabled' => true, 'order' => 9, 'settings' => []],
                ['type' => 'agencies_cta', 'enabled' => true, 'order' => 10, 'settings' => []],
                ['type' => 'itinerary', 'enabled' => true, 'order' => 11, 'settings' => []],
                ['type' => 'agencies', 'enabled' => true, 'order' => 12, 'settings' => []],
                ['type' => 'testimonials', 'enabled' => true, 'order' => 13, 'settings' => []],
                ['type' => 'blog', 'enabled' => true, 'order' => 14, 'settings' => ['count' => 3]],
                ['type' => 'faq', 'enabled' => true, 'order' => 15, 'settings' => []],
                ['type' => 'cta', 'enabled' => true, 'order' => 16, 'settings' => []],
            ],
        ];
    }

    /**
     * Reorder stored sections into the canonical visual order.
     *
     * Older configs (version < 3) stored duplicated and non-sequential
     * `order` values and a `categories` section that duplicated `interests`.
     * Since the front-end never applied that order, upgrading is safe: we
     * keep each section's enabled flag and settings, alias `categories` to
     * `interests`, add any missing canonical sections, drop types the
     * front-end cannot render, and append other supported legacy sections
     * at the end.
     *
     * @param array $sections Stored sections.
     * @return array Canonical sections (orders renumbered from 1).
     */
    public static function canonicalize_sections($sections) {
        $allowed = self::section_types();
        $stored  = [];
        if (is_array($sections)) {
            foreach ($sections as $sec) {
                if (!is_array($sec) || empty($sec['type'])) {
                    continue;
                }
                $type = sanitize_key($sec['type']);
                // Legacy alias: the chips section used to be called categories.
                if ('categories' === $type) {
                    $type = 'interests';
                }
                if (!array_key_exists($type, $allowed)) {
                    // Type removed from the builder (e.g. newsletter) or unknown.
                    continue;
                }
                if (isset($stored[$type])) {
                    // Duplicate: keep it enabled if either copy was enabled.
                    $stored[$type]['enabled'] = !empty($stored[$type]['enabled']) || !empty($sec['enabled']);
                    if (empty($stored[$type]['settings']) && !empty($sec['settings']) && is_array($sec['settings'])) {
                        $stored[$type]['settings'] = $sec['settings'];
                    }
                    continue;
                }
                $stored[$type] = [
                    'type'     => $type,
                    'enabled'  => !empty($sec['enabled']),
                    'settings' => is_array($sec['settings'] ?? null) ? $sec['settings'] : [],
                ];
            }
        }

        $out   = [];
        $seen  = [];
        $order = 1;
        foreach (self::default_config()['sections'] as $def) {
            $type = $def['type'];
            $seen[$type] = true;
            $out[] = [
                'type'     => $type,
                'enabled'  => isset($stored[$type]) ? (bool) $stored[$type]['enabled'] : (bool) $def['enabled'],
                'order'    => $order++,
                'settings' => isset($stored[$type]) ? $stored[$type]['settings'] : $def['settings'],
            ];
        }
        // Preserve other supported sections that are not in the default set.
        foreach ($stored as $type => $sec) {
            if (isset($seen[$type]) || !array_key_exists($type, $allowed)) {
                continue;
            }
            $out[] = [
                'type'     => $type,
                'enabled'  => (bool) $sec['enabled'],
                'order'    => $order++,
                'settings' => $sec['settings'],
            ];
        }
        return $out;
    }

    /**
     * Load config from DB (with fallback to default).
     */
    public static function load() {
        $config = get_option(self::OPTION, null);
        if (!is_array($config) || empty($config['sections'])) {
            $config = self::default_config();
            update_option(self::OPTION, $config, false);
            return $config;
        }
        // Migrate old configs once, preserving enabled flags and settings.
        if (($config['version'] ?? 0) < self::VERSION) {
            $config['sections'] = self::canonicalize_sections($config['sections']);
            $config['version']  = self::VERSION;
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
        $types  = self::section_types();
        $order  = 1;
        $byType = [];
        foreach ($config['sections'] as $sec) {
            if (!is_array($sec) || empty($sec['type'])) {
                continue;
            }
            $type = sanitize_key($sec['type']);
            // Legacy alias: the chips section used to be called categories.
            if ('categories' === $type) {
                $type = 'interests';
            }
            if (!array_key_exists($type, $types)) {
                continue;
            }
            if (isset($byType[$type])) {
                // Keep a single copy; preserve the enabled state of either.
                $index = $byType[$type];
                $clean['sections'][$index]['enabled'] = !empty($clean['sections'][$index]['enabled']) || !empty($sec['enabled']);
                if (empty($clean['sections'][$index]['settings']) && !empty($sec['settings']) && is_array($sec['settings'])) {
                    $clean['sections'][$index]['settings'] = $sec['settings'];
                }
                continue;
            }
            $byType[$type] = count($clean['sections']);
            $clean['sections'][] = [
                'type'     => $type,
                'enabled'  => !empty($sec['enabled']),
                'order'    => $order++,
                'settings' => is_array($sec['settings'] ?? null) ? $sec['settings'] : [],
            ];
        }
        if (empty($clean['sections'])) {
            return false;
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
        wp_enqueue_media();
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

            <div class="tap-lb-presets" style="margin:12px 0;padding:14px;border:1px solid #ddd;border-radius:8px;background:#fff;max-width:900px;">
                <h2 style="margin:0 0 10px;font-size:15px;"><?php esc_html_e('Presets e importación', 'travel-agency-platform'); ?></h2>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <select id="tap-lb-preset-select">
                        <option value=""><?php esc_html_e('Seleccionar preset…', 'travel-agency-platform'); ?></option>
                        <?php foreach (array_keys(self::presets()) as $preset_name): ?>
                            <option value="<?php echo esc_attr($preset_name); ?>"><?php echo esc_html($preset_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="tap-lb-preset-name" placeholder="<?php echo esc_attr__('Nombre del preset', 'travel-agency-platform'); ?>">
                    <button type="button" class="button" data-preset="save"><?php esc_html_e('Guardar preset', 'travel-agency-platform'); ?></button>
                    <button type="button" class="button" data-preset="load"><?php esc_html_e('Aplicar', 'travel-agency-platform'); ?></button>
                    <button type="button" class="button" data-preset="delete"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                </div>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:flex-start;">
                    <textarea id="tap-lb-json" rows="4" style="flex:1;font-family:monospace;" placeholder="<?php echo esc_attr__('Pega aquí un JSON de configuración para importar', 'travel-agency-platform'); ?>"></textarea>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <button type="button" class="button" id="tap-lb-export"><?php esc_html_e('Exportar', 'travel-agency-platform'); ?></button>
                        <button type="button" class="button" id="tap-lb-import"><?php esc_html_e('Importar', 'travel-agency-platform'); ?></button>
                    </div>
                </div>
            </div>

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

        <!-- Preview panel -->
        <div class="tap-lb-preview-wrap" style="margin-top:24px;">
            <h2><?php esc_html_e('Vista previa', 'travel-agency-platform'); ?></h2>
            <p class="description"><?php esc_html_e('La vista previa se actualiza al guardar los cambios.', 'travel-agency-platform'); ?></p>
            <iframe id="tap-lb-preview" src="<?php echo esc_url(home_url('/')); ?>" style="width:100%;height:600px;border:1px solid #ddd;border-radius:8px;background:#fff;"></iframe>
        </div>

        <!-- SortableJS -->
        <script src="<?php echo esc_url(TAP_PLUGIN_URL . 'assets/js/vendor/sortable.min.js'); ?>"></script>
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

            // Media picker for section background.
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.tap-lb-pick-media');
                if (!btn || typeof wp === 'undefined' || !wp.media) return;
                e.preventDefault();
                var input = btn.parentNode.querySelector('input[name="background"]');
                var frame = wp.media({ title: '<?php echo esc_js(__('Elegir imagen', 'travel-agency-platform')); ?>', button: { text: '<?php echo esc_js(__('Usar imagen', 'travel-agency-platform')); ?>' }, multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    if (input && att.url) { input.value = att.url; input.dispatchEvent(new Event('change', { bubbles: true })); }
                });
                frame.open();
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

            function collectConfig() {
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
                return {version: <?php echo (int) self::VERSION; ?>, sections: sections};
            }

            // Save via AJAX.
            document.getElementById('tap-lb-save').addEventListener('click', function(){
                var status = document.getElementById('tap-lb-status');
                status.textContent = '<?php echo esc_js(__('Guardando...', 'travel-agency-platform')); ?>';
                var data = new FormData();
                data.append('action', 'tap_landing_save_ajax');
                data.append('nonce', '<?php echo wp_create_nonce('tap_landing_save_ajax'); ?>');
                data.append('config', JSON.stringify(collectConfig()));

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) {
                            status.textContent = '<?php echo esc_js(__('Guardado.', 'travel-agency-platform')); ?>';
                            status.style.color = 'green';
                            // Reload preview.
                            var iframe = document.getElementById('tap-lb-preview');
                            if (iframe) iframe.src = iframe.src;
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

            // Presets + import/export.
            var statusEl = document.getElementById('tap-lb-status');
            function setStatus(text, ok) {
                if (!statusEl) return;
                statusEl.textContent = text;
                statusEl.style.color = ok ? 'green' : 'red';
            }
            document.querySelectorAll('[data-preset]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var mode = btn.getAttribute('data-preset');
                    var select = document.getElementById('tap-lb-preset-select');
                    var name = mode === 'save'
                        ? document.getElementById('tap-lb-preset-name').value.trim()
                        : select.value;
                    if (!name) { setStatus('<?php echo esc_js(__('Escribe un nombre.', 'travel-agency-platform')); ?>', false); return; }
                    if ((mode === 'load' || mode === 'delete') && !confirm('<?php echo esc_js(__('¿Confirmar?', 'travel-agency-platform')); ?>')) return;
                    var data = new FormData();
                    data.append('action', 'tap_landing_preset');
                    data.append('nonce', '<?php echo wp_create_nonce('tap_landing_save_ajax'); ?>');
                    data.append('mode', mode);
                    data.append('name', name);
                    if (mode === 'save') data.append('config', JSON.stringify(collectConfig()));
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:data, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            setStatus(res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Listo.', 'travel-agency-platform')); ?>', !!res.success);
                            if (res.success && mode === 'save' && name) {
                                var opt = document.createElement('option');
                                opt.value = name; opt.textContent = name;
                                select.appendChild(opt);
                                select.value = name;
                                document.getElementById('tap-lb-preset-name').value = '';
                            }
                            if (res.success && mode !== 'save') window.location.reload();
                        })
                        .catch(function(){ setStatus('<?php echo esc_js(__('Error de conexión.', 'travel-agency-platform')); ?>', false); });
                });
            });
            document.getElementById('tap-lb-export').addEventListener('click', function(){
                document.getElementById('tap-lb-json').value = JSON.stringify(collectConfig(), null, 2);
                setStatus('<?php echo esc_js(__('Configuración copiada al área de texto.', 'travel-agency-platform')); ?>', true);
            });
            document.getElementById('tap-lb-import').addEventListener('click', function(){
                var raw = document.getElementById('tap-lb-json').value.trim();
                var config;
                try { config = JSON.parse(raw); } catch (e) { setStatus('<?php echo esc_js(__('JSON inválido.', 'travel-agency-platform')); ?>', false); return; }
                if (!config.sections || !config.sections.length) { setStatus('<?php echo esc_js(__('JSON inválido.', 'travel-agency-platform')); ?>', false); return; }
                var data = new FormData();
                data.append('action', 'tap_landing_save_ajax');
                data.append('nonce', '<?php echo wp_create_nonce('tap_landing_save_ajax'); ?>');
                data.append('config', JSON.stringify(config));
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) window.location.reload();
                        else setStatus('<?php echo esc_js(__('Error al importar.', 'travel-agency-platform')); ?>', false);
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
        .tap-lb-media { display: flex; gap: 6px; align-items: center; }
        .tap-lb-media .button { width: auto; white-space: nowrap; }
        .tap-lb-toolbar { margin: 16px 0; display: flex; align-items: center; gap: 8px; }
        </style>
        <?php
    }

    /**
     * Render settings fields for a section type.
     */
    /**
     * Editable text fields per section type. Labels double as the admin
     * field labels; theme defaults keep the current copy when unset.
     */
    public static function text_fields($type) {
        $all = [
            'eyebrow'     => __('Etiqueta superior', 'travel-agency-platform'),
            'title'       => __('Título', 'travel-agency-platform'),
            'subtitle'    => __('Subtítulo', 'travel-agency-platform'),
            'button_text' => __('Texto del botón', 'travel-agency-platform'),
            'button_url'  => __('URL del botón', 'travel-agency-platform'),
        ];
        $map = [
            'hero'         => ['eyebrow', 'title', 'subtitle'],
            'destinations' => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'interests'    => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'promoted'     => ['eyebrow', 'title', 'subtitle'],
            'featured'     => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'pillars'      => ['eyebrow', 'title', 'subtitle'],
            'referrals'    => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'agencies_cta' => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'itinerary'    => ['eyebrow', 'title', 'button_text'],
            'agencies'     => ['eyebrow', 'title'],
            'testimonials' => ['eyebrow', 'title'],
            'blog'         => ['eyebrow', 'title', 'subtitle', 'button_text'],
            'faq'          => ['eyebrow', 'title', 'subtitle'],
            'cta'          => ['title', 'subtitle', 'button_text', 'button_url'],
        ];
        $out = [];
        foreach (($map[$type] ?? []) as $key) {
            $out[$key] = $all[$key];
        }
        return $out;
    }

    public static function render_section_settings($type, $settings) {
        $text_fields = self::text_fields($type);
        echo '<label>' . esc_html__('Visible para', 'travel-agency-platform') . '</label>';
        echo '<select name="visible_to">';
        foreach ([
            'all'       => __('Todos', 'travel-agency-platform'),
            'logged_in' => __('Usuarios registrados', 'travel-agency-platform'),
            'guests'    => __('Solo visitantes', 'travel-agency-platform'),
            'agency'    => __('Solo agencias', 'travel-agency-platform'),
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($value, (string) ($settings['visible_to'] ?? 'all'), false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<label><input type="checkbox" name="hide_on_mobile" value="1" ' . checked(1, $settings['hide_on_mobile'] ?? 0, false) . '> ' . esc_html__('Ocultar en móvil', 'travel-agency-platform') . '</label>';
        echo '<label><input type="checkbox" name="hide_on_desktop" value="1" ' . checked(1, $settings['hide_on_desktop'] ?? 0, false) . '> ' . esc_html__('Ocultar en escritorio', 'travel-agency-platform') . '</label>';
        foreach ($text_fields as $key => $label) {
            $value = (string) ($settings[$key] ?? '');
            if ($key === 'button_url') {
                echo '<label>' . esc_html($label) . '</label><input type="url" name="button_url" value="' . esc_attr($value) . '" placeholder="https://…">';
            } elseif ($key === 'subtitle') {
                echo '<label>' . esc_html($label) . '</label><textarea name="subtitle" rows="2">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<label>' . esc_html($label) . '</label><input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
        }
        switch ($type) {
            case 'hero':
                echo '<label>' . esc_html__('Imagen de fondo', 'travel-agency-platform') . '</label>';
                echo '<div class="tap-lb-media">';
                echo '<input type="text" name="background" value="' . esc_attr($settings['background'] ?? '') . '" placeholder="https://…">';
                echo '<button type="button" class="button tap-lb-pick-media">' . esc_html__('Elegir imagen', 'travel-agency-platform') . '</button>';
                echo '</div>';
                echo '<label><input type="checkbox" name="show_search" value="1" ' . checked(1, $settings['show_search'] ?? 1, false) . '> ' . esc_html__('Mostrar buscador', 'travel-agency-platform') . '</label>';
                break;
            case 'featured':
                echo '<label>' . esc_html__('Cantidad', 'travel-agency-platform') . '</label>';
                echo '<input type="number" name="count" value="' . esc_attr($settings['count'] ?? 6) . '" min="3" max="12">';
                echo '<label>' . esc_html__('Modo', 'travel-agency-platform') . '</label>';
                echo '<select name="mode"><option value="auto"' . selected('auto', $settings['mode'] ?? 'auto', false) . '>Automático (por rating)</option><option value="manual"' . selected('manual', $settings['mode'] ?? 'auto', false) . '>Manual</option></select>';
                break;
            case 'blog':
                echo '<label>' . esc_html__('Cantidad de posts', 'travel-agency-platform') . '</label>';
                echo '<input type="number" name="count" value="' . esc_attr($settings['count'] ?? 3) . '" min="1" max="6">';
                break;
            case 'cta':
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
            case 'faq':
                echo '<label>' . esc_html__('Preguntas (una por línea: Pregunta | Respuesta)', 'travel-agency-platform') . '</label>';
                echo '<textarea name="faq_text" rows="6">' . esc_textarea($settings['faq_text'] ?? '') . '</textarea>';
                break;
            default:
                echo '<p class="description">' . esc_html__('Sin opciones adicionales.', 'travel-agency-platform') . '</p>';
        }
    }

    /** Named layout presets stored alongside the active config. */
    const PRESETS_OPTION = 'tap_landing_presets';

    public static function presets() {
        $presets = get_option(self::PRESETS_OPTION, []);
        return is_array($presets) ? $presets : [];
    }

    public static function ajax_preset() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permiso.', 'travel-agency-platform')]);
        }
        check_ajax_referer('tap_landing_save_ajax', 'nonce');
        $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $presets = self::presets();

        if ('save' === $mode) {
            $raw = wp_unslash($_POST['config'] ?? '');
            $config = json_decode($raw, true);
            if ($name === '' || !is_array($config)) {
                wp_send_json_error(['message' => __('Nombre o configuración inválida.', 'travel-agency-platform')]);
            }
            $presets[$name] = $config;
            update_option(self::PRESETS_OPTION, $presets);
            wp_send_json_success(['message' => __('Preset guardado.', 'travel-agency-platform')]);
        }
        if ('load' === $mode || 'delete' === $mode) {
            if (!isset($presets[$name])) {
                wp_send_json_error(['message' => __('No existe ese preset.', 'travel-agency-platform')]);
            }
            if ('delete' === $mode) {
                unset($presets[$name]);
                update_option(self::PRESETS_OPTION, $presets);
                wp_send_json_success(['message' => __('Preset eliminado.', 'travel-agency-platform')]);
            }
            if (is_array($presets[$name]['sections'] ?? null)) {
                self::save($presets[$name]);
                self::invalidate_cache();
            }
            wp_send_json_success(['message' => __('Preset aplicado.', 'travel-agency-platform')]);
        }
        wp_send_json_error(['message' => __('Acción desconocida.', 'travel-agency-platform')]);
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
