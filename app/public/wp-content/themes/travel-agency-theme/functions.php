<?php
defined('ABSPATH') || exit;

define('TAT_VERSION', '2.0.30');

function travel_agency_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    add_theme_support('custom-logo', [
        'height'      => 60,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ]);

    register_nav_menus([
        'primary' => __('Primary Menu', 'travel-agency-platform'),
        'footer'  => __('Footer Menu', 'travel-agency-platform'),
    ]);
}
add_action('after_setup_theme', 'travel_agency_theme_setup');

/**
 * Whether the current request renders the agency listing manager.
 *
 * The wizard stylesheet is only needed there (accommodation and tour
 * wizards). Loading it site-wide cost a request and ~25KB on every page.
 */
function travel_agency_is_manage_listing() {
    if (!is_page()) {
        return false;
    }
    return is_page_template('templates/manage-listing.php') || is_page('manage-listing');
}

function travel_agency_theme_assets() {
    $plugin_url = content_url('plugins/travel-agency-platform/');
    $theme_uri  = get_template_directory_uri();

    wp_enqueue_style('tap-tokens', $plugin_url . 'assets/css/tokens.css', [], TAT_VERSION);
    wp_enqueue_style('tap-public', $plugin_url . 'assets/css/public.css', ['tap-tokens'], TAT_VERSION);

    // Fonts are now self-hosted via @font-face in style.css (assets/fonts/).
    // No external Google Fonts dependency.

    wp_enqueue_style('travel-agency-theme-style', get_stylesheet_uri(), ['tap-public'], TAT_VERSION);
    if (travel_agency_is_manage_listing()) {
        wp_enqueue_style('tap-agency-calendar', $plugin_url . 'assets/css/agency-calendar.css', ['tap-public'], TAT_VERSION);
    }


    wp_enqueue_script('travel-agency-theme-main', $theme_uri . '/assets/js/main.js', ['jquery'], TAT_VERSION, true);
    wp_localize_script('travel-agency-theme-main', 'tat_ajax', [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'nonce'         => wp_create_nonce('tap_nonce'),
        'filters_label' => __('Filtros', 'travel-agency-platform'),
    ]);

    // Enhanced date picker on the landing hero search.
    if (is_front_page()) {
        wp_enqueue_script('jquery-ui-datepicker');
        $date_init = "(function($){\n"
            . "  if (!$.datepicker) return;\n"
            . "  var fmt = 'yy-mm-dd';\n"
            . "  $('#hs-checkin').datepicker({\n"
            . "    dateFormat: fmt,\n"
            . "    minDate: 0,\n"
            . "    onSelect: function(selected) {\n"
            . "      var d = $(this).datepicker('getDate');\n"
            . "      if (d) {\n"
            . "        var next = new Date(d.getTime() + 86400000);\n"
            . "        $('#hs-checkout').datepicker('option', 'minDate', next);\n"
            . "        if (!$('#hs-checkout').val()) { $('#hs-checkout').datepicker('setDate', next); }\n"
            . "      }\n"
            . "    }\n"
            . "  });\n"
            . "  $('#hs-checkout').datepicker({\n"
            . "    dateFormat: fmt,\n"
            . "    minDate: 1\n"
            . "  });\n"
            . "})(jQuery);";
        wp_add_inline_script('jquery-ui-datepicker', $date_init);
    }
}

