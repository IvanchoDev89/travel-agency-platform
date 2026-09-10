<?php
/**
 * TAP_Itinerary — interactive itinerary builder (T3).
 *
 * Wizard: interests (tap_tour_type / tap_service_cat) -> destinations
 * (tap_location) -> budget ($min/$max + service types) -> matching services.
 * Progressive enhancement: JS uses AJAX; without JS a normal GET renders the
 * same results server-side. A shareable ?itin=<id,id> view summarises the
 * selected "mi itinerario" with per-service CTAs.
 */
defined('ABSPATH') || exit;

class TAP_Itinerary
{
    public static function init()
    {
        add_shortcode('tap_itinerary_builder', [self::class, 'render']);
        add_action('wp_ajax_tap_itinerary_search', [self::class, 'ajax_search']);
        add_action('wp_ajax_nopriv_tap_itinerary_search', [self::class, 'ajax_search']);
    }

    public static function service_types()
    {
        return ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
    }

    /** Human label + price suffix per service type (msgid-aligned). */
    public static function type_meta($type)
    {
        $map = [
            'tap_accommodation' => ['label' => __('Alojamiento', 'travel-agency-platform'), 'suffix' => __('/noche', 'travel-agency-platform')],
            'tap_tour'          => ['label' => __('Tour', 'travel-agency-platform'), 'suffix' => __('/persona', 'travel-agency-platform')],
            'tap_transport'     => ['label' => __('Transporte', 'travel-agency-platform'), 'suffix' => __('/trayecto', 'travel-agency-platform')],
            'tap_car_rental'    => ['label' => __('Renta de auto', 'travel-agency-platform'), 'suffix' => __('/día', 'travel-agency-platform')],
            'tap_boat'          => ['label' => __('Barco', 'travel-agency-platform'), 'suffix' => __('/día', 'travel-agency-platform')],
            'tap_package'       => ['label' => __('Paquete', 'travel-agency-platform'), 'suffix' => ''],
            'tap_equipment'     => ['label' => __('Equipo', 'travel-agency-platform'), 'suffix' => __('/día', 'travel-agency-platform')],
        ];
        return $map[$type] ?? ['label' => ucfirst(str_replace('tap_', '', $type)), 'suffix' => ''];
    }

