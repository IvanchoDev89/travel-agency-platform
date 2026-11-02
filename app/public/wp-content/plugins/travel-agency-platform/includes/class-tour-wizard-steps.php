<?php
defined('ABSPATH') || exit;

/**
 * Step renderers for the tour wizard.
 *
 * The flow follows how a tour operator builds a product:
 *   1. Product basics (what it is, who it is for, when it runs).
 *   2. Itinerary and logistics (where it starts, what happens, what to bring).
 *   3. Pricing and availability (rates, group size, recurring departures).
 *   4. Requirements, policies and sustainability.
 *   5. Review and publish.
 */
class TAP_Tour_Wizard_Steps {

    /**
     * Progress shown in the rail and in the review step.
     *
     * @param int    $listing_id Tour id.
     * @return array<string,mixed>
     */
    public static function tour_wizard_progress($listing_id) {
        $listing_id = (int) $listing_id;
        $items      = [];
        $completed  = 0;

        $title      = $listing_id ? (string) get_the_title($listing_id) : '';
        $content    = $listing_id ? (string) get_post_field('post_content', $listing_id) : '';
        $thumb      = $listing_id ? (int) get_post_thumbnail_id($listing_id) : 0;
        $done1      = '' !== trim($title) && 'Auto Draft' !== $title && '' !== trim(wp_strip_all_tags($content));
        $items[1]   = [
            'step'   => 1,
            'label'  => __('Información', 'travel-agency-platform'),
            'done'   => $done1,
            'detail' => $done1 ? __('Ficha completa', 'travel-agency-platform') : __('Añade nombre y descripción', 'travel-agency-platform'),
        ];
        if ($done1) {
            ++$completed;
        }

        $days       = $listing_id ? (int) get_post_meta($listing_id, '_tap_tour_duration_days', true) : 0;
        $hours      = $listing_id ? (int) get_post_meta($listing_id, '_tap_tour_duration_hours', true) : 0;
        $meeting    = $listing_id ? (string) get_post_meta($listing_id, '_tap_tour_meeting_point', true) : '';
        $itinerary  = $listing_id ? TAP_Tour_Info::itinerary_rows($listing_id) : [];
        $done2      = ($days > 0 || $hours > 0 || '' !== (string) get_post_meta($listing_id, '_tap_tour_duration', true)) && '' !== trim($meeting) && !empty($itinerary);
        $items[2]   = [
            'step'   => 2,
            'label'  => __('Itinerario y logística', 'travel-agency-platform'),
            'done'   => $done2,
            'detail' => $done2 ? sprintf(
                /* translators: %d: number of itinerary stops */
                _n('%d parada', '%d paradas', count($itinerary), 'travel-agency-platform'),
                count($itinerary)
            ) : __('Define duración, punto de encuentro e itinerario', 'travel-agency-platform'),
        ];
        if ($done2) {
            ++$completed;
        }

        $price      = $listing_id ? TAP_Tour_Info::adult_price($listing_id) : 0.0;
        $capacity   = $listing_id ? (int) get_post_meta($listing_id, '_tap_tour_capacity', true) : 0;
        $next_slot  = $listing_id ? TAP_Tour_Info::next_slot($listing_id) : null;
        $has_slots  = $listing_id ? !empty(TAP_Tour_Availability::slots_for('tap_tour', $listing_id, gmdate('Y-m-d'))) : false;
        $done3      = $price > 0 && $capacity > 0 && $has_slots;
        $items[3]   = [
            'step'   => 3,
            'label'  => __('Precios y disponibilidad', 'travel-agency-platform'),
            'done'   => $done3,
            'detail' => $done3 ? __('Listo para recibir reservas', 'travel-agency-platform') : __('Falta precio, capacidad o fechas', 'travel-agency-platform'),
        ];
        if ($done3) {
            ++$completed;
        }

        $includes   = $listing_id ? (string) get_post_meta($listing_id, '_tap_tour_includes', true) : '';
        $policy     = $listing_id ? (string) get_post_meta($listing_id, '_tap_cancellation_policy', true) : '';
        $done4      = '' !== trim($includes) && '' !== trim($policy);
        $items[4]   = [
            'step'   => 4,
            'label'  => __('Políticas y detalles', 'travel-agency-platform'),
            'done'   => $done4,
            'detail' => $done4 ? __('Condiciones definidas', 'travel-agency-platform') : __('Añade inclusiones y cancelación', 'travel-agency-platform'),
        ];
        if ($done4) {
            ++$completed;
        }

        return [
            'completed' => $completed,
            'percent'   => (int) round(($completed / 4) * 100),
            'items'     => $items,
            'steps'     => $items,
        ];
    }

    /**
     * Currency data for the wizard.
     *
     * @param int $listing_id Tour id.
     * @return array{0:string,1:string,2:int}
     */
    public static function wizard_currency($listing_id) {
        $code = (string) get_post_meta((int) $listing_id, '_tap_tour_currency', true);
        if ('' === $code) {
            $code = TAP_Currency::code();
        }
        return [strtoupper($code), TAP_Currency::symbol($code), TAP_Currency::decimals($code)];
    }

    /* ────────────────────────────── Step 1 ────────────────────────────── */

