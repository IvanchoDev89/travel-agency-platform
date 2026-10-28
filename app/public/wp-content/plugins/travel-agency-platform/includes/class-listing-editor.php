<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
/**
 * TAP_Listing_Editor — front-office listing manager and the 5-step
 * accommodation wizard behind [tap_agency_manage].
 *
 * Extracted from TAP_Shortcodes (this block alone was ~2000 lines of the
 * god class): manage index/editor, wizard progress + steps (info, rooms,
 * calendar, policies, publish), room form, image uploader and script.
 */
defined('ABSPATH') || exit;

class TAP_Listing_Editor {
    public static function agency_manage($atts) {
        if (!is_user_logged_in()) {
            return '<p class="tap-empty"><a href="' . esc_url(wp_login_url(home_url('/mi-cuenta/'))) . '">' . esc_html__('Log in to manage your listings', 'travel-agency-platform') . '</a></p>';
        }

        $user    = wp_get_current_user();
        $is_admin = user_can($user, 'manage_options');
        $agency  = $is_admin ? 0 : TAP_Booking::get_agency_for_user($user->ID);
        if (!$is_admin && !$agency) {
            return '<p class="tap-empty">' . esc_html__('Only agency accounts can manage listings.', 'travel-agency-platform') . '</p>';
        }

        $listing_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $new_type   = isset($_GET['new']) ? sanitize_key(wp_unslash($_GET['new'])) : '';

        if (!$is_admin && $agency && TAP_Approval::is_pending($agency)) {
            echo '<div class="tap-notice"><span class="tap-notice-icon" aria-hidden="true">⚠</span><span><strong>' . esc_html__('Tu agencia está en revisión', 'travel-agency-platform') . '.</strong> ' . esc_html__('Puedes preparar tus listados, pero serán visibles para los viajeros solo cuando el administrador apruebe tu agencia.', 'travel-agency-platform') . '</span></div>';
        }

        if ($listing_id) {
            $listing = get_post($listing_id);
            $types   = TAP_Post_Types::get_service_types();
            unset($types['tap_room']);
            $types   = array_keys($types);
            if (!$listing || !in_array($listing->post_type, $types, true)) {
                return '<p class="tap-empty">' . esc_html__('Listing not found.', 'travel-agency-platform') . '</p>';
            }
            if (!$is_admin && !self::agency_owns_listing_meta($listing_id, $listing->post_type, $agency)) {
                return '<p class="tap-empty">' . esc_html__('You can only manage your own listings.', 'travel-agency-platform') . '</p>';
            }
            return self::render_manage_editor($listing_id, $listing->post_type);
        }

        return self::render_manage_index($new_type, $agency, $is_admin);
    }

    private static function agency_owns_listing_meta($listing_id, $type, $agency) {
        $prefix = TAP_Post_Types::meta_prefix($type);
        return (int) get_post_meta($listing_id, '_tap_' . $prefix . '_agency_id', true) === (int) $agency;
    }

    private static function manage_agency_id($agency, $is_admin) {
        if ($is_admin) {
            $posted = isset($_GET['agency_id']) ? intval($_GET['agency_id']) : 0;
            return $posted ? $posted : 0;
        }
        return (int) $agency;
    }

