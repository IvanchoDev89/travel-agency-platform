<?php
defined('ABSPATH') || exit;

/**
 * Operator UI for users and roles: list by role, change role, link/unlink an
 * agency. Roles/capabilities are defined in TAP_Roles; this is the missing
 * management surface.
 */
class TAP_User_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page'], 39);
        add_action('admin_post_tap_user_role', [__CLASS__, 'handle_role']);
        add_action('admin_post_tap_user_agency', [__CLASS__, 'handle_agency']);
    }

    public static function add_page() {
        add_submenu_page(
            'travel-platform',
            __('Usuarios y roles', 'travel-agency-platform'),
            __('Usuarios y roles', 'travel-agency-platform'),
            'manage_options',
            'tap-users',
            [__CLASS__, 'render']
        );
    }

    /** Roles managed by this plugin. */
    public static function roles() {
        return [
            'administrator' => __('Administrador', 'travel-agency-platform'),
            'tap_agency_admin' => __('Administrador de agencia', 'travel-agency-platform'),
            'tap_agency_employee' => __('Empleado de agencia', 'travel-agency-platform'),
            'tap_client' => __('Viajero', 'travel-agency-platform'),
            'subscriber' => __('Suscriptor', 'travel-agency-platform'),
        ];
    }

    public static function handle_role() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $uid = absint($_POST['user_id'] ?? 0);
        check_admin_referer('tap_user_role_' . $uid);
        $role = sanitize_key(wp_unslash($_POST['role'] ?? ''));
        if (!array_key_exists($role, self::roles())) {
            wp_safe_redirect(add_query_arg('user_error', 'role', admin_url('admin.php?page=tap-users')));
            exit;
        }
        $user = get_userdata($uid);
        if (!$user) { wp_die(esc_html__('Usuario no encontrado.', 'travel-agency-platform')); }
        foreach (array_keys(self::roles()) as $remove) { $user->remove_role($remove); }
        $user->add_role($role);
        wp_safe_redirect(add_query_arg('user_updated', '1', admin_url('admin.php?page=tap-users')));
        exit;
    }

    public static function handle_agency() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $uid = absint($_POST['user_id'] ?? 0);
        check_admin_referer('tap_user_agency_' . $uid);
        $agency_id = absint($_POST['agency_id'] ?? 0);
        if ($agency_id) {
            update_user_meta($uid, '_tap_agency_id', $agency_id);
            update_post_meta($agency_id, '_tap_agency_user_id', $uid);
            $user = get_userdata($uid);
            if ($user) { $user->add_role('tap_agency_admin'); }
        } else {
            delete_user_meta($uid, '_tap_agency_id');
            $user = get_userdata($uid);
            if ($user) { $user->remove_role('tap_agency_admin');
$user->remove_role('tap_agency_employee'); }
        }
        if (class_exists('TAP_Dashboard_Content')) { TAP_Dashboard_Content::sync_agency_registry(); }
        wp_safe_redirect(add_query_arg('user_updated', '1', admin_url('admin.php?page=tap-users')));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $role_filter = isset($_GET['role_filter']) ? sanitize_key(wp_unslash($_GET['role_filter'])) : '';
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 30;

        $args = [
            'number' => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'orderby' => 'user_registered',
            'order' => 'DESC',
            'count_total' => true,
        ];
        if ($role_filter && array_key_exists($role_filter, self::roles())) { $args['role'] = $role_filter; }
        if ('' !== $s) { $args['search'] = '*' . $s . '*'; }
        $query = new WP_User_Query($args);
        $users = $query->get_results();
        $total = (int) $query->get_total();

        $agencies = get_posts([
            'post_type' => 'tap_agency', 'post_status' => 'publish',
            'posts_per_page' => -1, 'orderby' => 'post_title', 'order' => 'ASC',
        ]);

        echo '<div class="wrap"><h1>' . esc_html__('Usuarios y roles', 'travel-agency-platform') . '</h1>';
        if (isset($_GET['user_updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Usuario actualizado.', 'travel-agency-platform') . '</p></div>';
        }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:14px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="tap-users">';
        echo '<input type="search" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Nombre o email', 'travel-agency-platform') . '" class="regular-text">';
        echo '<select name="role_filter"><option value="">' . esc_html__('Todos los roles', 'travel-agency-platform') . '</option>';
        foreach (self::roles() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($role_filter, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><button class="button">' . esc_html__('Filtrar', 'travel-agency-platform') . '</button>';
        echo '<span class="description">' . esc_html(sprintf(__('%d usuarios', 'travel-agency-platform'), $total)) . '</span></form>';

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Usuario', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Roles', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Agencia', 'travel-agency-platform') . '</th>'
            . '<th>' . esc_html__('Acciones', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $linked = (int) get_user_meta($user->ID, '_tap_agency_id', true);
            echo '<tr><td>' . esc_html($user->display_name) . '<br><small>' . esc_html($user->user_email) . '</small></td>';
            echo '<td>' . esc_html(implode(', ', $user->roles)) . '</td>';
            echo '<td>' . ($linked ? esc_html(get_the_title($linked)) : '—') . '</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">'
                . '<input type="hidden" name="action" value="tap_user_role"><input type="hidden" name="user_id" value="' . (int) $user->ID . '">';
            wp_nonce_field('tap_user_role_' . (int) $user->ID, '_wpnonce', true, false);
            echo '<select name="role" class="small-text">';
            foreach (self::roles() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected(in_array($key, (array) $user->roles, true), true, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><button class="button button-small">' . esc_html__('Cambiar', 'travel-agency-platform') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">'
                . '<input type="hidden" name="action" value="tap_user_agency"><input type="hidden" name="user_id" value="' . (int) $user->ID . '">';
            wp_nonce_field('tap_user_agency_' . (int) $user->ID, '_wpnonce', true, false);
            echo '<select name="agency_id" class="small-text"><option value="0">' . esc_html__('Sin agencia', 'travel-agency-platform') . '</option>';
            foreach ($agencies as $ag) {
                echo '<option value="' . (int) $ag->ID . '" ' . selected($linked, (int) $ag->ID, false) . '>' . esc_html($ag->post_title) . '</option>';
            }
            echo '</select><button class="button button-small">' . esc_html__('Asignar', 'travel-agency-platform') . '</button></form></td></tr>';
        }
        if (!$users) {
            echo '<tr><td colspan="4">' . esc_html__('Sin usuarios.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo TAP_Dashboard::paginate($total, $per_page, $paged);
        echo '</div>';
    }
}
