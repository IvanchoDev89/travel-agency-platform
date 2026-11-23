<?php
defined('ABSPATH') || exit;

/**
 * llms.txt: a small plain-text index for AI crawlers, listing the site's
 * identity and its main public entry points. No extra rewrite rules needed:
 * template_redirect fires before WordPress renders its 404 template.
 */
class TAP_LLMS {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_output']);
    }

    /** Serve /llms.txt as plain text. */
    public static function maybe_output() {
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!is_string($path) || '/llms.txt' !== rtrim($path, '/')) {
            return;
        }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=' . get_option('blog_charset'));
        echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text
        exit;
    }

    /** Build the file body (also callable directly by E2E suites). */
    public static function content() {
        $site   = get_bloginfo('name');
        $desc   = get_bloginfo('description');
        $home   = home_url('/');
        $lines  = [
            '# ' . $site,
            '',
            '> ' . $desc,
            '',
            'Este sitio es una plataforma de viajes de Costa Rica. Ofrece alojamientos, tours, transportes, alquiler de autos, barcos, paquetes y equipo.',
            '',
            '## Enlaces principales',
            '',
            '- [Inicio](' . $home . ')',
        ];

        $pages = [
            'Buscar servicios'   => '/search-results/',
            'Tours'              => '/tour/',
            'Alojamientos'       => '/accommodation/',
            'Destinos'           => '/destinations/',
            'Centro de soporte'  => '/centro-de-soporte/',
            'Privacidad'         => '/privacidad/',
        ];
        foreach ($pages as $label => $slug) {
            $lines[] = '- [' . $label . '](' . home_url($slug) . ')';
        }

        $services = get_posts([
            'post_type'      => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_boat', 'tap_package'],
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        if ($services) {
            $lines[] = '';
            $lines[] = '## Servicios destacados';
            $lines[] = '';
            foreach ($services as $service) {
                $lines[] = '- [' . get_the_title($service) . '](' . get_permalink($service) . ')';
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
