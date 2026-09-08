<?php
/**
 * suite_destinations.php — Fase 1: hierarchical destinations (tap_location).
 * Verifies the CR seed, level consistency, term-map JSON, breadcrumbs and the
 * cascading picker. Read-only against seeded data; no fixtures are created.
 */
require_once __DIR__ . '/bootstrap.php';

tap_t_assert(taxonomy_exists('tap_location'), 'tap_location taxonomy registered');
tap_t_assert(method_exists('TAP_Destinations', 'seed_cr'), 'TAP_Destinations present');

$levels = TAP_Destinations::levels();
tap_t_assert(count($levels) === 5, 'five destination levels defined');
tap_t_assert('Cantón' === TAP_Destinations::level_label(2), 'level 2 label is Cantón');
tap_t_assert('País' === TAP_Destinations::level_label(0), 'level 0 label is País (root)');

// Level distribution of the seeded CR tree.
$root = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false, 'parent' => 0]);
tap_t_assert(count($root) === 1 && $root[0]->name === 'Costa Rica', 'single root term Costa Rica');

$dist = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
$all = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false, 'number' => 0]);
foreach ($all as $t) {
    $l = TAP_Destinations::term_level($t->term_id);
    if ($l !== null && isset($dist[$l])) {
        $dist[$l]++;
    }
}
tap_t_assert($dist[0] === 1, sprintf('exactly one level-0 term (%d)', $dist[0]));
tap_t_assert($dist[1] === 7, sprintf('seven provinces / level 1 (%d)', $dist[1]));
tap_t_assert($dist[2] === 82, sprintf('82 cantons / level 2 (%d)', $dist[2]));

// term_map is a numeric-keyed array that serializes to valid JSON.
$map = TAP_Destinations::term_map();
tap_t_assert(is_array($map), 'term_map returns an array');
tap_t_assert(null !== json_encode($map), 'term_map serializes to valid JSON');
$ok = true;
foreach (array_keys($map) as $pid) {
    if (!is_numeric($pid)) {
        $ok = false;
        break;
    }
}
tap_t_assert($ok, 'term_map keys are parent term ids');

// Breadcrumbs walk the parent chain.
$la = get_term_by('slug', 'la-fortuna', 'tap_location');
tap_t_assert($la instanceof WP_Term, 'san carlos has La Fortuna place');
tap_t_assert(4 === TAP_Destinations::term_level($la->term_id), 'La Fortuna is a level-4 place');
$bc = TAP_Destinations::breadcrumb($la->term_id);
tap_t_assert('Costa Rica / Alajuela / San Carlos / La Fortuna' === $bc, 'La Fortuna breadcrumb correct: ' . $bc);
$ala = get_term_by('slug', 'alajuela', 'tap_location');
tap_t_assert('Costa Rica / Alajuela' === TAP_Destinations::breadcrumb($ala->term_id), 'Alajuela breadcrumb correct');
tap_t_assert(1 === TAP_Destinations::term_level($ala->term_id), 'Alajuela is a province (level 1)');

// children() ordering follows tap_dest_order (official canton sequence).
$kids = TAP_Destinations::children($ala->term_id);
$first = $kids[0] ?? null;
tap_t_assert(is_array($kids) && count($kids) >= 15, 'Alajuela has 15+ cantons');
tap_t_assert($first instanceof WP_Term && $first->name === 'Alajuela', 'Alajuela canton sorts first (order 1)');
$sorted = true;
$prev = -1;
foreach ($kids as $c) {
    $o = (int) get_term_meta($c->term_id, 'tap_dest_order', true);
    if ($o < $prev) {
        $sorted = false;
        break;
    }
    $prev = $o;
}
tap_t_assert($sorted, 'children() orders by tap_dest_order ascending');

// Cascading picker: renders ancestor selects, leaves children out, preselected.
$html = TAP_Destinations::render_picker($la->term_id, 'location');
tap_t_assert(strpos($html, 'value="' . $la->term_id . '"') !== false, 'picker hidden field carries selected term id');
tap_t_assert(strpos($html, 'name="location"') !== false, 'picker hidden field named location');
tap_t_assert(strpos($html, 'selected="selected">La Fortuna') !== false, 'picker preselects La Fortuna at deepest level');
tap_t_assert(strpos($html, 'Charlotte') === false, 'picker options are children of selected level only');
tap_t_assert(strpos($html, '>Cantón') !== false || strpos($html, '>Distrito') !== false, 'picker shows intermediate level labels');

// seed_cr is idempotent: re-running must not duplicate terms.
$before = wp_count_terms('tap_location');
TAP_Destinations::seed_cr();
$after = wp_count_terms('tap_location');
tap_t_assert($before === $after, 'seed_cr does not duplicate when re-run');

tap_t_finish();