<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
// phpcs:disable WordPress.WP.GlobalVariablesOverride
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
defined('ABSPATH') || exit;

class TAP_Metaboxes {
    private static $fields   = [];
    private static $loaded   = false;

    /**
     * Ensure the tour/experience wizard metabox is visible by default
     * (not collapsed/hidden) in the block editor.
     */
    public static function unhide_tour_metabox($hidden, $screen) {
        if (!is_object($screen)) return $hidden;
        $post_type = isset($screen->post_type) ? $screen->post_type : '';
        if (!in_array($post_type, ['tap_tour', 'tap_experience'], true)) return $hidden;
        $hidden = array_values(array_diff((array) $hidden, ['tap_tour_details', 'tap_experience_details', 'tap_extras']));
        return $hidden;
    }

    public static function register() {
        self::register_agency_metabox();
        self::register_accommodation_metabox();
        self::register_room_metabox();
        self::register_tour_metabox();
        self::register_experience_metabox();
        self::register_transport_metabox();
        self::register_car_rental_metabox();
        self::register_boat_metabox();
        self::register_package_metabox();
        self::register_booking_metabox();
        self::register_equipment_metabox();
        self::register_destination_metabox();
        self::register_translation_metabox();
        self::register_spot_metabox();
        self::$loaded = true;
    }

