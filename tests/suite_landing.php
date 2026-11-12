<?php
/**
 * suite_landing.php — Landing Page Builder config v3 migration and
 * front-page wiring (order + independent toggles).
 * Run via: wp --path="<site>/app/public" eval-file tests/suite_landing.php
 */
require __DIR__ . '/bootstrap.php';

tap_t_assert(class_exists('TAP_Landing_Builder'), '[landing] builder class is loaded');

$types = TAP_Landing_Builder::section_types();
$expected = [
    'hero', 'stats', 'facts', 'destinations', 'interests', 'promoted', 'featured',
    'pillars', 'referrals', 'agencies_cta', 'itinerary', 'agencies', 'testimonials',
    'blog', 'faq', 'cta',
];
tap_t_assert(array_keys($types) === $expected, '[landing] section_types lists the 16 canonical sections in order');

foreach (['categories', 'newsletter', 'video', 'text', 'gallery'] as $removed) {
    tap_t_assert(!array_key_exists($removed, $types), "[landing] unsupported type {$removed} is not offered");
}

$default = TAP_Landing_Builder::default_config();
tap_t_assert((int) $default['version'] === (int) TAP_Landing_Builder::VERSION, '[landing] default config version matches builder version');
tap_t_assert(count($default['sections']) === 16, '[landing] default config has 16 sections');
$default_types = array_column($default['sections'], 'type');
tap_t_assert($default_types === $expected, '[landing] default config follows the canonical order');
tap_t_assert(array_column($default['sections'], 'order') === range(1, 16), '[landing] default config orders are sequential');

/* Legacy v2 config: duplicated orders, categories + interests, newsletter and
 * a stale type that the landing cannot render. */
$legacy = [
    ['type' => 'hero', 'enabled' => true, 'order' => 1, 'settings' => []],
    ['type' => 'categories', 'enabled' => false, 'order' => 2, 'settings' => []],
    ['type' => 'interests', 'enabled' => true, 'order' => 7, 'settings' => ['foo' => 'bar']],
    ['type' => 'destinations', 'enabled' => false, 'order' => 4, 'settings' => ['show_map' => false]],
    ['type' => 'newsletter', 'enabled' => true, 'order' => 6, 'settings' => []],
    ['type' => 'cta', 'enabled' => false, 'order' => 7, 'settings' => []],
    ['type' => 'made_up_section', 'enabled' => true, 'order' => 9, 'settings' => []],
];
$migrated = TAP_Landing_Builder::canonicalize_sections($legacy);
$migrated_types = array_column($migrated, 'type');
$migrated_by_type = array_column($migrated, null, 'type');

tap_t_assert($migrated_types === $expected, '[landing] migration reorders legacy config canonically');
tap_t_assert(count($migrated_types) === count(array_unique($migrated_types)), '[landing] migration keeps a single copy of each type');
tap_t_assert(array_column($migrated, 'order') === range(1, 16), '[landing] migration renumbers orders from 1');
tap_t_assert(!isset($migrated_by_type['categories']), '[landing] categories is aliased away');
tap_t_assert(!isset($migrated_by_type['newsletter']), '[landing] newsletter is dropped (not rendered by the landing)');
tap_t_assert(!isset($migrated_by_type['made_up_section']), '[landing] unknown types are dropped');
tap_t_assert(!empty($migrated_by_type['interests']['enabled']), '[landing] categories/interests merge keeps the section enabled');
tap_t_assert(($migrated_by_type['interests']['settings']['foo'] ?? '') === 'bar', '[landing] interests settings survive the migration');
tap_t_assert(empty($migrated_by_type['destinations']['enabled']), '[landing] destination toggle is preserved');
tap_t_assert(($migrated_by_type['destinations']['settings']['show_map'] ?? true) === false, '[landing] destination settings survive the migration');
tap_t_assert(empty($migrated_by_type['cta']['enabled']), '[landing] CTA toggle is preserved');
tap_t_assert(!empty($migrated_by_type['promoted']['enabled']), '[landing] missing promoted section is added enabled by default');

/* Front-page wiring: every section is buffered and printed in builder order. */
$front = (string) file_get_contents(get_template_directory() . '/front-page.php');
tap_t_assert(strpos($front, 'TAP_Landing_Builder::ordered_sections()') !== false, '[landing] front-page prints sections in builder order');
tap_t_assert(strpos($front, 'resultados-de-busqueda') === false, '[landing] front-page no longer links to the dead search page');
tap_t_assert(strpos($front, 'action="<?php echo esc_url($searchUrl); ?>"') !== false, '[landing] hero search form uses the canonical search URL');
tap_t_assert(substr_count($front, 'ob_start()') === substr_count($front, 'ob_get_clean()'), '[landing] every buffered section is captured');

foreach ($expected as $type) {
    tap_t_assert(strpos($front, '$tap_lp_sections[\'' . $type . '\']') !== false, "[landing] front-page buffers the {$type} section");
}

tap_t_finish();
