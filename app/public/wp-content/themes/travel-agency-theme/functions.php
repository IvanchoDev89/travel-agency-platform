<?php
defined('ABSPATH') || exit;

define('TAT_VERSION', '2.0.0');

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

function travel_agency_theme_assets() {
    $plugin_url = content_url('plugins/travel-agency-platform/');
    $theme_uri  = get_template_directory_uri();

    wp_enqueue_style('tap-tokens', $plugin_url . 'assets/css/tokens.css', [], TAT_VERSION);
    wp_enqueue_style('tap-public', $plugin_url . 'assets/css/public.css', [], TAT_VERSION);
    wp_enqueue_style('travel-agency-theme-style', get_stylesheet_uri(), ['tap-public'], TAT_VERSION);

    wp_enqueue_script('travel-agency-theme-main', $theme_uri . '/assets/js/main.js', ['jquery'], TAT_VERSION, true);
    wp_localize_script('travel-agency-theme-main', 'tat_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('tap_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'travel_agency_theme_assets');

class TAT_Nav_Walker extends Walker_Nav_Menu {
    public function start_lvl(&$output, $depth = 0, $args = null) {
        $indent = str_repeat("\t", $depth);
        $output .= "\n$indent<ul class=\"sub-menu\" data-depth=\"$depth\">\n";
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
            $item_output .= '<button class="submenu-toggle" aria-label="' . esc_attr__('Toggle submenu', 'travel-agency-platform') . '">';
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
    if (is_singular(['tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'])) {
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
