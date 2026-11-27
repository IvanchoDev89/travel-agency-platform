<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for dashboard navigation.
 *
 * Sections are grouped so an agency admin sees 3 meaningful groups instead of
 * 13 flat links. Keys and URLs stay identical to the historical flat nav, so
 * bookmarks and deep links keep working.
 */
class TAP_Dashboard_Nav {

    public static function agency($active, $user_id = 0, $role = '') {
        $base = home_url('/mi-cuenta/');
        $user_id = $user_id ?: get_current_user_id();
        $agency = (int) TAP_Booking::get_agency_for_user($user_id);
        $unread = $agency ? TAP_Chat::unread_total_for_agency($agency) : 0;
        $new_bookings = 0;
        if ($agency) {
            global $wpdb;
            $new_bookings = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d AND status IN ('request','pending')",
                $agency
            ));
        }
        $is_admin = ('tap_agency_employee' !== $role && 'employee' !== $role);
        $url = static function ($key) use ($base) {
            return 'overview' === $key ? $base : add_query_arg('seccion', $key, $base);
        };
        $item = static function ($key, $label, $icon, $badge = 0) use ($url) {
            $out = [
                'key'   => $key,
                'label' => $label,
                'icon'  => $icon,
                'url'   => $url($key),
            ];
            if ($badge > 0) { $out['badge'] = $badge; }
            return $out;
        };

        $groups = [];
        $groups[] = [
            'key'   => 'operation',
            'label' => __('Operación', 'travel-agency-platform'),
            'items' => [
                $item('overview', __('Resumen', 'travel-agency-platform'), 'home'),
                $item('bo-operations', __('Operaciones', 'travel-agency-platform'), 'clipboard', $unread + $new_bookings),
                $item('bo-calendar', __('Calendario', 'travel-agency-platform'), 'calendar'),
                $item('bo-listings', __('Listados', 'travel-agency-platform'), 'grid'),
            ],
        ];

        if ($is_admin) {
            $groups[] = [
                'key'   => 'business',
                'label' => __('Negocio', 'travel-agency-platform'),
                'items' => [
                    $item('bo-finances', __('Finanzas', 'travel-agency-platform'), 'wallet'),
                    $item('bo-invoices', __('Facturas', 'travel-agency-platform'), 'receipt'),
                    $item('bo-reviews', __('Reseñas', 'travel-agency-platform'), 'chat'),
                    $item('bo-analytics', __('Analytics', 'travel-agency-platform'), 'chart'),
                    $item('bo-disputas', __('Disputas', 'travel-agency-platform'), 'flag'),
                    $item('bo-promos', __('Destacados', 'travel-agency-platform'), 'megaphone'),
                    $item('bo-referrals', __('Referidos', 'travel-agency-platform'), 'link'),
                ],
            ];
        }

        $account = [];
        if ($is_admin) {
            $account[] = $item('bo-profile', __('Perfil', 'travel-agency-platform'), 'user');
            $account[] = $item('bo-team', __('Equipo', 'travel-agency-platform'), 'users');
        }
        $account[] = $item('loyalty', __('Mis puntos', 'travel-agency-platform'), 'award');
        $account[] = $item('bo-audit', __('Actividad', 'travel-agency-platform'), 'clock');
        $account[] = [
            'key'   => 'help',
            'label' => __('Ayuda', 'travel-agency-platform'),
            'icon'  => 'help',
            'url'   => home_url('/centro-de-soporte/'),
        ];
        $groups[] = [
            'key'   => 'account',
            'label' => __('Cuenta', 'travel-agency-platform'),
            'items' => $account,
        ];

        return $groups;
    }

    /** Flatten grouped nav (used by mobile "more" sheet and shortcuts). */
    public static function flatten(array $groups) {
        $out = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Keys that stay visible in the mobile bottom bar. */
    public static function primary_keys() {
        return ['overview', 'bo-operations', 'bo-calendar', 'bo-listings'];
    }
}
