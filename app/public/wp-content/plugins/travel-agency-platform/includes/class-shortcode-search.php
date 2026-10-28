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

        $keyword   = sanitize_text_field(wp_unslash($_GET['keyword'] ?? ''));
        $type      = sanitize_text_field(wp_unslash($_GET['type'] ?? ''));
        $location  = intval($_GET['location'] ?? 0);
        $difficulty = sanitize_title(wp_unslash($_GET['difficulty'] ?? ''));
        $tour_type  = sanitize_title(wp_unslash($_GET['tour_type'] ?? ''));
        $min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? floatval($_GET['min_price']) : null;
        $max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? floatval($_GET['max_price']) : null;
        $sort      = sanitize_key(wp_unslash($_GET['sort'] ?? 'relevance'));

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
                <?php $keyword = sanitize_text_field(wp_unslash($_GET['keyword'] ?? '')); ?>
                <input type="hidden" name="keyword" value="<?php echo esc_attr($keyword); ?>">
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
                            printf('<option value="%s" %s>%s</option>', esc_attr($t->slug), selected($tour_type, $t->slug, false), esc_html($t->name));
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
                            printf('<option value="%s" %s>%s</option>', esc_attr($d->slug), selected($difficulty, $d->slug, false), esc_html($d->name));
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

        // Destination archive filter: the unified ficha links to
        // "/tour/?destino=la-fortuna", and the archive grid is rendered by this
        // shortcode (not by the main query), so the filter has to be applied
        // here too. An explicit `location` attribute still wins.
        $destination = null;
        if (! $atts['location']) {
            $dest_slug   = sanitize_title(wp_unslash($_GET['destino'] ?? ''));
            $destination = $dest_slug ? get_term_by('slug', $dest_slug, 'tap_location') : null;
        }

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
            } elseif ($destination instanceof WP_Term) {
                $pt_args['tax_query'] = [[
                    'taxonomy'         => 'tap_location',
                    'field'            => 'term_id',
                    'terms'            => (int) $destination->term_id,
                    'include_children' => true,
                ]];
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
            <?php
            foreach ($all_posts as $post):
                setup_postdata($post);
				$GLOBALS['post'] = $post;
                ?>
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
				<?php
            endforeach;
			wp_reset_postdata();
			$GLOBALS['post'] = $tap_prev_post;
			?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function service_detail($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post = get_post(intval($atts['id']));

        if (!$post) return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';
        // Tanda C (C8): only published services are public; drafts/pending
        // remain visible to their editor (owner/agency admin) for preview.
        if ('publish' !== $post->post_status && !current_user_can('edit_post', $post->ID)) {
            return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';
        }

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
                            <?php echo esc_html(number_format($rating['avg'], 1)); ?> ★ <small><?php echo esc_html(sprintf(/* translators: %d: número de reseñas */ _n('(%d reseña)', '(%d reseñas)', (int) $rating['count'], 'travel-agency-platform'), (int) $rating['count'])); ?></small>
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
                    if (in_array($key, $exclude_keys, true)) continue;

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
        $atts = shortcode_atts(['limit' => 24], $atts);

        $q        = isset($_GET['agency_q']) ? sanitize_text_field(wp_unslash($_GET['agency_q'])) : '';
        $city     = isset($_GET['agency_city']) ? sanitize_text_field(wp_unslash($_GET['agency_city'])) : '';
        $sort     = isset($_GET['agency_sort']) && in_array($_GET['agency_sort'], ['name', 'services'], true) ? $_GET['agency_sort'] : 'name';
        $paged    = max(1, isset($_GET['agency_page']) ? (int) $_GET['agency_page'] : 1);
        $per_page = max(1, (int) $atts['limit']);

        $meta_query = [
            ['key' => '_tap_agency_verified', 'value' => '1'],
            ['key' => TAP_Approval::STATUS_META, 'value' => TAP_Approval::APPROVED],
            // Legacy agencies have no ACTIVE_META and are approved by default;
            // a strict `!= 0` join silently hid them from the directory.
            [
                'relation' => 'OR',
                ['key' => TAP_Approval::ACTIVE_META, 'compare' => 'NOT EXISTS'],
                ['key' => TAP_Approval::ACTIVE_META, 'value' => '0', 'compare' => '!='],
            ],
        ];
        if ('' !== $city) {
            $meta_query[] = ['key' => '_tap_agency_city', 'value' => $city];
        }

        $args = [
            'post_type'      => 'tap_agency',
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ];
        if ('' !== $q) {
            $args['s'] = $q;
        }

        $ordered = get_posts($args);
        $stats   = self::agency_stats($ordered);
        if ('services' === $sort) {
            usort($ordered, static function ($a, $b) use ($stats) {
                return ((int) ($stats[(int) $b]['total'] ?? 0)) <=> ((int) ($stats[(int) $a]['total'] ?? 0));
            });
        }

        $total     = count($ordered);
        $max_pages = max(1, (int) ceil($total / $per_page));
        $paged     = min($paged, $max_pages);
        $page_ids  = array_slice($ordered, ($paged - 1) * $per_page, $per_page);
        $agencies  = $page_ids ? get_posts([
            'post_type'      => 'tap_agency',
            'post_status'    => 'publish',
            'post__in'       => $page_ids,
            'orderby'        => 'post__in',
            'posts_per_page' => count($page_ids),
        ]) : [];

        $cities  = self::agency_cities();
        $ratings = self::agency_ratings($page_ids);
        $covers  = self::agency_cover_ids($page_ids);
        $base    = get_post_type_archive_link('tap_agency') ?: home_url('/agency/');
        $active  = ('' !== $q || '' !== $city);

        ob_start();
        ?>
        <div class="tap-container">
            <section class="tap-agencies">
                <div class="tap-agencies-intro">
                    <div>
                        <p class="tap-agencies-eyebrow"><?php esc_html_e('Directorio verificado', 'travel-agency-platform'); ?></p>
                        <h2><?php esc_html_e('Encuentra tu agencia local', 'travel-agency-platform'); ?></h2>
                        <p class="tap-agencies-lead"><?php esc_html_e('Agencias y operadores aprobados por la plataforma. Compara sus servicios y contacta directamente.', 'travel-agency-platform'); ?></p>
                    </div>
                    <a class="tap-btn tap-btn-primary" href="<?php echo esc_url(home_url('/register/')); ?>"><?php esc_html_e('Publica tu agencia', 'travel-agency-platform'); ?></a>
                </div>

                <form class="tap-agencies-toolbar" method="get" action="<?php echo esc_url($base); ?>" role="search">
                    <?php if ('' !== $city): ?>
                        <input type="hidden" name="agency_city" value="<?php echo esc_attr($city); ?>">
                    <?php endif; ?>
                    <div class="tap-agencies-field">
                        <label class="tap-sr-only" for="tap-agency-q"><?php esc_html_e('Buscar agencias', 'travel-agency-platform'); ?></label>
                        <input type="search" id="tap-agency-q" name="agency_q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Buscar por nombre…', 'travel-agency-platform'); ?>">
                    </div>
                    <div class="tap-agencies-field tap-agencies-field-sort">
                        <label class="tap-sr-only" for="tap-agency-sort"><?php esc_html_e('Ordenar por', 'travel-agency-platform'); ?></label>
                        <select id="tap-agency-sort" name="agency_sort">
                            <option value="name" <?php selected($sort, 'name'); ?>><?php esc_html_e('Nombre (A-Z)', 'travel-agency-platform'); ?></option>
                            <option value="services" <?php selected($sort, 'services'); ?>><?php esc_html_e('Más servicios', 'travel-agency-platform'); ?></option>
                        </select>
                    </div>
                    <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Filtrar', 'travel-agency-platform'); ?></button>
                    <?php if ($active): ?>
                        <a class="tap-agencies-clear" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Limpiar filtros', 'travel-agency-platform'); ?></a>
                    <?php endif; ?>
                </form>

                <?php if (!empty($cities)): ?>
                    <nav class="tap-agencies-chips" aria-label="<?php esc_attr_e('Filtrar por ubicación', 'travel-agency-platform'); ?>">
                        <a class="tap-agency-chip <?php echo '' === $city ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_filter([
                            'agency_q'    => $q,
                            'agency_sort' => 'name' === $sort ? '' : $sort,
                        ], static fn($v) => '' !== $v), $base)); ?>" <?php echo '' === $city ? 'aria-current="true"' : ''; ?>><?php esc_html_e('Todas', 'travel-agency-platform'); ?></a>
                        <?php foreach ($cities as $c): ?>
                            <?php
                            $chip_url = add_query_arg(array_filter([
                                'agency_q'    => $q,
                                'agency_city' => $c,
                                'agency_sort' => 'name' === $sort ? '' : $sort,
                            ], static fn($v) => '' !== $v), $base);
                            $is_active = ($c === $city);
                            ?>
                            <a class="tap-agency-chip <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url($chip_url); ?>" <?php echo $is_active ? 'aria-current="true"' : ''; ?>><?php echo esc_html($c); ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <p class="tap-agencies-count">
                    <?php
                    printf(
                        /* translators: %d: number of agencies matching the filters */
                        esc_html(_n('%d agencia', '%d agencias', (int) $total, 'travel-agency-platform')),
                        (int) $total
                    );
                    ?>
                </p>

                <?php if ($agencies): ?>
                    <div class="tap-agencies-grid">
                        <?php foreach ($agencies as $agency): ?>
                            <?php
                            $agency_id = (int) $agency->ID;
                            $link      = get_permalink($agency_id);
                            $verified  = '1' === (string) get_post_meta($agency_id, '_tap_agency_verified', true);
                            $city_name = (string) get_post_meta($agency_id, '_tap_agency_city', true);
                            $country   = (string) get_post_meta($agency_id, '_tap_agency_country', true);
                            $location  = trim($city_name . ($city_name && $country ? ', ' : '') . $country);
                            $excerpt   = $agency->post_excerpt ?: wp_trim_words(wp_strip_all_tags($agency->post_content), 24);
                            $title     = wp_strip_all_tags($agency->post_title);
                            $initial   = function_exists('mb_substr') ? mb_substr($title, 0, 1) : substr($title, 0, 1);
                            $initial   = function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial);
                            $info      = $stats[$agency_id] ?? [];
                            $services  = (int) ($info['total'] ?? 0);
                            $types     = (array) ($info['types'] ?? []);
                            $rating    = $ratings[$agency_id] ?? null;
                            $cover_id  = (int) ($covers[$agency_id] ?? 0);
                            $cover_url = $cover_id ? get_the_post_thumbnail_url($cover_id, 'large') : '';
                            $variant   = 1 + ($agency_id % 5);
                            ?>
                            <article class="tap-agency-card">
                                <div class="tap-agency-card-media">
                                    <a class="tap-agency-card-cover<?php echo $cover_url ? '' : ' tap-agency-card-cover--g' . (int) $variant; ?>" href="<?php echo esc_url($link); ?>" tabindex="-1" aria-hidden="true">
                                        <?php if ($cover_url): ?>
                                            <img src="<?php echo esc_url($cover_url); ?>" alt="" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    </a>
                                    <a class="tap-agency-card-logo" href="<?php echo esc_url($link); ?>" tabindex="-1" aria-hidden="true">
                                        <?php if (has_post_thumbnail($agency_id)): ?>
                                            <?php echo get_the_post_thumbnail($agency_id, 'medium', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                                        <?php else: ?>
                                            <span class="tap-agency-card-initial"><?php echo esc_html($initial); ?></span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div class="tap-agency-card-body">
                                    <div class="tap-agency-card-head">
                                        <h3><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($agency->post_title); ?></a></h3>
                                        <?php if ($verified): ?>
                                            <span class="tap-agency-card-badge"><?php esc_html_e('Verificada', 'travel-agency-platform'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ('' !== $location || $rating): ?>
                                        <div class="tap-agency-card-subline">
                                            <?php if ('' !== $location): ?>
                                                <p class="tap-agency-card-location">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                                    <?php echo esc_html($location); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($rating): ?>
                                                <span class="tap-agency-card-rating" aria-label="<?php echo esc_attr(sprintf(
                                                    /* translators: 1: average rating, 2: number of reviews */
                                                    __('Valoración media %1$s de 5 (%2$d reseñas)', 'travel-agency-platform'),
                                                    number_format_i18n((float) $rating['avg'], 1),
                                                    (int) $rating['count']
                                                )); ?>">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26"/></svg>
                                                    <?php echo esc_html(number_format_i18n((float) $rating['avg'], 1)); ?>
                                                    <span>(<?php echo (int) $rating['count']; ?>)</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($excerpt): ?>
                                        <p class="tap-agency-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                                    <?php endif; ?>
                                    <?php if ($types): ?>
                                        <ul class="tap-agency-card-types" aria-label="<?php esc_attr_e('Tipos de servicio', 'travel-agency-platform'); ?>">
                                            <?php foreach ($types as $type => $count): ?>
                                                <?php
                                                $type_labels = TAP_Post_Types::get_service_types();
                                                $type_label  = $type_labels[$type] ?? $type;
                                                ?>
                                                <li title="<?php echo esc_attr($type_label . ': ' . (int) $count); ?>" aria-label="<?php echo esc_attr($type_label . ': ' . (int) $count); ?>">
                                                    <?php echo self::service_type_icon($type); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed inline SVG. ?>
                                                    <span><?php echo (int) $count; ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if ($services > 0): ?>
                                        <p class="tap-agency-card-meta">
                                            <?php
                                            printf(
                                                /* translators: %d: number of published services */
                                                esc_html(_n('%d servicio publicado', '%d servicios publicados', $services, 'travel-agency-platform')),
                                                $services
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <a class="tap-agency-card-cta" href="<?php echo esc_url($link); ?>">
                                    <?php esc_html_e('Ver perfil', 'travel-agency-platform'); ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($max_pages > 1): ?>
                        <nav class="tap-agencies-pagination" aria-label="<?php esc_attr_e('Paginación de agencias', 'travel-agency-platform'); ?>">
                            <?php
                            $base_url = add_query_arg(array_filter([
                                'agency_q'    => $q,
                                'agency_city' => $city,
                                'agency_sort' => 'name' === $sort ? '' : $sort,
                            ], static fn($v) => '' !== $v), $base);
                            $base_url .= (false === strpos($base_url, '?') ? '?' : '&') . 'agency_page=%#%';
                            $links = paginate_links([
                                'base'      => $base_url,
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $max_pages,
                                'mid_size'  => 1,
                                'end_size'  => 1,
                                'prev_text' => __('‹ Anterior', 'travel-agency-platform'),
                                'next_text' => __('Siguiente ›', 'travel-agency-platform'),
                                'type'      => 'array',
                            ]);
                            if ($links) {
                                echo '<ul>';
                                foreach ($links as $link_html) {
                                    echo '<li>' . $link_html . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- paginate_links markup.
                                }
                                echo '</ul>';
                            }
                            ?>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="tap-agencies-empty">
                        <h3><?php esc_html_e('No encontramos agencias', 'travel-agency-platform'); ?></h3>
                        <p><?php esc_html_e('Prueba con otra búsqueda o ubicación.', 'travel-agency-platform'); ?></p>
                        <?php if (!empty($cities)): ?>
                            <div class="tap-agencies-chips tap-agencies-chips--suggest">
                                <?php foreach (array_slice($cities, 0, 6) as $c): ?>
                                    <a class="tap-agency-chip" href="<?php echo esc_url(add_query_arg('agency_city', $c, $base)); ?>"><?php echo esc_html($c); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($active): ?>
                            <a class="tap-btn tap-btn-outline" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Ver todas las agencias', 'travel-agency-platform'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <aside class="tap-agencies-join">
                    <div>
                        <h2><?php esc_html_e('¿Tienes una agencia?', 'travel-agency-platform'); ?></h2>
                        <p><?php esc_html_e('Crea tu perfil, publica tus servicios y recibe reservas de viajeros de todo el mundo.', 'travel-agency-platform'); ?></p>
                    </div>
                    <a class="tap-btn tap-btn-primary" href="<?php echo esc_url(home_url('/register/')); ?>"><?php esc_html_e('Crear mi perfil de agencia', 'travel-agency-platform'); ?></a>
                </aside>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Inline icon for each service family, used on the directory cards.
     */
    /** Inline SVG glyph per service type (map items without a photo). */
    public static function service_type_icon($type) {
        $icons = [
            'tap_accommodation' => '<path d="M3 21v-9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9"/><path d="M6 10V7a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3"/><path d="M3 17h18"/>',
            'tap_tour'          => '<circle cx="12" cy="12" r="9"/><polygon points="15.5 8.5 13.5 13.5 8.5 15.5 10.5 10.5"/>',
            'tap_transport'     => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M3 11h18"/><circle cx="7.5" cy="19" r="1.5"/><circle cx="16.5" cy="19" r="1.5"/>',
            'tap_car_rental'    => '<path d="M5 16l1.6-5A2 2 0 0 1 8.5 9.5h7a2 2 0 0 1 1.9 1.5L19 16"/><rect x="4" y="16" width="16" height="3" rx="1"/><circle cx="7.5" cy="19.5" r="1"/><circle cx="16.5" cy="19.5" r="1"/>',
            'tap_boat'          => '<path d="M3 18h18l-2.5 3H5.5z"/><path d="M12 3v11"/><path d="M12 5l7 8H12"/>',
            'tap_package'       => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        ];
        $svg = $icons[$type] ?? '<circle cx="12" cy="12" r="9"/>';
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
    }

    /**
     * Distinct, non-empty cities of visible agencies for the location filter.
     */
    private static function agency_cities() {
        global $wpdb;
        return (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_tap_agency_city'
                AND pm.meta_value <> ''
                AND p.post_type = 'tap_agency'
                AND p.post_status = 'publish'
                AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} v WHERE v.post_id = p.ID AND v.meta_key = '_tap_agency_verified' AND v.meta_value = '1')
                AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s)
                AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = %s AND a.meta_value = '0')
              ORDER BY pm.meta_value ASC",
            TAP_Approval::STATUS_META,
            TAP_Approval::APPROVED,
            TAP_Approval::ACTIVE_META
        ));
    }

    /**
     * Published service count and per-type breakdown per agency POST id, in
     * one indexed query.
     */
    private static function agency_stats($agency_ids) {
        global $wpdb;
        $agency_ids = array_values(array_filter(array_map('intval', (array) $agency_ids)));
        if (empty($agency_ids)) {
            return [];
        }
        $keys = [];
        foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'] as $type) {
            $key = TAP_Post_Types::agency_meta_key($type);
            if ($key) {
                $keys[$key] = $type;
            }
        }
        if (empty($keys)) {
            return [];
        }
        $in_ids  = implode(',', $agency_ids);
        $in_keys = "'" . implode("','", array_map('esc_sql', array_keys($keys))) . "'";
        $rows    = $wpdb->get_results(
            "SELECT pm.meta_key, pm.meta_value AS agency_id, COUNT(*) AS total
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key IN ({$in_keys})
                AND pm.meta_value IN ({$in_ids})
                AND p.post_status = 'publish'
              GROUP BY pm.meta_key, pm.meta_value"
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $aid  = (int) $row->agency_id;
            $type = $keys[$row->meta_key] ?? '';
            if (!$type) {
                continue;
            }
            $out[$aid]['types'][$type] = (int) $row->total;
            $out[$aid]['total']        = (int) ($out[$aid]['total'] ?? 0) + (int) $row->total;
        }
        return $out;
    }

    /**
     * Approved-review average and count per agency, joined through the
     * listing ownership meta of each review's service type.
     */
    private static function agency_ratings($agency_ids) {
        global $wpdb;
        $agency_ids = array_values(array_filter(array_map('intval', (array) $agency_ids)));
        if (empty($agency_ids)) {
            return [];
        }
        $cases = [];
        foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'] as $type) {
            $key = TAP_Post_Types::agency_meta_key($type);
            if ($key) {
                $cases[] = $wpdb->prepare('WHEN %s THEN %s', $type, $key);
            }
        }
        if (empty($cases)) {
            return [];
        }
        $in_ids = implode(',', $agency_ids);
        $rows   = $wpdb->get_results(
            "SELECT pm.meta_value AS agency_id, AVG(r.rating) AS avg_rating, COUNT(*) AS reviews
               FROM {$wpdb->prefix}tap_reviews r
               JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = r.service_id
                AND pm.meta_key = CASE r.service_type " . implode(' ', $cases) . " ELSE '' END
              WHERE r.is_approved = 1
                AND pm.meta_value IN ({$in_ids})
              GROUP BY pm.meta_value"
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row->agency_id] = [
                'avg'   => (float) $row->avg_rating,
                'count' => (int) $row->reviews,
            ];
        }
        return $out;
    }

    /**
     * Most recent service with a thumbnail per agency, used as the card cover.
     */
    private static function agency_cover_ids($agency_ids) {
        global $wpdb;
        $agency_ids = array_values(array_filter(array_map('intval', (array) $agency_ids)));
        if (empty($agency_ids)) {
            return [];
        }
        $keys = [];
        foreach (['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'] as $type) {
            $key = TAP_Post_Types::agency_meta_key($type);
            if ($key) {
                $keys[] = $key;
            }
        }
        if (empty($keys)) {
            return [];
        }
        $in_ids  = implode(',', $agency_ids);
        $in_keys = "'" . implode("','", array_map('esc_sql', $keys)) . "'";
        $rows    = $wpdb->get_results(
            "SELECT pm.meta_value AS agency_id, p.ID
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key IN ({$in_keys})
                AND pm.meta_value IN ({$in_ids})
                AND p.post_status = 'publish'
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} th
                     WHERE th.post_id = p.ID AND th.meta_key = '_thumbnail_id' AND th.meta_value <> ''
                )
              ORDER BY p.post_date DESC"
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $aid = (int) $row->agency_id;
            if (!isset($out[$aid])) {
                $out[$aid] = (int) $row->ID;
            }
        }
        return $out;
    }

    /**
     * Interactive map of protected destinations and bookable services.
     *
     * Renders a Leaflet map (OpenStreetMap tiles, no API key) with two layers:
     * educational spots (`tap_spot`) and bookable services (accommodations,
     * tours, packages...). A layer switch toggles between "Todo", "Destinos" and
     * "Servicios"; the side panel lists the matching cards and opens a detail
     * panel on click. Every destination is editable from wp-admin through the
     * `tap_spot` CPT; services come from the regular service post types.
     */
    public static function destinations_map($atts) {
        $atts = shortcode_atts([
            'type'  => '',
            'limit' => 0,
            'height' => 560,
        ], $atts);

        $active_type = isset($_GET['spot_type']) ? sanitize_title(wp_unslash($_GET['spot_type'])) : '';
        if ('' === $active_type && '' !== $atts['type']) {
            $active_type = sanitize_title($atts['type']);
        }

        $query_args = [];
        if ('' !== $active_type) {
            $query_args['type'] = $active_type;
        }
        if ((int) $atts['limit'] > 0) {
            $query_args['posts_per_page'] = (int) $atts['limit'];
        }
        $spots = TAP_Destination_Spots::map_data($query_args);
        $types = TAP_Destination_Spots::used_types();

        $services      = TAP_Destination_Spots::services_map_data();
        $service_types = self::service_types_summary($services);

        $center = TAP_Destination_Spots::default_center();
        $height = max(320, (int) $atts['height']);
        $base   = get_permalink() ?: home_url('/destinos/');
        $total  = count($spots);
        $total_services = count($services);

        wp_enqueue_style('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_style('tap-leaflet-cluster', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.css', [], '1.5.3');
        wp_enqueue_style('tap-leaflet-cluster-default', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.default.css', [], '1.5.3');
        wp_enqueue_script('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('tap-leaflet-cluster', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.markercluster.js', ['tap-leaflet'], '1.5.3', true);
        wp_enqueue_script('tap-destinations-map', TAP_PLUGIN_URL . 'assets/js/destinations-map.js', ['jquery', 'tap-leaflet', 'tap-leaflet-cluster'], TAP_VERSION, true);
        wp_localize_script('tap-destinations-map', 'tapDestsMap', [
            'pluginUrl' => TAP_PLUGIN_URL,
            'i18n'      => [
                'all'      => __('Todos', 'travel-agency-platform'),
                'result'   => __('%d lugar', 'travel-agency-platform'),
                'results'  => __('%d lugares', 'travel-agency-platform'),
                'noResults' => __('No hay lugares para este filtro.', 'travel-agency-platform'),
                'schedule' => __('Horario', 'travel-agency-platform'),
                'fee'      => __('Tarifa', 'travel-agency-platform'),
                'area'     => __('Extensión', 'travel-agency-platform'),
                'created'  => __('Año de creación', 'travel-agency-platform'),
                'accessibility' => __('Accesibilidad', 'travel-agency-platform'),
                'interest' => __('Datos de interés', 'travel-agency-platform'),
                'visit'    => __('Ver ficha completa', 'travel-agency-platform'),
                'ecosystem' => __('Ecosistema', 'travel-agency-platform'),
                'bestSeason' => __('Mejor época', 'travel-agency-platform'),
                'biodiversity' => __('Biodiversidad', 'travel-agency-platform'),
                'activities' => __('Actividades recomendadas', 'travel-agency-platform'),
                'tips'     => __('Consejos para el visitante', 'travel-agency-platform'),
                'conservation' => __('Conservación', 'travel-agency-platform'),
                'practical' => __('Ficha práctica', 'travel-agency-platform'),
                'approx'   => __('Ubicación aproximada según su destino.', 'travel-agency-platform'),
                'viewService' => __('Ver servicio', 'travel-agency-platform'),
                'details'  => __('Ver detalles', 'travel-agency-platform'),
                'selectPrompt' => __('Selecciona un punto en el mapa para ver su ficha.', 'travel-agency-platform'),
            ],
        ]);

        ob_start();
        ?>
        <div class="tap-container">
            <section class="tap-destmap" data-center="<?php echo esc_attr($center['lat'] . ',' . $center['lng']); ?>" data-zoom="<?php echo esc_attr($center['zoom']); ?>">
                <header class="tap-destmap-intro">
                    <p class="tap-destmap-eyebrow"><?php esc_html_e('Mapa interactivo', 'travel-agency-platform'); ?></p>
                    <h2><?php esc_html_e('Destinos y servicios en un solo mapa', 'travel-agency-platform'); ?></h2>
                    <p class="tap-destmap-lead"><?php esc_html_e('Explora parques, reservas y sitios protegidos de Costa Rica junto con los alojamientos, tours y paquetes que puedes reservar. Cambia de capa para ver solo destinos, solo servicios o todo.', 'travel-agency-platform'); ?></p>
                </header>

                <div class="tap-destmap-toolbar">
                    <div class="tap-destmap-layers" role="group" aria-label="<?php esc_attr_e('Capas del mapa', 'travel-agency-platform'); ?>">
                        <button type="button" class="tap-destmap-layer is-active" data-layer="all" aria-pressed="true"><?php esc_html_e('Todo', 'travel-agency-platform'); ?></button>
                        <button type="button" class="tap-destmap-layer" data-layer="spots" aria-pressed="false">
                            <?php esc_html_e('Destinos', 'travel-agency-platform'); ?>
                            <span class="tap-destmap-layer-count"><?php echo (int) $total; ?></span>
                        </button>
                        <button type="button" class="tap-destmap-layer" data-layer="services" aria-pressed="false">
                            <?php esc_html_e('Servicios', 'travel-agency-platform'); ?>
                            <span class="tap-destmap-layer-count"><?php echo (int) $total_services; ?></span>
                        </button>
                    </div>

                    <?php if (!empty($types)): ?>
                        <nav class="tap-destmap-filters tap-destmap-filters--spots" aria-label="<?php esc_attr_e('Filtrar por tipo de destino', 'travel-agency-platform'); ?>">
                            <?php foreach ($types as $type): ?>
                                <?php
                                $is_active = ($type['slug'] === $active_type);
                                $url       = $is_active ? $base : add_query_arg('spot_type', $type['slug'], $base);
                                ?>
                                <a class="tap-destmap-chip <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url($url); ?>" <?php echo $is_active ? 'aria-current="true"' : ''; ?>>
                                    <?php echo esc_html($type['name']); ?>
                                    <span class="tap-destmap-chip-count"><?php echo (int) $type['count']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>

                    <?php if (!empty($service_types)): ?>
                        <nav class="tap-destmap-filters tap-destmap-filters--services" hidden aria-label="<?php esc_attr_e('Filtrar por tipo de servicio', 'travel-agency-platform'); ?>">
                            <button type="button" class="tap-destmap-chip tap-destmap-chip--service is-active" data-service-type="" aria-current="true">
                                <?php esc_html_e('Todos', 'travel-agency-platform'); ?>
                                <span class="tap-destmap-chip-count"><?php echo (int) $total_services; ?></span>
                            </button>
                            <?php foreach ($service_types as $stype): ?>
                                <button type="button" class="tap-destmap-chip tap-destmap-chip--service" data-service-type="<?php echo esc_attr($stype['slug']); ?>" aria-current="false">
                                    <?php echo esc_html($stype['name']); ?>
                                    <span class="tap-destmap-chip-count"><?php echo (int) $stype['count']; ?></span>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                </div>

                <div class="tap-destmap-layout">
                    <div class="tap-destmap-canvas">
                        <div id="tap-destmap" class="tap-destmap-map" style="height:<?php echo esc_attr($height); ?>px" role="application" aria-label="<?php esc_attr_e('Mapa de destinos y servicios', 'travel-agency-platform'); ?>"></div>
                        <input type="hidden" id="tap-destmap-data" value="<?php echo esc_attr(wp_json_encode($spots)); ?>">
                        <input type="hidden" id="tap-destmap-services" value="<?php echo esc_attr(wp_json_encode($services)); ?>">
                        <div class="tap-destmap-legend">
                            <span class="tap-destmap-legend-item"><span class="tap-destmap-pin tap-destmap-pin--spot" aria-hidden="true"></span><?php esc_html_e('Destino protegido', 'travel-agency-platform'); ?></span>
                            <span class="tap-destmap-legend-item"><span class="tap-destmap-pin tap-destmap-pin--service" aria-hidden="true"></span><?php esc_html_e('Servicio reservable', 'travel-agency-platform'); ?></span>
                        </div>
                    </div>

                    <aside class="tap-destmap-panel" aria-live="polite">
                        <div class="tap-destmap-panel-head">
                            <div class="tap-destmap-tabs" role="tablist">
                                <button type="button" class="tap-destmap-tab is-active" role="tab" data-tab="spots" aria-selected="true">
                                    <?php esc_html_e('Destinos', 'travel-agency-platform'); ?>
                                    <span class="tap-destmap-tab-count"><?php echo (int) $total; ?></span>
                                </button>
                                <button type="button" class="tap-destmap-tab" role="tab" data-tab="services" aria-selected="false">
                                    <?php esc_html_e('Servicios', 'travel-agency-platform'); ?>
                                    <span class="tap-destmap-tab-count"><?php echo (int) $total_services; ?></span>
                                </button>
                            </div>
                            <span class="tap-destmap-count" id="tap-destmap-count"><?php
                                printf(
                                    /* translators: %d: number of places */
                                    esc_html(_n('%d lugar', '%d lugares', $total + $total_services, 'travel-agency-platform')),
                                    (int) ($total + $total_services)
                                );
                            ?></span>
                        </div>

                        <ul class="tap-destmap-list" id="tap-destmap-list">
                            <?php foreach ($spots as $spot): ?>
                                <li class="tap-destmap-item" data-spot-id="<?php echo (int) $spot['id']; ?>" data-item-id="<?php echo (int) $spot['id']; ?>" data-item-kind="spot">
                                    <button type="button" class="tap-destmap-item-btn" aria-expanded="false" aria-controls="tap-destmap-detail">
                                        <span class="tap-destmap-item-thumb">
                                            <?php if ($spot['img']): ?>
                                                <img src="<?php echo esc_url($spot['img']); ?>" alt="" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <span class="tap-destmap-item-thumb-icon" aria-hidden="true"><?php echo self::spot_type_icon($spot['type']); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed inline SVG. ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tap-destmap-item-text">
                                            <span class="tap-destmap-item-title"><?php echo esc_html($spot['title']); ?></span>
                                            <span class="tap-destmap-item-meta">
                                                <?php if ($spot['typeName']): ?><span class="tap-destmap-item-type"><?php echo esc_html($spot['typeName']); ?></span><?php endif; ?>
                                                <?php if ($spot['province']): ?><span class="tap-destmap-item-province"><?php echo esc_html($spot['province']); ?></span><?php endif; ?>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <ul class="tap-destmap-list tap-destmap-list--services" id="tap-destmap-service-list" hidden>
                            <?php foreach ($services as $service): ?>
                                <li class="tap-destmap-item tap-destmap-item--service" data-item-id="<?php echo (int) $service['id']; ?>" data-item-kind="service" data-service-type="<?php echo esc_attr($service['type']); ?>">
                                    <button type="button" class="tap-destmap-item-btn" aria-expanded="false" aria-controls="tap-destmap-detail">
                                        <span class="tap-destmap-item-thumb">
                                            <?php if ($service['img']): ?>
                                                <img src="<?php echo esc_url($service['img']); ?>" alt="" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <span class="tap-destmap-item-thumb-icon tap-destmap-item-thumb-icon--service" aria-hidden="true"><?php echo self::service_type_icon($service['type']); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed inline SVG. ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tap-destmap-item-text">
                                            <span class="tap-destmap-item-title"><?php echo esc_html($service['title']); ?></span>
                                            <span class="tap-destmap-item-meta">
                                                <span class="tap-destmap-item-type"><?php echo esc_html($service['typeName']); ?></span>
                                                <?php if ($service['city']): ?><span class="tap-destmap-item-province"><?php echo esc_html($service['city']); ?></span><?php endif; ?>
                                                <?php if ($service['price']): ?><span class="tap-destmap-item-price"><?php echo esc_html($service['price']); ?></span><?php endif; ?>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="tap-destmap-detail" id="tap-destmap-detail" hidden></div>

                        <?php if (!$spots && !$services): ?>
                            <p class="tap-destmap-empty"><?php esc_html_e('Todavía no hay destinos ni servicios publicados. Agrégalos desde el panel de administración.', 'travel-agency-platform'); ?></p>
                        <?php endif; ?>
                    </aside>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Group mapped services by type with a live count, for the filter chips.
     *
     * @param array $services Services from TAP_Destination_Spots::services_map_data().
     * @return array<int,array{slug:string,name:string,count:int}>
     */
    private static function service_types_summary($services) {
        $counts = [];
        foreach ((array) $services as $service) {
            $slug = (string) ($service['type'] ?? '');
            if ('' === $slug) {
                continue;
            }
            if (!isset($counts[$slug])) {
                $counts[$slug] = [
                    'slug' => $slug,
                    'name' => (string) ($service['typeName'] ?? $slug),
                    'count' => 0,
                ];
            }
            $counts[$slug]['count']++;
        }
        ksort($counts);
        return array_values($counts);
    }
    /** Inline SVG glyph per destination type (map items without a photo). */
    public static function spot_type_icon($type) {
        $icons = [
            'parque-nacional'        => '<path d="M12 2 4 14h5l-3 8 6-7v5h4v-5l4 5-3-8h5z"/>',
            'reserva-biologica'      => '<path d="M12 22V8"/><path d="M12 8c0-4 3-6 8-6 0 4-3 6-8 6z"/><path d="M12 12c0-4-3-6-8-6 0 4 3 6 8 6z"/>',
            'refugio-vida-silvestre' => '<path d="M4 20c2-6 6-9 12-9"/><circle cx="18" cy="8" r="3"/><path d="M9 20h11"/>',
            'volcan'                 => '<path d="M3 20h18L15 8l-2 4-2-6-8 14z"/><path d="M10 6l1 2"/>',
            'playa-protegida'        => '<path d="M2 18c3 0 4-2 7-2s4 2 7 2 4-2 6-2"/><circle cx="8" cy="8" r="3"/><path d="M2 14c3 0 4-2 7-2"/>',
            'sitio-arqueologico'     => '<path d="M4 21h16"/><path d="M6 21V10l6-4 6 4v11"/><path d="M10 21v-6h4v6"/>',
            'zona-protegida'         => '<path d="M12 21s-7-6.5-7-11a7 7 0 0 1 14 0c0 4.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'monumento-natural'      => '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M12 22V12"/><path d="M3 7l9 5 9-5"/>',
            'termas'                 => '<path d="M4 20c3-3 4-6 8-9s5-6 8-9"/><path d="M3 20h18"/><path d="M8 16c2-2 3-4 6-6"/>',
            'pueblo'                 => '<path d="M3 21h18"/><path d="M5 21v-8h5v8"/><path d="M13 21v-11h6v11"/><path d="M7 16h1M16 14h1"/>',
            'mirador'                => '<path d="M2 20h20"/><path d="M12 20V9"/><path d="M6 20l6-11 6 11"/><path d="M9.5 15.5l2.5-2.5 2.5 2.5"/>',
        ];
        $svg = $icons[$type] ?? '<path d="M12 21s-7-6.5-7-11a7 7 0 0 1 14 0c0 4.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
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
            <?php
            foreach ($all_posts as $post): setup_postdata($post);
				$GLOBALS['post'] = $post;
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
				<?php
            endforeach;
			wp_reset_postdata();
			$GLOBALS['post'] = $tap_prev_post;
			?>
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
