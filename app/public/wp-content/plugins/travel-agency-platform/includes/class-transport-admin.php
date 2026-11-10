<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Transport admin: vehicles and drivers CRUD.
 */
class TAP_Transport_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_pages'], 40);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function add_pages() {
        add_submenu_page('travel-platform', __('Vehículos', 'travel-agency-platform'), __('Vehículos', 'travel-agency-platform'), 'manage_options', 'tap-vehicles', [__CLASS__, 'render_vehicles']);
        add_submenu_page('travel-platform', __('Conductores', 'travel-agency-platform'), __('Conductores', 'travel-agency-platform'), 'manage_options', 'tap-drivers', [__CLASS__, 'render_drivers']);
        add_submenu_page('travel-platform', __('Rutas', 'travel-agency-platform'), __('Rutas', 'travel-agency-platform'), 'manage_options', 'tap-routes', [__CLASS__, 'render_routes']);
        add_submenu_page('travel-platform', __('Viajes', 'travel-agency-platform'), __('Viajes', 'travel-agency-platform'), 'manage_options', 'tap-trips', [__CLASS__, 'render_trips']);
    }

    public static function handle_actions() {
        if (isset($_POST['tap_vehicle_action'])) {
            if (!current_user_can('manage_options')) wp_die('');
            check_admin_referer('tap_vehicle_nonce');
            $action = sanitize_key(wp_unslash($_POST['tap_vehicle_action']));
            if ('save' === $action) {
                $data = [
                    'agency_id' => (int) ($_POST['agency_id'] ?? 0),
                    'type' => sanitize_key(wp_unslash($_POST['type'] ?? 'land')),
                    'plate' => sanitize_text_field(wp_unslash($_POST['plate'] ?? '')),
                    'model' => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
                    'capacity' => (int) ($_POST['capacity'] ?? 0),
                    'status' => sanitize_key(wp_unslash($_POST['status'] ?? 'active')),
                    'driver_id' => (int) ($_POST['driver_id'] ?? 0) ?: null,
                ];
                $id = absint($_POST['id'] ?? 0);
                if ($id) TAP_Transport_Vehicles::update($id, $data);
                else TAP_Transport_Vehicles::create($data);
                wp_safe_redirect(add_query_arg(['page' => 'tap-vehicles', 'updated' => '1'], admin_url('admin.php')));
                exit;
            }
            if ('delete' === $action) {
                TAP_Transport_Vehicles::delete(absint($_POST['id'] ?? 0));
                wp_safe_redirect(add_query_arg(['page' => 'tap-vehicles', 'deleted' => '1'], admin_url('admin.php')));
                exit;
            }
        }
        if (isset($_POST['tap_route_action'])) {
            if (!current_user_can('manage_options')) wp_die('');
            check_admin_referer('tap_route_nonce');
            $action = sanitize_key(wp_unslash($_POST['tap_route_action']));
            if ('save' === $action) {
                $data = [
                    'origin' => sanitize_text_field(wp_unslash($_POST['origin'] ?? '')),
                    'destination' => sanitize_text_field(wp_unslash($_POST['destination'] ?? '')),
                    'distance_km' => (float) ($_POST['distance_km'] ?? 0),
                    'estimated_duration' => (int) ($_POST['estimated_duration'] ?? 0),
                    'type' => sanitize_key(wp_unslash($_POST['type'] ?? 'land')),
                ];
                $id = absint($_POST['id'] ?? 0);
                if ($id) TAP_Transport_Routes::update($id, $data);
                else TAP_Transport_Routes::create($data);
                wp_safe_redirect(add_query_arg(['page' => 'tap-routes', 'updated' => '1'], admin_url('admin.php')));
                exit;
            }
            if ('delete' === $action) {
                TAP_Transport_Routes::delete(absint($_POST['id'] ?? 0));
                wp_safe_redirect(add_query_arg(['page' => 'tap-routes', 'deleted' => '1'], admin_url('admin.php')));
                exit;
            }
        }

        if (isset($_POST['tap_driver_action'])) {
            if (!current_user_can('manage_options')) wp_die('');
            check_admin_referer('tap_driver_nonce');
            $action = sanitize_key(wp_unslash($_POST['tap_driver_action']));
            if ('save' === $action) {
                $data = [
                    'user_id' => (int) ($_POST['user_id'] ?? 0),
                    'agency_id' => (int) ($_POST['agency_id'] ?? 0),
                    'license_number' => sanitize_text_field(wp_unslash($_POST['license_number'] ?? '')),
                    'license_type' => sanitize_text_field(wp_unslash($_POST['license_type'] ?? 'B1')),
                    'status' => sanitize_key(wp_unslash($_POST['status'] ?? 'active')),
                ];
                $id = absint($_POST['id'] ?? 0);
                if ($id) TAP_Transport_Drivers::update($id, $data);
                else TAP_Transport_Drivers::create($data);
                wp_safe_redirect(add_query_arg(['page' => 'tap-drivers', 'updated' => '1'], admin_url('admin.php')));
                exit;
            }
            if ('delete' === $action) {
                TAP_Transport_Drivers::delete(absint($_POST['id'] ?? 0));
                wp_safe_redirect(add_query_arg(['page' => 'tap-drivers', 'deleted' => '1'], admin_url('admin.php')));
                exit;
            }
        }
    }

    public static function render_vehicles() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $vehicles = TAP_Transport_Vehicles::for_agency(0) ?: [];
        // Get all vehicles if agency 0 (admin view).
        if (empty($vehicles)) {
            $vehicles = $wpdb->get_results("SELECT * FROM " . TAP_Transport_Vehicles::table() . " ORDER BY id DESC") ?: [];
        }
        $agencies = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'tap_agency' AND post_status = 'publish' ORDER BY post_title");
        $editing = isset($_GET['edit']) ? TAP_Transport_Vehicles::get(absint($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Vehículos', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <h2><?php echo $editing ? esc_html__('Editar vehículo', 'travel-agency-platform') : esc_html__('Nuevo vehículo', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_vehicle_nonce'); ?>
                <input type="hidden" name="tap_vehicle_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th><td>
                        <select name="agency_id" required>
                            <option value=""><?php esc_html_e('Seleccionar', 'travel-agency-platform'); ?></option>
                            <?php foreach ($agencies as $ag): ?>
                                <option value="<?php echo (int) $ag->ID; ?>" <?php selected($editing->agency_id ?? 0, $ag->ID); ?>><?php echo esc_html($ag->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th><td>
                        <select name="type">
                            <option value="land" <?php selected($editing->type ?? 'land', 'land'); ?>><?php esc_html_e('Terrestre', 'travel-agency-platform'); ?></option>
                            <option value="water" <?php selected($editing->type ?? 'land', 'water'); ?>><?php esc_html_e('Acuático', 'travel-agency-platform'); ?></option>
                            <option value="air" <?php selected($editing->type ?? 'land', 'air'); ?>><?php esc_html_e('Aéreo', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Placa', 'travel-agency-platform'); ?></th><td><input type="text" name="plate" value="<?php echo esc_attr($editing->plate ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Modelo', 'travel-agency-platform'); ?></th><td><input type="text" name="model" value="<?php echo esc_attr($editing->model ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Capacidad', 'travel-agency-platform'); ?></th><td><input type="number" name="capacity" value="<?php echo esc_attr($editing->capacity ?? 0); ?>" min="1" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th><td>
                        <select name="status">
                            <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php esc_html_e('Activo', 'travel-agency-platform'); ?></option>
                            <option value="maintenance" <?php selected($editing->status ?? 'active', 'maintenance'); ?>><?php esc_html_e('Mantenimiento', 'travel-agency-platform'); ?></option>
                            <option value="inactive" <?php selected($editing->status ?? 'active', 'inactive'); ?>><?php esc_html_e('Inactivo', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Conductor asignado', 'travel-agency-platform'); ?></th><td>
                        <select name="driver_id">
                            <option value="0"><?php esc_html_e('Ninguno', 'travel-agency-platform'); ?></option>
                            <?php foreach (TAP_Transport_Drivers::for_agency($editing->agency_id ?? 0) as $drv): ?>
                                <option value="<?php echo (int) $drv->id; ?>" <?php selected($editing->driver_id ?? 0, $drv->id); ?>><?php echo esc_html($drv->license_number); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Vehículos', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Placa', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Modelo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Capacidad', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($vehicles as $v): ?>
                        <tr>
                            <td><?php echo (int) $v->id; ?></td>
                            <td><?php echo esc_html(get_the_title((int) $v->agency_id)); ?></td>
                            <td><?php echo esc_html($v->type); ?></td>
                            <td><?php echo esc_html($v->plate); ?></td>
                            <td><?php echo esc_html($v->model); ?></td>
                            <td><?php echo (int) $v->capacity; ?></td>
                            <td><?php echo esc_html($v->status); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-vehicles', 'edit' => $v->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_vehicle_nonce'); ?>
                                    <input type="hidden" name="tap_vehicle_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $v->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$vehicles): ?>
                        <tr><td colspan="8"><?php esc_html_e('No hay vehículos.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_drivers() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $drivers = $wpdb->get_results("SELECT * FROM " . TAP_Transport_Drivers::table() . " ORDER BY id DESC") ?: [];
        $agencies = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'tap_agency' AND post_status = 'publish' ORDER BY post_title");
        $editing = isset($_GET['edit']) ? TAP_Transport_Drivers::get(absint($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Conductores', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <h2><?php echo $editing ? esc_html__('Editar conductor', 'travel-agency-platform') : esc_html__('Nuevo conductor', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_driver_nonce'); ?>
                <input type="hidden" name="tap_driver_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Usuario (WP)', 'travel-agency-platform'); ?></th><td><input type="number" name="user_id" value="<?php echo esc_attr($editing->user_id ?? 0); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th><td>
                        <select name="agency_id" required>
                            <option value=""><?php esc_html_e('Seleccionar', 'travel-agency-platform'); ?></option>
                            <?php foreach ($agencies as $ag): ?>
                                <option value="<?php echo (int) $ag->ID; ?>" <?php selected($editing->agency_id ?? 0, $ag->ID); ?>><?php echo esc_html($ag->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Número de licencia', 'travel-agency-platform'); ?></th><td><input type="text" name="license_number" value="<?php echo esc_attr($editing->license_number ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Tipo de licencia', 'travel-agency-platform'); ?></th><td>
                        <select name="license_type">
                            <?php foreach (['B1', 'B2', 'B3', 'C1', 'C2', 'D1', 'D2', 'E'] as $lt): ?>
                                <option value="<?php echo esc_attr($lt); ?>" <?php selected($editing->license_type ?? 'B1', $lt); ?>><?php echo esc_html($lt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th><td>
                        <select name="status">
                            <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php esc_html_e('Activo', 'travel-agency-platform'); ?></option>
                            <option value="inactive" <?php selected($editing->status ?? 'active', 'inactive'); ?>><?php esc_html_e('Inactivo', 'travel-agency-platform'); ?></option>
                            <option value="suspended" <?php selected($editing->status ?? 'active', 'suspended'); ?>><?php esc_html_e('Suspendido', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Conductores', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Usuario', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Licencia', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($drivers as $d): ?>
                        <tr>
                            <td><?php echo (int) $d->id; ?></td>
                            <td><?php echo esc_html(get_userdata($d->user_id) ? get_userdata($d->user_id)->display_name : '—'); ?></td>
                            <td><?php echo esc_html(get_the_title((int) $d->agency_id)); ?></td>
                            <td><?php echo esc_html($d->license_number); ?></td>
                            <td><?php echo esc_html($d->license_type); ?></td>
                            <td><?php echo esc_html($d->status); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-drivers', 'edit' => $d->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_driver_nonce'); ?>
                                    <input type="hidden" name="tap_driver_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$drivers): ?>
                        <tr><td colspan="7"><?php esc_html_e('No hay conductores.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_routes() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $routes = TAP_Transport_Routes::search('', '', '');
        $editing = isset($_GET['edit']) ? TAP_Transport_Routes::get(absint($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Rutas', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <h2><?php echo $editing ? esc_html__('Editar ruta', 'travel-agency-platform') : esc_html__('Nueva ruta', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_route_nonce'); ?>
                <input type="hidden" name="tap_route_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Origen', 'travel-agency-platform'); ?></th><td><input type="text" name="origin" value="<?php echo esc_attr($editing->origin ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Destino', 'travel-agency-platform'); ?></th><td><input type="text" name="destination" value="<?php echo esc_attr($editing->destination ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Distancia (km)', 'travel-agency-platform'); ?></th><td><input type="number" name="distance_km" value="<?php echo esc_attr($editing->distance_km ?? 0); ?>" step="0.01" min="0" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Duración estimada (min)', 'travel-agency-platform'); ?></th><td><input type="number" name="estimated_duration" value="<?php echo esc_attr($editing->estimated_duration ?? 0); ?>" min="0" step="1" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th><td>
                        <select name="type">
                            <option value="land" <?php selected($editing->type ?? 'land', 'land'); ?>><?php esc_html_e('Terrestre', 'travel-agency-platform'); ?></option>
                            <option value="water" <?php selected($editing->type ?? 'land', 'water'); ?>><?php esc_html_e('Acuático', 'travel-agency-platform'); ?></option>
                            <option value="air" <?php selected($editing->type ?? 'land', 'air'); ?>><?php esc_html_e('Aéreo', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Rutas', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Origen', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Destino', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Distancia (km)', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Duración (min)', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($routes as $r): ?>
                        <tr>
                            <td><?php echo (int) $r->id; ?></td>
                            <td><?php echo esc_html($r->origin); ?></td>
                            <td><?php echo esc_html($r->destination); ?></td>
                            <td><?php echo esc_html($r->distance_km); ?></td>
                            <td><?php echo (int) $r->estimated_duration; ?></td>
                            <td><?php echo esc_html($r->type); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-routes', 'edit' => $r->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_route_nonce'); ?>
                                    <input type="hidden" name="tap_route_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$routes): ?>
                        <tr><td colspan="7"><?php esc_html_e('No hay rutas.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_trips() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $trips = TAP_Transport_Trips::for_agency(0) ?: [];
        // Admin: get all trips
        if (empty($trips)) {
            $trips = $wpdb->get_results("SELECT * FROM " . TAP_Transport_Trips::table() . " ORDER BY departure_time DESC LIMIT 100") ?: [];
        }
        $statuses = ['scheduled', 'boarding', 'in_transit', 'completed', 'cancelled'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Viajes', 'travel-agency-platform'); ?></h1>
            <div id="tap-trips-map" style="height:360px;border-radius:12px;margin-bottom:20px;border:1px solid #e2e8f0;"></div>
            <link rel="stylesheet" href="<?php echo esc_url(TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css'); ?>">
            <script src="<?php echo esc_url(TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js'); ?>"></script>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Salida', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Llegada', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Pasajeros', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($trips as $tr): ?>
                        <tr data-trip="<?php echo (int) $tr->id; ?>">
                            <td><?php echo (int) $tr->id; ?></td>
                            <td><?php echo esc_html(get_the_title((int) $tr->service_id)); ?></td>
                            <td><?php echo esc_html($tr->departure_time); ?></td>
                            <td><?php echo esc_html($tr->arrival_time ?: '—'); ?></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($tr->status); ?>"><?php echo esc_html($tr->status); ?></span></td>
                            <td><?php echo count(TAP_Transport_Trips::passengers((int) $tr->id)); ?></td>
                            <td>
                                <?php if ('scheduled' === $tr->status): ?>
                                    <button type="button" class="button button-small tap-trip-status" data-trip="<?php echo (int) $tr->id; ?>" data-status="boarding"><?php esc_html_e('Iniciar abordaje', 'travel-agency-platform'); ?></button>
                                <?php elseif ('boarding' === $tr->status): ?>
                                    <button type="button" class="button button-small tap-trip-status" data-trip="<?php echo (int) $tr->id; ?>" data-status="in_transit"><?php esc_html_e('Iniciar viaje', 'travel-agency-platform'); ?></button>
                                <?php elseif ('in_transit' === $tr->status): ?>
                                    <button type="button" class="button button-small tap-trip-status" data-trip="<?php echo (int) $tr->id; ?>" data-status="completed"><?php esc_html_e('Completar', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                                <button type="button" class="button button-small tap-trip-live" data-trip="<?php echo (int) $tr->id; ?>"><?php esc_html_e('Ver en vivo', 'travel-agency-platform'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$trips): ?>
                        <tr><td colspan="7"><?php esc_html_e('No hay viajes.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <script>
            (function(){
                if (typeof L === 'undefined') return;
                var map = L.map('tap-trips-map').setView([9.93, -84.08], 8);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap'}).addTo(map);
                var markers = {};
                document.querySelectorAll('.tap-trip-live').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tid = btn.getAttribute('data-trip');
                        var fd = new FormData();
                        fd.append('action', 'tap_transport_live');
                        fd.append('trip_id', tid);
                        fd.append('nonce', '<?php echo esc_js(wp_create_nonce('tap_transport_nonce')); ?>');
                        fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success && res.data.position) {
                                var pos = res.data.position;
                                if (markers[tid]) map.removeLayer(markers[tid]);
                                markers[tid] = L.marker([pos.lat, pos.lng]).addTo(map).bindPopup('Trip ' + tid);
                                map.setView([pos.lat, pos.lng], 12);
                            } else {
                                alert('Sin posición disponible.');
                            }
                        });
                    });
                });
                document.querySelectorAll('.tap-trip-status').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tid = btn.getAttribute('data-trip');
                        var st = btn.getAttribute('data-status');
                        var fd = new FormData();
                        fd.append('action', 'tap_transport_status');
                        fd.append('trip_id', tid);
                        fd.append('status', st);
                        fd.append('nonce', '<?php echo esc_js(wp_create_nonce('tap_transport_nonce')); ?>');
                        fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success) { location.reload(); }
                            else { alert(res.data && res.data.message ? res.data.message : 'Error'); }
                        });
                    });
                });
            })();
            </script>
        </div>
        <?php
    }
}