function tap_customizer_css() {
    $primary      = get_theme_mod('tap_color_primary', '#0d9488');
    $primary_dark = get_theme_mod('tap_color_primary_dark', '#0f766e');
    $primary_bg   = get_theme_mod('tap_color_primary_bg', '#f0fdfa');
    $primary_border = get_theme_mod('tap_color_primary_border', '#99f6e4');
    $text         = get_theme_mod('tap_color_text', '#0f172a');
    $text_muted   = get_theme_mod('tap_color_text_muted', '#64748b');
    $bg           = get_theme_mod('tap_color_background', '#ffffff');
    $border       = get_theme_mod('tap_color_border', '#e2e8f0');
    $font_family  = get_theme_mod('tap_font_family', 'plus-jakarta-sans');
    $heading_font = get_theme_mod('tap_heading_font', 'fraunces');
    $font_size    = (int) get_theme_mod('tap_font_size_base', 16);
    $container    = (int) get_theme_mod('tap_container_max', 1200);
    $padding      = (int) get_theme_mod('tap_section_padding', 64);
    $header_height = (int) get_theme_mod('tap_header_height', 76);
    $header_bg    = get_theme_mod('tap_header_bg', 'glass');
    $logo_height  = (int) get_theme_mod('tap_header_logo_height', 36);
    $footer_bg    = get_theme_mod('tap_footer_bg', '#0f172a');
    $footer_text  = get_theme_mod('tap_footer_text', '#cbd5e1');
    $custom_css   = get_theme_mod('tap_custom_css', '');

    $fonts = [
        'system'             => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'plus-jakarta-sans'  => "'Plus Jakarta Sans', sans-serif",
        'inter'              => "'Inter', sans-serif",
        'poppins'            => "'Poppins', sans-serif",
        'montserrat'         => "'Montserrat', sans-serif",
        'manrope'            => "'Manrope', sans-serif",
        'sora'               => "'Sora', sans-serif",
        'roboto'             => "'Roboto', sans-serif",
        'lato'               => "'Lato', sans-serif",
    ];
    $font_stack = $fonts[$font_family] ?? $fonts['plus-jakarta-sans'];
    $heading_fonts = [
        'fraunces'          => "'Fraunces', serif",
        'playfair-display'  => "'Playfair Display', serif",
        'dm-serif-display'  => "'DM Serif Display', serif",
        'lora'              => "'Lora', serif",
        'plus-jakarta-sans' => "'Plus Jakarta Sans', sans-serif",
    ];
    $heading_stack = $heading_fonts[$heading_font] ?? $heading_fonts['fraunces'];

    $css = ':root {';
    $css .= '--tap-primary: ' . esc_attr($primary) . ';';
    $css .= '--tap-primary-dark: ' . esc_attr($primary_dark) . ';';
    $css .= '--tap-primary-bg: ' . esc_attr($primary_bg) . ';';
    $css .= '--tap-primary-border: ' . esc_attr($primary_border) . ';';
    $css .= '--tap-neutral-900: ' . esc_attr($text) . ';';
    $css .= '--tap-neutral-500: ' . esc_attr($text_muted) . ';';
    $css .= '--tap-border: ' . esc_attr($border) . ';';
    $css .= '--tap-surface: #ffffff;';
    $css .= '--tap-surface-2: #f8fafc;';
    $css .= '--tap-surface-muted: #f8fafc;';
    $css .= '--tap-font-sans: ' . esc_attr($font_stack) . ';';
    $css .= '--tap-font-serif: ' . esc_attr($heading_stack) . ';';
    $css .= '--tap-text-base: ' . $font_size . 'px;';
    $css .= '--tap-container-max: ' . $container . 'px;';
    $css .= '--tap-section-padding: ' . $padding . 'px;';
    $css .= '--tap-header-height: ' . $header_height . 'px;';
    $css .= '}';
    // Header background
    $header_bg_css = 'background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);';
    if ($header_bg === 'solid') {
        $header_bg_css = 'background: #fff;';
    } elseif ($header_bg === 'dark') {
        $header_bg_css = 'background: #0f172a; color: #fff;';
    } elseif ($header_bg === 'transparent') {
        $header_bg_css = 'background: transparent; border-bottom: none;';
    }
    $css .= '.site-header { ' . $header_bg_css . ' }';
    if (!get_theme_mod('tap_header_sticky', true)) {
        $css .= '.site-header { position: relative; }';
    }
    if (!get_theme_mod('tap_header_shadow', true)) {
        $css .= '.site-header.scrolled { box-shadow: none; }';
    }
    $css .= '.site-branding .custom-logo { height: ' . $logo_height . 'px; width: auto; }';
    $css .= '.site-footer { background: ' . esc_attr($footer_bg) . '; color: ' . esc_attr($footer_text) . '; }';
    $css .= '.site-footer h3, .site-footer .footer-brand h3 { color: #fff; }';
    if ($custom_css) {
        $css .= $custom_css;
    }
    if ($bg !== '#ffffff') {
        $css .= 'body { background-color: ' . esc_attr($bg) . '; }';
    }
    echo '<style id="tap-customizer-css">' . $css . '</style>';
}
add_action('wp_head', 'tap_customizer_css', 5);

add_action('wp_enqueue_scripts', 'travel_agency_theme_assets');

/**
 * Render-safe media URL.
 *
 * Local media saved into theme mods (Pexels videos, posters, customizer images)
 * captures the absolute URL of the moment it was created. Under Local-by-
 * Flywheel the host port changes between sessions, which silently breaks every
 * saved absolute URL. Same-host URLs (ignoring the port) are therefore rendered
 * as root-relative paths; external URLs (e.g. a Pexels hotlink) pass through.
 *
 * Assumes a root WordPress install (a Local site standard).
 */
function tap_media_url($url) {
    $url = trim((string) $url);
    if ('' === $url) {
        return '';
    }
    $parsed  = wp_parse_url($url);
    if (empty($parsed['host'])) {
        return $url; // already relative
    }
    $current = wp_parse_url(home_url('/'));
    if (($parsed['host'] ?? '') !== ($current['host'] ?? '')) {
        return $url; // external
    }
    $path = '/' . ltrim((string) ($parsed['path'] ?? ''), '/');
    return $path === '/' ? '' : $path;
}

