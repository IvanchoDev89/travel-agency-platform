<?php
/**
 * suite_tour_catalog.php — Fase 1: tour facets (tap_tour_type + tap_tour_difficulty)
 * and their wiring into search filters / REST search. Creates a temp tour with
 * terms assigned, verifies facet filtering (match + exclusion) and the forced
 * tap_tour narrowing, then removes the fixture.
 */
require_once __DIR__ . '/bootstrap.php';

$created = [];

tap_t_assert(taxonomy_exists('tap_tour_type'), 'tap_tour_type taxonomy registered');
tap_t_assert(taxonomy_exists('tap_tour_difficulty'), 'tap_tour_difficulty taxonomy registered');

// Seeded vocabularies.
$tt = get_terms(['taxonomy' => 'tap_tour_type', 'hide_empty' => false, 'number' => 0]);
$tt_slugs = wp_list_pluck($tt, 'slug');
tap_t_assert(is_array($tt) && count($tt) === 12, sprintf('12 tour types seeded (%d)', count($tt)));
foreach (['aventura', 'naturaleza', 'cultural', 'playa', 'gastronomia', 'relax', 'aereo', 'nautico', 'deportivo', 'familiar', 'avistamiento', 'senderismo'] as $s) {
    tap_t_assert(in_array($s, $tt_slugs, true), "tour type seeded: $s");
}

$df = get_terms(['taxonomy' => 'tap_tour_difficulty', 'hide_empty' => false, 'number' => 0]);
$df_by_slug = [];
foreach ($df as $d) {
    $df_by_slug[$d->slug] = $d->name;
}
tap_t_assert(is_array($df) && count($df) === 4, '4 tour difficulties seeded');
tap_t_assert(isset($df_by_slug['easy'], $df_by_slug['moderate'], $df_by_slug['hard'], $df_by_slug['extreme']), 'difficulty slugs present');
tap_t_assert('Fácil' === $df_by_slug['easy'] && 'Extremo' === $df_by_slug['extreme'], 'difficulty names in ES');

// Fixture: tour with hard + aventura + a destination term.
$agency = tap_t_test_agency();
$post = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'publish',
    'post_title'  => 'FacetSvc ' . wp_generate_password(6, false),
]);
if ($post && !is_wp_error($post)) {
    $created[] = (int) $post;
    update_post_meta($post, '_tap_tour_is_active', '1');
    update_post_meta($post, '_tap_tour_price', '120');
    update_post_meta($post, '_tap_tour_price_adult', '120');
    update_post_meta($post, '_tap_tour_price_child', '80');
    update_post_meta($post, '_tap_tour_currency', 'USD');
    update_post_meta($post, '_tap_tour_agency_id', $agency);
    wp_set_object_terms($post, ['hard'], 'tap_tour_difficulty');
    wp_set_object_terms($post, ['aventura'], 'tap_tour_type');
    $la = get_term_by('slug', 'la-fortuna', 'tap_location');
    if ($la instanceof WP_Term) {
        wp_set_object_terms($post, [$la->term_id], 'tap_location');
    }
}
tap_t_assert($post && !is_wp_error($post), 'created temp tour fixture');

// REST search honours difficulty + tour_type facets.
$resp = tap_t_rest('GET', '/tap/v1/search', ['type' => 'tap_tour', 'difficulty' => 'hard', 'tour_type' => 'aventura']);
$data = tap_t_rest_data($resp);
$ids = array_column((array) $data['results'] ?? [], 'id');
tap_t_assert(in_array((int) $post, array_map('intval', $ids), true), 'REST facet (hard+aventura) returns the tour');
$resp2 = tap_t_rest('GET', '/tap/v1/search', ['type' => 'tap_tour', 'difficulty' => 'moderate']);
$data2 = tap_t_rest_data($resp2);
$ids2 = array_column((array) $data2['results'] ?? [], 'id');
tap_t_assert(!in_array((int) $post, array_map('intval', $ids2), true), 'REST facet (moderate) excludes the tour');

// Shortcode filtering with GET params.
$_GET = ['difficulty' => 'hard', 'tour_type' => 'aventura', 'keyword' => 'FacetSvc'];
$out = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out, 'FacetSvc') !== false, 'search shortcode surfaces matching tour');

$_GET = ['difficulty' => 'easy', 'keyword' => 'FacetSvc'];
$out2 = do_shortcode('[tap_search_results]');
preg_match_all('/FacetSvc/', $out2, $hit);
tap_t_assert(count($hit[0]) === 1, 'no result cards for a non-matching difficulty (only keyword echo)');

// Non-existent facet must not error (ignored gracefully, still renders results).
$_GET = ['difficulty' => 'nope-not-a-term'];
$out3 = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out3, 'tap-search-results') !== false, 'unknown difficulty handled gracefully without error');

// Difficulty forces tap_tour even if another type was requested.
$_GET = ['type' => 'tap_accommodation', 'difficulty' => 'hard', 'keyword' => 'FacetSvc'];
$out4 = do_shortcode('[tap_search_results]');
tap_t_assert(strpos($out4, 'FacetSvc') !== false, 'difficulty active narrows results to tours regardless of type param');

foreach ($created as $pid) {
    wp_delete_post($pid, true);
}
tap_t_finish();