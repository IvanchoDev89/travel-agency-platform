<?php
defined('ABSPATH') || exit;

/**
 * Admin screen for market-tooling: waitlist, shuttle groups, iCal import,
 * travel-insurance policies. Read-heavy with a few mutating actions.
 */
class TAP_Dashboard_Market {

    const CAP = 'manage_options';

    public static function init() {
        add_action('admin_post_tap_market_waitlist_notify', [__CLASS__, 'handle_waitlist_notify']);
        add_action('admin_post_tap_market_waitlist_cancel', [__CLASS__, 'handle_waitlist_cancel']);
        add_action('admin_post_tap_market_ical_import', [__CLASS__, 'handle_ical_import']);
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) {
            wp_die(__('No tienes permiso para ver esta pantalla.', 'travel-agency-platform'));
        }
        $msg = isset($_GET['tap_market_msg']) ? sanitize_text_field(wp_unslash($_GET['tap_market_msg'])) : '';
        $err = isset($_GET['tap_market_err']) ? sanitize_text_field(wp_unslash($_GET['tap_market_err'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Herramientas de mercado', 'travel-agency-platform'); ?></h1>
            <?php if ($msg): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Waitlist', 'travel-agency-platform'); ?></h2>
            <?php self::render_waitlist(); ?>

            <h2><?php esc_html_e('Shuttle groups', 'travel-agency-platform'); ?></h2>
            <?php self::render_shuttle(); ?>

            <h2><?php esc_html_e('Importar iCal', 'travel-agency-platform'); ?></h2>
            <?php self::render_ical(); ?>

            <h2><?php esc_html_e('Seguros de viaje emitidos', 'travel-agency-platform'); ?></h2>
            <?php self::render_insurance(); ?>
        </div>
        <?php
    }

    private static function render_waitlist() {
        global $wpdb;
        $t = TAP_Waitlist::table();
        $status_filter = isset($_GET['wl_status']) ? sanitize_key(wp_unslash($_GET['wl_status'])) : '';
        if ($status_filter) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT 200", $status_filter));
        } else {
            $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 200");
        }
        ?>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-market')); ?>" class="button <?php echo $status_filter === '' ? 'button-primary' : ''; ?>"><?php esc_html_e('Todos', 'travel-agency-platform'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-market&wl_status=waiting')); ?>" class="button <?php echo $status_filter === 'waiting' ? 'button-primary' : ''; ?>"><?php esc_html_e('En espera', 'travel-agency-platform'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-market&wl_status=notified')); ?>" class="button <?php echo $status_filter === 'notified' ? 'button-primary' : ''; ?>"><?php esc_html_e('Notificados', 'travel-agency-platform'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tap-market&wl_status=cancelled')); ?>" class="button <?php echo $status_filter === 'cancelled' ? 'button-primary' : ''; ?>"><?php esc_html_e('Cancelados', 'travel-agency-platform'); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:50px;">ID</th>
                <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Fechas', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Personas', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7"><em><?php esc_html_e('Sin entradas.', 'travel-agency-platform'); ?></em></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><?php echo esc_html($r->service_type . ' #' . $r->service_id . ($r->room_id ? ' (room ' . $r->room_id . ')' : '')); ?></td>
                    <td><?php echo esc_html($r->check_in . ($r->check_out ? ' → ' . $r->check_out : '')); ?></td>
                    <td><?php echo (int) $r->adults; ?></td>
                    <td><?php echo $r->user_id ? esc_html('user ' . $r->user_id) : esc_html($r->guest_email); ?></td>
                    <td><?php echo esc_html($r->status); ?></td>
                    <td>
                        <?php if ('waiting' === $r->status): ?>
                            <?php $n = wp_nonce_url(admin_url('admin-post.php?action=tap_market_waitlist_notify&item_id=' . (int) $r->id), 'tap_market_waitlist_notify_' . (int) $r->id); ?>
                            <?php $c = wp_nonce_url(admin_url('admin-post.php?action=tap_market_waitlist_cancel&item_id=' . (int) $r->id), 'tap_market_waitlist_cancel_' . (int) $r->id); ?>
                            <a class="button button-small" href="<?php echo esc_url($n); ?>"><?php esc_html_e('Notificar', 'travel-agency-platform'); ?></a>
                            <a class="button button-small" href="<?php echo esc_url($c); ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_shuttle() {
        global $wpdb;
        $t = TAP_Shuttle::table();
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 200");
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:50px;">ID</th>
                <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Ruta', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Ocupación', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Precio/asiento', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7"><em><?php esc_html_e('Sin grupos.', 'travel-agency-platform'); ?></em></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><?php echo (int) $r->service_id; ?></td>
                    <td><?php echo esc_html($r->route_key); ?></td>
                    <td><?php echo esc_html($r->travel_date); ?></td>
                    <td><?php echo (int) $r->booked_passengers; ?> / <?php echo (int) $r->max_passengers; ?></td>
                    <td><?php echo esc_html(TAP_Currency::fmt(TAP_Shuttle::price_per_seat($r))); ?></td>
                    <td><?php echo esc_html($r->status); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_ical() {
        $rooms = get_posts(['post_type' => 'tap_room', 'posts_per_page' => 200, 'post_status' => 'publish']);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('tap_market_ical_import', 'tap_market_ical_nonce'); ?>
            <input type="hidden" name="action" value="tap_market_ical_import">
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="tap-ical-room"><?php esc_html_e('Habitación', 'travel-agency-platform'); ?></label></th>
                    <td>
                        <select name="room_id" id="tap-ical-room" required>
                            <option value=""><?php esc_html_e('— Selecciona —', 'travel-agency-platform'); ?></option>
                            <?php foreach ($rooms as $rm): ?>
                                <option value="<?php echo (int) $rm->ID; ?>"><?php echo esc_html($rm->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tap-ical-url"><?php esc_html_e('URL del feed iCal', 'travel-agency-platform'); ?></label></th>
                    <td>
                        <input name="ical_url" id="tap-ical-url" type="url" class="regular-text" required placeholder="https://...">
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Importar', 'travel-agency-platform')); ?>
        </form>
        <?php
    }

    private static function render_insurance() {
        global $wpdb;
        $t = TAP_Travel_Insurance::table();
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 200");
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:50px;">ID</th>
                <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Plan', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Prima', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Póliza', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7"><em><?php esc_html_e('Sin pólizas.', 'travel-agency-platform'); ?></em></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><?php echo (int) $r->booking_id; ?></td>
                    <td><?php echo esc_html($r->plan); ?></td>
                    <td><?php echo esc_html(TAP_Currency::fmt((float) $r->premium)); ?></td>
                    <td><?php echo esc_html(TAP_Currency::fmt((float) $r->commission)); ?></td>
                    <td><?php echo esc_html($r->policy_number); ?></td>
                    <td><?php echo esc_html($r->status); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h3><?php esc_html_e('Planes disponibles', 'travel-agency-platform'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e('Plan', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('% del monto', 'travel-agency-platform'); ?></th>
                <th><?php esc_html_e('Cobertura', 'travel-agency-platform'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (TAP_Travel_Insurance::plans() as $key => $plan): ?>
                <tr>
                    <td><?php echo esc_html($plan['label']); ?> (<?php echo esc_html($key); ?>)</td>
                    <td><?php echo esc_html($plan['price_pct']); ?>%</td>
                    <td><?php echo esc_html($plan['coverage']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function redirect($flag, $msg = '', $err = '') {
        $url = admin_url('admin.php?page=tap-market');
        if ($msg) $url = add_query_arg('tap_market_msg', rawurlencode($msg), $url);
        if ($err) $url = add_query_arg('tap_market_err', rawurlencode($err), $url);
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_waitlist_notify() {
        if (!current_user_can(self::CAP)) wp_die('');
        $id = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        check_admin_referer('tap_market_waitlist_notify_' . $id);
        TAP_Waitlist::mark_notified($id);
        self::redirect('ok', __('Entrada marcada como notificada.', 'travel-agency-platform'));
    }

    public static function handle_waitlist_cancel() {
        if (!current_user_can(self::CAP)) wp_die('');
        $id = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        check_admin_referer('tap_market_waitlist_cancel_' . $id);
        TAP_Waitlist::cancel($id);
        self::redirect('ok', __('Entrada cancelada.', 'travel-agency-platform'));
    }

    public static function handle_ical_import() {
        if (!current_user_can(self::CAP)) wp_die('');
        check_admin_referer('tap_market_ical_import', 'tap_market_ical_nonce');
        $room_id = isset($_POST['room_id']) ? (int) $_POST['room_id'] : 0;
        $url = isset($_POST['ical_url']) ? esc_url_raw(wp_unslash($_POST['ical_url'])) : '';
        if (!$room_id || !$url) {
            self::redirect('err', '', __('Faltan datos.', 'travel-agency-platform'));
        }
        $ranges = TAP_ICal_Import::fetch($url);
        if (!$ranges) {
            self::redirect('err', '', __('No se pudieron obtener eventos del feed.', 'travel-agency-platform'));
        }
        $count = TAP_ICal_Import::apply_to_room($room_id, $ranges);
        self::redirect('ok', sprintf(__('Importadas %d fechas bloqueadas (%d eventos).', 'travel-agency-platform'), $count, count($ranges)));
    }
}
