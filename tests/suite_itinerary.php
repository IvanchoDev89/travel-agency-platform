<?php
/**
 * suite_itinerary.php — itinerary builder (T3): taxonomy+price matching,
 * relaxed fallback, approved-agency exclusion, share view, i18n presence.
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$pref = 'it_' . substr(md5(microtime(true)), 0, 6) . '_';

// ---- Presence & wiring ----
tap_t_assert(class_exists('TAP_Itinerary'), 'TAP_Itinerary loaded');
tap_t_assert(shortcode_exists('tap_itinerary_builder'), 'shortcode tap_itinerary_builder registered');
tap_t_assert(false !== has_action('wp_ajax_tap_itinerary_search'), 'wp_ajax_tap_itinerary_search hooked');
tap_t_assert(false !== has_action('wp_ajax_nopriv_tap_itinerary_search'), 'nopriv hook present');

$td = TAP_Itinerary::terms_data();
tap_t_assert(!empty($td['interests']), 'seeded tour types feed the interests step');
tap_t_assert(!empty($td['destinations']), 'CR location tree feeds the destinations step');
tap_t_assert(count($td['types']) >= 6, 'service type choices present');

// ---- param normalisation ----
$interestSlug = $td['interests'][0]['slug'];
$locTerm = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false]);
$locId = is_array($locTerm) && $locTerm ? (int) $locTerm[0]->term_id : 0;
tap_t_assert($locId > 0, 'a location term exists for fixtures');

$params = TAP_Itinerary::parse_params([
    'interests'  => [$interestSlug, 'no-such-interest'],
    'locations'  => [$locId, 'abc'],
    'min_price'  => '10',
    'max_price'  => '300',
]);
tap_t_assert($params['interests'] === [$interestSlug], 'bogus interest slug dropped');
tap_t_assert($params['locations'] === [$locId], 'bogus location dropped');
tap_t_assert($params['min_price'] === 10.0 && $params['max_price'] === 300.0, 'price band parsed');

// ---- fixtures: two agencies + services ----
function it_make_agency($title)
{
    $id = wp_insert_post(['post_type' => 'tap_agency', 'post_status' => 'publish', 'post_title' => $title]);
    return $id;
}
function it_make_service($title, $agency, $price, $active = '1')
{
    $id = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => $title]);
    update_post_meta($id, '_tap_tour_agency_id', $agency);
    update_post_meta($id, '_tap_tour_price_adult', $price);
    update_post_meta($id, '_tap_tour_is_active', $active);
    return $id;
}

$agOk   = it_make_agency($pref . 'Agencia OK');
$agNo   = it_make_agency($pref . 'Agencia Pendiente');
TAP_Approval::set_status($agOk, TAP_Approval::APPROVED);
TAP_Approval::set_status($agNo, TAP_Approval::PENDING);
TAP_Approval::reset_cache();

$s1 = it_make_service($pref . 'Tour 50', $agOk, 50);
$s2 = it_make_service($pref . 'Tour 150', $agOk, 150);
$s3 = it_make_service($pref . 'Tour sin tags', $agOk, 60);
$s4 = it_make_service($pref . 'Tour otro destino', $agOk, 80);
$s5 = it_make_service($pref . 'Tour caro', $agOk, 500);
$s6 = it_make_service($pref . 'Tour agencia pendiente', $agNo, 90);
$s7 = it_make_service($pref . 'Tour inactivo', $agOk, 120, '0');

$otherLocTerms = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false]);
$otherLoc = 0;
foreach ((array) $otherLocTerms as $t) {
    if ((int) $t->term_id !== $locId) { $otherLoc = (int) $t->term_id; break; }
}

wp_set_object_terms($s1, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s2, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s4, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s5, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s6, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s7, [$interestSlug], 'tap_tour_type');
wp_set_object_terms($s1, [$locId], 'tap_location');
wp_set_object_terms($s2, [$locId], 'tap_location');
wp_set_object_terms($s4, $otherLoc ? [$otherLoc] : [], 'tap_location');
wp_set_object_terms($s5, [$locId], 'tap_location');
wp_set_object_terms($s6, [$locId], 'tap_location');
wp_set_object_terms($s7, [$locId], 'tap_location');

// ---- strict matching ----
$strict = [
    'interests'  => [$interestSlug],
    'locations'  => [$locId],
    'min_price'  => null,
    'max_price'  => 300,
    'types'      => ['tap_tour'],
    'categories' => [],
];
$found = array_map(fn($p) => $p->ID, TAP_Itinerary::build_query($strict));
sort($found);
tap_t_assert($found === [$s1, $s2], 'strict query matches interest+location within price (got ' . implode(',', $found) . ')');
tap_t_assert(!in_array($s3, $found, true), 'untagged service excluded');
tap_t_assert(!in_array($s4, $found, true), 'other-destination service excluded');
tap_t_assert(!in_array($s5, $found, true), 'over-budget service excluded');
tap_t_assert(!in_array($s6, $found, true), 'pending-agency service excluded');
tap_t_assert(!in_array($s7, $found, true), 'inactive service excluded');

$wide = $strict; $wide['min_price'] = 90;
$foundWide = array_map(fn($p) => $p->ID, TAP_Itinerary::build_query($wide));
tap_t_assert(in_array($s2, $foundWide, true) && !in_array($s1, $foundWide, true), 'min price floors out cheaper tour');

// ---- relaxed keeps location+price, drops interests ----
$relaxed = array_map(fn($p) => $p->ID, TAP_Itinerary::build_query($strict, true));
sort($relaxed);
tap_t_assert($relaxed === [$s1, $s2], 'relaxed keeps location+price and drops interest (got ' . implode(',', $relaxed) . ')');

// ---- share view ----
$share = TAP_Itinerary::share_view($s1 . ',' . $s2 . ',999999,' . $s7);
tap_t_assert(false !== strpos($share, $pref . 'Tour 50'), 'share view lists selected service');
tap_t_assert(false !== strpos($share, $pref . 'Tour 150'), 'share view lists second service');
tap_t_assert(false === strpos($share, '999999'), 'share view ignores invalid ids');
tap_t_assert(false === strpos($share, $pref . 'Tour inactivo'), 'share view skips inactive service');
tap_t_assert(false !== strpos($share, 'Total estimado'), 'share view shows total');

$shareEmpty = TAP_Itinerary::share_view('0,abc');
tap_t_assert(false !== strpos($shareEmpty, 'vacío'), 'share view handles empty itinerario');

// ---- wizard renders ----
$GLOBALS['post'] = get_post($s1);
$_GET = [];
$wizard = TAP_Itinerary::render([]);
tap_t_assert(false !== strpos($wizard, 'data-itin-form'), 'wizard form rendered');
tap_t_assert(false !== strpos($wizard, 'tap-itin-side'), 'itinerary summary panel rendered');
tap_t_assert(false !== strpos($wizard, 'data-itin-view'), 'share-your-itinerary button present');
$itinJs = file_get_contents(__DIR__ . '/../app/public/wp-content/plugins/travel-agency-platform/assets/js/itinerary.js');
tap_t_assert(false !== strpos($itinJs, 'itin='), 'share URL scheme built by JS');

// ---- i18n presence ----
TAP_Localization::set_lang('en');
$en = __('Mi itinerario', 'travel-agency-platform');
tap_t_assert('My itinerary' === $en, 'EN translation for Mi itinerario');
TAP_Localization::set_lang('es');
$es = __('Total estimado', 'travel-agency-platform');
tap_t_assert('Total estimado' === $es, 'ES identity for Total estimado');

// ---- cleanup ----
foreach ([$s1, $s2, $s3, $s4, $s5, $s6, $s7, $agOk, $agNo] as $id) {
    wp_delete_post($id, true);
}
tap_t_finish();