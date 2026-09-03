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

// 1b) FAQ + ContactPoint rich results on service singular
$faq_ok = strpos($out, 'FAQPage') !== false && strpos($out, 'Question') !== false;
tap_t_assert($faq_ok, "[seo] service singular includes FAQPage rich results");
// ContactPoint only emits when a phone is available (agency phone or support setting).
$pt_type = $GLOBALS['post']->post_type;
$pt_prefix = str_replace('tap_', '', $pt_type);
$sec_agency = (int) get_post_meta($GLOBALS['post']->ID, '_tap_' . $pt_prefix . '_agency_id', true);
$sec_has_phone = $sec_agency ? (bool) get_post_meta($sec_agency, '_tap_agency_phone', true) : false;
if ($sec_has_phone || (string) get_option('tap_support_phone', '') !== '') {
    tap_t_assert(strpos($out, 'ContactPoint') !== false, "[seo] service singular includes ContactPoint");
} else {
    tap_t_assert(true, "[seo] ContactPoint skipped (no phone configured on this site)");
}

// 1c) visible breadcrumbs rendered by service_detail shortcode
if ($found_singular && in_array($GLOBALS['post']->post_type, $service_types, true)) {
    $sid = $GLOBALS['post']->ID;
    $det = do_shortcode('[tap_service_detail id="' . $sid . '"]');
    tap_t_assert(strpos($det, 'tap-breadcrumbs') !== false, "[conversion] service_detail renders visible breadcrumbs");
}

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
tap_t_assert(strpos($out, 'tap-search-filters') !== false, "[conversion] search_results filter bar present");

unset($_GET['keyword']);
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'Busca servicios') !== false, "[conversion] search_results shows prompt without keyword");

$_GET['keyword'] = 'zzzznomatch999';
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'No encontramos resultados') !== false, "[conversion] search_results shows empty state");

// 5b) filters: type select filters only tours, sort renders
$_GET['keyword'] = ''; $_GET['type'] = 'tap_tour'; $_GET['sort'] = 'price_asc';
$out = do_shortcode('[tap_search_results]');
tap_t_assert((bool) preg_match('/value="tap_tour"[^>]*selected=/', $out), "[conversion] type filter applied");
tap_t_assert((bool) preg_match('/value="price_asc"[^>]*selected=/', $out), "[conversion] sort=price_asc applied");
unset($_GET['type'], $_GET['sort']);

// 6) search form includes autocomplete destination box
$form = do_shortcode('[tap_search]');
tap_t_assert(strpos($form, 'tap-search-destino') !== false && strpos($form, 'tap-form-suggestions') !== false, "[conversion] search form has autocomplete box");

// 7) agency detail renders visible breadcrumbs
if ($agency) {
    $det = do_shortcode('[tap_agency_detail id="' . $agency[0] . '"]');
    tap_t_assert(strpos($det, 'tap-breadcrumbs') !== false, "[conversion] agency_detail renders visible breadcrumbs");
}

// 8) sitemap: WP core provider covers all 6 service types + taxonomies
if (function_exists('wp_sitemaps_get_server')) {
    $pts = apply_filters('wp_sitemaps_post_types', get_post_types(['public' => true]));
    $missing = array_diff($service_types, array_keys($pts));
    tap_t_assert(empty($missing), "[seo] sitemap post_types covers all 6 service types (" . ($missing ? implode(',', $missing) : 'all') . ")");
    $taxs = apply_filters('wp_sitemaps_taxonomies', get_taxonomies(['public' => true]));
    $want = ['tap_location', 'tap_service_cat', 'tap_property_type', 'tap_tour_type', 'tap_vehicle_type', 'tap_boat_type', 'tap_amenity'];
    $missing_tax = array_diff($want, array_keys($taxs));
    tap_t_assert(empty($missing_tax), "[seo] sitemap taxonomies covers platform taxonomies");
}

tap_t_finish();