<?php
defined('ABSPATH') || exit;

/**
 * Four-step agency listing wizard: progress bar, the info/rooms/calendar/\n * policies/publish steps, the room form and its shared field and label\n * helpers. Pure view code (ob_start plus HTML), split out of\n * TAP_Listing_Editor so that file keeps only the router and the editor shell.
 */
class TAP_Acc_Wizard {
    public static function render_acc_wizard_script($listing_id) {
        $is_edit = (bool) $listing_id;
        $edit_url = $is_edit ? home_url('/manage-listing/?id=' . (int) $listing_id) : '';
        list($currency_code, $currency_symbol, $currency_decimals) = TAP_Acc_Wizard_Steps::wizard_currency($listing_id);

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
            'bedTypes'        => TAP_Acc_Wizard_Room_Form::acc_bed_labels(),
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

        $progress = TAP_Acc_Wizard_Steps::acc_wizard_progress($listing_id, $type);
        $status   = $is_edit ? get_post_status($listing_id) : 'draft';
        $is_live  = 'publish' === $status && '1' === get_post_meta($listing_id, '_tap_acc_is_active', true);

        // UX pre-check: block entry when the plan limit is already reached.
        // save_listing_data() enforces the real limit server-side.
        if (!$is_edit && !current_user_can('manage_options') && class_exists('TAP_Subscriptions')) {
            $limit_agency = TAP_Booking::get_agency_for_user();
            if ($limit_agency) {
                $limit = TAP_Subscriptions::listing_limit($limit_agency);
                if ($limit >= 0 && TAP_Subscriptions::listing_count($limit_agency) >= $limit) {
                    ob_start();
                    ?>
                    <div class="tap-agency-panel tap-manage-wrap">
                        <div class="tap-dash-login-card">
                            <h2 class="tap-panel-title"><?php esc_html_e('Límite de listados alcanzado', 'travel-agency-platform'); ?></h2>
                            <p>
                            <?php
                            /* translators: %d: número máximo de listados permitidos por el plan */
                            printf(esc_html__('Your plan allows a maximum of %d listings. Upgrading your plan unlocks more.', 'travel-agency-platform'), (int) $limit);
                            ?>
                            </p>
                            <a class="tap-btn tap-btn-primary" href="<?php echo esc_url(home_url('/planes/')); ?>"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
                        </div>
                    </div>
                    <?php
                    return ob_get_clean();
                }
            }
        }

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
                        echo TAP_Acc_Wizard_Steps::render_acc_step_info($listing_id, $type);
                    } elseif ($step === 2) {
                        echo TAP_Acc_Wizard_Steps::render_acc_step_rooms($listing_id);
                    } elseif ($step === 3) {
                        echo TAP_Acc_Wizard_Steps::render_acc_step_calendar($listing_id);
                    } elseif ($step === 4) {
                        echo TAP_Acc_Wizard_Steps::render_acc_step_policies($listing_id);
                    } else {
                        echo TAP_Acc_Wizard_Steps::render_acc_step_publish($listing_id);
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


}
