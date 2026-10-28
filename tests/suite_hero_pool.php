<?php
/**
 * suite_hero_pool.php — landing hero video pool: the theme_mod roundtrip,
 * the random picker and the rules that drop unusable entries (deleted
 * Media Library files, empty URLs) and keep remote links untouched.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_hero_pool.php
 */
require __DIR__ . '/bootstrap.php';

/* Theme functions come from the active theme (inc/pexels.php). */
tap_t_assert(function_exists('tap_pexels_pool_get'), 'theme exposes tap_pexels_pool_get()');
tap_t_assert(function_exists('tap_pexels_pool_pick'), 'theme exposes tap_pexels_pool_pick()');

/* 1) Pick falls back to the single video when the pool is unusable ----------- */
tap_t_assert(null === tap_pexels_pool_pick([]), 'empty pool falls back to the single video');
tap_t_assert(null === tap_pexels_pool_pick('not-an-array'), 'malformed pool falls back to the single video');
tap_t_assert(null === tap_pexels_pool_pick([['poster' => '/x.jpg']]), 'entry without a URL is ignored');
tap_t_assert(null === tap_pexels_pool_pick(['garbage', null, 42]), 'non-array entries are ignored');
tap_t_assert(
    null === tap_pexels_pool_pick([['url' => '/wp-content/uploads/tap-pexels-missing-' . wp_generate_password(8, false) . '.mp4']]),
    'a deleted local file drops out of the rotation'
);

/* 2) Local files are validated against ABSPATH ------------------------------- */
$uploads = wp_upload_dir();
$dir     = trailingslashit($uploads['basedir']);
if (!is_dir($dir)) {
    wp_mkdir_p($dir);
}
$slug    = 'tap-pexels-pool-' . wp_generate_password(8, false);
$abs_vid = $dir . $slug . '.mp4';
$abs_pst = $dir . $slug . '.jpg';
$created = [];

foreach ([$abs_vid, $abs_pst] as $file) {
    if (false !== file_put_contents($file, 'test')) {
        $created[] = $file;
    }
}

$rel_url   = '/' . ltrim(str_replace(ABSPATH, '', $abs_vid), '/');
$rel_poster = '/' . ltrim(str_replace(ABSPATH, '', $abs_pst), '/');

$local = tap_pexels_pool_pick([['url' => $rel_url, 'poster' => $rel_poster]]);
tap_t_assert(is_array($local), 'an existing local file is a valid pool entry');
tap_t_assert(($local['url'] ?? '') === $rel_url, 'the picked URL is returned untouched (root-relative)');
tap_t_assert(($local['poster'] ?? '') === $rel_poster, 'the poster travels with the picked video');

/* 3) Remote Pexels links are not checked against the filesystem -------------- */
$remote = 'https://videos.pexels.com/video-files/123/123-hd.mp4';
$picked = tap_pexels_pool_pick([['url' => $remote, 'poster' => 'https://images.pexels.com/x.jpg']]);
tap_t_assert(is_array($picked) && $picked['url'] === $remote, 'remote URLs stay in the rotation (download disabled)');

/* 4) Mixed pools only rotate through usable entries -------------------------- */
$dead   = '/wp-content/uploads/tap-pexels-gone-' . wp_generate_password(8, false) . '.mp4';
$mixed  = tap_pexels_pool_pick([
    ['url' => $dead],
    ['url' => $rel_url, 'poster' => $rel_poster],
]);
tap_t_assert(is_array($mixed) && $mixed['url'] === $rel_url, 'unusable entries are skipped, usable ones are picked');

/* 5) Random rotation reaches every valid entry ------------------------------- */
$two  = [['url' => $rel_url], ['url' => $remote]];
$seen = [];
for ($i = 0; $i < 60; $i++) {
    $pick = tap_pexels_pool_pick($two);
    $seen[$pick['url'] ?? ''] = true;
}
tap_t_assert(count($seen) === 2, 'every reload can pick any video of the pool (' . count($seen) . '/2 seen)');

/* 6) tap_pexels_pool_get() normalizes the theme_mod -------------------------- */
$orig    = get_theme_mod('tap_hero_video_pool', null);
$had_mod = (null !== $orig);

set_theme_mod('tap_hero_video_pool', 'broken');
tap_t_assert([] === tap_pexels_pool_get(), 'a malformed theme_mod is read as an empty pool');

set_theme_mod('tap_hero_video_pool', ['a' => ['url' => $remote]]);
$normalized = tap_pexels_pool_get();
tap_t_assert(is_array($normalized) && array_keys($normalized) === [0], 'pool entries are re-indexed');

if ($had_mod) {
    set_theme_mod('tap_hero_video_pool', $orig);
} else {
    remove_theme_mod('tap_hero_video_pool');
}

/* 7) The admin AJAX endpoints are registered -------------------------------- */
tap_t_assert(false !== has_action('wp_ajax_tap_pexels_pool_add'), 'admin AJAX action tap_pexels_pool_add is registered');
tap_t_assert(false !== has_action('wp_ajax_tap_pexels_pool_remove'), 'admin AJAX action tap_pexels_pool_remove is registered');
tap_t_assert(false !== has_action('wp_ajax_tap_pexels_use_video'), 'the single-video action is still registered');

/* Cleanup: remove the temporary media files. */
foreach ($created as $file) {
    @unlink($file);
}

tap_t_finish();
