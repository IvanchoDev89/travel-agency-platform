<?php
/**
 * suite_content.php — T5 content seed validation.
 * Verifies pages, posts, services, destinations, menus and i18n are in place.
 */
require __DIR__ . '/bootstrap.php';

// ---- 1) static pages ----
$pages = [
    'tours' => 300,
    'sobre-nosotros' => 300,
    'contacto' => 300,
    'preguntas-frecuentes' => 300,
    'politica-de-privacidad' => 300,
    'como-funciona' => 300,
    'terminos-y-condiciones' => 300,
    'blog' => 50,
];
foreach ($pages as $slug => $minLen) {
    $p = get_page_by_path($slug);
    tap_t_assert($p && strlen($p->post_content) >= $minLen, "page {$slug} exists with content >= {$minLen} chars (got " . ($p ? strlen($p->post_content) : 0) . ")");
}

// ---- 2) services with seed meta ----
function svc_count($type) {
    $posts = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_tap_t5_seeded', 'meta_value' => '1']);
    return count($posts);
}
tap_t_assert(svc_count('tap_tour') >= 6, 'at least 6 seeded tours (got ' . svc_count('tap_tour') . ')');
tap_t_assert(svc_count('tap_transport') >= 2, 'at least 2 seeded transports');
tap_t_assert(svc_count('tap_boat') >= 1, 'at least 1 seeded boat');
tap_t_assert(svc_count('tap_package') >= 2, 'at least 2 seeded packages');
tap_t_assert(svc_count('tap_equipment') >= 1, 'at least 1 seeded equipment');
tap_t_assert(svc_count('tap_accommodation') >= 6, 'at least 6 seeded accommodations');

// ---- 3) tours have agency, price, location, thumbnail, SEO ----
$tours = get_posts(['post_type' => 'tap_tour', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_tap_t5_seeded', 'meta_value' => '1']);
$ok_tours = 0;
foreach ($tours as $t) {
    $pfx   = '_tap_tour_';
    $ag    = (int) get_post_meta($t->ID, $pfx . 'agency_id', true);
    $price = floatval(get_post_meta($t->ID, '_tap_tour_price_adult', true));
    $seo   = get_post_meta($t->ID, '_tap_seo_description', true);
    $thumb = has_post_thumbnail($t->ID);
    $loc   = wp_get_post_terms($t->ID, 'tap_location', ['fields' => 'ids']);
    if ($ag && $price > 0 && $seo && $thumb && !empty($loc)) {
        $ok_tours++;
    }
}
tap_t_assert($ok_tours >= 6, "all 6 tours have agency + price + SEO + thumbnail + location (ok={$ok_tours})");

// ---- 4) accommodations enriched ----
$acc_ids = [35, 160, 45, 42, 39, 70];
$ok_acc = 0;
foreach ($acc_ids as $aid) {
    $p = get_post($aid);
    if (!$p || $p->post_type !== 'tap_accommodation') continue;
    $ag    = (int) get_post_meta($aid, '_tap_acc_agency_id', true);
    $price = floatval(get_post_meta($aid, '_tap_acc_price_per_night', true));
    $seo   = get_post_meta($aid, '_tap_seo_description', true);
    if ($ag && $price > 0 && $seo) {
        $ok_acc++;
    }
}
tap_t_assert($ok_acc >= 5, "at least 5 accommodations have agency + price + SEO (ok={$ok_acc})");

// ---- 5) destination terms ----
$dests = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false, 'fields' => 'ids']);
tap_t_assert(count($dests) >= 100, 'at least 100 tap_location terms (got ' . count($dests) . ')');
$nuevo = get_term_by('slug', 'nuevo-arenal', 'tap_location');
tap_t_assert($nuevo && $nuevo->parent > 0, 'Nuevo Arenal is a child term');
$fortuna = get_term_by('slug', 'la-fortuna', 'tap_location');
tap_t_assert($fortuna && $fortuna->parent > 0, 'La Fortuna is a child term');

// ---- 6) blog posts ----
$posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_tap_t5_seeded', 'meta_value' => '1']);
tap_t_assert(count($posts) >= 8, 'at least 8 seeded blog posts (got ' . count($posts) . ')');
$ok_posts = 0;
foreach ($posts as $po) {
    $cats = wp_get_post_categories($po->ID);
    $thumb = has_post_thumbnail($po->ID);
    if (!empty($cats) && $thumb) $ok_posts++;
}
tap_t_assert($ok_posts >= 8, "all 8 posts have category + thumbnail (ok={$ok_posts})");

// ---- 7) menus ----
$primary = wp_get_nav_menu_object('Primary Menu');
tap_t_assert($primary, 'Primary Menu exists');
$footer = wp_get_nav_menu_object('Footer Menu');
tap_t_assert($footer, 'Footer Menu exists');
$items = wp_get_nav_menu_items('Primary Menu');
tap_t_assert($items && count($items) >= 10, 'Primary Menu has >= 10 items (got ' . ($items ? count($items) : 0) . ')');
$has_blog = false;
foreach ($items as $i) {
    if ($i->title === 'Blog') $has_blog = true;
}
tap_t_assert($has_blog, 'Primary Menu includes Blog item');

// ---- 8) reading options ----
tap_t_assert((int) get_option('page_for_posts') > 0, 'page_for_posts is set');
tap_t_assert((int) get_option('page_on_front') > 0, 'page_on_front is set');

// ---- 9) i18n: template strings exist in .po ----
$po = file_get_contents(__DIR__ . '/../app/public/wp-content/plugins/travel-agency-platform/languages/travel-agency-platform-es_ES.po');
$i18n_strings = ['Experiencias en %s', 'Descubre otros rincones de la zona', 'Arma tu viaje a tu medida', 'Comenzar a planear', 'Leer más'];
$ok_i18n = 0;
foreach ($i18n_strings as $s) {
    if (strpos($po, $s) !== false) $ok_i18n++;
}
tap_t_assert($ok_i18n >= 5, "at least 5 template strings in es_ES .po (ok={$ok_i18n})");

// ---- 10) WP 7.1 compat: setup_postdata global sync ----
// Verify services_list shortcode returns proper titles (not empty)
$svc = do_shortcode('[tap_services type="tap_tour" limit="1"]');
$has_title = strpos($svc, '<h3>') !== false && strpos($svc, '</a></h3>') !== false;
tap_t_assert($has_title, 'services_list renders titles (WP 7.1 compat)');

tap_t_finish();
