<?php
defined('ABSPATH') || exit;

class TAP_Roles {
    /**
     * Bump when this class adds/removes roles or capabilities so the setup
     * below re-runs exactly once after each deploy that changes the catalog.
     *
     * Version 3: administrator catalog derived from each CPT's real capability_type
     * (adds the missing edit_published_ and private_ primitives, equipment and
     * rooms) and the cleanup loop no longer deletes the correct plural caps.
     */
    const SETUP_VERSION = 3;

    public static function setup() {
        if ((int) get_option('tap_roles_version', 0) !== self::SETUP_VERSION) {
            self::add_agency_admin_role();
            self::add_agency_employee_role();
            self::add_client_role();
            self::add_capabilities();
            update_option('tap_roles_version', self::SETUP_VERSION);
        }
        add_action('user_register', [__CLASS__, 'assign_client_role']);
    }

    public static function assign_client_role($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user || !is_array($user->roles) || !in_array('subscriber', $user->roles, true)) return;
        $user->set_role('tap_client');
    }

    /** True when the user holds the tap_agency_admin role (not platform admins). */
    public static function is_agency_admin($user) {
        return in_array('tap_agency_admin', (array) $user->roles, true);
    }

    /** True when the user holds the tap_agency_employee role. */
    public static function is_agency_employee($user) {
        return in_array('tap_agency_employee', (array) $user->roles, true);
    }

    /** Who may use the agency back-office sections (platform admins included). */
    public static function can_manage_agency_panel($user) {
        return user_can($user, 'manage_options') || self::is_agency_admin($user);
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
        $post_types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment', 'tap_room'];

        // Primitive caps the meta-cap router needs for a member of the agency
        // to edit their own listings (map_meta_cap requires the *_published_*
        // variants on published content).
        $action_map = [
            'edit'    => ['edit_posts', 'edit_published_posts', 'edit_private_posts'],
            'publish' => ['publish_posts'],
            'delete'  => ['delete_posts', 'delete_published_posts', 'delete_private_posts'],
            'read'    => ['read_private_posts'],
        ];

        $caps = [];
        foreach ($post_types as $pt) {
            $obj = get_post_type_object($pt);
            foreach ($actions as $action) {
                $keys = $action_map[$action] ?? [$action . '_posts'];
                foreach ($keys as $key) {
                    if ($obj && !empty($obj->cap->{$key})) {
                        $caps[] = $obj->cap->{$key};
                    }
                }
                $caps[] = "{$action}_{$pt}"; // legacy singular, checked by UIs
            }
        }

        return array_values(array_unique($caps));
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

        $post_types = ['tap_agency', 'tap_accommodation', 'tap_room', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];

        // Role-usable primitive caps, taken from each CPT's capability_type so
        // irregular plurals (tap_agencies, tap_equipment_items) and the
        // edit_published_*/private_* variants map_meta_cap requires are always
        // granted.
        $primitives = [
            'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts',
            'publish_posts', 'read_private_posts',
            'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
        ];

        foreach ($post_types as $pt) {
            $admin->add_cap("edit_{$pt}", true);
            $admin->add_cap("read_{$pt}", true);
            $admin->add_cap("delete_{$pt}", true);

            $obj = get_post_type_object($pt);
            foreach ($primitives as $key) {
                $cap = ($obj && !empty($obj->cap->{$key})) ? $obj->cap->{$key} : '';
                if ($cap) {
                    $admin->add_cap($cap, true);
                }
            }
        }

        // Drop only the genuinely misspelled caps granted by earlier versions
        // (tap_agencys* from the old {$pt}s loop, tap_equipments* instead of
        // tap_equipment_items). Correct plurals must never be removed here.
        foreach (['tap_agencys', 'tap_equipments'] as $bad) {
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
