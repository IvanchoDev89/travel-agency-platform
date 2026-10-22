<?php
/**
 * Test suite: the PHP runtime requirement.
 *
 * The plugin runs on the PHP core, so it has to tell apart a patched release
 * from a vulnerable one. A single "minimum version" is not enough: 8.3.30
 * is newer than 8.2.35 but still affected by the October 2026 advisories.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/bootstrap.php';

tap_t_assert(class_exists('TAP_Requirements'), 'TAP_Requirements is loaded');

// The running interpreter must satisfy the requirement, otherwise the
// whole suite would be running on a vulnerable build.
tap_t_assert(
    TAP_Requirements::is_php_patched(PHP_VERSION),
    'the running PHP ' . PHP_VERSION . ' is patched'
);
tap_t_assert(
    TAP_Requirements::is_php_patched(),
    'the default check reads the running version'
);

// Patched releases of every supported branch.
$patched = ['8.2.35', '8.3.36', '8.4.27', '8.5.12', '8.5.13', '8.4.28', '8.3.37', '8.2.36', '9.0.0'];
foreach ($patched as $version) {
    tap_t_assert(
        TAP_Requirements::is_php_patched($version),
        "the patched release {$version} is accepted"
    );
}

// Vulnerable releases of the same branches.
$vulnerable = ['8.2.0', '8.2.34', '8.3.0', '8.3.30', '8.3.35', '8.4.0', '8.4.18', '8.4.26', '8.5.0', '8.5.3', '8.5.11'];
foreach ($vulnerable as $version) {
    tap_t_assert(
        !TAP_Requirements::is_php_patched($version),
        "the vulnerable release {$version} is rejected"
    );
}

// Branches without a published fix are never silently accepted.
foreach (['7.4.33', '8.0.30', '8.1.31'] as $version) {
    tap_t_assert(
        !TAP_Requirements::is_php_patched($version),
        "the unmaintained branch {$version} is rejected"
    );
}

// The requirement reported for a branch.
tap_t_assert('8.5.12' === TAP_Requirements::patched_for('8.5.3'), 'the 8.5 branch reports 8.5.12');
tap_t_assert('8.3.36' === TAP_Requirements::patched_for('8.3.30'), 'the 8.3 branch reports 8.3.36');
tap_t_assert('' === TAP_Requirements::patched_for('8.1.0'), 'an unmaintained branch reports no release');

tap_t_assert(
    false !== strpos(TAP_Requirements::patched_list(), '8.5.12'),
    'the list of patched releases mentions 8.5.12'
);
tap_t_assert(
    false !== strpos(TAP_Requirements::ADVISORIES, 'CVE-2026-109618'),
    'the advisory identifiers are exposed'
);

// The plugin header must ask for a patched version too.
$header = file_get_contents(TAP_PLUGIN_DIR . 'travel-agency-platform.php');
tap_t_assert(
    false !== strpos($header, 'Requires PHP:      8.2.35'),
    'the plugin header requires a patched PHP'
);

tap_t_finish();
