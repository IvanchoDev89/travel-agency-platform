<?php
/**
 * TAP_Shortcode_Search — catalog/search presentation shortcodes:
 * [tap_search], [tap_search_results], [tap_services], [tap_service_detail],
 * [tap_agencies], [tap_agency_detail] and [tap_featured].
 *
 * Extracted from TAP_Shortcodes (god class split, Fase 2). Shared query
 * helpers (ids_by_meta, active_listing_ids, exclude_pending) remain in
 * TAP_Shortcodes because agency_services still needs them.
 */
defined('ABSPATH') || exit;

class TAP_Shortcode_Search {
    public static function search_form($atts) {
        $atts = shortcode_atts(['type' => '', 'placeholder' => __('Where do you want to go?', 'travel-agency-platform')], $atts);
        $uid = wp_unique_id('tap-sf-');
        ob_start();
        ?>
        <div class="tap-search-form tap-search-box">
            <form method="get" action="<?php echo esc_url(home_url('/search-results')); ?>" class="tap-search-form-inner">
                <input type="hidden" name="tap_search" value="1">
                <div class="tap-search-fields">
                <div class="tap-search-field">
                    <label for="<?php echo esc_attr($uid); ?>-destino"><?php esc_html_e('Destination', 'travel-agency-platform'); ?></label>
                    <div class="tap-search-destino">
                        <input type="text" id="<?php echo esc_attr($uid); ?>-destino" name="keyword" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" class="tap-input" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="tap-hs-listbox">
                        <div class="tap-hs-suggestions tap-form-suggestions" role="listbox" aria-expanded="false"></div>
                    </div>
                </div>
                    <div class="tap-search-field">
                        <label for="<?php echo esc_attr($uid); ?>-check-in"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></label>
                        <input type="date" id="<?php echo esc_attr($uid); ?>-check-in" name="check_in" class="tap-input">
                    </div>
                    <div class="tap-search-field">
                        <label for="<?php echo esc_attr($uid); ?>-check-out"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></label>
                        <input type="date" id="<?php echo esc_attr($uid); ?>-check-out" name="check_out" class="tap-input">
                    </div>
                    <div class="tap-search-field">
                        <label for="<?php echo esc_attr($uid); ?>-guests"><?php esc_html_e('Guests', 'travel-agency-platform'); ?></label>
                        <input type="number" id="<?php echo esc_attr($uid); ?>-guests" name="guests" min="1" value="1" class="tap-input tap-input-sm">
                    </div>
                    <div class="tap-search-field">
                        <label>&nbsp;</label>
                        <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Search', 'travel-agency-platform'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function search_results($atts) {
        $atts = shortcode_atts(['per_page' => 20], $atts);

        $current_url = home_url(add_query_arg([]));

        $keyword   = sanitize_text_field($_GET['keyword'] ?? '');
        $type      = sanitize_text_field($_GET['type'] ?? '');
        $location  = intval($_GET['location'] ?? 0);
        $difficulty = sanitize_title($_GET['difficulty'] ?? '');
        $tour_type  = sanitize_title($_GET['tour_type'] ?? '');
        $min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? floatval($_GET['min_price']) : null;
        $max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? floatval($_GET['max_price']) : null;
        $sort      = sanitize_key($_GET['sort'] ?? 'relevance');

        // Difficulty and tour-type only apply to tours; force the tour type.
        if ($difficulty || $tour_type) {
            $type = 'tap_tour';
        }

        $service_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
        $types = $type && in_array($type, $service_types, true) ? [$type] : $service_types;
        $types = array_values(array_filter($types, function ($pt) {
            return (bool) TAP_Promotions::keys_for_type($pt);
        }));

        $args = [
            'post_type'      => $types,
            'posts_per_page' => intval($atts['per_page']) * count($types),
            'post_status'    => 'publish',
            'suppress_filters' => true,
            'no_found_rows'  => true,
        ];

        $tax_query = [];
        if ($location) {
            $tax_query[] = ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => $location];
        }
        if ($difficulty && in_array('tap_tour', $types, true) && term_exists($difficulty, 'tap_tour_difficulty')) {
            $tax_query[] = ['taxonomy' => 'tap_tour_difficulty', 'field' => 'slug', 'terms' => $difficulty];
        }
        if ($tour_type && in_array('tap_tour', $types, true) && term_exists($tour_type, 'tap_tour_type')) {
            $tax_query[] = ['taxonomy' => 'tap_tour_type', 'field' => 'slug', 'terms' => $tour_type];
        }
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $args = TAP_Approval::exclude_from_query($args, $types);