    /** Wizard data: interests, categories, destinations (2 levels) + budget defaults. */
    public static function terms_data()
    {
        $interests = [];
        $tourTypes = get_terms(['taxonomy' => 'tap_tour_type', 'hide_empty' => false]);
        if (is_array($tourTypes) && !is_wp_error($tourTypes)) {
            foreach ($tourTypes as $t) {
                $interests[] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
            }
        }

        $categories = [];
        $cats = get_terms(['taxonomy' => 'tap_service_cat', 'hide_empty' => false]);
        if (is_array($cats) && !is_wp_error($cats)) {
            foreach ($cats as $t) {
                $categories[] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
            }
        }

        $destinations = [];
        $tops = get_terms(['taxonomy' => 'tap_location', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'number' => 40]);
        if (is_array($tops) && !is_wp_error($tops)) {
            foreach ($tops as $t) {
                $parentName = $t->name;
                $kids = get_terms(['taxonomy' => 'tap_location', 'parent' => (int) $t->term_id, 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC']);
                if (is_array($kids) && !is_wp_error($kids) && $kids) {
                    foreach ($kids as $c) {
                        $destinations[] = ['id' => (int) $c->term_id, 'name' => $c->name, 'parent' => $parentName, 'count' => (int) $c->count];
                        if (count($destinations) >= 40) {
                            break;
                        }
                    }
                    if (count($destinations) >= 40) {
                        break;
                    }
                } else {
                    $destinations[] = ['id' => (int) $t->term_id, 'name' => $t->name, 'parent' => '', 'count' => (int) $t->count];
                }
            }
        }

        return [
            'interests'    => $interests,
            'categories'   => $categories,
            'destinations' => $destinations,
            'budget'       => ['min' => 0, 'max' => 9999],
            'types'        => [
                ['value' => 'tap_accommodation', 'label' => __('Alojamiento', 'travel-agency-platform')],
                ['value' => 'tap_tour', 'label' => __('Tours y experiencias', 'travel-agency-platform')],
                ['value' => 'tap_transport', 'label' => __('Transporte', 'travel-agency-platform')],
                ['value' => 'tap_car_rental', 'label' => __('Renta de auto', 'travel-agency-platform')],
                ['value' => 'tap_boat', 'label' => __('Barco', 'travel-agency-platform')],
                ['value' => 'tap_package', 'label' => __('Paquetes', 'travel-agency-platform')],
            ],
        ];
    }

    /** Normalise URL/AJAX params into a safe query spec. */
    public static function parse_params($raw)
    {
        $interests = $categories = $locations = $types = [];
        $budget = ['min' => null, 'max' => null];

        foreach ((array) ($raw['interests'] ?? []) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug && term_exists($slug, 'tap_tour_type')) {
                $interests[] = $slug;
            }
        }
        foreach ((array) ($raw['categories'] ?? []) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug && term_exists($slug, 'tap_service_cat')) {
                $categories[] = $slug;
            }
        }
        foreach ((array) ($raw['locations'] ?? []) as $id) {
            $id = intval($id);
            if ($id && term_exists($id, 'tap_location')) {
                $locations[] = $id;
            }
        }
        foreach ((array) ($raw['types'] ?? []) as $t) {
            $t = sanitize_key($t);
            if (in_array($t, self::service_types(), true)) {
                $types[] = $t;
            }
        }
        if (isset($raw['min_price']) && $raw['min_price'] !== '') {
            $budget['min'] = max(0, floatval($raw['min_price']));
        }
        if (isset($raw['max_price']) && $raw['max_price'] !== '') {
            $budget['max'] = abs(floatval($raw['max_price']));
        }

        return [
            'interests'  => array_values(array_unique($interests)),
            'categories' => array_values(array_unique($categories)),
            'locations'  => array_values(array_unique($locations)),
            'types'      => array_values(array_unique($types)),
            'min_price'  => $budget['min'],
            'max_price'  => $budget['max'],
        ];
    }

    /**
     * Match services from established taxonomies + price band.
     * $relax drops the interest/category terms (keeps location + price).
     */
    public static function build_query($params, $relax = false)
    {
        $params = self::parse_params($params);
        $types  = $params['types'] ?: self::service_types();
        $rows   = [];

        foreach ($types as $pt) {
            $keys = TAP_Promotions::keys_for_type($pt);
            if (!$keys) {
                continue;
            }
            $args = [
                'post_type'         => $pt,
                'post_status'       => 'publish',
                'posts_per_page'    => 200,
                'meta_query'        => [['key' => $keys['active_key'], 'value' => '1']],
                'suppress_filters'  => true,
            ];

            $tax = [];
            if (!$relax) {
                if ($pt === 'tap_tour' && $params['interests']) {
                    $tax[] = ['taxonomy' => 'tap_tour_type', 'field' => 'slug', 'terms' => $params['interests']];
                }
                if ($params['categories']) {
                    $tax[] = ['taxonomy' => 'tap_service_cat', 'field' => 'slug', 'terms' => $params['categories']];
                }
            }
            if ($params['locations']) {
                $tax[] = ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => $params['locations']];
            }

            if (count($tax) > 1) {
                $tax['relation'] = 'AND';
            }
            if ($tax) {
                $args['tax_query'] = $tax;
            }

            $price_key = TAP_API::get_price_key($pt);
            if ($price_key) {
                if ($params['min_price'] !== null) {
                    $args['meta_query'][] = ['key' => $price_key, 'value' => $params['min_price'], 'type' => 'NUMERIC', 'compare' => '>='];
                }
                if ($params['max_price'] !== null) {
                    $args['meta_query'][] = ['key' => $price_key, 'value' => $params['max_price'], 'type' => 'NUMERIC', 'compare' => '<='];
                }
            }

            $args = TAP_Approval::exclude_from_query($args, [$pt]);

            $q = new WP_Query($args);
            foreach ($q->posts as $p) {
                $rows[] = $p;
            }
            wp_reset_postdata();
        }