    private static function service_types() {
        return ['tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
    }

    /**
     * The register() and get_fields() helpers are also reached from the front-end back-office,
     * where add_meta_box() (wp-admin/includes/template.php) is not loaded.
     * The field registry must populate everywhere; the meta box UI is admin-only.
     */
    private static function maybe_add_meta_box($id, $title, $callback, $screen, $context = 'advanced', $priority = 'default', $callback_args = null) {
        if (function_exists('add_meta_box') && is_admin()) {
            add_meta_box($id, $title, $callback, $screen, $context, $priority, $callback_args);
        }
    }

    private static function register_destination_metabox() {
        foreach (self::service_types() as $type) {
            self::maybe_add_meta_box('tap_destination', __('Destination', 'travel-agency-platform'), [__CLASS__, 'render_destination'], $type, 'side', 'default');
        }
    }

    /**
     * Educational spot fields (parque nacional, reserva, volcán…). Reuses the
     * generic render()/save() pipeline so the same field registry drives the
     * metabox, the REST payload and the map.
     */
    private static function register_translation_metabox() {
        foreach (self::service_types() as $type) {
            self::maybe_add_meta_box('tap_translations', __('Traducciones (EN)', 'travel-agency-platform'), [__CLASS__, 'render_translations'], $type, 'normal', 'low');
        }
    }

    public static function render_extras($post) {
        $service_type = $post->post_type;
        $service_id = (int) $post->ID;
        $extras = TAP_Service_Extras::for_service($service_type, $service_id, false);
        wp_nonce_field('tap_extras_save', 'tap_extras_nonce');
        ?>
        <div class="tap-extras-editor" data-service-type="<?php echo esc_attr($service_type); ?>"
            data-service-id="<?php echo (int) $service_id; ?>">
            <p class="description"><?php esc_html_e('Agrega servicios adicionales que el cliente puede contratar junto al tour/experiencia.', 'travel-agency-platform'); ?></p>
            <table class="widefat striped tap-extras-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Precio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Moneda', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Por persona', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Obligatorio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Activo', 'travel-agency-platform'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tap-extras-rows">
                    <?php foreach ($extras as $ex): ?>
                        <tr data-id="<?php echo (int) $ex->id; ?>">
                            <td><input type="text" name="extras_name[]" value="<?php echo esc_attr($ex->name); ?>" class="regular-text"></td>
                            <td><textarea name="extras_desc[]" rows="2" class="large-text"><?php echo esc_textarea($ex->description); ?></textarea></td>
                            <td><input type="number" name="extras_price[]" value="<?php echo esc_attr($ex->price); ?>" step="0.01" style="width:90px;"></td>
                            <td><input type="text" name="extras_currency[]" value="<?php echo esc_attr($ex->currency); ?>" style="width:60px;"></td>
                            <td><input type="checkbox" name="extras_per_person[<?php echo (int) $ex->id; ?>]" value="1" <?php checked(1, $ex->is_per_person); ?>></td>
                            <td><input type="checkbox" name="extras_required[<?php echo (int) $ex->id; ?>]" value="1" <?php checked(1, $ex->is_required); ?>></td>
                            <td><input type="checkbox" name="extras_active[<?php echo (int) $ex->id; ?>]" value="1" <?php checked(1, $ex->is_active); ?>></td>
                            <td><button type="button" class="button tap-extra-remove"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button><input type="hidden" name="extras_id[]" value="<?php echo (int) $ex->id; ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="tap-extra-add"><?php esc_html_e('Agregar extra', 'travel-agency-platform'); ?></button></p>
            <script>
            (function(){
                var btn = document.getElementById('tap-extra-add');
                var tbody = document.getElementById('tap-extras-rows');
                if (!btn || !tbody) return;
                btn.addEventListener('click', function(){
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="text" name="extras_name[]" value="" class="regular-text"></td>'
                        + '<td><textarea name="extras_desc[]" rows="2" class="large-text"></textarea></td>'
                        + '<td><input type="number" name="extras_price[]" value="0" step="0.01" style="width:90px;"></td>'
                        + '<td><input type="text" name="extras_currency[]" value="USD" style="width:60px;"></td>'
                        + '<td><input type="checkbox" name="extras_per_person[new]" value="1" checked></td>'
                        + '<td><input type="checkbox" name="extras_required[new]" value="1"></td>'
                        + '<td><input type="checkbox" name="extras_active[new]" value="1" checked></td>'
                        + '<td><button type="button" class="button tap-extra-remove"><?php echo esc_js(__('Eliminar', 'travel-agency-platform')); ?></button><input type="hidden" name="extras_id[]" value="0"></td>';
                    tbody.appendChild(tr);
                });
                tbody.addEventListener('click', function(e){
                    if (e.target && e.target.classList.contains('tap-extra-remove')) {
                        e.target.closest('tr').remove();
                    }
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function render_translations($post) {
        if (!class_exists('TAP_Translations')) return;
        $tr = TAP_Translations::all_for($post->post_type, $post->ID, 'en');
        wp_nonce_field('tap_translations_save', 'tap_translations_nonce');
        ?>
        <p class="description"><?php esc_html_e('Traducción al inglés del título y descripción. Si se deja vacío, se usa el español.', 'travel-agency-platform'); ?></p>
        <table class="form-table" style="margin-top:0;">
            <tr>
                <th><label for="tap_tr_title"><?php esc_html_e('Título (EN)', 'travel-agency-platform'); ?></label></th>
                <td><input type="text" id="tap_tr_title" name="tap_tr_title" value="<?php echo esc_attr($tr['title'] ?? ''); ?>" class="regular-text" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="tap_tr_description"><?php esc_html_e('Descripción (EN)', 'travel-agency-platform'); ?></label></th>
                <td><textarea id="tap_tr_description" name="tap_tr_description" rows="4" class="large-text"><?php echo esc_textarea($tr['description'] ?? ''); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    private static function register_spot_metabox() {
        self::maybe_add_meta_box('tap_spot_details', __('Ficha educativa', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_spot', 'normal', 'high');
        self::maybe_add_meta_box('tap_spot_location', __('Ubicación', 'travel-agency-platform'), [__CLASS__, 'render_spot_location'], 'tap_spot', 'side', 'default');
        self::$fields['tap_spot'] = TAP_Destination_Spots::fields();
        // Yield pricing rules for every service type.
        foreach (self::service_types() as $stype) {
            self::maybe_add_meta_box('tap_pricing_rules_' . $stype, __('Reglas de precio (yield)', 'travel-agency-platform'), [__CLASS__, 'render_pricing_rules'], $stype, 'normal', 'low');
        }
    }

    /**
     * "Ubicación" box on a destination ficha.
     *
     * The place is picked with the same 5-level cascade the listings use, so
     * both sides speak the same language: one destination = one ficha + one
     * location term, and the services tagged with that term show up on the
     * ficha.
     */
    public static function render_spot_location($post) {
        $term_id = TAP_Destination_Spots::location_term_id($post->ID);
        echo '<p class="description">' . esc_html__('Lugar al que pertenece esta ficha. Los servicios publicados en esa ubicación se muestran en la ficha.', 'travel-agency-platform') . '</p>';
        echo TAP_Destinations::render_picker($term_id, 'tap_spot_location');
        if (! $term_id) {
            echo '<p class="description">' . esc_html__('Sin ubicación: la ficha se muestra igual, pero no lista servicios.', 'travel-agency-platform') . '</p>';
        }
    }

    public static function render_destination($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $selected = 0;
        $highest  = -1;
        $terms    = get_the_terms($post->ID, 'tap_location');
        if (is_array($terms) && $terms) {
            foreach ($terms as $term) {
                $level = TAP_Destinations::term_level($term->term_id);
                $level = $level !== null ? $level : TAP_Destinations::term_depth($term->term_id);
                if ($level > $highest) {
                    $highest  = $level;
                    $selected = (int) $term->term_id;
                }
            }
        }
        echo '<p class="description">' . esc_html__('Destination hierarchy: país → provincia → cantón → distrito → lugar.', 'travel-agency-platform') . '</p>';
        echo TAP_Destinations::render_picker($selected, 'tap_destination');
    }

    /**
     * Options for the tour_type taxonomy, keyed by term slug.
     *
     * @return array<string,string>
     */
    public static function tour_type_options() {
        $opts = [];
        if (!taxonomy_exists('tap_tour_type')) {
            return $opts;
        }
        foreach (get_terms(['taxonomy' => 'tap_tour_type', 'hide_empty' => false]) as $t) {
            if ($t instanceof WP_Term) {
                $opts[$t->slug] = $t->name;
            }
        }
        return $opts;
    }

    /**
     * Render the yield pricing rules metabox.
     */
    public static function render_pricing_rules($post) {
        if (!class_exists('TAP_Pricing_Rules')) return;
        $rules = TAP_Pricing_Rules::for_service($post->post_type, $post->ID);
        wp_nonce_field('tap_pricing_rules_save', 'tap_pricing_rules_nonce');
        ?>
        <div class="tap-pricing-rules">
            <p class="description"><?php esc_html_e('Las reglas aplican un modificador al precio base cuando se cumplen las condiciones. La primera regla que haga match (por prioridad) se aplica.', 'travel-agency-platform'); ?></p>
            <?php if ($rules): ?>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <thead><tr>
                        <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Condición', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Modificador', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Prioridad', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acción', 'travel-agency-platform'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ($rules as $r):
                        $cond = json_decode($r->condition_json, true);
                        $cond_str = '';
                        if (is_array($cond)) {
                            foreach ($cond as $k => $v) {
                                if (is_array($v)) $v = implode(',', $v);
                                $cond_str .= $k . '=' . $v . ' ';
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html(TAP_Pricing_Rules::rule_types()[$r->rule_type] ?? $r->rule_type); ?></td>
                            <td><?php echo esc_html(trim($cond_str)); ?></td>
                            <td><?php echo esc_html(($r->modifier_type === 'percent' ? $r->modifier_value . '%' : '$' . $r->modifier_value)); ?></td>
                            <td><?php echo (int) $r->priority; ?></td>
                            <td>
                                <button type="submit" name="tap_pricing_rule_delete" value="<?php echo (int) $r->id; ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(__('¿Eliminar esta regla?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('Sin reglas. El precio base se usa sin modificadores.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>

            <h4><?php esc_html_e('Agregar regla', 'travel-agency-platform'); ?></h4>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="tap_rule_type"><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></label></th>
                    <td>
                        <select name="tap_rule_type" id="tap_rule_type">
                            <option value="">—</option>
                            <?php foreach (TAP_Pricing_Rules::rule_types() as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tap_rule_days"><?php esc_html_e('Días de anticipación (early bird / last minute)', 'travel-agency-platform'); ?></label></th>
                    <td><input type="number" name="tap_rule_days" id="tap_rule_days" value="30" min="0" style="width:80px;"></td>
                </tr>
                <tr>
                    <th><label for="tap_rule_months"><?php esc_html_e('Meses (seasonal, separados por coma)', 'travel-agency-platform'); ?></label></th>
                    <td><input type="text" name="tap_rule_months" id="tap_rule_months" value="" placeholder="12,1,2" style="width:200px;"></td>
                </tr>
                <tr>
                    <th><label for="tap_rule_modifier_type"><?php esc_html_e('Tipo de modificador', 'travel-agency-platform'); ?></label></th>
                    <td>
                        <select name="tap_rule_modifier_type" id="tap_rule_modifier_type">
                            <option value="percent"><?php esc_html_e('Porcentaje (%)', 'travel-agency-platform'); ?></option>
                            <option value="fixed"><?php esc_html_e('Monto fijo', 'travel-agency-platform'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tap_rule_modifier_value"><?php esc_html_e('Valor (negativo = descuento)', 'travel-agency-platform'); ?></label></th>
                    <td><input type="number" name="tap_rule_modifier_value" id="tap_rule_modifier_value" value="-10" step="0.01" style="width:100px;"></td>
                </tr>
                <tr>
                    <th><label for="tap_rule_priority"><?php esc_html_e('Prioridad', 'travel-agency-platform'); ?></label></th>
                    <td><input type="number" name="tap_rule_priority" id="tap_rule_priority" value="5" min="0" style="width:80px;"></td>
                </tr>
            </table>
            <button type="submit" name="tap_pricing_rule_add" value="1" class="button button-primary"><?php esc_html_e('Agregar regla', 'travel-agency-platform'); ?></button>
        </div>
        <?php
    }

    public static function get_fields($post_type) {
        if (!self::$loaded) {
            self::register();
        }
        return isset(self::$fields[$post_type]) ? self::$fields[$post_type] : [];
    }

    private static function register_agency_metabox() {
        self::maybe_add_meta_box('tap_agency_details', __('Agency Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_agency', 'normal', 'high');
        self::$fields['tap_agency'] = [
            '_tap_agency_email'     => ['type' => 'email', 'label' => __('Email', 'travel-agency-platform')],
            '_tap_agency_phone'     => ['type' => 'text', 'label' => __('Phone', 'travel-agency-platform')],
            '_tap_agency_whatsapp'  => ['type' => 'text', 'label' => __('WhatsApp', 'travel-agency-platform')],
            '_tap_agency_website'   => ['type' => 'url', 'label' => __('Website', 'travel-agency-platform')],
            '_tap_agency_address'   => ['type' => 'textarea', 'label' => __('Address', 'travel-agency-platform')],
            '_tap_agency_city'      => ['type' => 'text', 'label' => __('City', 'travel-agency-platform')],
            '_tap_agency_country'   => ['type' => 'text', 'label' => __('Country', 'travel-agency-platform')],
            '_tap_agency_commission'=> ['type' => 'number', 'label' => __('Commission %', 'travel-agency-platform'), 'step' => '0.01', 'default' => '10'],
            '_tap_agency_verified'  => ['type' => 'checkbox', 'label' => __('Verified Agency', 'travel-agency-platform')],
            '_tap_agency_user_id'   => ['type' => 'number', 'label' => __('WordPress User ID', 'travel-agency-platform')],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_accommodation_metabox() {
        self::maybe_add_meta_box('tap_accommodation_details', __('Accommodation Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_location', __('Location & Address', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_location'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_policies', __('Policies & Rules', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_policies'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_gallery', __('Photo Gallery', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_gallery'], 'tap_accommodation', 'side', 'low');

        self::$fields['tap_accommodation'] = [
            '_tap_acc_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_acc_type'         => ['type' => 'select', 'label' => __('Type', 'travel-agency-platform'), 'options' => ['hotel' => 'Hotel', 'hostel' => 'Hostel', 'resort' => 'Resort', 'villa' => 'Villa', 'cabin' => 'Cabin', 'apartment' => 'Apartment', 'boutique' => 'Boutique Hotel', 'eco' => 'Eco-Lodge']],
            '_tap_acc_stars'        => ['type' => 'select', 'label' => __('Stars', 'travel-agency-platform'), 'options' => ['1' => '1 Star', '2' => '2 Stars', '3' => '3 Stars', '4' => '4 Stars', '5' => '5 Stars']],
            '_tap_acc_price_per_night' => ['type' => 'number', 'label' => __('Price per Night ($) — fallback if no room types', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_acc_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_acc_capacity'     => ['type' => 'number', 'label' => __('Max Guests', 'travel-agency-platform')],
            '_tap_acc_rooms'        => ['type' => 'number', 'label' => __('Number of Rooms', 'travel-agency-platform')],
            '_tap_acc_bathrooms'    => ['type' => 'number', 'label' => __('Bathrooms', 'travel-agency-platform')],
            '_tap_acc_bedrooms'     => ['type' => 'number', 'label' => __('Bedrooms', 'travel-agency-platform')],
            '_tap_acc_checkin_time' => ['type' => 'time', 'label' => __('Default Check-in Time', 'travel-agency-platform')],
            '_tap_acc_checkout_time'=> ['type' => 'time', 'label' => __('Default Check-out Time', 'travel-agency-platform')],
            '_tap_acc_lat'          => ['type' => 'text', 'label' => __('Latitude', 'travel-agency-platform')],
            '_tap_acc_lng'          => ['type' => 'text', 'label' => __('Longitude', 'travel-agency-platform')],
            '_tap_acc_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_tour_metabox() {
        self::maybe_add_meta_box('tap_tour_details', __('Tour Details — Wizard multi-paso', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_tour', 'normal', 'high');
        self::maybe_add_meta_box('tap_extras', __('Servicios extra (add-ons)', 'travel-agency-platform'), [__CLASS__, 'render_extras'], 'tap_tour', 'normal', 'low');
        self::$fields['tap_tour'] = [
            '_tap_tour_agency_id'   => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_tour_type'        => ['type' => 'select', 'label' => __('Tour Type', 'travel-agency-platform'), 'options' => self::tour_type_options()],
            '_tap_tour_duration'    => ['type' => 'text', 'label' => __('Duration', 'travel-agency-platform'), 'placeholder' => 'e.g. 3 days / 2 nights'],
            '_tap_tour_difficulty'  => ['type' => 'select', 'label' => __('Difficulty', 'travel-agency-platform'), 'options' => ['easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard', 'extreme' => 'Extreme']],
            '_tap_tour_price_adult' => ['type' => 'number', 'label' => __('Price per Adult ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_price_child' => ['type' => 'number', 'label' => __('Price per Child ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_currency'    => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_tour_max_people'  => ['type' => 'number', 'label' => __('Max People per Group', 'travel-agency-platform')],
            '_tap_tour_min_age'     => ['type' => 'number', 'label' => __('Minimum Age', 'travel-agency-platform')],
            '_tap_tour_start_time'  => ['type' => 'time', 'label' => __('Start Time', 'travel-agency-platform')],
            '_tap_tour_end_time'    => ['type' => 'time', 'label' => __('End Time', 'travel-agency-platform')],
            '_tap_tour_meeting_point' => ['type' => 'text', 'label' => __('Meeting Point', 'travel-agency-platform')],
            '_tap_tour_includes'    => ['type' => 'textarea', 'label' => __('What\'s Included', 'travel-agency-platform')],
            '_tap_tour_excludes'    => ['type' => 'textarea', 'label' => __('What\'s Not Included', 'travel-agency-platform')],
            '_tap_tour_what_bring'  => ['type' => 'textarea', 'label' => __('What to Bring', 'travel-agency-platform')],
            '_tap_tour_lat'         => ['type' => 'text', 'label' => __('Latitude', 'travel-agency-platform')],
            '_tap_tour_lng'         => ['type' => 'text', 'label' => __('Longitude', 'travel-agency-platform')],
            '_tap_tour_is_active'   => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_tour_capacity'    => ['type' => 'number', 'label' => __('Cupos por fecha (capacidad)', 'travel-agency-platform')],
            /* ---- categorización ---- */
            '_tap_tour_category'    => ['type' => 'select', 'label' => __('Categoría', 'travel-agency-platform'), 'options' => ['yoga' => 'Yoga / Wellness', 'kayak' => 'Kayak', 'senderismo' => 'Senderismo', 'snorkel' => 'Snorkel', 'birdwatching' => 'Birdwatching', 'cultural' => 'Cultural', 'aventura' => 'Aventura', 'wellness' => 'Wellness', 'workshop' => 'Workshop', 'class' => 'Class', 'tasting' => 'Tasting', 'art' => 'Art', 'nature' => 'Nature', 'otro' => 'Otro']],
            '_tap_tour_tags'        => ['type' => 'text', 'label' => __('Etiquetas (comma-separated)', 'travel-agency-platform')],
            '_tap_tour_fitness'     => ['type' => 'select', 'label' => __('Nivel físico', 'travel-agency-platform'), 'options' => ['none' => 'Ninguno', 'light' => 'Ligero', 'moderate' => 'Moderado', 'athletic' => 'Atlético']],
            '_tap_tour_max_age'     => ['type' => 'number', 'label' => __('Edad máxima', 'travel-agency-platform')],
            /* ---- logística ---- */
            '_tap_tour_pickup'      => ['type' => 'checkbox', 'label' => __('Incluye traslado/recogida', 'travel-agency-platform')],
            '_tap_tour_pickup_locations' => ['type' => 'textarea', 'label' => __('Puntos de recogida', 'travel-agency-platform')],
            /* ---- grupo ---- */
            '_tap_tour_min_people'  => ['type' => 'number', 'label' => __('Mínimo de personas para operar', 'travel-agency-platform')],
            '_tap_tour_max_per_booking' => ['type' => 'number', 'label' => __('Máximo por reserva', 'travel-agency-platform')],
            '_tap_tour_private'     => ['type' => 'checkbox', 'label' => __('Disponible como tour privado', 'travel-agency-platform')],
            '_tap_tour_private_price' => ['type' => 'number', 'label' => __('Precio tour privado ($)', 'travel-agency-platform'), 'step' => '0.01'],
            /* ---- tarifas ---- */
            '_tap_tour_price_senior' => ['type' => 'number', 'label' => __('Precio Senior ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_price_student' => ['type' => 'number', 'label' => __('Precio Estudiante ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_child_age'   => ['type' => 'text', 'label' => __('Rango de edad niño', 'travel-agency-platform'), 'placeholder' => 'e.g. 5-12'],
            /* ---- inclusiones ---- */
            '_tap_tour_equipment'   => ['type' => 'textarea', 'label' => __('Equipo provisto', 'travel-agency-platform')],
            '_tap_tour_equipment_rental' => ['type' => 'checkbox', 'label' => __('Alquiler de equipo disponible', 'travel-agency-platform')],
            /* ---- ecoturismo ---- */
            '_tap_tour_leave_no_trace' => ['type' => 'checkbox', 'label' => __('Sigue principios Leave No Trace', 'travel-agency-platform'), 'default' => '1'],
            '_tap_tour_carbon_offset' => ['type' => 'checkbox', 'label' => __('Compensación de carbono incluida', 'travel-agency-platform')],
            '_tap_tour_local_community' => ['type' => 'checkbox', 'label' => __('Beneficia a comunidad local', 'travel-agency-platform')],
            '_tap_tour_wildlife'    => ['type' => 'checkbox', 'label' => __('Incluye observación de fauna', 'travel-agency-platform')],
            '_tap_tour_trail'       => ['type' => 'select', 'label' => __('Condición del sendero', 'travel-agency-platform'), 'options' => ['paved' => 'Pavimentado', 'dirt' => 'Tierra', 'rocky' => 'Rocoso', 'muddy' => 'Lodoso', 'mixed' => 'Mixto']],
            '_tap_tour_elevation'   => ['type' => 'number', 'label' => __('Desnivel (metros)', 'travel-agency-platform')],
            /* ---- legales ---- */
            '_tap_tour_terms'       => ['type' => 'textarea', 'label' => __('Términos y condiciones', 'travel-agency-platform')],
            '_tap_tour_privacy'     => ['type' => 'textarea', 'label' => __('Política de privacidad', 'travel-agency-platform')],
            '_tap_tour_waiver'      => ['type' => 'textarea', 'label' => __('Texto del waiver (descargo)', 'travel-agency-platform')],
            '_tap_tour_waiver_required' => ['type' => 'checkbox', 'label' => __('Waiver obligatorio antes de reservar', 'travel-agency-platform')],
            '_tap_tour_waiver_min_age' => ['type' => 'number', 'label' => __('Edad mínima para firmar waiver', 'travel-agency-platform')],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_equipment_metabox() {
        self::maybe_add_meta_box('tap_equipment_details', __('Equipment Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_equipment', 'normal', 'high');
        self::$fields['tap_equipment'] = [
            '_tap_eq_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_eq_type'         => ['type' => 'select', 'label' => __('Category', 'travel-agency-platform'), 'options' => ['hiking' => 'Senderismo', 'camping' => 'Camping', 'kayak' => 'Kayak', 'snorkel' => 'Snorkel', 'surf' => 'Surf', 'bicicleta' => 'Bicicleta', 'acuatico' => 'Deportes acuáticos', 'fotografia' => 'Fotografía', 'bebe' => 'Equipo para bebés', 'playa' => 'Playa', 'cocina' => 'Cocina / Parrilla', 'otros' => 'Otros']],
            '_tap_eq_price_day'    => ['type' => 'number', 'label' => __('Price per Day ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_price_hour'   => ['type' => 'number', 'label' => __('Price per Hour ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_deposit'      => ['type' => 'number', 'label' => __('Security Deposit ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_quantity'     => ['type' => 'number', 'label' => __('Units Available', 'travel-agency-platform')],
            '_tap_eq_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_eq_pickup'       => ['type' => 'text', 'label' => __('Pick-up / Delivery Location', 'travel-agency-platform')],
            '_tap_eq_conditions'   => ['type' => 'textarea', 'label' => __('Rental Conditions', 'travel-agency-platform')],
            '_tap_eq_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'       => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description' => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_experience_metabox() {
        self::maybe_add_meta_box('tap_experience_details', __('Experience Details — Wizard multi-paso', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_experience', 'normal', 'high');
        self::maybe_add_meta_box('tap_extras', __('Servicios extra (add-ons)', 'travel-agency-platform'), [__CLASS__, 'render_extras'], 'tap_experience', 'normal', 'low');
        self::$fields['tap_experience'] = [
            '_tap_exp_agency_id'     => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_exp_type'          => ['type' => 'select', 'label' => __('Category', 'travel-agency-platform'), 'options' => ['workshop' => 'Workshop', 'class' => 'Class', 'tasting' => 'Tasting', 'art' => 'Art', 'nature' => 'Nature', 'wellness' => 'Wellness', 'other' => 'Other']],
            '_tap_exp_duration'      => ['type' => 'text', 'label' => __('Duration', 'travel-agency-platform'), 'placeholder' => 'e.g. 2 hours'],
            '_tap_exp_price_adult'   => ['type' => 'number', 'label' => __('Price per Person ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_exp_price_child'   => ['type' => 'number', 'label' => __('Price per Child ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_exp_currency'      => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_exp_max_people'    => ['type' => 'number', 'label' => __('Max Participants', 'travel-agency-platform')],
            '_tap_exp_min_age'       => ['type' => 'number', 'label' => __('Minimum Age', 'travel-agency-platform')],
            '_tap_exp_start_time'    => ['type' => 'time', 'label' => __('Start Time', 'travel-agency-platform')],
            '_tap_exp_end_time'      => ['type' => 'time', 'label' => __('End Time', 'travel-agency-platform')],
            '_tap_exp_meeting_point' => ['type' => 'text', 'label' => __('Meeting Point', 'travel-agency-platform')],
            '_tap_exp_includes'      => ['type' => 'textarea', 'label' => __("What's Included", 'travel-agency-platform')],
            '_tap_exp_excludes'      => ['type' => 'textarea', 'label' => __("What's Not Included", 'travel-agency-platform')],
            '_tap_exp_what_bring'    => ['type' => 'textarea', 'label' => __('What to Bring', 'travel-agency-platform')],
            '_tap_exp_lat'           => ['type' => 'text', 'label' => __('Latitude', 'travel-agency-platform')],
            '_tap_exp_lng'           => ['type' => 'text', 'label' => __('Longitude', 'travel-agency-platform')],
            '_tap_exp_is_active'     => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_exp_capacity'      => ['type' => 'number', 'label' => __('Cupos por fecha (capacidad)', 'travel-agency-platform')],
            /* ---- categorización ---- */
            '_tap_exp_category'      => ['type' => 'select', 'label' => __('Categoría', 'travel-agency-platform'), 'options' => ['workshop' => 'Workshop', 'class' => 'Class', 'tasting' => 'Tasting', 'art' => 'Art', 'nature' => 'Nature', 'wellness' => 'Wellness', 'yoga' => 'Yoga', 'meditation' => 'Meditación', 'otro' => 'Otro']],
            '_tap_exp_tags'          => ['type' => 'text', 'label' => __('Etiquetas (comma-separated)', 'travel-agency-platform')],
            '_tap_exp_fitness'       => ['type' => 'select', 'label' => __('Nivel físico', 'travel-agency-platform'), 'options' => ['none' => 'Ninguno', 'light' => 'Ligero', 'moderate' => 'Moderado', 'athletic' => 'Atlético']],
            '_tap_exp_max_age'       => ['type' => 'number', 'label' => __('Edad máxima', 'travel-agency-platform')],
            /* ---- logística ---- */
            '_tap_exp_pickup'        => ['type' => 'checkbox', 'label' => __('Incluye traslado/recogida', 'travel-agency-platform')],
            '_tap_exp_pickup_locations' => ['type' => 'textarea', 'label' => __('Puntos de recogida', 'travel-agency-platform')],
            /* ---- grupo ---- */
            '_tap_exp_min_people'    => ['type' => 'number', 'label' => __('Mínimo de personas para operar', 'travel-agency-platform')],
            '_tap_exp_max_per_booking' => ['type' => 'number', 'label' => __('Máximo por reserva', 'travel-agency-platform')],
            '_tap_exp_private'       => ['type' => 'checkbox', 'label' => __('Disponible como experiencia privada', 'travel-agency-platform')],
            '_tap_exp_private_price' => ['type' => 'number', 'label' => __('Precio privado ($)', 'travel-agency-platform'), 'step' => '0.01'],
            /* ---- tarifas ---- */
            '_tap_exp_price_senior'  => ['type' => 'number', 'label' => __('Precio Senior ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_exp_price_student' => ['type' => 'number', 'label' => __('Precio Estudiante ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_exp_child_age'     => ['type' => 'text', 'label' => __('Rango de edad niño', 'travel-agency-platform'), 'placeholder' => 'e.g. 5-12'],
            /* ---- inclusiones ---- */
            '_tap_exp_equipment'     => ['type' => 'textarea', 'label' => __('Equipo provisto', 'travel-agency-platform')],
            '_tap_exp_equipment_rental' => ['type' => 'checkbox', 'label' => __('Alquiler de equipo disponible', 'travel-agency-platform')],
            /* ---- bienestar / ecoturismo ---- */
            '_tap_exp_leave_no_trace' => ['type' => 'checkbox', 'label' => __('Sigue principios Leave No Trace', 'travel-agency-platform'), 'default' => '1'],
            '_tap_exp_carbon_offset' => ['type' => 'checkbox', 'label' => __('Compensación de carbono incluida', 'travel-agency-platform')],
            '_tap_exp_local_community' => ['type' => 'checkbox', 'label' => __('Beneficia a comunidad local', 'travel-agency-platform')],
            '_tap_exp_wildlife'      => ['type' => 'checkbox', 'label' => __('Incluye observación de fauna', 'travel-agency-platform')],
            /* ---- legales ---- */
            '_tap_exp_terms'         => ['type' => 'textarea', 'label' => __('Términos y condiciones', 'travel-agency-platform')],
            '_tap_exp_privacy'       => ['type' => 'textarea', 'label' => __('Política de privacidad', 'travel-agency-platform')],
            '_tap_exp_waiver'        => ['type' => 'textarea', 'label' => __('Texto del waiver (descargo)', 'travel-agency-platform')],
            '_tap_exp_waiver_required' => ['type' => 'checkbox', 'label' => __('Waiver obligatorio antes de reservar', 'travel-agency-platform')],
            '_tap_exp_waiver_min_age' => ['type' => 'number', 'label' => __('Edad mínima para firmar waiver', 'travel-agency-platform')],
            '_tap_seo_title'         => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'   => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
        // Yield pricing rules metabox registered via service_types() loop.
    }

    private static function register_transport_metabox() {
        self::maybe_add_meta_box('tap_transport_details', __('Transport Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_transport', 'normal', 'high');
        self::$fields['tap_transport'] = [
            '_tap_trans_agency_id'  => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_trans_type'       => ['type' => 'select', 'label' => __('Service Type', 'travel-agency-platform'), 'options' => ['private' => 'Private', 'shared' => 'Shared', 'shuttle' => 'Shuttle', 'luxury' => 'Luxury']],
            '_tap_trans_vehicle'    => ['type' => 'text', 'label' => __('Vehicle Type', 'travel-agency-platform')],
            '_tap_trans_capacity'   => ['type' => 'number', 'label' => __('Passenger Capacity', 'travel-agency-platform')],
            '_tap_trans_price'      => ['type' => 'number', 'label' => __('Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_trans_price_type' => ['type' => 'select', 'label' => __('Price Type', 'travel-agency-platform'), 'options' => ['per_person' => 'Per Person', 'per_vehicle' => 'Per Vehicle', 'per_trip' => 'Per Trip']],
            '_tap_trans_currency'   => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_trans_from'       => ['type' => 'text', 'label' => __('Pick-up Location', 'travel-agency-platform')],
            '_tap_trans_to'         => ['type' => 'text', 'label' => __('Drop-off Location', 'travel-agency-platform')],
            '_tap_trans_duration'   => ['type' => 'text', 'label' => __('Trip Duration', 'travel-agency-platform')],
            '_tap_trans_has_wifi'   => ['type' => 'checkbox', 'label' => __('WiFi on Board', 'travel-agency-platform')],
            '_tap_trans_has_ac'     => ['type' => 'checkbox', 'label' => __('Air Conditioning', 'travel-agency-platform')],
            '_tap_trans_is_active'  => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_car_rental_metabox() {
        self::maybe_add_meta_box('tap_car_rental_details', __('Car Rental Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_car_rental', 'normal', 'high');
        self::$fields['tap_car_rental'] = [
            '_tap_car_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_car_brand'        => ['type' => 'text', 'label' => __('Brand', 'travel-agency-platform')],
            '_tap_car_model'        => ['type' => 'text', 'label' => __('Model', 'travel-agency-platform')],
            '_tap_car_year'         => ['type' => 'number', 'label' => __('Year', 'travel-agency-platform')],
            '_tap_car_type'         => ['type' => 'select', 'label' => __('Car Type', 'travel-agency-platform'), 'options' => ['economy' => 'Economy', 'compact' => 'Compact', 'midsize' => 'Midsize', 'suv' => 'SUV', 'luxury' => 'Luxury', 'van' => 'Van', 'pickup' => 'Pickup']],
            '_tap_car_transmission' => ['type' => 'select', 'label' => __('Transmission', 'travel-agency-platform'), 'options' => ['manual' => 'Manual', 'automatic' => 'Automatic']],
            '_tap_car_seats'        => ['type' => 'number', 'label' => __('Seats', 'travel-agency-platform')],
            '_tap_car_doors'        => ['type' => 'number', 'label' => __('Doors', 'travel-agency-platform')],
            '_tap_car_price_per_day'=> ['type' => 'number', 'label' => __('Price per Day ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_car_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_car_location'     => ['type' => 'text', 'label' => __('Pick-up Location', 'travel-agency-platform')],
            '_tap_car_has_gps'      => ['type' => 'checkbox', 'label' => __('GPS Included', 'travel-agency-platform')],
            '_tap_car_has_insurance'=> ['type' => 'checkbox', 'label' => __('Insurance Included', 'travel-agency-platform')],
            '_tap_car_has_ac'       => ['type' => 'checkbox', 'label' => __('Air Conditioning', 'travel-agency-platform')],
            '_tap_car_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_boat_metabox() {
        self::maybe_add_meta_box('tap_boat_details', __('Boat Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_boat', 'normal', 'high');
        self::$fields['tap_boat'] = [
            '_tap_boat_agency_id'   => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_boat_type'        => ['type' => 'select', 'label' => __('Boat Type', 'travel-agency-platform'), 'options' => ['yacht' => 'Yacht', 'catamaran' => 'Catamaran', 'speedboat' => 'Speedboat', 'fishing' => 'Fishing Boat', 'sailboat' => 'Sailboat', 'kayak' => 'Kayak', 'pontoon' => 'Pontoon']],
            '_tap_boat_capacity'    => ['type' => 'number', 'label' => __('Max Passengers', 'travel-agency-platform')],
            '_tap_boat_length'      => ['type' => 'text', 'label' => __('Length', 'travel-agency-platform')],
            '_tap_boat_price_half'  => ['type' => 'number', 'label' => __('Half Day Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_price_full'  => ['type' => 'number', 'label' => __('Full Day Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_price_hourly'=> ['type' => 'number', 'label' => __('Hourly Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_currency'    => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_boat_departure'   => ['type' => 'text', 'label' => __('Departure Point', 'travel-agency-platform')],
            '_tap_boat_destination' => ['type' => 'text', 'label' => __('Destination', 'travel-agency-platform')],
            '_tap_boat_has_captain' => ['type' => 'checkbox', 'label' => __('Captain Included', 'travel-agency-platform')],
            '_tap_boat_has_crew'    => ['type' => 'checkbox', 'label' => __('Crew Included', 'travel-agency-platform')],
            '_tap_boat_is_active'   => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_package_metabox() {
        self::maybe_add_meta_box('tap_package_details', __('Package Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_package', 'normal', 'high');
        self::$fields['tap_package'] = [
            '_tap_pkg_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_pkg_duration'     => ['type' => 'text', 'label' => __('Duration', 'travel-agency-platform'), 'placeholder' => 'e.g. 5 days / 4 nights'],
            '_tap_pkg_price'        => ['type' => 'number', 'label' => __('Total Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_pkg_price_per_person' => ['type' => 'number', 'label' => __('Price per Person ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_pkg_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_pkg_max_people'   => ['type' => 'number', 'label' => __('Max People', 'travel-agency-platform')],
            '_tap_pkg_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_booking_metabox() {
        self::maybe_add_meta_box('tap_booking_details', __('Booking Details', 'travel-agency-platform'), [__CLASS__, 'render_booking'], 'tap_booking', 'normal', 'high');
    }

    private static function register_room_metabox() {
        self::maybe_add_meta_box('tap_room_details', __('Room Details', 'travel-agency-platform'), [__CLASS__, 'render_room'], 'tap_room', 'normal', 'high');
        self::maybe_add_meta_box('tap_room_beds', __('Bed Configuration', 'travel-agency-platform'), [__CLASS__, 'render_room_beds'], 'tap_room', 'normal', 'high');
        self::maybe_add_meta_box('tap_room_gallery', __('Room Photos', 'travel-agency-platform'), [__CLASS__, 'render_room_gallery'], 'tap_room', 'side', 'low');
    }

    public static function render($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $post_type = $post->post_type;

        if (!isset(self::$fields[$post_type])) {
                echo '<p>' . esc_html__('No configuration fields found.', 'travel-agency-platform') . '</p>';
            return;
        }

        // Multi-step wizard for tours/experiences (user-friendly for agencies).
        if (in_array($post_type, ['tap_tour', 'tap_experience'], true)) {
            self::render_wizard($post, $post_type);
            return;
        }

        echo '<table class="form-table">';
        foreach (self::$fields[$post_type] as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);
            if (empty($value) && isset($field['default'])) {
                $value = $field['default'];
            }
            echo '<tr>';
            echo '<th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th>';
            echo '<td>';
            self::render_field($key, $field, $value);
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    /**
     * Multi-step wizard renderer for tours/experiences.
     * Groups fields into logical steps with prev/next navigation.
     */
    private static function render_wizard($post, $post_type) {
        $steps = self::wizard_steps($post_type);
        $all_fields = self::$fields[$post_type];
        ?>
        <div class="tap-wizard-notice" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:20px;">🧙</span>
            <div>
                <strong style="color:#1e40af;"><?php esc_html_e('Wizard multi-paso', 'travel-agency-platform'); ?></strong>
                <p style="margin:2px 0 0;font-size:13px;color:#374151;"><?php esc_html_e('Completa cada paso para configurar el tour. Usa los botones Anterior/Siguiente o haz clic en los números de arriba.', 'travel-agency-platform'); ?></p>
            </div>
        </div>
        <div class="tap-wizard" data-post-type="<?php echo esc_attr($post_type); ?>">
            <div class="tap-wizard-progress">
                <?php foreach ($steps as $i => $step): ?>
                    <button type="button" class="tap-wizard-step-btn <?php echo 0 === $i ? 'is-active' : ''; ?>" data-step="<?php echo (int) $i; ?>">
                        <span class="tap-wizard-step-num"><?php echo (int) ($i + 1); ?></span>
                        <span class="tap-wizard-step-label"><?php echo esc_html($step['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php foreach ($steps as $i => $step): ?>
                <div class="tap-wizard-panel <?php echo 0 === $i ? 'is-active' : 'is-hidden'; ?>" data-step="<?php echo (int) $i; ?>">
                    <h3 class="tap-wizard-panel-title"><?php echo esc_html($step['label']); ?></h3>
                    <table class="form-table">
                        <?php foreach ($step['fields'] as $key): ?>
                            <?php if (!isset($all_fields[$key])) continue; ?>
                            <?php $field = $all_fields[$key]; ?>
                            <?php $value = get_post_meta($post->ID, $key, true); ?>
                            <?php if (empty($value) && isset($field['default'])) $value = $field['default']; ?>
                            <tr>
                                <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
                                <td><?php self::render_field($key, $field, $value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <div class="tap-wizard-nav">
                        <?php if ($i > 0): ?>
                            <button type="button" class="button tap-wizard-prev" data-step="<?php echo (int) $i; ?>">&larr; <?php esc_html_e('Anterior', 'travel-agency-platform'); ?></button>
                        <?php endif; ?>
                        <?php if ($i < count($steps) - 1): ?>
                            <button type="button" class="button button-primary tap-wizard-next" data-step="<?php echo (int) $i; ?>"><?php esc_html_e('Siguiente', 'travel-agency-platform'); ?> &rarr;</button>
                        <?php else: ?>
                            <span class="tap-wizard-hint"><?php esc_html_e('Guarda el tour con el botón "Actualizar/Publish" del panel derecho.', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <style>
        .tap-wizard{max-width:100%;}
        .tap-wizard-progress{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;}
        .tap-wizard-step-btn{display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #d1d5db;border-radius:999px;background:#fff;cursor:pointer;font-size:13px;color:#4b5563;transition:all .2s;}
        .tap-wizard-step-btn.is-active{background:#2563eb;color:#fff;border-color:#2563eb;}
        .tap-wizard-step-btn:hover:not(.is-active){background:#f3f4f6;}
        .tap-wizard-step-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.08);font-weight:700;font-size:12px;}
        .tap-wizard-step-btn.is-active .tap-wizard-step-num{background:rgba(255,255,255,.25);}
        .tap-wizard-panel{display:block;}
        .tap-wizard-panel.is-hidden{display:none;}
        .tap-wizard-panel-title{margin:0 0 12px;font-size:1.15rem;color:#1f2937;}
        .tap-wizard-nav{display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb;}
        .tap-wizard-hint{font-size:12px;color:#6b7280;font-style:italic;}
        </style>
        <script>
        (function initWizard(){
            var w = document.querySelector('.tap-wizard');
            if (!w) { setTimeout(initWizard, 100); return; }
            var panels = w.querySelectorAll('.tap-wizard-panel');
            var btns = w.querySelectorAll('.tap-wizard-step-btn');
            // Hide all but first.
            panels.forEach(function(p, idx){ if (idx !== 0) p.classList.add('is-hidden'); });
            function show(i){
                panels.forEach(function(p){ p.classList.add('is-hidden'); p.classList.remove('is-active'); });
                btns.forEach(function(b){ b.classList.remove('is-active'); });
                if (panels[i]) { panels[i].classList.remove('is-hidden'); panels[i].classList.add('is-active'); }
                if (btns[i]) btns[i].classList.add('is-active');
            }
            btns.forEach(function(b){ b.addEventListener('click', function(){ show(parseInt(b.dataset.step,10)); }); });
            w.querySelectorAll('.tap-wizard-next').forEach(function(b){ b.addEventListener('click', function(){ show(parseInt(b.dataset.step,10)+1); }); });
            w.querySelectorAll('.tap-wizard-prev').forEach(function(b){ b.addEventListener('click', function(){ show(parseInt(b.dataset.step,10)-1); }); });
        })();
        </script>
        <?php
    }

    /**
     * Step definitions for the tour/experience wizard.
     */
    private static function wizard_steps($post_type) {
        // Define steps per type.
        if ($post_type === 'tap_tour') {
            return [
                ['label' => __('1. Información básica', 'travel-agency-platform'), 'fields' => ['_tap_tour_agency_id', '_tap_tour_category', '_tap_tour_type', '_tap_tour_tags', '_tap_tour_duration', '_tap_tour_difficulty', '_tap_tour_fitness', '_tap_tour_min_age', '_tap_tour_max_age', '_tap_tour_is_active']],
                ['label' => __('2. Logística', 'travel-agency-platform'), 'fields' => ['_tap_tour_start_time', '_tap_tour_end_time', '_tap_tour_meeting_point', '_tap_tour_lat', '_tap_tour_lng', '_tap_tour_pickup', '_tap_tour_pickup_locations']],
                ['label' => __('3. Tarifas y capacidad', 'travel-agency-platform'), 'fields' => ['_tap_tour_price_adult', '_tap_tour_price_child', '_tap_tour_price_senior', '_tap_tour_price_student', '_tap_tour_currency', '_tap_tour_child_age', '_tap_tour_capacity', '_tap_tour_min_people', '_tap_tour_max_people', '_tap_tour_max_per_booking', '_tap_tour_private', '_tap_tour_private_price']],
                ['label' => __('4. Inclusiones y ecoturismo', 'travel-agency-platform'), 'fields' => ['_tap_tour_includes', '_tap_tour_excludes', '_tap_tour_what_bring', '_tap_tour_equipment', '_tap_tour_equipment_rental', '_tap_tour_leave_no_trace', '_tap_tour_carbon_offset', '_tap_tour_local_community', '_tap_tour_wildlife', '_tap_tour_trail', '_tap_tour_elevation']],
                ['label' => __('5. Legales', 'travel-agency-platform'), 'fields' => ['_tap_tour_terms', '_tap_tour_privacy', '_tap_tour_waiver', '_tap_tour_waiver_required', '_tap_tour_waiver_min_age']],
                ['label' => __('6. SEO', 'travel-agency-platform'), 'fields' => ['_tap_seo_title', '_tap_seo_description']],
            ];
        }
        return [
            ['label' => __('1. Información básica', 'travel-agency-platform'), 'fields' => ['_tap_exp_agency_id', '_tap_exp_category', '_tap_exp_type', '_tap_exp_tags', '_tap_exp_duration', '_tap_exp_fitness', '_tap_exp_min_age', '_tap_exp_max_age', '_tap_exp_is_active']],
            ['label' => __('2. Logística', 'travel-agency-platform'), 'fields' => ['_tap_exp_start_time', '_tap_exp_end_time', '_tap_exp_meeting_point', '_tap_exp_lat', '_tap_exp_lng', '_tap_exp_pickup', '_tap_exp_pickup_locations']],
            ['label' => __('3. Tarifas y capacidad', 'travel-agency-platform'), 'fields' => ['_tap_exp_price_adult', '_tap_exp_price_child', '_tap_exp_price_senior', '_tap_exp_price_student', '_tap_exp_currency', '_tap_exp_child_age', '_tap_exp_capacity', '_tap_exp_min_people', '_tap_exp_max_people', '_tap_exp_max_per_booking', '_tap_exp_private', '_tap_exp_private_price']],
            ['label' => __('4. Inclusiones y ecoturismo', 'travel-agency-platform'), 'fields' => ['_tap_exp_includes', '_tap_exp_excludes', '_tap_exp_what_bring', '_tap_exp_equipment', '_tap_exp_equipment_rental', '_tap_exp_leave_no_trace', '_tap_exp_carbon_offset', '_tap_exp_local_community', '_tap_exp_wildlife']],
            ['label' => __('5. Legales', 'travel-agency-platform'), 'fields' => ['_tap_exp_terms', '_tap_exp_privacy', '_tap_exp_waiver', '_tap_exp_waiver_required', '_tap_exp_waiver_min_age']],
            ['label' => __('6. SEO', 'travel-agency-platform'), 'fields' => ['_tap_seo_title', '_tap_seo_description']],
        ];
    }

    private static function render_field($key, $field, $value) {
        switch ($field['type']) {
            case 'text':
            case 'email':
            case 'url':
            case 'number':
            case 'time':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" %s %s>',
                    esc_attr($field['type']),
                    esc_attr($key),
                    esc_attr($key),
                    esc_attr($value),
                    isset($field['step']) ? 'step="' . esc_attr($field['step']) . '"' : '',
                    isset($field['placeholder']) ? 'placeholder="' . esc_attr($field['placeholder']) . '"' : ''
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
                    esc_attr($key),
                    esc_attr($key),
                    esc_textarea($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($key),
                    esc_attr($key),
                    checked('1', $value, false)
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($key), esc_attr($key));
                foreach ($field['options'] as $opt_val => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_val),
                        selected($opt_val, $value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;

            case 'select_post':
                // Bounded dropdown: a catalogue can hold thousands of posts and
                // rendering every one of them made the editor unusable. The
                // current selection is always injected even when it falls
                // outside the window, so saving never clears an existing link.
                $limit   = 300;
                $current = (int) $value;
                $query   = new WP_Query([
                    'post_type'      => $field['post_type'],
                    'posts_per_page' => $limit,
                    'post_status'    => 'publish',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ]);
                $posts     = $query->posts;
                $truncated = $query->found_posts > $limit;

                $listed = array_map('intval', wp_list_pluck($posts, 'ID'));
                if ($current && !in_array($current, $listed, true)) {
                    $selected_post = get_post($current);
                    if ($selected_post && 'publish' === $selected_post->post_status) {
                        array_unshift($posts, $selected_post);
                    }
                }
                printf('<select id="%s" name="%s"><option value="">-- Select --</option>', esc_attr($key), esc_attr($key));
                foreach ($posts as $p) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($p->ID),
                        selected($p->ID, $value, false),
                        esc_html($p->post_title)
                    );
                }
                echo '</select>';
                if ($truncated) {
                    printf(
                        '<p class="description">%s</p>',
                        esc_html(sprintf(
                            /* translators: %d: número máximo de resultados mostrados */
                            __('Mostrando los primeros %d resultados. Usa la búsqueda del listado para localizar otros.', 'travel-agency-platform'),
                            $limit
                        ))
                    );
                }
                break;
        }
    }

    /** Keep only a signed decimal; empty/invalid input becomes an empty string. */
    private static function sanitize_coord($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        // Accept only an optionally signed decimal number; anything else is dropped.
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return '';
        }
        return (string) (float) $raw;
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['tap_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tap_metabox_nonce'])), 'tap_metabox_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $post_type = $post->post_type;

        if (isset(self::$fields[$post_type])) {
            foreach (self::$fields[$post_type] as $key => $field) {
                if ($field['type'] === 'checkbox') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } elseif (isset($_POST[$key])) {
                    $raw = wp_unslash($_POST[$key]);
                    if ($field['type'] === 'textarea') {
                        // Keep line breaks (e.g. "datos de interés" bullet lists).
                        $value = sanitize_textarea_field($raw);
                    } elseif (in_array($key, TAP_Destination_Spots::coord_fields(), true)) {
                        // Coordinates stay raw strings; validated to a numeric range.
                        $value = self::sanitize_coord($raw);
                    } else {
                        $value = sanitize_text_field($raw);
                    }
                } else {
                    continue;
                }
                update_post_meta($post_id, $key, $value);
            }
        }

        if ($post_type === 'tap_accommodation') {
            $text_fields = ['_tap_acc_address', '_tap_acc_city', '_tap_acc_state', '_tap_acc_zip', '_tap_acc_country', '_tap_acc_cancellation', '_tap_acc_house_rules', '_tap_acc_gallery'];
            foreach ($text_fields as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
                }
            }
        }

        // Service extras (tours/experiences).
        if (in_array($post_type, ['tap_tour', 'tap_experience'], true)) {
            if (isset($_POST['tap_extras_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tap_extras_nonce'])), 'tap_extras_save')) {
                $names = isset($_POST['extras_name']) && is_array($_POST['extras_name']) ? (array) wp_unslash($_POST['extras_name']) : [];
                $descs = isset($_POST['extras_desc']) && is_array($_POST['extras_desc']) ? (array) wp_unslash($_POST['extras_desc']) : [];
                $prices = isset($_POST['extras_price']) && is_array($_POST['extras_price']) ? (array) wp_unslash($_POST['extras_price']) : [];
                $currencies = isset($_POST['extras_currency']) && is_array($_POST['extras_currency']) ? (array) wp_unslash($_POST['extras_currency']) : [];
                $ids = isset($_POST['extras_id']) && is_array($_POST['extras_id']) ? (array) wp_unslash($_POST['extras_id']) : [];
                $per_person = isset($_POST['extras_per_person']) && is_array($_POST['extras_per_person']) ? (array) wp_unslash($_POST['extras_per_person']) : [];
                $required = isset($_POST['extras_required']) && is_array($_POST['extras_required']) ? (array) wp_unslash($_POST['extras_required']) : [];
                $active = isset($_POST['extras_active']) && is_array($_POST['extras_active']) ? (array) wp_unslash($_POST['extras_active']) : [];

                // Existing extras for this service.
                $existing = TAP_Service_Extras::for_service($post_type, $post_id, false);
                $existing_ids = array_map('intval', wp_list_pluck($existing, 'id'));
                $submitted_ids = array_map('intval', $ids);
                // Delete removed extras.
                foreach ($existing_ids as $eid) {
                    if (!in_array($eid, $submitted_ids, true)) {
                        TAP_Service_Extras::delete($eid);
                    }
                }
                // Upsert.
                foreach ($names as $i => $name) {
                    $name = sanitize_text_field($name);
                    if ($name === '') continue;
                    $eid = isset($ids[$i]) ? (int) $ids[$i] : 0;
                    $data = [
                        'service_type'  => $post_type,
                        'service_id'    => $post_id,
                        'name'          => $name,
                        'description'   => isset($descs[$i]) ? sanitize_textarea_field($descs[$i]) : '',
                        'price'         => isset($prices[$i]) ? (float) $prices[$i] : 0,
                        'currency'      => isset($currencies[$i]) ? sanitize_text_field($currencies[$i]) : 'USD',
                        'is_per_person' => isset($per_person[$eid]) ? 1 : (isset($per_person['new']) ? 1 : 0),
                        'is_required'   => isset($required[$eid]) ? 1 : (isset($required['new']) ? 1 : 0),
                        'is_active'     => isset($active[$eid]) ? 1 : (isset($active['new']) ? 1 : 0),
                        'sort_order'    => $i,
                    ];
                    // For new rows, id=0; the per_person/required/active arrays use "new" key.
                    if ($eid > 0) {
                        TAP_Service_Extras::update($eid, $data);
                    } else {
                        TAP_Service_Extras::create($data);
                    }
                }
            }
        }

        if ($post_type === 'tap_room') {
            $room_text = ['_tap_room_accommodation_id', '_tap_room_price_per_night', '_tap_room_min_stay', '_tap_room_currency', '_tap_room_max_adults', '_tap_room_max_children', '_tap_room_max_occupancy', '_tap_room_inventory', '_tap_room_overbooking_pct', '_tap_room_size', '_tap_room_view', '_tap_room_floor', '_tap_room_gallery'];
            foreach ($room_text as $key) {
                if (isset($_POST[$key])) {
                    $val = sanitize_text_field(wp_unslash($_POST[$key]));
                    if (in_array($key, ['_tap_room_price_per_night', '_tap_room_max_adults', '_tap_room_max_children', '_tap_room_max_occupancy', '_tap_room_inventory', '_tap_room_overbooking_pct', '_tap_room_floor'], true)) {
                        $val = floatval($val);
                    }
                    update_post_meta($post_id, $key, $val);
                }
            }

            $is_active = isset($_POST['_tap_room_is_active']) ? '1' : '0';
            update_post_meta($post_id, '_tap_room_is_active', $is_active);

            if (isset($_POST['_tap_room_beds'])) {
                update_post_meta($post_id, '_tap_room_beds', sanitize_text_field(wp_unslash($_POST['_tap_room_beds'])));
            }
        }

        if ($post_type === TAP_Destination_Spots::CPT && isset($_POST['tap_spot_location'])) {
            // The ficha's place: stored as post meta and mirrored on the term so
            // a location page can hand over to its ficha without an extra query.
            TAP_Destination_Spots::set_location_term($post_id, absint($_POST['tap_spot_location']));
        }

        // Translations (multi-language content).
        if (in_array($post_type, self::service_types(), true) && isset($_POST['tap_translations_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tap_translations_nonce'])), 'tap_translations_save')) {
            if (class_exists('TAP_Translations')) {
                $title = sanitize_text_field(wp_unslash($_POST['tap_tr_title'] ?? ''));
                $desc  = sanitize_textarea_field(wp_unslash($_POST['tap_tr_description'] ?? ''));
                if ('' !== $title) {
                    TAP_Translations::set($post_type, $post_id, 'en', 'title', $title);
                }
                if ('' !== $desc) {
                    TAP_Translations::set($post_type, $post_id, 'en', 'description', $desc);
                }
            }
        }

        // Yield pricing rules management.
        if (in_array($post_type, self::service_types(), true) && isset($_POST['tap_pricing_rules_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tap_pricing_rules_nonce'])), 'tap_pricing_rules_save')) {
            if (isset($_POST['tap_pricing_rule_delete'])) {
                TAP_Pricing_Rules::delete(absint($_POST['tap_pricing_rule_delete']));
            } elseif (isset($_POST['tap_pricing_rule_add'])) {
                $rule_type = sanitize_key(wp_unslash($_POST['tap_rule_type'] ?? ''));
                if ($rule_type && isset(TAP_Pricing_Rules::rule_types()[$rule_type])) {
                    $condition = [];
                    $days = absint($_POST['tap_rule_days'] ?? 0);
                    if (in_array($rule_type, ['early_bird', 'last_minute'], true)) {
                        $condition['days_before'] = $days;
                    }
                    $months_raw = sanitize_text_field(wp_unslash($_POST['tap_rule_months'] ?? ''));
                    if ('seasonal' === $rule_type && '' !== $months_raw) {
                        $condition['months'] = array_map('intval', array_filter(array_map('trim', explode(',', $months_raw))));
                    }
                    TAP_Pricing_Rules::save([
                        'service_type'   => $post_type,
                        'service_id'     => $post_id,
                        'rule_type'      => $rule_type,
                        'condition'      => $condition,
                        'modifier_type'  => sanitize_key(wp_unslash($_POST['tap_rule_modifier_type'] ?? 'percent')),
                        'modifier_value' => (float) (wp_unslash($_POST['tap_rule_modifier_value'] ?? 0)),
                        'priority'       => absint($_POST['tap_rule_priority'] ?? 0),
                        'is_active'      => 1,
                    ]);
                }
            }
        }

        $service_like = in_array($post_type, self::service_types(), true);
        if ($service_like || $post_type === 'tap_room') {
            if (isset($_POST['tap_destination'])) {
                $dest = absint($_POST['tap_destination']);
                $valid = $dest && term_exists($dest, 'tap_location');
                wp_set_object_terms($post_id, $valid ? [$dest] : [], 'tap_location', false);
            }
        }

        if ($post_type === 'tap_tour' && isset($_POST['_tap_tour_difficulty'])) {
            $difficulty = sanitize_key(wp_unslash($_POST['_tap_tour_difficulty']));
            $term = $difficulty && term_exists($difficulty, 'tap_tour_difficulty') ? $difficulty : '';
            if ($term) {
                $tt = term_exists($term, 'tap_tour_difficulty');
                $term_id = (int) (is_array($tt) ? $tt['term_id'] : $tt);
                wp_set_object_terms($post_id, [$term_id], 'tap_tour_difficulty', false);
            } else {
                wp_set_object_terms($post_id, [], 'tap_tour_difficulty', false);
            }
        }

        if ($post_type === 'tap_tour' && isset($_POST['_tap_tour_type'])) {
            $ttype = sanitize_text_field(wp_unslash($_POST['_tap_tour_type']));
            if ($ttype && term_exists($ttype, 'tap_tour_type')) {
                $tt = term_exists($ttype, 'tap_tour_type');
                $term_id = (int) (is_array($tt) ? $tt['term_id'] : $tt);
                wp_set_object_terms($post_id, [$term_id], 'tap_tour_type', false);
            } else {
                wp_set_object_terms($post_id, [], 'tap_tour_type', false);
            }
        }
    }

    public static function render_accommodation_location($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $fields = [
            '_tap_acc_address'   => [__('Street Address', 'travel-agency-platform'), 'text'],
            '_tap_acc_city'      => [__('City', 'travel-agency-platform'), 'text'],
            '_tap_acc_state'     => [__('State / Province', 'travel-agency-platform'), 'text'],
            '_tap_acc_zip'       => [__('ZIP / Postal Code', 'travel-agency-platform'), 'text'],
            '_tap_acc_country'   => [__('Country', 'travel-agency-platform'), 'text'],
        ];
        echo '<table class="form-table">';
        foreach ($fields as $key => $data) {
            $val = get_post_meta($post->ID, $key, true);
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($data[0]) . '</label></th><td>';
            echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" class="regular-text" style="width:100%">';
            echo '</td></tr>';
        }
        echo '</table>';
    }

    public static function render_accommodation_policies($post) {
        $cancellation = get_post_meta($post->ID, '_tap_acc_cancellation', true);
        $house_rules = get_post_meta($post->ID, '_tap_acc_house_rules', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="_tap_acc_cancellation"><?php esc_html_e('Cancellation Policy', 'travel-agency-platform'); ?></label></th>
                <td>
                    <select id="_tap_acc_cancellation" name="_tap_acc_cancellation" style="width:100%">
                        <option value="flexible" <?php selected('flexible', $cancellation); ?>><?php esc_html_e('Flexible — Free cancellation 24h before', 'travel-agency-platform'); ?></option>
                        <option value="moderate" <?php selected('moderate', $cancellation); ?>><?php esc_html_e('Moderate — Free cancellation 5 days before', 'travel-agency-platform'); ?></option>
                        <option value="strict" <?php selected('strict', $cancellation); ?>><?php esc_html_e('Strict — 50% refund up to 7 days before', 'travel-agency-platform'); ?></option>
                        <option value="non_refundable" <?php selected('non_refundable', $cancellation); ?>><?php esc_html_e('Non-Refundable', 'travel-agency-platform'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="_tap_acc_house_rules"><?php esc_html_e('House Rules', 'travel-agency-platform'); ?></label></th>
                <td><textarea id="_tap_acc_house_rules" name="_tap_acc_house_rules" rows="4" style="width:100%"><?php echo esc_textarea($house_rules); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    public static function render_accommodation_gallery($post) {
        $gallery = get_post_meta($post->ID, '_tap_acc_gallery', true);
        $ids = $gallery ? explode(',', $gallery) : [];
        ?>
        <div>
            <div id="tap-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <?php foreach ($ids as $id): $img = wp_get_attachment_image_src($id, 'thumbnail'); if ($img): ?>
                    <div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;">
                        <img src="<?php echo esc_url($img[0]); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <button type="button" class="tap-gallery-remove" data-id="<?php echo esc_attr($id); ?>" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button>
                    </div>
						<?php
                endif;
endforeach;
				?>
            </div>
            <input type="hidden" name="_tap_acc_gallery" id="_tap_acc_gallery" value="<?php echo esc_attr($gallery); ?>">
            <button type="button" class="button" id="tap-gallery-add"><?php esc_html_e('Add Images', 'travel-agency-platform'); ?></button>
            <p class="description"><?php esc_html_e('Upload or select images for the property gallery.', 'travel-agency-platform'); ?></p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            $('#tap-gallery-add').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '<?php echo esc_js(__('Select Images', 'travel-agency-platform')); ?>',
                    button: { text: '<?php echo esc_js(__('Add to Gallery', 'travel-agency-platform')); ?>' },
                    multiple: true
                });
                frame.on('select', function() {
                    var ids = $('#_tap_acc_gallery').val() ? $('#_tap_acc_gallery').val().split(',') : [];
                    var attachments = frame.state().get('selection').toJSON();
                    attachments.forEach(function(att) {
                        if (ids.indexOf(String(att.id)) === -1) ids.push(String(att.id));
                        var preview = $('#tap-gallery-preview');
                        if (!preview.find('[data-id="' + att.id + '"]').length) {
                            preview.append('<div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;"><img src="' + (att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;"><button type="button" class="tap-gallery-remove" data-id="' + att.id + '" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button></div>');
                        }
                    });
                    $('#_tap_acc_gallery').val(ids.join(','));
                });
                frame.open();
            });
            $(document).on('click', '.tap-gallery-remove', function() {
                var id = $(this).data('id');
                $(this).parent().remove();
                var ids = $('#_tap_acc_gallery').val().split(',').filter(function(v) { return v !== String(id); });
                $('#_tap_acc_gallery').val(ids.join(','));
            });
        });
        </script>
        <?php
    }

    public static function render_room($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $fields = [
            '_tap_room_accommodation_id' => ['type' => 'select_post', 'label' => __('Parent Accommodation', 'travel-agency-platform'), 'post_type' => 'tap_accommodation'],
            '_tap_room_price_per_night'  => ['type' => 'number', 'label' => __('Precio base por noche ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_room_min_stay'         => ['type' => 'number', 'label' => __('Estadía mínima base (noches)', 'travel-agency-platform'), 'default' => '1'],
            '_tap_room_currency'         => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_room_max_adults'       => ['type' => 'number', 'label' => __('Max Adults', 'travel-agency-platform')],
            '_tap_room_max_children'     => ['type' => 'number', 'label' => __('Max Children', 'travel-agency-platform')],
            '_tap_room_max_occupancy'    => ['type' => 'number', 'label' => __('Max Total Occupancy', 'travel-agency-platform')],
            '_tap_room_inventory'        => ['type' => 'number', 'label' => __('Number of Rooms of This Type', 'travel-agency-platform'), 'default' => '1'],
            '_tap_room_overbooking_pct'  => ['type' => 'number', 'label' => __('Overbooking % (0 = none)', 'travel-agency-platform'), 'default' => '0'],
            '_tap_room_size'             => ['type' => 'text', 'label' => __('Room Size (e.g. 35 m²)', 'travel-agency-platform')],
            '_tap_room_view'             => ['type' => 'text', 'label' => __('View (e.g. Ocean View, City View)', 'travel-agency-platform')],
            '_tap_room_floor'            => ['type' => 'number', 'label' => __('Floor', 'travel-agency-platform')],
            '_tap_room_is_active'        => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
        ];
        echo '<table class="form-table">';
        foreach ($fields as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value === '' && isset($field['default'])) $value = $field['default'];
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';
            self::render_field($key, $field, $value);
            echo '</td></tr>';
        }
        echo '</table>';
    }

    public static function render_room_beds($post) {
        $beds = get_post_meta($post->ID, '_tap_room_beds', true);
        $beds = $beds ? (is_string($beds) ? json_decode($beds, true) : $beds) : [];
        ?>
        <div id="tap-beds-container">
            <p><strong><?php esc_html_e('Add bed types available in this room:', 'travel-agency-platform'); ?></strong></p>
            <table class="widefat" style="margin-bottom:12px;">
                <thead><tr><th><?php esc_html_e('Bed Type', 'travel-agency-platform'); ?></th><th style="width:80px;"><?php esc_html_e('Count', 'travel-agency-platform'); ?></th><th style="width:40px;"></th></tr></thead>
                <tbody id="tap-beds-tbody">
                    <?php if (empty($beds)): ?>
                    <tr class="tap-bed-row">
                        <td><select name="tap_bed_type[]" style="width:100%;"><?php self::bed_type_options(''); ?></select></td>
                        <td><input type="number" name="tap_bed_count[]" value="1" min="1" style="width:60px;"></td>
                        <td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($beds as $bed): ?>
                        <tr class="tap-bed-row">
                            <td><select name="tap_bed_type[]" style="width:100%;"><?php self::bed_type_options($bed['type'] ?? ''); ?></select></td>
                            <td><input type="number" name="tap_bed_count[]" value="<?php echo esc_attr($bed['count'] ?? 1); ?>" min="1" style="width:60px;"></td>
                            <td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" class="button" id="tap-bed-add"><?php esc_html_e('+ Add Bed Type', 'travel-agency-platform'); ?></button>
            <input type="hidden" name="_tap_room_beds" id="_tap_room_beds" value='<?php echo esc_attr(wp_json_encode($beds)); ?>'>
        </div>
        <script>
        jQuery(document).ready(function($) {
            function rebuildBedsJson() {
                var data = [];
                $('#tap-beds-tbody tr').each(function() {
                    var type = $(this).find('select').val();
                    var count = $(this).find('input[type="number"]').val();
                    if (type) data.push({type: type, count: parseInt(count) || 1});
                });
                $('#_tap_room_beds').val(JSON.stringify(data));
            }
            $('#tap-bed-add').on('click', function() {
                var row = '<tr class="tap-bed-row"><td><select name="tap_bed_type[]" style="width:100%;"><?php echo str_replace("'", "\\'", str_replace("\n", '', self::bed_type_options_html())); ?></select></td><td><input type="number" name="tap_bed_count[]" value="1" min="1" style="width:60px;"></td><td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td></tr>';
                $('#tap-beds-tbody').append(row);
            });
            $(document).on('click', '.tap-bed-remove', function() { $(this).closest('tr').remove(); rebuildBedsJson(); });
            $(document).on('change', '#tap-beds-tbody select, #tap-beds-tbody input', rebuildBedsJson);
        });
        </script>
        <?php
    }

    private static function bed_type_options($selected) {
        $types = [
            '' => __('-- Select --', 'travel-agency-platform'),
            'king' => __('King', 'travel-agency-platform'),
            'queen' => __('Queen', 'travel-agency-platform'),
            'double' => __('Double / Full', 'travel-agency-platform'),
            'twin' => __('Twin / Single', 'travel-agency-platform'),
            'bunk' => __('Bunk Bed', 'travel-agency-platform'),
            'sofa' => __('Sofa Bed', 'travel-agency-platform'),
            'crib' => __('Crib', 'travel-agency-platform'),
            'murphy' => __('Murphy Bed', 'travel-agency-platform'),
            'futon' => __('Futon', 'travel-agency-platform'),
        ];
        foreach ($types as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($val, $selected, false) . '>' . esc_html($label) . '</option>';
        }
    }

    private static function bed_type_options_html() {
        ob_start();
        self::bed_type_options('');
        return ob_get_clean();
    }

    public static function render_room_gallery($post) {
        $gallery = get_post_meta($post->ID, '_tap_room_gallery', true);
        $ids = $gallery ? explode(',', $gallery) : [];
        ?>
        <div>
            <div id="tap-room-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <?php foreach ($ids as $id): $img = wp_get_attachment_image_src($id, 'thumbnail'); if ($img): ?>
                    <div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;">
                        <img src="<?php echo esc_url($img[0]); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <button type="button" class="tap-room-gallery-remove" data-id="<?php echo esc_attr($id); ?>" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button>
                    </div>
						<?php
                endif;
endforeach;
				?>
            </div>
            <input type="hidden" name="_tap_room_gallery" id="_tap_room_gallery" value="<?php echo esc_attr($gallery); ?>">
            <button type="button" class="button" id="tap-room-gallery-add"><?php esc_html_e('Add Photos', 'travel-agency-platform'); ?></button>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            $('#tap-room-gallery-add').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: '<?php echo esc_js(__('Select Photos', 'travel-agency-platform')); ?>', button: { text: '<?php echo esc_js(__('Add to Gallery', 'travel-agency-platform')); ?>' }, multiple: true });
                frame.on('select', function() {
                    var ids = $('#_tap_room_gallery').val() ? $('#_tap_room_gallery').val().split(',') : [];
                    var attachments = frame.state().get('selection').toJSON();
                    attachments.forEach(function(att) {
                        if (ids.indexOf(String(att.id)) === -1) ids.push(String(att.id));
                        var preview = $('#tap-room-gallery-preview');
                        if (!preview.find('[data-id="' + att.id + '"]').length) {
                            preview.append('<div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;"><img src="' + (att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;"><button type="button" class="tap-room-gallery-remove" data-id="' + att.id + '" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button></div>');
                        }
                    });
                    $('#_tap_room_gallery').val(ids.join(','));
                });
                frame.open();
            });
            $(document).on('click', '.tap-room-gallery-remove', function() {
                var id = $(this).data('id');
                $(this).parent().remove();
                var ids = $('#_tap_room_gallery').val().split(',').filter(function(v) { return v !== String(id); });
                $('#_tap_room_gallery').val(ids.join(','));
            });
        });
        </script>
        <?php
    }

    public static function render_booking($post) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $post->ID
        ));

        if (!$booking) {
                echo '<p>' . esc_html__('Booking details not found.', 'travel-agency-platform') . '</p>';
            return;
        }

        $service = get_post($booking->service_id);
        $agency = get_post($booking->agency_id);
        $client = get_userdata($booking->client_id);

        ?>
        <style>
            .tap-booking-details { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            .tap-booking-details .field { margin-bottom: 10px; }
            .tap-booking-details .label { font-weight: bold; display: block; color: #555; font-size: 12px; text-transform: uppercase; }
            .tap-booking-details .value { font-size: 14px; margin-top: 2px; }
            .tap-status { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
            .tap-status-pending { background: #fff3cd; color: #856404; }
            .tap-status-confirmed { background: #d4edda; color: #155724; }
            .tap-status-cancelled { background: #f8d7da; color: #721c24; }
            .tap-status-completed { background: #cce5ff; color: #004085; }
        </style>
        <div class="tap-booking-details">
            <div class="field"><span class="label"><?php esc_html_e('Booking Code', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->booking_code); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Status', 'travel-agency-platform'); ?></span><span class="value"><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Client', 'travel-agency-platform'); ?></span><span class="value"><?php echo $client ? esc_html($client->display_name) . ' (' . esc_html($client->user_email) . ')' : 'N/A'; ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Agency', 'travel-agency-platform'); ?></span><span class="value"><?php echo $agency ? esc_html($agency->post_title) : 'N/A'; ?></span></div>
            <?php if ($service): ?>
            <div class="field"><span class="label"><?php esc_html_e('Service', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($service->post_title) . ' (' . esc_html(str_replace('tap_', '', $booking->service_type)) . ')'; ?></span></div>
            <?php endif; ?>
            <?php if ($booking->check_in): ?>
            <div class="field"><span class="label"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->check_in); ?></span></div>
            <?php endif; ?>
            <?php if ($booking->check_out): ?>
            <div class="field"><span class="label"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->check_out); ?></span></div>
            <?php endif; ?>
            <div class="field"><span class="label"><?php esc_html_e('Adults', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->adults); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Children', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->children); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Total Amount', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Commission', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(TAP_Currency::fmt($booking->commission_amount)); ?> (<?php echo esc_html($booking->commission_percent); ?>%)</span></div>
            <div class="field"><span class="label"><?php esc_html_e('Payment Status', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(ucfirst($booking->payment_status)); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Payment Method', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->payment_method ?: 'N/A'); ?></span></div>
            <?php if ($booking->notes): ?>
            <div class="field" style="grid-column: 1 / -1;"><span class="label"><?php esc_html_e('Notes', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->notes); ?></span></div>
            <?php endif; ?>
            <div class="field" style="grid-column: 1 / -1;"><span class="label"><?php esc_html_e('Created', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->created_at); ?></span></div>
        </div>
        <?php
    }

    public static function get_meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value !== '' ? $value : $default;
    }
}
