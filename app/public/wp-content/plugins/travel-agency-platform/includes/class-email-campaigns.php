<?php
defined('ABSPATH') || exit;

/**
 * Email marketing campaigns: create, schedule, send, track.
 */
class TAP_Email_Campaigns {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_email_campaigns';
    }

    /** Segments available. */
    public static function segments() {
        return [
            'all'        => __('Todos los usuarios', 'travel-agency-platform'),
            'clients'    => __('Viajeros (clientes)', 'travel-agency-platform'),
            'agencies'   => __('Agencias', 'travel-agency-platform'),
            'leads'      => __('Leads de contacto', 'travel-agency-platform'),
            'inactive'   => __('Inactivos (90+ días)', 'travel-agency-platform'),
        ];
    }

    /** Get campaigns. */
    public static function all($status = '') {
        global $wpdb;
        $t = self::table();
        if ($status) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status = %s ORDER BY created_at DESC", $status));
        }
        return $wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC");
    }

    public static function get($id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    public static function save($data) {
        global $wpdb;
        $t = self::table();
        $row = [
            'name'         => sanitize_text_field($data['name'] ?? ''),
            'subject'      => sanitize_text_field($data['subject'] ?? ''),
            'body'         => wp_kses_post($data['body'] ?? ''),
            'segment'      => sanitize_key($data['segment'] ?? 'all'),
            'status'       => in_array(($data['status'] ?? 'draft'), ['draft', 'scheduled', 'sent'], true) ? $data['status'] : 'draft',
            'scheduled_at' => !empty($data['scheduled_at']) ? sanitize_text_field($data['scheduled_at']) : null,
            'created_by'   => (int) ($data['created_by'] ?? get_current_user_id()),
        ];
        if (empty($row['name']) || empty($row['subject'])) {
            return new WP_Error('invalid_campaign', __('Nombre y asunto son obligatorios.', 'travel-agency-platform'));
        }
        if (isset($data['id']) && (int) $data['id'] > 0) {
            $wpdb->update($t, $row, ['id' => (int) $data['id']]);
            return (int) $data['id'];
        }
        $wpdb->insert($t, $row);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']);
    }

    /** Get recipients for a segment. Returns array of email addresses. */
    public static function recipients($segment) {
        $emails = [];
        switch ($segment) {
            case 'clients':
                $users = get_users(['role' => 'tap_client', 'fields' => ['user_email']]);
                foreach ($users as $u) $emails[] = $u->user_email;
                break;
            case 'agencies':
                $users = get_users(['role' => 'tap_agency_admin', 'fields' => ['user_email']]);
                foreach ($users as $u) $emails[] = $u->user_email;
                break;
            case 'leads':
                if (class_exists('TAP_Leads')) {
                    // get distinct emails from leads table
                    global $wpdb;
                    $rows = $wpdb->get_col("SELECT DISTINCT email FROM {$wpdb->prefix}tap_leads WHERE email != ''");
                    $emails = array_merge($emails, (array) $rows);
                }
                break;
            case 'inactive':
                // users who haven't logged in 90+ days - approximate via user meta
                $users = get_users(['fields' => ['user_email', 'ID']]);
                foreach ($users as $u) {
                    $last = get_user_meta($u->ID, 'last_login', true);
                    if (!$last || strtotime($last) < strtotime('-90 days')) {
                        $emails[] = $u->user_email;
                    }
                }
                break;
            default:
                $users = get_users(['fields' => ['user_email']]);
                foreach ($users as $u) $emails[] = $u->user_email;
        }
        return array_values(array_unique(array_filter($emails)));
    }

    /** Send a campaign now. Returns count sent. */
    public static function send($id) {
        $camp = self::get($id);
        if (!$camp || $camp->status === 'sent') return 0;

        $recipients = self::recipients($camp->segment);
        $sent = 0;
        foreach ($recipients as $email) {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = wp_mail($email, $camp->subject, $camp->body, $headers);
            if ($ok) $sent++;
        }

        global $wpdb;
        $wpdb->update(self::table(), [
            'status'     => 'sent',
            'sent_at'    => current_time('mysql'),
            'sent_count' => $sent,
        ], ['id' => (int) $id]);

        return $sent;
    }

    /** Schedule a campaign. */
    public static function schedule($id, $datetime) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), [
            'status'       => 'scheduled',
            'scheduled_at' => $datetime,
        ], ['id' => (int) $id]);
    }

    /** Process scheduled campaigns (cron). */
    public static function process_scheduled() {
        global $wpdb;
        $t = self::table();
        $due = $wpdb->get_results("SELECT id FROM {$t} WHERE status = 'scheduled' AND scheduled_at <= NOW()");
        foreach ($due as $row) {
            self::send((int) $row->id);
        }
    }

    /** Init: register cron. */
    public static function init() {
        if (!wp_next_scheduled('tap_process_email_campaigns')) {
            wp_schedule_event(time(), 'hourly', 'tap_process_email_campaigns');
        }
        add_action('tap_process_email_campaigns', [__CLASS__, 'process_scheduled']);
    }
    /* ── admin handlers ──────────────────────────────────────────────── */

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        check_admin_referer('tap_email_campaign_save');
        $id = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
        $data = [
            'name'         => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'subject'      => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
            'body'         => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
            'segment'      => sanitize_key(wp_unslash($_POST['segment'] ?? 'all')),
            'status'       => 'draft',
            'scheduled_at' => !empty($_POST['scheduled_at']) ? sanitize_text_field(wp_unslash($_POST['scheduled_at'])) : null,
            'created_by'   => get_current_user_id(),
        ];
        if ($id > 0) $data['id'] = $id;
        $res = self::save($data);
        if (is_wp_error($res)) {
            wp_die($res->get_error_message());
        }
        wp_safe_redirect(admin_url('admin.php?page=tap-email-campaigns&saved=1'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('tap_email_campaign_delete_' . $id);
        self::delete($id);
        wp_safe_redirect(admin_url('admin.php?page=tap-email-campaigns&deleted=1'));
        exit;
    }

    public static function handle_send() {
        if (!current_user_can('manage_options')) wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('tap_email_campaign_send_' . $id);
        $sent = self::send($id);
        wp_safe_redirect(admin_url('admin.php?page=tap-email-campaigns&sent=' . (int) $sent));
        exit;
    }

    public static function handle_schedule() {
        if (!current_user_can('manage_options')) wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('tap_email_campaign_schedule_' . $id);
        $camp = self::get($id);
        if ($camp) {
            self::schedule($id, $camp->scheduled_at ?: current_time('mysql'));
        }
        wp_safe_redirect(admin_url('admin.php?page=tap-email-campaigns&scheduled=1'));
        exit;
    }

    /* ── admin page ──────────────────────────────────────────────────── */

    public static function campaigns_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Acceso denegado.', 'travel-agency-platform'));
        }
        $action = sanitize_key(wp_unslash($_GET['action'] ?? 'list'));
        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ('edit' === $action || 'new' === $action) {
            $camp = $id ? self::get($id) : null;
            self::render_edit_form($camp);
            return;
        }

        // List view
        $campaigns = self::all();
        $saved = !empty($_GET['saved']);
        $deleted = !empty($_GET['deleted']);
        $sent = isset($_GET['sent']) ? (int) $_GET['sent'] : null;
        $scheduled = !empty($_GET['scheduled']);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Campañas de email', 'travel-agency-platform'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tap-email-campaigns&action=new')); ?>" class="page-title-action"><?php esc_html_e('Nueva campaña', 'travel-agency-platform'); ?></a>
            </h1>
            <?php if ($saved): ?><div class="notice notice-success"><p><?php esc_html_e('Campaña guardada.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if ($deleted): ?><div class="notice notice-success"><p><?php esc_html_e('Campaña eliminada.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if ($sent !== null): ?><div class="notice notice-success"><p><?php echo esc_html(sprintf(__('Campaña enviada a %d destinatarios.', 'travel-agency-platform'), $sent)); ?></p></div><?php endif; ?>
            <?php if ($scheduled): ?><div class="notice notice-success"><p><?php esc_html_e('Campaña programada.', 'travel-agency-platform'); ?></p></div><?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Segmento', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Enviados', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Programada', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$campaigns): ?>
                    <tr><td colspan="6"><?php esc_html_e('Sin campañas.', 'travel-agency-platform'); ?></td></tr>
                <?php else: foreach ($campaigns as $c): ?>
                    <tr>
                        <td><strong><?php echo esc_html($c->name); ?></strong><br><small><?php echo esc_html($c->subject); ?></small></td>
                        <td><?php echo esc_html(self::segments()[$c->segment] ?? $c->segment); ?></td>
                        <td><?php echo esc_html($c->status); ?></td>
                        <td><?php echo (int) $c->sent_count; ?></td>
                        <td><?php echo $c->scheduled_at ? esc_html($c->scheduled_at) : '—'; ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-email-campaigns&action=edit&id=' . (int) $c->id)); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                            <?php if ($c->status !== 'sent'): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tap_email_campaign_send&id=' . (int) $c->id), 'tap_email_campaign_send_' . (int) $c->id)); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Enviar ahora?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Enviar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tap_email_campaign_delete&id=' . (int) $c->id), 'tap_email_campaign_delete_' . (int) $c->id)); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_edit_form($camp) {
        $id = $camp ? (int) $camp->id : 0;
        ?>
        <div class="wrap">
            <h1><?php echo $id ? esc_html__('Editar campaña', 'travel-agency-platform') : esc_html__('Nueva campaña', 'travel-agency-platform'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tap_email_campaign_save">
                <input type="hidden" name="campaign_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field('tap_email_campaign_save'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="camp_name"><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="camp_name" name="name" value="<?php echo esc_attr($camp->name ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="camp_subject"><?php esc_html_e('Asunto', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="camp_subject" name="subject" value="<?php echo esc_attr($camp->subject ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="camp_segment"><?php esc_html_e('Segmento', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="camp_segment" name="segment">
                                <?php foreach (self::segments() as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($camp->segment ?? 'all', $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="camp_scheduled"><?php esc_html_e('Programar para (opcional)', 'travel-agency-platform'); ?></label></th>
                        <td><input type="datetime-local" id="camp_scheduled" name="scheduled_at" value="<?php echo esc_attr($camp && $camp->scheduled_at ? str_replace(' ', 'T', $camp->scheduled_at) : ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="camp_body"><?php esc_html_e('Cuerpo (HTML)', 'travel-agency-platform'); ?></label></th>
                        <td><textarea id="camp_body" name="body" rows="12" class="large-text"><?php echo esc_textarea($camp->body ?? ''); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button($id ? __('Actualizar', 'travel-agency-platform') : __('Crear campaña', 'travel-agency-platform')); ?>
            </form>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tap-email-campaigns')); ?>"><?php esc_html_e('← Volver a la lista', 'travel-agency-platform'); ?></a></p>
        </div>
        <?php
    }

}
