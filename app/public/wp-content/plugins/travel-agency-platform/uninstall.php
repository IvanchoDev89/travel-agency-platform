<?php
/**
 * Travel Agency Platform — uninstaller.
 *
 * Runs only when the plugin is deleted from wp-admin. Removes every trace the
 * plugin ever wrote: custom tables, options, transients, scheduled events,
 * custom roles/capabilities and the plugin's own post types.
 *
 * Set the `tap_uninstall_keep_content` option to '1' before deleting the
 * plugin if you want the tap_* posts to survive (tables are still removed).
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// 1) Custom tables ---------------------------------------------------------
// Enumerate all plugin tables dynamically so new tables are never forgotten.
$tables = [];
$all_tables = $wpdb->get_col($wpdb->prepare(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
    $wpdb->prefix . 'tap_%'
));
foreach ((array) $all_tables as $name) {
    $tables[] = substr((string) $name, strlen($wpdb->prefix));
}

foreach ($tables as $table) {
    $name = $wpdb->prefix . $table;
    // Verify it truly is ours before dropping (guards against prefix
    // collisions with unrelated tables).
    $exists = $wpdb->get_var($wpdb->prepare(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
        $name
    ));
    if ($exists === $name) {
        $wpdb->query("DROP TABLE {$name}");
    }
}

// 2) Scheduled events ------------------------------------------------------
foreach ([
    'tap_maintenance_hook', 'tap_auto_hook', 'tap_refund_retry_hook',
    'tap_points_expiry_hook', 'tap_push_reminders_hook', 'tap_admin_alerts_hook',
    'tap_affiliate_release_hook', 'tap_agency_registry_hook', 'tap_data_retention_hook',
    'tap_pay_reminder_hook', 'tap_process_email_campaigns',
] as $hook) {
    wp_clear_scheduled_hook($hook);
}

// 3) Options & transients --------------------------------------------------
$options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        'tap_%',
        '_transient_tap_%',
        '_transient_timeout_tap_%'
    )
);
foreach ($options as $option_name) {
    delete_option($option_name);
}

// Drop cached transients that may point at the (now gone) tables.
delete_transient('tap_rates');
// 'tap_map_' was never a real key — purge map transients by prefix.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '_transient_tap_map_data_v%'
         OR option_name LIKE '_transient_timeout_tap_map_data_v%'
         OR option_name LIKE '_transient_tap_services_map_data_v%'
         OR option_name LIKE '_transient_timeout_tap_services_map_data_v%'"
);

// 4) Roles & capabilities ---------------------------------------------------
$roles = ['tap_agency_admin', 'tap_agency_employee', 'tap_client'];
foreach ($roles as $role) {
    remove_role($role);
}
$admin = get_role('administrator');
if ($admin) {
    foreach (['tap_manage_bookings', 'tap_manage_agencies', 'tap_manage_commissions', 'tap_manage_reviews',
                'tap_manage_disputes', 'tap_view_reports', 'tap_manage_settings',] as $cap) {
        $admin->remove_cap($cap);
    }
}

// 4b) User meta written by the plugin (favorites, staff agency link, language)
foreach (['tap_favorites', '_tap_agency_id', 'tap_lang'] as $meta_key) {
    delete_metadata('user', 0, $meta_key, '', true);
}

// 5) Custom post types + their meta ----------------------------------------
if (get_option('tap_uninstall_keep_content', '0') !== '1') {
    $post_types = ['tap_agency', 'tap_accommodation', 'tap_room', 'tap_tour', 'tap_transport',
                    'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment',];
    $ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('" . implode("','", array_map('esc_sql', $post_types)) . "')"
    );
    if ($ids) {
        foreach (array_chunk($ids, 200) as $chunk) {
            $where = 'ID IN (' . implode(',', array_map('intval', $chunk)) . ')';
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE {$where}");
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$where})");
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$where})");
        }
    }
    // Leftover tap_ meta on unrelated content.
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_tap_%'");
}
