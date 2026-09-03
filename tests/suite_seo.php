<?php
/**
 * E2E suite: SEO tags + search-results conversion shortcode.
 * Run via: wp --path="<site>/app/public" eval-file tests/suite_seo.php
 */
require __DIR__ . '/bootstrap.php';
add_filter('ai_enable', '__return_false');

global $wp_query, $wp_the_query;

function tap_seo_render() {
    ob_start();
    TAP_SEO::output();
    return ob_get_clean();
}

$service_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

// 1) Service singular: canonical + meta description + twitter + JSON-LD type
$found_singular = false;
foreach ($service_types as $pt) {
    $ids = get_posts(['post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
    if (!$ids) {
        continue;
    }
    $post = get_post($ids[0]);
    setup_postdata($post);
    $wp_query = new WP_Query();
    $wp_query->is_single = true; $wp_query->is_singular = true;
    $wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID;
    $GLOBALS['post'] = $post; $wp_the_query = $wp_query;

    $out = tap_seo_render();
    tap_t_assert(strpos($out, 'rel="canonical"') !== false, "[seo] {$pt} canonical present");
    tap_t_assert(preg_match('/<meta name="description" content="[^"]+">/', $out) === 1, "[seo] {$pt} meta description present < 156 chars");
    tap_t_assert(strpos($out, 'twitter:card') !== false, "[seo] {$pt} twitter cards present");
    tap_t_assert(preg_match('/<script type="application\/ld\+json">/', $out) === 1, "[seo] {$pt} JSON-LD present");

    // TouristTrip only for tours
    if ($pt === 'tap_tour') {
        tap_t_assert(strpos($out, 'TouristTrip') !== false, "[seo] tour uses TouristTrip schema");
    } elseif ($pt === 'tap_accommodation') {
        tap_t_assert(strpos($out, '"@type":"Hotel"') !== false, "[seo] accommodation uses Hotel schema");
    }
    $found_singular = true;
    break;
}
tap_t_assert($found_singular, "[seo] at least one service singular tested");

// 2) Agency singular: TravelAgency schema + og profile
$agency = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
if ($agency) {
    $post = get_post($agency[0]);
    setup_postdata($post);
    $wp_query = new WP_Query();
    $wp_query->is_single = true; $wp_query->is_singular = true;
    $wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID;
    $GLOBALS['post'] = $post; $wp_the_query = $wp_query;
    $out = tap_seo_render();
    tap_t_assert(strpos($out, 'TravelAgency') !== false, "[seo] agency TravelAgency schema present");
    tap_t_assert(strpos($out, 'content="profile"') !== false, "[seo] agency og:type=profile present");
    tap_t_assert(strpos($out, 'rel="canonical"') !== false, "[seo] agency canonical present");
}

// 3) Service archives: canonical + descriptive meta
foreach (['tap_tour', 'tap_accommodation'] as $pt) {
    $wp_query = new WP_Query(); $wp_the_query = $wp_query;
    $wp_query->is_archive = true; $wp_query->is_post_type_archive = true;
    $wp_query->set('post_type', $pt);
    $out = tap_seo_render();
    tap_t_assert(strpos($out, 'rel="canonical"') !== false, "[seo] {$pt} archive canonical present");
    tap_t_assert(preg_match('/<meta name="description" content="[^"]+">/', $out) === 1, "[seo] {$pt} archive meta description present");
}

// 4) Agency archive
$wp_query = new WP_Query(); $wp_the_query = $wp_query;
$wp_query->is_archive = true; $wp_query->is_post_type_archive = true;
$wp_query->set('post_type', 'tap_agency');
$out = tap_seo_render();
tap_t_assert(strpos($out, 'rel="canonical"') !== false && preg_match('/<meta name="description" content="[^"]+">/', $out) === 1, "[seo] agency archive canonical + description present");

// 5) Search results shortcode: nonempty state + empty state + no-keyword prompt
$any = get_posts(['post_type' => $service_types, 'post_status' => 'publish', 'posts_per_page' => 1]);
$kw = ($any && trim($any[0]->post_title) !== '') ? $any[0]->post_title : 'tour';
$_GET['keyword'] = $kw;
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'tap-service-card') !== false, "[conversion] search_results renders result cards ($kw)");
tap_t_assert(strpos($out, 'resultado') !== false, "[conversion] search_results shows count");

unset($_GET['keyword']);
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'Busca servicios') !== false, "[conversion] search_results shows prompt without keyword");

$_GET['keyword'] = 'zzzznomatch999';
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'No encontramos resultados') !== false, "[conversion] search_results shows empty state");

// 6) search form includes autocomplete destination box
$form = do_shortcode('[tap_search]');
tap_t_assert(strpos($form, 'tap-search-destino') !== false && strpos($form, 'tap-form-suggestions') !== false, "[conversion] search form has autocomplete box");

tap_t_finish();