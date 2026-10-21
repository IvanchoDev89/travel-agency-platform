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
            '_tap_spot_interest'   => ['label' => __('Datos de interés', 'travel-agency-platform'), 'type' => 'textarea', 'desc' => __('Uno por línea. Se muestran como viñetas.', 'travel-agency-platform')],
            '_tap_spot_schedule'   => ['label' => __('Horario', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_fee'        => ['label' => __('Tarifa', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_area'       => ['label' => __('Extensión', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_created'    => ['label' => __('Año de creación', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_accessibility' => ['label' => __('Accesibilidad', 'travel-agency-platform'), 'type' => 'text'],
            '_tap_spot_website'    => ['label' => __('Sitio oficial', 'travel-agency-platform'), 'type' => 'url'],
        ];
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
                'interest' => self::interest_list($post->ID),
                'schedule' => (string) get_post_meta($post->ID, '_tap_spot_schedule', true),
                'fee'      => (string) get_post_meta($post->ID, '_tap_spot_fee', true),
                'area'     => (string) get_post_meta($post->ID, '_tap_spot_area', true),
                'created'  => (string) get_post_meta($post->ID, '_tap_spot_created', true),
                'accessibility' => (string) get_post_meta($post->ID, '_tap_spot_accessibility', true),
            ];
        }
        return $data;
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

    /** "Datos de interés" textarea split into trimmed, non-empty lines. */
    public static function interest_list($post_id) {
        $raw   = (string) get_post_meta($post_id, '_tap_spot_interest', true);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        return array_values(array_filter(array_map('trim', $lines), function ($l) {
            return $l !== '';
        }));
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
        update_post_meta($post_id, '_tap_spot_lat', $spot['lat']);
        update_post_meta($post_id, '_tap_spot_lng', $spot['lng']);
        update_post_meta($post_id, '_tap_spot_province', $spot['province']);
        update_post_meta($post_id, '_tap_spot_interest', implode("\n", $spot['interest']));
        foreach (['schedule', 'fee', 'area', 'created', 'accessibility', 'website'] as $k) {
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
            ],
        ];
    }
}
