<?php
defined('ABSPATH') || exit;

/**
 * Tour wizard shell.
 *
 * URL-based, one form per step (like TAP_Acc_Wizard). Each step persists
 * through the shared tap_agency_save_listing endpoint and then advances to the
 * next step, so a tour operator never loses work between steps.
 */
class TAP_Tour_Wizard {

    /**
     * Localized configuration for assets.agency-wizard.js.
     *
     * @param int $listing_id Tour id (0 when creating).
     * @return string
     */
    public static function render_tour_wizard_script($listing_id) {
        $is_edit  = (bool) $listing_id;
        $edit_url = $is_edit ? home_url('/manage-listing/?id=' . (int) $listing_id) : '';

        wp_enqueue_script('tap-agency-wizard', TAP_PLUGIN_URL . 'assets/js/agency-wizard.js', [], TAP_VERSION, true);
        wp_localize_script('tap-agency-wizard', 'tapAgencyWizard', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'uploadNonce'     => wp_create_nonce('tap_front_dash_nonce'),
            'listingNonce'    => wp_create_nonce('tap_agency_listing_nonce'),
            'isEdit'          => $is_edit,
            'listingId'       => (int) $listing_id,
            'listingType'     => 'tap_tour',
            'stepUrl'         => $edit_url ? $edit_url . '&step=' : '',
            'currency'        => TAP_Currency::symbol(),
            'currencyCode'    => TAP_Currency::code(),
            'decimals'        => TAP_Currency::decimals(),
            'maxUploadBytes'  => TAP_Agency_Media::agency_image_max_bytes(),
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
                'uploadFailed'  => __('No se pudo subir la imagen.', 'travel-agency-platform'),
                'badType'       => __('solo se permiten imágenes.', 'travel-agency-platform'),
                'tooBig'        => __('la imagen supera el tamaño máximo.', 'travel-agency-platform'),
                'added'         => __('Imagen añadida.', 'travel-agency-platform'),
                'delete'        => __('Eliminar', 'travel-agency-platform'),
                'deleted'       => __('Eliminado.', 'travel-agency-platform'),
                'publish'       => __('Publicar', 'travel-agency-platform'),
                'publishSub'    => __('Pasará a ser visible para los viajeros.', 'travel-agency-platform'),
                'unpublish'     => __('Despublicar', 'travel-agency-platform'),
                'unpublishSub'  => __('Dejará de aparecer en el buscador hasta que lo publiques de nuevo.', 'travel-agency-platform'),
                'networkError'  => __('Error de conexión. Revisa tu red e inténtalo de nuevo.', 'travel-agency-platform'),
                'fieldRequired' => __('Completa este campo.', 'travel-agency-platform'),
                'checkFields'   => __('Revisa los campos marcados.', 'travel-agency-platform'),
                'schedNeedDays' => __('Selecciona al menos un día de la semana.', 'travel-agency-platform'),
                'schedNeedTimes'=> __('Indica al menos un horario válido (HH:MM).', 'travel-agency-platform'),
                'schedBadRange' => __('Revisa el rango de fechas.', 'travel-agency-platform'),
                'schedWorking'  => __('Generando horarios…', 'travel-agency-platform'),
                'schedDone'     => __('Horarios actualizados.', 'travel-agency-platform'),
                'slotDeleted'   => __('Horario eliminado.', 'travel-agency-platform'),
                'slotBlocked'   => __('Horario bloqueado.', 'travel-agency-platform'),
                'slotFree'      => __('Horario disponible de nuevo.', 'travel-agency-platform'),
                'noSlots'       => __('Todavía no hay salidas. Genera la primera temporada arriba.', 'travel-agency-platform'),
                'allDay'        => __('Todo el día', 'travel-agency-platform'),
                'places'        => __('%1$s/%2$s reservados', 'travel-agency-platform'),
                'block'         => __('Bloquear', 'travel-agency-platform'),
                'unblock'       => __('Desbloquear', 'travel-agency-platform'),
                'removeStop'    => __('Eliminar parada', 'travel-agency-platform'),
                'day'           => __('Día', 'travel-agency-platform'),
                'time'          => __('Hora', 'travel-agency-platform'),
                'stopTitle'     => __('Parada', 'travel-agency-platform'),
                'stopDesc'      => __('Descripción', 'travel-agency-platform'),
            ],
        ]);

        return '';
    }

    /**
     * Render the wizard shell for the current step.
     *
     * @param int    $listing_id Tour id (0 when creating).
     * @return string
     */
    public static function render_tour_wizard($listing_id, $type = 'tap_tour') {
        $listing_id = (int) $listing_id;
        $is_edit    = (bool) $listing_id;
        $type_label = TAP_Post_Types::get_service_types()[$type] ?? ucfirst($type);

        $step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
        if ($step < 1 || $step > 5) {
            $step = 1;
        }
        if ($step > 1 && !$is_edit) {
            $step = 1;
        }

        $edit_base = home_url('/manage-listing/?id=' . $listing_id);
        $new_url   = home_url('/manage-listing/?new=' . rawurlencode($type));
        $steps     = [
            1 => ['label' => __('Información', 'travel-agency-platform'), 'hint' => __('Nombre, fotos, duración e idiomas', 'travel-agency-platform')],
            2 => ['label' => __('Itinerario', 'travel-agency-platform'), 'hint' => __('Punto de encuentro, paradas e inclusiones', 'travel-agency-platform')],
            3 => ['label' => __('Precios y fechas', 'travel-agency-platform'), 'hint' => __('Tarifas, grupo, cancelación y salidas', 'travel-agency-platform')],
            4 => ['label' => __('Políticas', 'travel-agency-platform'), 'hint' => __('Requisitos, waiver, ecoturismo y extras', 'travel-agency-platform')],
            5 => ['label' => __('Publicar', 'travel-agency-platform'), 'hint' => __('Revisa, completa el SEO y publica', 'travel-agency-platform')],
        ];

        $progress = TAP_Tour_Wizard_Steps::tour_wizard_progress($listing_id);
        $status   = $is_edit ? get_post_status($listing_id) : 'draft';
        $is_live  = 'publish' === $status && '1' === get_post_meta($listing_id, '_tap_tour_is_active', true);

        // UX pre-check: block entry when the plan limit is already reached.
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

        // The stylesheet is enqueued in the head by the theme for the
        // manage-listing template; enqueuing it here (after wp_head) would be
        // too late to print.
        ob_start();
        ?>
        <div class="tap-agency-panel tap-manage-wrap tap-wizard" data-wizard-step="<?php echo (int) $step; ?>" data-listing="<?php echo (int) $listing_id; ?>">
            <header class="tap-wizard-head">
                <div class="tap-wizard-head-main">
                    <a class="tap-wizard-back" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">
                        <span aria-hidden="true">&larr;</span> <?php esc_html_e('Mis servicios', 'travel-agency-platform'); ?>
                    </a>
                    <h2 class="tap-panel-title"><?php echo $is_edit ? esc_html__('Editar tour', 'travel-agency-platform') : esc_html__('Nuevo tour', 'travel-agency-platform'); ?></h2>
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
                            — <?php echo esc_html($steps[$step]['hint']); ?>
                        </span>
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
                    if (1 === $step) {
                        echo TAP_Tour_Wizard_Steps::render_step_info($listing_id);
                    } elseif (2 === $step) {
                        echo TAP_Tour_Wizard_Steps::render_step_logistics($listing_id);
                    } elseif (3 === $step) {
                        echo TAP_Tour_Wizard_Steps::render_step_pricing($listing_id);
                    } elseif (4 === $step) {
                        echo TAP_Tour_Wizard_Steps::render_step_details($listing_id);
                    } else {
                        echo TAP_Tour_Wizard_Steps::render_step_publish($listing_id);
                    }
                    ?>
                </main>

                <aside class="tap-wizard-rail" aria-label="<?php esc_attr_e('Resumen del alta', 'travel-agency-platform'); ?>">
                    <div class="tap-rail-card">
                        <div class="tap-rail-card-head">
                            <strong><?php esc_html_e('Tu progreso', 'travel-agency-platform'); ?></strong>
                            <span class="tap-rail-count"><?php echo (int) $progress['completed']; ?>/4</span>
                        </div>
                        <p class="tap-rail-lead"><?php esc_html_e('Completa estos puntos para que tu tour esté listo para recibir reservas.', 'travel-agency-platform'); ?></p>
                        <ul class="tap-rail-list">
                            <?php
                            foreach ($progress['items'] as $item):
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

                    <?php if (3 === $step): ?>
                        <div class="tap-rail-card tap-rail-card-help">
                            <strong><?php esc_html_e('Cómo funciona la disponibilidad', 'travel-agency-platform'); ?></strong>
                            <p class="tap-rail-lead"><?php esc_html_e('Cada salida es una fecha + hora con su propia capacidad. Si no generas salidas, el tour no aparecerá como reservable.', 'travel-agency-platform'); ?></p>
                        </div>
                    <?php elseif (5 === $step): ?>
                        <div class="tap-rail-card tap-rail-card-help">
                            <strong><?php esc_html_e('¿Necesitas ayuda?', 'travel-agency-platform'); ?></strong>
                            <p class="tap-rail-lead"><?php esc_html_e('Si un revisor rechaza tu tour, verás aquí el motivo y podrás corregirlo sin empezar de nuevo.', 'travel-agency-platform'); ?></p>
                            <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>"><?php esc_html_e('Volver al panel', 'travel-agency-platform'); ?></a>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>

            <?php
            $prev_url = $step > 1 ? $edit_base . '&step=' . ($step - 1) : home_url('/manage-listing/');
            ?>
            <footer class="tap-wizard-foot">
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($prev_url); ?>">
                    <?php echo $step > 1 ? esc_html__('Atrás', 'travel-agency-platform') : esc_html__('Cancelar', 'travel-agency-platform'); ?>
                </a>
                <div class="tap-wizard-foot-hint">
                    <?php echo 5 === $step ? esc_html__('Guarda los cambios o publica cuando esté listo.', 'travel-agency-platform') : esc_html__('Guarda este paso para continuar.', 'travel-agency-platform'); ?>
                </div>
                <button type="submit" form="tap-tour-form" class="tap-btn tap-btn-primary tap-wizard-next">
                    <?php echo 5 === $step ? esc_html__('Guardar cambios', 'travel-agency-platform') : esc_html__('Guardar y continuar', 'travel-agency-platform'); ?>
                </button>
            </footer>
        </div>
        <?php
        echo self::render_tour_wizard_script($listing_id);
        return ob_get_clean();
    }
}
