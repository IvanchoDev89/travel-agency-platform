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
     * Admin page: tap-landing.
     */
    public static function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permiso.', 'travel-agency-platform'));
        }
        $config = self::load();
        $types = self::section_types();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Landing Page Builder', 'travel-agency-platform'); ?></h1>
            <p class="description"><?php esc_html_e('Activa, desactiva y reordena las secciones de la landing page. Los cambios se reflejan inmediatamente.', 'travel-agency-platform'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tap_landing_save', 'tap_landing_nonce'); ?>
                <input type="hidden" name="action" value="tap_landing_save">

                <table class="wp-list-table widefat fixed striped tap-landing-sections">
                    <thead>
                        <tr>
                            <th style="width:40px;"><?php esc_html_e('Orden', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Sección', 'travel-agency-platform'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Activa', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($config['sections'] as $i => $sec): ?>
                            <tr>
                                <td>
                                    <input type="number" name="landing_order[<?php echo (int) $i; ?>]" value="<?php echo (int) $sec['order']; ?>" min="1" style="width:50px;">
                                </td>
                                <td>
                                    <strong><?php echo esc_html($types[$sec['type']] ?? $sec['type']); ?></strong>
                                    <input type="hidden" name="landing_type[<?php echo (int) $i; ?>]" value="<?php echo esc_attr($sec['type']); ?>">
                                </td>
                                <td>
                                    <input type="checkbox" name="landing_enabled[<?php echo (int) $i; ?>]" value="1" <?php checked(!empty($sec['enabled'])); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:12px;">
                    <button type="button" class="button" id="tap-landing-add-section"><?php esc_html_e('Agregar sección', 'travel-agency-platform'); ?></button>
                </p>

                <div id="tap-landing-add-panel" style="display:none;margin-top:12px;padding:12px;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">
                    <label><?php esc_html_e('Tipo de sección:', 'travel-agency-platform'); ?></label>
                    <select name="new_section_type" id="tap-landing-new-type">
                        <?php foreach ($types as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="tap-landing-confirm-add"><?php esc_html_e('Agregar', 'travel-agency-platform'); ?></button>
                </div>

                <?php submit_button(__('Guardar configuración', 'travel-agency-platform')); ?>
            </form>

            <script>
            (function(){
                var addBtn = document.getElementById('tap-landing-add-section');
                var panel = document.getElementById('tap-landing-add-panel');
                if (addBtn && panel) {
                    addBtn.addEventListener('click', function(){ panel.style.display = panel.style.display === 'none' ? 'block' : 'none'; });
                }
            })();
            </script>
        </div>
        <?php
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
