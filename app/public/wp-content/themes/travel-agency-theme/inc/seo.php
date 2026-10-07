<?php
/**
 * Theme-side SEO: meta tags + JSON-LD for the home page and blog
 * (the plugin's TAP_SEO covers services, agencies and their archives).
 *
 * Single source of truth for the landing FAQ so the visible section and
 * the FAQPage rich result can never drift apart.
 */
defined('ABSPATH') || exit;

/** Educational FAQ shown on the landing page (and mirrored as FAQPage schema). */
function tap_landing_faq_items() {
    return [
        [
            'q' => __('¿Cuál es la mejor época para visitar Costa Rica?', 'travel-agency-platform'),
            'a' => __('La temporada seca va de diciembre a abril: ideal para playas del Pacífico y actividades al aire libre. De mayo a noviembre el paisaje está verde y hay menos gente, con lluvias cortadas por la tarde. El Caribe tiene su propio ritmo: de septiembre a octubre suele ser su mejor momento.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Necesito visa para entrar a Costa Rica?', 'travel-agency-platform'),
            'a' => __('Los ciudadanos de la mayoría de países de América y Europa no necesitan visa para estancias turísticas de hasta 90 días. Sí se requiere pasaporte con vigencia mínima, comprobante de salida del país y, según el país de origen, fondos suficientes o un itinerario claro.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Qué moneda se usa y cuánto presupuesto necesito por día?', 'travel-agency-platform'),
            'a' => __('Se usa el colón costarricense y el dólar estadounidense se acepta ampliamente. Como referencia, un viajero austero puede moverse desde USD 40–60 por día, un viaje cómodo ronda los USD 100–150 y la gama alta supera los USD 200, todo reservando con anticipación.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Cuántos días necesito para conocer el país?', 'travel-agency-platform'),
            'a' => __('Con 7 días alcanza para una ruta clásica (San José, Arenal y Pacífico). Con 10 a 14 días podés sumar Monteverde, el Caribe o la Península de Osa sin apuros. Costa Rica es pequeña, pero las distancias en auto se alargan por las montañas: menos destinos, más disfrute.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Es seguro viajar por Costa Rica?', 'travel-agency-platform'),
            'a' => __('Costa Rica es uno de los destinos más estables y seguros de la región, con turismo maduro desde hace décadas. Aplican las precauciones de siempre: no dejar cosas a la vista en el auto, caminar con sentido común de noche y contratar operadores verificados para actividades de riesgo.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Cómo se recorre el país: auto, bus o tour?', 'travel-agency-platform'),
            'a' => __('Los buses públicos llegan a casi todos los pueblos y son muy baratos. El auto da libertad para costas y zonas remotas, aunque exige manejo defensivo en carreteras de montaña. Para parques nacionales con horarios, traslados largos o navegación, un tour guiado ahorra tiempo y logística.', 'travel-agency-platform'),
        ],
        [
            'q' => __('¿Costa Rica es cara para los turistas?', 'travel-agency-platform'),
            'a' => __('Depende del estilo: en zonas turísticas consolidadas (Guacalillo, Tamarindo, La Fortuna) los precios se acercan a los del sur de Europa. Viajando en temporada media, comiendo en sodas locales y alojándote en cabañas o hostales, el costo baja notablemente sin renunciar a la experiencia.', 'travel-agency-platform'),
        ],
    ];
}

/** Reading time estimate in minutes (min 1). */
function tap_reading_time($post_id = 0) {
    $post_id = $post_id ?: get_the_ID();
    $words   = str_word_count(wp_strip_all_tags((string) get_post_field('post_content', $post_id)));
    return max(1, (int) ceil($words / 200));
}

/** Canonical + meta description (only when the plugin SEO has not covered it). */
function tap_theme_meta($description, $canonical = '', $og_type = 'website') {
    if ($canonical) {
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    }
    if ($description) {
        $description = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($description)));
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 155);
        } else {
            $description = substr($description, 0, 155);
        }
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    }
    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    if ($canonical) {
        echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
    }
}

/** Organization + WebSite graph for the home page (with SearchAction). */
function tap_theme_home_json_ld() {
    $logo = '';
    if (has_custom_logo()) {
        $custom = wp_get_attachment_image_src((int) get_theme_mod('custom_logo'), 'full');
        if ($custom) {
            $logo = $custom[0];
        }
    }
    if (!$logo && get_site_icon_url()) {
        $logo = get_site_icon_url(512);
    }

    $search_target = get_post_type_archive_link('tap_accommodation');
    if ($search_target) {
        $search_target = add_query_arg('keyword', '{search_term_string}', $search_target);
    }

    $org = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        '@id'      => home_url('/#organization'),
        'name'     => get_bloginfo('name'),
        'url'      => home_url('/'),
    ];
    if ($logo) {
        $org['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
    }

    $site = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        '@id'      => home_url('/#website'),
        'name'     => get_bloginfo('name'),
        'url'      => home_url('/'),
        'inLanguage' => 'es',
        'publisher'  => ['@id' => home_url('/#organization')],
    ];
    if ($search_target) {
        $site['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $search_target],
            'query-input' => 'required name=search_term_string',
        ];
    }

    echo '<script type="application/ld+json">' . wp_json_encode($org) . '</script>' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode($site) . '</script>' . "\n";
}

