<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Email templates CRUD (admin) + rendering with variables.
 */
class TAP_Email_Templates {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_email_templates';
    }

    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_email_log';
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Plantillas de email', 'travel-agency-platform'),
            __('Email templates', 'travel-agency-platform'),
            'manage_options',
            'tap-email-templates',
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_actions() {
        if (!isset($_POST['tap_email_template_action'])) return;
        if (!current_user_can('manage_options')) wp_die('');
        check_admin_referer('tap_email_template_nonce');

        global $wpdb;
        $t = self::table();
        $action = sanitize_key(wp_unslash($_POST['tap_email_template_action']));
        if ('save' === $action) {
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $data = [
                'template_key' => sanitize_key(wp_unslash($_POST['template_key'] ?? '')),
                'subject'      => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
                'body'         => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
                'locale'       => sanitize_text_field(wp_unslash($_POST['locale'] ?? 'es_ES')),
                'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($id) {
                $wpdb->update($t, $data, ['id' => $id], ['%s','%s','%s','%s','%d'], ['%d']);
            } else {
                $wpdb->insert($t, $data, ['%s','%s','%s','%s','%d']);
            }
            wp_safe_redirect(add_query_arg(['page' => 'tap-email-templates', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }
        if ('delete' === $action) {
            $id = absint($_POST['id'] ?? 0);
            if ($id) $wpdb->delete($t, ['id' => $id], ['%d']);
            wp_safe_redirect(add_query_arg(['page' => 'tap-email-templates', 'deleted' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    public static function get_template($key, $locale = null) {
        global $wpdb;
        $t = self::table();
        if (!$locale) $locale = (function_exists('TAP_Localization') && method_exists('TAP_Localization', 'resolve_lang')) ? TAP_Localization::resolve_lang() : 'es_ES';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE template_key = %s AND locale = %s AND is_active = 1 LIMIT 1", $key, $locale));
        if (!$row && $locale !== 'es_ES') {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE template_key = %s AND locale = 'es_ES' AND is_active = 1 LIMIT 1", $key));
        }
        return $row;
    }

    public static function render($key, $vars = [], $locale = null) {
        $tpl = self::get_template($key, $locale);
        if (!$tpl) {
            return null; // fallback to hardcoded
        }
        $subject = $tpl->subject;
        $body = $tpl->body;
        foreach ($vars as $k => $v) {
            $subject = str_replace('{{' . $k . '}}', (string) $v, $subject);
            $body = str_replace('{{' . $k . '}}', (string) $v, $body);
        }
        return ['subject' => $subject, 'body' => $body];
    }

    public static function log($key, $recipient, $subject, $status = 'sent') {
        global $wpdb;
        $wpdb->insert(self::log_table(), [
            'template_key' => $key,
            'recipient'    => $recipient,
            'subject'      => $subject,
            'status'       => $status,
        ], ['%s','%s','%s','%s']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $t = self::table();
        $templates = $wpdb->get_results("SELECT * FROM {$t} ORDER BY template_key, locale");
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $editing = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $edit_id)) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Plantillas de email', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>

            <h2><?php echo $editing ? esc_html__('Editar plantilla', 'travel-agency-platform') : esc_html__('Nueva plantilla', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_email_template_nonce'); ?>
                <input type="hidden" name="tap_email_template_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Clave', 'travel-agency-platform'); ?></th><td><input type="text" name="template_key" value="<?php echo esc_attr($editing->template_key ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Asunto', 'travel-agency-platform'); ?></th><td><input type="text" name="subject" value="<?php echo esc_attr($editing->subject ?? ''); ?>" class="large-text" required></td></tr>
                    <tr><th><?php esc_html_e('Cuerpo (HTML)', 'travel-agency-platform'); ?></th><td><textarea name="body" rows="10" class="large-text"><?php echo esc_textarea($editing->body ?? ''); ?></textarea></td></tr>
                    <tr><th><?php esc_html_e('Idioma', 'travel-agency-platform'); ?></th><td>
                        <select name="locale">
                            <option value="es_ES" <?php selected($editing->locale ?? 'es_ES', 'es_ES'); ?>>es_ES</option>
                            <option value="en_US" <?php selected($editing->locale ?? 'es_ES', 'en_US'); ?>>en_US</option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Activa', 'travel-agency-platform'); ?></th><td><input type="checkbox" name="is_active" value="1" <?php checked($editing->is_active ?? 1, 1); ?>></td></tr>
                </table>
                <p class="description"><?php esc_html_e('Variables disponibles: {{booking_code}}, {{guest_name}}, {{total}}, {{service_name}}, {{agency_name}}, {{date}}', 'travel-agency-platform'); ?></p>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Plantillas existentes', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Clave', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Asunto', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Idioma', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Activa', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                        <tr>
                            <td><?php echo esc_html($tpl->template_key); ?></td>
                            <td><?php echo esc_html($tpl->subject); ?></td>
                            <td><?php echo esc_html($tpl->locale); ?></td>
                            <td><?php echo $tpl->is_active ? '✓' : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-email-templates', 'edit' => $tpl->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_email_template_nonce'); ?>
                                    <input type="hidden" name="tap_email_template_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $tpl->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$templates): ?>
                        <tr><td colspan="5"><?php esc_html_e('No hay plantillas.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}