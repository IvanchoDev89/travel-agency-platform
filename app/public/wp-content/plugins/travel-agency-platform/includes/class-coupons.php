<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Coupons (discount codes) for bookings.
 */
class TAP_Coupons {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_page'], 40);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_coupons';
    }

    /** Validate a coupon code for a given total. Returns coupon row or null. */
    public static function validate($code, $total = 0) {
        global $wpdb;
        $code = strtoupper(sanitize_text_field((string) $code));
        if ('' === $code) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE code = %s", $code));
        if (!$row || !(int) $row->is_active) return null;
        if ($row->expires_at && strtotime($row->expires_at) < time()) return null;
        if ((int) $row->max_uses > 0 && (int) $row->used_count >= (int) $row->max_uses) return null;
        if ((float) $row->min_amount > 0 && (float) $total < (float) $row->min_amount) return null;
        return $row;
    }

    /** Compute discount amount for a coupon and total. */
    public static function discount_for($coupon, $total) {
        $total = (float) $total;
        if (!$coupon || $total <= 0) return 0.0;
        if ('percent' === $coupon->discount_type) {
            $pct = (float) $coupon->discount_value;
            if ($pct > 100) $pct = 100;
            if ($pct < 0) $pct = 0;
            return round($total * ($pct / 100), 2);
        }
        // fixed
        $val = (float) $coupon->discount_value;
        if ($val > $total) $val = $total;
        if ($val < 0) $val = 0;
        return round($val, 2);
    }

    /** Mark a coupon as used (increment counter). */
    public static function mark_used($code) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET used_count = used_count + 1 WHERE code = %s",
            strtoupper(sanitize_text_field($code))
        ));
    }

    /** List all coupons. */
    public static function all() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY created_at DESC") ?: [];
    }

    /** Get by id. */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id));
    }

    /** Create coupon. */
    public static function create($data) {
        global $wpdb;
        $code = strtoupper(sanitize_text_field($data['code'] ?? ''));
        if ('' === $code) return 0;
        $wpdb->insert(self::table(), [
            'code'           => $code,
            'discount_type'  => in_array($data['discount_type'] ?? '', ['percent', 'fixed'], true) ? $data['discount_type'] : 'percent',
            'discount_value' => (float) ($data['discount_value'] ?? 0),
            'max_uses'       => (int) ($data['max_uses'] ?? 0),
            'min_amount'     => (float) ($data['min_amount'] ?? 0),
            'expires_at'     => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
            'is_active'      => !empty($data['is_active']) ? 1 : 0,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Update coupon. */
    public static function update($id, $data) {
        global $wpdb;
        $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        if (isset($data['code'])) $fields['code'] = strtoupper(sanitize_text_field($data['code']));
        if (isset($data['discount_type'])) $fields['discount_type'] = in_array($data['discount_type'], ['percent', 'fixed'], true) ? $data['discount_type'] : 'percent';
        if (isset($data['discount_value'])) $fields['discount_value'] = (float) $data['discount_value'];
        if (isset($data['max_uses'])) $fields['max_uses'] = (int) $data['max_uses'];
        if (isset($data['min_amount'])) $fields['min_amount'] = (float) $data['min_amount'];
        if (isset($data['expires_at'])) $fields['expires_at'] = !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null;
        if (isset($data['is_active'])) $fields['is_active'] = !empty($data['is_active']) ? 1 : 0;
        if (empty($fields)) return false;
        $wpdb->update(self::table(), $fields, ['id' => $id]);
        return true;
    }

    /** Delete coupon. */
    public static function delete($id) {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']);
        return true;
    }

    /** Admin page. */
    public static function add_page() {
        add_submenu_page('travel-platform', __('Cupones', 'travel-agency-platform'), __('Cupones', 'travel-agency-platform'), 'manage_options', 'tap-coupons', [__CLASS__, 'render_page']);
    }

    public static function handle_actions() {
        if (!isset($_POST['tap_coupon_action'])) return;
        if (!current_user_can('manage_options')) wp_die('');
        check_admin_referer('tap_coupon_nonce');
        $action = sanitize_key(wp_unslash($_POST['tap_coupon_action']));
        if ('save' === $action) {
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $data = [
                'code'           => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
                'discount_type'  => sanitize_key(wp_unslash($_POST['discount_type'] ?? 'percent')),
                'discount_value' => (float) ($_POST['discount_value'] ?? 0),
                'max_uses'       => (int) ($_POST['max_uses'] ?? 0),
                'min_amount'     => (float) ($_POST['min_amount'] ?? 0),
                'expires_at'     => sanitize_text_field(wp_unslash($_POST['expires_at'] ?? '')),
                'is_active'      => !empty($_POST['is_active']),
            ];
            if ($id) self::update($id, $data);
else self::create($data);
            wp_safe_redirect(add_query_arg(['page' => 'tap-coupons', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }
        if ('delete' === $action) {
            self::delete(absint($_POST['id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'tap-coupons', 'deleted' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $t = self::table();
        $coupons = $wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC");
        $editing = isset($_GET['edit']) ? self::get(absint($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cupones de descuento', 'travel-agency-platform'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Guardado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p><?php esc_html_e('Eliminado.', 'travel-agency-platform'); ?></p></div><?php endif; ?>

            <h2><?php echo $editing ? esc_html__('Editar cupón', 'travel-agency-platform') : esc_html__('Nuevo cupón', 'travel-agency-platform'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('tap_coupon_nonce'); ?>
                <input type="hidden" name="tap_coupon_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) $editing->id; ?>"><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th><td><input type="text" name="code" value="<?php echo esc_attr($editing->code ?? ''); ?>" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th><td>
                        <select name="discount_type">
                            <option value="percent" <?php selected($editing->discount_type ?? 'percent', 'percent'); ?>><?php esc_html_e('Porcentaje', 'travel-agency-platform'); ?></option>
                            <option value="fixed" <?php selected($editing->discount_type ?? 'percent', 'fixed'); ?>><?php esc_html_e('Monto fijo', 'travel-agency-platform'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Valor', 'travel-agency-platform'); ?></th><td><input type="number" name="discount_value" value="<?php echo esc_attr($editing->discount_value ?? 0); ?>" step="0.01" min="0" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Usos máximos (0 = ilimitado)', 'travel-agency-platform'); ?></th><td><input type="number" name="max_uses" value="<?php echo esc_attr($editing->max_uses ?? 0); ?>" min="0" step="1" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Monto mínimo', 'travel-agency-platform'); ?></th><td><input type="number" name="min_amount" value="<?php echo esc_attr($editing->min_amount ?? 0); ?>" step="0.01" min="0" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Expira (YYYY-MM-DD)', 'travel-agency-platform'); ?></th><td><input type="date" name="expires_at" value="<?php echo esc_attr($editing->expires_at ? substr($editing->expires_at, 0, 10) : ''); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Activo', 'travel-agency-platform'); ?></th><td><input type="checkbox" name="is_active" value="1" <?php checked($editing->is_active ?? 1, 1); ?>></td></tr>
                </table>
                <?php submit_button($editing ? __('Actualizar', 'travel-agency-platform') : __('Crear', 'travel-agency-platform')); ?>
            </form>

            <h2><?php esc_html_e('Cupones', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Valor', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Usos', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Mínimo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Expira', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Activo', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($coupons as $c): ?>
                        <tr>
                            <td><code><?php echo esc_html($c->code); ?></code></td>
                            <td><?php echo esc_html($c->discount_type); ?></td>
                            <td><?php echo esc_html($c->discount_value); ?><?php echo 'percent' === $c->discount_type ? '%' : ''; ?></td>
                            <td><?php echo (int) $c->used_count; ?> / <?php echo (int) $c->max_uses > 0 ? (int) $c->max_uses : '∞'; ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($c->min_amount)); ?></td>
                            <td><?php echo esc_html($c->expires_at ?: '—'); ?></td>
                            <td><?php echo $c->is_active ? '✓' : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'tap-coupons', 'edit' => $c->id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_coupon_nonce'); ?>
                                    <input type="hidden" name="tap_coupon_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$coupons): ?>
                        <tr><td colspan="8"><?php esc_html_e('No hay cupones.', 'travel-agency-platform'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}