    public static function render_step_info($listing_id) {
        $listing = $listing_id ? get_post($listing_id) : null;
        $title   = $listing ? $listing->post_title : '';
        $content = $listing ? $listing->post_content : '';
        $main_id = $listing_id ? (int) get_post_thumbnail_id($listing_id) : 0;
        $gallery = $listing_id ? array_filter(array_map('trim', explode(',', (string) get_post_meta($listing_id, '_tap_tour_gallery', true)))) : [];

        ob_start();
        ?>
        <form class="tap-manage-form tap-tour-step-form" id="tap-tour-form" data-step="1" data-next-step="2">
            <?php self::form_context($listing_id, 'tap_tour', true); ?>
            <div class="tap-wizard-section">
                <p class="tap-section-lead"><?php esc_html_e('Empieza por lo que verá el viajero: un nombre claro, una descripción que venda la experiencia y fotos que la muestren.', 'travel-agency-platform'); ?></p>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Lo esencial', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Lo que aparece arriba en la ficha pública del tour.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <div class="tap-field tap-span-2">
                            <label for="tap-tour-title"><?php esc_html_e('Nombre del tour', 'travel-agency-platform'); ?> <span class="tap-req">*</span></label>
                            <input id="tap-tour-title" type="text" name="title" required maxlength="120" value="<?php echo esc_attr($title); ?>" placeholder="<?php esc_attr_e('Ej.: Caminata al volcán Arenal al amanecer', 'travel-agency-platform'); ?>">
                            <span class="tap-field-hint"><?php esc_html_e('Sé concreto: tipo de actividad + lugar + algo diferencial.', 'travel-agency-platform'); ?></span>
                            <span class="tap-field-error" aria-live="polite"></span>
                        </div>
                        <div class="tap-field tap-span-2">
                            <label for="tap-tour-description"><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                            <textarea id="tap-tour-description" name="description" rows="5" placeholder="<?php esc_attr_e('Qué vive el viajero, qué hace especial este tour y a quién va dirigido.', 'travel-agency-platform'); ?>"><?php echo esc_textarea($content); ?></textarea>
                            <span class="tap-field-hint"><?php esc_html_e('2-3 párrafos. Evita repetir el itinerario: para eso está el paso 2.', 'travel-agency-platform'); ?></span>
                        </div>
                        <?php
                        echo self::field('_tap_tour_highlights', $listing_id, [
                            'type' => 'textarea',
                            'span' => true,
                            'hint' => __('3-5 razones por las que elegir este tour. Una por línea.', 'travel-agency-platform'),
                        ]);
                        ?>
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Fotos', 'travel-agency-platform'); ?></label>
                            <?php
                            echo TAP_Acc_Wizard_Room_Form::render_image_uploader($listing_id, $main_id, $gallery, [
                                'main_id'       => 'featured_attachment_id',
                                'gallery_ids'   => 'gallery_attachment_ids',
                                'main_url'      => 'featured_image_url',
                                'gallery_urls'  => 'gallery_urls',
                                'listing_id'    => $listing_id,
                                'listing_type'  => 'tap_tour',
                            ]);
                            ?>
                            <span class="tap-field-hint"><?php esc_html_e('La primera foto es la que se ve en el buscador. Usa imágenes horizontales y bien iluminadas.', 'travel-agency-platform'); ?></span>
                        </div>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Ficha del tour', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Clasificación y duración para que el viajero encuentre el tour adecuado.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_type', $listing_id, ['label' => __('Tipo de tour', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_category', $listing_id, ['label' => __('Categoría', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_difficulty', $listing_id, ['label' => __('Dificultad', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_fitness', $listing_id, ['label' => __('Nivel físico', 'travel-agency-platform')]);
                        ?>
                        <div class="tap-field">
                            <label for="tap-tour-duration-days"><?php esc_html_e('Duración (días)', 'travel-agency-platform'); ?></label>
                            <input id="tap-tour-duration-days" type="number" name="_tap_tour_duration_days" min="0" step="1" value="<?php echo esc_attr((string) get_post_meta($listing_id, '_tap_tour_duration_days', true)); ?>" placeholder="0">
                        </div>
                        <div class="tap-field">
                            <label for="tap-tour-duration-hours"><?php esc_html_e('Horas adicionales', 'travel-agency-platform'); ?></label>
                            <input id="tap-tour-duration-hours" type="number" name="_tap_tour_duration_hours" min="0" step="1" value="<?php echo esc_attr((string) get_post_meta($listing_id, '_tap_tour_duration_hours', true)); ?>" placeholder="<?php esc_attr_e('Ej.: 4', 'travel-agency-platform'); ?>">
                            <span class="tap-field-hint"><?php esc_html_e('Para tours de un día: deja días en 0 y usa las horas.', 'travel-agency-platform'); ?></span>
                        </div>
                        <?php self::languages_field($listing_id); ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Grupo y edades', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Define a quién aceptas y cuántas personas pueden reservar.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_min_age', $listing_id, ['label' => __('Edad mínima', 'travel-agency-platform'), 'hint' => __('0 si no hay restricción.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_max_age', $listing_id, ['label' => __('Edad máxima', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_min_people', $listing_id, ['label' => __('Mínimo de personas para operar', 'travel-agency-platform'), 'hint' => __('Si no se alcanza, el tour no sale. 0 = sin mínimo.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_max_people', $listing_id, ['label' => __('Máximo de personas por grupo', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_max_per_booking', $listing_id, ['label' => __('Máximo por reserva', 'travel-agency-platform'), 'hint' => __('Limita compras grandes o grupos privados.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_capacity', $listing_id, ['label' => __('Capacidad por horario', 'travel-agency-platform'), 'hint' => __('Plazas disponibles en cada salida.', 'travel-agency-platform')]);
                        ?>
                    </div>
                </section>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────── Step 2 ────────────────────────────── */

    public static function render_step_logistics($listing_id) {
        $listing_id = (int) $listing_id;
        wp_enqueue_style('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', true);
        $lat = (string) get_post_meta($listing_id, '_tap_tour_lat', true);
        $lng = (string) get_post_meta($listing_id, '_tap_tour_lng', true);

        ob_start();
        ?>
        <form class="tap-manage-form tap-tour-step-form" id="tap-tour-form" data-step="2" data-next-step="3">
            <?php self::form_context($listing_id, 'tap_tour', false); ?>
            <div class="tap-wizard-section">
                <p class="tap-section-lead"><?php esc_html_e('El viajero necesita saber dónde presentarse y qué vivirá minuto a minuto. Un itinerario claro reduce preguntas y cancelaciones.', 'travel-agency-platform'); ?></p>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Punto de encuentro', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('La dirección exacta y la hora a la que empieza la experiencia.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_departure_city', $listing_id, ['label' => __('Ciudad de salida', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_meeting_point', $listing_id, ['label' => __('Punto de encuentro', 'travel-agency-platform'), 'hint' => __('Dirección o referencia visible para el viajero.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_start_time', $listing_id, ['label' => __('Hora de inicio', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_end_time', $listing_id, ['label' => __('Hora de fin', 'travel-agency-platform')]);
                        ?>
                        <div class="tap-field">
                            <label for="tap-tour-lat"><?php esc_html_e('Latitud', 'travel-agency-platform'); ?></label>
                            <input id="tap-tour-lat" type="text" name="_tap_tour_lat" inputmode="decimal" value="<?php echo esc_attr($lat); ?>" placeholder="9.933">
                        </div>
                        <div class="tap-field">
                            <label for="tap-tour-lng"><?php esc_html_e('Longitud', 'travel-agency-platform'); ?></label>
                            <input id="tap-tour-lng" type="text" name="_tap_tour_lng" inputmode="decimal" value="<?php echo esc_attr($lng); ?>" placeholder="-84.083">
                        </div>
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Ubicación en el mapa', 'travel-agency-platform'); ?></label>
                            <div id="tap-tour-map" class="tap-tour-map" style="height:280px;"></div>
                            <span class="tap-field-hint"><?php esc_html_e('Arrastra el marcador o haz clic en el mapa para ajustar la ubicación.', 'travel-agency-platform'); ?></span>
                        </div>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Recogida', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Si recoges al viajero en su hotel, indícalo aquí.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_pickup', $listing_id, ['label' => __('Incluye traslado o recogida', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_pickup_locations', $listing_id, [
                            'type' => 'textarea',
                            'span' => true,
                            'hint' => __('Una zona o punto por línea. Ej.: Zona 1, Zona 2, Hotel X.', 'travel-agency-platform'),
                        ]);
                        ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Itinerario', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Describe las paradas en orden. En tours de varios días, agrupa por día.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <?php self::itinerary_field($listing_id); ?>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Qué incluye', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Sé honesto con lo incluido y lo no incluido: evita disputas en destino.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_includes', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Un elemento por línea. Ej.: Guía certificado, transporte, almuerzo.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_excludes', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Un elemento por línea. Ej.: Propinas, bebidas, seguro de viaje.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_what_bring', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Ropa, calzado, documentación o protección solar.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_equipment', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Equipo que entregas tú durante la actividad.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_equipment_rental', $listing_id, ['label' => __('Alquiler de equipo disponible', 'travel-agency-platform')]);
                        ?>
                    </div>
                </section>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────── Step 3 ────────────────────────────── */

    public static function render_step_pricing($listing_id) {
        $listing_id = (int) $listing_id;
        $capacity   = (int) get_post_meta($listing_id, '_tap_tour_capacity', true);
        $start_time = (string) get_post_meta($listing_id, '_tap_tour_start_time', true);
        $policies   = TAP_Refunds::cancellation_policies();
        $current    = (string) get_post_meta($listing_id, '_tap_cancellation_policy', true);
        if ('' === $current) {
            $current = TAP_Refunds::default_cancellation_policy('tap_tour');
        }

        ob_start();
        ?>
        <form class="tap-manage-form tap-tour-step-form" id="tap-tour-form" data-step="3" data-next-step="4">
            <?php self::form_context($listing_id, 'tap_tour', false); ?>
            <div class="tap-wizard-section">
                <p class="tap-section-lead"><?php esc_html_e('Define las tarifas, el tamaño del grupo y las salidas recurrentes. Sin fechas disponibles el tour no puede recibir reservas.', 'travel-agency-platform'); ?></p>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Tarifas por persona', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('El precio adulto es obligatorio. El resto son opcionales.', 'travel-agency-platform'); ?></p>
                        </div>
                        <span class="tap-chip tap-chip-muted"><?php echo esc_html(TAP_Currency::code()); ?></span>
                    </div>
                    <div class="tap-form-grid">
                        <div class="tap-field">
                            <label for="tap-tour-currency"><?php esc_html_e('Moneda', 'travel-agency-platform'); ?></label>
                            <select id="tap-tour-currency" name="_tap_tour_currency">
                                <?php foreach (TAP_Currency::codes() as $code): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected(strtoupper((string) get_post_meta($listing_id, '_tap_tour_currency', true) ?: TAP_Currency::code()), $code); ?>><?php echo esc_html($code); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php
                        echo self::field('_tap_tour_price_adult', $listing_id, ['label' => __('Precio adulto', 'travel-agency-platform'), 'required' => true, 'min' => '0.01', 'step' => '0.01']);
                        echo self::field('_tap_tour_price_child', $listing_id, ['label' => __('Precio niño', 'travel-agency-platform'), 'min' => '0', 'step' => '0.01']);
                        echo self::field('_tap_tour_child_age', $listing_id, ['label' => __('Rango de edad niño', 'travel-agency-platform'), 'hint' => __('Ej.: 5-12 años.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_price_senior', $listing_id, ['label' => __('Precio senior', 'travel-agency-platform'), 'min' => '0', 'step' => '0.01']);
                        echo self::field('_tap_tour_price_student', $listing_id, ['label' => __('Precio estudiante', 'travel-agency-platform'), 'min' => '0', 'step' => '0.01']);
                        ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Grupo y tour privado', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Controla cuántas personas entran por salida y ofrece una versión privada.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_capacity', $listing_id, ['label' => __('Capacidad por horario', 'travel-agency-platform'), 'required' => true, 'min' => '1']);
                        echo self::field('_tap_tour_deposit_pct', $listing_id, ['label' => __('Depósito', 'travel-agency-platform'), 'min' => '0', 'max' => '100', 'hint' => __('Porcentaje del total que se cobra al reservar. 0 = pago completo.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_booking_cutoff', $listing_id, ['label' => __('Cierre de reservas', 'travel-agency-platform'), 'min' => '0', 'hint' => __('Horas antes del inicio en que se dejan de aceptar reservas. 0 = sin límite.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_private', $listing_id, ['label' => __('Disponible como tour privado', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_private_price', $listing_id, ['label' => __('Precio tour privado', 'travel-agency-platform'), 'min' => '0', 'step' => '0.01', 'hint' => __('Precio total del grupo privado, no por persona.', 'travel-agency-platform')]);
                        ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Política de cancelación', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Se muestra en la ficha y se aplica a los reembolsos.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <div class="tap-field tap-span-2">
                            <label for="tap-tour-cancellation"><?php esc_html_e('Condiciones', 'travel-agency-platform'); ?></label>
                            <select id="tap-tour-cancellation" name="_tap_cancellation_policy">
                                <?php foreach ($policies as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>
            </div>
        </form>

        <?php if ($listing_id): ?>
            <section class="tap-manage-form tap-tour-sched" data-service="<?php echo (int) $listing_id; ?>">
                <div class="tap-block-head">
                    <div>
                        <h3 class="tap-block-title"><?php esc_html_e('Salidas y disponibilidad', 'travel-agency-platform'); ?></h3>
                        <p class="tap-block-sub"><?php esc_html_e('Genera los horarios recurrentes de la temporada. Puedes bloquear o eliminar salidas concretas.', 'travel-agency-platform'); ?></p>
                    </div>
                    <span class="tap-chip tap-chip-muted"><?php echo esc_html(TAP_Currency::code()); ?></span>
                </div>

                <div class="tap-sched-form">
                    <div class="tap-sched-grid">
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Desde', 'travel-agency-platform'); ?></span>
                            <input type="date" class="tap-sched-start" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>">
                        </label>
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Hasta', 'travel-agency-platform'); ?></span>
                            <input type="date" class="tap-sched-end" value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('+3 months'))); ?>">
                        </label>
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Horarios', 'travel-agency-platform'); ?></span>
                            <input type="text" class="tap-sched-times" value="<?php echo esc_attr('' !== $start_time ? substr($start_time, 0, 5) : '09:00'); ?>" placeholder="09:00, 14:00">
                            <small><?php esc_html_e('Separa varios horarios con comas.', 'travel-agency-platform'); ?></small>
                        </label>
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Capacidad por salida', 'travel-agency-platform'); ?></span>
                            <input type="number" class="tap-sched-capacity" min="1" step="1" value="<?php echo esc_attr((string) ($capacity > 0 ? $capacity : 12)); ?>">
                        </label>
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Precio por salida (opcional)', 'travel-agency-platform'); ?></span>
                            <input type="number" class="tap-sched-price" min="0" step="0.01" placeholder="<?php esc_attr_e('Vacío = precio base', 'travel-agency-platform'); ?>">
                        </label>
                        <label class="tap-sched-field">
                            <span><?php esc_html_e('Etiqueta (opcional)', 'travel-agency-platform'); ?></span>
                            <input type="text" class="tap-sched-label" maxlength="100" placeholder="<?php esc_attr_e('Ej.: Temporada alta', 'travel-agency-platform'); ?>">
                        </label>
                    </div>

                    <fieldset class="tap-sched-days">
                        <legend><?php esc_html_e('Días de la semana', 'travel-agency-platform'); ?></legend>
                        <?php
                        $dow = [
                            1 => __('Lun', 'travel-agency-platform'),
                            2 => __('Mar', 'travel-agency-platform'),
                            3 => __('Mié', 'travel-agency-platform'),
                            4 => __('Jue', 'travel-agency-platform'),
                            5 => __('Vie', 'travel-agency-platform'),
                            6 => __('Sáb', 'travel-agency-platform'),
                            7 => __('Dom', 'travel-agency-platform'),
                        ];
                        foreach ($dow as $value => $label):
                            ?>
                            <label class="tap-sched-day">
                                <input type="checkbox" class="tap-sched-day-input" value="<?php echo (int) $value; ?>" <?php checked($value <= 5); ?>>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="tap-sched-actions">
                        <button type="button" class="tap-btn tap-btn-primary tap-btn-sm tap-sched-generate"><?php esc_html_e('Generar horarios', 'travel-agency-platform'); ?></button>
                        <span class="tap-sched-msg" role="status" aria-live="polite"></span>
                    </div>
                </div>

                <div class="tap-sched-list-wrap">
                    <div class="tap-sched-list-head">
                        <strong><?php esc_html_e('Próximas salidas', 'travel-agency-platform'); ?></strong>
                        <span class="tap-sched-count"></span>
                    </div>
                    <ul class="tap-sched-list">
                        <?php
                        $slots = TAP_Tour_Availability::slots_payload($listing_id);
                        foreach ($slots as $slot) {
                            echo self::slot_row($slot);
                        }
                        ?>
                    </ul>
                    <p class="tap-sched-empty" <?php echo $slots ? 'hidden' : ''; ?>><?php esc_html_e('Todavía no hay salidas. Genera la primera temporada arriba.', 'travel-agency-platform'); ?></p>
                </div>
            </section>
        <?php else: ?>
            <div class="tap-wizard-callout">
                <p><?php esc_html_e('Guarda la información básica del tour para generar las salidas y la disponibilidad.', 'travel-agency-platform'); ?></p>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────── Step 4 ────────────────────────────── */

    public static function render_step_details($listing_id) {
        $listing_id = (int) $listing_id;
        ob_start();
        ?>
        <form class="tap-manage-form tap-tour-step-form" id="tap-tour-form" data-step="4" data-next-step="5">
            <?php self::form_context($listing_id, 'tap_tour', false); ?>
            <div class="tap-wizard-section">
                <p class="tap-section-lead"><?php esc_html_e('Aquí se definen las condiciones que protegen al viajero y a tu operación. Cuanto más claras, menos incidencias.', 'travel-agency-platform'); ?></p>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Requisitos y accesibilidad', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Ayuda al viajero a saber si este tour es para él.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_requirements', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Condición física, edad, documentación, experiencia previa…', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_accessibility', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Indica si es apto para movilidad reducida, sillas de ruedas, etc.', 'travel-agency-platform')]);
                        ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Ecoturismo', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Destaca las prácticas sostenibles de tu operación.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_leave_no_trace', $listing_id, ['label' => __('Sigue principios Leave No Trace', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_carbon_offset', $listing_id, ['label' => __('Compensación de carbono incluida', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_local_community', $listing_id, ['label' => __('Beneficia a la comunidad local', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_wildlife', $listing_id, ['label' => __('Incluye observación de fauna', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_trail', $listing_id, ['label' => __('Condición del sendero', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_elevation', $listing_id, ['label' => __('Desnivel (metros)', 'travel-agency-platform'), 'min' => '0']);
                        ?>
                    </div>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Waiver y datos legales', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('El waiver se muestra antes de confirmar la reserva cuando es obligatorio.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <?php
                        echo self::field('_tap_tour_waiver', $listing_id, ['type' => 'textarea', 'span' => true, 'hint' => __('Texto que el viajero acepta antes de reservar.', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_waiver_required', $listing_id, ['label' => __('Waiver obligatorio antes de reservar', 'travel-agency-platform')]);
                        echo self::field('_tap_tour_waiver_min_age', $listing_id, ['label' => __('Edad mínima para firmar el waiver', 'travel-agency-platform'), 'min' => '0']);
                        echo self::field('_tap_tour_terms', $listing_id, ['type' => 'textarea', 'span' => true]);
                        echo self::field('_tap_tour_privacy', $listing_id, ['type' => 'textarea', 'span' => true]);
                        ?>
                    </div>
                </section>

                <?php if ($listing_id): ?>
                    <?php $extras = TAP_Service_Extras::for_service('tap_tour', $listing_id); ?>
                    <section class="tap-card-block">
                        <div class="tap-block-head">
                            <div>
                                <h3 class="tap-block-title"><?php esc_html_e('Servicios extra', 'travel-agency-platform'); ?></h3>
                                <p class="tap-block-sub"><?php esc_html_e('Add-ons que el viajero puede sumar al reservar.', 'travel-agency-platform'); ?></p>
                            </div>
                            <span class="tap-chip tap-chip-muted"><?php echo esc_html(number_format_i18n(count($extras))); ?></span>
                        </div>
                        <?php if ($extras): ?>
                            <ul class="tap-tour-extras-list">
                                <?php foreach ($extras as $extra): ?>
                                    <li>
                                        <strong><?php echo esc_html($extra->name); ?></strong>
                                        <span><?php echo esc_html(TAP_Currency::fmt((float) $extra->price)); ?><?php echo $extra->is_per_person ? ' / ' . esc_html__('persona', 'travel-agency-platform') : ''; ?></span>
                                        <?php if ($extra->is_required): ?><span class="tap-chip tap-chip-muted"><?php esc_html_e('Obligatorio', 'travel-agency-platform'); ?></span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="tap-field-hint"><?php esc_html_e('No hay extras configurados. El equipo de la plataforma puede añadirlos desde administración.', 'travel-agency-platform'); ?></p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────── Step 5 ────────────────────────────── */

    public static function render_step_publish($listing_id) {
        $listing_id = (int) $listing_id;
        if (!$listing_id) {
            return '<div class="tap-wizard-callout"><p>' . esc_html__('Guarda primero la información del tour.', 'travel-agency-platform') . '</p></div>';
        }

        $listing     = get_post($listing_id);
        $progress    = self::tour_wizard_progress($listing_id);
        $is_live     = 'publish' === get_post_status($listing_id);
        $can_publish = current_user_can('publish_posts') || current_user_can('manage_options');
        $thumb       = get_post_thumbnail_id($listing_id);
        $price       = TAP_Tour_Info::adult_price($listing_id);
        $duration    = TAP_Tour_Info::duration_label($listing_id);
        $languages   = TAP_Tour_Info::languages($listing_id);
        $itinerary   = TAP_Tour_Info::itinerary_rows($listing_id);
        $next_slot   = TAP_Tour_Info::next_slot($listing_id);
        $policy_key  = (string) get_post_meta($listing_id, '_tap_cancellation_policy', true);
        $policies    = TAP_Refunds::cancellation_policies();
        $mode        = TAP_Booking::booking_mode('tap_tour', $listing_id);
        $edit_base   = home_url('/manage-listing/?id=' . $listing_id);

        ob_start();
        ?>
        <form class="tap-manage-form tap-tour-step-form" id="tap-tour-form" data-step="5" data-next-step="5">
            <?php self::form_context($listing_id, 'tap_tour', false); ?>
            <div class="tap-wizard-section">
                <p class="tap-section-lead"><?php esc_html_e('Revisa cómo se verá tu tour, completa el SEO y publica cuando esté listo.', 'travel-agency-platform'); ?></p>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Resumen', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Así lo verá el viajero en la ficha pública.', 'travel-agency-platform'); ?></p>
                        </div>
                        <?php if ($is_live): ?>
                            <span class="tap-chip tap-chip-success"><?php esc_html_e('Publicado', 'travel-agency-platform'); ?></span>
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
                                <span><?php esc_html_e('Duración', 'travel-agency-platform'); ?></span>
                                <strong><?php echo '' !== $duration ? esc_html($duration) : '—'; ?></strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Precio adulto', 'travel-agency-platform'); ?></span>
                                <strong><?php echo $price > 0 ? esc_html(TAP_Currency::fmt($price)) : '—'; ?></strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Idiomas', 'travel-agency-platform'); ?></span>
                                <strong><?php echo $languages ? esc_html(implode(', ', $languages)) : '—'; ?></strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Itinerario', 'travel-agency-platform'); ?></span>
                                <strong>
                                <?php
                                echo $itinerary
                                    /* translators: %d: number of stops */
                                    ? esc_html(sprintf(_n('%d parada', '%d paradas', count($itinerary), 'travel-agency-platform'), count($itinerary)))
                                    : '—';
                                ?>
                                </strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Próxima salida', 'travel-agency-platform'); ?></span>
                                <strong>
                                <?php
                                if ($next_slot) {
                                    echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $next_slot->date)));
                                    if (!empty($next_slot->time_slot)) {
                                        echo ' · ' . esc_html(substr((string) $next_slot->time_slot, 0, 5));
                                    }
                                } else {
                                    echo '—';
                                }
                                ?>
                                </strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Cancelación', 'travel-agency-platform'); ?></span>
                                <strong><?php echo esc_html(isset($policies[$policy_key]) ? $policies[$policy_key] : '—'); ?></strong>
                            </div>
                            <div class="tap-review-row">
                                <span><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></span>
                                <strong><?php echo 'request' === $mode ? esc_html__('Solicitud: la agencia confirma', 'travel-agency-platform') : esc_html__('Reserva directa', 'travel-agency-platform'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="tap-review-actions">
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=1'); ?>"><?php esc_html_e('Editar información', 'travel-agency-platform'); ?></a>
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_base . '&step=2'); ?>"><?php esc_html_e('Editar itinerario', 'travel-agency-platform'); ?></a>
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
                            <p class="tap-block-sub"><?php esc_html_e('Un tour completo convierte mejor y genera menos consultas.', 'travel-agency-platform'); ?></p>
                        </div>
                        <span class="tap-chip tap-chip-muted"><?php echo (int) $progress['percent']; ?>%</span>
                    </div>
                    <ul class="tap-checklist">
                        <?php foreach ($progress['items'] as $item): ?>
                            <li class="tap-check<?php echo $item['done'] ? ' is-done' : ''; ?>">
                                <span class="tap-check-mark" aria-hidden="true"><?php echo $item['done'] ? '&#10003;' : '&#9679;'; ?></span>
                                <span class="tap-check-text">
                                    <a href="<?php echo esc_url($edit_base . '&step=' . (int) $item['step']); ?>"><?php echo esc_html($item['label']); ?></a>
                                    <?php if ('' !== $item['detail']): ?><span class="tap-check-hint"><?php echo esc_html($item['detail']); ?></span><?php endif; ?>
                                </span>
                                <span class="tap-check-state"><?php echo $item['done'] ? esc_html__('Listo', 'travel-agency-platform') : esc_html__('Pendiente', 'travel-agency-platform'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="tap-card-block">
                    <div class="tap-block-head">
                        <div>
                            <h3 class="tap-block-title"><?php esc_html_e('Publicación y SEO', 'travel-agency-platform'); ?></h3>
                            <p class="tap-block-sub"><?php esc_html_e('Dónde se muestra y cómo aparece en Google.', 'travel-agency-platform'); ?></p>
                        </div>
                    </div>
                    <div class="tap-form-grid">
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Destino', 'travel-agency-platform'); ?></label>
                            <?php
                            $dest_sel = 0;
                            $dterms   = get_the_terms($listing_id, 'tap_location');
                            $highest  = -1;
                            if (is_array($dterms)) {
                                foreach ($dterms as $dt) {
                                    $level = TAP_Destinations::term_level($dt->term_id);
                                    $level = null !== $level ? $level : TAP_Destinations::term_depth($dt->term_id);
                                    if ($level > $highest) {
                                        $highest  = $level;
                                        $dest_sel = (int) $dt->term_id;
                                    }
                                }
                            }
                            echo TAP_Destinations::render_picker($dest_sel, 'destination');
                            ?>
                            <span class="tap-field-hint"><?php esc_html_e('El destino conecta el tour con el buscador y la ficha de destino.', 'travel-agency-platform'); ?></span>
                        </div>
                        <?php
                        echo self::field('_tap_seo_title', $listing_id, ['span' => true, 'label' => __('Título SEO', 'travel-agency-platform'), 'hint' => __('Máximo 60 caracteres. Si lo dejas vacío se usa el nombre del tour.', 'travel-agency-platform')]);
                        echo self::field('_tap_seo_description', $listing_id, ['type' => 'textarea', 'span' => true, 'label' => __('Meta descripción', 'travel-agency-platform'), 'hint' => __('Máximo 155 caracteres. Resume el tour y el lugar.', 'travel-agency-platform')]);
                        ?>
                        <div class="tap-field">
                            <label for="tap-tour-booking-mode"><?php esc_html_e('Modo de reserva', 'travel-agency-platform'); ?></label>
                            <select id="tap-tour-booking-mode" name="booking_mode">
                                <option value="instant" <?php selected(TAP_Booking::booking_mode('tap_tour', $listing_id), 'instant'); ?>><?php esc_html_e('Reserva directa (pago inmediato)', 'travel-agency-platform'); ?></option>
                                <option value="request" <?php selected(TAP_Booking::booking_mode('tap_tour', $listing_id), 'request'); ?>><?php esc_html_e('Solicitud de reserva (la agencia confirma antes del pago)', 'travel-agency-platform'); ?></option>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="tap-card-block tap-publish-box<?php echo $is_live ? ' is-live' : ''; ?>">
                    <div class="tap-publish-inner">
                        <div>
                            <h3 class="tap-block-title">
                                <?php echo $is_live ? esc_html__('Tu tour está publicado', 'travel-agency-platform') : esc_html__('Publica tu tour', 'travel-agency-platform'); ?>
                            </h3>
                            <p class="tap-block-sub">
                                <?php if ($is_live): ?>
                                    <?php esc_html_e('Los viajeros ya pueden encontrarlo y reservar. Puedes despublicarlo en cualquier momento.', 'travel-agency-platform'); ?>
                                <?php elseif (!$can_publish): ?>
                                    <?php esc_html_e('Tu tour se enviará a revisión y el equipo lo aprobará antes de publicarlo.', 'travel-agency-platform'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('Cuando lo publiques aparecerá en el buscador. Asegúrate de tener precios y salidas configuradas.', 'travel-agency-platform'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="tap-publish-actions">
                            <button type="button" class="tap-btn <?php echo $is_live ? 'tap-btn-danger' : 'tap-btn-primary'; ?>" data-publish="<?php echo $is_live ? '0' : '1'; ?>">
                                <?php echo $is_live ? esc_html__('Despublicar', 'travel-agency-platform') : esc_html__('Publicar tour', 'travel-agency-platform'); ?>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────── helpers ───────────────────────────── */

    /**
     * Hidden context every step form needs to save through
     * TAP_Ajax_Listing::save_listing_data().
     *
     * `save_listing_data()` always writes post_title/post_content, so steps
     * after the first must resend the current values (hidden) or they would be
     * wiped on save.
     *
     * @param int  $listing_id  Tour id (0 when creating).
     * @param string $type      Post type.
     * @param bool $with_content Whether the step renders title/description.
     */
    private static function form_context($listing_id, $type, $with_content) {
        wp_nonce_field('tap_agency_listing_nonce', 'nonce');
        ?>
        <input type="hidden" name="listing_id" value="<?php echo (int) $listing_id; ?>">
        <input type="hidden" name="listing_type" value="<?php echo esc_attr($type); ?>">
        <?php if (!$with_content): ?>
            <input type="hidden" name="title" value="<?php echo esc_attr($listing_id ? get_the_title($listing_id) : ''); ?>">
            <textarea name="description" hidden><?php echo esc_textarea($listing_id ? (string) get_post_field('post_content', $listing_id) : ''); ?></textarea>
        <?php endif; ?>
        <?php
    }

    /**
     * Render one field from the tour field map.
     *
     * @param string $key        Meta key.
     * @param int    $listing_id Tour id.
     * @param array<string,mixed> $args Overrides (type, label, hint, span, required, min, max, step).
     * @return string
     */
    private static function field($key, $listing_id, $args = []) {
        $fields = TAP_Metaboxes::get_fields('tap_tour');
        if (!isset($fields[$key])) {
            return '';
        }

        $cfg      = $fields[$key];
        $type     = isset($args['type']) ? $args['type'] : (isset($cfg['type']) ? $cfg['type'] : 'text');
        $label    = isset($args['label']) ? $args['label'] : (isset($cfg['label']) ? $cfg['label'] : $key);
        $hint     = isset($args['hint']) ? $args['hint'] : '';
        $required = !empty($args['required']);
        $span     = !empty($args['span']);
        $min      = isset($args['min']) ? (string) $args['min'] : null;
        $max      = isset($args['max']) ? (string) $args['max'] : null;
        $step     = isset($args['step']) ? (string) $args['step'] : (isset($cfg['step']) ? (string) $cfg['step'] : null);
        $value    = $listing_id ? get_post_meta($listing_id, $key, true) : (isset($cfg['default']) ? $cfg['default'] : '');
        $id       = 'tap-f-' . sanitize_html_class(ltrim($key, '_'));
        $options  = isset($cfg['options']) && is_array($cfg['options']) ? $cfg['options'] : [];

        ob_start();
        ?>
        <div class="tap-field<?php echo $span ? ' tap-span-2' : ''; ?>">
            <?php if ('checkbox' === $type): ?>
                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
                <label class="tap-check-label" for="<?php echo esc_attr($id); ?>">
                    <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" value="1" <?php checked('1', (string) $value); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php else: ?>
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?><?php if ($required): ?> <span class="tap-req">*</span><?php endif; ?></label>
                <?php if ('select' === $type): ?>
                    <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>"<?php echo $required ? ' required' : ''; ?>>
                        <option value=""><?php esc_html_e('Selecciona…', 'travel-agency-platform'); ?></option>
                        <?php foreach ($options as $option_value => $option_label): ?>
                            <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected((string) $value, (string) $option_value); ?>><?php echo esc_html((string) $option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ('textarea' === $type): ?>
                    <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" rows="<?php echo isset($args['rows']) ? (int) $args['rows'] : 3; ?>"<?php echo $required ? ' required' : ''; ?>><?php echo esc_textarea((string) $value); ?></textarea>
                <?php elseif ('time' === $type): ?>
                    <input id="<?php echo esc_attr($id); ?>" type="time" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $value); ?>"<?php echo $required ? ' required' : ''; ?>>
                <?php else: ?>
                    <input
                        id="<?php echo esc_attr($id); ?>"
                        type="<?php echo 'number' === $type ? 'number' : ('email' === $type ? 'email' : ('url' === $type ? 'url' : 'text')); ?>"
                        name="<?php echo esc_attr($key); ?>"
                        value="<?php echo esc_attr((string) $value); ?>"
                        <?php echo null !== $min ? 'min="' . esc_attr($min) . '"' : ''; ?>
                        <?php echo null !== $max ? 'max="' . esc_attr($max) . '"' : ''; ?>
                        <?php echo null !== $step ? 'step="' . esc_attr($step) . '"' : ''; ?>
                        <?php echo $required ? ' required' : ''; ?>
                    >
                <?php endif; ?>
            <?php endif; ?>
            <?php if ('' !== $hint): ?><span class="tap-field-hint"><?php echo esc_html($hint); ?></span><?php endif; ?>
            <?php if ('checkbox' !== $type): ?><span class="tap-field-error" aria-live="polite"></span><?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Languages offered by the guide, stored as a comma-separated list.
     *
     * @param int $listing_id Tour id.
     */
    private static function languages_field($listing_id) {
        $current = TAP_Tour_Info::languages($listing_id);
        ?>
        <div class="tap-field tap-span-2">
            <label><?php esc_html_e('Idiomas disponibles', 'travel-agency-platform'); ?></label>
            <input type="hidden" name="_tap_tour_languages_present" value="1">
            <div class="tap-chips tap-lang-chips">
                <?php foreach (TAP_Tour_Info::language_options() as $language): ?>
                    <label class="tap-chip">
                        <input type="checkbox" name="_tap_tour_languages[]" value="<?php echo esc_attr($language); ?>" <?php checked(in_array($language, $current, true)); ?>>
                        <span><?php echo esc_html($language); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <span class="tap-field-hint"><?php esc_html_e('Marca los idiomas en los que puedes guiar el tour.', 'travel-agency-platform'); ?></span>
        </div>
        <?php
    }

    /**
     * Itinerary builder. Rows are serialized to the plain-text meta format so
     * the same data stays editable in wp-admin.
     *
     * @param int $listing_id Tour id.
     */
    private static function itinerary_field($listing_id) {
        $rows = TAP_Tour_Info::itinerary_rows($listing_id);
        $text = TAP_Tour_Info::itinerary_to_text($rows);
        ?>
        <div class="tap-itin-builder">
            <div class="tap-itin-head">
                <span class="tap-itin-head-day"><?php esc_html_e('Día', 'travel-agency-platform'); ?></span>
                <span class="tap-itin-head-time"><?php esc_html_e('Hora', 'travel-agency-platform'); ?></span>
                <span class="tap-itin-head-title"><?php esc_html_e('Parada', 'travel-agency-platform'); ?></span>
                <span class="tap-itin-head-desc"><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></span>
            </div>
            <div class="tap-itin-rows"></div>
            <script type="application/json" class="tap-itin-seed"><?php echo wp_json_encode($rows, JSON_UNESCAPED_UNICODE); ?></script>
            <button type="button" class="tap-btn tap-btn-sm tap-itin-add">+ <?php esc_html_e('Añadir parada', 'travel-agency-platform'); ?></button>
            <input type="hidden" name="_tap_tour_itinerary" class="tap-itin-value" value="<?php echo esc_attr($text); ?>">
            <p class="tap-field-hint"><?php esc_html_e('Ejemplo: Día 1 · 09:00 | Bienvenida | Nos reunimos en la oficina y repartimos el equipo.', 'travel-agency-platform'); ?></p>
        </div>
        <?php
    }

    /**
     * One row in the upcoming-slots list (server render).
     *
     * @param array<string,mixed> $slot Slot payload.
     * @return string
     */
    private static function slot_row($slot) {
        $classes = 'tap-sched-item';
        if (!empty($slot['is_blocked'])) {
            $classes .= ' is-blocked';
        } elseif (!empty($slot['full'])) {
            $classes .= ' is-full';
        }
        ob_start();
        ?>
        <li class="<?php echo esc_attr($classes); ?>" data-slot="<?php echo (int) $slot['id']; ?>">
            <span class="tap-sched-date"><?php echo esc_html((string) $slot['date_label']); ?></span>
            <span class="tap-sched-time"><?php echo esc_html((string) ($slot['time_slot'] ? substr((string) $slot['time_slot'], 0, 5) : __('Todo el día', 'travel-agency-platform'))); ?></span>
            <span class="tap-sched-places">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: booked places, 2: capacity */
                    __('%1$s/%2$s reservados', 'travel-agency-platform'),
                    number_format_i18n((int) $slot['booked']),
                    (int) $slot['capacity'] > 0 ? number_format_i18n((int) $slot['capacity']) : '∞'
                ));
                ?>
            </span>
            <?php if (!empty($slot['price_label'])): ?><span class="tap-sched-price"><?php echo esc_html((string) $slot['price_label']); ?></span><?php endif; ?>
            <?php if (!empty($slot['label'])): ?><span class="tap-sched-tag"><?php echo esc_html((string) $slot['label']); ?></span><?php endif; ?>
            <span class="tap-sched-item-actions">
                <button type="button" class="tap-btn tap-btn-sm tap-sched-op" data-op="<?php echo !empty($slot['is_blocked']) ? 'unblock' : 'block'; ?>">
                    <?php echo !empty($slot['is_blocked']) ? esc_html__('Desbloquear', 'travel-agency-platform') : esc_html__('Bloquear', 'travel-agency-platform'); ?>
                </button>
                <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-sched-op" data-op="delete" <?php echo (int) $slot['booked'] > 0 ? 'disabled' : ''; ?>><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
            </span>
        </li>
        <?php
        return ob_get_clean();
    }
}
