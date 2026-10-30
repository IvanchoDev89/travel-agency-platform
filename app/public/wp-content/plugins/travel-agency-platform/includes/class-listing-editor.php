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
                <input type="hidden" name="slot_id" id="bw-slot-id" value="">

                <!-- Progress bar -->
                <div class="tap-wz">
                    <div class="tap-wz-bar">
                        <div class="tap-wz-fill" id="tap-wz-fill"></div>
                    </div>
                    <div class="tap-wz-steps">
                        <button type="button" class="tap-wz-step is-active" data-step="1"><span class="tap-wz-num">1</span><span class="tap-wz-label">Información</span></button>
                        <button type="button" class="tap-wz-step" data-step="2"><span class="tap-wz-num">2</span><span class="tap-wz-label">Precios</span></button>
                        <button type="button" class="tap-wz-step" data-step="3"><span class="tap-wz-num">3</span><span class="tap-wz-label">Detalles</span></button>
                        <button type="button" class="tap-wz-step" data-step="4"><span class="tap-wz-num">4</span><span class="tap-wz-label">Publicar</span></button>
                    </div>
                    <div class="tap-wz-counter">Paso <strong id="tap-wz-cur">1</strong> de <strong>4</strong></div>
                </div>

                <!-- Step 1: Información -->
                <div class="tap-wz-panel" data-step="1">
                    <div class="tap-wz-section">
                        <h3 class="tap-wz-title">Información básica</h3>
                        <div class="tap-wz-grid">
                            <div class="tap-wz-field tap-wz-span2">
                                <label>Nombre *</label>
                                <input type="text" name="title" required value="<?php echo esc_attr($listing ? $listing->post_title : ''); ?>">
                            </div>
                            <div class="tap-wz-field tap-wz-span2">
                                <label>Descripción</label>
                                <textarea name="description" rows="4"><?php echo esc_textarea($listing ? $listing->post_content : ''); ?></textarea>
                            </div>
                            <div class="tap-wz-field tap-wz-span2">
                                <label>Fotos</label>
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
                        </div>
                    </div>
                    <div class="tap-wz-nav">
                        <span></span>
                        <button type="button" class="tap-wz-next">Siguiente &rarr;</button>
                    </div>
                </div>

                <!-- Step 2: Precios y ubicación -->
                <div class="tap-wz-panel" data-step="2" style="display:none;">
                    <div class="tap-wz-section">
                        <h3 class="tap-wz-title">Precios y ubicación</h3>
                        <div class="tap-wz-grid">
                            <?php
                            $price_fields = [];
                            $loc_fields = [];
                            foreach ($fields as $mk => $cfg) {
                                if (strpos($mk, '_tap_' . $prefix . '_agency_id') !== false) continue;
                                if ($mk === '_tap_seo_title' || $mk === '_tap_seo_description') continue;
                                $kl = strtolower($mk);
                                if (false !== strpos($kl, 'price') || false !== strpos($kl, 'deposit') || false !== strpos($kl, 'currency')) {
                                    $price_fields[$mk] = $cfg;
                                } elseif (false !== strpos($kl, 'lat') || false !== strpos($kl, 'lng') || false !== strpos($kl, 'city') || false !== strpos($kl, 'location') || false !== strpos($kl, 'meeting_point') || false !== strpos($kl, 'pickup') || false !== strpos($kl, 'destination')) {
                                    $loc_fields[$mk] = $cfg;
                                }
                            }
                            $render_field_simple = static function ($mk, $cfg, $is_edit, $listing_id) {
                                $ftype = $cfg['type'] ?? 'text';
                                $label = $cfg['label'] ?? $mk;
                                $value = $is_edit ? get_post_meta($listing_id, $mk, true) : ($cfg['default'] ?? '');
                                echo '<div class="tap-wz-field">';
                                switch ($ftype) {
                                    case 'checkbox':
                                        echo '<label class="tap-wz-check"><input type="checkbox" name="' . esc_attr($mk) . '" value="1" ' . checked('1', (string) $value, false) . '> ' . esc_html($label) . '</label>';
                                        break;
                                    case 'select':
                                        echo '<label>' . esc_html($label) . '</label><select name="' . esc_attr($mk) . '"><option value="">—</option>';
                                        foreach ($cfg['options'] as $k => $v) {
                                            $sel = (string) $value !== '' && (string) $k === (string) $value ? ' selected' : '';
                                            echo '<option value="' . esc_attr($k) . '"' . $sel . '>' . esc_html($v) . '</option>';
                                        }
                                        echo '</select>';
                                        break;
                                    case 'number':
                                        $step = isset($cfg['step']) ? ' step="' . esc_attr($cfg['step']) . '"' : '';
                                        echo '<label>' . esc_html($label) . '</label><input type="number" name="' . esc_attr($mk) . '"' . $step . ' value="' . esc_attr($value) . '" min="0">';
                                        break;
                                    case 'time':
                                        echo '<label>' . esc_html($label) . '</label><input type="time" name="' . esc_attr($mk) . '" value="' . esc_attr($value) . '">';
                                        break;
                                    case 'textarea':
                                        echo '<label>' . esc_html($label) . '</label><textarea name="' . esc_attr($mk) . '" rows="3">' . esc_textarea($value) . '</textarea>';
                                        break;
                                    default:
                                        echo '<label>' . esc_html($label) . '</label><input type="text" name="' . esc_attr($mk) . '" value="' . esc_attr($value) . '">';
                                }
                                echo '</div>';
                            };
                            if ($price_fields) {
                                echo '<div class="tap-wz-group-title">Precios</div>';
                                foreach ($price_fields as $mk => $cfg) $render_field_simple($mk, $cfg, $is_edit, $listing_id);
                            }
                            if ($loc_fields) {
                                echo '<div class="tap-wz-group-title">Ubicación</div>';
                                foreach ($loc_fields as $mk => $cfg) $render_field_simple($mk, $cfg, $is_edit, $listing_id);
                            }
                            // Map picker
                            $has_coords = false;
                            foreach ($fields as $mk => $cfg) {
                                if (false !== stripos($mk, '_lat') || false !== stripos($mk, '_lng')) { $has_coords = true;
break; }
                            }
                            if ($has_coords) {
                                $lat_val = '';
$lng_val = '';
                                foreach ($fields as $mk => $cfg) {
                                    if (false !== stripos($mk, '_lat')) $lat_val = $is_edit ? get_post_meta($listing_id, $mk, true) : '';
                                    if (false !== stripos($mk, '_lng')) $lng_val = $is_edit ? get_post_meta($listing_id, $mk, true) : '';
                                }
                                if ('' === $lat_val) $lat_val = '9.93';
                                if ('' === $lng_val) $lng_val = '-84.08';
                                ?>
                                <div class="tap-wz-field tap-wz-span2">
                                    <label><?php esc_html_e('Ubicar en el mapa', 'travel-agency-platform'); ?></label>
                                    <div id="tap-listing-map" style="height:220px;border-radius:8px;overflow:hidden;border:1px solid var(--tap-border,#e5e7eb);"></div>
                                    <input type="hidden" id="tap-map-lat-init" value="<?php echo esc_attr($lat_val); ?>">
                                    <input type="hidden" id="tap-map-lng-init" value="<?php echo esc_attr($lng_val); ?>">
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="tap-wz-nav">
                        <button type="button" class="tap-wz-prev">&larr; Anterior</button>
                        <button type="button" class="tap-wz-next">Siguiente &rarr;</button>
                    </div>
                </div>

                <!-- Step 3: Detalles -->
                <div class="tap-wz-panel" data-step="3" style="display:none;">
                    <div class="tap-wz-section">
                        <h3 class="tap-wz-title">Detalles del servicio</h3>
                        <div class="tap-wz-grid">
                            <?php
                            $detail_fields = [];
                            foreach ($fields as $mk => $cfg) {
                                if (strpos($mk, '_tap_' . $prefix . '_agency_id') !== false) continue;
                                if ($mk === '_tap_seo_title' || $mk === '_tap_seo_description') continue;
                                $kl = strtolower($mk);
                                if (false !== strpos($kl, 'price') || false !== strpos($kl, 'deposit') || false !== strpos($kl, 'currency')) continue;
                                if (false !== strpos($kl, 'lat') || false !== strpos($kl, 'lng') || false !== strpos($kl, 'city') || false !== strpos($kl, 'location') || false !== strpos($kl, 'meeting_point') || false !== strpos($kl, 'pickup') || false !== strpos($kl, 'departure') || false !== strpos($kl, 'delivery') || false !== strpos($kl, 'destination')) continue;
                                $detail_fields[$mk] = $cfg;
                            }
                            foreach ($detail_fields as $mk => $cfg) $render_field_simple($mk, $cfg, $is_edit, $listing_id);
                            ?>
                        </div>
                    </div>
                    <div class="tap-wz-nav">
                        <button type="button" class="tap-wz-prev">&larr; Anterior</button>
                        <button type="button" class="tap-wz-next">Siguiente &rarr;</button>
                    </div>
                </div>

                <!-- Step 4: SEO y publicación -->
                <div class="tap-wz-panel" data-step="4" style="display:none;">
                    <div class="tap-wz-section">
                        <h3 class="tap-wz-title">SEO y publicación</h3>
                        <div class="tap-wz-grid">
                            <?php
                            $seo_fields = [];
                            foreach (['_tap_seo_title', '_tap_seo_description'] as $sk) {
                                if (isset($fields[$sk])) $seo_fields[$sk] = $fields[$sk];
                            }
                            foreach ($seo_fields as $mk => $cfg) $render_field_simple($mk, $cfg, $is_edit, $listing_id);
                            ?>
                            <div class="tap-wz-field tap-wz-span2">
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
                                            if ($dl > $highest_level) { $highest_level = $dl;
$dest_sel = (int) $dt->term_id; }
                                        }
                                    }
                                }
                                echo TAP_Destinations::render_picker($dest_sel, 'destination');
                                ?>
                            </div>
                            <div class="tap-wz-field">
                                <label><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></label>
                                <select name="booking_mode">
                                    <option value="instant" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'instant'); ?>><?php esc_html_e('Reserva directa', 'travel-agency-platform'); ?></option>
                                    <option value="request" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'request'); ?>><?php esc_html_e('Solicitud de reserva', 'travel-agency-platform'); ?></option>
                                </select>
                            </div>
                            <?php if ($is_edit): ?>
                            <div class="tap-wz-field tap-wz-span2">
                                <?php $is_published = 'publish' === $listing->post_status; ?>
                                <button type="button" class="tap-btn <?php echo $is_published ? 'tap-btn-danger' : 'tap-btn-primary'; ?>" data-publish="<?php echo $is_published ? '0' : '1'; ?>">
                                    <?php echo $is_published ? esc_html__('Despublicar', 'travel-agency-platform') : esc_html__('Publicar', 'travel-agency-platform'); ?>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tap-wz-nav">
                        <button type="button" class="tap-wz-prev">&larr; Anterior</button>
                        <button type="submit" class="tap-btn tap-btn-primary tap-wz-submit"><?php echo $is_edit ? esc_html__('Guardar cambios', 'travel-agency-platform') : esc_html__('Crear servicio', 'travel-agency-platform'); ?></button>
                    </div>
                </div>
            </form>

            <style>
            .tap-wz{font-family:inherit;}
            .tap-wz-bar{background:var(--tap-primary-bg);border:1px solid var(--tap-primary-border);border-radius:var(--tap-radius-xl);padding:20px;margin-bottom:24px;}
            .tap-wz-progress{margin-bottom:14px;}
            .tap-wz-progress-track{height:6px;background:var(--tap-neutral-200);border-radius:3px;overflow:hidden;}
            .tap-wz-progress-fill{height:100%;background:linear-gradient(90deg,var(--tap-primary),var(--tap-primary-dark));border-radius:3px;transition:width .4s cubic-bezier(.4,0,.2,1);width:25%;}
            .tap-wz-steps{display:flex;gap:8px;flex-wrap:wrap;list-style:none;margin:0;padding:0;}
            .tap-wz-step{flex:1 1 0;min-width:120px;padding:0;position:relative;}
            .tap-wz-step > button{display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid var(--tap-neutral-300);border-radius:10px;background:#fff;color:var(--tap-neutral-600);font-size:13px;font-weight:600;cursor:pointer;height:100%;transition:all .25s;width:100%;}
            .tap-wz-step.is-active > button{background:var(--tap-primary);color:#fff;border-color:var(--tap-primary);box-shadow:0 4px 14px rgba(13,148,136,.35);}
            .tap-wz-step.is-done > button{background:var(--tap-success-bg,#ecfdf5);color:var(--tap-success-dark,#047857);border-color:var(--tap-success-border,#a7f3d0);}
            .tap-wz-step > button:hover:not(.is-active){background:var(--tap-neutral-50);border-color:var(--tap-neutral-400);transform:translateY(-2px);}
            .tap-wz-num{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(0,0,0,.06);font-weight:700;font-size:12px;}
            .tap-wz-step.is-active .tap-wz-num{background:rgba(255,255,255,.25);}
            .tap-wz-step.is-done .tap-wz-num{background:var(--tap-success,#10b981);color:#fff;}
            .tap-wz-label{white-space:nowrap;}
            .tap-wz-counter{text-align:center;margin-top:8px;font-size:13px;color:var(--tap-neutral-500);}
            .tap-wz-counter strong{color:var(--tap-primary);font-weight:700;}
            .tap-wz-panel{animation:fadeIn .3s ease;}
            @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
            .tap-wz-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--tap-space-4,16px);}
            .tap-wz-field{display:flex;flex-direction:column;gap:4px;}
            .tap-wz-field label{font-size:13px;font-weight:600;color:var(--tap-neutral-700);}
            .tap-wz-field input,.tap-wz-field select,.tap-wz-field textarea{padding:8px 10px;border:1px solid var(--tap-neutral-300);border-radius:var(--tap-radius-md,6px);font-size:14px;font-family:inherit;}
            .tap-wz-field input:focus,.tap-wz-field select:focus,.tap-wz-field textarea:focus{outline:none;border-color:var(--tap-primary);box-shadow:0 0 0 3px rgba(13,148,136,.15);}
            .tap-wz-span2{grid-column:1/-1;}
            .tap-wz-section{margin-bottom:16px;}
            .tap-wz-title{margin:0 0 12px;font-size:1.1rem;color:var(--tap-neutral-800);}
            .tap-wz-group-title{grid-column:1/-1;font-weight:700;font-size:13px;color:var(--tap-neutral-600);margin-top:8px;padding-bottom:4px;border-bottom:1px solid var(--tap-neutral-200);}
            .tap-wz-nav{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--tap-neutral-200);gap:8px;}
            .tap-wz-prev,.tap-wz-next,.tap-wz-submit{min-width:120px;padding:10px 18px;border-radius:8px;font-weight:600;border:1px solid var(--tap-neutral-300);background:#fff;color:var(--tap-neutral-700);cursor:pointer;transition:all .2s;}
            .tap-wz-next,.tap-wz-submit{background:var(--tap-primary);color:#fff;border-color:var(--tap-primary);}
            .tap-wz-next:hover,.tap-wz-submit:hover{background:var(--tap-primary-dark);transform:translateY(-1px);}
            .tap-wz-prev:hover{background:var(--tap-neutral-100);}
            .tap-wz-check{display:flex;align-items:center;gap:6px;font-weight:400;}
            @media (max-width:600px){.tap-wz-grid{grid-template-columns:1fr;}.tap-wz-step{min-width:0;flex-basis:48%;}.tap-wz-step span:last-child{display:none;}}
            </style>
            <script>
            (function(){
                var form = document.getElementById('tap-listing-form');
                if (!form) return;
                var steps = form.querySelectorAll('.tap-wz-panel');
                var btns = form.querySelectorAll('.tap-wz-step');
                var nextBtns = form.querySelectorAll('.tap-wz-next');
                var prevBtns = form.querySelectorAll('.tap-wz-prev');
                var submitBtn = form.querySelector('.tap-wz-submit');
                var fill = document.getElementById('tap-wz-fill');
                var counter = document.getElementById('tap-wz-cur');
                var current = 1;
                var total = steps.length;
                function show(n){
                    if (n < 1) n = 1;
                    if (n > total) n = total;
                    current = n;
                    steps.forEach(function(s){ s.style.display = (parseInt(s.dataset.step,10)===n) ? '' : 'none'; });
                    btns.forEach(function(b){ 
                        var bs = parseInt(b.dataset.step,10);
                        b.classList.toggle('is-active', bs===n);
                        b.classList.toggle('is-done', bs<n);
                    });
                    if (fill) fill.style.width = (n/total*100) + '%';
                    if (counter) counter.textContent = n;
                    var top = form.getBoundingClientRect().top + window.scrollY - 20;
                    if (window.scrollY > top) window.scrollTo({top: top, behavior:'smooth'});
                }
                btns.forEach(function(b){ b.addEventListener('click', function(){ show(parseInt(b.dataset.step,10)); }); });
                nextBtns.forEach(function(b){ b.addEventListener('click', function(){ show(current+1); }); });
                prevBtns.forEach(function(b){ b.addEventListener('click', function(){ show(current-1); }); });
                show(1);
            })();
            </script>
        <?php
        // Reuse the shared wizard bundle: it provides the Media Library uploader,
        // toasts, publish toggle and form submission. The generic editor has no
        // steps, so cfg.genericEditor makes submitListing jump straight to the
        // edit URL instead of step 2.
        wp_enqueue_style('tap-agency-calendar', TAP_PLUGIN_URL . 'assets/css/agency-calendar.css', [], TAP_VERSION);
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
