<?php
/**
 * TAP_Acc_Wizard_Room_Form — Room form and its supporting widgets.
 *
 * The per-room editor form, the bed-type label map and the image uploader
 * used by the accommodation wizard steps. Split out of TAP_Acc_Wizard in
 * 1.5.17; the steps stay in TAP_Acc_Wizard and call into this class.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Acc_Wizard_Room_Form {

    public static function render_room_form($room, $acc_id, $open = false) {
        $room_id = $room ? (int) $room->ID : 0;
        $is_new  = !$room_id;
        $meta    = function ($key, $default = '') use ($room_id) {
            return $room_id ? (string) get_post_meta($room_id, $key, true) : $default;
        };
        $beds     = $room_id ? (array) TAP_Post_Types::get_room_beds($room_id) : [];
        $amenity  = $room_id ? implode(', ', (array) TAP_Post_Types::get_room_amenities($room_id)) : '';
        $thumb_id = $room_id ? (int) get_post_thumbnail_id($room_id) : 0;
        $gallery  = $room_id
            ? array_filter(array_map('trim', explode(',', (string) get_post_meta($room_id, '_tap_room_gallery', true))))
            : [];
        $currency = $meta('_tap_room_currency', strtoupper(get_post_meta((int) $acc_id, '_tap_acc_currency', true) ?: TAP_Currency::code()));
        $currency_symbol = $currency ? TAP_Currency::plain_symbol($currency) : '';
        $form_id  = $is_new ? 'tap-room-add' : 'tap-room-' . $room_id;
        $active   = $room_id ? ('1' === $meta('_tap_room_is_active', '1')) : true;

        ob_start();
        ?>
        <form class="tap-manage-form tap-room-form<?php echo $room_id ? '' : ' tap-room-form-new'; ?>"
                id="<?php echo esc_attr($form_id); ?>"
                data-room="<?php echo (int) $room_id; ?>"
                <?php echo $room_id ? '' : 'data-new="1"'; ?>>
                <?php echo $active ? '' : 'data-inactive="1"'; ?>>
                <?php echo ($room_id || $open) ? '' : 'hidden'; ?>>
                <div class="tap-unit-card<?php echo $active ? '' : ' is-inactive'; ?>">
            <header class="tap-unit-card-head">
                <div class="tap-unit-card-title">
                    <strong><?php echo esc_html($is_new ? __('Nueva unidad', 'travel-agency-platform') : $room->post_title); ?></strong>
                    <?php if (!$is_new): ?>
                        <span class="tap-chip tap-chip-muted"><?php echo esc_html(sprintf(/* translators: %s: price */ __('%s / noche', 'travel-agency-platform'), $meta('_tap_room_price_per_night') ? trim($currency_symbol) . ' ' . number_format((float) $meta('_tap_room_price_per_night'), 2) : '—')); ?></span>
                        <span class="tap-chip <?php echo $active ? 'tap-chip-success' : 'tap-chip-muted'; ?>"><?php echo $active ? esc_html__('Activa', 'travel-agency-platform') : esc_html__('Inactiva', 'travel-agency-platform'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$is_new): ?>
                    <button type="button" class="tap-btn tap-btn-sm tap-unit-toggle" aria-expanded="false">
                        <?php esc_html_e('Editar', 'travel-agency-platform'); ?>
                    </button>
                <?php endif; ?>
            </header>

            <div class="tap-unit-card-body">
                <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                <input type="hidden" name="room_id" value="<?php echo (int) $room_id; ?>">
                <input type="hidden" name="accommodation_id" value="<?php echo (int) $acc_id; ?>">
                <input type="hidden" name="currency" value="<?php echo esc_attr($currency); ?>">

                <div class="tap-form-grid">
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-title"><?php esc_html_e('Nombre de la unidad', 'travel-agency-platform'); ?> <span class="tap-req" aria-hidden="true">*</span></label>
                        <input type="text" id="<?php echo esc_attr($form_id); ?>-title" name="title" required maxlength="120"
                                placeholder="<?php esc_attr_e('p.ej. Habitación Doble Estándar', 'travel-agency-platform'); ?>"
                                value="<?php echo esc_attr($is_new ? '' : $room->post_title); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-price"><?php esc_html_e('Precio por noche', 'travel-agency-platform'); ?> <span class="tap-req" aria-hidden="true">*</span></label>
                        <div class="tap-number-row">
                            <button type="button" class="tap-num-btn" data-step="-1" aria-label="<?php esc_attr_e('Restar', 'travel-agency-platform'); ?>">&minus;</button>
                            <input type="number" id="<?php echo esc_attr($form_id); ?>-price" class="tap-num-input tap-field-input"
                                    name="price_per_night" min="0.01" step="0.01" required
                                    placeholder="<?php echo esc_attr(trim($currency_symbol)); ?>"
                                    value="<?php echo esc_attr($meta('_tap_room_price_per_night')); ?>">
                            <button type="button" class="tap-num-btn" data-step="1" aria-label="<?php esc_attr_e('Sumar', 'travel-agency-platform'); ?>">+</button>
                        </div>
                        <span class="tap-field-hint"><?php esc_html_e('Es el precio base; los días especiales se ajustan en el paso de Precios.', 'travel-agency-platform'); ?></span>
                    </div>

                    <div class="tap-field tap-span-2">
                        <label for="<?php echo esc_attr($form_id); ?>-desc"><?php esc_html_e('Descripción de la unidad', 'travel-agency-platform'); ?></label>
                        <textarea id="<?php echo esc_attr($form_id); ?>-desc" name="description" rows="2" maxlength="500"><?php echo esc_textarea($is_new ? '' : $room->post_excerpt); ?></textarea>
                    </div>

                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-adults"><?php esc_html_e('Máx. adultos', 'travel-agency-platform'); ?></label>
                        <input type="number" id="<?php echo esc_attr($form_id); ?>-adults" name="max_adults" min="1" max="30" value="<?php echo esc_attr($meta('_tap_room_max_adults', '2')); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-occupancy"><?php esc_html_e('Máx. ocupación total', 'travel-agency-platform'); ?></label>
                        <input type="number" id="<?php echo esc_attr($form_id); ?>-occupancy" name="max_occupancy" min="1" max="30" value="<?php echo esc_attr($meta('_tap_room_max_occupancy', '2')); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-inventory"><?php esc_html_e('Unidades disponibles', 'travel-agency-platform'); ?></label>
                        <div class="tap-number-row">
                            <button type="button" class="tap-num-btn" data-step="-1" aria-label="<?php esc_attr_e('Restar', 'travel-agency-platform'); ?>">&minus;</button>
                            <input type="number" id="<?php echo esc_attr($form_id); ?>-inventory" class="tap-num-input tap-field-input" name="inventory" min="1" max="999" value="<?php echo esc_attr($meta('_tap_room_inventory', '1')); ?>">
                            <button type="button" class="tap-num-btn" data-step="1" aria-label="<?php esc_attr_e('Sumar', 'travel-agency-platform'); ?>">+</button>
                        </div>
                        <span class="tap-field-hint"><?php esc_html_e('Cuántas de esta unidad puedes vender a la vez.', 'travel-agency-platform'); ?></span>
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-minstay"><?php esc_html_e('Estadía mínima', 'travel-agency-platform'); ?></label>
                        <div class="tap-number-row">
                            <button type="button" class="tap-num-btn" data-step="-1" aria-label="<?php esc_attr_e('Restar', 'travel-agency-platform'); ?>">&minus;</button>
                            <input type="number" id="<?php echo esc_attr($form_id); ?>-minstay" class="tap-num-input tap-field-input" name="min_stay" min="0" max="60" value="<?php echo esc_attr($meta('_tap_room_min_stay', '1')); ?>">
                            <button type="button" class="tap-num-btn" data-step="1" aria-label="<?php esc_attr_e('Sumar', 'travel-agency-platform'); ?>">+</button>
                        </div>
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-size"><?php esc_html_e('Tamaño', 'travel-agency-platform'); ?></label>
                        <input type="text" id="<?php echo esc_attr($form_id); ?>-size" name="size" placeholder="28 m²" value="<?php echo esc_attr($meta('_tap_room_size')); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-view"><?php esc_html_e('Vista', 'travel-agency-platform'); ?></label>
                        <input type="text" id="<?php echo esc_attr($form_id); ?>-view" name="view" placeholder="<?php esc_attr_e('p.ej. Vista al mar', 'travel-agency-platform'); ?>" value="<?php echo esc_attr($meta('_tap_room_view')); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-floor"><?php esc_html_e('Piso', 'travel-agency-platform'); ?></label>
                        <input type="number" id="<?php echo esc_attr($form_id); ?>-floor" name="floor" min="-5" max="200" value="<?php echo esc_attr($meta('_tap_room_floor', '0')); ?>">
                    </div>
                    <div class="tap-field">
                        <label for="<?php echo esc_attr($form_id); ?>-amenities"><?php esc_html_e('Comodidades', 'travel-agency-platform'); ?></label>
                        <input type="text" id="<?php echo esc_attr($form_id); ?>-amenities" name="amenities"
                                placeholder="<?php esc_attr_e('WiFi, Aire acondicionado, Desayuno', 'travel-agency-platform'); ?>"
                                value="<?php echo esc_attr($amenity); ?>">
                        <span class="tap-field-hint"><?php esc_html_e('Separadas por coma.', 'travel-agency-platform'); ?></span>
                    </div>
                </div>

                <div class="tap-unit-subsection">
                    <h4 class="tap-subsection-title"><?php esc_html_e('Camas', 'travel-agency-platform'); ?></h4>
                    <div class="tap-beds-rows" data-beds>
                        <?php foreach ($beds as $bed): ?>
                            <div class="tap-bed-row">
                                <select class="tap-bed-type" aria-label="<?php esc_attr_e('Tipo de cama', 'travel-agency-platform'); ?>">
                                    <?php foreach (self::acc_bed_labels() as $bt => $bl): ?>
                                        <option value="<?php echo esc_attr($bt); ?>" <?php selected($bt, $bed['type'] ?? ''); ?>><?php echo esc_html($bl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="tap-bed-count tap-num-input" min="1" step="1" value="<?php echo esc_attr($bed['count'] ?? 1); ?>" aria-label="<?php esc_attr_e('Cantidad', 'travel-agency-platform'); ?>">
                                <button type="button" class="tap-bed-remove" aria-label="<?php esc_attr_e('Quitar cama', 'travel-agency-platform'); ?>">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="tap-btn tap-btn-sm tap-bed-add">+ <?php esc_html_e('Añadir cama', 'travel-agency-platform'); ?></button>
                    <input type="hidden" name="_tap_room_beds" class="tap-beds-json">
                </div>

                <div class="tap-unit-subsection">
                    <h4 class="tap-subsection-title"><?php esc_html_e('Fotos de la unidad', 'travel-agency-platform'); ?></h4>
                    <?php
                    echo self::render_image_uploader($room_id, $thumb_id, $gallery, [
                        'main_id'      => 'room_attachment_id',
                        'gallery_ids'  => 'gallery_attachment_ids',
                        'main_url'     => 'room_thumbnail_url',
                        'gallery_urls' => 'gallery_urls',
                        'listing_id'   => (int) $acc_id,
                        'listing_type' => 'tap_accommodation',
                        'parent_id'    => $room_id,
                    ]);
                    ?>
                </div>

                <div class="tap-unit-subsection tap-unit-footer">
                    <?php if (!$is_new): ?>
                        <label class="tap-switch" for="<?php echo esc_attr($form_id); ?>-active">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" id="<?php echo esc_attr($form_id); ?>-active" name="is_active" value="1" <?php checked($active); ?>>
                            <span class="tap-switch-track" aria-hidden="true"></span>
                            <span><?php esc_html_e('Unidad activa', 'travel-agency-platform'); ?></span>
                        </label>
                    <?php endif; ?>
                    <div class="tap-unit-actions">
                        <?php if (!$is_new): ?>
                            <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-delete-room"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                        <?php endif; ?>
                        <button type="submit" class="tap-btn tap-btn-primary tap-btn-sm">
                            <?php echo $is_new ? esc_html__('Añadir unidad', 'travel-agency-platform') : esc_html__('Guardar unidad', 'travel-agency-platform'); ?>
                        </button>
                    </div>
                    <p class="tap-form-msg" role="status" aria-live="polite"></p>
                </div>
            </div>
        </div>
        </form>
        <?php
        return ob_get_clean();
    }



    public static function acc_bed_labels() {
        $map = [
            'king'   => __('King', 'travel-agency-platform'),
            'queen'  => __('Queen', 'travel-agency-platform'),
            'double' => __('Doble', 'travel-agency-platform'),
            'twin'   => __('Twin', 'travel-agency-platform'),
            'bunk'   => __('Literas', 'travel-agency-platform'),
            'sofa'   => __('Cama sofá', 'travel-agency-platform'),
            'crib'   => __('Cuna', 'travel-agency-platform'),
            'murphy' => __('Murphy', 'travel-agency-platform'),
            'futon'  => __('Futón', 'travel-agency-platform'),
        ];
        $types = TAP_Post_Types::bed_types();
        $out   = [];
        foreach ($types as $key => $label) {
            $out[$key] = isset($map[$key]) ? $map[$key] : $label;
        }
        return $out;
    }



    public static function render_image_uploader($post_id, $main_id, $gallery_ids, $names) {
        // $names may carry the ownership context for unit uploaders:
        //   listing_id / listing_type -> checked by the endpoint
        //   parent_id                 -> attachment parent (defaults to post_id)
        $listing_id   = isset($names['listing_id']) ? (int) $names['listing_id'] : (int) $post_id;
        $listing_type = isset($names['listing_type']) ? $names['listing_type'] : 'tap_accommodation';
        $parent_id    = isset($names['parent_id']) ? (int) $names['parent_id'] : (int) $post_id;
        $items = [];
        $thumb = $main_id ? wp_get_attachment_image_url((int) $main_id, 'medium') : '';
        $url   = $main_id ? wp_get_attachment_url((int) $main_id) : '';
        if ($main_id && $url) {
            $items[] = ['id' => (int) $main_id, 'url' => $url, 'thumb' => $thumb ?: $url];
        }
        foreach ((array) $gallery_ids as $gid) {
            $gid  = (int) $gid;
            $gurl = $gid ? wp_get_attachment_url($gid) : '';
            if (!$gurl) {
                continue;
            }
            $gthumb = wp_get_attachment_image_url($gid, 'medium');
            $items[] = ['id' => $gid, 'url' => $gurl, 'thumb' => $gthumb ?: $gurl];
        }
        $initial = wp_json_encode($items);
        $max_mb  = size_format(TAP_Agency_Media::agency_image_max_bytes());
        ob_start();
        ?>
        <div class="tap-uploader"
            data-post-id="<?php echo (int) $listing_id; ?>"
            data-parent-id="<?php echo (int) $parent_id; ?>"
            data-post-type="<?php echo esc_attr($listing_type); ?>"
            data-initial="<?php echo esc_attr($initial); ?>"
            data-max-bytes="<?php echo (int) TAP_Agency_Media::agency_image_max_bytes(); ?>">
            <div class="tap-uploader-bar">
                <label class="tap-btn tap-btn-sm tap-uploader-pick">
                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
                    <?php esc_html_e('Subir imágenes', 'travel-agency-platform'); ?>
                </label>
                <small class="tap-uploader-hint">
                    <?php
                    printf(
                        /* translators: %s: max upload size */
                        esc_html__('JPG, PNG, WEBP o GIF · máx. %s', 'travel-agency-platform'),
                        esc_html($max_mb)
                    );
                    ?>
                </small>
                <button type="button" class="tap-uploader-url-toggle"><?php esc_html_e('Usar una URL', 'travel-agency-platform'); ?></button>
            </div>

            <div class="tap-uploader-drop" role="button" tabindex="0"
                aria-label="<?php esc_attr_e('Arrastra aquí tus imágenes o pulsa para elegir archivos', 'travel-agency-platform'); ?>">
                <?php esc_html_e('Arrastra tus imágenes aquí o pulsa para elegir archivos', 'travel-agency-platform'); ?>
            </div>

            <div class="tap-uploader-url-wrap">
                <input type="url" class="tap-uploader-url" placeholder="https://…">
                <button type="button" class="tap-btn tap-btn-sm tap-uploader-url-add"><?php esc_html_e('Añadir', 'travel-agency-platform'); ?></button>
            </div>

            <div class="tap-uploader-grid"></div>

            <input type="hidden" class="tap-uploader-main" data-role="main-id" data-name="<?php echo esc_attr($names['main_id']); ?>">
            <input type="hidden" class="tap-uploader-gallery" data-role="gallery-ids" data-name="<?php echo esc_attr($names['gallery_ids']); ?>">
            <input type="hidden" class="tap-uploader-main-url" data-role="main-url" data-name="<?php echo esc_attr($names['main_url']); ?>">
            <textarea class="tap-uploader-gallery-urls" data-role="gallery-urls" data-name="<?php echo esc_attr($names['gallery_urls']); ?>" hidden></textarea>

            <p class="tap-uploader-progress" role="status" aria-live="polite"></p>
            <p class="tap-uploader-hint tap-uploader-hint-block">
                <?php esc_html_e('La primera imagen será la foto principal. Puedes cambiarla en cualquier momento con la estrella.', 'travel-agency-platform'); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }



}