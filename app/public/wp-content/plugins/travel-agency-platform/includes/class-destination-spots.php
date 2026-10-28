<?php
defined('ABSPATH') || exit;

/**
 * Educational destination spots — the data behind the interactive Destinations map.
 *
 * A `tap_spot` is a place worth learning about (parque nacional, reserva
 * biológica, volcán, refugio de vida silvestre, playa protegida, sitio
 * arqueológico, …). Each spot is fully editable from wp-admin: title, rich
 * description, featured image, category (`tap_spot_type`), coordinates and a
 * handful of educational meta fields (datos de interés, horario, tarifa,
 * accesibilidad, año de creación, extensión, provincia).
 *
 * The map shortcode `[tap_destinations_map]` (class-shortcode-search.php) reads
 * these spots; the single template renders the educational page for each one.
 */
class TAP_Destination_Spots {

    const CPT = 'tap_spot';
    const TAX = 'tap_spot_type';
    const SEED_OPTION = 'tap_spot_seed';
    const ENRICH_OPTION = 'tap_spot_enriched';
    const ENRICH_VERSION = 2;
    const PAGE_OPTION = 'tap_spot_page_id';
    const TYPES_SIGNATURE_OPTION = 'tap_spot_types_signature';
    const SPOTS_SIGNATURE_OPTION = 'tap_spot_sync_signature';

    /** Default categories (slug => label). Labels are translated at register time. */
    public static function default_types() {
        return [
            'parque-nacional'        => __('Parque Nacional', 'travel-agency-platform'),
            'reserva-biologica'      => __('Reserva Biológica', 'travel-agency-platform'),
            'refugio-vida-silvestre' => __('Refugio de Vida Silvestre', 'travel-agency-platform'),
            'volcan'                 => __('Volcán', 'travel-agency-platform'),
            'playa-protegida'        => __('Playa Protegida', 'travel-agency-platform'),
            'sitio-arqueologico'     => __('Sitio Arqueológico', 'travel-agency-platform'),
            'zona-protegida'         => __('Zona Protectora', 'travel-agency-platform'),
            'monumento-natural'      => __('Monumento Natural', 'travel-agency-platform'),
            'termas'                 => __('Termas', 'travel-agency-platform'),
            'pueblo'                 => __('Pueblo Turístico', 'travel-agency-platform'),
            'mirador'                => __('Mirador', 'travel-agency-platform'),
        ];
    }

    /** Default map coordinates: roughly the geographic centre of Costa Rica. */
    public static function default_center() {
        return ['lat' => 9.7489, 'lng' => -83.7534, 'zoom' => 8];
    }

