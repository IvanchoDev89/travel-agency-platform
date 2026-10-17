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
tap_t_assert(3 === TAP_Destinations::term_level($la->term_id), 'La Fortuna is a level-3 distrito');
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

// normalize() keeps level == depth and reparents orphan tourist places.
$lago  = get_term_by('name', 'Lago Arenal', 'tap_location');
$nuevo = get_term_by('name', 'Nuevo Arenal', 'tap_location');
$sc    = get_term_by('name', 'San Carlos', 'tap_location');
$til   = get_term_by('name', 'Tilarán', 'tap_location');
tap_t_assert($lago instanceof WP_Term && $sc instanceof WP_Term && (int) $lago->parent === (int) $sc->term_id, 'Lago Arenal reparented under San Carlos canton');
tap_t_assert($nuevo instanceof WP_Term && $til instanceof WP_Term && (int) $nuevo->parent === (int) $til->term_id, 'Nuevo Arenal reparented under Tilarán canton');

$all_terms = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false, 'number' => 0]);
$coherent  = true;
foreach ($all_terms as $t) {
    if (TAP_Destinations::term_level($t->term_id) !== TAP_Destinations::term_depth($t->term_id)) {
        $coherent = false;
        break;
    }
}
tap_t_assert($coherent, 'every destination tap_dest_level equals its tree depth');

// on_term_created backfills level/order for terms added via the WP term UI.
$tmp = wp_insert_term('TAP Test Temp Distrito', 'tap_location', ['parent' => (int) $sc->term_id]);
tap_t_assert(!is_wp_error($tmp), 'temp destination term created');
if (!is_wp_error($tmp)) {
    $tmp_id = (int) $tmp['term_id'];
    tap_t_assert(3 === TAP_Destinations::term_level($tmp_id), 'new term under a canton gets level 3 (distrito)');
    tap_t_assert((int) get_term_meta($tmp_id, 'tap_dest_order', true) > 0, 'new term gets a backfilled order');
    wp_delete_term($tmp_id, 'tap_location');
}

// Cascading picker: renders ancestor selects, leaves children out, preselected.
$html = TAP_Destinations::render_picker($la->term_id, 'location');
tap_t_assert(strpos($html, 'value="' . $la->term_id . '"') !== false, 'picker hidden field carries selected term id');
tap_t_assert(strpos($html, 'name="location"') !== false, 'picker hidden field named location');
tap_t_assert(strpos($html, 'selected="selected">La Fortuna') !== false, 'picker preselects La Fortuna at deepest level');
tap_t_assert(strpos($html, 'Charlotte') === false, 'picker options are children of selected level only');
tap_t_assert(strpos($html, '>Cantón') !== false || strpos($html, '>Distrito') !== false, 'picker shows intermediate level labels');

// Fresh picker: renders every level so the JS cascade can grow downward.
$fresh = TAP_Destinations::render_picker(0, 'location');
tap_t_assert(substr_count($fresh, '<select') === 5, 'fresh picker renders all five levels');
tap_t_assert(strpos($fresh, '>Costa Rica</option>') !== false, 'fresh picker level-0 offers the country root');
tap_t_assert(strpos($fresh, 'var MAP = {') !== false, 'picker embeds the parent->children map for cascading');

// seed_cr is idempotent: re-running must not duplicate terms.
$before = wp_count_terms('tap_location');
TAP_Destinations::seed_cr();
$after = wp_count_terms('tap_location');
tap_t_assert($before === $after, 'seed_cr does not duplicate when re-run');

// ---- admin management screen ----
tap_t_assert(class_exists('TAP_Dashboard_Destinations'), 'TAP_Dashboard_Destinations loaded');
$admin_login = 'tap_dest_admin_' . wp_generate_password(6, false);
$admin_id    = wp_create_user($admin_login, wp_generate_password(20), 'tap-dest-admin-' . wp_generate_password(6, false) . '@example.test');
if (is_wp_error($admin_id)) {
    tap_t_fail('admin fixture: ' . $admin_id->get_error_message());
} else {
    (new WP_User($admin_id))->set_role('administrator');
    wp_set_current_user($admin_id);
    ob_start();
    TAP_Dashboard_Destinations::render_page();
    $screen = ob_get_clean();
    tap_t_assert(strpos($screen, 'tap_destinations_save') !== false, 'admin screen renders the save form');
    tap_t_assert(strpos($screen, 'tap_destinations_add') !== false, 'admin screen renders the add form');
    tap_t_assert(strpos($screen, 'Costa Rica') !== false, 'admin screen lists the country root');
    tap_t_assert(strpos($screen, 'name="parent[') !== false, 'admin screen exposes a parent select per term');
    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($admin_id);
}

tap_t_finish();