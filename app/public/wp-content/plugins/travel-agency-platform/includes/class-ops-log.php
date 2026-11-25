<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Platform-wide log viewer: audit trail and email delivery history.
 * Without this, operators debug by opening phpMyAdmin or raw log files.
 */
class TAP_Ops_Log {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page'], 36);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Registros', 'travel-agency-platform'),
            __('Registros', 'travel-agency-platform'),
            'manage_options',
            'tap-logs',
            [__CLASS__, 'render']
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        global $wpdb;
        $tab = isset($_GET['log_tab']) && 'email' === sanitize_key($_GET['log_tab']) ? 'email' : 'audit';
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;

        if ('email' === $tab) {
            $table = $wpdb->prefix . 'tap_email_log';
            $where = '1=1';
            $params = [];
            if ('' !== $s) {
                $where .= ' AND (recipient LIKE %s OR subject LIKE %s OR template_key LIKE %s)';
                $like = '%' . $wpdb->esc_like($s) . '%';
                $params = [$like, $like, $like];
            }
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY sent_at DESC LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, ($paged - 1) * $per_page])
            ));
        } else {
            $table = class_exists('TAP_Audit_Log') ? TAP_Audit_Log::table() : $wpdb->prefix . 'tap_audit_log';
            $where = '1=1';
            $params = [];
            if ('' !== $s) {
                $where .= ' AND (action LIKE %s OR details LIKE %s OR object_type LIKE %s)';
                $like = '%' . $wpdb->esc_like($s) . '%';
                $params = [$like, $like, $like];
            }
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, ($paged - 1) * $per_page])
            ));
        }

        $base = admin_url('admin.php?page=tap-logs');
        echo '<div class="wrap"><h1>' . esc_html__('Registros de la plataforma', 'travel-agency-platform') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach (['audit' => __('Auditoría', 'travel-agency-platform'), 'email' => __('Correos', 'travel-agency-platform')] as $key => $label) {
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="'
                . esc_url(add_query_arg('log_tab', $key, $base)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:14px 0;">';
        echo '<input type="hidden" name="page" value="tap-logs"><input type="hidden" name="log_tab" value="' . esc_attr($tab) . '">';
        echo '<input type="search" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Buscar', 'travel-agency-platform') . '" class="regular-text">';
        echo '<button class="button">' . esc_html__('Filtrar', 'travel-agency-platform') . '</button>';
        echo ' <span class="description">' . esc_html(sprintf(__('%d registros', 'travel-agency-platform'), $total)) . '</span></form>';

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        if ('email' === $tab) {
            foreach ([__('Fecha', 'travel-agency-platform'), __('Plantilla', 'travel-agency-platform'), __('Destinatario', 'travel-agency-platform'), __('Asunto', 'travel-agency-platform'), __('Estado', 'travel-agency-platform')] as $h) {
                echo '<th>' . esc_html($h) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ((array) $rows as $r) {
                echo '<tr><td>' . esc_html($r->sent_at) . '</td><td>' . esc_html($r->template_key) . '</td><td>' . esc_html($r->recipient) . '</td><td>' . esc_html($r->subject) . '</td><td>' . esc_html($r->status) . '</td></tr>';
            }
        } else {
            foreach ([__('Fecha', 'travel-agency-platform'), __('Acción', 'travel-agency-platform'), __('Usuario', 'travel-agency-platform'), __('Agencia', 'travel-agency-platform'), __('Objeto', 'travel-agency-platform'), __('Detalle', 'travel-agency-platform'), __('IP', 'travel-agency-platform')] as $h) {
                echo '<th>' . esc_html($h) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ((array) $rows as $r) {
                $user = $r->user_id ? get_userdata((int) $r->user_id) : null;
                echo '<tr><td>' . esc_html($r->created_at) . '</td><td>' . esc_html($r->action) . '</td><td>' . esc_html($user ? $user->user_login : '—') . '</td><td>#' . (int) $r->agency_id . '</td><td>' . esc_html(($r->object_type ?: '—') . ' #' . (int) ($r->object_id ?? 0)) . '</td><td>' . esc_html($r->details ?: '—') . '</td><td>' . esc_html($r->ip ?: '—') . '</td></tr>';
            }
        }
        if (!$rows) {
            echo '<tr><td colspan="7">' . esc_html__('Sin registros.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo TAP_Dashboard::paginate($total, $per_page, $paged);
        echo '</div>';
    }
}