    /**
     * Educational meta fields. Kept in one place so the metabox, the save
     * handler, the REST payload and the tests all agree on names/order.
     *
     * @return array<string,array{label:string,type:string,desc?:string}>
     */
    public static function fields() {
        return [
            '_tap_spot_lat'        => ['label' => __('Latitud', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('Decimal, p. ej. 10.4631', 'travel-agency-platform')],
            '_tap_spot_lng'        => ['label' => __('Longitud', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('Decimal, p. ej. -84.7033', 'travel-agency-platform')],
            '_tap_spot_province'   => ['label' => __('Provincia', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_region'     => ['label' => __('Región', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('p. ej. Guanacaste, Caribe Sur', 'travel-agency-platform')],
            '_tap_spot_ecosystem'  => ['label' => __('Ecosistema', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('p. ej. Bosque nuboso, Humedal', 'travel-agency-platform')],
            '_tap_spot_altitude'   => ['label' => __('Altitud', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('p. ej. 1 670 msnm', 'travel-agency-platform')],
            '_tap_spot_interest'   => ['label' => __('Datos de interés', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Uno por línea. Se muestran como viñetas.', 'travel-agency-platform')],
            '_tap_spot_biodiversity' => ['label' => __('Biodiversidad', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Una especie o grupo por línea.', 'travel-agency-platform')],
            '_tap_spot_activities' => ['label' => __('Actividades recomendadas', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Una por línea.', 'travel-agency-platform')],
            '_tap_spot_tips'       => ['label' => __('Consejos para el visitante', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Uno por línea.', 'travel-agency-platform')],
            '_tap_spot_conservation' => ['label' => __('Conservación', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Estado, amenazas y esfuerzos de conservación.', 'travel-agency-platform')],
            '_tap_spot_best_season' => ['label' => __('Mejor época', 'travel-agency-platform'), 'type' => 'text', 'desc' => __('p. ej. Diciembre a abril', 'travel-agency-platform')],
            '_tap_spot_schedule'   => ['label' => __('Horario', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_fee'        => ['label' => __('Tarifa', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_area'       => ['label' => __('Extensión', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_created'    => ['label' => __('Año de creación', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_accessibility' => ['label' => __('Accesibilidad', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_website'    => ['label' => __('Sitio oficial', 'travel-agency-platform'), 'type' => 'url'],
        ];
    }

    /** Fields whose value is a one-per-line list (rendered as bullets). */
    public static function list_fields() {
        return ['_tap_spot_interest', '_tap_spot_biodiversity', '_tap_spot_activities', '_tap_spot_tips'];
    }

    /** Numeric/coordinate fields that must be sanitized differently. */
    public static function coord_fields() {
        return ['_tap_spot_lat', '_tap_spot_lng'];
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    public static function register() {
        self::register_cpt();
        self::register_taxonomy();

        add_filter('manage_tap_spot_posts_columns', [__CLASS__, 'admin_columns']);
        add_action('manage_tap_spot_posts_custom_column', [__CLASS__, 'admin_column'], 10, 2);
    }

    /**
     * "Ubicación" column in the destinations list.
     *
     * Makes the unification visible at a glance: a ficha without a place will
     * not list any services, so the empty cells are the ones to fix.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public static function admin_columns($columns) {
        $columns['tap_spot_location'] = __('Ubicación', 'travel-agency-platform');
        return $columns;
    }

    public static function admin_column($column, $post_id) {
        if ('tap_spot_location' !== $column) {
            return;
        }
        $term = self::location_term($post_id);
        if (! $term) {
            echo '<span style="color:#b45309">' . esc_html__('Sin ubicación', 'travel-agency-platform') . '</span>';
            return;
        }
        $url = get_edit_term_link($term->term_id, 'tap_location');
        if ($url) {
            echo '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
            return;
        }
        echo esc_html($term->name);
    }

    private static function register_cpt() {
        $labels = [
            'name'               => __('Destinos educativos', 'travel-agency-platform'),
            'singular_name'      => __('Destino educativo', 'travel-agency-platform'),
            'add_new'            => __('Añadir destino', 'travel-agency-platform'),
            'add_new_item'       => __('Añadir nuevo destino', 'travel-agency-platform'),
            'edit_item'          => __('Editar destino', 'travel-agency-platform'),
            'view_item'          => __('Ver destino', 'travel-agency-platform'),
            'search_items'       => __('Buscar destinos', 'travel-agency-platform'),
            'not_found'          => __('No se encontraron destinos', 'travel-agency-platform'),
            'all_items'          => __('Todos los destinos', 'travel-agency-platform'),
            'menu_name'          => __('Destinos', 'travel-agency-platform'),
        ];

        register_post_type(self::CPT, [
            'labels'        => $labels,
            'public'        => true,
            'has_archive'   => false,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-location-alt',
            'menu_position' => 21,
            'supports'      => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'rewrite'       => ['slug' => 'destino'],
            'show_in_rest'  => true,
        ]);
    }

    private static function register_taxonomy() {
        $labels = [
            'name'          => __('Tipos de destino', 'travel-agency-platform'),
            'singular_name' => __('Tipo de destino', 'travel-agency-platform'),
            'search_items'  => __('Buscar tipos', 'travel-agency-platform'),
            'all_items'     => __('Todos los tipos', 'travel-agency-platform'),
            'edit_item'     => __('Editar tipo', 'travel-agency-platform'),
            'update_item'   => __('Actualizar tipo', 'travel-agency-platform'),
            'add_new_item'  => __('Añadir tipo', 'travel-agency-platform'),
            'new_item_name' => __('Nombre del tipo', 'travel-agency-platform'),
            'menu_name'     => __('Tipos de destino', 'travel-agency-platform'),
        ];

        register_taxonomy(self::TAX, [self::CPT], [
            'labels'            => $labels,
            'public'            => true,
            'hierarchical'      => true,
            'rewrite'           => ['slug' => 'tipo-destino'],
            'show_in_rest'      => true,
            'show_admin_column' => true,
        ]);
    }

    /** Create the default type terms if they do not exist yet (idempotent). */
    /**
     * Seed the default spot categories.
     *
     * Runs on `init` for every request, so it must not query the database when
     * nothing changed. The signature of the default set is stored in an
     * autoloaded option: once the terms exist, the check costs zero queries.
     * Bumping the list (or a first install) re-runs the seed.
     */
    public static function seed_types() {
        if (!taxonomy_exists(self::TAX)) {
            return;
        }

        $types = self::default_types();
        $signature = md5(implode('|', array_keys($types)) . '|' . self::ENRICH_VERSION);
        if (get_option(self::TYPES_SIGNATURE_OPTION) === $signature) {
            return;
        }

        foreach ($types as $slug => $name) {
            if (!term_exists($slug, self::TAX)) {
                wp_insert_term($name, self::TAX, ['slug' => $slug]);
            }
        }

        update_option(self::TYPES_SIGNATURE_OPTION, $signature);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Spots with valid coordinates for the map, newest first.
     *
     * @param array $args Optional overrides (type slug, posts_per_page).
     * @return array<int,array<string,mixed>>
     */
    public static function map_data(array $args = []) {
        $args = array_merge([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ], $args);

        if (!empty($args['type'])) {
            $args['tax_query'] = [[
                'taxonomy' => self::TAX,
                'field'    => 'slug',
                'terms'    => $args['type'],
            ],];
            unset($args['type']);
        }

        $data = [];
        foreach (get_posts($args) as $post) {
            $lat = self::coord($post->ID, '_tap_spot_lat');
            $lng = self::coord($post->ID, '_tap_spot_lng');
            if ($lat === null || $lng === null) {
                continue; // no map point without coordinates
            }

            $types     = self::types_of($post->ID);
            $type_slug = $types ? $types[0]->slug : '';
            $type_name = $types ? $types[0]->name : '';

            $data[] = [
                'id'       => (int) $post->ID,
                'title'    => get_the_title($post),
                'url'      => get_permalink($post),
                'lat'      => $lat,
                'lng'      => $lng,
                'type'     => $type_slug,
                'typeName' => $type_name,
                'img'      => (string) get_the_post_thumbnail_url($post, 'medium_large'),
                'excerpt'  => wp_strip_all_tags(get_the_excerpt($post)),
                'province' => (string) get_post_meta($post->ID, '_tap_spot_province', true),
                'region'   => (string) get_post_meta($post->ID, '_tap_spot_region', true),
                'ecosystem' => (string) get_post_meta($post->ID, '_tap_spot_ecosystem', true),
                'altitude' => (string) get_post_meta($post->ID, '_tap_spot_altitude', true),
                'bestSeason' => (string) get_post_meta($post->ID, '_tap_spot_best_season', true),
                'interest' => self::list_value($post->ID, '_tap_spot_interest'),
                'biodiversity' => self::list_value($post->ID, '_tap_spot_biodiversity'),
                'activities' => self::list_value($post->ID, '_tap_spot_activities'),
                'tips'     => self::list_value($post->ID, '_tap_spot_tips'),
                'conservation' => (string) get_post_meta($post->ID, '_tap_spot_conservation', true),
                'schedule' => (string) get_post_meta($post->ID, '_tap_spot_schedule', true),
                'fee'      => (string) get_post_meta($post->ID, '_tap_spot_fee', true),
                'area'     => (string) get_post_meta($post->ID, '_tap_spot_area', true),
                'created'  => (string) get_post_meta($post->ID, '_tap_spot_created', true),
                'accessibility' => (string) get_post_meta($post->ID, '_tap_spot_accessibility', true),
                'website'  => (string) get_post_meta($post->ID, '_tap_spot_website', true),
            ];
        }
        return $data;
    }

    /**
     * Bounding box used to tell a real Costa Rican position from a corrupt or
     * placeholder coordinate (test fixtures often carry foreign values).
     *
     * @return array{lat:array{0:float,1:float},lng:array{0:float,1:float}}
     */
    public static function cr_bounds() {
        return [
            'lat' => [7.0, 12.0],
            'lng' => [-86.0, -82.0],
        ];
    }

    /**
     * Whether a lat/lng pair is a plausible position inside Costa Rica.
     *
     * @param float|null $lat Latitude.
     * @param float|null $lng Longitude.
     * @return bool
     */
    public static function valid_coord($lat, $lng) {
        if (null === $lat || null === $lng) {
            return false;
        }
        $b = self::cr_bounds();
        return $lat >= $b['lat'][0] && $lat <= $b['lat'][1]
            && $lng >= $b['lng'][0] && $lng <= $b['lng'][1];
    }

    /**
     * Published services (accommodations, tours, packages…) placed on the map.
     *
     * A service keeps its own position when that position is plausible for
     * Costa Rica. Otherwise it is placed on the centroid of its destination
     * term (computed from the services that do carry coordinates, or taken
     * from the curated map) and flagged `approx`, so it never silently
     * disappears from the map.
     *
     * @param array $args Optional ['types' => string[], 'limit' => int].
     * @return array<int,array<string,mixed>>
     */
    public static function services_map_data(array $args = []) {
        $types = !empty($args['types']) ? (array) $args['types'] : ['tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $types = array_values(array_filter($types, 'post_type_exists'));
        if (!$types) {
            return [];
        }

        $excluded = class_exists('TAP_Approval') ? TAP_Approval::excluded_listing_ids() : [];
        $query = [
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => isset($args['limit']) ? (int) $args['limit'] : 300,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];
        if ($excluded) {
            $query['post__not_in'] = array_map('intval', $excluded);
        }

        $labels = TAP_Post_Types::get_service_types();

        // First pass: collect the services and their own positions so the
        // destination centroids can be derived from real data.
        $candidates = [];
        foreach (get_posts($query) as $post) {
            $type   = $post->post_type;
            $prefix = TAP_Post_Types::meta_prefix($type);
            if (!$prefix) {
                continue;
            }
            $lat = self::coord($post->ID, '_tap_' . $prefix . '_lat');
            $lng = self::coord($post->ID, '_tap_' . $prefix . '_lng');
            $own  = self::valid_coord($lat, $lng);

            $candidates[] = [
                'post'    => $post,
                'type'    => $type,
                'lat'     => $own ? (float) $lat : null,
                'lng'     => $own ? (float) $lng : null,
                'terms'   => self::location_terms($post->ID),
                'centroid' => null,
            ];
        }

        $centroids = self::computed_centroids($candidates);

        // Second pass: resolve the position of every service.
        $data = [];
        foreach ($candidates as $candidate) {
            $post    = $candidate['post'];
            $type    = $candidate['type'];
            $lat     = $candidate['lat'];
            $lng     = $candidate['lng'];
            $approx  = false;

            if (null === $lat || null === $lng) {
                $resolved = self::resolve_position($candidate['terms'], $centroids);
                if (null === $resolved) {
                    continue; // no position at all: nothing to show on the map
                }
                [$lat, $lng] = $resolved;
                $approx = true;
            }

            $price_key = class_exists('TAP_API') ? TAP_API::get_price_key($type) : null;
            $price     = $price_key ? (float) get_post_meta($post->ID, $price_key, true) : 0.0;
            $rating    = class_exists('TAP_API') ? TAP_API::get_rating_stats($type, $post->ID) : ['avg' => 0, 'count' => 0];

            $data[] = [
                'id'        => (int) $post->ID,
                'title'     => get_the_title($post),
                'url'       => get_permalink($post),
                'lat'       => (float) $lat,
                'lng'       => (float) $lng,
                'approx'    => $approx,
                'type'      => $type,
                'typeName'  => $labels[$type] ?? $type,
                'city'      => self::service_city($post->ID, $type),
                'img'       => (string) get_the_post_thumbnail_url($post, 'medium'),
                'price'     => $price > 0 ? self::format_price($price) : '',
                'ratingAvg' => round((float) $rating['avg'], 1),
                'ratingCount' => (int) $rating['count'],
            ];
        }
        return $data;
    }

    /** A price formatted for a JSON payload (plain symbol, never an entity). */
    private static function format_price($amount) {
        if (!class_exists('TAP_Currency')) {
            return number_format((float) $amount, 2);
        }
        $symbol = TAP_Currency::plain_symbol();
        return trim($symbol . ' ' . number_format((float) $amount, TAP_Currency::decimals()));
    }

    /**
     * The location terms of a service, most specific first.
     *
     * @param int $post_id Service post id.
     * @return array<int,WP_Term>
     */
    private static function location_terms($post_id) {
        $terms = get_the_terms($post_id, 'tap_location');
        if (!is_array($terms) || !$terms) {
            return [];
        }
        usort($terms, function ($a, $b) {
            return $b->parent <=> $a->parent;
        });
        return $terms;
    }

    /**
     * Centroid per destination term, computed from the services that carry
     * their own valid coordinates.
     *
     * @param array $candidates Candidate services.
     * @return array<string,array{0:float,1:float}>
     */
    private static function computed_centroids(array $candidates) {
        $sums = [];
        foreach ($candidates as $candidate) {
            if (null === $candidate['lat'] || null === $candidate['lng']) {
                continue;
            }
            foreach ($candidate['terms'] as $term) {
                if (!isset($sums[$term->slug])) {
                    $sums[$term->slug] = ['lat' => 0.0, 'lng' => 0.0, 'n' => 0];
                }
                $sums[$term->slug]['lat'] += (float) $candidate['lat'];
                $sums[$term->slug]['lng'] += (float) $candidate['lng'];
                ++$sums[$term->slug]['n'];
            }
        }

        $centroids = [];
        foreach ($sums as $slug => $sum) {
            if ($sum['n'] < 1) {
                continue;
            }
            $centroids[$slug] = [$sum['lat'] / $sum['n'], $sum['lng'] / $sum['n']];
        }
        return $centroids;
    }

    /**
     * Resolve a position from a service's location terms.
     *
     * Tries the term itself, then its ancestors, using first the centroids
     * computed from real data and finally the curated map.
     *
     * @param array $terms      Location terms, most specific first.
     * @param array $centroids  Computed centroids keyed by term slug.
     * @return array{0:float,1:float}|null
     */
    private static function resolve_position(array $terms, array $centroids) {
        foreach ($terms as $term) {
            $slug     = $term->slug;
            $parent   = $term->parent ? get_term($term->parent, 'tap_location') : null;
            $fallback = $parent instanceof WP_Term ? $parent->slug : '';

            if (isset($centroids[$slug])) {
                return $centroids[$slug];
            }
            $curated = self::term_points();
            if (isset($curated[$slug])) {
                return $curated[$slug];
            }
            if ($fallback !== '' && isset($centroids[$fallback])) {
                return $centroids[$fallback];
            }
            if ($fallback !== '' && isset($curated[$fallback])) {
                return $curated[$fallback];
            }
        }
        return null;
    }

    /** A human location label for a service (destination term, then city meta). */
    private static function service_city($post_id, $type) {
        $terms = get_the_terms($post_id, 'tap_location');
        if (is_array($terms) && $terms) {
            usort($terms, function ($a, $b) {
                return $b->parent <=> $a->parent;
            });
            return $terms[0]->name;
        }
        $prefix = TAP_Post_Types::meta_prefix($type);
        foreach (['_tap_' . $prefix . '_city', '_tap_' . $prefix . '_location'] as $key) {
            $val = (string) get_post_meta($post_id, $key, true);
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    /**
     * Curated centroids for the location taxonomy, keyed by term slug.
     *
     * Used as the last resort when no service of a destination carries its
     * own coordinates, so every listing with a location still shows up.
     *
     * @return array<string,array{0:float,1:float}>
     */
    public static function term_points() {
        return [
            'costa-rica' => [9.7489, -83.7534],
            'guanacaste' => [10.5000, -85.3000],
            'alajuela' => [10.0163, -84.2116],
            'san-jose' => [9.9281, -84.0907],
            'cartago' => [9.8644, -83.9194],
            'heredia' => [10.0024, -84.1165],
            'limon' => [9.9910, -83.0359],
            'puntarenas' => [9.9763, -84.8387],
            'liberia' => [10.6346, -85.4407],
            'sarapiqui' => [10.4574, -84.0279],
            'turrialba' => [9.9047, -83.6813],
            'tilaran' => [10.4569, -85.4597],
            'guatuso' => [10.6667, -84.7833],
            'san-carlos' => [10.4617, -84.8433],
            'la-fortuna' => [10.4678, -84.6428],
            'nuevo-arenal' => [10.2622, -84.5833],
            'lago-arenal' => [10.5833, -84.8333],
            'rio-celeste' => [10.5708, -84.9547],
            'monteverde' => [10.3009, -84.8245],
            'monteverde-cloud-forest' => [10.3009, -84.8245],
            'la-cruz' => [10.0672, -84.6333],
            'tortuguero' => [10.5406, -83.5026],
            'samara' => [9.8819, -85.5286],
            'nosara' => [9.9763, -85.6530],
            'playa-del-coco' => [10.3347, -85.4466],
            'playa-hermosa' => [10.6081, -85.6558],
            'herradura' => [9.6425, -84.1683],
            'tamarindo' => [10.2993, -85.8371],
            'cabo-papagayo' => [10.5372, -85.7536],
            'nicoya' => [9.9763, -85.6530],
            'santa-cruz' => [9.6333, -85.1167],
            'cambalache' => [10.0833, -85.0833],
            'jaco' => [9.6141, -84.6306],
            'manuel-antonio' => [9.3917, -84.1375],
            'quepos' => [9.4321, -84.1619],
            'parrita' => [9.6333, -84.0833],
            'nacipes' => [9.3833, -84.1667],
            'dominical' => [9.3389, -83.9250],
            'uvita' => [9.1614, -83.7352],
            'golfito' => [8.6383, -83.1167],
            'osa' => [8.5390, -83.3070],
            'puerto-jimenez' => [8.5390, -83.3070],
            'drake-bay' => [8.6915, -83.6700],
            'boruca' => [8.8833, -83.1167],
            'puerto-viejo' => [9.6594, -82.7536],
            'cahuita' => [9.7382, -82.8343],
            'bribri' => [9.9667, -83.0333],
            'puerto-yaquin' => [9.6000, -82.9000],
            'talamanca' => [9.3167, -83.2833],
            'montezuma' => [9.6500, -85.0667],
            'santa-ana' => [9.9833, -85.7000],
            'cabo-blanco' => [9.5333, -85.1167],
            'paquera' => [9.5167, -84.1167],
            'isla-del-coco' => [5.5271, -87.0580],
            'turrialba-turrialba' => [9.9047, -83.6813],
            'pococi' => [10.1500, -83.7500],
            'guacimo' => [10.2167, -83.5833],
            'siquirres' => [10.0833, -83.5167],
            'matina' => [10.0833, -83.3000],
            'tibas' => [10.2500, -83.4000],
            'carrillo' => [10.0667, -84.8333],
            'sarchi' => [10.1500, -84.8500],
            'san-ramon' => [10.0333, -84.6333],
            'greecia' => [10.0167, -84.3167],
            'atenas' => [10.0167, -84.0833],
            'puriscal' => [9.7667, -84.0167],
            'san-isidro' => [9.3667, -83.7000],
            'tarrazu' => [9.6667, -83.9000],
            'dota' => [9.3333, -84.1167],
            'san-mateo' => [9.5167, -84.0167],
            'goicoechea' => [9.9833, -84.0833],
            'vazquez-de-coronado' => [9.8333, -84.1167],
            'escazu' => [9.9333, -84.1500],
            'santa-ana-escazu' => [9.9833, -84.1833],
            'desamparados' => [9.9167, -84.0167],
            'curridabat' => [9.9333, -84.0333],
            'moravia' => [9.9667, -84.0500],
            'santa-cruz-goicoechea' => [9.9667, -84.0833],
            'san-rafael' => [9.9000, -84.0667],
            'la-union' => [9.9667, -84.0000],
            'mora' => [9.8833, -83.9333],
            'goicoechea-alajuelita' => [9.9500, -84.0667],
            'alajuelita' => [9.9500, -84.0500],
            'belen' => [9.9500, -84.1000],
            'flores' => [9.9667, -84.1167],
            'barva' => [10.0167, -84.1000],
            'heredia-barva' => [10.0167, -84.1000],
            'santo-domingo' => [9.9000, -83.8500],
            'san-vicente' => [9.9667, -83.7500],
            'tibas-tibas' => [10.2500, -83.4000],
            'puntarenas-puntarenas' => [9.9763, -84.8387],
            'esparza' => [9.9833, -84.6500],
            'montezuma-montezuma' => [9.6500, -85.0667],
            'santa-rosa' => [10.8333, -85.7500],
            'lagunas' => [10.6667, -85.6667],
            'nicoya-santa-cruz' => [9.6333, -85.1167],
            'carrillo-buenas-aires' => [10.0000, -84.8000],
            'upala' => [10.9500, -85.3500],
            'los-chiles' => [11.0333, -84.7167],
            'grecia-sarapiqui' => [10.4333, -84.0000],
            'la-union-la-union' => [10.6333, -84.9667],
            'guayabo' => [9.9717, -83.6897],
            'aserrí' => [9.4667, -83.6833],
            'monotes-de-oca' => [9.7833, -83.9333],
            'tarrazu-tarrazu' => [9.6667, -83.9000],
            'dota-dota' => [9.3333, -84.1167],
            'parrita-parrita' => [9.6333, -84.0833],
            'poas-poas' => [10.1977, -84.2312],
            'rincon-de-la-vieja' => [10.8278, -85.3232],
            'pluma-ponderosa' => [10.4333, -84.9833],
        ];
    }
    /** All assigned spot types (term objects) — the first one is the primary. */
    public static function types_of($post_id) {
        $terms = get_the_terms($post_id, self::TAX);
        return (is_array($terms) && $terms) ? $terms : [];
    }

    /**
     * Types present among published spots, each with a live count, ordered for
     * the filter bar. Empty types are kept out so the filter never yields zero.
     *
     * @return array<int,array{slug:string,name:string,count:int}>
     */
    public static function used_types() {
        $terms = get_terms([
            'taxonomy'   => self::TAX,
            'hide_empty' => true,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            $out[] = [
                'slug'  => $term->slug,
                'name'  => $term->name,
                'count' => (int) $term->count,
            ];
        }
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /** Coordinate value as float, or null when absent/invalid. */
    public static function coord($post_id, $key) {
        $raw = get_post_meta($post_id, $key, true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $val = (float) $raw;
        if ($val === 0.0 && (string) $raw !== '0' && (string) $raw !== '0.0') {
            return null;
        }
        return $val;
    }

    /** A one-per-line meta value split into trimmed, non-empty entries. */
    public static function list_value($post_id, $key) {
        $raw   = (string) get_post_meta($post_id, $key, true);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        return array_values(array_filter(array_map('trim', $lines), function ($l) {
            return $l !== '';
        }));
    }

    /** "Datos de interés" textarea split into trimmed, non-empty lines. */
    public static function interest_list($post_id) {
        return self::list_value($post_id, '_tap_spot_interest');
    }

    // ---------------------------------------------------------------------
    // Unified destination: ficha ↔ location term
    // ---------------------------------------------------------------------
    //
    // A destination is ONE concept shown on ONE canonical page: the ficha
    // (tap_spot). Listings keep tagging their location with the `tap_location`
    // taxonomy so every existing search/archive keeps working; the ficha is
    // linked 1:1 to the term that represents its place and uses it to pull the
    // services published there.
    //
    // The link is stored twice (post meta + term meta) so a lookup is cheap in
    // both directions: the ficha needs its term, and a term page needs to know
    // it must hand over to the ficha.

    const LOCATION_META      = '_tap_spot_location';
    const LOCATION_TERM_META = 'tap_spot_id';
    const LINK_OPTION        = 'tap_spot_link_version';
    const LINK_VERSION       = 1;

    /** Linked `tap_location` term id, or 0. Validated on read. */
    public static function location_term_id($spot_id) {
        $term_id = (int) get_post_meta((int) $spot_id, self::LOCATION_META, true);
        if ($term_id <= 0) {
            return 0;
        }
        if (! term_exists($term_id, 'tap_location')) {
            return 0;
        }
        return $term_id;
    }

    /** Linked `tap_location` term, or null. */
    public static function location_term($spot_id) {
        $term_id = self::location_term_id($spot_id);
        if (! $term_id) {
            return null;
        }
        $term = get_term($term_id, 'tap_location');
        return ($term instanceof WP_Term) ? $term : null;
    }

    /**
     * The ficha linked to a location term (one ficha per place), or 0.
     *
     * Memoized per request: a destination archive can ask for the same term
     * several times while rendering breadcrumbs and cards.
     */
    public static function spot_for_term($term_id) {
        static $cache = [];
        $term_id = (int) $term_id;
        if (isset($cache[$term_id])) {
            return $cache[$term_id];
        }
        if (! $term_id) {
            $cache[$term_id] = 0;
            return 0;
        }
        $ids = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::LOCATION_META,
            'meta_value'     => $term_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $cache[$term_id] = $ids ? (int) $ids[0] : 0;
        return $cache[$term_id];
    }

    /**
     * Link a ficha to a location term (0 unlinks it).
     *
     * Both sides are kept in sync: a term never points at a ficha that no
     * longer claims it, and the previous term of a re-linked ficha is cleared.
     * Unlinking stores `0` instead of deleting the meta so the automatic
     * same-slug sync leaves the admin's decision alone.
     */
    public static function set_location_term($spot_id, $term_id) {
        $spot_id = (int) $spot_id;
        $term_id = (int) $term_id;
        if (! $spot_id || self::CPT !== get_post_type($spot_id)) {
            return;
        }
        if ($term_id && ! term_exists($term_id, 'tap_location')) {
            $term_id = 0;
        }

        $previous = (int) get_post_meta($spot_id, self::LOCATION_META, true);

        update_post_meta($spot_id, self::LOCATION_META, $term_id);
        if ($term_id) {
            update_term_meta($term_id, self::LOCATION_TERM_META, $spot_id);
        }
        if ($previous && $previous !== $term_id) {
            // Only clear the old term when it still points at this ficha.
            if ((int) get_term_meta($previous, self::LOCATION_TERM_META, true) === $spot_id) {
                delete_term_meta($previous, self::LOCATION_TERM_META);
            }
        }
    }

    /**
     * One-off: link fichas to the same-slug location term.
     *
     * 13 of the 31 seeded fichas share a slug with a term (la-fortuna, samara,
     * manuel-antonio…). Linking them automatically is safe and reversible;
     * everything else stays unlinked until an editor picks a place in the
     * "Ubicación" box, because a fuzzy name match would attach, say, "Parque
     * Nacional Braulio Carrillo" to the canton of "Carrillo".
     *
     * Runs once and then short-circuits on a stored flag, so a front-end
     * request costs a single autoloaded option read and zero queries. When a
     * release adds new seeded destinations, bump LINK_VERSION to run it again;
     * explicit links (including "unlinked") are always respected.
     */
    public static function link_locations() {
        if ((int) get_option(self::LINK_OPTION) >= self::LINK_VERSION) {
            return;
        }
        if (! post_type_exists(self::CPT) || ! taxonomy_exists('tap_location')) {
            return;
        }

        $spots = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach ($spots as $spot_id) {
            // An explicit choice (including "Sin ubicación" = 0) is respected.
            if (metadata_exists('post', $spot_id, self::LOCATION_META)) {
                continue;
            }
            $slug = get_post_field('post_name', $spot_id);
            $term = $slug ? get_term_by('slug', $slug, 'tap_location') : null;
            if ($term instanceof WP_Term) {
                self::set_location_term($spot_id, (int) $term->term_id);
            }
        }

        update_option(self::LINK_OPTION, self::LINK_VERSION, true);
    }

    /**
     * Published services placed in the destination of a ficha.
     *
     * Returns the linked term, one group per service type (label, total and a
     * preview of the newest `$per_type`) and the overall total. Two queries:
     * one for the ids (grouping + counts) and one for the cards, so a
     * destination with 200 services still renders in constant time.
     *
     * @param int $spot_id  Ficha id.
     * @param int $per_type Cards shown per service type.
     * @return array{term:WP_Term|null,groups:array<string,array{label:string,total:int,posts:array<int,WP_Post>}>,total:int}
     */
    public static function services_for_spot($spot_id, $per_type = 4) {
        $term = self::location_term($spot_id);
        if (! $term) {
            return ['term' => null, 'groups' => [], 'total' => 0];
        }

        $labels = TAP_Post_Types::get_service_types();
        $types  = array_values(array_diff(array_keys($labels), ['tap_room']));

        $args = [
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy'         => 'tap_location',
                'field'            => 'term_id',
                'terms'            => (int) $term->term_id,
                'include_children' => true,
            ],],
        ];

        $excluded = class_exists('TAP_Approval') ? TAP_Approval::excluded_listing_ids() : [];
        if ($excluded) {
            $args['post__not_in'] = array_map('intval', $excluded);
        }

        $ids = get_posts($args);
        if (! $ids) {
            return ['term' => $term, 'groups' => [], 'total' => 0];
        }

        $by_type = [];
        foreach ($ids as $id) {
            $by_type[get_post_type($id)][] = (int) $id;
        }

        $groups = [];
        $preview = [];
        foreach ($by_type as $type => $list) {
            $groups[$type] = [
                'label' => $labels[$type] ?? $type,
                'total' => count($list),
                'posts' => [],
            ];
            $preview = array_merge($preview, array_slice($list, 0, max(1, (int) $per_type)));
        }

        if ($preview) {
            $posts = get_posts([
                'post_type'      => $types,
                'post_status'    => 'publish',
                'post__in'       => $preview,
                'orderby'        => 'post__in',
                'posts_per_page' => count($preview),
                'no_found_rows'  => true,
            ]);
            foreach ($posts as $post) {
                $groups[$post->post_type]['posts'][] = $post;
            }
        }

        return ['term' => $term, 'groups' => $groups, 'total' => count($ids)];
    }

    /**
     * Child places of a destination that are worth showing in the ficha.
     *
     * Prefers places that have a ficha or published services, so the block
     * never fills with empty administrative cantons.
     *
     * @param int $term_id Location term.
     * @param int $limit   Maximum entries.
     * @return array<int,array{term:WP_Term,url:string,spot_id:int,count:int}>
     */
    public static function nearby_places($term_id, $limit = 6) {
        if (! $term_id || ! class_exists('TAP_Destinations')) {
            return [];
        }
        $children = TAP_Destinations::children($term_id);
        if (is_wp_error($children) || ! $children) {
            return [];
        }

        $out = [];
        foreach ($children as $child) {
            $spot_id = self::spot_for_term($child->term_id);
            $count   = (int) $child->count;
            if (! $spot_id && ! $count) {
                continue;
            }
            $out[] = [
                'term'    => $child,
                'spot_id' => $spot_id,
                'count'   => $count,
                'url'     => $spot_id ? get_permalink($spot_id) : get_term_link($child),
            ];
            if (count($out) >= (int) $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Term ids that already have a ficha (their URL 301s to the ficha).
     *
     * Used by the sitemap so it does not advertise redirecting URLs.
     *
     * @return int[]
     */
    public static function linked_term_ids() {
        $spots = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::LOCATION_META,
            'meta_value'     => '0',
            'meta_compare'   => '>',
            'meta_type'      => 'NUMERIC',
        ]);

        $terms = [];
        foreach ($spots as $spot_id) {
            $term_id = (int) get_post_meta($spot_id, self::LOCATION_META, true);
            if ($term_id) {
                $terms[$term_id] = $term_id;
            }
        }
        return array_values($terms);
    }

    /**
     * Send a location term page to its ficha when one is linked.
     *
     * A destination has ONE canonical URL: the ficha. Terms that have a ficha
     * are consolidated with a 301 so links, bookmarks and search engines land
     * on the page that carries both the educational profile and the services.
     * Terms without a ficha keep serving their archive untouched.
     */
    public static function redirect_linked_terms() {
        if (is_admin() || ! is_tax('tap_location')) {
            return;
        }
        $term = get_queried_object();
        if (! $term instanceof WP_Term) {
            return;
        }
        $spot_id = self::spot_for_term($term->term_id);
        if (! $spot_id) {
            return;
        }
        $url = get_permalink($spot_id);
        if ($url) {
            wp_safe_redirect($url, 301);
            exit;
        }
    }

    // ---------------------------------------------------------------------
    // Seeding
    // ---------------------------------------------------------------------

    /**
     * Create the `/destinos/` page once and link it in the primary menu.
     *
     * Idempotent and non-destructive: if a page already exists at the slug it is
     * adopted (never overwritten), and the menu item is only added when missing.
     * The page body is a normal editable post holding `[tap_destinations_map]`,
     * so an admin can change the intro copy right from the editor.
     */
    public static function ensure_page() {
        if (!post_type_exists('page') || !taxonomy_exists('nav_menu')) {
            return;
        }

        $page_id = (int) get_option(self::PAGE_OPTION);

        // Re-validate with a single post read: a stale option (page deleted,
        // trashed or replaced) must fall through and heal. get_post() is
        // primed in the object cache, so this stays at one query per request
        // instead of the four the previous status/post pair issued.
        if ($page_id) {
            $page = get_post($page_id);
            if (!$page || 'page' !== $page->post_type || 'trash' === $page->post_status) {
                $page_id = 0;
            }
        }

        // Only touch the menu when the page had to be resolved or created;
        // linking on every request would re-read the whole menu for nothing.
        $needs_link = ($page_id === 0);

        if (!$page_id) {
            $existing = get_page_by_path('destinos', OBJECT, 'page');
            if ($existing) {
                $page_id = (int) $existing->ID;
            } else {
                $page_id = (int) wp_insert_post([
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => __('Destinos', 'travel-agency-platform'),
                    'post_name'    => 'destinos',
                    'post_content' => "[tap_destinations_map]\n",
                ], true);
                if (is_wp_error($page_id)) {
                    return;
                }
                flush_rewrite_rules(false);
            }
            update_option(self::PAGE_OPTION, $page_id);
        }

        if ($needs_link) {
            self::link_menu_item($page_id);
        }
    }

    /** Append the Destinos page to the primary menu if it is not already there. */
    private static function link_menu_item($page_id) {
        $locations = get_nav_menu_locations();
        if (empty($locations['primary'])) {
            return;
        }
        $menu_id = (int) $locations['primary'];

        foreach (wp_get_nav_menu_items($menu_id) as $item) {
            if ((string) $item->object === 'page' && (int) $item->object_id === (int) $page_id) {
                return; // already linked
            }
        }

        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'     => __('Destinos', 'travel-agency-platform'),
            'menu-item-object'    => 'page',
            'menu-item-object-id' => (int) $page_id,
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
        ]);
    }

    /**
     * Seed ~14 real Costa Rican protected places once (guarded by an option so
     * an admin's own edits are never overwritten on re-runs). Everything seeded
     * here is fully editable afterwards from wp-admin.
     */
    public static function seed() {
        if (!post_type_exists(self::CPT) || get_option(self::SEED_OPTION)) {
            return;
        }
        self::seed_types();

        foreach (self::seed_spots() as $slug => $spot) {
            self::insert_spot($slug, $spot);
        }

        update_option(self::SEED_OPTION, 1);
    }

    /**
     * Back-fill the richer educational fields on spots seeded before they
     * existed. Idempotent (guarded by a versioned option) and non-destructive:
     * it only writes a field when the stored value is still empty, so an
     * editor's own edits are never overwritten.
     */
    public static function enrich_existing() {
        if (!post_type_exists(self::CPT)) {
            return;
        }
        $done = (int) get_option(self::ENRICH_OPTION);
        if ($done >= self::ENRICH_VERSION) {
            return;
        }

        $list_keys = ['biodiversity', 'activities', 'tips'];
        $text_keys = ['region', 'ecosystem', 'altitude', 'best_season', 'conservation', 'accessibility', 'website'];

        foreach (self::seed_spots() as $slug => $spot) {
            $post = get_page_by_path($slug, OBJECT, self::CPT);
            if (!$post) {
                continue;
            }
            $id = (int) $post->ID;
            foreach ($list_keys as $k) {
                if (empty($spot[$k]) || !is_array($spot[$k])) {
                    continue;
                }
                if ((string) get_post_meta($id, '_tap_spot_' . $k, true) === '') {
                    update_post_meta($id, '_tap_spot_' . $k, implode("\n", $spot[$k]));
                }
            }
            foreach ($text_keys as $k) {
                if (empty($spot[$k])) {
                    continue;
                }
                if ((string) get_post_meta($id, '_tap_spot_' . $k, true) === '') {
                    update_post_meta($id, '_tap_spot_' . $k, $spot[$k]);
                }
            }
            // The editorial body lives in the post itself, not in meta.
            if (!empty($spot['content']) && '' === trim((string) $post->post_content)) {
                wp_update_post([
                    'ID'           => $id,
                    'post_content' => $spot['content'],
                ]);
            }
        }

        update_option(self::ENRICH_OPTION, self::ENRICH_VERSION);
    }

    /**
     * Insert the destinations added after the first release.
     *
     * Idempotent and non-destructive: a destination that already exists is
     * left untouched, so an editor's edits are never lost.
     *
     * Runs on `init` for every request, so it short-circuits on a stored
     * signature of the seed set. Adding a new destination to
     * seed_extra_spots() changes the signature and triggers exactly one sync;
     * every other request costs a single autoloaded option read and zero
     * queries.
     */
    public static function sync_extra_spots() {
        if (!post_type_exists(self::CPT)) {
            return;
        }

        $spots     = self::seed_extra_spots();
        $signature = md5(implode('|', array_keys($spots)));
        if (get_option(self::SPOTS_SIGNATURE_OPTION) === $signature) {
            return;
        }

        self::seed_types();
        foreach ($spots as $slug => $spot) {
            self::insert_spot($slug, $spot);
        }

        update_option(self::SPOTS_SIGNATURE_OPTION, $signature);
    }

    private static function insert_spot($slug, array $spot) {
        $existing = get_page_by_path($slug, OBJECT, self::CPT);
        if ($existing) {
            return (int) $existing->ID;
        }

        $post_id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'post_title'   => $spot['title'],
            'post_name'    => $slug,
            'post_excerpt' => $spot['excerpt'],
            'post_content' => $spot['content'],
        ], true);
        if (is_wp_error($post_id)) {
            return 0;
        }
        $post_id = (int) $post_id;

        if (!empty($spot['type']) && term_exists($spot['type'], self::TAX)) {
            wp_set_object_terms($post_id, $spot['type'], self::TAX);
        }

        $list_keys = ['interest', 'biodiversity', 'activities', 'tips'];
        $text_keys = ['province', 'region', 'ecosystem', 'altitude', 'best_season', 'schedule', 'fee', 'area', 'created', 'accessibility', 'website', 'conservation'];

        update_post_meta($post_id, '_tap_spot_lat', $spot['lat']);
        update_post_meta($post_id, '_tap_spot_lng', $spot['lng']);
        foreach ($list_keys as $k) {
            if (!empty($spot[$k]) && is_array($spot[$k])) {
                update_post_meta($post_id, '_tap_spot_' . $k, implode("\n", $spot[$k]));
            }
        }
        foreach ($text_keys as $k) {
            if (!empty($spot[$k])) {
                update_post_meta($post_id, '_tap_spot_' . $k, $spot[$k]);
            }
        }
        return $post_id;
    }

    /**
     * The seed content: real places with real approximate coordinates and
     * factual educational notes. Coordinates are approximate centroids.
     */
    public static function seed_spots() {
        $spots = [
            'volcan-arenal' => [
                'title'    => 'Parque Nacional Volcán Arenal',
                'type'     => 'parque-nacional',
                'lat' => '10.4631', 'lng' => '-84.7033',
                'province' => 'Alajuela',
                'excerpt'  => __('Uno de los volcanes más activos del mundo y el ícono de La Fortuna.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('El Arenal es un estratovolcán de 1 670 msnm; su última gran erupción fue en 1968.', 'travel-agency-platform'),
                    __('Entre 1968 y 2010 mantuvo actividad casi permanente, con flujos de lava visibles de noche.', 'travel-agency-platform'),
                    __('Protege 12 124 hectáreas de bosque premontano y numerosa fauna: tucanes, perezosos y coatíes.', 'travel-agency-platform'),
                    __('Hay senderos como El Silencio y la colada de lava de 1968; el mirador del lago es imperdible.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $15 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('12 124 ha', 'travel-agency-platform'),
                'created'  => '1991',
                'region'   => __('Zona Norte', 'travel-agency-platform'),
                'ecosystem' => __('Bosque premontano húmedo y coladas de lava', 'travel-agency-platform'),
                'altitude' => __('1 670 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril (estación seca)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Tucán pico iris y otras 400+ especies de aves', 'travel-agency-platform'),
                    __('Perezoso de tres dedos y mono aullador', 'travel-agency-platform'),
                    __('Coa/coatí, pizote y venado cola blanca', 'travel-agency-platform'),
                    __('Rana de ojos rojos y numerosos reptiles', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminatas guiadas por El Silencio y el mirador del lago', 'travel-agency-platform'),
                    __('Avistamiento de aves al amanecer', 'travel-agency-platform'),
                    __('Baño en termales naturales al pie del volcán', 'travel-agency-platform'),
                    __('Canopy y puentes colgantes en la zona', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Lleva capa de lluvia: el clima es húmedo casi todo el año.', 'travel-agency-platform'),
                    __('Mejor visibilidad del cono por la mañana temprano.', 'travel-agency-platform'),
                    __('Usa calzado cerrado con buen agarre para los senderos.', 'travel-agency-platform'),
                ],
                'conservation' => __('Desde 2010 el volcán entró en reposo; el parque protege el bosque que se regeneró sobre las coladas históricas.', 'travel-agency-platform'),
            ],
            'tortuguero' => [
                'title'    => 'Parque Nacional Tortuguero',
                'type'     => 'parque-nacional',
                'lat' => '10.5406', 'lng' => '-83.5026',
                'province' => 'Limón',
                'excerpt'  => __('La "Amazonia de Costa Rica": canales, selva y la mayor playa de anidación de tortuga verde del Caribe.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Es el sitio de anidación más importante del hemisferio para la tortuga verde (Chelonia mydas).', 'travel-agency-platform'),
                    __('Solo se llega por agua o por aire: no hay carretera hasta el pueblo de Tortuguero.', 'travel-agency-platform'),
                    __('Sus 31 187 ha protegen canales, lagunas y bosque húmedo tropical con 400 especies de árboles.', 'travel-agency-platform'),
                    __('La temporada de desove va de julio a octubre; los tours nocturnos son guiados y regulados.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 6:00 a 18:00 (tours nocturnos con guía)', 'travel-agency-platform'),
                'fee'      => __('Entrada: $17 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('31 187 ha', 'travel-agency-platform'),
                'created'  => '1975',
                'region'   => __('Caribe Norte', 'travel-agency-platform'),
                'ecosystem' => __('Bosque húmedo tropical, canales y lagunas costeras', 'travel-agency-platform'),
                'altitude' => __('0 msnm (nivel del mar)', 'travel-agency-platform'),
                'best_season' => __('Julio a octubre (desove de tortugas)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Tortuga verde, la mayor población de anidación del hemisferio', 'travel-agency-platform'),
                    __('Tortuga laúd, carey y culibrán', 'travel-agency-platform'),
                    __('Marañón y delfín mular en los canales', 'travel-agency-platform'),
                    __('Aves de humedal, ranas arbóreas y peces tropicales', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Recorrido en bote por los canales con guía', 'travel-agency-platform'),
                    __('Caminatas nocturnas para observar el desove', 'travel-agency-platform'),
                    __('Kayak y remar en la laguna', 'travel-agency-platform'),
                    __('Avistamiento de aves en la selva', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Se necesita permiso de acceso: reserve con antelación en temporada alta.', 'travel-agency-platform'),
                    __('El parque no permite entrar con ganado: use repelente y ropa larga.', 'travel-agency-platform'),
                    __('Pague a guías autorizados dentro del parque.', 'travel-agency-platform'),
                ],
                'conservation' => __('Está conectado al Corredor Biológico Talamanca-Caribe y al Parque Nacional Corcovado, formando uno de los mayores bloques de selva húmeda del mundo.', 'travel-agency-platform'),
            ],
            'manuel-antonio' => [
                'title'    => 'Parque Nacional Manuel Antonio',
                'type'     => 'parque-nacional',
                'lat' => '9.3917', 'lng' => '-84.1375',
                'province' => 'Puntarenas',
                'excerpt'  => __('Playas de arena blanca, acantilados y monos que bajan de los árboles: el parque más visitado del país.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Es el parque más visitado de Costa Rica y uno de los más pequeños con apenas 1 983 ha.', 'travel-agency-platform'),
                    __('Cuenta con tres playas accesibles: Espadilla Sur, Manuel Antonio y Puerto Escondido.', 'travel-agency-platform'),
                    __('Hogar de monos carablanca, tití y aulladores, además de perezosos de tres dedos.', 'travel-agency-platform'),
                    __('La entrada tiene aforo limitado por franjas horarias; conviene reservar con antelación.', 'travel-agency-platform'),
                ],
                'schedule' => __('Martes a domingo, 7:00 a 16:00 (cerrado lunes)', 'travel-agency-platform'),
                'fee'      => __('Entrada: $18 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('1 983 ha', 'travel-agency-platform'),
                'created'  => '1972',
                'region'   => __('Pacífico Central', 'travel-agency-platform'),
                'ecosystem' => __('Selva húmeda tropical y playas de arena blanca', 'travel-agency-platform'),
                'altitude' => __('0-160 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a agosto (fauna del Pacífico)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Mono carablanca y mono tití', 'travel-agency-platform'),
                    __('Perezoso de tres dedos y coatí', 'travel-agency-platform'),
                    __('Aguacate, tucán y guacamaya', 'travel-agency-platform'),
                    __('Tortugas verdes que anidan en la bahía', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Senderismo por el Parque del Manglar', 'travel-agency-platform'),
                    __('Kayak y paddle board en Playa Espadilla', 'travel-agency-platform'),
                    __('Paseos en barco y avistamiento de ballenas', 'travel-agency-platform'),
                    __('Caminatas nocturnas para observar fauna', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La entrada es por cupos y franjas: compre la de las 7:00 para ver más fauna.', 'travel-agency-platform'),
                    __('No se permite estacionar fuera del estacionamiento con cupo.', 'travel-agency-platform'),
                    __('El mar es fuerte: lo seguro son largas caminatas, no el baño.', 'travel-agency-platform'),
                ],
                'conservation' => __('A pesar de su tamaño reducido, concentra una densidad de especies excepcional; su manejo busca compatibilizar el uso turístico con la conservación.', 'travel-agency-platform'),
            ],
            'corcovado' => [
                'title'    => 'Parque Nacional Corcovado',
                'type'     => 'parque-nacional',
                'lat' => '8.5377', 'lng' => '-83.5717',
                'province' => 'Puntarenas',
                'excerpt'  => __('La joya de la Península de Osa: el bosque primario más extenso del Pacífico centroamericano.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('National Geographic lo llamó "el lugar con mayor intensidad biológica del planeta" en bosque húmedo.', 'travel-agency-platform'),
                    __('Es el único lugar de Costa Rica donde coexisten las cuatro especies de monos del país.', 'travel-agency-platform'),
                    __('Poblaciones importantes de tapir, puma, jaguar y la guacamaya roja se refugian aquí.', 'travel-agency-platform'),
                    __('Se accede a pie desde Los Patos, de noche desde Drake o en avioneta; requiere guía autorizado.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 6:00 a 17:00 (ingreso con guía)', 'travel-agency-platform'),
                'fee'      => __('Entrada: $20 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('42 469 ha', 'travel-agency-platform'),
                'created'  => '1975',
                'region'   => __('Península de Osa', 'travel-agency-platform'),
                'ecosystem' => __('Bosque húmedo tropical primario', 'travel-agency-platform'),
                'altitude' => __('0-1 600 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a octubre', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Las cuatro especies de monos del país juntas', 'travel-agency-platform'),
                    __('Tapir, jaguar, puma y coatí', 'travel-agency-platform'),
                    __('Guacamaya roja y monos aulladores', 'travel-agency-platform'),
                    __('Más de 500 especies de aves', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminatas guiadas de varios días en la selva', 'travel-agency-platform'),
                    __('Avistamiento de aves desde el dosel', 'travel-agency-platform'),
                    __('Caminata nocturna en busca de fauna', 'travel-agency-platform'),
                    __('Cruceros nocturnos desde Drake Bay', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El ingreso es obligatorio con guía: reserve con el parque.', 'travel-agency-platform'),
                    __('No hay señal celular ni cobertura: avise su plan.', 'travel-agency-platform'),
                    __('Prepare equipo para lluvia intensa y calor.', 'travel-agency-platform'),
                ],
                'conservation' => __('Junto con Tortuguero forma el bloque de bosque tropical húmedo continuo más grande de Mesoamérica; su creación protegió la integridad de la Península de Osa.', 'travel-agency-platform'),
            ],
            'rincon-de-la-vieja' => [
                'title'    => 'Parque Nacional Rincón de la Vieja',
                'type'     => 'parque-nacional',
                'lat' => '10.8278', 'lng' => '-85.3232',
                'province' => 'Guanacaste',
                'excerpt'  => __('Volcán, fumarolas, lodo hirviente y cataratas: geología viva en Guanacaste.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('El complejo volcánico tiene nueve conos; el Von Seebach es el punto más alto con 1 895 msnm.', 'travel-agency-platform'),
                    __('Concentra 32 ríos y decenas de cataratas; la catarata La Cangreja es la más famosa.', 'travel-agency-platform'),
                    __('Sus fumarolas y ollas de lodo son un laboratorio natural de actividad geotérmica.', 'travel-agency-platform'),
                    __('Forma parte del Área de Conservación Guanacaste, Patrimonio de la Humanidad (UNESCO).', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 7:00 a 15:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $17 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('14 083 ha', 'travel-agency-platform'),
                'created'  => '1973',
                'region'   => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque tropical seco y geotermia activa', 'travel-agency-platform'),
                'altitude' => __('0-1 895 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril (temporada seca)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Venado cola blanca y puma', 'travel-agency-platform'),
                    __('Zorro gris, coatí y armadillo', 'travel-agency-platform'),
                    __('Aves de bosque seco como el chocoano', 'travel-agency-platform'),
                    __('Aves atraídas por los manantiales termales', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Visita a las fumarolas y las ollas de barro', 'travel-agency-platform'),
                    __('Caminata hasta la catarata La Cangreja', 'travel-agency-platform'),
                    __('Baños termales en el río activo', 'travel-agency-platform'),
                    __('Cabalgata por la sabana del parque', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El barro burbujeante es sulfúrico: no lo toque.', 'travel-agency-platform'),
                    __('Lleve traje de baño, toalla y calzado de agua.', 'travel-agency-platform'),
                    __('El camino a la catarata es de tierra: no apto para autos.', 'travel-agency-platform'),
                ],
                'conservation' => __('Forma parte del Área de Conservación Guanacaste, reconocida por la UNESCO como Patrimonio de la Humanidad por su biodiversidad.', 'travel-agency-platform'),
            ],
            'poas' => [
                'title'    => 'Parque Nacional Volcán Poás',
                'type'     => 'parque-nacional',
                'lat' => '10.1977', 'lng' => '-84.2312',
                'province' => 'Alajuela',
                'excerpt'  => __('Uno de los cráteres activos más grandes del mundo, accesible casi hasta el borde.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Su cráter principal mide 1,3 km de diámetro y es uno de los más anchos del planeta.', 'travel-agency-platform'),
                    __('La laguna del cráter cambia de color y a veces desaparece por completo con la temperatura.', 'travel-agency-platform'),
                    __('El mirador está a pocos minutos del estacionamiento, ideal para visita de medio día.', 'travel-agency-platform'),
                    __('El acceso se controla por aforo y horario; la nubosidad puede ocultar el cráter temprano.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 7:00 a 14:00 (reserva obligatoria)', 'travel-agency-platform'),
                'fee'      => __('Entrada: $17 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('6 506 ha', 'travel-agency-platform'),
                'created'  => '1971',
                'region'   => __('Valle Central', 'travel-agency-platform'),
                'ecosystem' => __('Bosque montano y cráter volcánico', 'travel-agency-platform'),
                'altitude' => __('1 350-2 540 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril, en días despejados', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Quetzal, ave nacional y símbolo del parque', 'travel-agency-platform'),
                    __('Conejo de monte y cusuco', 'travel-agency-platform'),
                    __('Bromélias y orquídeas epífitas del bosque alto', 'travel-agency-platform'),
                    __('Aves carroñeras atraídas al cráter', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Mirador del cráter a pie', 'travel-agency-platform'),
                    __('Sendero al bosque alto y la laguna del cráter', 'travel-agency-platform'),
                    __('Avistamiento del quetzal en el bosque alto', 'travel-agency-platform'),
                    __('Visita al mirador de la cima con binoculares', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La reserva incluye un cupo por turno: reserve online.', 'travel-agency-platform'),
                    __('Lleve ropa de abrigo: el cráter está a 8-10 °C.', 'travel-agency-platform'),
                    __('El cráter puede soltar gases: respete las barreras de seguridad', 'travel-agency-platform'),
                ],
                'conservation' => __('Protege el bosque montano del norte del Valle Central, uno de los últimos remanentes de bosque nuboso del país.', 'travel-agency-platform'),
            ],
            'irazu' => [
                'title'    => 'Parque Nacional Volcán Irazú',
                'type'     => 'volcan',
                'lat' => '9.9797', 'lng' => '-83.8525',
                'province' => 'Cartago',
                'excerpt'  => __('El volcán más alto del país: en un día claro se ven los dos océanos.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Con 3 432 msnm es el volcán más alto de Costa Rica.', 'travel-agency-platform'),
                    __('En días despejados se divisan el Pacífico y el Caribe desde la cima.', 'travel-agency-platform'),
                    __('Su laguna del cráter varía de verde a turquesa según los minerales disueltos.', 'travel-agency-platform'),
                    __('La carretera llega casi hasta el cráter, por lo que es accesible sin caminata larga.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $15 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('2 309 ha', 'travel-agency-platform'),
                'created'  => '1955',
                'region'   => __('Valle Central', 'travel-agency-platform'),
                'ecosystem' => __('Bosque montano y páramo de altura', 'travel-agency-platform'),
                'altitude' => __('1 875-3 432 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril (los días más despejados)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Quetzal y colibríes de montaña', 'travel-agency-platform'),
                    __('Venado de cola blanca y puma', 'travel-agency-platform'),
                    __('Bromélias, musgos y frailejones de páramo', 'travel-agency-platform'),
                    __('Aves de páramo y colibríes de montaña', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Subir en vehículo hasta el mirador', 'travel-agency-platform'),
                    __('Caminata corta hasta la cima del cráter', 'travel-agency-platform'),
                    __('Fotografiar la laguna del cráter', 'travel-agency-platform'),
                    __('Avistamiento de aves en el camino de acceso', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Faltan unos 40 km de camino hasta el cráter: maneje con cuidado.', 'travel-agency-platform'),
                    __('El frío en la cima es intenso, incluso en verano.', 'travel-agency-platform'),
                    __('Revise el estado del cráter: se cierra por actividad volcánica.', 'travel-agency-platform'),
                ],
                'conservation' => __('Su cráter es inestable y con frecuencia se cierra al público; el parque protege un bosque montano y una franja de páramo de gran valor.', 'travel-agency-platform'),
            ],
            'chirripo' => [
                'title'    => 'Parque Nacional Chirripó',
                'type'     => 'parque-nacional',
                'lat' => '9.4855', 'lng' => '-83.4870',
                'province' => 'San José',
                'excerpt'  => __('El techo de Costa Rica: páramos, lagos glaciares y el famoso amanecer sobre el Pacífico.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('El Cerro Chirripó alcanza 3 820 msnm, el punto más alto del país.', 'travel-agency-platform'),
                    __('En la cima, en días claros, se ven el Pacífico y el Caribe al mismo tiempo.', 'travel-agency-platform'),
                    __('Sus lagos de origen glaciar, como el Lagos Ditkevi, son únicos en Centroamérica.', 'travel-agency-platform'),
                    __('La subida requiere reserva de refugio y por lo general se hace en dos días.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días con reserva previa, 5:00 a 17:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $18 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('50 150 ha', 'travel-agency-platform'),
                'created'  => '1975',
                'region'   => __('Valle Central', 'travel-agency-platform'),
                'ecosystem' => __('Páramo, bosque nuboso y lagunas glaciares', 'travel-agency-platform'),
                'altitude' => __('1 100-3 820 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio (clima estable)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Quetzal y grallaria', 'travel-agency-platform'),
                    __('Páramo con frailejones y musgos', 'travel-agency-platform'),
                    __('Aves migratorias como la golondrina', 'travel-agency-platform'),
                    __('Zorro andino y venado de monte', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Ascenso de dos días hasta la cima', 'travel-agency-platform'),
                    __('Visita a los lagos Ditkevi y Chadolín', 'travel-agency-platform'),
                    __('Observación del amanecer sobre el Pacífico', 'travel-agency-platform'),
                    __('Caminata por el valle de Cerros', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La reserva del refugio es obligatoria y se agota rápido.', 'travel-agency-platform'),
                    __('Se necesita un guía para la zona de páramo.', 'travel-agency-platform'),
                    __('El clima cambia rápido: el frío en la cima es extremo.', 'travel-agency-platform'),
                ],
                'conservation' => __('Sus páramos son fuentes de agua para el Valle Central; la presión turística se gestiona con cupos, senderos señalizados y cierres temporales.', 'travel-agency-platform'),
            ],
            'cahuita' => [
                'title'    => 'Parque Nacional Cahuita',
                'type'     => 'playa-protegida',
                'lat' => '9.7382', 'lng' => '-82.8343',
                'province' => 'Limón',
                'excerpt'  => __('Arrecife de coral, playa de arena blanca y selva que llega al mar en el Caribe sur.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Protege uno de los arrecifes de coral mejor conservados del Caribe costarricense.', 'travel-agency-platform'),
                    __('Se puede recorrer a pie un sendero de 8 km entre playa y bosque.', 'travel-agency-platform'),
                    __('Sus aguas tranquilas son ideales para snorkel con peces tropicales.', 'travel-agency-platform'),
                    __('El terremoto de 1991 elevó la costa y creó la playa actual frente al pueblo.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $5 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('2 245 ha (1 067 terrestres / 600 marinas)', 'travel-agency-platform'),
                'created'  => '1978',
                'region'   => __('Caribe Sur', 'travel-agency-platform'),
                'ecosystem' => __('Arrecife de coral, manglar y selva costera', 'travel-agency-platform'),
                'altitude' => __('0-300 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a octubre', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Peces de arrecife, rayas y tiburones', 'travel-agency-platform'),
                    __('Tortuga verde y tortuga laúd', 'travel-agency-platform'),
                    __('Manglar con cangrejos, peces y tortugas', 'travel-agency-platform'),
                    __('Aves costeras como la fragata y el pelicano', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Snorkel en el arrecife de la punta', 'travel-agency-platform'),
                    __('Caminata del sendero costero de 8 km', 'travel-agency-platform'),
                    __('Kayak por la laguna de Cahuita', 'travel-agency-platform'),
                    __('Avistamiento de tortugas y aves', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Use protector solar sin oxibenzona: protege el coral.', 'travel-agency-platform'),
                    __('El mar puede ser fuerte en temporada de lluvias: respete la bandera.', 'travel-agency-platform'),
                    __('No hay servicios en el parque: solo acceso por sendero.', 'travel-agency-platform'),
                ],
                'conservation' => __('Su arrecife forma parte del Área de Conservación de Guanacaste y se recupera de los daños térmicos del terremoto de 1991.', 'travel-agency-platform'),
            ],
            'marino-ballena' => [
                'title'    => 'Parque Nacional Marino Ballena',
                'type'     => 'playa-protegida',
                'lat' => '9.1107', 'lng' => '-83.7356',
                'province' => 'Puntarenas',
                'excerpt'  => __('La cola de ballena más famosa del país y santuario de migración de ballenas jorobadas.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Su tómbolo de arena dibuja una cola de ballena en marea baja, visible en fotos aéreas.', 'travel-agency-platform'),
                    __('Dos poblaciones de ballena jorobada migran aquí: del hemisferio norte y del sur.', 'travel-agency-platform'),
                    __('Es uno de los pocos lugares del mundo donde se ven ambas migraciones en un año.', 'travel-agency-platform'),
                    __('Uvita, su comunidad, organiza el Festival de la Ballena en temporada de avistamiento.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 7:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $6 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('5 375 ha (110 terrestres / 5 265 marinas)', 'travel-agency-platform'),
                'created'  => '1989',
                'region'   => __('Pacífico Sur', 'travel-agency-platform'),
                'ecosystem' => __('Humedal costero, playa y zona marina', 'travel-agency-platform'),
                'altitude' => __('0 msnm (nivel del mar)', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a octubre (migraciones)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Ballena jorobada del norte y del sur', 'travel-agency-platform'),
                    __('Delfines, tortugas y rayas', 'travel-agency-platform'),
                    __('Peces de arrecife en la zona marina', 'travel-agency-platform'),
                    __('Aves playeras y garzas en el tómbolo', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Avistamiento de ballenas en lancha', 'travel-agency-platform'),
                    __('Caminata guiada por el tómbolo de arena', 'travel-agency-platform'),
                    __('Kayak entre arenas y manglares', 'travel-agency-platform'),
                    __('Tour de manglar en la laguna', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El avistamiento se hace desde 300 metros o más lejos.', 'travel-agency-platform'),
                    __('No se puede entrar al agua con las ballenas.', 'travel-agency-platform'),
                    __('Reserve con operadores autorizados por el parque.', 'travel-agency-platform'),
                ],
                'conservation' => __('La prohibición de cazar ballenas en aguas costeras, convertida en ley en 1992, ha protegido a las poblaciones de jorobadas de la zona.', 'travel-agency-platform'),
            ],
            'guayabo' => [
                'title'    => 'Monumento Nacional Guayabo',
                'type'     => 'sitio-arqueologico',
                'lat' => '9.9717', 'lng' => '-83.6897',
                'province' => 'Cartago',
                'excerpt'  => __('La ciudad precolombina más importante de Costa Rica: acueductos y calzadas de hace 3 000 años.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Fue una ciudad habitada entre el 1000 a.C. y el 1400 d.C., hoy con ruinas de piedra.', 'travel-agency-platform'),
                    __('Sus acueductos y tanques de agua siguen funcionando en parte tras siglos.', 'travel-agency-platform'),
                    __('Las calzadas empedradas conectaban la ciudad con otros asentamientos de la región.', 'travel-agency-platform'),
                    __('Fue declarado Monumento Nacional en 1973 y Patrimonio de Ingeniería (ASCE) en 2009.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 15:30', 'travel-agency-platform'),
                'fee'      => __('Entrada: $5 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('233 ha', 'travel-agency-platform'),
                'created'  => '1973',
                'region'   => __('Valle Central', 'travel-agency-platform'),
                'ecosystem' => __('Bosque premontano y sitio arqueológico', 'travel-agency-platform'),
                'altitude' => __('1 200-1 400 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril (temporada seca)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Bosque secundario en regeneración', 'travel-agency-platform'),
                    __('Aves como el chocoano y el tucán', 'travel-agency-platform'),
                    __('Mamíferos como el coatí y el zorro', 'travel-agency-platform'),
                    __('Mariposas y polinizadores de los campos abiertos', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Recorrer el sitio con guía', 'travel-agency-platform'),
                    __('Ver los acueductos y los tanques de piedra', 'travel-agency-platform'),
                    __('Senderismo por la calzada empedrada', 'travel-agency-platform'),
                    __('Observación de aves en el bosque', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El sendero principal es accesible en silla de ruedas.', 'travel-agency-platform'),
                    __('El museo cierra al mediodía: planifique la visita temprano.', 'travel-agency-platform'),
                    __('Use repelente: el bosque es húmedo.', 'travel-agency-platform'),
                ],
                'conservation' => __('Declarado Patrimonio de la Ingeniería por la ASCE en 2009; su conservación revela cómo las sociedades precolombinas gestionaron el agua.', 'travel-agency-platform'),
            ],
            'tenorio' => [
                'title'    => 'Parque Nacional Volcán Tenorio',
                'type'     => 'parque-nacional',
                'lat' => '10.6731', 'lng' => '-85.0225',
                'province' => 'Guanacaste',
                'excerpt'  => __('El hogar del Río Celeste, donde el agua se vuelve turquesa por un fenómeno mineral único.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('El color turquesa del Río Celeste resulta de la mezcla de minerales de dos ríos.', 'travel-agency-platform'),
                    __('Tiene cuatro volcanes apagados y la catarata del río es uno de los sitios más fotografiados.', 'travel-agency-platform'),
                    __('Protege bosque nuboso y lluvioso, hábitat del tapir y de aves como el quetzal.', 'travel-agency-platform'),
                    __('El sendero de 3 km lleva a la catarata, la laguna azul y los "teñideros".', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 14:00 (cupo limitado por hora)', 'travel-agency-platform'),
                'fee'      => __('Entrada: $12 extranjeros / ₡800 nacionales', 'travel-agency-platform'),
                'area'     => __('12 870 ha', 'travel-agency-platform'),
                'created'  => '1995',
                'region'   => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque nuboso y lluvioso de montaña', 'travel-agency-platform'),
                'altitude' => __('0-2 000 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril (nubosidad baja en la cima)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Quetzal y colibríes de montaña', 'travel-agency-platform'),
                    __('Tapir, venado y coatí', 'travel-agency-platform'),
                    __('Aves de bosque nuboso como el chocoano', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata al Río Celeste (3 km)', 'travel-agency-platform'),
                    __('Visita a la laguna Azul y la catarata', 'travel-agency-platform'),
                    __('Paseo a caballo por el parque', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El acceso tiene cupo por hora: llegue temprano.', 'travel-agency-platform'),
                    __('El río Celeste tiene corrientes fuertes: no se puede nadar.', 'travel-agency-platform'),
                    __('Lleve ropa de lluvia y calzado impermeable.', 'travel-agency-platform'),
                ],
                'conservation' => __('Parte del Área de Conservación Guanacaste; su bosque nuboso protege el corredor biológico entre el Pacífico y el Valle Central.', 'travel-agency-platform'),
            ],
            'palo-verde' => [
                'title'    => 'Parque Nacional Palo Verde',
                'type'     => 'zona-protegida',
                'lat' => '10.3535', 'lng' => '-85.3489',
                'province' => 'Guanacaste',
                'excerpt'  => __('Humedal y bosque tropical seco: el mejor lugar del país para observar aves acuáticas.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Es el humedal más importante de Costa Rica y santuario de aves migratorias.', 'travel-agency-platform'),
                    __('En sus lagunas invernan miles de aves acuáticas, incluidas garzas y playeros migrantes.', 'travel-agency-platform'),
                    __('Protege bosque tropical seco, uno de los ecosistemas más amenazados del mundo.', 'travel-agency-platform'),
                    __('Alberga la mayor población de jabirú (cigüeña) del Pacífico centroamericano.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 7:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $12 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('18 418 ha', 'travel-agency-platform'),
                'created'  => '1978',
                'region'   => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Humedal, manglar y bosque tropical seco', 'travel-agency-platform'),
                'altitude' => __('0-300 msnm', 'travel-agency-platform'),
                'best_season' => __('Noviembre a marzo (aves migratorias)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Jabirú, la cigüeña emblemática del Pacífico', 'travel-agency-platform'),
                    __('Más de 200 especies de aves acuáticas', 'travel-agency-platform'),
                    __('Crocodilos americanos en las lagunas', 'travel-agency-platform'),
                    __('Venado y coatí en el bosque seco', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Recorrido en lancha por las lagunas', 'travel-agency-platform'),
                    __('Observación de aves con guía especializada', 'travel-agency-platform'),
                    __('Caminata por el bosque seco', 'travel-agency-platform'),
                    __('Visita a las lagunas de la zona', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Hay cocodrilos activos: no se acerque al agua.', 'travel-agency-platform'),
                    __('El centro del parque cierra al mediodía.', 'travel-agency-platform'),
                    __('Use repelente y protección solar.', 'travel-agency-platform'),
                ],
                'conservation' => __('El humedal más importante de Costa Rica y sede del Santuario de Aves Migratorias de Palmar Chico.', 'travel-agency-platform'),
            ],
            'isla-del-coco' => [
                'title'    => 'Parque Nacional Isla del Coco',
                'type'     => 'reserva-biologica',
                'lat' => '5.5271', 'lng' => '-87.0580',
                'province' => 'Puntarenas',
                'excerpt'  => __('La isla del tesoro: 550 km mar adentro, Patrimonio de la Humanidad y paraíso de tiburones.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Está a 550 km del continente y solo se llega en barco (36 h) o tras vuelo privado.', 'travel-agency-platform'),
                    __('Fue declarada Patrimonio de la Humanidad por la UNESCO en 1997.', 'travel-agency-platform'),
                    __('Sus aguas concentran tiburones martillo en cardúmenes, y se cree inspiración de "Jurassic Park".', 'travel-agency-platform'),
                    __('En la isla hay más de 400 especies de insectos y plantas endémicas.', 'travel-agency-platform'),
                ],
                'schedule' => __('Acceso solo con permiso y guardaparques; cruceros de varios días', 'travel-agency-platform'),
                'fee'      => __('Entrada: $35 extranjeros / ₡2 000 nacionales', 'travel-agency-platform'),
                'area'     => __('2 095 ha terrestres / 972 000 ha marinas', 'travel-agency-platform'),
                'created'  => '1978',
                'region'   => __('Pacífico Sur', 'travel-agency-platform'),
                'ecosystem' => __('Isla oceánica y arrecifes de coral', 'travel-agency-platform'),
                'altitude' => __('0-300 msnm', 'travel-agency-platform'),
                'best_season' => __('Mayo a octubre (aguas tranquilas)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Tiburones martillo y tiburones bordeados', 'travel-agency-platform'),
                    __('Más de 400 especies de insectos y plantas endémicas', 'travel-agency-platform'),
                    __('Peces de arrecife y rayas', 'travel-agency-platform'),
                    __('Aves marinas y tortugas verdes', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Buceo con guía en los arrecifes', 'travel-agency-platform'),
                    __('Snorkel con tiburones martillo', 'travel-agency-platform'),
                    __('Caminatas guiadas por la selva de la isla', 'travel-agency-platform'),
                    __('Observación de aves marinas y pingüinos', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El permiso de acceso lo emite el parque: es obligatorio.', 'travel-agency-platform'),
                    __('El viaje en barco dura 36 horas: planee la logística.', 'travel-agency-platform'),
                    __('Lleva provisiones y equipo de buceo propio', 'travel-agency-platform'),
                ],
                'conservation' => __('Su protección como Reserva Biológica y Patrimonio de la Humanidad es uno de los mayores logros de conservación marina del país.', 'travel-agency-platform'),
            ],
            'gandoca-manzanillo' => [
                'title'    => 'Refugio de Vida Silvestre Gandoca-Manzanillo',
                'type'     => 'refugio-vida-silvestre',
                'lat' => '9.6263', 'lng' => '-82.6788',
                'province' => 'Limón',
                'excerpt'  => __('Arrecife, manglar y playas de desove de tortuga baula en el Caribe sur.', 'travel-agency-platform'),
                'content'  => '',
                'interest' => [
                    __('Protege uno de los pocos bosques de cativo y un manglar de importancia regional.', 'travel-agency-platform'),
                    __('Es sitio de anidación de tortuga baula (Dermochelys coriacea) y tortuga carey.', 'travel-agency-platform'),
                    __('Sus arrecifes de coral albergan gran biodiversidad marina.', 'travel-agency-platform'),
                    __('El sendero costero conecta Manzanillo con la laguna de Gandoca.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, 8:00 a 16:00', 'travel-agency-platform'),
                'fee'      => __('Entrada: $5 extranjeros / ₡1 000 nacionales', 'travel-agency-platform'),
                'area'     => __('9 449 ha (4 436 terrestres / 5 013 marinas)', 'travel-agency-platform'),
                'created'  => '1985',
                'region'   => __('Caribe Sur', 'travel-agency-platform'),
                'ecosystem' => __('Manglar, arrecife de coral y playa de desove', 'travel-agency-platform'),
                'altitude' => __('0-200 msnm', 'travel-agency-platform'),
                'best_season' => __('Junio a noviembre (anidación de tortugas)', 'travel-agency-platform'),
                'biodiversity' => [
                    __('Tortuga baula y tortuga carey', 'travel-agency-platform'),
                    __('Bosque de cativo y mangle rojo', 'travel-agency-platform'),
                    __('Peces de arrecife y langostas', 'travel-agency-platform'),
                    __('Aves costeras y tortugas verdes', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata del sendero costero', 'travel-agency-platform'),
                    __('Kayak por la laguna de Gandoca', 'travel-agency-platform'),
                    __('Snorkel en el arrecife de Manzanillo', 'travel-agency-platform'),
                    __('Tour nocturno de tortugas con guía', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El tortuguero se observa con guía: no se puede alterar la anidación.', 'travel-agency-platform'),
                    __('Hay corrientes fuertes en la playa: nade con cuidado', 'travel-agency-platform'),
                    __('Use repelente en la zona de manglar', 'travel-agency-platform'),
                ],
                'conservation' => __('Protege una de las últimas poblaciones de tortuga baula del Caribe, junto con el manglar más extenso de la zona.', 'travel-agency-platform'),
            ],
        ];

        $spots = array_merge($spots, self::seed_extra_spots());
        return self::merge_content($spots);
    }

    /**
     * Destinations added to the map after the first release.
     *
     * They live in their own method so the original seed stays readable and
     * both sets can be reviewed independently. Several of them sit on the same
     * towns where the platform's services are published, so the map connects
     * the protected areas with the bookable experiences around them.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function seed_extra_spots() {
        return [
            'monteverde' => [
                'title'    => 'Reserva Biológica del Bosque Nuboso de Monteverde',
                'type'     => 'reserva-biologica',
                'lat' => '10.3009', 'lng' => '-84.8245',
                'province' => 'Puntarenas',
                'excerpt'  => __('Niebla constante, aves que viven en el dosel y uno de los hábitats más difíciles de conservar del planeta.', 'travel-agency-platform'),
                'interest' => [
                    __('La neblina cubre el bosque casi todo el año y mantiene la humedad que lo hace único.', 'travel-agency-platform'),
                    __('El quetzal anida en las copas: el macho tiene una cola de hasta un metro.', 'travel-agency-platform'),
                    __('Protege 1.600 hectáreas donde las epífitas cubren el 75 por ciento de los troncos.', 'travel-agency-platform'),
                    __('Se recorre con senderos de tablones y pasarelas entre la neblina.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Quetzal, la cotinga y el tucán del bosque nuboso', 'travel-agency-platform'),
                    __('Gavilanes, tucanes y más de 400 especies de aves', 'travel-agency-platform'),
                    __('Perezoso de dos dedos, armadillo y zarigueya', 'travel-agency-platform'),
                    __('Ranitas de colores y orquídeas epífitas', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Recorrido guiado por los senderos con pasarela', 'travel-agency-platform'),
                    __('Avistamiento del quetzal en los árboles', 'travel-agency-platform'),
                    __('Caminatas nocturnas para ver animales activos', 'travel-agency-platform'),
                    __('Visita al centro de visitantes del bosque', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Lleve ropa impermeable y calzado cómodo', 'travel-agency-platform'),
                    __('El sendero puede resbalar con la humedad', 'travel-agency-platform'),
                    __('Use repelente y linterna', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 7:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $10 extranjeros / 3.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('1.600 hectáreas', 'travel-agency-platform'),
                'created' => __('1983', 'travel-agency-platform'),
                'region' => __('Puntarenas', 'travel-agency-platform'),
                'ecosystem' => __('Bosque nuboso húmedo de montaña', 'travel-agency-platform'),
                'altitude' => __('1.530-1.800 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril para las aves migratorias', 'travel-agency-platform'),
                'conservation' => __('Su creación en 1983 evitó la tala de un bosque que hoy alimenta a los cafetales de la zona; sigue siendo el mayor esfuerzo de conservación privada de bosque nuboso del país.', 'travel-agency-platform'),
                'accessibility' => __('El sendero principal tiene tablones y es transitable con ayuda; la niebla y las escaleras limitan la movilidad autónoma.', 'travel-agency-platform'),
                'website' => __('https://www.reservabosquedemonteverde.org/', 'travel-agency-platform'),
                'content' => __('El bosque nuboso de Monteverde se mantuvo a salvo casi por accidente: cafetaleros sin un ingreso propio, junto con los conservacionistas, aceptaron dejar áreas sin talar a cambio de agua. Hoy la reserva protege 1.600 hectáreas de bosque con neblina permanente, donde el quetzal y las aves del dosel son la verdadera atracción. Las pasarelas de tablones permiten entrar sin pisar el suelo, algo poco habitual en un bosque tan húmedo.', 'travel-agency-platform'),
            ],
            'santa-rosa' => [
                'title'    => 'Parque Nacional Santa Rosa',
                'type'     => 'zona-protegida',
                'lat' => '10.3006', 'lng' => '-85.5639',
                'province' => 'Guanacaste',
                'excerpt'  => __('El primer parque nacional del país, con el mayor manglar del Pacífico.', 'travel-agency-platform'),
                'interest' => [
                    __('Abrió en 1955 y fue el primer parque nacional del país.', 'travel-agency-platform'),
                    __('Su manglar de 1.000 hectáreas es el mayor del Pacífico.', 'travel-agency-platform'),
                    __('En Matapalo se protege bosque seco, matorral y dunas costeras.', 'travel-agency-platform'),
                    __('La playa de Nacascolo es punto de llegada de las tortugas verdes.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Crocodilo americano en los canales del manglar', 'travel-agency-platform'),
                    __('Mono aullador en el bosque seco', 'travel-agency-platform'),
                    __('Venado cola blanca en el sector de Matapalo', 'travel-agency-platform'),
                    __('Tortuga verde en la playa de Nacascolo', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Paseo en bote por los canales del manglar', 'travel-agency-platform'),
                    __('Caminata desde el parque hasta la playa', 'travel-agency-platform'),
                    __('Avistamiento de tortugas verdes en la costa', 'travel-agency-platform'),
                    __('Observación de aves con guía especializado', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La navegación en el manglar es difícil: vaya con guía.', 'travel-agency-platform'),
                    __('En la playa no hay servicios, lleve agua y comida.', 'travel-agency-platform'),
                    __('Manténgase lejos del agua por los crocodilos.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 7:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $6 extranjeros / 1.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('13.500 hectáreas', 'travel-agency-platform'),
                'created' => __('1955', 'travel-agency-platform'),
                'region' => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical, manglar y dunas costeras', 'travel-agency-platform'),
                'altitude' => __('0-200 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril, y julio para ver tortugas', 'travel-agency-platform'),
                'conservation' => __('Forma parte del área de conservación Guanacaste y protege el bosque seco del Pacífico norte.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra y el manglar exige barca; no hay sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('El parque abrió en 1955 y fue la primera área protegida del país. Hoy protege bosque seco, el mayor manglar del Pacífico y la playa de Nacascolo. En julio llegan tortugas verdes a desovar en la costa.', 'travel-agency-platform'),
            ],
            'la-fortuna' => [
                'title'    => 'La Fortuna y sus Termas',
                'type'     => 'termas',
                'lat' => '10.4678', 'lng' => '-84.6428',
                'province' => 'Alajuela',
                'excerpt'  => __('Aguas termales a los pies del Arenal, café de altura y el volcán de fondo.', 'travel-agency-platform'),
                'interest' => [
                    __('Las termas reciben agua de 60 a 100 grados de fuentes volcánicas.', 'travel-agency-platform'),
                    __('El pueblo está en la base norte del Volcán Arenal, a 20 km del cono.', 'travel-agency-platform'),
                    __('La zona cafétera produce café de altura de gran calidad.', 'travel-agency-platform'),
                    __('Es punto de partida hacia el Arenal, el Río Celeste y Lomas Barbudal.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Tucanes, colibríes y tres especies de monos en los cerros', 'travel-agency-platform'),
                    __('Aves migratorias que descansan en la zona durante sus viajes', 'travel-agency-platform'),
                    __('Arañas, escarabajos y moluscos de humedad tropical', 'travel-agency-platform'),
                    __('Bosque ribereño con aves acuáticas en el Río San Carlos', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Baño en las termas naturales', 'travel-agency-platform'),
                    __('Visita una plantación de café con cata', 'travel-agency-platform'),
                    __('Paseos en bote por el Lago Arenal al atardecer', 'travel-agency-platform'),
                    __('Caminatas nocturnas con guía en los senderos del cono', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Lleve traje de baño y toalla: hay taquillas y duchas con agua caliente.', 'travel-agency-platform'),
                    __('El acceso a las termas es libre; se paga solo el uso de las instalaciones.', 'travel-agency-platform'),
                    __('Use repelente en el bosque: los senderos son cortos y hay garrapatas.', 'travel-agency-platform'),
                ],
                'schedule' => __('Termas abiertas todos los días, de 8:00 a 21:00', 'travel-agency-platform'),
                'fee' => __('Termas: desde $12 extranjeros / 4.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('Zona de termas de unas 3 hectáreas', 'travel-agency-platform'),
                'created' => __('1913', 'travel-agency-platform'),
                'region' => __('Zona Norte', 'travel-agency-platform'),
                'ecosystem' => __('Bosque premontano, campos de café y fuentes geotérmicas', 'travel-agency-platform'),
                'altitude' => __('340 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a agosto', 'travel-agency-platform'),
                'conservation' => __('Las fuentes termales nacen del mismo sistema volcánico que alimenta el Arenal; su uso se regula para evitar el sobreconsumo de agua.', 'travel-agency-platform'),
                'accessibility' => __('Las termas tienen plataformas y acceso por rampa; los senderos naturales no son accesibles en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('La Fortuna creció alrededor de las fuentes termales que afloran en la falda del Arenal y hoy es el punto de partida más usado de la Zona Norte. A veinte kilómetros del cono, el pueblo combina el volcán de fondo con restaurantes y la salida diaria de los tours hacia el parque nacional, el Río Celeste y las senderas de Lomas Barbudal.', 'travel-agency-platform'),
            ],
            'montezuma' => [
                'title'    => 'Montezuma y Santa Teresa',
                'type'     => 'pueblo',
                'lat' => '9.6500', 'lng' => '-85.0667',
                'province' => 'Puntarenas',
                'excerpt'  => __('La playa más conocida de la península de Nicoya: surf, yoga y atardeceres sobre el Pacífico.', 'travel-agency-platform'),
                'interest' => [
                    __('Montezuma fue un pueblo pesquero que atrajo la primera ola de surf en los años 70.', 'travel-agency-platform'),
                    __('Santa Teresa, a 7 km, es el foco de la vida nocturna y de las clases de yoga.', 'travel-agency-platform'),
                    __('La zona protege bosque seco tropical y varias bahías con anidación de tortuga.', 'travel-agency-platform'),
                    __('La bahía de Crucitas es el mejor punto para ver ballenas jorobadas en temporada.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Ballenas jorobadas y delfines en temporada', 'travel-agency-platform'),
                    __('Tortugas verdes que anidan en la arena de Malabrigo', 'travel-agency-platform'),
                    __('Aves playeras y garzas en la franja de manglar', 'travel-agency-platform'),
                    __('Bosque seco con quebranta y chapulines', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Clases de surf para todos los niveles', 'travel-agency-platform'),
                    __('Kayak y paddle board en la bahía de Crucitas', 'travel-agency-platform'),
                    __('Avistamiento de ballenas desde el agua', 'travel-agency-platform'),
                    __('Caminata al mirador de Montezuma al atardecer', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Las corrientes son fuertes: nade solo en la zona marcada y con chaleco.', 'travel-agency-platform'),
                    __('En temporada alta las playas se llenan: llegue temprano.', 'travel-agency-platform'),
                    __('El camino a Santa Teresa es de tierra: use un vehículo alto.', 'travel-agency-platform'),
                ],
                'schedule' => __('Pueblo con acceso libre; las actividades se reservan por la tarde', 'travel-agency-platform'),
                'fee' => __('Sin entrada; las clases de surf y las excursiones tienen precio aparte', 'travel-agency-platform'),
                'area' => __('Bahías de Montezuma, Santa Teresa y Malabrigo', 'travel-agency-platform'),
                'created' => __('1845', 'travel-agency-platform'),
                'region' => __('Península de Nicoya', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical y selva costera', 'travel-agency-platform'),
                'altitude' => __('0-120 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a agosto', 'travel-agency-platform'),
                'conservation' => __('La franja costera de Santa Teresa y Montezuma forma parte de la zona de protección del bosque seco y de las bahías de anidación de tortuga.', 'travel-agency-platform'),
                'accessibility' => __('El centro del pueblo es transitable a pie; las playas de arena suave no tienen acceso para sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('Montezuma cambio de pesquería a turismo cuando los surfistas llegaron en los años setenta, y hoy sigue siendo un pueblo pequeño de casas bajas con la playa como eje. A siete kilómetros, Santa Teresa concentra la escena de yoga, surf y vida nocturna, mientras que las playas del sur mantienen un ambiente más tranquilo. El atardecer sobre el Pacífico, los botes de pesca que salen de madrugada y las tortugas que anidan de noche en Malabrigo resumen el lugar.', 'travel-agency-platform'),
            ],
            'nuevo-arenal' => [
                'title'    => 'Nuevo Arenal y el Lago Arenal',
                'type'     => 'pueblo',
                'lat' => '10.2622', 'lng' => '-84.5833',
                'province' => 'Guanacaste',
                'excerpt'  => __('Pueblo a la orilla del lago más grande del país, con botes al atardecer.', 'travel-agency-platform'),
                'interest' => [
                    __('El Lago Arenal tiene 51 km² y es el lago más grande de Costa Rica.', 'travel-agency-platform'),
                    __('El pueblo viejo quedó bajo el agua en 1990, con el proyecto Arenal.', 'travel-agency-platform'),
                    __('El Observatorio Arenal queda a 5 km y observa el volcán.', 'travel-agency-platform'),
                    __('Desde el muelle salen los botes de atardecer por el lago.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Mono aullador en el bosque de ribera', 'travel-agency-platform'),
                    __('Perezoso de dos dedos en los árboles', 'travel-agency-platform'),
                    __('Crocodrilos en el río Chiquito', 'travel-agency-platform'),
                    __('Martin pescador en la orilla del lago', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Paseo en bote al atardecer por el lago', 'travel-agency-platform'),
                    __('Caminata hasta el Observatorio Arenal', 'travel-agency-platform'),
                    __('Observación de aves en la orilla del lago', 'travel-agency-platform'),
                    __('Kayak en el lago durante la mañana', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Lleve una chaqueta para la tarde en el lago.', 'travel-agency-platform'),
                    __('El camino al observatorio es angosto y con curvas.', 'travel-agency-platform'),
                    __('Nade solo en las áreas marcadas del lago.', 'travel-agency-platform'),
                ],
                'schedule' => __('Acceso libre; los paseos en bote salen por la tarde', 'travel-agency-platform'),
                'fee' => __('Sin entrada', 'travel-agency-platform'),
                'area' => __('Pueblo a la orilla del Lago Arenal, en el norte de Guanacaste', 'travel-agency-platform'),
                'created' => __('1990', 'travel-agency-platform'),
                'region' => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Lago de agua dulce y bosque de ribera', 'travel-agency-platform'),
                'altitude' => __('520 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y de julio a octubre', 'travel-agency-platform'),
                'conservation' => __('El lago y sus Áreas de ribera forman parte del área de conservación Guanacaste.', 'travel-agency-platform'),
                'accessibility' => __('El pueblo es transitable a pie y el muelle es plano; los senderos del lago no.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('Nuevo Arenal se levantó de nuevo en 1990, cuando el embalse tapó el pueblo viejo. Hoy vive del lago y ofrece los atardeceres más claros del norte del país. Los botes salen al atardecer y el Observatorio Arenal queda a cinco kilómetros.', 'travel-agency-platform'),
            ],
            'rio-celeste' => [
                'title'    => 'Río Celeste y los Tenideros',
                'type'     => 'termas',
                'lat' => '10.5708', 'lng' => '-84.9547',
                'province' => 'Alajuela',
                'excerpt'  => __('Agua turquesa de minerales termales y una cascada dentro del parque Tenorio.', 'travel-agency-platform'),
                'interest' => [
                    __('El río nace donde el Río Grande del volcán se une con el Río Pailas.', 'travel-agency-platform'),
                    __('Los minerales de las aguas termales dan el color turquesa.', 'travel-agency-platform'),
                    __('Los tenideros son las pozas donde el color del agua cambia.', 'travel-agency-platform'),
                    __('La cascada cae unos 8 metros sobre una piscina natural.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Mono aullador en el bosque de montaña', 'travel-agency-platform'),
                    __('Capuchin de cara blanca en los árboles', 'travel-agency-platform'),
                    __('Quetzal resplandeciente en el bosque alto', 'travel-agency-platform'),
                    __('Ocelote en la selva húmeda', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata de 3 km hasta la cascada y las pozas', 'travel-agency-platform'),
                    __('Baño en el río de agua fría', 'travel-agency-platform'),
                    __('Visita al centro de información', 'travel-agency-platform'),
                    __('Observación de aves en el sendero', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La corriente es fuerte: nade solo con chaleco y cuidado.', 'travel-agency-platform'),
                    __('Lleve toalla y ropa para cambiarse.', 'travel-agency-platform'),
                    __('Use calzado para las rocas resbalosas.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 14:00, con cupo por hora', 'travel-agency-platform'),
                'fee' => __('Entrada: $12 extranjeros / 800 colones nacionales', 'travel-agency-platform'),
                'area' => __('Sitio termal dentro del Parque Nacional Volcán Tenorio', 'travel-agency-platform'),
                'created' => __('1995', 'travel-agency-platform'),
                'region' => __('Zona Norte', 'travel-agency-platform'),
                'ecosystem' => __('Bosque húmedo de montaña y ríos geotermicos', 'travel-agency-platform'),
                'altitude' => __('1.400 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril para el cielo despejado', 'travel-agency-platform'),
                'conservation' => __('Está dentro del Parque Nacional Volcán Tenorio, que protege los manantiales geotermicos.', 'travel-agency-platform'),
                'accessibility' => __('El sendero de 3 km tiene tierra y escalones, no es apto para sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('El Río Celeste nace del encuentro entre el Río Grande y el Río Pailas. Los minerales termales dan el color turquesa a los tenideros. La caminata de 3 km lleva a la cascada y cruza un puente.', 'travel-agency-platform'),
            ],
            'lomas-barbudal' => [
                'title'    => 'Reserva Biológica Lomas Barbudal',
                'type'     => 'reserva-biologica',
                'lat' => '10.0833', 'lng' => '-85.3667',
                'province' => 'Guanacaste',
                'excerpt'  => __('Uno de los últimos bosques secos de Guanacaste y un gran refugio de aves.', 'travel-agency-platform'),
                'interest' => [
                    __('Se creó en 1991 junto con el área de conservación Guanacaste.', 'travel-agency-platform'),
                    __('Protege 2.797 hectáreas de bosque seco casi intacto.', 'travel-agency-platform'),
                    __('Es uno de los últimos bosques secos que quedan en Guanacaste.', 'travel-agency-platform'),
                    __('Forma parte de un corredor biológico entre el norte y el sur.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Más de 230 especies de aves registradas en la reserva', 'travel-agency-platform'),
                    __('Ara escarlata en el bosque seco', 'travel-agency-platform'),
                    __('Venado cola blanca en los claros', 'travel-agency-platform'),
                    __('Mapache, iguana verde y otros mamíferos', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata guiada de observación de aves', 'travel-agency-platform'),
                    __('Visita al centro de visitantes', 'travel-agency-platform'),
                    __('Recorrido por los senderos del bosque seco', 'travel-agency-platform'),
                    __('Picnic en el mirador', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El sendero es rugoso y polvoroso en temporada seca.', 'travel-agency-platform'),
                    __('Lleve agua, sombrero y protector solar.', 'travel-agency-platform'),
                    __('La lista de aves del sitio está en la entrada.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $10 extranjeros / 2.500 colones nacionales', 'travel-agency-platform'),
                'area' => __('2.797 hectáreas', 'travel-agency-platform'),
                'created' => __('1991', 'travel-agency-platform'),
                'region' => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical y galería de ribera', 'travel-agency-platform'),
                'altitude' => __('100-800 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio para las aves', 'travel-agency-platform'),
                'conservation' => __('Protege uno de los últimos bosques secos de Guanacaste dentro de un corredor biológico.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra y piedra, no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('Lomas Barbudal protege uno de los últimos bosques secos de Guanacaste. Sus senderos suben desde el suelo rojo hasta el mirador, con el bosque como protagonista. Su lista de aves roza las 230 especies, la razón de muchas visitas.', 'travel-agency-platform'),
            ],
            'cabo-blanco' => [
                'title'    => 'Reserva Natural Absoluta de Cabo Blanco',
                'type'     => 'monumento-natural',
                'lat' => '9.5333', 'lng' => '-85.1167',
                'province' => 'Puntarenas',
                'excerpt'  => __('La primera reserva absoluta del país: bosque seco, costa rocosa y una playa de tortugas.', 'travel-agency-platform'),
                'interest' => [
                    __('Declarada en 1963, fue la primera reserva natural absoluta del país.', 'travel-agency-platform'),
                    __('Cubre unas 7.800 hectáreas que incluyen el mar y el bosque seco.', 'travel-agency-platform'),
                    __('En 1963 los Beatles nadaron en la playa que hoy protege las tortugas.', 'travel-agency-platform'),
                    __('El proyecto Ostional administra la reserva de tortugas marinas del sitio.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Tortuga verde que anida en la playa de Cabo Blanco', 'travel-agency-platform'),
                    __('Buhón blanco y negro en el bosque seco', 'travel-agency-platform'),
                    __('Ara escarlata en parejas en el dosel', 'travel-agency-platform'),
                    __('Venado cola blanca en los claros del bosque', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Visita a la playa de anidación de tortugas', 'travel-agency-platform'),
                    __('Caminata por el sendero costero', 'travel-agency-platform'),
                    __('Observación de aves al amanecer', 'travel-agency-platform'),
                    __('Snorkel en el arrecife', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Los senderos son cortos pero rocosos: lleve buen calzado.', 'travel-agency-platform'),
                    __('No hay servicios en la playa, lleve agua y protector solar.', 'travel-agency-platform'),
                    __('Respete las zonas marcadas de anidación de las tortugas.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $6 extranjeros / 1.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('7.800 hectáreas, con el mar y el bosque', 'travel-agency-platform'),
                'created' => __('1963', 'travel-agency-platform'),
                'region' => __('Puntarenas', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical, costa rocosa y área marina', 'travel-agency-platform'),
                'altitude' => __('0-120 msnm', 'travel-agency-platform'),
                'best_season' => __('Junio a noviembre para las tortugas', 'travel-agency-platform'),
                'conservation' => __('Protege el bosque seco y la costa donde anidan tortugas verdes, con áreas delimitadas para su protección.', 'travel-agency-platform'),
                'accessibility' => __('Se llega por carretera y a pie; los senderos rocosos no admiten sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('Cabo Blanco fue declarada reserva absoluta en 1963, la primera del país. Hoy protege unas 7.800 hectáreas de bosque seco, costa rocosa y mar. La playa donde nadaron los Beatles recibe tortugas verdes cada temporada.', 'travel-agency-platform'),
            ],
            'cabo-papagayo' => [
                'title'    => 'Cabo Papagayo y Península de Nicoya',
                'type'     => 'playa-protegida',
                'lat' => '10.5372', 'lng' => '-85.7536',
                'province' => 'Guanacaste',
                'excerpt'  => __('El extremo más norte de la península de Nicoya, con acantilados y bosque seco.', 'travel-agency-platform'),
                'interest' => [
                    __('Es el punto más septentrional de la península de Nicoya.', 'travel-agency-platform'),
                    __('Rodean el cabo el bosque seco y los acantilados rocosos.', 'travel-agency-platform'),
                    __('Desde el mirador se ve todo el Golfo de Papagayo.', 'travel-agency-platform'),
                    __('Cerca están los hoteles y las playas de Playa Nacascolo.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Fragata y pelicano pardo sobre la costa', 'travel-agency-platform'),
                    __('Mono aullador en el bosque seco', 'travel-agency-platform'),
                    __('Venado cola blanca en los claros', 'travel-agency-platform'),
                    __('Aves playeras en la franja de arena', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata por el sendero costero hasta el acantilado', 'travel-agency-platform'),
                    __('Paseo en bote por el golfo', 'travel-agency-platform'),
                    __('Visita a las playas cercanas', 'travel-agency-platform'),
                    __('Snorkel en el arrecife', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El sol es fuerte y no hay sombra en el camino.', 'travel-agency-platform'),
                    __('Los caminos son rocosos, use calzado firme.', 'travel-agency-platform'),
                    __('El bosque seco es ralo: no espere sombra.', 'travel-agency-platform'),
                ],
                'schedule' => __('Acceso libre todo el día en las áreas publicas', 'travel-agency-platform'),
                'fee' => __('Sin entrada a las playas', 'travel-agency-platform'),
                'area' => __('Cabo, Playa Nacascolo y los acantilados del norte', 'travel-agency-platform'),
                'created' => __('2008', 'travel-agency-platform'),
                'region' => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical, manglar y costa rocosa', 'travel-agency-platform'),
                'altitude' => __('0-80 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y de julio a octubre', 'travel-agency-platform'),
                'conservation' => __('El cabo pertenece al Área de Conservación Tempisque, que protege el bosque seco y la anidación de tortugas.', 'travel-agency-platform'),
                'accessibility' => __('Se llega en vehículo por camino de tierra; el sendero al acantilado es de rocas.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('Cabo Papagayo marca el punto más septentrional de la península de Nicoya. El camino costero pasa entre bosque seco y acantilados hasta el mirador del golfo. Alrededor hay hoteles y playas, y al sur el refugio de tortugas de Ostional.', 'travel-agency-platform'),
            ],
            'cerro-de-la-muerte' => [
                'title'    => 'Cerro de la Muerte',
                'type'     => 'mirador',
                'lat' => '9.5700', 'lng' => '-83.8700',
                'province' => 'San José',
                'excerpt'  => __('El punto más alto de la sierra de Talamanca, con vista al valle de Savegre.', 'travel-agency-platform'),
                'interest' => [
                    __('Su cima llega a 3.642 msnm y se conoce también como Savegre.', 'travel-agency-platform'),
                    __('La carretera interamericana pasa a poca distancia del mirador.', 'travel-agency-platform'),
                    __('Desde el mirador se ve el valle de Savegre y el Pacífico.', 'travel-agency-platform'),
                    __('Alrededor hay plantaciones de pino, páramo y bosque frío de montaña.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Gallo de monte, ave endémica de la sierra', 'travel-agency-platform'),
                    __('Quetzal resplandeciente en el bosque nuboso cercano', 'travel-agency-platform'),
                    __('Mono aullador en las zonas más bajas', 'travel-agency-platform'),
                    __('Tapir de monte, el mamífero mayor del país', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata por el mirador de la carretera', 'travel-agency-platform'),
                    __('Visita a la Reserva Biológica Lomas Barbudal, muy cerca', 'travel-agency-platform'),
                    __('Recorrido por fincas de café y miel en el valle', 'travel-agency-platform'),
                    __('Fotografía del valle al amanecer', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Arriba hace frío y viento casi todo el año.', 'travel-agency-platform'),
                    __('En la mañana hay neblina y la vista se pierde.', 'travel-agency-platform'),
                    __('En temporada de lluvias use un vehículo 4x4.', 'travel-agency-platform'),
                ],
                'schedule' => __('Acceso todo el día; la carretera permanece abierta todo el año', 'travel-agency-platform'),
                'fee' => __('Sin entrada', 'travel-agency-platform'),
                'area' => __('Mirador y tramo de la ruta 32 en la sierra de Talamanca', 'travel-agency-platform'),
                'created' => __('1943', 'travel-agency-platform'),
                'region' => __('Talamanca', 'travel-agency-platform'),
                'ecosystem' => __('Páramo de alta altura, bosque de pino y bosque nuboso', 'travel-agency-platform'),
                'altitude' => __('1.500-3.642 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril, y julio para la carretera', 'travel-agency-platform'),
                'conservation' => __('Protege el pino, el páramo y el bosque nuboso de la sierra de Talamanca.', 'travel-agency-platform'),
                'accessibility' => __('Llega en vehículo por la ruta 32; el mirador no tiene rampa.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('A 3.642 msnm está la cima más alta de la sierra de Talamanca. La carretera interamericana que cruza el cerro se completó en 1943. Desde el mirador se ve el valle de Savegre y el mar en días claros.', 'travel-agency-platform'),
            ],
            'peninsula-nicoya' => [
                'title'    => 'Península de Nicoya y el Área de Conservación Guanacaste',
                'type'     => 'zona-protegida',
                'lat' => '9.7000', 'lng' => '-85.0000',
                'province' => 'Puntarenas',
                'excerpt'  => __('La península más grande del país: bosque seco al norte y selva húmeda al sur.', 'travel-agency-platform'),
                'interest' => [
                    __('La península mide unos 3.200 km² y es la más grande del país.', 'travel-agency-platform'),
                    __('El norte es bosque seco y el sur es selva húmeda.', 'travel-agency-platform'),
                    __('El Área de Conservación Guanacaste fue creada en 1991.', 'travel-agency-platform'),
                    __('Allí están Santa Rosa, Palo Verde y el río Tempisque.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Venado cola blanca en el bosque seco', 'travel-agency-platform'),
                    __('Mono aullador en la selva del sur', 'travel-agency-platform'),
                    __('Tortuga verde en las playas del Golfo de Nicoya', 'travel-agency-platform'),
                    __('Ara escarlata en los árboles del bosque seco', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Visita a las áreas protegidas de la península', 'travel-agency-platform'),
                    __('Paseo en bote por el río Tempisque', 'travel-agency-platform'),
                    __('Visita a los pueblos pesqueros del sur', 'travel-agency-platform'),
                    __('Recorrido por el cabo y sus miradores', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Las distancias entre los extremos de la península son largas.', 'travel-agency-platform'),
                    __('El sur es muy lluvioso en temporada.', 'travel-agency-platform'),
                    __('Planifique la ruta con un mapa antes de salir.', 'travel-agency-platform'),
                ],
                'schedule' => __('Acceso libre todo el día; cada reserva cobra su entrada', 'travel-agency-platform'),
                'fee' => __('Sin entrada general; cada reserva protegida tiene la suya', 'travel-agency-platform'),
                'area' => __('Península de 3.200 km² con áreas protegidas de todo tipo', 'travel-agency-platform'),
                'created' => __('1991', 'travel-agency-platform'),
                'region' => __('Pacífico Sur', 'travel-agency-platform'),
                'ecosystem' => __('Del bosque seco tropical a la selva húmeda', 'travel-agency-platform'),
                'altitude' => __('0-1.200 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril', 'travel-agency-platform'),
                'conservation' => __('El Área de Conservación Guanacaste protege un mosaico de bosque seco, humedales y costa.', 'travel-agency-platform'),
                'accessibility' => __('Se recorre en vehículo 4x4; los senderos de cada reserva son de tierra.', 'travel-agency-platform'),
                'website' => __('https://whc.UNESCO.org/en/list/928/', 'travel-agency-platform'),
                'content' => __('La península de Nicoya es la mayor del país, con 3.200 km². Al norte el bosque seco, al sur la selva húmeda y el Osa. El Área de Conservación Guanacaste, creada en 1991, protege el norte de la península.', 'travel-agency-platform'),
            ],
            'braulio-carrillo' => [
                'title'    => 'Parque Nacional Braulio Carrillo',
                'type'     => 'parque-nacional',
                'lat' => '10.4036', 'lng' => '-84.0169',
                'province' => 'Heredia',
                'excerpt'  => __('La mayor selva primaria de las montanas centrales, con un río que la atraviesa.', 'travel-agency-platform'),
                'interest' => [
                    __('Creado en 1978, protege la mayor selva primaria de las montanas centrales.', 'travel-agency-platform'),
                    __('Un solo sector del parque está abierto a los visitantes.', 'travel-agency-platform'),
                    __('De 42.000 hectáreas, solo una parte pequeña recibe visitas.', 'travel-agency-platform'),
                    __('El río Braulio Carrillo cruza el parque de lado a lado.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('La harpía, ave de rapaz mayor, sobrevuela el dosel', 'travel-agency-platform'),
                    __('Ocelote y oso de anteojos en el suelo del bosque', 'travel-agency-platform'),
                    __('Mono aullador y perezoso de dos dedos', 'travel-agency-platform'),
                    __('Tucanes y pavas en el bosque de ribera', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata guiada por el sendero del río Braulio Carrillo', 'travel-agency-platform'),
                    __('Observación de aves con guía especialista', 'travel-agency-platform'),
                    __('Visita al centro de interpretación del parque', 'travel-agency-platform'),
                    __('Baño en la cascada y la poza natural', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('Use manga larga: el bosque es denso y con mosquitos.', 'travel-agency-platform'),
                    __('El sendero es resbaloso cuando hay humedad.', 'travel-agency-platform'),
                    __('No entre al río: la corriente es fuerte.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $6 extranjeros / 1.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('42.000 hectáreas', 'travel-agency-platform'),
                'created' => __('1978', 'travel-agency-platform'),
                'region' => __('Huetar Norte', 'travel-agency-platform'),
                'ecosystem' => __('Bosque húmedo tropical y cerros karsticos', 'travel-agency-platform'),
                'altitude' => __('60-1.174 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril', 'travel-agency-platform'),
                'conservation' => __('Conserva la mayor mancha de selva primaria de las montanas centrales del país.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra, con pendientes y raíces; no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('El parque protege una de las últimas manchas grandes de selva primaria del país. El río Braulio Carrillo lo atraviesa y define el sector abierto a visitas. El bosque conserva especies raras como la harpía y el ocelote.', 'travel-agency-platform'),
            ],
            'tapantial' => [
                'title'    => 'Parque Nacional Tapantial',
                'type'     => 'parque-nacional',
                'lat' => '9.5636', 'lng' => '-83.8533',
                'province' => 'Cartago',
                'excerpt'  => __('Bosque nuboso de las montañas volcánicas centrales con una gran población de quetzal.', 'travel-agency-platform'),
                'interest' => [
                    __('Creado en 1982, protege el bosque más extenso de la sierra volcánica central.', 'travel-agency-platform'),
                    __('Su bosque de neblina alberga una de las mayores poblaciones de quetzal.', 'travel-agency-platform'),
                    __('El Mirador de la Gollita mira al valle y al dosel del bosque.', 'travel-agency-platform'),
                    __('El Sendero del Quetzal une el parque con el valle de Savegre.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Quetzal resplandeciente, la estrella del parque', 'travel-agency-platform'),
                    __('Momoto resplandeciente en el dosel del bosque', 'travel-agency-platform'),
                    __('Aves de seda y colibríes de la neblina', 'travel-agency-platform'),
                    __('Mono aullador y tapir de monte en el sotobosque', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Observación de quetzal con guía en el mirador', 'travel-agency-platform'),
                    __('Caminata por los senderos de la reserva', 'travel-agency-platform'),
                    __('Observación de aves en el Mirador de la Gollita', 'travel-agency-platform'),
                    __('Visita a una finca de café en los alrededores', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El sendero al mirador del quetzal es fangoso y resbaloso.', 'travel-agency-platform'),
                    __('Lleve agua y bocadillos: no hay servicios dentro.', 'travel-agency-platform'),
                    __('La puerta cierra a las 15:00, no después.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $5 extranjeros / 1.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('9.500 hectáreas', 'travel-agency-platform'),
                'created' => __('1982', 'travel-agency-platform'),
                'region' => __('Cartago', 'travel-agency-platform'),
                'ecosystem' => __('Bosque nuboso húmedo de la sierra volcánica central', 'travel-agency-platform'),
                'altitude' => __('1.600-2.600 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril', 'travel-agency-platform'),
                'conservation' => __('Sostiene al quetzal y protege el bosque nuboso más extenso de la sierra volcánica central.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra, con barro y pendientes; no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('Tapantial protege el bosque nuboso más extenso de la sierra volcánica central. El quetzal anida en el dosel y se observa con guía desde el mirador. A las 15:00 cierra la puerta y el valle queda en silencio.', 'travel-agency-platform'),
            ],
            'juan-santamaria' => [
                'title'    => 'Parque Nacional Juan Santamaría',
                'type'     => 'parque-nacional',
                'lat' => '10.1167', 'lng' => '-84.6333',
                'province' => 'Alajuela',
                'excerpt'  => __('El último gran tramo de bosque seco del Pacífico central, con el río Tárcoles.', 'travel-agency-platform'),
                'interest' => [
                    __('Creado en 1995, protege la mayor extensión de bosque seco del Pacífico central.', 'travel-agency-platform'),
                    __('El río Tárcoles marca el límite norte del área protegida.', 'travel-agency-platform'),
                    __('El mirador de la Piedra Bola domina la selva y el valle.', 'travel-agency-platform'),
                    __('Los manglares del estero son parte de la zona protegida.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Ara escarlata en parejas y bandadas del bosque seco', 'travel-agency-platform'),
                    __('Capuchin de cara blanca en los árboles del bosque', 'travel-agency-platform'),
                    __('Puma que recorre el parque de madrugada', 'travel-agency-platform'),
                    __('Crocodrilos del río Tárcoles en las barras de arena', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminata hasta el mirador de la Piedra Bola', 'travel-agency-platform'),
                    __('Observación de aves a lo largo del río Tárcoles', 'travel-agency-platform'),
                    __('Paseo en bote por el estero y los manglares', 'travel-agency-platform'),
                    __('Visita a una granja de crocodilos', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El último tramo del río solo se recorre en bote.', 'travel-agency-platform'),
                    __('El camino de entrada es de tierra y grava.', 'travel-agency-platform'),
                    __('Lleve repelente: en temporada hay muchos mosquitos.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todos los días, de 8:00 a 16:00', 'travel-agency-platform'),
                'fee' => __('Entrada: $6 extranjeros / 1.000 colones nacionales', 'travel-agency-platform'),
                'area' => __('11.700 hectáreas', 'travel-agency-platform'),
                'created' => __('1995', 'travel-agency-platform'),
                'region' => __('Alajuela', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical y manglares del Pacífico central', 'travel-agency-platform'),
                'altitude' => __('0-400 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril', 'travel-agency-platform'),
                'conservation' => __('Salva el último tramo de bosque seco del Pacífico central y protege los manglares.', 'travel-agency-platform'),
                'accessibility' => __('El mirador se alcanza por sendero de tierra; el estero solo se visita en bote.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
                'content' => __('El parque protege el bosque seco que quedó entre el río Tárcoles y el Pacífico. El mirador de la Piedra Bola ofrece la mejor vista del sector. El río y los manglares se recorren en bote desde la entrada principal.', 'travel-agency-platform'),
            ],
            'samara' => [
                'title'    => 'Samara',
                'type'     => 'playa-protegida',
                'lat' => '9.8819', 'lng' => '-85.5286',
                'province' => 'Guanacaste',
                'excerpt'  => __('Pueblo de surf con una playa protegida por el proyecto de tortugas Ostional.', 'travel-agency-platform'),
                'interest' => [
                    __('Pueblo de surf en la península de Nicoya, al norte de Guanacaste.', 'travel-agency-platform'),
                    __('Su playa de unos 1,4 km va del río Samara hasta el mar.', 'travel-agency-platform'),
                    __('El estuario entre el Womble y el río atrae cientos de aves.', 'travel-agency-platform'),
                    __('Cerca están Isla Chora y la finca Rancho Quemado, con tortugas.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Tortuga verde anidando en la arena de la playa', 'travel-agency-platform'),
                    __('Mono aullador en el bosque seco cercano', 'travel-agency-platform'),
                    __('Venado cola blanca en los claros del bosque', 'travel-agency-platform'),
                    __('Ara en los árboles del bosque seco', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Caminar por la playa durante la marea baja', 'travel-agency-platform'),
                    __('Observación de aves en el estuario del río', 'travel-agency-platform'),
                    __('Recorrido de anidación de tortugas en Rancho Quemado', 'travel-agency-platform'),
                    __('Clase de surf para principiantes', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('La boca del río no es segura para nadar.', 'travel-agency-platform'),
                    __('La playa tiene corrientes fuertes y resacas.', 'travel-agency-platform'),
                    __('No deje sus pertenencias sobre la arena.', 'travel-agency-platform'),
                ],
                'schedule' => __('Abierto todo el día', 'travel-agency-platform'),
                'fee' => __('Sin entrada para la playa; el recorrido de tortugas tiene su propio precio', 'travel-agency-platform'),
                'area' => __('Sector Ostional con unas 3.000 ha de playa y 2.700 ha marinas', 'travel-agency-platform'),
                'created' => __('1990', 'travel-agency-platform'),
                'region' => __('Guanacaste', 'travel-agency-platform'),
                'ecosystem' => __('Bosque seco tropical, estuario de río y playa arenosa', 'travel-agency-platform'),
                'altitude' => __('0-30 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a octubre', 'travel-agency-platform'),
                'conservation' => __('La playa protege los sitios de anidación de tortugas verdes dentro del proyecto Ostional.', 'travel-agency-platform'),
                'accessibility' => __('Se llega en bus o auto por carretera; la arena firme permite acceso a pie.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('Samara es un pueblo de surf en la península de Nicoya con playa de 1,4 km. La entrada del río Samara forma un estuario con gran variedad de aves. La playa forma parte del proyecto de tortugas Ostional.', 'travel-agency-platform'),
            ],
            'puerto-jimenez' => [
                'title'    => 'Puerto Jimenez y la Península de Osa',
                'type'     => 'pueblo',
                'lat' => '8.5390', 'lng' => '-83.3070',
                'province' => 'Puntarenas',
                'excerpt'  => __('Puerto de entrada a la península de Osa y al parque nacional Corcovado.', 'travel-agency-platform'),
                'interest' => [
                    __('Principal puerto de acceso a la península de Osa y al parque Corcovado.', 'travel-agency-platform'),
                    __('El pueblo se desarrollo con la creación del parque en 1963.', 'travel-agency-platform'),
                    __('La entrada principal del parque está a unos 10 km del centro.', 'travel-agency-platform'),
                    __('Hoy es la base de una gran cantidad de lodges de aventura.', 'travel-agency-platform'),
                ],
                'biodiversity' => [
                    __('Ara escarlata en el dosel de la selva', 'travel-agency-platform'),
                    __('Mono aullador en el bosque húmedo', 'travel-agency-platform'),
                    __('Tapir en los senderos del parque', 'travel-agency-platform'),
                    __('Martin pescador a orillas del río Agujas', 'travel-agency-platform'),
                ],
                'activities' => [
                    __('Visita al parque nacional Corcovado', 'travel-agency-platform'),
                    __('Paseo en bote por la Bahía de Drake', 'travel-agency-platform'),
                    __('Caminata sobre el manglar en Playa Juan Castillo', 'travel-agency-platform'),
                    __('Visita a los lodges de la zona', 'travel-agency-platform'),
                ],
                'tips' => [
                    __('El camino al parque es de tierra y queda en mal estado.', 'travel-agency-platform'),
                    __('Reserve el permiso del parque y el guía con antelación.', 'travel-agency-platform'),
                    __('Lleve efectivo porque hay pocos cajeros.', 'travel-agency-platform'),
                ],
                'schedule' => __('Todo el día; los cupos del parque se reservan con antelación', 'travel-agency-platform'),
                'fee' => __('Sin entrada al pueblo; Corcovado exige permiso, guía y pago', 'travel-agency-platform'),
                'area' => __('Pueblo costero con lodges, al norte de la península de Osa', 'travel-agency-platform'),
                'created' => __('1963', 'travel-agency-platform'),
                'region' => __('Península de Osa', 'travel-agency-platform'),
                'ecosystem' => __('Selva tropical húmeda y manglar costero', 'travel-agency-platform'),
                'altitude' => __('0-50 msnm', 'travel-agency-platform'),
                'best_season' => __('Diciembre a abril y julio a octubre', 'travel-agency-platform'),
                'conservation' => __('Corcovado protege el bosque tropical húmedo más extenso del país y la vida marina de Osa.', 'travel-agency-platform'),
                'accessibility' => __('El pueblo tiene calle principal pavimentada; el camino al parque es de tierra y exige 4x4.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
                'content' => __('Puerto Jimenez es la principal puerta de entrada a la península de Osa. El parque nacional Corcovado está a unos 10 km del pueblo. Al sur comienza la Bahía de Drake y su reserva marina.', 'travel-agency-platform'),
            ],
        ];
    }

    /**
     * Editorial body, accessibility note and official website per destination.
     *
     * Kept apart from the main seed so the factual tables stay readable and
     * the three fields can be reviewed (and translated) on their own.
     *
     * @return array<string,array<string,string>>
     */
    public static function seed_content() {
        return [
            'monteverde' => [
                'content' => __('El bosque nuboso de Monteverde se mantuvo a salvo casi por accidente: cafetaleros sin un ingreso propio, junto con los conservacionistas, aceptaron dejar áreas sin talar a cambio de agua. Hoy la reserva protege 1.600 hectáreas de bosque con neblina permanente, donde el quetzal y las aves del dosel son la verdadera atracción. Las pasarelas de tablones permiten entrar sin pisar el suelo, algo poco habitual en un bosque tan húmedo.', 'travel-agency-platform'),
                'accessibility' => __('El sendero principal tiene tablones y es transitable con ayuda; la niebla y las escaleras limitan la movilidad autónoma.', 'travel-agency-platform'),
                'website' => __('https://www.reservabosquedemonteverde.org/', 'travel-agency-platform'),
            ],
            'santa-rosa' => [
                'content' => __('El parque abrió en 1955 y fue la primera área protegida del país. Hoy protege bosque seco, el mayor manglar del Pacífico y la playa de Nacascolo. En julio llegan tortugas verdes a desovar en la costa.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra y el manglar exige barca; no hay sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'la-fortuna' => [
                'content' => __('La Fortuna creció alrededor de las fuentes termales que afloran en la falda del Arenal y hoy es el punto de partida más usado de la Zona Norte. A veinte kilómetros del cono, el pueblo combina el volcán de fondo con restaurantes y la salida diaria de los tours hacia el parque nacional, el Río Celeste y las senderas de Lomas Barbudal.', 'travel-agency-platform'),
                'accessibility' => __('Las termas tienen plataformas y acceso por rampa; los senderos naturales no son accesibles en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'montezuma' => [
                'content' => __('Montezuma cambio de pesquería a turismo cuando los surfistas llegaron en los años setenta, y hoy sigue siendo un pueblo pequeño de casas bajas con la playa como eje. A siete kilómetros, Santa Teresa concentra la escena de yoga, surf y vida nocturna, mientras que las playas del sur mantienen un ambiente más tranquilo. El atardecer sobre el Pacífico, los botes de pesca que salen de madrugada y las tortugas que anidan de noche en Malabrigo resumen el lugar.', 'travel-agency-platform'),
                'accessibility' => __('El centro del pueblo es transitable a pie; las playas de arena suave no tienen acceso para sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'nuevo-arenal' => [
                'content' => __('Nuevo Arenal se levantó de nuevo en 1990, cuando el embalse tapó el pueblo viejo. Hoy vive del lago y ofrece los atardeceres más claros del norte del país. Los botes salen al atardecer y el Observatorio Arenal queda a cinco kilómetros.', 'travel-agency-platform'),
                'accessibility' => __('El pueblo es transitable a pie y el muelle es plano; los senderos del lago no.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'rio-celeste' => [
                'content' => __('El Río Celeste nace del encuentro entre el Río Grande y el Río Pailas. Los minerales termales dan el color turquesa a los tenideros. La caminata de 3 km lleva a la cascada y cruza un puente.', 'travel-agency-platform'),
                'accessibility' => __('El sendero de 3 km tiene tierra y escalones, no es apto para sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'lomas-barbudal' => [
                'content' => __('Lomas Barbudal protege uno de los últimos bosques secos de Guanacaste. Sus senderos suben desde el suelo rojo hasta el mirador, con el bosque como protagonista. Su lista de aves roza las 230 especies, la razón de muchas visitas.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra y piedra, no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'cabo-blanco' => [
                'content' => __('Cabo Blanco fue declarada reserva absoluta en 1963, la primera del país. Hoy protege unas 7.800 hectáreas de bosque seco, costa rocosa y mar. La playa donde nadaron los Beatles recibe tortugas verdes cada temporada.', 'travel-agency-platform'),
                'accessibility' => __('Se llega por carretera y a pie; los senderos rocosos no admiten sillas de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'cabo-papagayo' => [
                'content' => __('Cabo Papagayo marca el punto más septentrional de la península de Nicoya. El camino costero pasa entre bosque seco y acantilados hasta el mirador del golfo. Alrededor hay hoteles y playas, y al sur el refugio de tortugas de Ostional.', 'travel-agency-platform'),
                'accessibility' => __('Se llega en vehículo por camino de tierra; el sendero al acantilado es de rocas.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'cerro-de-la-muerte' => [
                'content' => __('A 3.642 msnm está la cima más alta de la sierra de Talamanca. La carretera interamericana que cruza el cerro se completó en 1943. Desde el mirador se ve el valle de Savegre y el mar en días claros.', 'travel-agency-platform'),
                'accessibility' => __('Llega en vehículo por la ruta 32; el mirador no tiene rampa.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'peninsula-nicoya' => [
                'content' => __('La península de Nicoya es la mayor del país, con 3.200 km². Al norte el bosque seco, al sur la selva húmeda y el Osa. El Área de Conservación Guanacaste, creada en 1991, protege el norte de la península.', 'travel-agency-platform'),
                'accessibility' => __('Se recorre en vehículo 4x4; los senderos de cada reserva son de tierra.', 'travel-agency-platform'),
                'website' => __('https://whc.UNESCO.org/en/list/928/', 'travel-agency-platform'),
            ],
            'braulio-carrillo' => [
                'content' => __('El parque protege una de las últimas manchas grandes de selva primaria del país. El río Braulio Carrillo lo atraviesa y define el sector abierto a visitas. El bosque conserva especies raras como la harpía y el ocelote.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra, con pendientes y raíces; no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'tapantial' => [
                'content' => __('Tapantial protege el bosque nuboso más extenso de la sierra volcánica central. El quetzal anida en el dosel y se observa con guía desde el mirador. A las 15:00 cierra la puerta y el valle queda en silencio.', 'travel-agency-platform'),
                'accessibility' => __('Los senderos son de tierra, con barro y pendientes; no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'juan-santamaria' => [
                'content' => __('El parque protege el bosque seco que quedó entre el río Tárcoles y el Pacífico. El mirador de la Piedra Bola ofrece la mejor vista del sector. El río y los manglares se recorren en bote desde la entrada principal.', 'travel-agency-platform'),
                'accessibility' => __('El mirador se alcanza por sendero de tierra; el estero solo se visita en bote.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'samara' => [
                'content' => __('Samara es un pueblo de surf en la península de Nicoya con playa de 1,4 km. La entrada del río Samara forma un estuario con gran variedad de aves. La playa forma parte del proyecto de tortugas Ostional.', 'travel-agency-platform'),
                'accessibility' => __('Se llega en bus o auto por carretera; la arena firme permite acceso a pie.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'puerto-jimenez' => [
                'content' => __('Puerto Jimenez es la principal puerta de entrada a la península de Osa. El parque nacional Corcovado está a unos 10 km del pueblo. Al sur comienza la Bahía de Drake y su reserva marina.', 'travel-agency-platform'),
                'accessibility' => __('El pueblo tiene calle principal pavimentada; el camino al parque es de tierra y exige 4x4.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
            'volcan-arenal' => [
                'content' => __('El Arenal es un estratovolcan de 1.670 msnm en el norte de Costa Rica. Erupciono entre 1968 y 2010, con flujos de lava que brillaban de noche. La colada de 1968 es hoy un sendero. El Observatorio Arenal está en la carretera de subida.', 'travel-agency-platform'),
                'accessibility' => __('Se llega en vehículo hasta el mirador. El cráter se visita en vehículo abierto. El clima es caluroso en la base.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'tortuguero' => [
                'content' => __('Tortuguero es un parque de selva húmeda en la costa del Caribe. Solo se llega en bote o en avión. Sus 31.187 hectáreas están cruzadas por canales y el pueblo no tiene carreteras. Guarda la mayor playa de desove de tortuga verde del hemisferio, de julio a octubre.', 'travel-agency-platform'),
                'accessibility' => __('No hay acceso por tierra: todo el recorrido es en bote y solo con guía. El pueblo es plano pero la humedad es alta.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'manuel-antonio' => [
                'content' => __('Manuel Antonio es el parque más visitado de Costa Rica. Es uno de los más pequenos, con 1.983 hectáreas. Sus tres playas son Espadilla Sur, Manuel Antonio y Puerto Escondido. Los monos carablanca y titi se ven en los senderos y el parque cierra los lunes.', 'travel-agency-platform'),
                'accessibility' => __('Las playas son de arena y hay escalones en los accesos. El parque es transitable a pie, pero con mucha gente.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'corcovado' => [
                'content' => __('Corcovado protege el mayor bosque primario que queda en el Pacífico de Mesoamerica. Cubre 42.469 hectáreas. Reune las cuatro especies de monos del país, junto con tapir, jaguar y puma. El ingreso es obligatorio con guía. Se hace por las estaciones de Los Patos y Sirena.', 'travel-agency-platform'),
                'accessibility' => __('El acceso es en bote o a pie por senderos de tierra. El clima es caluroso y húmedo todo el año.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'rincon-de-la-vieja' => [
                'content' => __('Rincón de la Vieja tiene nueve conos volcanicos. El Von Seebach es el más alto, con 1.895 msnm. Fumarolas, ollas de barro hirviendo y la catarata La Cangreja llenan el sector norte. Las termas del Río Clarillo son parte del Área de Conservación Guanacaste. Es Patrimonio de la Humanidad de la UNESCO.', 'travel-agency-platform'),
                'accessibility' => __('El sendero a la catarata es de tierra y se llega en vehículo. Las termas tienen plataformas de descanso.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'poas' => [
                'content' => __('Poás tiene uno de los crateres volcanicos más anchos del mundo. Su diametro es de 1,3 km. La laguna del cráter cambia de color. La cima está a un corto sendero desde el estacionamiento. Los gases son toxicos, por eso hay barreras, y la visita exige reserva por turno.', 'travel-agency-platform'),
                'accessibility' => __('El mirador se alcanza sin esfuerzo. Hace frío y hay que subir de vuelta en vehículo por la altura.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'irazu' => [
                'content' => __('Irazú es el volcán más alto de Costa Rica, con 3.432 msnm. En días despejados se ven los dos oceanos. La laguna del cráter cambia de color con los minerales. La carretera llega cerca del cráter. La cima es fría y ventosa todo el año.', 'travel-agency-platform'),
                'accessibility' => __('La carretera llega casi al cráter sin caminata. El frío y el viento hacen que conviene bajar pronto.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'chirripo' => [
                'content' => __('Chirripó es el punto más alto de Costa Rica, con 3.820 msnm. Sus lagos glaciares Ditkevi y Chadolin son la principal atracción. El amanecer sobre el Pacífico completa el paisaje. El páramo está cubierto de frailejones y el refugio se debe reservar con anticipacion.', 'travel-agency-platform'),
                'accessibility' => __('La subida toma dos días y el clima cambia muy rápido. Hace falta refugiarse y llevar ropa de frío.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'cahuita' => [
                'content' => __('Cahuita protege un arrecife de coral, una playa y un bosque de manglar. El terremoto de 1991 levantó la costa y formo la playa actual. El arrecife está bien conservado para el snorkel y un sendero de 8 km une la playa y el bosque.', 'travel-agency-platform'),
                'accessibility' => __('El sendero es de tierra y de tramo corto; el mar tiene corrientes y no hay servicios dentro del parque.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'marino-ballena' => [
                'content' => __('Marino Ballena protege 5.375 hectáreas, casi todas marinas, alrededor de la península de Osa. Su tormbolo de arena parece una cola de ballena en marea baja. Ballenas jorobadas de los dos hemisferios migran aqui y Uvita organiza el festival de la ballena en temporada.', 'travel-agency-platform'),
                'accessibility' => __('El avistamiento se hace en lancha y el tormbolo se recorre a pie; no hay acceso en silla de ruedas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'guayabo' => [
                'content' => __('Guayabo fue la ciudad precolombina más importante de Costa Rica, habitada entre el 1000 a.C. y el 1400 d.C. Sus acueductos y tanques de piedra siguen funcionando y las calzadas empedradas conectan los monticulos. Fue declarado monumento nacional en 1973 y patrimonio de la ingenieria en 2009.', 'travel-agency-platform'),
                'accessibility' => __('El sendero principal es de tierra y es accesible en silla de ruedas; el museo está a corta distancia.', 'travel-agency-platform'),
                'website' => __('https://www.museosycostarica.go.cr/', 'travel-agency-platform'),
            ],
            'tenorio' => [
                'content' => __('Tenorio tiene cuatro volcánes apagados y bosque nuboso con tapires y quetzales. El Río Celeste se vuelve turquesa donde se mezclan dos ríos, dentro del Área de Conservación Guanacaste. Un sendero de 3 km lleva a la laguna azul, los tenideros y la catarata.', 'travel-agency-platform'),
                'accessibility' => __('El sendero es de tierra y empedrado con pendientes; hay que caminar entre tres y cinco horas.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'palo-verde' => [
                'content' => __('Palo Verde protege el humedal más importante de Costa Rica. Miles de aves acuáticas migratorias, entre ellas los jabiru, pasan el invierno en sus lagunas. Hay cocodrilos en las lagunas y el parque conserva uno de los últimos bosques tropicales secos del país.', 'travel-agency-platform'),
                'accessibility' => __('El recorrido principal es en lancha por las lagunas; el centro de visitantes es accesible a pie.', 'travel-agency-platform'),
                'website' => __('https://www.acguanacaste.ac.cr/', 'travel-agency-platform'),
            ],
            'isla-del-coco' => [
                'content' => __('Isla del Coco está a 550 km de la costa y es Patrimonio de la Humanidad de la UNESCO desde 1997. Concentra más de 400 especies endémicas de insectos y plantas, ademas de cardumenes estacionales de tiburones martillo. El permiso de acceso lo emite el parque.', 'travel-agency-platform'),
                'accessibility' => __('Solo se llega en barco, con 36 horas de viaje; no hay ninguna facilidad en la isla.', 'travel-agency-platform'),
                'website' => __('https://www.sinervariascostarica.org/', 'travel-agency-platform'),
            ],
            'gandoca-manzanillo' => [
                'content' => __('Gandoca-Manzanillo protege manglares, un arrecife de coral y una playa de arena de desove. La playa es sitio de anidación de tortuga baula, una de las últimas poblaciones del Caribe. Un sendero costero une Manzanillo con la laguna de Gandoca entre bosque de cativo y mangle rojo.', 'travel-agency-platform'),
                'accessibility' => __('El sendero costero es de tierra y de clima caluroso; la playa es de arena blanda y no tiene servicios.', 'travel-agency-platform'),
                'website' => __('https://www.visitcostarica.com/', 'travel-agency-platform'),
            ],
        ];
    }

    /**
     * Merge the editorial content into the seed entries.
     *
     * @param array $spots Seed entries.
     * @return array
     */
    private static function merge_content(array $spots) {
        foreach (self::seed_content() as $slug => $content) {
            if (isset($spots[$slug]) && is_array($content)) {
                $spots[$slug] = array_merge($spots[$slug], $content);
            }
        }
        return $spots;
    }
}
