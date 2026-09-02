<?php
/**
 * suite_core.php — plugin integrity + schema.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_core.php
 */
require __DIR__ . '/bootstrap.php';

// 1) plugin header + constant
tap_t_assert(defined('TAP_VERSION'), 'TAP_VERSION constant defined');
tap_t_assert(version_compare(TAP_VERSION, '1.3.0', '>='), 'TAP_VERSION >= 1.3.0');
tap_t_assert(class_exists('TAP_Installer'), 'TAP_Installer class exists');
tap_t_assert(class_exists('TAP_Booking'), 'TAP_Booking class exists');
tap_t_assert(class_exists('TAP_Promotions'), 'TAP_Promotions class exists');
tap_t_assert(class_exists('TAP_Analytics'), 'TAP_Analytics class exists');
tap_t_assert(class_exists('TAP_Dashboard'), 'TAP_Dashboard class exists');

// 2) schema tables exist
global $wpdb;
$tables = ['tap_bookings', 'tap_plans', 'tap_agency_subscriptions', 'tap_promos', 'tap_commission_payments', 'tap_listing_views'];
foreach ($tables as $t) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . $t . "'") === $wpdb->prefix . $t;
    tap_t_assert($exists, "table {$t} exists");
}

// 3) migrate() is idempotent (safe to re-run; no data loss)
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings");
$r = new ReflectionClass('TAP_Installer');
$m = $r->getMethod('migrate');
$m->setAccessible(true);
$m->invoke(null);
$m->invoke(null);
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings");
tap_t_assert($before === $after, "migrate() idempotent (bookings {$before} == {$after})");

// 4) plans seeded correctly
$plans = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}tap_plans ORDER BY id");
$expected = [1 => 'Gratis', 2 => 'Básico', 3 => 'Pro'];
foreach ($expected as $pid => $name) {
    $found = false;
    foreach ($plans as $p) {
        if ((int) $p->id === $pid) { $found = true; break; }
    }
    tap_t_assert($found, "plan {$pid} seeded");
}

tap_t_finish();