        usort($rows, function ($a, $b) {
            $fa = (int) TAP_Promotions::is_featured($a->ID);
            $fb = (int) TAP_Promotions::is_featured($b->ID);
            if ($fa !== $fb) {
                return $fb - $fa;
            }
            $ra = TAP_API::get_rating_stats($a->post_type, $a->ID);
            $rb = TAP_API::get_rating_stats($b->post_type, $b->ID);
            $avga = (float) ($ra['avg'] ?? 0);
            $avgb = (float) ($rb['avg'] ?? 0);
            if ($avga !== $avgb) {
                return $avgb <=> $avga;
            }
            return strcmp($b->post_date, $a->post_date);
        });

        return array_slice($rows, 0, 60);
    }

    /** Shared card markup for a matching service (AJAX + server render). */
    public static function render_item_card($post)
    {
        $meta      = self::type_meta($post->post_type);
        $priceKey  = TAP_API::get_price_key($post->post_type);
        $price     = $priceKey ? floatval(get_post_meta($post->ID, $priceKey, true)) : 0;
        $rating    = TAP_API::get_rating_stats($post->post_type, $post->ID);
        $avg       = (float) ($rating['avg'] ?? 0);
        $count     = (int) ($rating['count'] ?? 0);

        $location = '';
        $terms = get_the_terms($post->ID, 'tap_location');
        if (is_array($terms) && $terms) {
            $location = $terms[0]->name;
        }

        $duration = '';
        if ($post->post_type === 'tap_tour') {
            $duration = (string) get_post_meta($post->ID, '_tap_tour_duration', true);
        }
        ?>
        <article class="tap-itin-item" data-id="<?php echo esc_attr($post->ID); ?>" data-type="<?php echo esc_attr($post->post_type); ?>" data-title="<?php echo esc_attr($post->post_title); ?>" data-price="<?php echo esc_attr($price); ?>">
            <div class="tap-itin-item-media">
                <?php if (has_post_thumbnail($post->ID)): ?>
                    <?php echo get_the_post_thumbnail($post->ID, 'medium'); ?>
                <?php else: ?>
                    <span class="tap-itin-item-ph"></span>
                <?php endif; ?>
                <span class="tap-itin-item-type"><?php echo esc_html($meta['label']); ?></span>
            </div>
            <div class="tap-itin-item-body">
                <h3><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                <?php if ($location || $duration): ?>
                    <p class="tap-itin-item-meta">
                        <?php if ($location): ?><span><?php echo esc_html($location); ?></span><?php endif; ?>
                        <?php if ($duration): ?><span><?php echo esc_html($duration); ?></span><?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($count > 0): ?>
                    <p class="tap-itin-item-rating" aria-label="<?php echo esc_attr($avg . '/5'); ?>">★ <?php echo esc_html(number_format_i18n($avg, 1)); ?></p>
                <?php endif; ?>
                <div class="tap-itin-item-footer">
                    <?php if ($price > 0): ?>
                        <span class="tap-itin-item-price"><?php echo esc_html(TAP_Currency::fmt0($price)) . esc_html($meta['suffix']); ?></span>
                    <?php else: ?>
                        <span class="tap-itin-item-price tap-itin-item-price-cta"><?php esc_html_e('Consultar precio', 'travel-agency-platform'); ?></span>
                    <?php endif; ?>
                    <button type="button" class="tap-btn tap-btn-sm tap-btn-primary tap-itin-add" data-id="<?php echo esc_attr($post->ID); ?>"><?php esc_html_e('Añadir al itinerario', 'travel-agency-platform'); ?></button>
                </div>
            </div>
        </article>
        <?php
    }

    /** Results grid used by AJAX, no-JS GET and the review step. */
    public static function render_results_html($params, $relaxed = false)
    {
        $posts = self::build_query($params, $relaxed);
        ob_start();
        if ($posts) {
            echo '<div class="tap-itin-results-grid">';
            foreach ($posts as $p) {
                self::render_item_card($p);
            }
            echo '</div>';
        } else {
            echo '<p class="tap-itin-results-empty">' . esc_html__('No encontramos servicios que matchen exactamente con tus intereses. Probá ampliar el presupuesto, quitar algún interés o elegir otro destino.', 'travel-agency-platform') . '</p>';
        }
        return ob_get_clean();
    }

    public static function ajax_search()
    {
        check_ajax_referer('tap_nonce', 'nonce');
        $params = self::parse_params($_POST);
        $relaxed = !empty($_POST['relaxed']);
        $html = self::render_results_html($params, $relaxed);
        wp_send_json_success([
            'html'  => $html,
            'total' => count(self::build_query($params, $relaxed)),
        ]);
    }

    /** Shareable "mi itinerario" view for ?itin=<id1,id2> (ids are post IDs). */
    public static function share_view($raw_ids)
    {
        $ids = [];
        foreach (explode(',', (string) $raw_ids) as $id) {
            $id = intval($id);
            if ($id) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        $posts = [];
        foreach ($ids as $id) {
            $p = get_post($id);
            if (!$p || $p->post_status !== 'publish' || !in_array($p->post_type, self::service_types(), true)) {
                continue;
            }
            $keys = TAP_Promotions::keys_for_type($p->post_type);
            if ($keys && get_post_meta($id, $keys['active_key'], true) !== '1') {
                continue;
            }
            $posts[] = $p;
        }

        ob_start();
        if (!$posts) {
            echo '<p class="tap-itin-results-empty">' . esc_html__('El itinerario que intentás abrir está vacío o ya no está disponible.', 'travel-agency-platform') . '</p>';
            return ob_get_clean();
        }

        $total = 0;
        foreach ($posts as $p) {
            $priceKey = TAP_API::get_price_key($p->post_type);
            $total += $priceKey ? floatval(get_post_meta($p->ID, $priceKey, true)) : 0;
        }
        ?>
        <div class="tap-itin-share">
            <div class="tap-itin-share-head">
                <p class="tap-itin-eyebrow"><?php esc_html_e('Tu itinerario', 'travel-agency-platform'); ?></p>
                <h2><?php esc_html_e('Así queda tu viaje', 'travel-agency-platform'); ?></h2>
                <p class="tap-itin-share-sub"><?php esc_html_e('Servicios seleccionados según tus intereses, destinos y presupuesto.', 'travel-agency-platform'); ?></p>
            </div>
            <ul class="tap-itin-share-list">
                <?php foreach ($posts as $p): $meta = self::type_meta($p->post_type);
                    $priceKey = TAP_API::get_price_key($p->post_type);
                    $price = $priceKey ? floatval(get_post_meta($p->ID, $priceKey, true)) : 0; ?>
                    <li>
                        <div class="tap-itin-share-item">
                            <?php if (has_post_thumbnail($p->ID)): ?><?php echo get_the_post_thumbnail($p->ID, 'thumbnail'); ?><?php endif; ?>
                            <div class="tap-itin-share-item-info">
                                <span class="tap-itin-item-type"><?php echo esc_html($meta['label']); ?></span>
                                <h3><?php echo esc_html($p->post_title); ?></h3>
                                <?php if ($price > 0): ?>
                                    <p><?php echo esc_html(TAP_Currency::fmt0($price)) . esc_html($meta['suffix']); ?></p>
                                <?php endif; ?>
                            </div>
                            <a class="tap-btn tap-btn-outline tap-btn-sm" href="<?php echo esc_url(get_permalink($p->ID)); ?>"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="tap-itin-share-foot">
                <p><?php esc_html_e('Total estimado', 'travel-agency-platform'); ?></p>
                <span class="tap-itin-share-total"><?php echo esc_html(TAP_Currency::fmt($total)); ?></span>
            </div>
            <p class="tap-itin-share-note"><?php esc_html_e('Monto estimado según tarifas publicadas por persona/noche. La reserva y el pago se gestionan directo con cada agencia.', 'travel-agency-platform'); ?></p>
            <button type="button" class="tap-btn tap-btn-secondary" onclick="window.print()"><?php esc_html_e('Imprimir itinerario', 'travel-agency-platform'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render($atts, $content = null)
    {
        $atts = shortcode_atts(['title' => ''], $atts, 'tap_itinerary_builder');

        if (isset($_GET['itin']) && $_GET['itin'] !== '') {
            return self::share_view($_GET['itin']);
        }

        wp_enqueue_script('tap-itinerary', TAP_PLUGIN_URL . 'assets/js/itinerary.js', ['jquery'], TAP_VERSION, true);
        wp_localize_script('tap-itinerary', 'tapItinerary', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('tap_nonce'),
            'view_url'      => get_permalink(),
            'data'          => self::terms_data(),
            'currency_symbol' => TAP_Currency::symbol(),
            'addText'       => __('Añadir al itinerario', 'travel-agency-platform'),
            'addedText'     => __('Agregado ✓', 'travel-agency-platform'),
            'errorText'     => __('Hubo un problema al cargar los resultados. Reintentá en unos segundos.', 'travel-agency-platform'),
            'emptyDestMsg'  => __('Todavía no hay destinos publicados. Podés continuar igual y explorar por intereses.', 'travel-agency-platform'),
        ]);

        $params = self::parse_params($_GET);

        $searched = !empty($_GET['itin_search']);
        $results = '';
        if ($searched) {
            $results = self::render_results_html($params);
        }

        ob_start();
        ?>
        <div class="tap-itin" id="tap-itin">
            <div class="tap-itin-head">
                <p class="tap-itin-eyebrow"><?php esc_html_e('Tu viaje, paso a paso', 'travel-agency-platform'); ?></p>
                <h2><?php echo $atts['title'] ? esc_html($atts['title']) : esc_html__('Armá tu itinerario', 'travel-agency-platform'); ?></h2>
                <p class="tap-itin-sub"><?php esc_html_e('Elegí tus intereses, destinos y presupuesto. En segundos encontrás experiencias que matchean con tu plan.', 'travel-agency-platform'); ?></p>
            </div>

            <div class="tap-itin-layout">
                <div class="tap-itin-main">
                    <form class="tap-itin-form" method="get" action="<?php echo esc_url(get_permalink()); ?>" data-itin-form>
                        <input type="hidden" name="itin_search" value="1">
                        <input type="hidden" name="interests[]" data-itin-fld="interests">
                        <input type="hidden" name="locations[]" data-itin-fld="locations">
                        <input type="hidden" name="types[]" data-itin-fld="types">
                        <input type="hidden" name="min_price" data-itin-fld="min_price">
                        <input type="hidden" name="max_price" data-itin-fld="max_price">

                        <section class="tap-itin-step is-active" data-itin-step="1">
                            <h3><?php esc_html_e('1. Lo que te apasiona', 'travel-agency-platform'); ?></h3>
                            <p><?php esc_html_e('Elegí uno o más intereses. Podés cambiar de idea cuando quieras.', 'travel-agency-platform'); ?></p>
                            <div class="tap-itin-chips" data-role="interests"></div>
                            <div class="tap-itin-chips tap-itin-chips-cat" data-role="categories"></div>
                        </section>

                        <section class="tap-itin-step" data-itin-step="2">
                            <h3><?php esc_html_e('2. ¿Hacia dónde viajás?', 'travel-agency-platform'); ?></h3>
                            <p><?php esc_html_e('Podés combinar varios destinos en un mismo itinerario.', 'travel-agency-platform'); ?></p>
                            <div class="tap-itin-dest-grid" data-role="destinations"></div>
                        </section>

                        <section class="tap-itin-step" data-itin-step="3">
                            <h3><?php esc_html_e('3. Presupuesto y tipo de servicio', 'travel-agency-platform'); ?></h3>
                            <p><?php esc_html_e('Dejá libre el rango si querés que te mostremos todo.', 'travel-agency-platform'); ?></p>
                            <div class="tap-itin-budget">
                                <label><?php esc_html_e('Presupuesto mínimo', 'travel-agency-platform'); ?><input type="number" name="itin_min" min="0" step="1" data-role="min_price" placeholder="0"></label>
                                <span class="tap-itin-budget-sep"></span>
                                <label><?php esc_html_e('Presupuesto máximo', 'travel-agency-platform'); ?><input type="number" name="itin_max" min="0" step="1" data-role="max_price" placeholder="<?php echo esc_attr(number_format_i18n(9999)); ?>"></label>
                            </div>
                            <fieldset class="tap-itin-types" data-role="types">
                                <legend><?php esc_html_e('Incluir', 'travel-agency-platform'); ?></legend>
                            </fieldset>
                            <p class="tap-itin-budget-note"><?php esc_html_e('La búsqueda se hace en servicios activos publicados por agencias verificadas.', 'travel-agency-platform'); ?></p>
                        </section>

                        <div class="tap-itin-nav">
                            <button type="button" class="tap-btn tap-btn-outline tap-itin-prev" data-itin-prev disabled><?php esc_html_e('Anterior', 'travel-agency-platform'); ?></button>
                            <button type="button" class="tap-btn tap-btn-primary tap-itin-next" data-itin-next><?php esc_html_e('Continuar', 'travel-agency-platform'); ?></button>
                            <button type="submit" class="tap-btn tap-btn-secondary tap-itin-run" data-itin-run hidden><?php esc_html_e('Buscar mi itinerario', 'travel-agency-platform'); ?></button>
                        </div>
                    </form>

                    <div class="tap-itin-results" data-itin-results>
                        <?php if ($results): echo $results; else: echo '<noscript><p class="tap-itin-nojs">' . esc_html__('Activá JavaScript para usar el constructor paso a paso, o usá la búsqueda por destino si preferís un flujo simple.', 'travel-agency-platform') . '</p></noscript>'; endif; ?>
                    </div>
                </div>

                <aside class="tap-itin-side">
                    <div class="tap-itin-side-card">
                        <h3><?php esc_html_e('Mi itinerario', 'travel-agency-platform'); ?></h3>
                        <ul class="tap-itin-side-list" data-itin-list></ul>
                        <p class="tap-itin-side-empty" data-itin-empty><?php esc_html_e('Todavía no agregaste ninguna experiencia.', 'travel-agency-platform'); ?></p>
                        <p class="tap-itin-side-total" hidden><span><?php esc_html_e('Total estimado', 'travel-agency-platform'); ?></span><b data-itin-total>0</b></p>
                        <a class="tap-btn tap-btn-primary tap-btn-sm tap-itin-view" data-itin-view hidden href="#"><?php esc_html_e('Ver mi itinerario', 'travel-agency-platform'); ?></a>
                        <button type="button" class="tap-btn tap-btn-outline tap-btn-sm tap-itin-clear" data-itin-clear><?php esc_html_e('Vaciar', 'travel-agency-platform'); ?></button>
                    </div>
                    <p class="tap-itin-side-note"><?php esc_html_e('La reserva final se hace directo con cada agencia.', 'travel-agency-platform'); ?></p>
                </aside>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}