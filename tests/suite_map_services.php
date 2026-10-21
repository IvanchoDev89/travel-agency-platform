<?php
/**
 * Test suite: dual-layer map (educational destinations + bookable services).
 *
 * Covers the service layer added to the Destinations map: the services data
 * provider, the shortcode markup for the layer switch, tabs and service list,
 * the enriched educational profile of the seeded spots and the idempotent
 * back-fill that upgrades spots seeded before those fields existed.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

// ---------------------------------------------------------------------------
// 1. The services data provider
// ---------------------------------------------------------------------------

tap_t_assert(method_exists('TAP_Destination_Spots', 'services_map_data'), 'services_map_data provider exists');

$services = TAP_Destination_Spots::services_map_data();
tap_t_assert(is_array($services), 'services_map_data returns an array');

$expected_types = array_intersect(
    ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_boat', 'tap_package'],
    array_keys(TAP_Post_Types::get_service_types())
);
tap_t_assert(!empty($expected_types), 'service post types are available to map');

$map_types = array_unique(array_column($services, 'type'));
foreach ($map_types as $type) {
    tap_t_assert(
        in_array($type, $expected_types, true),
        "mapped service type is a bookable service type ({$type})"
    );
}

$with_coords = array_filter($services, function ($s) {
    return null !== $s['lat'] && null !== $s['lng'];
});
tap_t_assert(!empty($with_coords), 'at least one service is placed on the map');

$geo_ok = true;
foreach ($services as $service) {
    if (!is_float($service['lat']) || !is_float($service['lng'])) {
        $geo_ok = false;
        break;
    }
    if ($service['lat'] < 7 || $service['lat'] > 12) {
        $geo_ok = false;
        break;
    }
    if ($service['lng'] > -82 || $service['lng'] < -86) {
        $geo_ok = false;
        break;
    }
}
tap_t_assert($geo_ok, 'every mapped service has float coordinates inside Costa Rica');

tap_t_assert(method_exists('TAP_Destination_Spots', 'valid_coord'), 'valid_coord helper exists');
tap_t_assert(TAP_Destination_Spots::valid_coord(9.9281, -84.0907), 'a San José position is valid');
tap_t_assert(!TAP_Destination_Spots::valid_coord(19.4326, -99.1332), 'a foreign position is rejected');
tap_t_assert(!TAP_Destination_Spots::valid_coord(null, null), 'a missing position is rejected');

// A listing whose own coordinates are out of bounds must fall back to its
// destination centroid instead of landing outside the country.
$fallback = wp_insert_post([
    'post_type'   => 'tap_accommodation',
    'post_status' => 'publish',
    'post_title'  => 'Alojamiento con coordenadas corruptas',
]);
tap_t_assert(is_int($fallback) && $fallback > 0, 'out-of-bounds fixture created');
wp_set_object_terms($fallback, 'la-fortuna', 'tap_location');
update_post_meta($fallback, '_tap_acc_lat', '19.4326');
update_post_meta($fallback, '_tap_acc_lng', '-99.1332');

$fallback_services = TAP_Destination_Spots::services_map_data();
$fallback_point = null;
foreach ($fallback_services as $service) {
    if ((int) $service['id'] === (int) $fallback) {
        $fallback_point = $service;
        break;
    }
}
tap_t_assert(null !== $fallback_point, 'the out-of-bounds listing still reaches the map');
if ($fallback_point) {
    tap_t_assert(true === $fallback_point['approx'], 'the out-of-bounds listing is flagged as approximate');
    tap_t_assert(
        $fallback_point['lat'] > 7 && $fallback_point['lat'] < 12
            && $fallback_point['lng'] > -86 && $fallback_point['lng'] < -82,
        'the out-of-bounds listing is relocated inside Costa Rica'
    );
}
wp_delete_post($fallback, true);

$shape_ok = true;
foreach ($services as $service) {
    foreach (['id', 'title', 'url', 'type', 'typeName', 'approx', 'ratingAvg', 'ratingCount'] as $key) {
        if (!array_key_exists($key, $service)) {
            $shape_ok = false;
            break 2;
        }
    }
}
tap_t_assert($shape_ok, 'each mapped service exposes the documented payload keys');

// Unpublished listings must never reach the public map.
$draft = wp_insert_post([
    'post_type'   => 'tap_tour',
    'post_status' => 'draft',
    'post_title'  => 'Tour oculto para el mapa',
]);
tap_t_assert(is_int($draft) && $draft > 0, 'draft tour fixture created');
$map_ids = array_map('intval', array_column($services, 'id'));
tap_t_assert(!in_array($draft, $map_ids, true), 'draft services are excluded from the map');
wp_delete_post($draft, true);

// ---------------------------------------------------------------------------
// 2. Shortcode markup for the dual layer
// ---------------------------------------------------------------------------

$html = do_shortcode('[tap_destinations_map]');

tap_t_assert(strpos($html, 'tap-destmap-toolbar') !== false, 'shortcode renders the map toolbar');
tap_t_assert(strpos($html, 'tap-destmap-layers') !== false, 'shortcode renders the layer switch');
tap_t_assert(strpos($html, 'data-layer="spots"') !== false, 'shortcode offers the destinations layer');
tap_t_assert(strpos($html, 'data-layer="services"') !== false, 'shortcode offers the services layer');
tap_t_assert(strpos($html, 'id="tap-destmap-services"') !== false, 'shortcode embeds the services JSON payload');
tap_t_assert(strpos($html, 'tap-destmap-tabs') !== false, 'shortcode renders the panel tabs');
tap_t_assert(strpos($html, 'tap-destmap-legend') !== false, 'shortcode renders the map legend');
tap_t_assert(strpos($html, 'data-item-kind="service"') !== false, 'service list items carry their kind');
tap_t_assert(strpos($html, 'data-service-type=') !== false, 'service list items carry their service type');
tap_t_assert(strpos($html, 'data-item-id=') !== false, 'list items carry their id for the map link');
tap_t_assert(strpos($html, 'data-spot-id=') !== false, 'spot list items keep the legacy data-spot-id attribute');

$services_json = null;
if (preg_match('/id="tap-destmap-services" value="([^"]*)"/', $html, $m)) {
    $services_json = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
}
tap_t_assert(is_array($services_json), 'the services payload is valid JSON');
tap_t_assert(!empty($services_json), 'the services payload is not empty');

// The legacy payload must still be present and decodable.
$spots_json = null;
if (preg_match('/id="tap-destmap-data" value="([^"]*)"/', $html, $m)) {
    $spots_json = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
}
tap_t_assert(is_array($spots_json), 'the destinations payload is valid JSON');
tap_t_assert(count($spots_json) >= 12, 'the destinations payload keeps every seeded spot');

// ---------------------------------------------------------------------------
// 3. Enriched educational profile
// ---------------------------------------------------------------------------

$fields = TAP_Destination_Spots::fields();
foreach ([
    '_tap_spot_region',
    '_tap_spot_ecosystem',
    '_tap_spot_altitude',
    '_tap_spot_best_season',
    '_tap_spot_biodiversity',
    '_tap_spot_activities',
    '_tap_spot_tips',
    '_tap_spot_conservation',
] as $key) {
    tap_t_assert(isset($fields[$key]), "metabox exposes the {$key} field");
}
tap_t_assert('textarea' === $fields['_tap_spot_biodiversity']['type'], 'biodiversity is a multiline field');
tap_t_assert('textarea' === $fields['_tap_spot_tips']['type'], 'tips is a multiline field');

$list_fields = TAP_Destination_Spots::list_fields();
tap_t_assert(in_array('_tap_spot_biodiversity', $list_fields, true), 'biodiversity is declared as a list field');
tap_t_assert(in_array('_tap_spot_activities', $list_fields, true), 'activities is declared as a list field');
tap_t_assert(in_array('_tap_spot_tips', $list_fields, true), 'tips is declared as a list field');

// The seeded spots must carry the extended profile.
$arenal = get_page_by_path('volcan-arenal', OBJECT, 'tap_spot');
tap_t_assert($arenal instanceof WP_Post, 'Volcán Arenal seeded');
tap_t_assert('' !== (string) get_post_meta($arenal->ID, '_tap_spot_region', true), 'Arenal has a region');
tap_t_assert('' !== (string) get_post_meta($arenal->ID, '_tap_spot_ecosystem', true), 'Arenal has an ecosystem');
tap_t_assert('' !== (string) get_post_meta($arenal->ID, '_tap_spot_best_season', true), 'Arenal has a best season');
tap_t_assert(count(TAP_Destination_Spots::list_value($arenal->ID, '_tap_spot_biodiversity')) >= 3, 'Arenal lists 3+ species');
tap_t_assert(count(TAP_Destination_Spots::list_value($arenal->ID, '_tap_spot_activities')) >= 3, 'Arenal lists 3+ activities');
tap_t_assert(count(TAP_Destination_Spots::list_value($arenal->ID, '_tap_spot_tips')) >= 2, 'Arenal lists 2+ tips');
tap_t_assert('' !== (string) get_post_meta($arenal->ID, '_tap_spot_conservation', true), 'Arenal has a conservation note');

// The map payload must expose the extended fields too.
$arenal_point = null;
foreach ($spots_json as $point) {
    if ('volcan-arenal' === $point['url'] || false !== strpos($point['url'], 'volcan-arenal')) {
        $arenal_point = $point;
        break;
    }
}
if (null === $arenal_point) {
    foreach ($spots_json as $point) {
        if (false !== strpos($point['title'], 'Arenal')) {
            $arenal_point = $point;
            break;
        }
    }
}
tap_t_assert(null !== $arenal_point, 'the map payload contains the Arenal');
if ($arenal_point) {
    foreach (['region', 'ecosystem', 'altitude', 'bestSeason', 'biodiversity', 'activities', 'tips', 'conservation'] as $key) {
        tap_t_assert(array_key_exists($key, $arenal_point), "map point exposes {$key}");
    }
    tap_t_assert(!empty($arenal_point['biodiversity']), 'map point carries the biodiversity list');
}

// ---------------------------------------------------------------------------
// 4. Back-fill of spots seeded before the extended fields existed
// ---------------------------------------------------------------------------

$legacy = wp_insert_post([
    'post_type'   => 'tap_spot',
    'post_status' => 'publish',
    'post_title'  => 'Lugar de prueba sin perfil',
    'post_name'   => 'lugar-de-prueba-sin-perfil',
]);
tap_t_assert(is_int($legacy) && $legacy > 0, 'legacy spot fixture created');
tap_t_assert('' === (string) get_post_meta($legacy, '_tap_spot_region', true), 'the fixture starts without a region');

delete_option('tap_spot_enriched');
TAP_Destination_Spots::enrich_existing();

tap_t_assert('' === (string) get_post_meta($legacy, '_tap_spot_region', true), 'the back-fill never invents data for unknown spots');
tap_t_assert('' !== (string) get_post_meta($arenal->ID, '_tap_spot_region', true), 'the back-fill keeps the seeded profile');

wp_delete_post($legacy, true);

// The back-fill must be idempotent.
$region_before = (string) get_post_meta($arenal->ID, '_tap_spot_region', true);
delete_option('tap_spot_enriched');
TAP_Destination_Spots::enrich_existing();
tap_t_assert($region_before === (string) get_post_meta($arenal->ID, '_tap_spot_region', true), 'the back-fill is idempotent');

// ---------------------------------------------------------------------------
// 5. Term centroids used to place services without their own coordinates
// ---------------------------------------------------------------------------

$points = TAP_Destination_Spots::term_points();
tap_t_assert(is_array($points) && !empty($points), 'term centroids are available');
tap_t_assert(isset($points['la-fortuna']), 'term centroids cover a known destination');
$point_ok = true;
foreach ($points as $coords) {
    if (!is_array($coords) || count($coords) !== 2) {
        $point_ok = false;
        break;
    }
    if (!is_float($coords[0]) && !is_int($coords[0])) {
        $point_ok = false;
        break;
    }
    if (!is_float($coords[1]) && !is_int($coords[1])) {
        $point_ok = false;
        break;
    }
}
tap_t_assert($point_ok, 'every term centroid is a lat/lng pair');

tap_t_finish();
