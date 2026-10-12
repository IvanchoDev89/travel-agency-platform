<?php
defined('ABSPATH') || exit;

/**
 * Four-step agency listing wizard: progress bar, the info/rooms/calendar/\n * policies/publish steps, the room form and its shared field and label\n * helpers. Pure view code (ob_start plus HTML), split out of\n * TAP_Listing_Editor so that file keeps only the router and the editor shell.
 */
class TAP_Acc_Wizard {
    public static function render_acc_step_publish($listing_id) {
        if (!$listing_id) {
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Guarda primero la información del alojamiento.', 'travel-agency-platform') . '</p></div>';
        }
        $listing   = get_post($listing_id);
        $rooms     = self::acc_rooms_for($listing_id);
        $progress  = self::acc_wizard_progress($listing_id, 'tap_accommodation');
        $is_live   = 'publish' === get_post_status($listing_id);
        $can_publish = current_user_can('publish_posts') || current_user_can('manage_options');
        $prices    = array_map(function ($r) {
            return (float) get_post_meta($r->ID, '_tap_room_price_per_night', true);
        }, $rooms);
        $min_p     = $prices ? min($prices) : 0;
        $max_p     = $prices ? max($prices) : 0;
        $currency  = strtoupper((string) get_post_meta($listing_id, '_tap_acc_currency', true));
        $symbol    = $currency ? TAP_Currency::plain_symbol($currency) : TAP_Currency::plain_symbol();
        $decimals  = TAP_Currency::decimals($currency ?: null);
        $fmt       = function ($amount) use ($symbol, $decimals) {
            return trim($symbol . ' ' . number_format((float) $amount, (int) $decimals));
        };
        $policy    = get_post_meta($listing_id, '_tap_acc_cancellation', true);
        $policies  = TAP_Refunds::cancellation_policies();
        $mode      = TAP_Booking::booking_mode('tap_accommodation', $listing_id);
        $rules     = trim((string) get_post_meta($listing_id, '_tap_acc_house_rules', true));
        $thumb     = get_post_thumbnail_id($listing_id);
        $edit_base = home_url('/manage-listing/?id=' . (int) $listing_id);
        $missing   = array_values(array_filter($progress['items'], function ($item) {
            return 1 === $item['step'] && !$item['done'];
        }));
        $not_ready = (int) ($progress['percent'] < 100);
        ob_start();
        ?>
        <div class="tap-wizard-section">
            <p class="tap-section-lead"><?php esc_html_e('Revisa cómo se verá tu alojamiento y publícalo cuando esté listo.', 'travel-agency-platform'); ?></p>

            <section class="tap-card-block">
                <div class="tap-block-head">
                    <div>
                        <h3 class="tap-block-title"><?php esc_html_e('Resumen', 'travel-agency-platform'); ?></h3>
                        <p class="tap-block-sub"><?php esc_html_e('Comprueba los datos que aparecerán en la web.', 'travel-agency-platform'); ?></p>
                    </div>
                    <?php if ($is_live): ?>
                        <span class="tap-chip tap-chip-success"><?php esc_html_e('Publicado', 'travel-agency-platform'); ?></span>
                    <?php elseif ('pending' === get_post_status($listing_id)): ?>
                        <span class="tap-chip tap-chip-muted"><?php esc_html_e('En revisión', 'travel-agency-platform'); ?></span>
                    <?php else: ?>
                        <span class="tap-chip tap-chip-muted"><?php esc_html_e('Borrador', 'travel-agency-platform'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="tap-review-preview">
                    <div class="tap-review-media">
                        <?php if ($thumb): ?>
                            <?php echo wp_get_attachment_image($thumb, 'medium', false, ['loading' => 'lazy', 'alt' => '']); ?>
                        <?php else: ?>
                            <span class="tap-review-media-empty"><?php esc_html_e('Sin foto principal', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tap-review-rows">
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html($listing ? $listing->post_title : ''); ?></strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Unidades', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(number_format_i18n(count($rooms))); ?></strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Precio por noche', 'travel-agency-platform'); ?></span>
                            <strong>
                            <?php
                            echo $prices
                                ? esc_html($fmt($min_p) . ($max_p > $min_p ? ' – ' . $fmt($max_p) : ''))
                                : '—';
							?>
                                </strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Días con precio especial', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(number_format_i18n($progress['overrides'])); ?></strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></span>
                            <strong>
                            <?php
                            echo esc_html('request' === $mode
                                ? __('Solicitud: la agencia confirma', 'travel-agency-platform')
                                : __('Reserva directa', 'travel-agency-platform'));
							?>
                                </strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Cancelación', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(isset($policies[$policy]) ? $policies[$policy] : '—'); ?></strong>
                        </div>
                        <div class="tap-review-row">
                            <span><?php esc_html_e('Reglas de la casa', 'travel-agency-platform'); ?></span>
                            <strong><?php echo '' !== $rules ? esc_html__('Definidas', 'travel-agency-platform') : esc_html__('Sin definir', 'travel-agency-platform'); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="tap-review-actions">
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=1'); ?>"><?php esc_html_e('Editar información', 'travel-agency-platform'); ?></a>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=2'); ?>"><?php esc_html_e('Editar unidades', 'travel-agency-platform'); ?></a>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=3'); ?>"><?php esc_html_e('Editar precios', 'travel-agency-platform'); ?></a>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=4'); ?>"><?php esc_html_e('Editar políticas', 'travel-agency-platform'); ?></a>
                    <?php if ($is_live): ?>
                        <a class="tap-btn tap-btn-primary tap-btn-sm" href="<?php echo esc_url(get_permalink($listing_id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver en la web', 'travel-agency-platform'); ?> &nearr;</a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="tap-card-block">
                <div class="tap-block-head">
                    <div>
                        <h3 class="tap-block-title"><?php esc_html_e('Antes de publicar', 'travel-agency-platform'); ?></h3>
                        <p class="tap-block-sub"><?php esc_html_e('Comprueba que la ficha está completa para conseguir reservas.', 'travel-agency-platform'); ?></p>
                    </div>
                    <span class="tap-progress-ring-label"><?php echo (int) $progress['percent']; ?>%</span>
                </div>
                <ul class="tap-checklist">
                    <?php foreach ($progress['items'] as $item): ?>
                        <li class="tap-check<?php echo $item['done'] ? ' is-done' : ''; ?>">
                            <span class="tap-check-mark" aria-hidden="true"><?php echo $item['done'] ? '&#10003;' : '&#9679;'; ?></span>
                            <span class="tap-check-text">
                                <a href="<?php echo esc_url($item['step'] > 1 ? $edit_base . '&step=' . (int) $item['step'] : $edit_base . '&step=1'); ?>"><?php echo esc_html($item['label']); ?></a>
                                <?php if ('' !== $item['detail']): ?><span class="tap-check-hint"><?php echo esc_html($item['detail']); ?></span><?php endif; ?>
                            </span>
                            <span class="tap-check-state">
                            <?php
                            echo $item['done']
                                ? esc_html__('Listo', 'travel-agency-platform')
                                : esc_html__('Pendiente', 'travel-agency-platform');
							?>
                                </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="tap-card-block tap-publish-box<?php echo $is_live ? ' is-live' : ''; ?>">
                <div class="tap-publish-inner">
                    <div>
                        <h3 class="tap-block-title">
                        <?php
                        echo $is_live
                            ? esc_html__('Tu alojamiento está publicado', 'travel-agency-platform')
                            : esc_html__('Publica tu alojamiento', 'travel-agency-platform');
						?>
                            </h3>
                        <p class="tap-block-sub">
                            <?php if ($is_live): ?>
                                <?php esc_html_e('Los viajeros ya pueden encontrarlo y reservar. Puedes despublicarlo en cualquier momento.', 'travel-agency-platform'); ?>
                            <?php elseif (!$can_publish): ?>
                                <?php esc_html_e('Tu alojamiento se enviará a revisión y el equipo lo aprobará antes de publicarlo.', 'travel-agency-platform'); ?>
                            <?php elseif ($not_ready): ?>
                                <?php esc_html_e('Aún faltan algunos pasos por completar, pero puedes publicar igualmente.', 'travel-agency-platform'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Todo listo. Publica cuando quieras: aparecerá en el buscador al instante.', 'travel-agency-platform'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="tap-publish-actions">
                        <button type="button" class="tap-btn <?php echo $is_live ? 'tap-btn-danger' : 'tap-btn-primary'; ?>" data-publish="<?php echo $is_live ? '0' : '1'; ?>">
                            <?php
                            echo $is_live
                                ? esc_html__('Despublicar', 'travel-agency-platform')
                                : esc_html__('Publicar alojamiento', 'travel-agency-platform');
							?>
                        </button>
                        <?php if (!$is_live && !$can_publish): ?>
                            <span class="tap-field-hint"><?php esc_html_e('Se enviará a moderación.', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($missing): ?>
                    <p class="tap-field-hint"><?php esc_html_e('Recuerda revisar: ', 'travel-agency-platform'); ?>
                        <?php
                        echo esc_html(implode(', ', array_map(function ($item) {
							return $item['label'];
						}, $missing)));
						?>
                    </p>
                <?php endif; ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }


    public static function acc_cancellation_hint($key) {
        $hints = [
            'flexible'       => __('Cancelación gratuita hasta 24 h antes de la llegada.', 'travel-agency-platform'),
            'moderate'       => __('Cancelación gratuita hasta 5 días antes de la llegada.', 'travel-agency-platform'),
            'strict'         => __('50% de reembolso hasta 7 días antes de la llegada.', 'travel-agency-platform'),
            'non_refundable' => __('Sin reembolso una vez confirmada la reserva.', 'travel-agency-platform'),
        ];
        return isset($hints[$key]) ? $hints[$key] : '';
    }


    public static function render_acc_step_policies($listing_id) {
        if (!$listing_id) {
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Guarda primero la información del alojamiento.', 'travel-agency-platform') . '</p></div>';
        }
        $listing = get_post($listing_id);
        $policy  = (string) get_post_meta($listing_id, '_tap_acc_cancellation', true);
        $rules   = (string) get_post_meta($listing_id, '_tap_acc_house_rules', true);
        $policies = TAP_Refunds::cancellation_policies();
        if ('' === $policy && isset($policies['flexible'])) {
            $policy = 'flexible';
        }
        ob_start();
        ?>
        <div class="tap-wizard-section">
            <p class="tap-section-lead"><?php esc_html_e('Estas condiciones se muestran al viajero antes de reservar y definen los reembolsos en caso de cancelación.', 'travel-agency-platform'); ?></p>

            <form class="tap-manage-form" id="tap-policies-form">
                <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                <input type="hidden" name="listing_id" value="<?php echo (int) $listing_id; ?>">
                <input type="hidden" name="listing_type" value="tap_accommodation">
                <input type="hidden" name="title" value="<?php echo esc_attr($listing ? $listing->post_title : ''); ?>">
                <input type="hidden" name="description" value="<?php echo esc_attr($listing ? $listing->post_content : ''); ?>">

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Política de cancelación', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Elige cuánto tiempo tiene el viajero para cancelar sin penalización.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-policy-options" role="radiogroup" aria-label="<?php esc_attr_e('Política de cancelación', 'travel-agency-platform'); ?>">
                        <?php foreach ($policies as $key => $label): ?>
                            <?php $hint = self::acc_cancellation_hint($key); ?>
                            <label class="tap-policy-option">
                                <input type="radio" name="_tap_acc_cancellation" value="<?php echo esc_attr($key); ?>" <?php checked($policy, $key); ?>>
                                <span class="tap-policy-body">
                                    <strong><?php echo esc_html($label); ?></strong>
                                    <?php if ($hint): ?><span class="tap-policy-hint"><?php echo esc_html($hint); ?></span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="tap-field-hint"><?php esc_html_e('Esta política se aplica automáticamente a todas las unidades del alojamiento.', 'travel-agency-platform'); ?></p>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Reglas de la casa', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Normas de convivencia, check-in, mascotas, fumadores… Aparecerán en la ficha del alojamiento.', 'travel-agency-platform'); ?></p>
                        </div>
                        <span class="tap-char-count" data-count-for="tap-house-rules" data-count-max="1000"></span>
                    </div>
                    <div class="tap-field">
                        <label class="tap-sr-only" for="tap-house-rules"><?php esc_html_e('Reglas de la casa', 'travel-agency-platform'); ?></label>
                        <textarea id="tap-house-rules" name="_tap_acc_house_rules" rows="7" maxlength="1000"
                                    placeholder="<?php esc_attr_e('Una regla por línea. p.ej.', 'travel-agency-platform'); ?>&#10;<?php esc_attr_e('Check-in desde las 15:00 y check-out antes de las 11:00', 'travel-agency-platform'); ?>&#10;<?php esc_attr_e('No se permiten mascotas', 'travel-agency-platform'); ?>&#10;<?php esc_attr_e('No se fuma en interiores', 'travel-agency-platform'); ?>"><?php echo esc_textarea($rules); ?></textarea>
                        <span class="tap-field-hint"><?php esc_html_e('Separa cada regla con un punto y coma o una línea nueva.', 'travel-agency-platform'); ?></span>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Vista previa', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Así verá el viajero estas condiciones antes de reservar.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-policy-preview">
                        <div class="tap-preview-block">
                            <h4><?php esc_html_e('Cancelación', 'travel-agency-platform'); ?></h4>
                            <p><?php echo esc_html(isset($policies[$policy]) ? $policies[$policy] : '—'); ?></p>
                        </div>
                        <div class="tap-preview-block">
                            <h4><?php esc_html_e('Reglas de la casa', 'travel-agency-platform'); ?></h4>
                            <?php if ('' !== trim($rules)): ?>
                                <ul class="tap-preview-list">
                                    <?php foreach (preg_split('/\r\n|\r|\n|;/', $rules) as $rule): ?>
                                        <?php $rule = trim($rule); ?>
                                        <?php if ('' !== $rule): ?><li><?php echo esc_html($rule); ?></li><?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="tap-preview-empty"><?php esc_html_e('Aún no has escrito reglas de la casa.', 'travel-agency-platform'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <p class="tap-form-msg" role="status" aria-live="polite"></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }


    public static function render_acc_step_calendar($listing_id) {
        if (!$listing_id) {
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Guarda primero la información del alojamiento.', 'travel-agency-platform') . '</p></div>';
        }
        $rooms = self::acc_rooms_for($listing_id);
        if (!$rooms) {
            $rooms_url = home_url('/manage-listing/?id=' . (int) $listing_id . '&step=2');
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Necesitas al menos una unidad para configurar precios y disponibilidad.', 'travel-agency-platform') . '</p><a class="tap-btn tap-btn-primary" href="' . esc_url($rooms_url) . '">' . esc_html__('Ir a Unidades', 'travel-agency-platform') . '</a></div>';
        }

        wp_enqueue_script('tap-agency-wizard', TAP_PLUGIN_URL . 'assets/js/agency-wizard.js', [], TAP_VERSION, true);
        wp_enqueue_script('tap-agency-calendar', TAP_PLUGIN_URL . 'assets/js/agency-calendar.js', [], TAP_VERSION, true);
        list($currency_code, $currency_symbol, $currency_decimals) = self::wizard_currency($listing_id);

        $month_names = [
            __('Enero', 'travel-agency-platform'), __('Febrero', 'travel-agency-platform'), __('Marzo', 'travel-agency-platform'),
            __('Abril', 'travel-agency-platform'), __('Mayo', 'travel-agency-platform'), __('Junio', 'travel-agency-platform'),
            __('Julio', 'travel-agency-platform'), __('Agosto', 'travel-agency-platform'), __('Septiembre', 'travel-agency-platform'),
            __('Octubre', 'travel-agency-platform'), __('Noviembre', 'travel-agency-platform'), __('Diciembre', 'travel-agency-platform'),
        ];
        $dow_labels = [__('Dom', 'travel-agency-platform'), __('Lun', 'travel-agency-platform'), __('Mar', 'travel-agency-platform'),
            __('Mié', 'travel-agency-platform'), __('Jue', 'travel-agency-platform'), __('Vie', 'travel-agency-platform'), __('Sáb', 'travel-agency-platform'),];

        wp_localize_script('tap-agency-calendar', 'tapAgencyCal', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('tap_front_dash_nonce'),
            'currency'  => $currency_symbol,
            'decimals'  => $currency_decimals,
            'rooms'     => array_map(function ($r) {
                return [
                    'id'        => (int) $r->ID,
                    'title'     => $r->post_title,
                    'price'     => (float) get_post_meta($r->ID, '_tap_room_price_per_night', true),
                    'min_stay'  => (int) get_post_meta($r->ID, '_tap_room_min_stay', true),
                    'inventory' => max(1, (int) get_post_meta($r->ID, '_tap_room_inventory', true)),
                ];
            }, $rooms),
            'i18n' => [
                'months'          => $month_names,
                'dow'             => $dow_labels,
                'base'            => __('Base', 'travel-agency-platform'),
                'override'        => __('Modificado', 'travel-agency-platform'),
                'blocked'         => __('Bloqueado', 'travel-agency-platform'),
                'blockedShort'    => __('Bloqueado', 'travel-agency-platform'),
                'booked'          => __('Ocupado', 'travel-agency-platform'),
                'available'       => __('disponible(s)', 'travel-agency-platform'),
                'nights'          => __('noches', 'travel-agency-platform'),
                'minStay'         => __('mín.', 'travel-agency-platform'),
                'basePriceIs'     => __('precio base', 'travel-agency-platform'),
                'overrides'       => __('días ajustados', 'travel-agency-platform'),
                'oneOverride'     => __('1 día ajustado', 'travel-agency-platform'),
                'price'           => __('Precio por noche', 'travel-agency-platform'),
                'availability'    => __('Disponibilidad', 'travel-agency-platform'),
                'useBase'         => __('Usar base', 'travel-agency-platform'),
                'block'           => __('Bloquear estas fechas', 'travel-agency-platform'),
                'label'           => __('Etiqueta (temporada)', 'travel-agency-platform'),
                'labelPlaceholder'=> __('p.ej. Temporada Alta', 'travel-agency-platform'),
                'emptyUsesBase'   => __('Vacío = usar la base.', 'travel-agency-platform'),
                'clearDay'        => __('Eliminar ajuste', 'travel-agency-platform'),
                'save'            => __('Guardar', 'travel-agency-platform'),
                'saved'           => __('Guardado', 'travel-agency-platform'),
                'copy'            => __('Copiar', 'travel-agency-platform'),
                'copyMonth'       => __('Copiar mes', 'travel-agency-platform'),
                'copyModeAll'     => __('Todo el mes (precios y ajustes)', 'travel-agency-platform'),
                'copyModeOverrides'=> __('Solo días con ajustes', 'travel-agency-platform'),
                'copyTarget'      => __('Mes destino', 'travel-agency-platform'),
                'copySource'      => __('Mes de origen', 'travel-agency-platform'),
                'daysTo'          => __('días se copiarán a', 'travel-agency-platform'),
                'copiedFrom'      => __('copiado desde', 'travel-agency-platform'),
                'skipped'         => __('omitidos (mes más corto)', 'travel-agency-platform'),
                'nothingToCopy'   => __('No hay días que copiar.', 'travel-agency-platform'),
                'nothingToClear'  => __('Este mes no tiene ajustes.', 'travel-agency-platform'),
                'clearMonth'      => __('Limpiar mes', 'travel-agency-platform'),
                'clear'           => __('Limpiar', 'travel-agency-platform'),
                'daysWillReset'   => __('días volverán al precio base', 'travel-agency-platform'),
                'bulkTools'       => __('Herramientas para varios días', 'travel-agency-platform'),
                'bulkToolsHint'   => __('Aplica el mismo cambio a un rango, a los días de la semana que elijas o a otro mes.', 'travel-agency-platform'),
                'clearMonthLabel' => __('Zona dangerous', 'travel-agency-platform'),
                'badRange'        => __('Revisa el rango de fechas.', 'travel-agency-platform'),
                'badAmount'       => __('Indica un importe o porcentaje mayor que 0.', 'travel-agency-platform'),
                'noOverrideTarget'=> __('Selecciona al menos un día de la semana.', 'travel-agency-platform'),
                'clickDayHint'    => __('Haz clic en cualquier día para editar su precio, etiqueta o bloquearlo.', 'travel-agency-platform'),
                'error'           => __('Error', 'travel-agency-platform'),
                'networkError'    => __('Error de conexión.', 'travel-agency-platform'),
                'loading'         => __('Cargando calendario…', 'travel-agency-platform'),
            ],
        ]);

        ob_start();
        ?>
        <div class="tap-wizard-section">
            <div class="tap-cal-intro">
                <p class="tap-section-lead"><?php esc_html_e('Define el precio de cada noche y abre o cierra la disponibilidad. Los días que no toques heredan el precio base de la unidad.', 'travel-agency-platform'); ?></p>
                <p class="tap-ac-hint"><?php esc_html_e('Haz clic en cualquier día para editar su precio, etiqueta o bloquearlo.', 'travel-agency-platform'); ?></p>
            </div>

            <div id="tap-ac-cal" class="tap-ac-cal">
                <div class="tap-ac-cal-head">
                    <div class="tap-ac-room-tabs" id="tap-ac-room-tabs" role="tablist" aria-label="<?php esc_attr_e('Unidades', 'travel-agency-platform'); ?>"></div>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/?id=' . (int) $listing_id . '&step=2')); ?>">+ <?php esc_html_e('Añadir unidad', 'travel-agency-platform'); ?></a>
                </div>

                <div class="tap-ac-cal-body">
                    <div class="tap-ac-cal-main">
                        <div class="tap-ac-month-nav">
                            <div class="tap-ac-month-switch">
                                <button type="button" class="tap-btn tap-btn-sm tap-ac-prev" aria-label="<?php esc_attr_e('Mes anterior', 'travel-agency-platform'); ?>">&larr;</button>
                                <span class="tap-ac-month-label" aria-live="polite">--</span>
                                <button type="button" class="tap-btn tap-btn-sm tap-ac-next" aria-label="<?php esc_attr_e('Mes siguiente', 'travel-agency-platform'); ?>">&rarr;</button>
                            </div>
                            <span class="tap-ac-legend">
                                <span><span class="tap-ac-swatch swatch-base"></span> <?php esc_html_e('Base', 'travel-agency-platform'); ?></span>
                                <span><span class="tap-ac-swatch swatch-override"></span> <?php esc_html_e('Modificado', 'travel-agency-platform'); ?></span>
                                <span><span class="tap-ac-swatch swatch-blocked"></span> <?php esc_html_e('Bloqueado', 'travel-agency-platform'); ?></span>
                            </span>
                        </div>
                        <div class="tap-ac-summary">
                            <span class="tap-ac-room-label"></span>
                            <span class="tap-ac-base-info"></span>
                            <span class="tap-ac-override-count"></span>
                        </div>
                        <div class="tap-ac-calendar" id="tap-ac-calendar"><div class="tap-ac-loading"><?php esc_html_e('Cargando calendario…', 'travel-agency-platform'); ?></div></div>
                    </div>

                    <div class="tap-ac-cal-side">
                        <div class="tap-ac-panel">
                            <div class="tap-ac-panel-title"><strong><?php esc_html_e('Fijar rango', 'travel-agency-platform'); ?></strong></div>
                            <div class="tap-ac-range-group">
                                <label><?php esc_html_e('Desde', 'travel-agency-platform'); ?><input type="date" class="tap-ac-field tap-ac-range-from"></label>
                                <label><?php esc_html_e('Hasta', 'travel-agency-platform'); ?><input type="date" class="tap-ac-field tap-ac-range-to"></label>
                            </div>
                            <label><?php esc_html_e('Precio', 'travel-agency-platform'); ?> <span class="tap-ac-currency">(<?php echo esc_html($currency_symbol); ?>)</span></label>
                            <input type="number" step="0.01" min="0" class="tap-ac-field tap-ac-range-price" placeholder="<?php esc_attr_e('vacío = no cambiar', 'travel-agency-platform'); ?>">
                            <label><?php esc_html_e('Estadía mínima', 'travel-agency-platform'); ?></label>
                            <input type="number" min="0" step="1" class="tap-ac-field tap-ac-range-minstay" placeholder="<?php esc_attr_e('vacío = no cambiar', 'travel-agency-platform'); ?>">
                            <label><?php esc_html_e('Disponibilidad', 'travel-agency-platform'); ?></label>
                            <select class="tap-ac-field tap-ac-range-avail">
                                <option value=""><?php esc_html_e('No cambiar', 'travel-agency-platform'); ?></option>
                                <option value="yes"><?php esc_html_e('Disponible', 'travel-agency-platform'); ?></option>
                                <option value="no"><?php esc_html_e('Bloquear', 'travel-agency-platform'); ?></option>
                            </select>
                            <label><?php esc_html_e('Etiqueta', 'travel-agency-platform'); ?></label>
                            <input type="text" maxlength="100" class="tap-ac-field tap-ac-range-label" placeholder="<?php esc_attr_e('vacío = no cambiar', 'travel-agency-platform'); ?>">
                            <div class="tap-ac-actions" id="tap-ac-range-actions">
                                <button type="button" class="tap-btn tap-btn-primary tap-btn-sm tap-ac-range-apply"><?php esc_html_e('Aplicar rango', 'travel-agency-platform'); ?></button>
                                <span class="tap-ac-msg" role="status" aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="tap-ac-panel">
                            <div class="tap-ac-panel-title"><strong><?php esc_html_e('Regla por día de semana', 'travel-agency-platform'); ?></strong></div>
                            <p class="tap-ac-hint"><?php esc_html_e('Se aplica a todos los días de la semana elegidos dentro del mes visible.', 'travel-agency-platform'); ?></p>
                            <div class="tap-ac-dow-group" id="tap-ac-dow">
                                <?php
                                $dow_short = [1 => __('Lun', 'travel-agency-platform'), 2 => __('Mar', 'travel-agency-platform'), 3 => __('Mié', 'travel-agency-platform'), 4 => __('Jue', 'travel-agency-platform'), 5 => __('Vie', 'travel-agency-platform'), 6 => __('Sáb', 'travel-agency-platform'), 7 => __('Dom', 'travel-agency-platform')];
                                foreach ($dow_short as $d => $dl):
									?>
                                    <label class="tap-ac-dow"><input type="checkbox" value="<?php echo (int) $d; ?>"> <?php echo esc_html($dl); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <label><?php esc_html_e('Operación', 'travel-agency-platform'); ?></label>
                            <select class="tap-ac-field tap-ac-dow-op">
                                <option value="set"><?php esc_html_e('Fijar precio', 'travel-agency-platform'); ?></option>
                                <option value="add_pct"><?php esc_html_e('Sumar % sobre el precio', 'travel-agency-platform'); ?></option>
                                <option value="sub_pct"><?php esc_html_e('Restar % sobre el precio', 'travel-agency-platform'); ?></option>
                                <option value="clear"><?php esc_html_e('Limpiar ajuste del día', 'travel-agency-platform'); ?></option>
                            </select>
                            <label><?php esc_html_e('Cantidad', 'travel-agency-platform'); ?> <span class="tap-ac-currency">(<?php echo esc_html($currency_symbol); ?><?php esc_html_e(' o %)', 'travel-agency-platform'); ?></span></label>
                            <input type="number" step="0.01" min="0" class="tap-ac-field tap-ac-dow-amount">
                            <label><?php esc_html_e('Estadía mínima', 'travel-agency-platform'); ?></label>
                            <input type="number" min="0" step="1" class="tap-ac-field tap-ac-dow-minstay" placeholder="<?php esc_attr_e('vacío = no cambiar', 'travel-agency-platform'); ?>">
                            <label><?php esc_html_e('Disponibilidad', 'travel-agency-platform'); ?></label>
                            <select class="tap-ac-field tap-ac-dow-avail">
                                <option value=""><?php esc_html_e('No cambiar', 'travel-agency-platform'); ?></option>
                                <option value="yes"><?php esc_html_e('Disponible', 'travel-agency-platform'); ?></option>
                                <option value="no"><?php esc_html_e('Bloquear', 'travel-agency-platform'); ?></option>
                            </select>
                            <div class="tap-ac-actions" id="tap-ac-dow-actions">
                                <button type="button" class="tap-btn tap-btn-primary tap-btn-sm tap-ac-dow-apply"><?php esc_html_e('Aplicar regla', 'travel-agency-platform'); ?></button>
                                <span class="tap-ac-msg" role="status" aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="tap-ac-panel">
                            <div class="tap-ac-panel-title"><strong><?php esc_html_e('Copiar mes', 'travel-agency-platform'); ?></strong></div>
                            <p class="tap-ac-hint"><?php esc_html_e('Replica los días del mes visible en otro mes.', 'travel-agency-platform'); ?></p>
                            <label><?php esc_html_e('Copiar', 'travel-agency-platform'); ?></label>
                            <select class="tap-ac-field tap-ac-copy-mode">
                                <option value="all"><?php esc_html_e('Todo el mes (precios y ajustes)', 'travel-agency-platform'); ?></option>
                                <option value="overrides"><?php esc_html_e('Solo días con ajustes', 'travel-agency-platform'); ?></option>
                            </select>
                            <label><?php esc_html_e('Mes destino', 'travel-agency-platform'); ?></label>
                            <select class="tap-ac-field tap-ac-copy-target"></select>
                            <div class="tap-ac-actions" id="tap-ac-copy-actions">
                                <button type="button" class="tap-btn tap-btn-primary tap-btn-sm tap-ac-copy-apply"><?php esc_html_e('Copiar mes', 'travel-agency-platform'); ?></button>
                                <span class="tap-ac-msg" role="status" aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="tap-ac-panel tap-ac-panel-danger">
                            <div class="tap-ac-panel-title"><strong><?php esc_html_e('Limpiar mes', 'travel-agency-platform'); ?></strong></div>
                            <p class="tap-ac-hint"><?php esc_html_e('Elimina todos los ajustes del mes visible y vuelve al precio base.', 'travel-agency-platform'); ?></p>
                            <div class="tap-ac-actions" id="tap-ac-clear-actions">
                                <button type="button" class="tap-btn tap-btn-danger tap-btn-sm tap-ac-clear-all"><?php esc_html_e('Limpiar ajustes del mes', 'travel-agency-platform'); ?></button>
                                <span class="tap-ac-msg" role="status" aria-live="polite"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    public static function render_acc_step_rooms($listing_id) {
        if (!$listing_id) {
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Guarda primero la información del alojamiento para poder añadir unidades.', 'travel-agency-platform') . '</p></div>';
        }
        $acc_id = (int) $listing_id;
        $rooms  = self::acc_rooms_for($acc_id);
        ob_start();
        ?>
        <div class="tap-wizard-section">
            <p class="tap-section-lead"><?php esc_html_e('Cada unidad es un tipo de habitación con su propio precio, inventario y fotos. Si todas tus habitaciones cuestan lo mismo, con una sola unidad es suficiente.', 'travel-agency-platform'); ?></p>

            <?php if (!$rooms): ?>
                <div class="tap-notice tap-notice-info">
                    <span class="tap-notice-icon" aria-hidden="true">i</span>
                    <span><?php esc_html_e('Aún no tienes unidades. Crea la primera con el formulario de abajo; podrás añadir las demás cuando quieras.', 'travel-agency-platform'); ?></span>
                </div>
            <?php endif; ?>

            <div class="tap-rooms-list">
                <?php
                foreach ($rooms as $room) {
                    echo self::render_room_form($room, $acc_id);
                }
                ?>
            </div>

            <section class="tap-card-block tap-card-block-cta" id="tap-room-new-block">
                <div class="tap-block-head">
                    <div>
                        <h3 class="tap-block-title"><?php esc_html_e('Añadir unidad', 'travel-agency-platform'); ?></h3>
                        <p class="tap-block-sub"><?php esc_html_e('Crea un tipo de habitación o unidad adicional.', 'travel-agency-platform'); ?></p>
                    </div>
                    <?php if ($rooms): ?>
                        <button type="button" class="tap-btn tap-btn-secondary" data-role="show-new-room" aria-controls="tap-room-add" aria-expanded="false">
                            + <?php esc_html_e('Añadir unidad', 'travel-agency-platform'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <?php echo self::render_room_form(null, $acc_id, !$rooms); ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }


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


    public static function render_acc_step_info($listing_id, $type) {
        $is_edit = (bool) $listing_id;
        $listing = $is_edit ? get_post($listing_id) : null;
        $prefix  = TAP_Post_Types::meta_prefix($type);
        $fields  = TAP_Metaboxes::get_fields($type);
        $labels  = self::acc_field_labels();
        list(, $currency_symbol) = self::wizard_currency($listing_id);

        /* Bucket the registry fields into the wizard groups. */
        $groups = [
            'identity'  => ['label' => __('Identidad del alojamiento', 'travel-agency-platform'), 'sub' => __('Lo primero que verá el viajero.', 'travel-agency-platform'), 'open' => true, 'keys' => ['_tap_' . $prefix . '_type', '_tap_' . $prefix . '_stars']],
            'location'  => ['label' => __('Ubicación', 'travel-agency-platform'), 'sub' => __('Destino y coordenadas para el mapa.', 'travel-agency-platform'), 'open' => true, 'keys' => ['_tap_' . $prefix . '_lat', '_tap_' . $prefix . '_lng']],
            'capacity'  => ['label' => __('Capacidad', 'travel-agency-platform'), 'sub' => __('Cuántas personas caben en total.', 'travel-agency-platform'), 'open' => false, 'keys' => ['_tap_' . $prefix . '_capacity', '_tap_' . $prefix . '_bedrooms', '_tap_' . $prefix . '_bathrooms', '_tap_' . $prefix . '_rooms']],
            'stay'      => ['label' => __('Llegada y salida', 'travel-agency-platform'), 'sub' => __('Horarios por defecto de check-in y check-out.', 'travel-agency-platform'), 'open' => false, 'keys' => ['_tap_' . $prefix . '_checkin_time', '_tap_' . $prefix . '_checkout_time']],
            'pricing'   => ['label' => __('Precio base', 'travel-agency-platform'), 'sub' => __('Suficiente con esto si todas tus unidades cuestan lo mismo.', 'travel-agency-platform'), 'open' => false, 'keys' => ['_tap_' . $prefix . '_price_per_night', '_tap_' . $prefix . '_currency']],
            'booking'   => ['label' => __('Reserva y visibilidad', 'travel-agency-platform'), 'sub' => __('Cómo reservan los viajeros y si aparece en el buscador.', 'travel-agency-platform'), 'open' => false, 'keys' => ['_tap_' . $prefix . '_is_active']],
        ];

        $used = [];
        foreach ($groups as $group) {
            foreach ($group['keys'] as $key) {
                $used[$key] = true;
            }
        }
        /* Anything a future registry adds still has to be reachable. */
        $extra = [];
        foreach ($fields as $key => $cfg) {
            if (isset($used[$key])) {
                continue;
            }
            if (false !== strpos($key, '_agency_id') || '_tap_seo_title' === $key || '_tap_seo_description' === $key) {
                continue;
            }
            $extra[] = $key;
        }
        if ($extra) {
            $groups['extra'] = [
                'label' => __('Otros detalles', 'travel-agency-platform'),
                'sub'   => __('Datos adicionales de tu alojamiento.', 'travel-agency-platform'),
                'open'  => false,
                'keys'  => $extra,
            ];
        }

        ob_start();
        ?>
        <form class="tap-manage-form" id="tap-listing-form" data-wizard="acc" data-step="1">
            <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
            <input type="hidden" name="listing_id" value="<?php echo (int) $listing_id; ?>">
            <input type="hidden" name="listing_type" value="<?php echo esc_attr($type); ?>">

            <?php if (!$is_edit): ?>
                <div class="tap-notice tap-notice-info">
                    <span class="tap-notice-icon" aria-hidden="true">i</span>
                    <span><?php esc_html_e('Crea el alojamiento con lo esencial. Podrás subir más fotos y detalle en los siguientes pasos.', 'travel-agency-platform'); ?></span>
                </div>
            <?php endif; ?>

            <!-- ── basics ────────────────────────────────────────────── -->
            <section class="tap-card-block">
                <div class="tap-field">
                    <label for="tap-acc-title"><?php esc_html_e('Nombre del alojamiento', 'travel-agency-platform'); ?> <span class="tap-req" aria-hidden="true">*</span></label>
                    <input type="text" id="tap-acc-title" name="title" required maxlength="120"
                            placeholder="<?php esc_attr_e('p.ej. Hotel Aurora – Habitación Vista Mar', 'travel-agency-platform'); ?>"
                            value="<?php echo esc_attr($listing ? $listing->post_title : ''); ?>">
                </div>
                <div class="tap-field">
                    <label for="tap-acc-description"><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                    <textarea id="tap-acc-description" name="description" rows="6" maxlength="2000"
                                placeholder="<?php esc_attr_e('Qué incluye, para quién es ideal, qué hace especial a tu alojamiento…', 'travel-agency-platform'); ?>"><?php echo esc_textarea($listing ? $listing->post_content : ''); ?></textarea>
                    <span class="tap-char-count" data-count-for="tap-acc-description" data-count-max="2000"></span>
                </div>
            </section>

            <!-- ── photos ────────────────────────────────────────────── -->
            <section class="tap-card-block">
                <div class="tap-block-head">
                    <div>
                        <h3 class="tap-block-title"><?php esc_html_e('Fotos', 'travel-agency-platform'); ?></h3>
                        <p class="tap-block-sub"><?php esc_html_e('Las imágenes son lo primero que atrae reservas. Sube al menos 3 fotos si puedes.', 'travel-agency-platform'); ?></p>
                    </div>
                </div>
                <?php
                $main_id     = $is_edit ? (int) get_post_thumbnail_id($listing_id) : 0;
                $gallery_ids = $is_edit
                    ? array_filter(array_map('trim', explode(',', (string) get_post_meta($listing_id, '_tap_' . $prefix . '_gallery', true))))
                    : [];
                echo self::render_image_uploader($listing_id, $main_id, $gallery_ids, [
                    'main_id'     => 'featured_attachment_id',
                    'gallery_ids' => 'gallery_attachment_ids',
                    'main_url'    => 'featured_image_url',
                    'gallery_urls' => 'gallery_urls',
                ]);
                ?>
            </section>

            <!-- ── grouped registry fields ───────────────────────────── -->
            <?php foreach ($groups as $gid => $group): ?>
                <?php
                $render_keys = [];
                foreach ($group['keys'] as $key) {
                    if (isset($fields[$key])) {
                        $render_keys[] = $key;
                    }
                }
                if ('location' === $gid) {
                    array_unshift($render_keys, '__destination__');
                }
                if ('booking' === $gid) {
                    $render_keys[] = '__booking_mode__';
                }
                if (!$render_keys) {
                    continue;
                }
                $group_id = 'tap-group-' . $gid;
                ?>
                <section class="tap-group<?php echo $group['open'] ? ' is-open' : ''; ?>" data-group="<?php echo esc_attr($gid); ?>">
                    <button type="button" class="tap-group-head" aria-expanded="<?php echo $group['open'] ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($group_id); ?>">
                        <span class="tap-group-titles">
                            <span class="tap-group-title"><?php echo esc_html($group['label']); ?></span>
                            <span class="tap-group-sub"><?php echo esc_html($group['sub']); ?></span>
                        </span>
                        <span class="tap-group-caret" aria-hidden="true">&darr;</span>
                    </button>
                    <div class="tap-group-body" id="<?php echo esc_attr($group_id); ?>">
                        <div class="tap-form-grid">
                            <?php foreach ($render_keys as $key): ?>
                                <?php
                                if ('__destination__' === $key) {
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
                                    ?>
                                    <div class="tap-field tap-span-2">
                                        <label><?php esc_html_e('Destino', 'travel-agency-platform'); ?></label>
                                        <?php echo TAP_Destinations::render_picker($dest_sel, 'destination'); ?>
                                        <span class="tap-field-hint"><?php esc_html_e('Elige el lugar más específico (ciudad o distrito).', 'travel-agency-platform'); ?></span>
                                    </div>
                                    <?php
                                    continue;
                                }

                                if ('__booking_mode__' === $key) {
                                    ?>
                                    <div class="tap-field">
                                        <label for="tap-acc-booking-mode"><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></label>
                                        <select id="tap-acc-booking-mode" name="booking_mode">
                                            <option value="instant" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'instant'); ?>><?php esc_html_e('Reserva directa (pago inmediato)', 'travel-agency-platform'); ?></option>
                                            <option value="request" <?php selected(TAP_Booking::booking_mode($type, $listing_id), 'request'); ?>><?php esc_html_e('Solicitud de reserva (la agencia confirma antes del pago)', 'travel-agency-platform'); ?></option>
                                        </select>
                                        <span class="tap-field-hint"><?php esc_html_e('La solicitud te da más control y menos cancelaciones por sorpresa.', 'travel-agency-platform'); ?></span>
                                    </div>
                                    <?php
                                    continue;
                                }

                                $cfg     = $fields[$key];
                                $ftype   = isset($cfg['type']) ? $cfg['type'] : 'text';
                                $copy    = isset($labels[$key]) ? $labels[$key] : [];
                                $label   = isset($copy['label']) ? $copy['label'] : (isset($cfg['label']) ? $cfg['label'] : $key);
                                $hint    = isset($copy['hint']) ? $copy['hint'] : '';
                                $value   = $is_edit ? get_post_meta($listing_id, $key, true) : (isset($cfg['default']) ? $cfg['default'] : '');
                                $options = isset($copy['options']) ? $copy['options'] : (isset($cfg['options']) ? $cfg['options'] : []);
                                $is_price = false !== strpos($key, 'price');
                                $span     = ('textarea' === $ftype || 'checkbox' === $ftype || 'select_post' === $ftype) ? ' tap-span-2' : '';
								$field_id = 'tap-acc-' . sanitize_html_class(str_replace('_', '-', $key));
								?>
                                <div class="tap-field<?php echo esc_attr($span); ?>">
                                    <?php if ('checkbox' === $ftype): ?>
                                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
                                        <label class="tap-switch" for="<?php echo esc_attr($field_id); ?>">
                                            <input type="checkbox" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($key); ?>" value="1" <?php checked('1', (string) $value, false); ?>>
                                            <span class="tap-switch-track" aria-hidden="true"></span>
                                            <span><?php echo esc_html($label); ?></span>
                                        </label>
                                    <?php else: ?>
                                        <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                                    <?php endif; ?>

                                    <?php if ('select' === $ftype): ?>
                                        <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($key); ?>">
                                            <option value=""><?php echo esc_html__('— Selecciona —', 'travel-agency-platform'); ?></option>
                                            <?php foreach ($options as $k => $v): ?>
                                                <option value="<?php echo esc_attr($k); ?>" <?php echo (string) $value !== '' && (string) $k === (string) $value ? ' selected' : ''; ?>><?php echo esc_html($v); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ('number' === $ftype): ?>
                                        <div class="tap-number-row">
                                            <button type="button" class="tap-num-btn" data-step="-1" aria-label="<?php esc_attr_e('Restar', 'travel-agency-platform'); ?>">&minus;</button>
                                            <input type="number" id="<?php echo esc_attr($field_id); ?>" class="tap-num-input tap-field-input" name="<?php echo esc_attr($key); ?>"
                                                    min="0"<?php echo isset($cfg['step']) ? ' step="' . esc_attr($cfg['step']) . '"' : ''; ?>
                                                    <?php echo $is_price ? 'placeholder="' . esc_attr(trim($currency_symbol)) . '"' : ''; ?>
                                                    value="<?php echo esc_attr($value); ?>">
                                            <button type="button" class="tap-num-btn" data-step="1" aria-label="<?php esc_attr_e('Sumar', 'travel-agency-platform'); ?>">+</button>
                                        </div>
                                    <?php elseif ('time' === $ftype): ?>
                                        <input type="time" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                    <?php else: ?>
                                        <input type="text" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                    <?php endif; ?>

                                    <?php if ($hint): ?>
                                        <span class="tap-field-hint"><?php echo esc_html($hint); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <p class="tap-form-msg" role="status" aria-live="polite"></p>
        </form>
        <?php
        return ob_get_clean();
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


    public static function acc_field_labels() {
        return [
            '_tap_acc_type' => [
                'label' => __('Tipo de alojamiento', 'travel-agency-platform'),
                'options' => [
                    'hotel' => __('Hotel', 'travel-agency-platform'),
                    'hostel' => __('Hostal', 'travel-agency-platform'),
                    'resort' => __('Resort', 'travel-agency-platform'),
                    'villa' => __('Villa', 'travel-agency-platform'),
                    'cabin' => __('Cabaña', 'travel-agency-platform'),
                    'apartment' => __('Apartamento', 'travel-agency-platform'),
                    'boutique' => __('Hotel boutique', 'travel-agency-platform'),
                    'eco' => __('Eco-alojamiento', 'travel-agency-platform'),
                ],
            ],
            '_tap_acc_stars' => [
                'label' => __('Categoría', 'travel-agency-platform'),
                'options' => [
                    '1' => __('1 estrella', 'travel-agency-platform'),
                    '2' => __('2 estrellas', 'travel-agency-platform'),
                    '3' => __('3 estrellas', 'travel-agency-platform'),
                    '4' => __('4 estrellas', 'travel-agency-platform'),
                    '5' => __('5 estrellas', 'travel-agency-platform'),
                ],
            ],
            '_tap_acc_price_per_night' => [
                'label'  => __('Precio por noche', 'travel-agency-platform'),
                'hint'   => __('Solo se usa si después no defines precio por unidad.', 'travel-agency-platform'),
            ],
            '_tap_acc_currency'    => ['label' => __('Moneda', 'travel-agency-platform'), 'hint' => __('Código ISO, por ejemplo USD, EUR o CRC.', 'travel-agency-platform')],
            '_tap_acc_capacity'    => ['label' => __('Huéspedes máximos', 'travel-agency-platform')],
            '_tap_acc_rooms'       => ['label' => __('Número de habitaciones', 'travel-agency-platform')],
            '_tap_acc_bathrooms'   => ['label' => __('Baños', 'travel-agency-platform')],
            '_tap_acc_bedrooms'    => ['label' => __('Dormitorios', 'travel-agency-platform')],
            '_tap_acc_checkin_time'  => ['label' => __('Hora de check-in', 'travel-agency-platform')],
            '_tap_acc_checkout_time' => ['label' => __('Hora de check-out', 'travel-agency-platform')],
            '_tap_acc_lat'         => ['label' => __('Latitud', 'travel-agency-platform'), 'hint' => __('Se rellena al elegir el destino.', 'travel-agency-platform')],
            '_tap_acc_lng'         => ['label' => __('Longitud', 'travel-agency-platform')],
            '_tap_acc_is_active'   => ['label' => __('Visible en el buscador', 'travel-agency-platform'), 'hint' => __('Desactívalo para ocultarlo sin despublicarlo.', 'travel-agency-platform')],
        ];
    }


    public static function acc_rooms_for($listing_id) {
        return get_posts([
            'post_type'      => 'tap_room',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_tap_room_accommodation_id',
            'meta_value'     => $listing_id,
        ]);
    }


    public static function render_acc_wizard_script($listing_id) {
        $is_edit = (bool) $listing_id;
        $edit_url = $is_edit ? home_url('/manage-listing/?id=' . (int) $listing_id) : '';
        list($currency_code, $currency_symbol, $currency_decimals) = self::wizard_currency($listing_id);

        wp_enqueue_script('tap-agency-wizard', TAP_PLUGIN_URL . 'assets/js/agency-wizard.js', [], TAP_VERSION, true);
        wp_localize_script('tap-agency-wizard', 'tapAgencyWizard', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'uploadNonce'     => wp_create_nonce('tap_front_dash_nonce'),
            'listingNonce'    => wp_create_nonce('tap_agency_listing_nonce'),
            'isEdit'          => $is_edit,
            'listingId'       => (int) $listing_id,
            'listingType'     => 'tap_accommodation',
            'stepUrl'         => $edit_url ? $edit_url . '&step=' : '',
            'currency'        => $currency_symbol,
            'currencyCode'    => $currency_code,
            'decimals'        => $currency_decimals,
            'maxUploadBytes'  => TAP_Agency_Media::agency_image_max_bytes(),
            'bedTypes'        => self::acc_bed_labels(),
            'i18n' => [
                'saved'         => __('Guardado', 'travel-agency-platform'),
                'error'         => __('Ha ocurrido un error', 'travel-agency-platform'),
                'confirm'       => __('Confirmar', 'travel-agency-platform'),
                'cancel'        => __('Cancelar', 'travel-agency-platform'),
                'close'         => __('Cerrar', 'travel-agency-platform'),
                'main'          => __('Principal', 'travel-agency-platform'),
                'setMain'       => __('Usar como foto principal', 'travel-agency-platform'),
                'removeImage'   => __('Quitar imagen', 'travel-agency-platform'),
                'bedType'       => __('Tipo de cama', 'travel-agency-platform'),
                'bedCount'      => __('Cantidad', 'travel-agency-platform'),
                'removeBed'     => __('Quitar cama', 'travel-agency-platform'),
                'uploading'     => __('Subiendo', 'travel-agency-platform'),
                'uploadingDone' => __('Subiendo', 'travel-agency-platform'),
                'uploadedCount' => __('Subidas', 'travel-agency-platform'),
                'uploaded'      => __('Imágenes subidas.', 'travel-agency-platform'),
                'uploadFailed'  => __('No se pudo subir la imagen.', 'travel-agency-platform'),
                'badType'       => __('solo se permiten imágenes.', 'travel-agency-platform'),
                'tooBig'        => __('la imagen supera el tamaño máximo.', 'travel-agency-platform'),
                'added'         => __('Imagen añadida.', 'travel-agency-platform'),
                'delete'        => __('Eliminar', 'travel-agency-platform'),
                'deleted'       => __('Unidad eliminada.', 'travel-agency-platform'),
                'deleteRoom'    => __('Eliminar unidad', 'travel-agency-platform'),
                'deleteRoomSub' => __('Se borrará la unidad y todos sus precios. Esta acción no se puede deshacer.', 'travel-agency-platform'),
                'publish'       => __('Publicar', 'travel-agency-platform'),
                'publishSub'    => __('Pasará a ser visible para los viajeros.', 'travel-agency-platform'),
                'unpublish'     => __('Despublicar', 'travel-agency-platform'),
                'unpublishSub'  => __('Dejará de aparecer en el buscador hasta que lo publiques de nuevo.', 'travel-agency-platform'),
                'networkError'  => __('Error de conexión. Revisa tu red e inténtalo de nuevo.', 'travel-agency-platform'),
            ],
        ]);

        return '';
    }


    public static function wizard_currency($listing_id) {
        $code = $listing_id ? (string) get_post_meta((int) $listing_id, '_tap_acc_currency', true) : '';
        $code = $code ? strtoupper($code) : TAP_Currency::code();
        return [
            $code,
            TAP_Currency::plain_symbol($code),
            (int) TAP_Currency::decimals($code),
        ];
    }


    public static function render_acc_wizard($listing_id, $type) {
        $is_edit   = (bool) $listing_id;
        $listing   = $is_edit ? get_post($listing_id) : null;
        $type_label = TAP_Post_Types::get_service_types()[$type] ?? ucfirst($type);

        $step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
        if ($step < 1 || $step > 5) {
            $step = 1;
        }
        if ($step > 1 && !$is_edit) {
            $step = 1;
        }

        $edit_base = home_url('/manage-listing/?id=' . (int) $listing_id);
        $new_url   = home_url('/manage-listing/?new=' . rawurlencode($type));
        $steps     = [
            1 => ['label' => __('Información', 'travel-agency-platform'), 'hint' => __('Nombre, fotos y datos principales', 'travel-agency-platform')],
            2 => ['label' => __('Unidades', 'travel-agency-platform'), 'hint' => __('Habitaciones, camas e inventario', 'travel-agency-platform')],
            3 => ['label' => __('Precios', 'travel-agency-platform'), 'hint' => __('Temporadas y disponibilidad', 'travel-agency-platform')],
            4 => ['label' => __('Políticas', 'travel-agency-platform'), 'hint' => __('Cancelación y reglas de la casa', 'travel-agency-platform')],
            5 => ['label' => __('Publicar', 'travel-agency-platform'), 'hint' => __('Revisa y publica tu alojamiento', 'travel-agency-platform')],
        ];

        $progress = self::acc_wizard_progress($listing_id, $type);
        $status   = $is_edit ? get_post_status($listing_id) : 'draft';
        $is_live  = 'publish' === $status && '1' === get_post_meta($listing_id, '_tap_acc_is_active', true);

        wp_enqueue_style('tap-agency-calendar', TAP_PLUGIN_URL . 'assets/css/agency-calendar.css', ['tap-public'], TAP_VERSION);

        ob_start();
        ?>
        <div class="tap-agency-panel tap-manage-wrap tap-wizard" data-wizard-step="<?php echo (int) $step; ?>" data-listing="<?php echo (int) $listing_id; ?>">
            <header class="tap-wizard-head">
                <div class="tap-wizard-head-main">
                    <a class="tap-wizard-back" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">
                        <span aria-hidden="true">&larr;</span> <?php esc_html_e('Mis alojamientos', 'travel-agency-platform'); ?>
                    </a>
                    <h2 class="tap-panel-title"><?php echo $is_edit ? esc_html__('Editar alojamiento', 'travel-agency-platform') : esc_html__('Nuevo alojamiento', 'travel-agency-platform'); ?></h2>
                    <p class="tap-wizard-head-meta">
                        <span class="tap-wizard-head-type"><?php echo esc_html($type_label); ?></span>
                        <span class="tap-wizard-head-sep" aria-hidden="true">&middot;</span>
                        <span>
                        <?php
                        printf(
                            /* translators: 1: current step number, 2: total steps */
                            esc_html__('Paso %1$d de %2$d', 'travel-agency-platform'),
                            (int) $step,
                            5
								);
						?>
                        — <?php echo esc_html($steps[$step]['hint']); ?></span>
                    </p>
                </div>
                <div class="tap-wizard-head-side">
                    <?php if ($is_edit): ?>
                        <span class="tap-chip <?php echo $is_live ? 'tap-chip-success' : 'tap-chip-muted'; ?>">
                            <?php echo $is_live ? esc_html__('Publicado', 'travel-agency-platform') : esc_html__('Borrador', 'travel-agency-platform'); ?>
                        </span>
                    <?php endif; ?>
                    <span class="tap-chip tap-chip-progress">
                        <span class="tap-chip-progress-bar"><span style="width:<?php echo (int) $progress['percent']; ?>%"></span></span>
                        <?php echo (int) $progress['percent']; ?>% <?php esc_html_e('completo', 'travel-agency-platform'); ?>
                    </span>
                </div>
            </header>

            <nav class="tap-wizard-nav" aria-label="<?php esc_attr_e('Progreso del alta', 'travel-agency-platform'); ?>">
                <ol class="tap-wizard-steps">
                    <?php
                    foreach ($steps as $n => $meta):
                        $step_done = !empty($progress['steps'][$n]['done']);
                        $locked    = !$is_edit && $n > 1;
                        $is_active = $n === $step;
                        $cls       = '';
                        if ($is_active) {
                            $cls .= ' active';
                        }
                        if ($locked) {
                            $cls .= ' locked';
                        } elseif ($step_done && $n < 5) {
                            $cls .= ' complete';
                        }
                        $href = $n === 1
                            ? ($is_edit ? $edit_base . '&step=1' : $new_url)
                            : $edit_base . '&step=' . $n;
                        ?>
                        <li class="tap-wizard-step<?php echo esc_attr($cls); ?>"<?php echo $is_active ? ' aria-current="step"' : ''; ?>>
                            <?php if ($locked): ?>
                                <span>
                                    <span class="tap-wizard-step-num" aria-hidden="true"><?php echo (int) $n; ?></span>
                                    <span class="tap-wizard-step-label"><?php echo esc_html($meta['label']); ?></span>
                                    <span class="tap-wizard-step-hint"><?php esc_html_e('Guarda el paso 1', 'travel-agency-platform'); ?></span>
                                </span>
                            <?php else: ?>
                                <a href="<?php echo esc_url($href); ?>">
                                    <span class="tap-wizard-step-num"<?php echo $step_done && !$is_active ? ' aria-label="' . esc_attr__('Completado', 'travel-agency-platform') . '"' : ''; ?>>
                                        <?php if ($step_done && !$is_active): ?><span aria-hidden="true">&check;</span><?php else: ?><?php echo (int) $n; ?><?php endif; ?>
                                    </span>
                                    <span class="tap-wizard-step-label"><?php echo esc_html($meta['label']); ?></span>
                                    <span class="tap-wizard-step-hint"><?php echo esc_html($meta['hint']); ?></span>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <div class="tap-wizard-layout">
                <main class="tap-wizard-body">
                    <?php
                    if ($step === 1) {
                        echo self::render_acc_step_info($listing_id, $type);
                    } elseif ($step === 2) {
                        echo self::render_acc_step_rooms($listing_id);
                    } elseif ($step === 3) {
                        echo self::render_acc_step_calendar($listing_id);
                    } elseif ($step === 4) {
                        echo self::render_acc_step_policies($listing_id);
                    } else {
                        echo self::render_acc_step_publish($listing_id);
                    }
                    ?>
                </main>

                <aside class="tap-wizard-rail" aria-label="<?php esc_attr_e('Resumen del alta', 'travel-agency-platform'); ?>">
                    <div class="tap-rail-card">
                        <div class="tap-rail-card-head">
                            <strong><?php esc_html_e('Tu progreso', 'travel-agency-platform'); ?></strong>
                            <span class="tap-rail-count"><?php echo (int) $progress['completed']; ?>/4</span>
                        </div>
                        <p class="tap-rail-lead"><?php esc_html_e('Completa estos puntos para que tu alojamiento esté listo para recibir reservas.', 'travel-agency-platform'); ?></p>
                        <ul class="tap-rail-list">
                            <?php
                            foreach ($progress['items'] as $item):
                                if (5 === $item['step']) {
                                    continue;
                                }
                                $item_locked = !$is_edit && $item['step'] > 1;
                                ?>
                                <li class="tap-rail-item<?php echo $item['done'] ? ' is-done' : ''; ?>">
                                    <span class="tap-rail-dot" aria-hidden="true"><?php echo $item['done'] ? '&check;' : ''; ?></span>
                                    <span class="tap-rail-body">
                                        <span class="tap-rail-label"><?php echo esc_html($item['label']); ?></span>
                                        <?php if ($item['detail']): ?>
                                            <span class="tap-rail-detail"><?php echo esc_html($item['detail']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!$item['done'] && !$item_locked): ?>
                                        <?php
                                        /* A brand new listing has no id yet, so step 1
                                            must point at the "new" url, not ?id=0. */
                                        $item_url = (1 === (int) $item['step'] && !$is_edit)
                                            ? $new_url
                                            : $edit_base . '&step=' . (int) $item['step'];
                                        ?>
                                        <a class="tap-rail-link" href="<?php echo esc_url($item_url); ?>"
                                            aria-label="<?php echo esc_attr(sprintf(/* translators: %s: item label */ __('Ir a: %s', 'travel-agency-platform'), $item['label'])); ?>">&rarr;</a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if (5 === $step): ?>
                        <div class="tap-rail-card tap-rail-card-help">
                            <strong><?php esc_html_e('¿Necesitas ayuda?', 'travel-agency-platform'); ?></strong>
                            <p class="tap-rail-lead"><?php esc_html_e('Si un revisor rechaza tu alojamiento, verás aquí el motivo y podrás corregirlo sin empezar de nuevo.', 'travel-agency-platform'); ?></p>
                            <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>"><?php esc_html_e('Volver al panel', 'travel-agency-platform'); ?></a>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>

            <?php
            $prev_url = $step > 1 ? $edit_base . '&step=' . ($step - 1) : home_url('/manage-listing/');
            $next_url = $step < 5 ? $edit_base . '&step=' . ($step + 1) : '';
            ?>
            <footer class="tap-wizard-foot">
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($prev_url); ?>">
                    <?php echo $step > 1 ? esc_html__('Atrás', 'travel-agency-platform') : esc_html__('Cancelar', 'travel-agency-platform'); ?>
                </a>
                <div class="tap-wizard-foot-hint">
                    <?php echo esc_html__('Guarda los cambios antes de continuar.', 'travel-agency-platform'); ?>
                </div>
                <?php if (1 === $step): ?>
                    <button type="submit" form="tap-listing-form" class="tap-btn tap-btn-primary tap-wizard-next">
                        <?php echo $is_edit ? esc_html__('Guardar y continuar', 'travel-agency-platform') : esc_html__('Crear y continuar', 'travel-agency-platform'); ?>
                    </button>
                <?php elseif (4 === $step): ?>
                    <button type="submit" form="tap-policies-form" class="tap-btn tap-btn-primary tap-wizard-next">
                        <?php esc_html_e('Guardar y continuar', 'travel-agency-platform'); ?>
                    </button>
                <?php elseif ($next_url): ?>
                    <a class="tap-btn tap-btn-primary tap-wizard-next" href="<?php echo esc_url($next_url); ?>">
                        <?php esc_html_e('Continuar', 'travel-agency-platform'); ?>
                    </a>
                <?php else: ?>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=1'); ?>"><?php esc_html_e('Seguir editando', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </footer>
        </div>
        <?php
        echo self::render_acc_wizard_script($listing_id);
        return ob_get_clean();
    }


    public static function acc_wizard_progress($listing_id, $type) {
        $is_edit = (bool) $listing_id;
        $listing = $is_edit ? get_post($listing_id) : null;
        $prefix  = TAP_Post_Types::meta_prefix($type);

        $title       = $listing ? trim((string) $listing->post_title) : '';
        $description = $listing ? trim((string) $listing->post_content) : '';
        $thumbnail   = $is_edit ? (int) get_post_thumbnail_id($listing_id) : 0;
        $destination = $is_edit ? get_the_terms($listing_id, 'tap_location') : false;

        $rooms = $is_edit ? self::acc_rooms_for($listing_id) : [];
        $room_ids = array_map(function ($r) {
            return (int) $r->ID;
        }, $rooms);
        $priced_rooms = 0;
        foreach ($rooms as $r) {
            if ((float) get_post_meta($r->ID, '_tap_room_price_per_night', true) > 0) {
                ++$priced_rooms;
            }
        }
        $overrides = $room_ids ? TAP_Pricing::override_count($room_ids) : 0;

        $policy  = $is_edit ? (string) get_post_meta($listing_id, '_tap_' . $prefix . '_cancellation', true) : '';
        $rules   = $is_edit ? (string) get_post_meta($listing_id, '_tap_' . $prefix . '_house_rules', true) : '';
        $is_published = $is_edit && 'publish' === get_post_status($listing_id);

        $items = [
            [
                'step'   => 1,
                'key'    => 'info',
                'label'  => __('Nombre del alojamiento', 'travel-agency-platform'),
                'done'   => '' !== $title,
                'detail' => '' !== $title ? '' : __('Ponle un nombre reconocible', 'travel-agency-platform'),
            ],
            [
                'step'   => 1,
                'key'    => 'desc',
                'label'  => __('Descripción', 'travel-agency-platform'),
                'done'   => '' !== $description,
                'detail' => '' !== $description ? '' : __('Cuenta qué ofrece y sus características', 'travel-agency-platform'),
            ],
            [
                'step'   => 1,
                'key'    => 'dest',
                'label'  => __('Destino', 'travel-agency-platform'),
                'done'   => is_array($destination) && $destination,
                'detail' => (is_array($destination) && $destination) ? '' : __('Selecciona dónde está', 'travel-agency-platform'),
            ],
            [
                'step'   => 1,
                'key'    => 'photo',
                'label'  => __('Foto principal', 'travel-agency-platform'),
                'done'   => $thumbnail > 0,
                'detail' => $thumbnail > 0 ? '' : __('Sube al menos una imagen', 'travel-agency-platform'),
            ],
            [
                'step'   => 2,
                'key'    => 'rooms',
                'label'  => __('Unidades creadas', 'travel-agency-platform'),
                'done'   => (bool) $room_ids,
                'detail' => $room_ids ? sprintf(_n('%d unidad', '%d unidades', count($room_ids), 'travel-agency-platform'), count($room_ids)) : __('Añade tu primera unidad', 'travel-agency-platform'),
            ],
            [
                'step'   => 2,
                'key'    => 'prices',
                'label'  => __('Precio por noche', 'travel-agency-platform'),
                'done'   => $priced_rooms === count($room_ids) && $priced_rooms > 0,
                'detail' => ($priced_rooms === count($room_ids) && $priced_rooms > 0) ? '' : __('Define el precio de cada unidad', 'travel-agency-platform'),
            ],
            [
                'step'   => 3,
                'key'    => 'calendar',
                'label'  => __('Ajustes de precios y disponibilidad', 'travel-agency-platform'),
                'done'   => $overrides > 0,
                'detail' => $overrides > 0 ? sprintf(_n('%d día ajustado', '%d días ajustados', $overrides, 'travel-agency-platform'), $overrides) : __('Opcional: fija temporadas o bloquea fechas', 'travel-agency-platform'),
            ],
            [
                'step'   => 4,
                'key'    => 'cancellation',
                'label'  => __('Política de cancelación', 'travel-agency-platform'),
                'done'   => '' !== $policy,
                'detail' => '' !== $policy ? '' : __('Obligatorio para reservar', 'travel-agency-platform'),
            ],
            [
                'step'   => 4,
                'key'    => 'rules',
                'label'  => __('Reglas de la casa', 'travel-agency-platform'),
                'done'   => '' !== trim($rules),
                'detail' => '' !== trim($rules) ? '' : __('Informa de mascotas, check-in y fumar', 'travel-agency-platform'),
            ],
            [
                'step'   => 5,
                'key'    => 'published',
                'label'  => __('Alojamiento publicado', 'travel-agency-platform'),
                'done'   => $is_published,
                'detail' => $is_published ? '' : __('Publica cuando todo esté listo', 'travel-agency-platform'),
            ],
        ];

        // A step counts as complete when all of its items are done.
        $by_step = [];
        foreach ($items as $item) {
            $by_step[$item['step']]['done'] = isset($by_step[$item['step']]['done']) ? ($by_step[$item['step']]['done'] && $item['done']) : $item['done'];
        }
        $completed = 0;
        foreach ([1, 2, 3, 4] as $n) {
            if (!empty($by_step[$n]['done'])) {
                ++$completed;
            }
        }

        $percent = (int) round(($completed / 4) * 100);

        return [
            'items'     => $items,
            'steps'     => $by_step,
            'completed' => $completed,
            'percent'   => $percent,
            'overrides' => $overrides,
            'rooms'     => count($room_ids),
        ];
    }
}