/** FAQPage schema mirroring tap_landing_faq_items(). */
function tap_theme_faq_json_ld() {
    $items = tap_landing_faq_items();
    if (!$items) {
        return;
    }
    $main = [];
    foreach ($items as $item) {
        $main[] = [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }
    $data = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $main,
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}

/** BlogPosting + BreadcrumbList for a single post. */
function tap_theme_post_json_ld($post) {
    $author = get_the_author_meta('display_name', $post->post_author);
    $img    = get_the_post_thumbnail_url($post->ID, 'large');

    $data = [
        '@context'      => 'https://schema.org',
        '@type'         => 'BlogPosting',
        'headline'      => get_the_title($post),
        'description'   => tap_theme_description_from($post),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink($post->ID)],
        'datePublished' => get_the_date('c', $post),
        'dateModified'  => get_the_modified_date('c', $post),
        'author'        => ['@type' => 'Person', 'name' => $author],
        'publisher'     => ['@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/')],
        'inLanguage'    => 'es',
        'wordCount'     => str_word_count(wp_strip_all_tags($post->post_content)),
    ];
    if ($img) {
        $data['image'] = [$img];
    }
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";

    $trail = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Inicio', 'travel-agency-platform'), 'item' => home_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Guías de viaje', 'travel-agency-platform'), 'item' => get_permalink((int) get_option('page_for_posts')) ?: home_url('/blog/')],
    ];
    $cats = get_the_category($post->ID);
    if ($cats) {
        $trail[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $cats[0]->name, 'item' => get_category_link($cats[0])];
        $pos = 4;
    } else {
        $pos = 3;
    }
    $trail[] = ['@type' => 'ListItem', 'position' => $pos, 'name' => get_the_title($post), 'item' => get_permalink($post->ID)];
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'BreadcrumbList',
        'itemListElement' => $trail,
    ], JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}

/** Excerpt-based description for a post. */
function tap_theme_description_from($post) {
    $base = $post->post_excerpt ?: $post->post_content;
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $base)));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 155);
    }
    return substr($text, 0, 155);
}

function tap_theme_seo_output() {
    if (is_admin() || is_feed()) {
        return;
    }

    // Services and agencies are fully handled by the plugin's TAP_SEO.
    if (class_exists('TAP_SEO') && is_singular(['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment', 'tap_agency'])) {
        return;
    }

    if (is_front_page()) {
        $desc = (string) get_theme_mod('tap_home_meta_desc', '');
        if (!$desc) {
            $desc = __('Guía completa de Costa Rica: destinos, mejores épocas, presupuestos y experiencias reservables con agencias locales verificadas.', 'travel-agency-platform');
        }
        tap_theme_meta($desc, home_url('/'));
        tap_theme_home_json_ld();
        if (get_theme_mod('tap_landing_show_faq', true)) {
            tap_theme_faq_json_ld();
        }
        return;
    }

    if (is_singular('post')) {
        $post = get_queried_object();
        if ($post instanceof WP_Post) {
            tap_theme_meta(tap_theme_description_from($post), get_permalink($post->ID), 'article');
            echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c', $post)) . '">' . "\n";
            echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c', $post)) . '">' . "\n";
            tap_theme_post_json_ld($post);
        }
        return;
    }

    if (is_home() || is_category()) {
        $blog_id = (int) get_option('page_for_posts');
        $title   = is_category() ? get_queried_object()->name : __('Guías de viaje de Costa Rica', 'travel-agency-platform');
        $desc    = is_category()
            ? sprintf(__('Todas las guías sobre %s: consejos prácticos, itinerarios y experiencias reales para planificar tu viaje a Costa Rica.', 'travel-agency-platform'), $title)
            : __('Guías de viaje de Costa Rica: destinos, mejores épocas, presupuestos, itinerarios y consejos prácticos escritos con información local.', 'travel-agency-platform');
        $canonical = is_category() ? get_category_link(get_queried_object()) : ($blog_id ? get_permalink($blog_id) : home_url('/blog/'));
        tap_theme_meta($desc, $canonical);
    }
}
add_action('wp_head', 'tap_theme_seo_output', 5);