        if ($min_price !== null || $max_price !== null) {
            // Price bounds use a per-type price key, so resolve them with one
            // indexed query per type (identical to the legacy behaviour).
            $args['s'] = $keyword;
            $rows = [];
            foreach ($types as $pt) {
                $keys = TAP_Promotions::keys_for_type($pt);
                $pt_args = array_merge($args, [
                    'post_type'  => $pt,
                    'meta_query' => [
                        ['key' => $keys['active_key'], 'compare' => '=', 'value' => '1'],
                    ],
                ]);
                $price_key = TAP_API::get_price_key($pt);
                if ($price_key) {
                    if ($min_price !== null) {
                        $pt_args['meta_query'][] = ['key' => $price_key, 'value' => $min_price, 'type' => 'NUMERIC', 'compare' => '>='];
                    }
                    if ($max_price !== null) {
                        $pt_args['meta_query'][] = ['key' => $price_key, 'value' => $max_price, 'type' => 'NUMERIC', 'compare' => '<='];
                    }
                }
                $q = new WP_Query($pt_args);
                foreach ($q->posts as $p) {
                    $rows[] = $p;
                }
                wp_reset_postdata();
            }
        } else {
            // Common case: a single main query over every requested type. The
            // active flag is resolved to a post list with one indexed scan on
            // wp_postmeta (avoiding the multi-way meta JOIN that makes the OR
            // meta_query impractical on large tables).
            $args['s'] = $keyword;
            $args['post__in'] = TAP_Shortcodes::active_listing_ids($types) ?: [0];
            $q = new WP_Query($args);
            $rows = $q->posts ?: [];
            wp_reset_postdata();
        }

        // Sort: featured bucket first, then chosen order.
        usort($rows, function ($a, $b) use ($sort) {
            $fa = (int) TAP_Promotions::is_featured($a->ID);
            $fb = (int) TAP_Promotions::is_featured($b->ID);
            if ($fa !== $fb) {
                return $fb - $fa;
            }
            $pa = TAP_API::get_price_key($a->post_type) ? floatval(get_post_meta($a->ID, TAP_API::get_price_key($a->post_type), true)) : 0;
            $pb = TAP_API::get_price_key($b->post_type) ? floatval(get_post_meta($b->ID, TAP_API::get_price_key($b->post_type), true)) : 0;

            switch ($sort) {
                case 'price_asc':
                    return $pa <=> $pb;
                case 'price_desc':
                    return $pb <=> $pa;
                case 'rating':
                    $ra = TAP_API::get_rating_stats($a->post_type, $a->ID);
                    $rb = TAP_API::get_rating_stats($b->post_type, $b->ID);
                    return ($rb['avg'] ?? 0) <=> ($ra['avg'] ?? 0);
                case 'newest':
                    return strcmp($b->post_date, $a->post_date);
                default:
                    return strcmp($b->post_date, $a->post_date);
            }
        });

        $total = count($rows);

