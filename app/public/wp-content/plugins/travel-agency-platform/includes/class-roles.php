<?php
defined('ABSPATH') || exit;

class TAP_Roles {
    public static function setup() {
        self::add_agency_admin_role();
        self::add_agency_employee_role();
        self::add_client_role();
        self::add_capabilities();
        add_action('user_register', [__CLASS__, 'assign_client_role']);
    }

    public static function assign_client_role($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user || !is_array($user->roles) || !in_array('subscriber', $user->roles, true)) return;
        $user->set_role('tap_client');
    }

    private static function add_agency_admin_role() {
        $caps = [
            'read'                      => true,
            'edit_posts'                => true,
            'upload_files'              => true,
            'manage_options'            => false,
            'publish_posts'             => true,
            'delete_posts'              => true,
            'edit_published_posts'      => true,
            'delete_published_posts'    => true,
            'edit_others_posts'         => false,
            'delete_others_posts'       => false,
        ];

        $service_caps = self::get_service_capabilities('edit', 'publish', 'delete');

        foreach ($service_caps as $cap) {
            $caps[$cap] = true;
        }

        $caps['edit_tap_agencies'] = true;
        $caps['edit_tap_agency'] = true;
        $caps['read_tap_agency'] = true;

        remove_role('tap_agency_admin');
        add_role('tap_agency_admin', __('Agency Admin', 'travel-agency-platform'), $caps);
    }

    private static function add_agency_employee_role() {
        $caps = [
            'read'                      => true,
            'edit_posts'                => false,
            'upload_files'              => true,
            'publish_posts'             => false,
            'delete_posts'              => false,
        ];

        $edit_caps = self::get_service_capabilities('edit');
        foreach ($edit_caps as $cap) {
            $caps[$cap] = true;
        }

        remove_role('tap_agency_employee');
        add_role('tap_agency_employee', __('Agency Employee', 'travel-agency-platform'), $caps);
    }

    private static function add_client_role() {
        remove_role('tap_client');
        add_role('tap_client', __('Client', 'travel-agency-platform'), [
            'read' => true,
        ]);
    }

    private static function get_service_capabilities(...$actions) {
        $post_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $caps = [];

        foreach ($post_types as $pt) {
            foreach ($actions as $action) {
                $caps[] = "{$action}_{$pt}s";
                $caps[] = "{$action}_{$pt}";
            }
        }

        return $caps;
    }

    private static function add_capabilities() {
        $admin = get_role('administrator');
        if (!$admin) return;

        $admin->add_cap('tap_manage_bookings', true);
        $admin->add_cap('tap_manage_agencies', true);
        $admin->add_cap('tap_manage_commissions', true);
        $admin->add_cap('tap_manage_reviews', true);
        $admin->add_cap('tap_manage_disputes', true);
        $admin->add_cap('tap_view_reports', true);
        $admin->add_cap('tap_manage_settings', true);

        $post_types = ['tap_agency', 'tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        foreach ($post_types as $pt) {
            // 'tap_agency' pluralises irregularly (tap_agencies, not
            // tap_agencys) and the CPT capability_type matches exactly that.
            $plural = ('tap_agency' === $pt) ? 'tap_agencies' : $pt . 's';
            $admin->add_cap("edit_{$pt}", true);
            $admin->add_cap("edit_{$plural}", true);
            $admin->add_cap("edit_others_{$plural}", true);
            $admin->add_cap("publish_{$plural}", true);
            $admin->add_cap("read_{$pt}", true);
            $admin->add_cap("delete_{$pt}", true);
            $admin->add_cap("delete_{$plural}", true);
            $admin->add_cap("delete_others_{$plural}", true);
        }

        // Drop the misspelled plurals granted by earlier versions.
        foreach ($post_types as $pt) {
            $bad = $pt . 's';
            foreach (["edit_{$bad}", "edit_others_{$bad}", "publish_{$bad}", "delete_{$bad}", "delete_others_{$bad}"] as $cap) {
                $admin->remove_cap($cap);
            }
        }
    }

    public static function remove() {
        remove_role('tap_agency_admin');
        remove_role('tap_agency_employee');
        remove_role('tap_client');
    }
}
