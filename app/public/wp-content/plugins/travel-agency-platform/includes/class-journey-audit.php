<?php
defined('ABSPATH') || exit;

/**
 * Read-only coverage audit for the B2B/B2C journeys.
 *
 * A booking flow can be perfect in code and still be broken in production by
 * a missing page, an unpublished page or an unregistered shortcode. This
 * check verifies the public entry points the plugin links to.
 */
class TAP_Journey_Audit {

    /** Slug => expected shortcode (or empty when a page template is used). */
    private static $pages = [
        'mi-cuenta'           => 'tap_front_dash',
        'checkout'            => '',
        'booking-detail'      => '',
        'my-bookings'         => 'tap_my_bookings',
        'manage-listing'      => '',
        'register'            => '',
        'planes'              => 'tap_plans',
        'search-results'      => 'tap_search_results',
        'centro-de-soporte'   => 'tap_support_center',
        'aviso-de-privacidad' => 'tap_privacy',
        'destinos'            => 'tap_destinations_map',
        'invita-y-gana'       => 'tap_referral_dashboard',
        'tours'               => 'tap_services',
        'dashboard'           => '',
    ];

    public static function issues() {
        $issues = [];
        foreach (self::$pages as $slug => $shortcode) {
            $page = get_page_by_path($slug);
            if (!$page || 'publish' !== $page->post_status) {
                $issues[] = [
                    'label'  => sprintf(/* translators: %s: slug de la página */ __('Falta la página del recorrido: /%s/', 'travel-agency-platform'), $slug),
                    'detail' => __('Crea y publica esa página; el plugin enlaza a ella desde el panel y el checkout.', 'travel-agency-platform'),
                ];
                continue;
            }
            if ('' !== $shortcode && false === strpos((string) $page->post_content, '[' . $shortcode)) {
                $issues[] = [
                    'label'  => sprintf(/* translators: 1: slug, 2: shortcode */ __('La página /%1$s/ no contiene [%2$s]', 'travel-agency-platform'), $slug, $shortcode),
                    'detail' => __('El recorrido se renderizará vacío aunque la página exista.', 'travel-agency-platform'),
                ];
            }
            if ('' !== $shortcode && !shortcode_exists($shortcode)) {
                $issues[] = [
                    'label'  => sprintf(/* translators: %s: shortcode */ __('Shortcode no registrado: [%s]', 'travel-agency-platform'), $shortcode),
                    'detail' => __('Otro plugin o el tema pudo desactivarlo.', 'travel-agency-platform'),
                ];
            }
            if ('' === $shortcode && '' === trim((string) $page->post_content)) {
                $template = (string) get_page_template_slug($page->ID);
                if ('' === $template || !locate_template($template)) {
                    $issues[] = [
                        'label'  => sprintf(/* translators: %s: slug */ __('La página /%s/ está vacía y sin plantilla', 'travel-agency-platform'), $slug),
                        'detail' => __('Añade el shortcode o asigna la plantilla del tema correspondiente.', 'travel-agency-platform'),
                    ];
                }
            }
        }
        return $issues;
    }
}