class TAT_Nav_Walker extends Walker_Nav_Menu {
    public function start_lvl(&$output, $depth = 0, $args = null) {
        $indent = str_repeat("\t", $depth);
        $output .= "\n$indent<ul class=\"sub-menu\" data-depth=\"" . (int) $depth . "\">\n";
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        $indent = $depth ? str_repeat("\t", $depth) : '';
        $classes = empty($item->classes) ? [] : (array) $item->classes;
        $class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args, $depth));
        $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';

        $has_children = in_array('menu-item-has-children', $classes);
        $aria_expanded = $has_children ? ' aria-expanded="false"' : '';

        $output .= $indent . '<li' . $class_names . $aria_expanded . '>';

        $atts = [];
        $atts['href'] = !empty($item->url) ? esc_url($item->url) : '#';
        $atts['class'] = 'nav-link';
        if ($depth > 0) $atts['class'] .= ' nav-link-sub';

        $attributes = '';
        foreach ($atts as $attr => $value) {
            $attributes .= ' ' . $attr . '="' . $value . '"';
        }

        $title = apply_filters('the_title', $item->title, $item->ID);
        $item_output = $args->before;
        $item_output .= '<a' . $attributes . '>';
        $item_output .= $args->link_before . $title . $args->link_after;
        $item_output .= '</a>';

        if ($has_children) {
            $item_output .= '<button class="submenu-toggle" aria-label="' . esc_attr__('Toggle submenu', 'travel-agency-platform') . '" aria-expanded="false" aria-controls="submenu-' . (int) $item->ID . '">';
            $item_output .= '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
            $item_output .= '</button>';
        }

        $item_output .= $args->after;
        $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }
}

function travel_agency_theme_widgets_init() {
    register_sidebar([
        'name'          => __('Footer Column 1', 'travel-agency-platform'),
        'id'            => 'footer-1',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3>',
        'after_title'   => '</h3>',
    ]);

    register_sidebar([
        'name'          => __('Footer Column 2', 'travel-agency-platform'),
        'id'            => 'footer-2',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3>',
        'after_title'   => '</h3>',
    ]);

    register_sidebar([
        'name'          => __('Footer Column 3', 'travel-agency-platform'),
        'id'            => 'footer-3',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3>',
        'after_title'   => '</h3>',
    ]);
}
add_action('widgets_init', 'travel_agency_theme_widgets_init');

function travel_agency_route_service_single($template) {
    if (is_singular('tap_tour')) {
        $alt = locate_template('single-tap_tour.php');
        if ($alt) return $alt;
    }
    if (is_singular(['tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'])) {
        $alt = locate_template('templates/single-service.php');
        if ($alt) return $alt;
    }
    return $template;
}
add_filter('template_include', 'travel_agency_route_service_single');

function tap_fav_button($post_id = 0, $class = '') {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    $active = class_exists('TAP_API') && TAP_API::is_favorite($post_id);
    $active_class = $active ? ' active' : '';
    return '<button type="button" class="tap-fav-btn ' . esc_attr($class . $active_class) . '" data-post-id="' . (int) $post_id . '" aria-label="' . esc_attr__('Guardar en favoritos', 'travel-agency-platform') . '" aria-pressed="' . ($active ? 'true' : 'false') . '"><span class="tap-fav-heart">♥</span></button>';
}

function tap_rating_stars($avg = 0, $count = 0) {
    if (empty($count)) {
        return '';
    }
    $fill = round(($avg / 5) * 100, 1);
    return '<span class="tap-stars" aria-hidden="true"><span class="tap-stars-fill" style="width:' . esc_attr($fill) . '%">★★★★★</span>★★★★★</span>';
}

function tap_card_rating($post_id = 0, $map_type = '') {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    if (!$map_type) {
        $map_type = get_post_type();
    }
    $stats = class_exists('TAP_API') ? TAP_API::get_rating_stats($map_type, $post_id) : ['avg' => 0, 'count' => 0];
    if (empty($stats['count'])) {
        return '';
    }
    return '<div class="tap-card-rating">' . tap_rating_stars($stats['avg'], $stats['count']) . '<span class="tap-card-rating-count">' . (int) $stats['count'] . '</span></div>';
}

require get_template_directory() . '/inc/customizer.php';

require get_template_directory() . '/inc/seo.php';

require get_template_directory() . '/inc/pexels.php';
require get_template_directory() . '/inc/pexels-admin.php';

require get_template_directory() . '/inc/login.php';
