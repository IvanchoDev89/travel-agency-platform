<?php
defined('ABSPATH') || exit;

class TAP_Discounts {
    const OPTION_KEY = 'tap_discount_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('add_meta_boxes', [__CLASS__, 'add_accommodation_metabox']);
        add_action('save_post_tap_accommodation', [__CLASS__, 'save_accommodation_meta'], 10, 2);
    }

    /* ===== Settings API ===== */

    public static function add_settings_page() {
        add_options_page(
            __('Descuentos y Promociones', 'travel-agency-platform'),
            __('Descuentos', 'travel-agency-platform'),
            'manage_options',
            'tap-discounts',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('tap_discount_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => [],
        ]);
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $clean = [];

        // Early bird
        $clean['early_enabled']  = !empty($input['early_enabled']) ? '1' : '0';
        $clean['early_days']     = isset($input['early_days']) ? max(0, (int) $input['early_days']) : 7;
        $clean['early_percent']  = isset($input['early_percent']) ? min(100, max(0, (float) $input['early_percent'])) : 10;

        // Last minute
        $clean['last_enabled']   = !empty($input['last_enabled']) ? '1' : '0';
        $clean['last_days']      = isset($input['last_days']) ? max(0, (int) $input['last_days']) : 3;
        $clean['last_percent']   = isset($input['last_percent']) ? min(100, max(0, (float) $input['last_percent'])) : 15;

        // Long stay
        $clean['long_enabled']   = !empty($input['long_enabled']) ? '1' : '0';
        // tiers: array of [nights => percent]
        $tiers = [];
        if (!empty($input['long_tiers']) && is_array($input['long_tiers'])) {
            foreach ($input['long_tiers'] as $tier) {
                $nights  = isset($tier['nights']) ? max(1, (int) $tier['nights']) : 0;
                $percent = isset($tier['percent']) ? min(100, max(0, (float) $tier['percent'])) : 0;
                if ($nights > 0 && $percent > 0) $tiers[] = ['nights' => $nights, 'percent' => $percent];
            }
        }
        if (empty($tiers)) $tiers = [['nights' => 7, 'percent' => 10], ['nights' => 14, 'percent' => 15]];
        usort($tiers, function ($a, $b) { return $a['nights'] - $b['nights']; });
        $clean['long_tiers'] = $tiers;

        return $clean;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php _e('Descuentos y Promociones', 'travel-agency-platform'); ?></h1>
            <p><?php _e('Configura promociones globales. Los alojamientos pueden desactivar o personalizar estos valores en su ficha.', 'travel-agency-platform'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('tap_discount_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php _e('Descuento anticipado (Early Bird)', 'travel-agency-platform'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[early_enabled]" value="1" <?php checked($s['early_enabled'], '1'); ?>> <?php _e('Habilitar', 'travel-agency-platform'); ?></label>
                            <p class="description">
                                <?php _e('Porcentaje', 'travel-agency-platform'); ?>:
                                <input type="number" step="0.5" min="0" max="100" name="<?php echo self::OPTION_KEY; ?>[early_percent]" value="<?php echo esc_attr($s['early_percent']); ?>">%
                                &nbsp; <?php _e('reservando con al menos', 'travel-agency-platform'); ?>
                                <input type="number" step="1" min="1" name="<?php echo self::OPTION_KEY; ?>[early_days]" value="<?php echo esc_attr($s['early_days']); ?>"> <?php _e('días de antelación', 'travel-agency-platform'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Descuento de última hora', 'travel-agency-platform'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[last_enabled]" value="1" <?php checked($s['last_enabled'], '1'); ?>> <?php _e('Habilitar', 'travel-agency-platform'); ?></label>
                            <p class="description">
                                <?php _e('Porcentaje', 'travel-agency-platform'); ?>:
                                <input type="number" step="0.5" min="0" max="100" name="<?php echo self::OPTION_KEY; ?>[last_percent]" value="<?php echo esc_attr($s['last_percent']); ?>">%
                                &nbsp; <?php _e('reservando con máximo', 'travel-agency-platform'); ?>
                                <input type="number" step="1" min="1" name="<?php echo self::OPTION_KEY; ?>[last_days]" value="<?php echo esc_attr($s['last_days']); ?>"> <?php _e('días de antelación', 'travel-agency-platform'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Descuento por estadía larga', 'travel-agency-platform'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[long_enabled]" value="1" <?php checked($s['long_enabled'], '1'); ?>> <?php _e('Habilitar', 'travel-agency-platform'); ?></label>
                            <div id="tap-long-tiers">
                                <?php foreach ($s['long_tiers'] as $i => $tier) : ?>
                                    <div class="tap-tier-row">
                                        <?php _e('Desde', 'travel-agency-platform'); ?>
                                        <input type="number" step="1" min="1" name="<?php echo self::OPTION_KEY; ?>[long_tiers][<?php echo $i; ?>][nights]" value="<?php echo esc_attr($tier['nights']); ?>" style="width:70px;"> <?php _e('noches', 'travel-agency-platform'); ?>
                                        = <input type="number" step="0.5" min="0" max="100" name="<?php echo self::OPTION_KEY; ?>[long_tiers][<?php echo $i; ?>][percent]" value="<?php echo esc_attr($tier['percent']); ?>" style="width:70px;">%
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p><button type="button" class="button" id="tap-add-tier"><?php _e('Añadir rango', 'travel-agency-platform'); ?></button></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            var idx = 100;
            $('#tap-add-tier').on('click', function(){
                var row = $('<div class="tap-tier-row">Desde <input type="number" step="1" min="1" name="<?php echo self::OPTION_KEY; ?>[long_tiers][' + idx + '][nights]" value="7" style="width:70px;"> noches = <input type="number" step="0.5" min="0" max="100" name="<?php echo self::OPTION_KEY; ?>[long_tiers][' + idx + '][percent]" value="10" style="width:70px;">%</div>');
                $('#tap-long-tiers').append(row);
                idx++;
            });
        });
        </script>
        <?php
    }

    /* ===== Per-accommodation overrides ===== */

    public static function add_accommodation_metabox() {
        add_meta_box(
            'tap_acc_promos',
            __('Promociones', 'travel-agency-platform'),
            [__CLASS__, 'render_accommodation_metabox'],
            'tap_accommodation',
            'side',
            'default'
        );
    }

    public static function render_accommodation_metabox($post) {
        wp_nonce_field('tap_promo_save', 'tap_promo_nonce');
        $meta = self::get_accommodation_settings($post->ID);
        ?>
        <p><label><input type="checkbox" name="tap_promo_override" value="1" <?php checked($meta['override'], '1'); ?>> <?php _e('Personalizar promociones', 'travel-agency-platform'); ?></label></p>
        <div id="tap-promo-fields">
            <p><label><input type="checkbox" name="tap_promo_early" value="1" <?php checked($meta['early_enabled'], '1'); ?>> <?php _e('Early Bird', 'travel-agency-platform'); ?></label>
            <br><small style="color:#666;">
                <input type="number" step="0.5" min="0" max="100" name="tap_promo_early_percent" value="<?php echo esc_attr($meta['early_percent']); ?>" style="width:60px;">% /
                <input type="number" step="1" min="1" name="tap_promo_early_days" value="<?php echo esc_attr($meta['early_days']); ?>" style="width:55px;"> <?php _e('días', 'travel-agency-platform'); ?>
            </small></p>
            <p><label><input type="checkbox" name="tap_promo_last" value="1" <?php checked($meta['last_enabled'], '1'); ?>> <?php _e('Última hora', 'travel-agency-platform'); ?></label>
            <br><small style="color:#666;">
                <input type="number" step="0.5" min="0" max="100" name="tap_promo_last_percent" value="<?php echo esc_attr($meta['last_percent']); ?>" style="width:60px;">% /
                <input type="number" step="1" min="1" name="tap_promo_last_days" value="<?php echo esc_attr($meta['last_days']); ?>" style="width:55px;"> <?php _e('días', 'travel-agency-platform'); ?>
            </small></p>
            <p><label><input type="checkbox" name="tap_promo_long" value="1" <?php checked($meta['long_enabled'], '1'); ?>> <?php _e('Estadía larga', 'travel-agency-platform'); ?></label>
            <br><small style="color:#666;"><textarea name="tap_promo_long_tiers" rows="2" style="width:100%;" placeholder="7:10, 14:15"><?php echo esc_textarea($meta['long_tiers_csv']); ?></textarea><br><?php _e('Formato: noches:porcentaje, separado por comas', 'travel-agency-platform'); ?></small></p>
        </div>
        <?php
    }

    public static function save_accommodation_meta($post_id, $post) {
        if (!isset($_POST['tap_promo_nonce']) || !wp_verify_nonce($_POST['tap_promo_nonce'], 'tap_promo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $override = !empty($_POST['tap_promo_override']) ? '1' : '0';
        $meta = [
            'override'      => $override,
            'early_enabled' => $override === '1' && !empty($_POST['tap_promo_early']) ? '1' : '',
            'early_percent' => isset($_POST['tap_promo_early_percent']) ? min(100, max(0, (float) $_POST['tap_promo_early_percent'])) : 0,
            'early_days'    => isset($_POST['tap_promo_early_days']) ? max(1, (int) $_POST['tap_promo_early_days']) : 7,
            'last_enabled'  => $override === '1' && !empty($_POST['tap_promo_last']) ? '1' : '',
            'last_percent'  => isset($_POST['tap_promo_last_percent']) ? min(100, max(0, (float) $_POST['tap_promo_last_percent'])) : 0,
            'last_days'     => isset($_POST['tap_promo_last_days']) ? max(1, (int) $_POST['tap_promo_last_days']) : 3,
            'long_enabled'  => $override === '1' && !empty($_POST['tap_promo_long']) ? '1' : '',
            'long_tiers'    => [],
        ];

        if (!empty($_POST['tap_promo_long_tiers'])) {
            $meta['long_tiers'] = self::parse_tiers_csv($_POST['tap_promo_long_tiers']);
        }

        update_post_meta($post_id, '_tap_acc_promos', $meta);
    }

    private static function parse_tiers_csv($csv) {
        $tiers = [];
        foreach (explode(',', $csv) as $pair) {
            $pair = trim($pair);
            if (strpos($pair, ':') === false) continue;
            list($nights, $percent) = array_map('trim', explode(':', $pair, 2));
            $nights  = (int) $nights;
            $percent = (float) $percent;
            if ($nights > 0 && $percent > 0) $tiers[] = ['nights' => $nights, 'percent' => $percent];
        }
        usort($tiers, function ($a, $b) { return $a['nights'] - $b['nights']; });
        return $tiers;
    }

    /* ===== Loading + computation ===== */

    public static function get_settings() {
        $defaults = [
            'early_enabled' => '0',
            'early_days'    => 7,
            'early_percent' => 10,
            'last_enabled'  => '0',
            'last_days'     => 3,
            'last_percent'  => 15,
            'long_enabled'  => '0',
            'long_tiers'    => [['nights' => 7, 'percent' => 10], ['nights' => 14, 'percent' => 15]],
        ];
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public static function get_accommodation_settings($accommodation_id) {
        $global = self::get_settings();
        $meta = get_post_meta($accommodation_id, '_tap_acc_promos', true);
        if (!is_array($meta) || empty($meta['override'])) {
            return [
                'override'      => '0',
                'early_enabled' => $global['early_enabled'],
                'early_percent' => $global['early_percent'],
                'early_days'    => $global['early_days'],
                'last_enabled'  => $global['last_enabled'],
                'last_percent'  => $global['last_percent'],
                'last_days'     => $global['last_days'],
                'long_enabled'  => $global['long_enabled'],
                'long_tiers'    => $global['long_tiers'],
                'long_tiers_csv'=> self::tiers_to_csv($global['long_tiers']),
            ];
        }
        $meta['long_tiers_csv'] = self::tiers_to_csv($meta['long_tiers'] ?? []);
        if (empty($meta['long_tiers'])) $meta['long_tiers'] = $global['long_tiers'];
        return $meta;
    }

    public static function tiers_to_csv($tiers) {
        $parts = [];
        foreach ((array) $tiers as $t) {
            $parts[] = $t['nights'] . ':' . $t['percent'];
        }
        return implode(', ', $parts);
    }

    /**
     * Compute applicable discounts for an accommodation room stay.
     *
     * @param int    $room_id
     * @param string $check_in
     * @param string $check_out
     * @param int    $accommodation_id (optional) for per-ac property overrides
     * @return array { base_total, discounts:[{type,label,percent,amount,applied_on}], total, savings, nights, breakdown:int }
     */
    public static function get_quote($room_id, $check_in, $check_out, $accommodation_id = 0) {
        // Caller is responsible for availability; this only computes pricing + discounts.
        $range = TAP_Pricing::get_prices_for_range($room_id, $check_in, $check_out);
        if (!$range['available']) {
            return ['available' => false, 'date' => $range['date'], 'total' => 0, 'base_total' => 0, 'savings' => 0, 'discounts' => [], 'nights' => 0];
        }

        $prices = $range['prices'];
        $base_total = $range['total'];
        $nights = count($prices);
        $today = new DateTime(date('Y-m-d'));
        $checkin_dt = new DateTime($check_in);
        $days_ahead = $today->diff($checkin_dt)->days;

        $accomm = $accommodation_id;
        if (!$accomm) {
            $accomm = (int) get_post_meta($room_id, '_tap_room_accommodation_id', true);
        }
        $cfg = self::get_accommodation_settings($accomm ?: 0);

        $discounts = [];
        $total_discount_pct = 0;

        if ($cfg['early_enabled'] && $cfg['early_percent'] > 0 && $days_ahead >= $cfg['early_days']) {
            $total_discount_pct += (float) $cfg['early_percent'];
            $discounts[] = [
                'type' => 'early',
                'label' => sprintf(__('Early Bird (%d+ días)', 'travel-agency-platform'), $cfg['early_days']),
                'percent' => (float) $cfg['early_percent'],
                'applied_on' => 'total',
            ];
        }

        if ($cfg['last_enabled'] && $cfg['last_percent'] > 0 && $days_ahead <= $cfg['last_days']) {
            $total_discount_pct += (float) $cfg['last_percent'];
            $discounts[] = [
                'type' => 'last',
                'label' => sprintf(__('Última hora (%d días)', 'travel-agency-platform'), $cfg['last_days']),
                'percent' => (float) $cfg['last_percent'],
                'applied_on' => 'total',
            ];
        }

        // Long stay: highest qualifying tier, applied per-night (= same as off total).
        if ($cfg['long_enabled'] && !empty($cfg['long_tiers']) && $nights > 0) {
            $best = 0;
            $best_tier_nights = 0;
            foreach ((array) $cfg['long_tiers'] as $tier) {
                if ((int) $tier['nights'] <= $nights && (float) $tier['percent'] > $best) {
                    $best = (float) $tier['percent'];
                    $best_tier_nights = (int) $tier['nights'];
                }
            }
            if ($best > 0) {
                $total_discount_pct += $best;
                $discounts[] = [
                    'type' => 'long',
                    'label' => sprintf(__('Estadía larga (%d+ noches)', 'travel-agency-platform'), $best_tier_nights),
                    'percent' => $best,
                    'applied_on' => 'per_night',
                ];
            }
        }

        // Compute final total applying percentages.
        $total_discount_pct = min(100, $total_discount_pct);
        $final_total = $base_total * (1 - $total_discount_pct / 100);

        foreach ($discounts as $i => $d) {
            $discounts[$i]['amount'] = $base_total * ($d['percent'] / 100);
        }

        $savings = $base_total - $final_total;

        return [
            'available' => true,
            'base_total' => $base_total,
            'total' => $final_total,
            'savings' => $savings,
            'discounts' => $discounts,
            'nights' => $nights,
            'prices' => $prices,
        ];
    }
}
