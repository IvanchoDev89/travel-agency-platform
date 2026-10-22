<?php
/**
 * Test suite: completeness of the destination content.
 *
 * Every published destination must carry a full educational profile: region,
 * ecosystem, elevation, best season, biodiversity, activities, visitor tips,
 * conservation status, accessibility note, official website and an editorial
 * body. The suite also checks the new destination types and that the extra
 * destinations are inserted on installs that were seeded before they existed.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

// ---------------------------------------------------------------------------
// 1. The new destination types
// ---------------------------------------------------------------------------

$types = TAP_Destination_Spots::default_types();
foreach (['termas', 'pueblo', 'mirador'] as $slug) {
    tap_t_assert(isset($types[$slug]), "default types include {$slug}");
}

foreach (['termas', 'pueblo', 'mirador'] as $slug) {
    tap_t_assert((bool) term_exists($slug, 'tap_spot_type'), "type term seeded: {$slug}");
}

// ---------------------------------------------------------------------------
// 2. The destinations added after the first release
// ---------------------------------------------------------------------------

$extra = TAP_Destination_Spots::seed_extra_spots();
tap_t_assert(is_array($extra) && count($extra) >= 16, 'the extra seed holds at least 16 destinations');

$expected_slugs = [
    'la-fortuna', 'montezuma', 'monteverde', 'santa-rosa', 'nuevo-arenal',
    'rio-celeste', 'lomas-barbudal', 'cabo-blanco', 'cabo-papagayo',
    'cerro-de-la-muerte', 'peninsula-nicoya', 'braulio-carrillo',
    'tapantial', 'juan-santamaria', 'samara', 'puerto-jimenez',
];
foreach ($expected_slugs as $slug) {
    tap_t_assert(isset($extra[$slug]), "extra seed includes {$slug}");
}

$valid_types = array_keys(TAP_Destination_Spots::default_types());
$extra_ok = true;
foreach ($extra as $slug => $spot) {
    if (!in_array($spot['type'], $valid_types, true)) {
        $extra_ok = false;
        break;
    }
    if ('' === trim((string) $spot['lat']) || '' === trim((string) $spot['lng'])) {
        $extra_ok = false;
        break;
    }
}
tap_t_assert($extra_ok, 'every extra destination uses a known type and has coordinates');

$extra_content_ok = true;
foreach ($extra as $slug => $spot) {
    foreach (['content', 'accessibility', 'website', 'excerpt', 'conservation'] as $key) {
        if (empty($spot[$key])) {
            $extra_content_ok = false;
            break 2;
        }
    }
}
tap_t_assert($extra_content_ok, 'every extra destination carries body, accessibility and website');

// The destinations the user asked for by name must be present.
foreach (['La Fortuna', 'Montezuma', 'Monteverde'] as $needle) {
    $found = false;
    foreach ($extra as $spot) {
        if (false !== stripos($spot['title'], $needle)) {
            $found = true;
            break;
        }
    }
    tap_t_assert($found, "the new destinations include {$needle}");
}

// ---------------------------------------------------------------------------
// 3. Every published destination has a complete profile
// ---------------------------------------------------------------------------

$published = $wpdb->get_results(
    "SELECT ID, post_name, post_title, post_content FROM {$wpdb->posts}
     WHERE post_type = 'tap_spot' AND post_status = 'publish'"
);
tap_t_assert(count($published) >= 31, 'at least 31 destinations are published (' . count($published) . ')');

$meta_keys = [
    '_tap_spot_region',
    '_tap_spot_ecosystem',
    '_tap_spot_altitude',
    '_tap_spot_best_season',
    '_tap_spot_conservation',
    '_tap_spot_accessibility',
    '_tap_spot_website',
];
$list_keys = ['_tap_spot_biodiversity', '_tap_spot_activities', '_tap_spot_tips'];

$incomplete = [];
foreach ($published as $post) {
    foreach ($meta_keys as $key) {
        if ('' === trim((string) get_post_meta($post->ID, $key, true))) {
            $incomplete[] = $post->post_name . ':' . $key;
        }
    }
    foreach ($list_keys as $key) {
        if (count(TAP_Destination_Spots::list_value($post->ID, $key)) < 2) {
            $incomplete[] = $post->post_name . ':' . $key;
        }
    }
    if ('' === trim((string) $post->post_content)) {
        $incomplete[] = $post->post_name . ':post_content';
    }
}
tap_t_assert(empty($incomplete), 'every published destination is complete (' . implode(', ', array_slice($incomplete, 0, 5)) . ')');

// The official website must be a real URL.
$bad_urls = [];
foreach ($published as $post) {
    $url = (string) get_post_meta($post->ID, '_tap_spot_website', true);
    if ('' === $url || !filter_var($url, FILTER_VALIDATE_URL)) {
        $bad_urls[] = $post->post_name;
    }
}
tap_t_assert(empty($bad_urls), 'every destination links to a valid website (' . implode(', ', $bad_urls) . ')');

// ---------------------------------------------------------------------------
// 4. No two destinations share a position
// ---------------------------------------------------------------------------

$points = [];
$collisions = [];
foreach ($published as $post) {
    $lat = (string) get_post_meta($post->ID, '_tap_spot_lat', true);
    $lng = (string) get_post_meta($post->ID, '_tap_spot_lng', true);
    if ('' === $lat || '' === $lng) {
        continue;
    }
    $key = $lat . ',' . $lng;
    if (isset($points[$key])) {
        $collisions[] = $points[$key] . ' / ' . $post->post_name;
    }
    $points[$key] = $post->post_name;
}
tap_t_assert(empty($collisions), 'no two destinations share a position (' . implode(', ', $collisions) . ')');

// ---------------------------------------------------------------------------
// 5. The map exposes the new destinations
// ---------------------------------------------------------------------------

$data = TAP_Destination_Spots::map_data();
tap_t_assert(count($data) >= 31, 'map_data returns every published destination');

$slugs = [];
foreach ($data as $point) {
    if (!empty($point['url'])) {
        $slugs[] = basename(parse_url($point['url'], PHP_URL_PATH));
    }
}
foreach (['la-fortuna', 'montezuma', 'monteverde'] as $slug) {
    tap_t_assert(in_array($slug, $slugs, true), "the map includes {$slug}");
}

// The new types must show up in the filter bar.
$used = TAP_Destination_Spots::used_types();
$used_slugs = array_column($used, 'slug');
tap_t_assert(in_array('pueblo', $used_slugs, true), 'the filter bar offers the towns type');
tap_t_assert(in_array('termas', $used_slugs, true), 'the filter bar offers the hot springs type');

// ---------------------------------------------------------------------------
// 6. The editorial body is rendered on the destination page
// ---------------------------------------------------------------------------

$fortuna = get_page_by_path('la-fortuna', OBJECT, 'tap_spot');
tap_t_assert($fortuna instanceof WP_Post, 'La Fortuna is published');
if ($fortuna) {
    tap_t_assert(strlen(trim((string) $fortuna->post_content)) > 120, 'La Fortuna has an editorial body');
    tap_t_assert(
        false !== strpos((string) $fortuna->post_content, 'Fortuna'),
        'the editorial body mentions the destination'
    );
}

// ---------------------------------------------------------------------------
// 7. The back-fill is idempotent and never overwrites an edit
// ---------------------------------------------------------------------------

$edited = get_page_by_path('volcan-arenal', OBJECT, 'tap_spot');
tap_t_assert($edited instanceof WP_Post, 'Volcán Arenal is published');

$before = (string) get_post_meta($edited->ID, '_tap_spot_accessibility', true);
update_post_meta($edited->ID, '_tap_spot_accessibility', 'Texto editado a mano');
delete_option('tap_spot_enriched');
TAP_Destination_Spots::enrich_existing();
tap_t_assert(
    'Texto editado a mano' === (string) get_post_meta($edited->ID, '_tap_spot_accessibility', true),
    'the back-fill never overwrites an edited field'
);

update_post_meta($edited->ID, '_tap_spot_accessibility', $before);
delete_option('tap_spot_enriched');
TAP_Destination_Spots::enrich_existing();
tap_t_assert(
    $before === (string) get_post_meta($edited->ID, '_tap_spot_accessibility', true),
    'the back-fill restores the seeded value'
);

// ---------------------------------------------------------------------------
// 8. The extra destinations are inserted on an install that lacked them
// ---------------------------------------------------------------------------

$probe = wp_insert_post([
    'post_type'   => 'tap_spot',
    'post_status' => 'publish',
    'post_title'  => 'Destino de prueba para la sincronizacion',
    'post_name'   => 'destino-de-prueba-sincronizacion',
]);
tap_t_assert(is_int($probe) && $probe > 0, 'sync probe created');

$before_count = count(TAP_Destination_Spots::seed_extra_spots());
TAP_Destination_Spots::sync_extra_spots();
$after_count = count(TAP_Destination_Spots::seed_extra_spots());
tap_t_assert($before_count === $after_count, 'syncing does not duplicate the extra destinations');

$exists = get_page_by_path('la-fortuna', OBJECT, 'tap_spot');
tap_t_assert($exists instanceof WP_Post, 'syncing keeps the destinations in place');

wp_delete_post($probe, true);

tap_t_finish();