    private static function render_manage_index($new_type, $agency, $is_admin) {
        $agency_id = self::manage_agency_id($agency, $is_admin);
        $types     = TAP_Post_Types::get_service_types();
        unset($types['tap_room']);
        ob_start();
        ?>
        <?php if (!($new_type && isset($types[$new_type]))): ?>
        <div class="tap-agency-panel tap-manage-wrap">
            <div class="tap-panel-head">
                <div>
                    <h2 class="tap-panel-title"><?php esc_html_e('Gestiona tus listados', 'travel-agency-platform'); ?></h2>
                    <p class="tap-panel-sub"><?php esc_html_e('Crea y edita tus servicios desde el panel.', 'travel-agency-platform'); ?></p>
                </div>
                <div class="tap-manage-new">
                    <span class="tap-manage-new-label"><?php esc_html_e('Nuevo:', 'travel-agency-platform'); ?></span>
                    <select class="tap-input tap-manage-type-select" onchange="if(this.value) window.location.href='<?php echo esc_url(home_url('/manage-listing/')); ?>?new='+this.value;">
                        <option value=""><?php esc_html_e('Seleccionar tipo…', 'travel-agency-platform'); ?></option>
                        <?php foreach ($types as $pt => $label): ?>
                            <option value="<?php echo esc_attr($pt); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

            <?php if ($new_type && isset($types[$new_type])): ?>
                <?php echo self::render_manage_editor(0, $new_type); ?>
            <?php else: ?>
            <div class="tap-grid tap-listings-grid">
                <?php
                $status_labels = [
                    'publish' => __('Publicado', 'travel-agency-platform'),
                    'pending' => __('En revisión', 'travel-agency-platform'),
                    'draft'   => __('Borrador', 'travel-agency-platform'),
                ];
                $empty = true;
                foreach ($types as $pt => $label) {
                    $prefix = TAP_Post_Types::meta_prefix($pt);
                    $args = [
                        'post_type'      => $pt,
                        /* Drafts and pending listings must stay visible: with the
                            explicit publish action they are a normal state. */
                        'post_status'    => ['publish', 'pending', 'draft'],
                        'posts_per_page' => 50,
                        'orderby'        => 'modified',
                        'order'          => 'DESC',
                    ];
                    if ($agency_id) {
                        $args['meta_key']   = '_tap_' . $prefix . '_agency_id';
                        $args['meta_value'] = $agency_id;
                    }
                    $listings = get_posts($args);
                    if (!$listings) {
                        continue;
                    }
                    $empty = false;
                    foreach ($listings as $l) {
                        $active   = '1' === get_post_meta($l->ID, '_tap_' . $prefix . '_is_active', true);
                        $city     = get_post_meta($l->ID, '_tap_' . $prefix . '_city', true)
                                    ?: get_post_meta($l->ID, '_tap_' . $prefix . '_location', true)
                                    ?: get_post_meta($l->ID, '_tap_' . $prefix . '_departure', true);
                        $edit_url = home_url('/manage-listing/?id=' . $l->ID);
                        $status   = $l->post_status;
                        ?>
                        <div class="tap-listing-card<?php echo 'publish' === $status ? '' : ' is-pending'; ?>">
                            <div class="tap-listing-card-head">
                                <strong><?php echo esc_html($l->post_title); ?></strong>
                                <span class="tap-badge"><?php echo esc_html($label); ?></span>
                                <?php if (isset($status_labels[$status])): ?>
                                    <span class="tap-badge <?php echo 'publish' === $status ? 'tap-badge-ok' : 'tap-badge-off'; ?>">
                                        <?php echo esc_html($status_labels[$status]); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="tap-badge <?php echo $active ? 'tap-badge-ok' : 'tap-badge-off'; ?>"><?php echo $active ? esc_html__('Activo', 'travel-agency-platform') : esc_html__('Inactivo', 'travel-agency-platform'); ?></span>
                            </div>
                            <div class="tap-listing-card-meta">
                                <span><?php echo esc_html($city ?: '—'); ?></span>
                            </div>
                            <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                        </div>
                        <?php
                    }
                }
                if ($empty) {
                    echo '<p class="tap-empty">' . esc_html__('Todavía no tienes listados. Usa "Nuevo" para crear tu primer servicio.', 'travel-agency-platform') . '</p>';
                }
                ?>
            </div>
            <?php endif; ?>
        <?php if (!($new_type && isset($types[$new_type]))): ?>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    private static function render_manage_editor($listing_id, $type) {
        $is_edit   = (bool) $listing_id;
        $listing   = $is_edit ? get_post($listing_id) : null;
        $prefix    = TAP_Post_Types::meta_prefix($type);
        $type_label = TAP_Post_Types::get_service_types()[$type] ?? ucfirst($type);
        $fields    = TAP_Metaboxes::get_fields($type);
        /* translators: %s: etiqueta del tipo de servicio */
        $action    = $is_edit ? __('Edit listing', 'travel-agency-platform') : sprintf(__('New %s', 'travel-agency-platform'), $type_label);

        if ('tap_accommodation' === $type) {
            return TAP_Acc_Wizard::render_acc_wizard($listing_id, $type);
        }

        ob_start();
        ?>
        <div class="tap-agency-panel tap-manage-wrap">
            <div class="tap-panel-head">
                <div>
                    <h2 class="tap-panel-title"><?php echo esc_html($action); ?></h2>
                </div>
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">&larr; <?php esc_html_e('Volver', 'travel-agency-platform'); ?></a>
            </div>

            <form class="tap-manage-form" id="tap-listing-form">
                <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                <input type="hidden" name="listing_id" value="<?php echo (int) $listing_id; ?>">
                <input type="hidden" name="listing_type" value="<?php echo esc_attr($type); ?>">
                <div class="tap-form-grid">
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Nombre *', 'travel-agency-platform'); ?></label>
                        <input type="text" name="title" required value="<?php echo esc_attr($listing ? $listing->post_title : ''); ?>">
                        <span class="tap-field-error" style="color:var(--tap-error);font-size:var(--tap-text-xs);min-height:1em;"></span>
                    </div>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                        <textarea name="description" rows="4"><?php echo esc_textarea($listing ? $listing->post_content : ''); ?></textarea>
                    </div>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Fotos', 'travel-agency-platform'); ?></label>
                        <?php
                        $main_id     = $is_edit ? (int) get_post_thumbnail_id($listing_id) : 0;
                        $gallery_ids = $is_edit
                            ? array_filter(array_map('trim', explode(',', (string) get_post_meta($listing_id, '_tap_' . $prefix . '_gallery', true))))
                            : [];
                        echo TAP_Acc_Wizard_Room_Form::render_image_uploader($listing_id, $main_id, $gallery_ids, [
                            'main_id'      => 'featured_attachment_id',
                            'gallery_ids'  => 'gallery_attachment_ids',
                            'main_url'     => 'featured_image_url',
                            'gallery_urls' => 'gallery_urls',
                            'listing_id'   => $listing_id,
                            'listing_type' => $type,
                        ]);
                        ?>
                    </div>
                    <?php
                    // Group fields into sections for a clearer, shorter form.
                    $groups = [
                        'price'    => ['title' => __('Precios', 'travel-agency-platform'), 'fields' => []],
                        'location' => ['title' => __('Ubicación', 'travel-agency-platform'), 'fields' => []],
                        'details'  => ['title' => __('Detalles', 'travel-agency-platform'), 'fields' => []],
                    ];
                    foreach ($fields as $meta_key => $cfg) {
                        if (strpos($meta_key, '_tap_' . $prefix . '_agency_id') !== false) {
                            continue;
                        }
                        if ($meta_key === '_tap_seo_title' || $meta_key === '_tap_seo_description') {
                            continue;
                        }
                        $key_lower = strtolower($meta_key);
                        if (false !== strpos($key_lower, 'price') || false !== strpos($key_lower, 'deposit') || false !== strpos($key_lower, 'currency')) {
                            $group = 'price';
                        } elseif (false !== strpos($key_lower, 'lat') || false !== strpos($key_lower, 'lng') || false !== strpos($key_lower, 'city') || false !== strpos($key_lower, 'location') || false !== strpos($key_lower, 'meeting_point') || false !== strpos($key_lower, 'pickup') || false !== strpos($key_lower, 'departure') || false !== strpos($key_lower, 'delivery') || false !== strpos($key_lower, 'destination')) {
                            $group = 'location';
                        } else {
                            $group = 'details';
                        }
                        $groups[$group]['fields'][$meta_key] = $cfg;
                    }

                    $render_field = static function ($meta_key, $cfg, $is_edit, $listing_id) {
                        $ftype  = isset($cfg['type']) ? $cfg['type'] : 'text';
                        $label  = isset($cfg['label']) ? $cfg['label'] : $meta_key;
                        $value  = $is_edit ? get_post_meta($listing_id, $meta_key, true) : (isset($cfg['default']) ? $cfg['default'] : '');
                        $span   = ($ftype === 'textarea') ? ' tap-span-2' : '';
                        echo '<div class="tap-field' . $span . '">';
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
                                $min = '';
                                if (false !== stripos($meta_key, '_lat')) { $min = ' min="-90" max="90"'; }
                                elseif (false !== stripos($meta_key, '_lng')) { $min = ' min="-180" max="180"'; }
                                else { $min = ' min="0"'; }
                                echo '<label>' . esc_html($label) . '</label><input type="number" name="' . esc_attr($meta_key) . '"' . $min . $step . ' value="' . esc_attr($value) . '">';
                                break;
                            case 'email':
                                echo '<label>' . esc_html($label) . '</label><input type="email" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '">';
                                break;
                            case 'url':
                                echo '<label>' . esc_html($label) . '</label><input type="url" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '">';
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
                        echo '<span class="tap-field-error" style="color:var(--tap-error);font-size:var(--tap-text-xs);min-height:1em;"></span>';
                        echo '</div>';
                    };

		foreach ($groups as $gkey => $gdata) {
			if (empty($gdata['fields'])) {
				continue;
			}
			echo '<div class="tap-field tap-span-2 tap-section-head" style="margin-top:var(--tap-space-4);"><strong>' . esc_html($gdata['title']) . '</strong></div>';
			foreach ($gdata['fields'] as $mk => $cfg) {
				$render_field($mk, $cfg, $is_edit, $listing_id);
			}
		}
		?>
                    <?php
                    // Coordinate map picker (when lat/lng fields exist).
                    $has_coords = false;
                    foreach ($fields as $mk => $cfg) {
                        if (false !== stripos($mk, '_lat') || false !== stripos($mk, '_lng')) { $has_coords = true;
							break; }
                    }
                    if ($has_coords) {
                        $lat_val = '';
                        $lng_val = '';
                        foreach ($fields as $mk => $cfg) {
                            if (false !== stripos($mk, '_lat')) { $lat_val = $is_edit ? get_post_meta($listing_id, $mk, true) : ''; }
                            if (false !== stripos($mk, '_lng')) { $lng_val = $is_edit ? get_post_meta($listing_id, $mk, true) : ''; }
                        }
                        if ('' === $lat_val) { $lat_val = '9.93'; }
                        if ('' === $lng_val) { $lng_val = '-84.08'; }
                        ?>
                        <div class="tap-field tap-span-2" style="margin-top:var(--tap-space-3);">
                            <label><?php esc_html_e('Ubicar en el mapa (arrastra el marcador)', 'travel-agency-platform'); ?></label>
                            <div id="tap-listing-map" style="height:260px;border-radius:var(--tap-radius);overflow:hidden;border:1px solid var(--tap-border);"></div>
                            <input type="hidden" id="tap-map-lat-init" value="<?php echo esc_attr($lat_val); ?>">
                            <input type="hidden" id="tap-map-lng-init" value="<?php echo esc_attr($lng_val); ?>">
                        </div>
                        <?php
                    }
                    ?>
                    <?php
                    // SEO section
                    $seo_fields = [];
                    foreach (['_tap_seo_title', '_tap_seo_description'] as $sk) {
                        if (isset($fields[$sk])) {
                            $seo_fields[$sk] = $fields[$sk];
                        }
                    }
                    if ($seo_fields) {
                        echo '<div class="tap-field tap-span-2 tap-section-head" style="margin-top:var(--tap-space-4);"><strong>' . esc_html__('SEO', 'travel-agency-platform') . '</strong></div>';
                        foreach ($seo_fields as $mk => $cfg) {
                            $render_field($mk, $cfg, $is_edit, $listing_id);
                        }
                    }
                    ?>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Destino', 'travel-agency-platform'); ?></label>
                        <?php
                        $dest_sel = 0;
                        if ($is_edit) {
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
                            <option value="instant" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'instant'); ?>><?php esc_html_e('Reserva directa (pago inmediato)', 'travel-agency-platform'); ?></option>
                            <option value="request" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'request'); ?>><?php esc_html_e('Solicitud de reserva (la agencia confirma antes del pago)', 'travel-agency-platform'); ?></option>
                        </select>
                    </div>
                    <?php if ($is_edit): ?>
                    <div class="tap-field tap-span-2">
                        <?php $is_published = 'publish' === $listing->post_status; ?>
                        <button type="button" class="tap-btn <?php echo $is_published ? 'tap-btn-danger' : 'tap-btn-primary'; ?>" data-publish="<?php echo $is_published ? '0' : '1'; ?>">
                            <?php echo $is_published ? esc_html__('Despublicar', 'travel-agency-platform') : esc_html__('Publicar', 'travel-agency-platform'); ?>
                        </button>
                        <span class="tap-form-msg" style="margin-left:0;margin-top:6px;"><?php echo $is_published ? esc_html__('El servicio está visible para los viajeros.', 'travel-agency-platform') : esc_html__('El servicio está en borrador o en revisión.', 'travel-agency-platform'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="tap-btn tap-btn-primary"><?php echo $is_edit ? esc_html__('Guardar cambios', 'travel-agency-platform') : esc_html__('Crear servicio', 'travel-agency-platform'); ?></button>
                <span class="tap-form-msg"></span>
            </form>

        </div>

        <?php
        // Reuse the shared wizard bundle: it provides the Media Library uploader,
        // toasts, publish toggle and form submission. The generic editor has no
        // steps, so cfg.genericEditor makes submitListing jump straight to the
        // edit URL instead of step 2.
        wp_enqueue_script('tap-agency-wizard', TAP_PLUGIN_URL . 'assets/js/agency-wizard.js', [], TAP_VERSION, true);
        wp_localize_script('tap-agency-wizard', 'tapAgencyWizard', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'uploadNonce'     => wp_create_nonce('tap_front_dash_nonce'),
            'listingNonce'    => wp_create_nonce('tap_agency_listing_nonce'),
            'isEdit'          => (bool) $is_edit,
            'listingId'       => (int) $listing_id,
            'listingType'     => $type,
            'stepUrl'         => '',
            'currency'        => '$',
            'currencyCode'    => 'USD',
            'decimals'        => 2,
            'maxUploadBytes'  => TAP_Agency_Media::agency_image_max_bytes(),
            'bedTypes'        => [],
            'genericEditor'   => true,
            'requireDestination' => true,
            'i18n' => [
                'saved'         => __('Guardado', 'travel-agency-platform'),
                'error'         => __('Ha ocurrido un error', 'travel-agency-platform'),
                'confirm'       => __('Confirmar', 'travel-agency-platform'),
                'cancel'        => __('Cancelar', 'travel-agency-platform'),
                'close'         => __('Cerrar', 'travel-agency-platform'),
                'main'          => __('Principal', 'travel-agency-platform'),
                'setMain'       => __('Usar como foto principal', 'travel-agency-platform'),
                'removeImage'   => __('Quitar imagen', 'travel-agency-platform'),
                'uploading'     => __('Subiendo', 'travel-agency-platform'),
                'uploadingDone' => __('Subiendo', 'travel-agency-platform'),
                'uploadedCount' => __('Subidas', 'travel-agency-platform'),
                'uploaded'      => __('Imágenes subidas.', 'travel-agency-platform'),
                'uploadFailed'  => __('No se pudo subir la imagen.', 'travel-agency-platform'),
                'badType'       => __('solo se permiten imágenes.', 'travel-agency-platform'),
                'tooBig'        => __('la imagen supera el tamaño máximo.', 'travel-agency-platform'),
                'added'         => __('Imagen añadida.', 'travel-agency-platform'),
                'delete'        => __('Eliminar', 'travel-agency-platform'),
                'networkError'  => __('Error de conexión. Revisa tu red e inténtalo de nuevo.', 'travel-agency-platform'),
                'destRequired'  => __('Selecciona un destino para el servicio.', 'travel-agency-platform'),
            ],
        ]);
        // Leaflet for the coordinate picker.
        wp_enqueue_style('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', true);
        ?>
        <script>
        (function(){
            function initMap(){
                if (typeof L === 'undefined') { setTimeout(initMap, 100); return; }
                var el = document.getElementById('tap-listing-map');
                if (!el) { return; }
                var latInput = document.querySelector('input[name*="_lat"]');
                var lngInput = document.querySelector('input[name*="_lng"]');
                var lat0 = parseFloat(document.getElementById('tap-map-lat-init').value) || 9.93;
                var lng0 = parseFloat(document.getElementById('tap-map-lng-init').value) || -84.08;
                if (latInput && latInput.value) { lat0 = parseFloat(latInput.value) || lat0; }
                if (lngInput && lngInput.value) { lng0 = parseFloat(lngInput.value) || lng0; }
                var map = L.map(el).setView([lat0, lng0], 12);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap',
                    maxZoom: 19
                }).addTo(map);
                var marker = L.marker([lat0, lng0], { draggable: true }).addTo(map);
                function sync(lat, lng) {
                    if (latInput) { latInput.value = lat.toFixed(6); }
                    if (lngInput) { lngInput.value = lng.toFixed(6); }
                }
                marker.on('dragend', function(e){
                    var ll = e.target.getLatLng();
                    sync(ll.lat, ll.lng);
                });
                map.on('click', function(e){
                    marker.setLatLng(e.latlng);
                    sync(e.latlng.lat, e.latlng.lng);
                });
                // keep marker in sync if user types
                [latInput, lngInput].forEach(function(inp){
                    if (!inp) return;
                    inp.addEventListener('change', function(){
                        var la = parseFloat(latInput.value), ln = parseFloat(lngInput.value);
                        if (!isNaN(la) && !isNaN(ln)) { marker.setLatLng([la, ln]); map.setView([la, ln], map.getZoom()); }
                    });
                });
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initMap); } else { initMap(); }
        })();
        </script>
        <script>
        (function(){
            function showErr(input, msg) {
                var field = input.closest('.tap-field');
                if (!field) return;
                var span = field.querySelector('.tap-field-error');
                if (span) span.textContent = msg || '';
                input.classList.toggle('is-invalid', !!msg);
            }
            function validate(input) {
                if (!input || !input.name) return '';
                var v = input.value.trim();
                if (input.hasAttribute('required') && '' === v) return 'Requerido.';
                if (input.type === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Email inválido.';
                if (input.type === 'url' && v && !/^https?:\/\//i.test(v)) return 'URL inválida (debe empezar con http).';
                if (input.type === 'number') {
                    if (v && isNaN(Number(v))) return 'Debe ser numérico.';
                    var min = input.getAttribute('min'); var max = input.getAttribute('max');
                    if (min !== null && v && Number(v) < Number(min)) return 'Mínimo ' + min + '.';
                    if (max !== null && v && Number(v) > Number(max)) return 'Máximo ' + max + '.';
                }
                return '';
            }
            function boot() {
                var form = document.getElementById('tap-listing-form');
                if (!form) return;
                form.querySelectorAll('input, select, textarea').forEach(function(inp){
                    inp.addEventListener('blur', function(){ showErr(inp, validate(inp)); });
                    inp.addEventListener('input', function(){ if (inp.classList.contains('is-invalid')) showErr(inp, validate(inp)); });
                });
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
        })();
        </script>
		<?php
        return ob_get_clean();
    }

    /**
     * B2B property wizard for accommodations: a guided 5-step flow
     * (Información → Unidades → Precios y disponibilidad → Políticas → Publicar)
     * that reuses the existing save handlers and the shared pricing core.
     */
    /**
     * Checklist state used by the wizard stepper and the contextual rail.
     * Every item maps to the step it belongs to so a single list can drive
     * both the "completed" markers and the "what is missing" hints.
     *
     * @return array{items: array, completed: int, percent: int}
     */
    /**
     * ISO code used by a listing: its own meta when set, the site setting
     * otherwise. Returns [code, plain symbol, decimals].
     */
    /**
     * Shared front-end behaviour for the accommodation wizard: ajax forms,
     * bed builder, Media Library uploader, publish toggle and feedback.
     */
    /**
     * Spanish labels + option copy for the accommodation wizard. The admin meta
     * box keeps its own registry so both UIs can evolve independently.
     */
    /**
     * Media Library uploader used by the listing and by every room.
     * Images are sent as attachment ids; the legacy URL fields stay in the
     * DOM (renamed by the script) for anybody pasting an external image.
     *
     * @param int   $post_id     Post the uploads are attached to (0 while new).
     * @param int   $main_id     Current featured attachment id.
     * @param array $gallery_ids Current gallery attachment ids.
     * @param array $names       ['main_id','gallery_ids','main_url','gallery_urls'] field names.
     */
    /**
     * Spanish bed type labels for the wizard (the shared registry keeps its
     * own English copy for the admin UI).
     */
    /**
     * One editable unit (room type) card. Used both for the existing rooms and
     * for the "add unit" form, so both stay in sync when the markup changes.
     */
    /**
     * Short Spanish clarification shown under each cancellation policy.
     */
}
