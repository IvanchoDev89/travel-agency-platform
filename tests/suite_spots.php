<?php
/**
 * suite_spots.php — educational destination spots (tap_spot) + map shortcode.
 * Verifies the CPT/taxonomy registration, the idempotent seed, the editable
 * educational meta pipeline and the [tap_destinations_map] markup. Restores
 * any fixture it creates so re-runs stay green.
 */
require_once __DIR__ . '/bootstrap.php';

// ---- registration ----
tap_t_assert(post_type_exists('tap_spot'), 'tap_spot post type registered');
tap_t_assert(taxonomy_exists('tap_spot_type'), 'tap_spot_type taxonomy registered');
tap_t_assert(class_exists('TAP_Destination_Spots'), 'TAP_Destination_Spots loaded');

$types = TAP_Destination_Spots::default_types();
tap_t_assert(isset($types['parque-nacional']), 'default types include parque-nacional');
tap_t_assert(isset($types['reserva-biologica']), 'default types include reserva-biologica');
tap_t_assert(isset($types['sitio-arqueologico']), 'default types include sitio-arqueologico');
foreach (array_keys($types) as $slug) {
    tap_t_assert((bool) term_exists($slug, 'tap_spot_type'), "type term seeded: {$slug}");
}

// ---- seeded content ----
$seeded = get_posts(['post_type' => 'tap_spot', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
tap_t_assert(count($seeded) >= 12, 'at least a dozen spots seeded (' . count($seeded) . ')');

$arenal = get_page_by_path('volcan-arenal', OBJECT, 'tap_spot');
tap_t_assert($arenal instanceof WP_Post, 'Volcán Arenal seeded');
tap_t_assert(TAP_Destination_Spots::coord($arenal->ID, '_tap_spot_lat') !== null, 'Arenal has a latitude');
tap_t_assert(count(TAP_Destination_Spots::interest_list($arenal->ID)) >= 3, 'Arenal has 3+ educational notes');
$arenal_types = TAP_Destination_Spots::types_of($arenal->ID);
tap_t_assert($arenal_types && 'parque-nacional' === $arenal_types[0]->slug, 'Arenal is a national park');

// seed is idempotent
$before = count($seeded);
TAP_Destination_Spots::seed();
$after = get_posts(['post_type' => 'tap_spot', 'posts_per_page' => -1, 'fields' => 'ids']);
tap_t_assert($before === count($after), 'seed does not duplicate on re-run');

// ---- map_data payload ----
$data = TAP_Destination_Spots::map_data();
tap_t_assert(is_array($data) && count($data) >= 12, 'map_data returns the seeded spots');
tap_t_assert(null !== json_encode($data), 'map_data serializes to JSON');
$point = $data[0];
foreach (['id', 'title', 'lat', 'lng', 'type', 'interest'] as $key) {
    tap_t_assert(array_key_exists($key, $point), "map_data point exposes {$key}");
}
tap_t_assert(is_float($point['lat']) && is_float($point['lng']), 'map_data coordinates are floats');

// only coordinate-bearing spots are mapped
$mock = wp_insert_post(['post_type' => 'tap_spot', 'post_status' => 'publish', 'post_title' => 'TAP E2E sin coords']);
$with_mock = TAP_Destination_Spots::map_data();
$ids = wp_list_pluck($with_mock, 'id');
tap_t_assert(!in_array($mock, array_map('intval', $ids), true), 'spot without coordinates is skipped by the map');
wp_delete_post($mock, true);

// ---- filter by type ----
$parks = TAP_Destination_Spots::map_data(['type' => 'parque-nacional']);
tap_t_assert($parks, 'map_data can filter by type slug');
$all_parks = true;
foreach ($parks as $p) {
    if ('parque-nacional' !== $p['type']) {
        $all_parks = false;
        break;
    }
}
tap_t_assert($all_parks, 'filtered map_data returns only the requested type');

// ---- used types carry live counts ----
$used = TAP_Destination_Spots::used_types();
tap_t_assert($used, 'used_types lists non-empty types');
$used_ok = true;
foreach ($used as $t) {
    if ($t['count'] < 1 || !term_exists($t['slug'], 'tap_spot_type')) {
        $used_ok = false;
        break;
    }
}
tap_t_assert($used_ok, 'every used type has a positive count and a real term');

// ---- shortcode markup ----
$html = do_shortcode('[tap_destinations_map]');
tap_t_assert(strpos($html, 'tap-destmap') !== false, 'shortcode renders the map wrapper');
tap_t_assert(strpos($html, 'id="tap-destmap"') !== false, 'shortcode renders the map canvas');
tap_t_assert(strpos($html, 'id="tap-destmap-data"') !== false, 'shortcode embeds the JSON payload');
tap_t_assert(strpos($html, 'tap-destmap-list') !== false, 'shortcode renders the linked list');
tap_t_assert(strpos($html, 'tap-destmap-chip') !== false, 'shortcode renders the type filter chips');
tap_t_assert(strpos($html, 'data-spot-id=') !== false, 'list items carry their spot id for the map link');

$filtered = do_shortcode('[tap_destinations_map type="sitio-arqueologico"]');
tap_t_assert(strpos($filtered, 'Guayabo') !== false, 'type attribute filters the rendered spots');

// ---- editable meta pipeline (metabox save) ----
$fields = TAP_Metaboxes::get_fields('tap_spot');
tap_t_assert(isset($fields['_tap_spot_lat']), 'metabox exposes the latitude field');
tap_t_assert(isset($fields['_tap_spot_interest']), 'metabox exposes the interest field');
tap_t_assert('textarea' === $fields['_tap_spot_interest']['type'], 'interest field is a textarea (multiline)');

$editor_id = wp_create_user('tap_spot_editor_' . wp_generate_password(6, false), wp_generate_password(16, false), tap_t_unique_email());
if (is_wp_error($editor_id)) {
    tap_t_fail('spot fixture user: ' . $editor_id->get_error_message());
} else {
    (new WP_User($editor_id))->set_role('administrator');
    wp_set_current_user($editor_id);

    $post_id = wp_insert_post(['post_type' => 'tap_spot', 'post_status' => 'publish', 'post_title' => 'TAP E2E editable']);
    $_POST = [
        'tap_metabox_nonce'   => wp_create_nonce('tap_metabox_save'),
        '_tap_spot_lat'       => '9.9333',
        '_tap_spot_lng'       => '-84.0667',
        '_tap_spot_province'  => 'San José',
        '_tap_spot_interest'  => "Linea uno\nLinea dos\n\n  ",
        '_tap_spot_created'   => '1975',
    ];
    TAP_Metaboxes::save($post_id, get_post($post_id));
    tap_t_assert('9.9333' === get_post_meta($post_id, '_tap_spot_lat', true), 'save stores the sanitized latitude');
    $saved = TAP_Destination_Spots::interest_list($post_id);
    tap_t_assert(2 === count($saved) && 'Linea dos' === $saved[1], 'save keeps multiline interest and drops blanks');
    tap_t_assert('San José' === get_post_meta($post_id, '_tap_spot_province', true), 'save stores the province');

    // coordinates outside sane input are rejected to empty
    $_POST = ['tap_metabox_nonce' => wp_create_nonce('tap_metabox_save'), '_tap_spot_lat' => 'not-a-number'];
    TAP_Metaboxes::save($post_id, get_post($post_id));
    tap_t_assert('' === get_post_meta($post_id, '_tap_spot_lat', true), 'garbage coordinates are rejected');

    wp_set_current_user(0);
    $_POST = [];
    wp_delete_post($post_id, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($editor_id);
}

// ---- page + menu wiring ----
$page = get_page_by_path('destinos', OBJECT, 'page');
tap_t_assert($page instanceof WP_Post, '/destinos/ page exists');
tap_t_assert('publish' === $page->post_status, '/destinos/ page is published');
tap_t_assert(false !== strpos($page->post_content, 'tap_destinations_map'), '/destinos/ page hosts the map shortcode');

$linked = false;
foreach (wp_get_nav_menu_items(47) as $item) {
    if ((int) $item->object_id === (int) $page->ID) {
        $linked = true;
        break;
    }
}
tap_t_assert($linked, 'Destinos page is linked in the primary menu');
tap_t_assert(TAP_Destination_Spots::default_center()['lat'] > 8.0, 'default map centre sits over Costa Rica');

tap_t_finish();
