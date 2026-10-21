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
    const PAGE_OPTION = 'tap_spot_page_id';

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
    public static function seed_types() {
        if (!taxonomy_exists(self::TAX)) {
            return;
        }
        foreach (self::default_types() as $slug => $name) {
            if (!term_exists($slug, self::TAX)) {
                wp_insert_term($name, self::TAX, ['slug' => $slug]);
            }
        }
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
            ]];
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
        $types = !empty($args['types']) ? (array) $args['types'] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
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
                $sums[$term->slug]['n']++;
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

        // Re-validate: a stale option (page deleted) must fall through and heal.
        if ($page_id && 'page' !== get_post_status($page_id) && get_post($page_id)) {
            $page_id = 0;
        }
        if ($page_id && get_post($page_id) && 'trash' === get_post_status($page_id)) {
            $page_id = 0;
        }

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

        self::link_menu_item($page_id);
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
        if (!post_type_exists(self::CPT) || get_option(self::ENRICH_OPTION)) {
            return;
        }

        $list_keys = ['biodiversity', 'activities', 'tips'];
        $text_keys = ['region', 'ecosystem', 'altitude', 'best_season', 'conservation'];

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
        }

        update_option(self::ENRICH_OPTION, 1);
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
        return [
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
    }
}
