<?php
defined('ABSPATH') || exit;

/**
 * Step renderers for the tour wizard. Each method returns HTML for one step.
 */
class TAP_Tour_Wizard_Steps {

    public static function tour_wizard_progress($listing_id, $type) {
        $listing_id = (int) $listing_id;
        $items = [];
        $completed = 0;
        $total_steps = 4; // steps 1-4 (step 5 is publish)

        // Step 1: Info
        $title = $listing_id ? get_the_title($listing_id) : '';
        $done1 = !empty($title) && $title !== 'Auto Draft';
        $items[1] = ['step' => 1, 'label' => __('Información', 'travel-agency-platform'), 'done' => $done1, 'detail' => $done1 ? __('Completo', 'travel-agency-platform') : ''];
        if ($done1) $completed++;

        // Step 2: Logistics
        $dur = $listing_id ? get_post_meta($listing_id, '_tap_tour_duration', true) : '';
        $done2 = !empty($dur);
        $items[2] = ['step' => 2, 'label' => __('Logística', 'travel-agency-platform'), 'done' => $done2, 'detail' => $done2 ? __('Completo', 'travel-agency-platform') : ''];
        if ($done2) $completed++;

        // Step 3: Pricing
        $price = $listing_id ? get_post_meta($listing_id, '_tap_tour_price_adult', true) : '';
        $done3 = !empty($price) && (float) $price > 0;
        $items[3] = ['step' => 3, 'label' => __('Tarifas', 'travel-agency-platform'), 'done' => $done3, 'detail' => $done3 ? __('Completo', 'travel-agency-platform') : ''];
        if ($done3) $completed++;

        // Step 4: Details
        $includes = $listing_id ? get_post_meta($listing_id, '_tap_tour_includes', true) : '';
        $done4 = !empty($includes);
        $items[4] = ['step' => 4, 'label' => __('Detalles y legales', 'travel-agency-platform'), 'done' => $done4, 'detail' => $done4 ? __('Completo', 'travel-agency-platform') : ''];
        if ($done4) $completed++;

        $percent = $total_steps > 0 ? (int) round(($completed / $total_steps) * 100) : 0;

        return [
            'completed' => $completed,
            'percent'   => $percent,
            'items'     => $items,
            'steps'     => $items,
        ];
    }

    public static function wizard_currency($listing_id) {
        $code = get_post_meta($listing_id, '_tap_tour_currency', true) ?: 'USD';
        if (class_exists('TAP_Currency')) {
            return [TAP_Currency::code(), TAP_Currency::symbol($code), TAP_Currency::decimals($code)];
        }
        return [$code, '$', 2];
    }

