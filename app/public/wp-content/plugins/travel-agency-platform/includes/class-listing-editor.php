<?php
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
        </div>
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
                    </div>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                        <textarea name="description" rows="4"><?php echo esc_textarea($listing ? $listing->post_content : ''); ?></textarea>
                    </div>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Foto principal (URL de la imagen)', 'travel-agency-platform'); ?></label>
                        <input type="url" name="featured_image_url" placeholder="https://…" value="<?php echo esc_url($is_edit ? (string) get_the_post_thumbnail_url($listing_id, 'full') : ''); ?>">
                        <?php if ($is_edit && get_the_post_thumbnail_url($listing_id)): ?>
                            <img class="tap-img-preview" src="<?php echo esc_url(get_the_post_thumbnail_url($listing_id, 'medium')); ?>" alt="" style="max-width:180px;height:auto;margin-top:6px;border-radius:6px;">
                        <?php endif; ?>
                    </div>
                    <?php
                    foreach ($fields as $meta_key => $cfg) {
                        if (strpos($meta_key, '_tap_' . $prefix . '_agency_id') !== false) {
                            continue;
                        }
                        if ($meta_key === '_tap_seo_title' || $meta_key === '_tap_seo_description') {
                            continue;
                        }
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
                                echo '<label>' . esc_html($label) . '</label><input type="number" name="' . esc_attr($meta_key) . '" min="0"' . $step . ' value="' . esc_attr($value) . '">';
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
                        echo '</div>';
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
                </div>
                <button type="submit" class="tap-btn tap-btn-primary"><?php echo $is_edit ? esc_html__('Guardar cambios', 'travel-agency-platform') : esc_html__('Crear servicio', 'travel-agency-platform'); ?></button>
                <span class="tap-form-msg"></span>
            </form>

        </div>

        <script>
        (function(){
            var boot = function(){
            if (typeof tap_ajax === 'undefined') { return; }
            var url = tap_ajax.ajax_url;
            var nonce = tap_ajax.listing_nonce;
            var msgBox = function(f, m){ var el = f.querySelector('.tap-form-msg'); if(el){ el.textContent = m; } };

            var listing = document.getElementById('tap-listing-form');
            if(listing){
                listing.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = listing.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(listing); data.append('action','tap_agency_save_listing');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ window.location.href = j.data.edit_url; }
                        else { msgBox(listing, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(listing, 'Network error'); });
                });
            }

            var addRoom = document.getElementById('tap-room-add');
            if(addRoom){
                bindBedBuilder(addRoom);
                addRoom.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = addRoom.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(addRoom); data.append('action','tap_agency_save_room');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ window.location.reload(); }
                        else { msgBox(addRoom, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(addRoom, 'Network error'); });
                });
            }

            var bedTypes = ['king','queen','double','twin','bunk','sofa','crib','murphy','futon'];
            var bedLabels = {king:'King',queen:'Queen',double:'Double',twin:'Twin',bunk:'Bunk',sofa:'Sofa Bed',crib:'Crib',murphy:'Murphy',futon:'Futon'};
            function bedRowHTML(type, count){
                var opts = bedTypes.map(function(t){ return '<option value="'+t+'"'+(t===type?' selected':'')+'>'+(bedLabels[t]||t)+'</option>'; }).join('');
                return '<div class="tap-bed-row"><select class="tap-bed-type">'+opts+'</select>'+
                        '<input type="number" class="tap-bed-count" min="1" value="'+(count||1)+'">'+
                        '<button type="button" class="tap-bed-remove" aria-label="<?php echo esc_attr__('Quitar cama', 'travel-agency-platform'); ?>">&times;</button></div>';
            }
            function bindBedBuilder(form){
                var rows = form.querySelector('.tap-beds-rows');
                if(!rows){ return; }
                var json = form.querySelector('.tap-beds-json');
                var add = form.querySelector('.tap-bed-add');
                if(add){ add.addEventListener('click', function(){ rows.insertAdjacentHTML('beforeend', bedRowHTML('double',1)); }); }
                rows.addEventListener('click', function(e){ if(e.target.classList.contains('tap-bed-remove')){ e.target.parentNode.remove(); } });
                function serialize(){
                    var arr = [];
                    rows.querySelectorAll('.tap-bed-row').forEach(function(row){
                        var t = row.querySelector('.tap-bed-type').value;
                        var c = parseInt(row.querySelector('.tap-bed-count').value, 10) || 0;
                        if(c > 0){ arr.push({type:t, count:c}); }
                    });
                    if(json){ json.value = JSON.stringify(arr); }
                }
                form.addEventListener('submit', serialize);
                serialize();
            }

            document.querySelectorAll('.tap-room-form[data-room]').forEach(function(form){
                bindBedBuilder(form);
                form.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(form); data.append('action','tap_agency_save_room');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ msgBox(form, j.data.message); }
                        else { msgBox(form, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(form, 'Network error'); });
                });
                var del = form.querySelector('.tap-delete-room');
                if(del){
                    del.addEventListener('click', function(){
                        if(!window.confirm('Delete this room?')){ return; }
                        var data = new FormData(); data.append('action','tap_agency_delete_room');
                        data.append('room_id', form.getAttribute('data-room'));
                        data.append('nonce', nonce);
                        fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(j){ if(j.success){ window.location.reload(); } else { window.alert(j.data.message || 'Error'); } });
});
                }
            }
        );
            };
            if ('loading' === document.readyState) { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
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
