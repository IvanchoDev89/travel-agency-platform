<?php
defined('ABSPATH') || exit;

/**
 * Tour/experience wizard: URL-based multi-step flow (consistent with TAP_Acc_Wizard).
 * Steps: 1. Información, 2. Logística, 3. Tarifas y disponibilidad, 4. Detalles y legales, 5. Publicar.
 */
class TAP_Tour_Wizard {

    public static function render_tour_wizard_script($listing_id) {
        $is_edit = (bool) $listing_id;
        $edit_url = $is_edit ? home_url('/manage-listing/?id=' . (int) $listing_id) : '';

        wp_enqueue_script('tap-agency-wizard', TAP_PLUGIN_URL . 'assets/js/agency-wizard.js', [], TAP_VERSION, true);
        wp_enqueue_style('tap-agency-calendar', TAP_PLUGIN_URL . 'assets/css/agency-calendar.css', ['tap-public'], TAP_VERSION);
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
            ],
        ]);

        return '';
    }

    public static function render_tour_wizard($listing_id, $type) {
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
            2 => ['label' => __('Logística', 'travel-agency-platform'), 'hint' => __('Duración, horarios y punto de encuentro', 'travel-agency-platform')],
            3 => ['label' => __('Tarifas', 'travel-agency-platform'), 'hint' => __('Precios, capacidad y disponibilidad', 'travel-agency-platform')],
            4 => ['label' => __('Detalles y legales', 'travel-agency-platform'), 'hint' => __('Inclusiones, ecoturismo, T&C y waiver', 'travel-agency-platform')],
            5 => ['label' => __('Publicar', 'travel-agency-platform'), 'hint' => __('Revisa y publica tu tour', 'travel-agency-platform')],
        ];

        $progress = TAP_Tour_Wizard_Steps::tour_wizard_progress($listing_id, $type);
        $status   = $is_edit ? get_post_status($listing_id) : 'draft';
        $is_live  = 'publish' === $status && '1' === get_post_meta($listing_id, '_tap_tour_is_active', true);

        // UX pre-check: block entry when plan limit reached.
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
                            <p><?php printf(esc_html__('Your plan allows a maximum of %d listings. Upgrading your plan unlocks more.', 'travel-agency-platform'), (int) $limit); ?></p>
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
        <style>
        /* TAP agency back-office wizard + pricing/availability calendar. */

        /* ── shell ───────────────────────────────────────────────────────── */

        .tap-wizard {
            --tap-wizard-rail: 268px;
            max-width: 1280px;
            margin: 0 auto;
            width: 100%;
        }


        .tap-wizard-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--tap-space-5);
            flex-wrap: wrap;
            padding-bottom: var(--tap-space-4);
            border-bottom: 1px solid var(--tap-border);
        }


        .tap-wizard-head-main { min-width: 0; }


        .tap-wizard-back {
            display: inline-flex;
            align-items: center;
            gap: var(--tap-space-1);
            margin-bottom: var(--tap-space-2);
            color: var(--tap-neutral-500);
            font-size: var(--tap-text-sm);
            font-weight: 600;
            text-decoration: none;
        }

        .tap-wizard-back:hover { color: var(--tap-primary); }


        .tap-wizard-head .tap-panel-title { margin: 0; }

        .tap-wizard-head-meta {
            margin: var(--tap-space-1) 0 0;
            color: var(--tap-neutral-500);
            font-size: var(--tap-text-sm);
        }

        .tap-wizard-head-type { font-weight: 600; color: var(--tap-neutral-700); }

        .tap-wizard-head-sep { margin: 0 var(--tap-space-1); }


        .tap-wizard-head-side {
            display: flex;
            align-items: center;
            gap: var(--tap-space-2);
            flex-wrap: wrap;
        }


        .tap-chip-progress {
            display: inline-flex;
            align-items: center;
            gap: var(--tap-space-2);
            background: var(--tap-surface-muted);
            cursor: default;
            font-variant-numeric: tabular-nums;
        }

        .tap-chip-progress-bar {
            display: block;
            width: 72px;
            height: 6px;
            border-radius: var(--tap-radius-full);
            background: var(--tap-neutral-200);
            overflow: hidden;
        }

        .tap-chip-progress-bar > span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: var(--tap-primary);
            transition: width var(--tap-transition-slow);
        }


        /* ── stepper ─────────────────────────────────────────────────────── */

        .tap-wizard-nav { margin: var(--tap-space-5) 0 0; }


        .tap-wizard-steps {
            display: flex;
            gap: var(--tap-space-2);
            flex-wrap: wrap;
            list-style: none;
            margin: 0;
            padding: 0;
        }


        .tap-wizard-step {
            flex: 1 1 0;
            min-width: 120px;
            padding: 0;
            position: relative;
        }


        .tap-wizard-step a,
        .tap-wizard-step > span {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: var(--tap-space-2);
            padding: var(--tap-space-3);
            border: 1px solid var(--tap-border);
            border-radius: var(--tap-radius-lg);
            background: var(--tap-surface);
            color: var(--tap-neutral-600);
            font-size: var(--tap-text-sm);
            font-weight: 600;
            text-decoration: none;
            height: 100%;
            transition: border-color var(--tap-transition), background var(--tap-transition), box-shadow var(--tap-transition);
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .tap-wizard-step > span { cursor: default; }


        .tap-wizard-step a:hover {
            border-color: var(--tap-primary);
            box-shadow: var(--tap-shadow-md);
        }


        .tap-wizard-step .tap-wizard-step-num {
            flex: 0 0 36px;
            width: 36px;
            height: 36px;
            margin-bottom: var(--tap-space-1);
            border-radius: var(--tap-radius-full);
            background: var(--tap-neutral-200);
            color: var(--tap-neutral-600);
            font-size: var(--tap-text-sm);
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }


        .tap-wizard-step-label {
            display: block;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .tap-wizard-step-hint {
            display: block;
            font-weight: 400;
            font-size: var(--tap-text-xs);
            color: var(--tap-neutral-400);
            line-height: 1.35;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .tap-wizard-step a .tap-wizard-step-hint { color: var(--tap-neutral-500); }


        .tap-wizard-step.complete .tap-wizard-step-num {
            background: var(--tap-success);
            color: #fff;
        }

        .tap-wizard-step.complete .tap-wizard-step-label { color: var(--tap-neutral-700); }


        .tap-wizard-step.active a,
        .tap-wizard-step.active > span {
            border-color: var(--tap-primary);
            background: var(--tap-primary-bg);
            color: var(--tap-primary-dark);
            box-shadow: var(--tap-shadow-primary);
        }

        .tap-wizard-step.active .tap-wizard-step-num { background: var(--tap-primary); color: #fff; }

        .tap-wizard-step.active .tap-wizard-step-hint { color: var(--tap-primary-dark); }


        .tap-wizard-step.locked { opacity: 0.55; }


        /* ── layout ──────────────────────────────────────────────────────── */

        .tap-wizard-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) var(--tap-wizard-rail);
            gap: var(--tap-space-6);
            align-items: start;
            margin: var(--tap-space-6) 0 0;
        }


        .tap-wizard-body { min-width: 0; }

        .tap-wizard-section { display: grid; gap: var(--tap-space-4); }


        .tap-wizard-callout {
            display: flex;
            align-items: center;
            gap: var(--tap-space-4);
            flex-wrap: wrap;
            padding: var(--tap-space-5);
            border: 1px dashed var(--tap-border-strong);
            border-radius: var(--tap-radius-lg);
            background: var(--tap-surface-muted);
            color: var(--tap-neutral-600);
        }

        .tap-wizard-callout p { margin: 0; }


        /* ── rail ────────────────────────────────────────────────────────── */

        .tap-wizard-rail { display: grid; gap: var(--tap-space-4); position: sticky; top: var(--tap-space-5); }


        .tap-rail-card {
            border: 1px solid var(--tap-border);
            border-radius: var(--tap-radius-xl);
            background: var(--tap-surface);
            padding: var(--tap-space-5);
            box-shadow: var(--tap-shadow-md);
        }

        .tap-rail-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--tap-space-2);
            margin-bottom: var(--tap-space-2);
            font-size: var(--tap-text-base);
        }

        .tap-rail-count {
            font-size: var(--tap-text-xs);
            font-weight: 700;
            color: var(--tap-primary-dark);
            background: var(--tap-primary-bg);
            border-radius: var(--tap-radius-full);
            padding: 2px var(--tap-space-2);
            font-variant-numeric: tabular-nums;
        }

        .tap-rail-lead {
            margin: 0 0 var(--tap-space-4);
            color: var(--tap-neutral-500);
            font-size: var(--tap-text-xs);
            line-height: 1.55;
        }

        .tap-rail-list { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--tap-space-1); }


        .tap-rail-item {
            display: flex;
            align-items: flex-start;
            gap: var(--tap-space-3);
            padding: var(--tap-space-2);
            border-radius: var(--tap-radius-md);
        }

        .tap-rail-item:hover { background: var(--tap-surface-muted); }


        .tap-rail-dot {
            flex: 0 0 18px;
            width: 18px;
            height: 18px;
            margin-top: 1px;
            border-radius: var(--tap-radius-full);
            border: 1.5px solid var(--tap-neutral-300);
            background: var(--tap-surface);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 15px;
            text-align: center;
        }

        .tap-rail-item.is-done .tap-rail-dot { border-color: var(--tap-success); background: var(--tap-success); }


        .tap-rail-body { flex: 1 1 auto; min-width: 0; }

        .tap-rail-label { display: block; font-size: var(--tap-text-sm); font-weight: 600; line-height: 1.35; }

        .tap-rail-item.is-done .tap-rail-label { color: var(--tap-neutral-500); }

        .tap-rail-detail {
            display: block;
            font-size: var(--tap-text-xs);
            color: var(--tap-warning-text);
            line-height: 1.35;
        }

        .tap-rail-item.is-done .tap-rail-detail { color: var(--tap-neutral-400); }

        .tap-rail-link {
            flex: 0 0 auto;
            color: var(--tap-neutral-400);
            text-decoration: none;
            font-weight: 700;
            align-self: center;
        }

        .tap-rail-link:hover { color: var(--tap-primary); }


        .tap-rail-card-help .tap-btn { margin-top: var(--tap-space-3); }


        /* ── sticky footer ──────────────────────────────────────────────── */

        .tap-wizard-foot {
            position: sticky;
            bottom: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--tap-space-4);
            margin-top: var(--tap-space-6);
            padding: var(--tap-space-4) var(--tap-space-5);
            border: 1px solid var(--tap-border);
            border-radius: var(--tap-radius-xl);
            background: var(--tap-surface);
            box-shadow: var(--tap-shadow-lg);
        }

        .tap-wizard-foot-hint {
            flex: 1 1 auto;
            text-align: center;
            color: var(--tap-neutral-400);
            font-size: var(--tap-text-xs);
        }

        .tap-wizard-next { min-width: 190px; justify-content: center; }


        .tap-chip {
            display: inline-flex;
            align-items: center;
            padding: 2px var(--tap-space-2);
            border-radius: var(--tap-radius-full);
            font-size: var(--tap-text-xs);
            font-weight: 600;
            line-height: 1.6;
            white-space: nowrap;
        }

        .tap-chip-muted { background: var(--tap-neutral-100); color: var(--tap-neutral-600); }

        .tap-chip-success { background: var(--tap-success-bg); color: var(--tap-success-dark); }

            .tap-wizard-layout { grid-template-columns: minmax(0, 1fr); }

            .tap-wizard-rail { position: static; }

            .tap-wizard-step { flex: 1 1 calc(50% - var(--tap-space-2)); min-width: 0; }

            .tap-wizard-foot { flex-wrap: wrap; }

            .tap-wizard-foot-hint { order: 3; flex: 1 1 100%; }

            .tap-wizard-next { flex: 1 1 auto; min-width: 0; }
        @media (max-width: 1024px) {
            .tap-wizard-layout { grid-template-columns: minmax(0, 1fr); }
            .tap-wizard-rail { position: static; }
        }
        @media (max-width: 640px) {
            .tap-wizard-step { flex: 1 1 calc(50% - var(--tap-space-2)); min-width: 0; }
            .tap-wizard-foot { flex-wrap: wrap; }
            .tap-wizard-foot-hint { order: 3; flex: 1 1 100%; }
            .tap-wizard-next { flex: 1 1 auto; min-width: 0; }
        }
        </style>
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
                        if ($is_active) $cls .= ' active';
                        if ($locked) $cls .= ' locked';
                        elseif ($step_done && $n < 5) $cls .= ' complete';
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
                        echo TAP_Tour_Wizard_Steps::render_step_info($listing_id, $type);
                    } elseif ($step === 2) {
                        echo TAP_Tour_Wizard_Steps::render_step_logistics($listing_id, $type);
                    } elseif ($step === 3) {
                        echo TAP_Tour_Wizard_Steps::render_step_pricing($listing_id, $type);
                    } elseif ($step === 4) {
                        echo TAP_Tour_Wizard_Steps::render_step_details($listing_id, $type);
                    } else {
                        echo TAP_Tour_Wizard_Steps::render_step_publish($listing_id, $type);
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
                                if (5 === $item['step']) { continue; }
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
                                            aria-label="<?php echo esc_attr(sprintf(__('Ir a: %s', 'travel-agency-platform'), $item['label'])); ?>">&rarr;</a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
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
                    <button type="submit" form="tap-tour-policies-form" class="tap-btn tap-btn-primary tap-wizard-next">
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
        echo self::render_tour_wizard_script($listing_id);
        return ob_get_clean();
    }
}