        if ('' === $keyword && !$type && !$location && !$difficulty && !$tour_type && $min_price === null && $max_price === null && $sort === 'relevance') {
            return self::search_filters($current_url, $type, $location, $sort, $min_price, $max_price, $difficulty, $tour_type)
                . '<p class="tap-no-results">' . esc_html__('Busca servicios por destino, nombre o tipo para ver resultados.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        echo self::search_filters($current_url, $type, $location, $sort, $min_price, $max_price, $difficulty, $tour_type);

        if ($location) {
            $bc = TAP_Destinations::breadcrumb($location);
            if ($bc) {
                echo '<nav class="tap-breadcrumb" aria-label="' . esc_attr__('Breadcrumb', 'travel-agency-platform') . '">' . esc_html($bc) . '</nav>';
            }
        }

        echo '<div class="tap-search-results">';
        if ($total) {
            /* translators: %d: number of results found. */
            echo '<p class="tap-results-count">' . esc_html(sprintf(_n('%d resultado encontrado', '%d resultados encontrados', $total, 'travel-agency-platform'), $total)) . '</p>';
        }
        echo '<div class="tap-services-grid">';
        foreach ($rows as $p) {
            self::render_result_card($p);
        }
        echo '</div>';
        if (!$total) {
            echo '<p class="tap-no-results">' . esc_html__('No encontramos resultados para tu búsqueda. Probá con otro destino, palabra clave o ajustá los filtros.', 'travel-agency-platform') . '</p>';
        }
        echo '</div>';

        return ob_get_clean();
    }

    protected static function search_filters($current_url, $type, $location, $sort, $min_price, $max_price, $difficulty = '', $tour_type = '') {
        $types = [
            ''    => __('Todos los servicios', 'travel-agency-platform'),
            'tap_accommodation' => __('Alojamientos', 'travel-agency-platform'),
            'tap_tour'          => __('Tours', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de Autos', 'travel-agency-platform'),
            'tap_boat'          => __('Barcos y Paseos', 'travel-agency-platform'),
            'tap_package'       => __('Paquetes', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipos y Alquileres', 'travel-agency-platform'),
        ];
        $sorts = [
            'relevance' => __('Más recientes', 'travel-agency-platform'),
            'price_asc' => __('Precio: menor a mayor', 'travel-agency-platform'),
            'price_desc'=> __('Precio: mayor a menor', 'travel-agency-platform'),
            'rating'    => __('Mejor valorados', 'travel-agency-platform'),
        ];
        ob_start();
        ?>
        <form class="tap-search-filters" method="get" action="<?php echo esc_url($current_url); ?>">
            <?php if (isset($_GET['keyword'])): ?>
                <input type="hidden" name="keyword" value="<?php echo esc_attr($keyword = sanitize_text_field($_GET['keyword'] ?? '')); ?>">
            <?php endif; ?>
            <label><?php esc_html_e('Tipo', 'travel-agency-platform'); ?>
                <select name="type">
                    <?php foreach ($types as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($type, $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="tap-field">
                <span class="tap-field-label"><?php esc_html_e('Ubicación', 'travel-agency-platform'); ?></span>
                <?php echo TAP_Destinations::render_picker($location, 'location'); ?>
            </div>
            <label><?php esc_html_e('Tipo de tour', 'travel-agency-platform'); ?>
                <select name="tour_type">
                    <option value="">—</option>
                    <?php
                    $tours = get_terms(['taxonomy' => 'tap_tour_type', 'hide_empty' => false, 'orderby' => 'name']);
                    if (!is_wp_error($tours)) {
                        foreach ($tours as $t) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($t->slug), selected($tour_type, $t->slug, false), esc_html(__($t->name, 'travel-agency-platform')));
                        }
                    }
                    ?>
                </select>
            </label>
            <label><?php esc_html_e('Dificultad', 'travel-agency-platform'); ?>
                <select name="difficulty">
                    <option value="">—</option>
                    <?php
                    $diffs = get_terms(['taxonomy' => 'tap_tour_difficulty', 'hide_empty' => false, 'orderby' => 'name']);
                    if (!is_wp_error($diffs)) {
                        foreach ($diffs as $d) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($d->slug), selected($difficulty, $d->slug, false), esc_html(__($d->name, 'travel-agency-platform')));
                        }
                    }
                    ?>
                </select>
            </label>
            <label><?php esc_html_e('Precio mín.', 'travel-agency-platform'); ?>
                <input type="number" name="min_price" min="0" step="0.01" value="<?php echo esc_attr($min_price !== null ? $min_price : ''); ?>" placeholder="0">
            </label>
            <label><?php esc_html_e('Precio máx.', 'travel-agency-platform'); ?>
                <input type="number" name="max_price" min="0" step="0.01" value="<?php echo esc_attr($max_price !== null ? $max_price : ''); ?>" placeholder="9999">
            </label>
            <label><?php esc_html_e('Ordenar', 'travel-agency-platform'); ?>
                <select name="sort">
                    <?php foreach ($sorts as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($sort, $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Aplicar', 'travel-agency-platform'); ?></button>
            <?php if ($type || $location || $difficulty || $tour_type || $min_price !== null || $max_price !== null || $sort !== 'relevance'): ?>
                <a class="tap-btn tap-btn-outline" href="<?php echo esc_url(remove_query_arg(['type', 'location', 'difficulty', 'tour_type', 'min_price', 'max_price', 'sort'])); ?>"><?php esc_html_e('Limpiar', 'travel-agency-platform'); ?></a>
            <?php endif; ?>
        </form>
        <?php
        return ob_get_clean();
    }

    protected static function render_result_card($post) {
        $type      = $post->post_type;
        $type_name = TAP_Post_Types::get_service_types()[$type] ?? ucfirst($type);
        $price_key = TAP_API::get_price_key($type);
        $price     = $price_key ? floatval(get_post_meta($post->ID, $price_key, true)) : 0;
        $featured  = TAP_Promotions::is_featured($post->ID);
        $city      = get_post_meta($post->ID, '_tap_' . (TAP_Promotions::prefix_for_type($type) ?: '') . '_city', true);
        $excerpt   = has_excerpt($post->ID) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 18);
        ?>
        <article class="tap-service-card">
            <?php if (has_post_thumbnail($post->ID)): ?>
                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo get_the_post_thumbnail($post->ID, 'medium', ['loading' => 'lazy', 'decoding' => 'async']); ?></a>
            <?php endif; ?>
            <div class="tap-service-card-body">
                <span class="tap-service-type"><?php echo esc_html($type_name); ?></span>
                <?php if ($featured): ?>
                    <span class="tap-featured-badge">★ <?php esc_html_e('Destacado', 'travel-agency-platform'); ?></span>
                <?php endif; ?>
                <h3><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                <?php if ($city): ?><p class="tap-service-city"><?php echo esc_html($city); ?></p><?php endif; ?>
                <p class="tap-service-excerpt"><?php echo esc_html($excerpt); ?></p>
                <?php if ($price > 0): ?>
                    <p class="tap-service-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></p>
                <?php endif; ?>
                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
            </div>
        </article>
        <?php
    }

    public static function services_list($atts) {
        $atts = shortcode_atts([
            'type'      => '',
            'limit'     => 12,
            'agency'    => '',
            'location'  => '',
            'featured'  => '',
            'columns'   => 3,
        ], $atts);

        $types = $atts['type'] ? (array) $atts['type'] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        $all_posts = [];
        foreach ($types as $pt) {
            $pt_args = [
                'post_type'      => $pt,
                'posts_per_page' => intval($atts['limit']),
                'post_status'    => 'publish',
            ];

            if ($atts['agency']) {
                $prefix = '_tap_' . TAP_Post_Types::meta_prefix($pt) . '_agency_id';
                $pt_args['meta_query'] = [
                    ['key' => $prefix, 'value' => intval($atts['agency'])],
                ];
            }

            if ($atts['location']) {
                $pt_args['tax_query'] = [
                    ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => intval($atts['location'])],
                ];
            }

            if ($atts['featured'] === 'yes') {
                $prefix = '_tap_' . TAP_Post_Types::meta_prefix($pt);
                $pt_args['meta_query'][] = ['key' => $prefix . '_is_featured', 'value' => '1'];
            }

            $pt_args = TAP_Approval::exclude_from_query($pt_args, [$pt]);

            $q = new WP_Query($pt_args);
            foreach ($q->posts as $p) {
                $all_posts[] = $p;
            }
        }

        if (empty($all_posts)) {
            return '<p class="tap-no-results">' . __('No services found.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        $tap_prev_post = $GLOBALS['post'] ?? null;
        ?>
        <div class="tap-services-grid" style="display: grid; grid-template-columns: repeat(<?php echo intval($atts['columns']); ?>, 1fr); gap: 20px;">
            <?php foreach ($all_posts as $post):
                setup_postdata($post); $GLOBALS['post'] = $post; ?>
                <div class="tap-service-card">
                    <?php if (has_post_thumbnail()): ?>
                        <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium', ['loading' => 'lazy', 'decoding' => 'async']); ?></a>
                    <?php endif; ?>
                    <div class="tap-service-card-body">
                        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                        <p class="tap-service-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
                        <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('View Details', 'travel-agency-platform'); ?></a>
                    </div>
                </div>
            <?php endforeach; wp_reset_postdata(); $GLOBALS['post'] = $tap_prev_post; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function service_detail($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post = get_post(intval($atts['id']));

        if (!$post) return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';

        $tap_prev_post = $GLOBALS['post'] ?? null;
        setup_postdata($post);
        $GLOBALS['post'] = $post;
        $type = $post->post_type;
        $prefix = '_tap_' . TAP_Post_Types::meta_prefix($type);

        ob_start();
        echo TAP_SEO::visible_breadcrumbs($post);
        ?>
        <div class="tap-service-detail">
            <?php
            $featured   = TAP_Promotions::is_featured($post->ID);
            $rating     = TAP_API::get_rating_stats($type, $post->ID);
            $agency_id  = (int) get_post_meta($post->ID, $prefix . '_agency_id', true);
            $agency     = $agency_id ? get_post($agency_id) : null;
            ?>
            <header class="tap-detail-header">
                <div class="tap-detail-titles">
                    <span class="tap-service-type"><?php echo esc_html(TAP_Post_Types::get_service_types()[$type] ?? ucfirst($type)); ?></span>
                    <?php if ($featured): ?>
                        <span class="tap-featured-badge">★ <?php esc_html_e('Destacado', 'travel-agency-platform'); ?></span>
                    <?php endif; ?>
                    <h1><?php the_title(); ?></h1>
                    <?php if ($rating['count'] > 0): ?>
                        <span class="tap-rating">
                            <?php echo esc_html(number_format($rating['avg'], 1)); ?> ★ <small><?php echo esc_html(sprintf(_n('(%d reseña)', '(%d reseñas)', (int) $rating['count'], 'travel-agency-platform'), (int) $rating['count'])); ?></small>
                        </span>
                    <?php endif; ?>
                    <?php if ($agency): ?>
                        <p class="tap-detail-agency"><?php esc_html_e('Operado por', 'travel-agency-platform'); ?> <a href="<?php echo esc_url(get_permalink($agency->ID)); ?>"><?php echo esc_html($agency->post_title); ?></a><?php if (get_post_meta($agency->ID, '_tap_agency_verified', true)): ?> <span class="tap-verified-badge" title="<?php esc_attr_e('Agencia verificada', 'travel-agency-platform'); ?>">✓</span><?php endif; ?></p>
                    <?php endif; ?>
                </div>
            </header>
            <?php if (has_post_thumbnail()): ?>
                <div class="tap-featured-image"><?php the_post_thumbnail('large', ['decoding' => 'async']); ?></div>
            <?php endif; ?>
            <div class="tap-content"><?php the_content(); ?></div>
            <div class="tap-meta-grid">
                <?php
                $price_fields = [
                    'tap_accommodation' => ['_tap_acc_price_per_night', __('Precio por noche', 'travel-agency-platform')],
                    'tap_tour' => ['_tap_tour_price_adult', __('Precio por adulto', 'travel-agency-platform')],
                    'tap_transport' => ['_tap_trans_price', __('Precio', 'travel-agency-platform')],
                    'tap_car_rental' => ['_tap_car_price_per_day', __('Precio por día', 'travel-agency-platform')],
                    'tap_boat' => ['_tap_boat_price_half', __('Precio medio día', 'travel-agency-platform')],
                    'tap_package' => ['_tap_pkg_price', __('Precio total', 'travel-agency-platform')],
                ];

                if (isset($price_fields[$type])) {
                    list($price_key, $price_label) = $price_fields[$type];
                    $price = get_post_meta($post->ID, $price_key, true);
                    if ($price) {
                        echo '<div class="tap-meta-item"><strong>' . esc_html($price_label) . ':</strong> <span class="tap-detail-price">' . esc_html(TAP_Currency::fmt(floatval($price))) . '</span></div>';
                    }
                }

                $exclude_keys = [$prefix . '_agency_id', $prefix . '_is_active', $price_key ?? '', $prefix . '_currency'];
                $all_meta = get_post_meta($post->ID);

                foreach ($all_meta as $key => $values) {
                    if (strpos($key, $prefix) !== 0) continue;
                    if (in_array($key, $exclude_keys)) continue;

                    $label = str_replace([$prefix . '_', '_'], ['', ' '], $key);
                    $label = ucwords(trim($label));
                    $value = $values[0];

                    if ($value === '1') $value = __('Sí', 'travel-agency-platform');
                    elseif ($value === '0') $value = __('No', 'travel-agency-platform');

                    echo '<div class="tap-meta-item"><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</div>';
                }
                ?>
            </div>
            <?php echo do_shortcode('[tap_booking_form service_type="' . esc_attr($type) . '" service_id="' . $post->ID . ']'); ?>
        </div>
        <?php
        wp_reset_postdata();
        $GLOBALS['post'] = $tap_prev_post;
        return ob_get_clean();
    }

    public static function agencies_list($atts) {
        $atts = shortcode_atts(['limit' => 20], $atts);

        $agencies = get_posts([
            'post_type'      => 'tap_agency',
            'posts_per_page' => intval($atts['limit']),
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tap_agency_verified', 'value' => '1'],
                ['key' => TAP_Approval::STATUS_META, 'value' => TAP_Approval::APPROVED],
                ['key' => TAP_Approval::ACTIVE_META, 'compare' => '!=', 'value' => '0'],
            ],
        ]);

        if (empty($agencies)) {
            return '<p>' . __('No agencies found.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        ?>
        <div class="tap-agencies-grid">
            <?php foreach ($agencies as $agency): ?>
                <div class="tap-agency-card">
                    <?php if (has_post_thumbnail($agency->ID)): ?>
                        <?php echo get_the_post_thumbnail($agency->ID, 'medium', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                    <?php endif; ?>
                    <h3><a href="<?php echo esc_url(get_permalink($agency->ID)); ?>"><?php echo esc_html($agency->post_title); ?></a></h3>
                    <p><?php echo esc_html(wp_trim_words($agency->post_excerpt ?: wp_trim_words($agency->post_content, 30), 20)); ?></p>
                    <a href="<?php echo esc_url(get_permalink($agency->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('View Profile', 'travel-agency-platform'); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_detail($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post = get_post(intval($atts['id']));

        if (!$post || $post->post_type !== 'tap_agency') {
            return '<p>' . __('Agency not found.', 'travel-agency-platform') . '</p>';
        }

        if (!TAP_Approval::is_approved($post->ID)) {
            $can_view = current_user_can('manage_options');
            if (!$can_view) {
                $owner_id = (int) get_post_meta($post->ID, '_tap_agency_user_id', true);
                $can_view = $owner_id === get_current_user_id();
            }
            if (!$can_view) {
                return '<div class="tap-agency-pending"><p>' . __('Esta agencia está en revisión y aún no es visible públicamente.', 'travel-agency-platform') . '</p></div>';
            }
        }

        $email = get_post_meta($post->ID, '_tap_agency_email', true);
        $phone = get_post_meta($post->ID, '_tap_agency_phone', true);
        $whatsapp = get_post_meta($post->ID, '_tap_agency_whatsapp', true);
        $website = get_post_meta($post->ID, '_tap_agency_website', true);
        $address = get_post_meta($post->ID, '_tap_agency_address', true);
        $city = get_post_meta($post->ID, '_tap_agency_city', true);
        $country = get_post_meta($post->ID, '_tap_agency_country', true);
        $is_verified = get_post_meta($post->ID, '_tap_agency_verified', true);

        ob_start();
        echo TAP_SEO::visible_breadcrumbs($post);
        ?>
        <div class="tap-agency-detail">
            <div class="tap-agency-header">
                <?php if (has_post_thumbnail($post->ID)): ?>
                    <div class="tap-agency-logo"><?php echo get_the_post_thumbnail($post->ID, 'medium', ['loading' => 'lazy', 'decoding' => 'async']); ?></div>
                <?php endif; ?>
                <div class="tap-agency-info">
                    <h1><?php echo esc_html($post->post_title); ?></h1>
                    <?php if ($is_verified): ?><span class="tap-badge tap-badge-success"><?php esc_html_e('Verified', 'travel-agency-platform'); ?></span><?php endif; ?>
                    <?php if ($city || $country): ?>
                        <p class="tap-agency-location"><?php echo esc_html($city . ($city && $country ? ', ' : '') . $country); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tap-agency-body">
                <div class="tap-content"><?php echo apply_filters('the_content', $post->post_content); ?></div>
                <div class="tap-agency-contact">
                    <h3><?php esc_html_e('Contact Information', 'travel-agency-platform'); ?></h3>
                    <?php if ($email): ?><p><strong><?php esc_html_e('Email:', 'travel-agency-platform'); ?></strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></p><?php endif; ?>
                    <?php if ($phone): ?><p><strong><?php esc_html_e('Phone:', 'travel-agency-platform'); ?></strong> <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a></p><?php endif; ?>
                    <?php if ($whatsapp): ?><p><strong><?php esc_html_e('WhatsApp:', 'travel-agency-platform'); ?></strong> <a href="https://wa.me/<?php echo esc_attr($whatsapp); ?>" target="_blank"><?php echo esc_html($whatsapp); ?></a></p><?php endif; ?>
                    <?php if ($website): ?><p><strong><?php esc_html_e('Website:', 'travel-agency-platform'); ?></strong> <a href="<?php echo esc_url($website); ?>" target="_blank"><?php echo esc_html($website); ?></a></p><?php endif; ?>
                    <?php if ($address): ?><p><strong><?php esc_html_e('Address:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($address); ?></p><?php endif; ?>
                </div>
                <?php echo do_shortcode('[tap_lead_form agency="' . (int) $post->ID . '" source="agency"]'); ?>
            </div>
            <h3><?php esc_html_e('Our Services', 'travel-agency-platform'); ?></h3>
            <?php echo do_shortcode('[tap_agency_services agency="' . $post->ID . '"]'); ?>
        </div>
        <?php
        return ob_get_clean();
    }


    public static function featured_services($atts) {
        $atts = shortcode_atts(['type' => '', 'limit' => 6], $atts);

        $types = $atts['type'] ? (array) $atts['type'] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $types = array_values(array_filter($types, function ($pt) {
            return (bool) TAP_Promotions::keys_for_type($pt);
        }));
        if (empty($types)) {
            return self::featured_services_empty();
        }

        // One main query for every requested type. The featured flag is
        // resolved with a single indexed scan on wp_postmeta instead of an
        // N-way OR meta_query JOIN.
        $flag_keys = [];
        foreach ($types as $pt) {
            $keys = TAP_Promotions::keys_for_type($pt);
            $flag_keys[] = $keys['flag_key'];
        }
        $q_args = [
            'post_type'      => $types,
            'posts_per_page' => intval($atts['limit']) * count($types),
            'post_status'    => 'publish',
            'post__in'       => TAP_Shortcodes::exclude_pending(TAP_Shortcodes::ids_by_meta($flag_keys, '1')) ?: [0],
            'no_found_rows'  => true,
        ];
        $q = new WP_Query($q_args);
        $all_posts = $q->posts ?: [];
        wp_reset_postdata();

        if (empty($all_posts)) {
            return self::featured_services_empty();
        }

        ob_start();
        $tap_prev_post = $GLOBALS['post'] ?? null;
        ?>
        <div class="tap-grid tap-grid-auto">
            <?php foreach ($all_posts as $post): setup_postdata($post); $GLOBALS['post'] = $post;
                $pt = $post->post_type;
                $price_key = TAP_API::get_price_key($pt);
                $price = $price_key ? floatval(get_post_meta($post->ID, $price_key, true)) : 0;
            ?>
            <div class="tap-card tap-service-card">
                <div class="tap-card-img-wrapper">
                    <?php if (has_post_thumbnail($post->ID)): ?>
                        <?php echo get_the_post_thumbnail($post->ID, 'medium', ['class' => 'tap-card-img', 'loading' => 'lazy', 'decoding' => 'async']); ?>
                    <?php else: ?>
                        <div class="tap-card-img" style="background: var(--tap-neutral-200); display: flex; align-items: center; justify-content: center; color: var(--tap-neutral-400); font-size: 2rem;">🏖️</div>
                    <?php endif; ?>
                    <span class="tap-badge tap-badge-warning top-right"><?php esc_html_e('Featured', 'travel-agency-platform'); ?></span>
                    <?php if ($price): ?>
                    <div class="tap-card-price"><span class="tap-card-price-amount"><?php echo esc_html(TAP_Currency::fmt0($price)); ?></span> <span class="tap-card-price-label"><?php esc_html_e('starting', 'travel-agency-platform'); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="tap-card-body">
                    <div class="tap-card-meta">
                        <span><?php echo esc_html(TAP_Post_Types::get_service_types()[$pt] ?? $pt); ?></span>
                    </div>
                    <h3 class="tap-card-title"><a href="<?php the_permalink(); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                    <p class="tap-card-text"><?php echo wp_trim_words(get_the_excerpt($post), 15); ?></p>
                    <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline tap-btn-block"><?php esc_html_e('View Details', 'travel-agency-platform'); ?></a>
                </div>
            </div>
            <?php endforeach; wp_reset_postdata(); $GLOBALS['post'] = $tap_prev_post; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function featured_services_empty() {
        return '<div class="tap-empty"><div class="tap-empty-icon">🏝️</div><h3>' . __('No featured services yet', 'travel-agency-platform') . '</h3><p>' . __('Check back soon for new featured listings.', 'travel-agency-platform') . '</p></div>';
    }

    /**
     * Post IDs whose wp_postmeta has any of $meta_keys set to $value.
     * One indexed scan instead of an N-way meta JOIN per service type.
     */
}
