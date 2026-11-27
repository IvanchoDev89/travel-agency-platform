<?php
/**
 * suite_journey_audit.php — B2B/B2C entry points are present and renderable.
 */
require_once __DIR__ . '/bootstrap.php';

$base = dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/';
$src = (string) file_get_contents($base . 'includes/class-journey-audit.php');
$launch = (string) file_get_contents($base . 'includes/class-launch-check.php');

foreach (['mi-cuenta', 'checkout', 'booking-detail', 'manage-listing', 'register', 'planes', 'search-results', 'centro-de-soporte'] as $slug) {
    tap_t_assert(false !== strpos($src, "'" . $slug . "'"), 'journey audit covers /' . $slug . '/');
}
tap_t_assert(false !== strpos($launch, 'TAP_Journey_Audit::issues'), 'journey audit is wired into the launch check');

$issues = TAP_Journey_Audit::issues();
tap_t_assert(is_array($issues), 'journey audit returns a list');
foreach ($issues as $issue) {
    tap_t_assert(isset($issue['label'], $issue['detail']), 'journey issue is renderable');
}

// Functional: unpublishing a required page must break the audit, then restore.
$page = get_page_by_path('mi-cuenta');
if ($page) {
    $original = $page->post_status;
    wp_update_post(['ID' => $page->ID, 'post_status' => 'draft']);
    $detected = false;
    foreach (TAP_Journey_Audit::issues() as $issue) {
        if (false !== strpos($issue['label'], '/mi-cuenta/')) { $detected = true; }
    }
    wp_update_post(['ID' => $page->ID, 'post_status' => $original]);
    tap_t_assert($detected, 'an unpublished journey page is reported');
    tap_t_assert('publish' === get_post($page->ID)->post_status, 'the tested page is restored');
} else {
    tap_t_pass('mi-cuenta page not available (skipped)');
}

tap_t_finish();