    public static function render_step_info($listing_id, $type) {
        $listing = $listing_id ? get_post($listing_id) : null;
        $prefix = 'tour';
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        ob_start();
        ?>
        <div class="tap-wizard-step-content">
            <div class="tap-form-grid">
                <div class="tap-field tap-span-2">
                    <label><?php esc_html_e('Nombre *', 'travel-agency-platform'); ?></label>
                    <input type="text" name="title" required value="<?php echo esc_attr($listing ? $listing->post_title : ''); ?>">
                </div>
                <div class="tap-field tap-span-2">
                    <label><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                    <textarea name="description" rows="4"><?php echo esc_textarea($listing ? $listing->post_content : ''); ?></textarea>
                </div>
                <div class="tap-field tap-span-2">
                    <label><?php esc_html_e('Fotos', 'travel-agency-platform'); ?></label>
                    <?php
                    $main_id = $listing_id ? (int) get_post_thumbnail_id($listing_id) : 0;
                    $gallery_ids = $listing_id ? array_filter(array_map('trim', explode(',', (string) get_post_meta($listing_id, '_tap_tour_gallery', true)))) : [];
                    echo TAP_Acc_Wizard_Room_Form::render_image_uploader($listing_id, $main_id, $gallery_ids, [
                        'main_id' => 'featured_attachment_id',
                        'gallery_ids' => 'gallery_attachment_ids',
                        'main_url' => 'featured_image_url',
                        'gallery_urls' => 'gallery_urls',
                        'listing_id' => $listing_id,
                        'listing_type' => 'tap_tour',
                    ]);
                    ?>
                </div>
                <?php
                // Basic fields
                $basic = ['_tap_tour_agency_id', '_tap_tour_category', '_tap_tour_type', '_tap_tour_tags', '_tap_tour_duration', '_tap_tour_difficulty', '_tap_tour_fitness', '_tap_tour_min_age', '_tap_tour_max_age', '_tap_tour_is_active'];
                foreach ($basic as $mk) {
                    if (isset($fields[$mk])) {
                        self::render_field($mk, $fields[$mk], (bool) $listing_id, $listing_id);
                    }
                }
                ?>
            </div>
        </div>
        <script>
        // Save step 1
        (function(){
            var form = document.getElementById('tap-listing-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // handled by agency-wizard.js
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function render_step_logistics($listing_id, $type) {
        $listing_id = (int) $listing_id;
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        ob_start();
        ?>
        <div class="tap-wizard-step-content">
            <div class="tap-form-grid">
                <?php
                $logistics = ['_tap_tour_start_time', '_tap_tour_end_time', '_tap_tour_meeting_point', '_tap_tour_lat', '_tap_tour_lng', '_tap_tour_pickup', '_tap_tour_pickup_locations'];
                foreach ($logistics as $mk) {
                    if (isset($fields[$mk])) {
                        self::render_field($mk, $fields[$mk], (bool) $listing_id, $listing_id);
                    }
                }
                ?>
                <div class="tap-field tap-span-2">
                    <label><?php esc_html_e('Ubicar en el mapa (arrastra el marcador)', 'travel-agency-platform'); ?></label>
                    <div id="tap-listing-map" style="height:260px;border-radius:var(--tap-radius);overflow:hidden;border:1px solid var(--tap-border);"></div>
                    <input type="hidden" id="tap-map-lat-init" value="<?php echo esc_attr(get_post_meta($listing_id, '_tap_tour_lat', true) ?: '9.93'); ?>">
                    <input type="hidden" id="tap-map-lng-init" value="<?php echo esc_attr(get_post_meta($listing_id, '_tap_tour_lng', true) ?: '-84.08'); ?>">
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_step_pricing($listing_id, $type) {
        $listing_id = (int) $listing_id;
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        ob_start();
        ?>
        <div class="tap-wizard-step-content">
            <div class="tap-form-grid">
                <?php
                $pricing = ['_tap_tour_price_adult', '_tap_tour_price_child', '_tap_tour_price_senior', '_tap_tour_price_student', '_tap_tour_currency', '_tap_tour_child_age', '_tap_tour_capacity', '_tap_tour_min_people', '_tap_tour_max_people', '_tap_tour_max_per_booking', '_tap_tour_private', '_tap_tour_private_price'];
                foreach ($pricing as $mk) {
                    if (isset($fields[$mk])) {
                        self::render_field($mk, $fields[$mk], (bool) $listing_id, $listing_id);
                    }
                }
                ?>
            </div>
            <?php if ($listing_id): ?>
            <div style="margin-top:20px;">
                <h3><?php esc_html_e('Disponibilidad por fecha', 'travel-agency-platform'); ?></h3>
                <p class="description"><?php esc_html_e('Gestiona los slots de disponibilidad para este tour.', 'travel-agency-platform'); ?></p>
                <?php
                $slots = TAP_Tour_Availability::slots_for('tap_tour', $listing_id);
                if ($slots) {
                    echo '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Hora</th><th>Capacidad</th><th>Reservados</th><th>Precio</th><th>Estado</th></tr></thead><tbody>';
                    foreach ($slots as $sl) {
                        echo '<tr>';
                        echo '<td>' . esc_html($sl->date) . '</td>';
                        echo '<td>' . esc_html($sl->time_slot ?: 'Todo el día') . '</td>';
                        echo '<td>' . (int) $sl->capacity . '</td>';
                        echo '<td>' . (int) $sl->booked . '</td>';
                        echo '<td>' . ($sl->price !== null ? TAP_Currency::fmt((float) $sl->price) : '—') . '</td>';
                        echo '<td>' . ($sl->is_blocked ? '🚫 Bloqueado' : '✅ Activo') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>' . esc_html__('No hay slots de disponibilidad. Se crearán automáticamente al guardar.', 'travel-agency-platform') . '</p>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_step_details($listing_id, $type) {
        $listing_id = (int) $listing_id;
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        ob_start();
        ?>
        <div class="tap-wizard-step-content">
            <div class="tap-form-grid">
                <?php
                $detail_fields = ['_tap_tour_includes', '_tap_tour_excludes', '_tap_tour_what_bring', '_tap_tour_equipment', '_tap_tour_equipment_rental', '_tap_tour_leave_no_trace', '_tap_tour_carbon_offset', '_tap_tour_local_community', '_tap_tour_wildlife', '_tap_tour_trail', '_tap_tour_elevation', '_tap_tour_terms', '_tap_tour_privacy', '_tap_tour_waiver', '_tap_tour_waiver_required', '_tap_tour_waiver_min_age'];
                foreach ($detail_fields as $mk) {
                    if (isset($fields[$mk])) {
                        self::render_field($mk, $fields[$mk], (bool) $listing_id, $listing_id);
                    }
                }
                ?>
            </div>
            <?php if ($listing_id): ?>
            <div style="margin-top:20px;">
                <h3><?php esc_html_e('Servicios extra', 'travel-agency-platform'); ?></h3>
                <?php echo TAP_Service_Extras::render_selector('tap_tour', $listing_id); ?>
                <p class="description"><?php esc_html_e('Gestiona los extras desde el panel de administración.', 'travel-agency-platform'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_step_publish($listing_id, $type) {
        $listing_id = (int) $listing_id;
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        ob_start();
        ?>
        <div class="tap-wizard-step-content">
            <div class="tap-form-grid">
                <?php
                $seo_fields = ['_tap_seo_title', '_tap_seo_description'];
                foreach ($seo_fields as $mk) {
                    if (isset($fields[$mk])) {
                        self::render_field($mk, $fields[$mk], (bool) $listing_id, $listing_id);
                    }
                }
                ?>
                <div class="tap-field tap-span-2">
                    <label><?php esc_html_e('Destino', 'travel-agency-platform'); ?></label>
                    <?php
                    $dest_sel = 0;
                    if ($listing_id) {
                        $dterms = get_the_terms($listing_id, 'tap_location');
                        $highest_level = -1;
                        if (is_array($dterms)) {
                            foreach ($dterms as $dt) {
                                $dl = TAP_Destinations::term_level($dt->term_id);
                                $dl = $dl !== null ? $dl : TAP_Destinations::term_depth($dt->term_id);
                                if ($dl > $highest_level) {
                                    $highest_level = $dl;
                                    $dest_sel = (int) $dt->term_id;
                                }
                            }
                        }
                    }
                    echo TAP_Destinations::render_picker($dest_sel, 'destination');
                    ?>
                </div>
                <div class="tap-field">
                    <label><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></label>
                    <select name="booking_mode">
                        <option value="instant" <?php selected(TAP_Booking::booking_mode('tap_tour', $listing_id), 'instant'); ?>><?php esc_html_e('Reserva directa (pago inmediato)', 'travel-agency-platform'); ?></option>
                        <option value="request" <?php selected(TAP_Booking::booking_mode('tap_tour', $listing_id), 'request'); ?>><?php esc_html_e('Solicitud de reserva (la agencia confirma antes del pago)', 'travel-agency-platform'); ?></option>
                    </select>
                </div>
                <?php if ($listing_id): ?>
                <div class="tap-field tap-span-2">
                    <?php $is_published = 'publish' === get_post_status($listing_id); ?>
                    <button type="button" class="tap-btn <?php echo $is_published ? 'tap-btn-danger' : 'tap-btn-primary'; ?>" data-publish="<?php echo $is_published ? '0' : '1'; ?>">
                        <?php echo $is_published ? esc_html__('Despublicar', 'travel-agency-platform') : esc_html__('Publicar', 'travel-agency-platform'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_field($meta_key, $cfg, $is_edit, $listing_id) {
        $ftype  = isset($cfg['type']) ? $cfg['type'] : 'text';
        $label  = isset($cfg['label']) ? $cfg['label'] : $meta_key;
        $value  = $is_edit ? get_post_meta($listing_id, $meta_key, true) : (isset($cfg['default']) ? $cfg['default'] : '');
        echo '<div class="tap-field">';
        switch ($ftype) {
            case 'checkbox':
                echo '<label class="tap-check-label"><input type="checkbox" name="' . esc_attr($meta_key) . '" value="1" ' . checked('1', (string) $value, false) . '> ' . esc_html($label) . '</label>';
                break;
            case 'select':
                echo '<label>' . esc_html($label) . '</label><select name="' . esc_attr($meta_key) . '"><option value="">—</option>';
                foreach ($cfg['options'] as $k => $v) {
                    $sel = (string) $value !== '' && (string) $k === (string) $value ? ' selected' : '';
                    echo '<option value="' . esc_attr($k) . '"' . $sel . '>' . esc_html($v) . '</option>';
                }
                echo '</select>';
                break;
            case 'number':
                $step = isset($cfg['step']) ? ' step="' . esc_attr($cfg['step']) . '"' : '';
                echo '<label>' . esc_html($label) . '</label><input type="number" name="' . esc_attr($meta_key) . '"' . $step . ' value="' . esc_attr($value) . '" min="0">';
                break;
            case 'time':
                echo '<label>' . esc_html($label) . '</label><input type="time" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '">';
                break;
            case 'textarea':
                echo '<label>' . esc_html($label) . '</label><textarea name="' . esc_attr($meta_key) . '" rows="3">' . esc_textarea($value) . '</textarea>';
                break;
            default:
                echo '<label>' . esc_html($label) . '</label><input type="text" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '">';
        }
        echo '</div>';
    }
}
