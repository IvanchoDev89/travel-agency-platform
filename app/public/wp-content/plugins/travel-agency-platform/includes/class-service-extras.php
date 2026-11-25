<?php
defined('ABSPATH') || exit;

/**
 * Service extras / add-ons for tours and experiences.
 */
class TAP_Service_Extras {

    public static function init() {
        add_action('admin_post_tap_extra_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_tap_extra_delete', [__CLASS__, 'handle_delete']);
    }

    /** Agency may only manage extras of its own listings. */
    public static function owns($service_type, $service_id) {
        $user = wp_get_current_user();
        if (!$user->ID || current_user_can('manage_options')) { return (bool) get_post($service_id); }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency || !in_array($service_type, ['tap_tour', 'tap_experience'], true)) { return false; }
        $key = 'tap_experience' === $service_type ? '_tap_exp_agency_id' : '_tap_tour_agency_id';
        return (int) get_post_meta((int) $service_id, $key, true) === $agency;
    }

    public static function handle_save() {
        if (!is_user_logged_in()) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $id = absint($_POST['extra_id'] ?? 0);
        check_admin_referer('tap_extra_' . $id);
        $existing = $id ? self::get($id) : null;
        $type = $existing ? (string) $existing->service_type : sanitize_key(wp_unslash($_POST['service_type'] ?? ''));
        $sid = $existing ? (int) $existing->service_id : absint($_POST['service_id'] ?? 0);
        if (!self::owns($type, $sid)) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $data = [
            'service_type' => $type, 'service_id' => $sid,
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'price' => (float) ($_POST['price'] ?? 0),
            'is_per_person' => !empty($_POST['is_per_person']) ? 1 : 0,
            'is_required' => !empty($_POST['is_required']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($existing) {
            self::update($id, $data);
        } else {
            self::create($data);
        }
        wp_safe_redirect(add_query_arg('extra_saved', '1', wp_get_referer() ?: home_url('/mi-cuenta/')));
        exit;
    }

    public static function handle_delete() {
        if (!is_user_logged_in()) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $id = absint($_POST['extra_id'] ?? 0);
        check_admin_referer('tap_extra_' . $id);
        $extra = self::get($id);
        if (!$extra || !self::owns((string) $extra->service_type, (int) $extra->service_id)) {
            wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]);
        }
        self::delete($id);
        wp_safe_redirect(add_query_arg('extra_deleted', '1', wp_get_referer() ?: home_url('/mi-cuenta/')));
        exit;
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_service_extras';
    }

    /** List extras for a service. */
    public static function for_service($service_type, $service_id, $active_only = true) {
        global $wpdb;
        $t = self::table();
        $service_type = sanitize_key($service_type);
        $service_id = (int) $service_id;
        if (!$service_type || !$service_id) return [];
        $where = 'WHERE service_type = %s AND service_id = %d';
        if ($active_only) $where .= ' AND is_active = 1';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} {$where} ORDER BY sort_order ASC, id ASC", $service_type, $service_id)) ?: [];
    }

    /** Get a single extra. */
    public static function get($id) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    /** Create an extra. */
    public static function create($data) {
        global $wpdb;
        $t = self::table();
        $service_type = sanitize_key($data['service_type'] ?? '');
        $service_id = (int) ($data['service_id'] ?? 0);
        $name = sanitize_text_field($data['name'] ?? '');
        if (!$service_type || !$service_id || $name === '') return 0;
        $wpdb->insert($t, [
            'service_type'  => $service_type,
            'service_id'   => $service_id,
            'name'         => $name,
            'description'  => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'price'        => isset($data['price']) ? (float) $data['price'] : 0,
            'currency'     => isset($data['currency']) ? sanitize_text_field($data['currency']) : 'USD',
            'is_per_person' => !empty($data['is_per_person']) ? 1 : 0,
            'is_required'  => !empty($data['is_required']) ? 1 : 0,
            'sort_order'   => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
            'is_active'    => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Update an extra. */
    public static function update($id, $data) {
        global $wpdb;
        $t = self::table();
        $id = (int) $id;
        if (!$id) return false;
        $fields = [];
        $formats = [];
        if (isset($data['name'])) { $fields['name'] = sanitize_text_field($data['name']);
$formats[] = '%s'; }
        if (isset($data['description'])) { $fields['description'] = sanitize_textarea_field($data['description']);
$formats[] = '%s'; }
        if (isset($data['price'])) { $fields['price'] = (float) $data['price'];
$formats[] = '%f'; }
        if (isset($data['currency'])) { $fields['currency'] = sanitize_text_field($data['currency']);
$formats[] = '%s'; }
        if (isset($data['is_per_person'])) { $fields['is_per_person'] = !empty($data['is_per_person']) ? 1 : 0;
$formats[] = '%d'; }
        if (isset($data['is_required'])) { $fields['is_required'] = !empty($data['is_required']) ? 1 : 0;
$formats[] = '%d'; }
        if (isset($data['sort_order'])) { $fields['sort_order'] = (int) $data['sort_order'];
$formats[] = '%d'; }
        if (isset($data['is_active'])) { $fields['is_active'] = !empty($data['is_active']) ? 1 : 0;
$formats[] = '%d'; }
        if (empty($fields)) return false;
        $wpdb->update($t, $fields, ['id' => $id], $formats, ['%d']);
        return true;
    }

    /** Delete an extra. */
    public static function delete($id) {
        global $wpdb;
        $t = self::table();
        $wpdb->delete($t, ['id' => (int) $id], ['%d']);
        return true;
    }

    /** Calculate total for selected extras. */
    public static function total_for($extra_ids, $adults = 1, $children = 0, $service_type = '', $service_id = 0) {
        if (empty($extra_ids)) return 0;
        $ids = array_map('intval', (array) $extra_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        global $wpdb;
        $t = self::table();
        $where = "id IN ({$placeholders})";
        $params = $ids;
        if ($service_type && $service_id) {
            $where .= ' AND service_type = %s AND service_id = %d';
            $params[] = sanitize_key($service_type);
            $params[] = (int) $service_id;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$where}", $params)) ?: [];
        $total = 0;
        foreach ($rows as $r) {
            $price = (float) $r->price;
            if ($r->is_per_person) {
                $total += $price * ($adults + $children);
            } else {
                $total += $price;
            }
        }
        return round($total, 2);
    }

    /** Render extras selector HTML for checkout. */
    public static function render_selector($service_type, $service_id) {
        $extras = self::for_service($service_type, $service_id);
        if (empty($extras)) return '';
        ob_start();
        ?>
        <div class="tap-extras-selector">
            <h4><?php esc_html_e('Servicios extra', 'travel-agency-platform'); ?></h4>
            <?php foreach ($extras as $ex): ?>
                <label class="tap-extra-item">
                    <input type="checkbox" name="extras[]" value="<?php echo (int) $ex->id; ?>" data-per-person="<?php echo $ex->is_per_person ? '1' : '0'; ?>" data-price="<?php echo esc_attr($ex->price); ?>">
                    <span class="tap-extra-name"><?php echo esc_html($ex->name); ?></span>
                    <?php if ($ex->description): ?><small class="tap-extra-desc"><?php echo esc_html($ex->description); ?></small><?php endif; ?>
                    <span class="tap-extra-price">
                        <?php echo esc_html(TAP_Currency::fmt((float) $ex->price)); ?>
                        <?php if ($ex->is_per_person): ?><small>/<?php esc_html_e('persona', 'travel-agency-platform'); ?></small><?php endif; ?>
                        <?php if ($ex->is_required): ?><small>(<?php esc_html_e('obligatorio', 'travel-agency-platform'); ?>)</small><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
