<?php get_header(); ?>

<div class="tap-page-header">
    <div class="tap-container">
        <?php
        $post_type = (string) get_query_var('post_type');
        if (!is_post_type_archive() && is_tax('tap_location')) {
            $post_type = '';
        }
        $type_labels = [
            'tap_accommodation' => __('Accommodations', 'travel-agency-platform'),
            'tap_tour'          => __('Tours', 'travel-agency-platform'),
            'tap_transport'     => __('Transports', 'travel-agency-platform'),
            'tap_car_rental'    => __('Car Rentals', 'travel-agency-platform'),
            'tap_boat'          => __('Boats', 'travel-agency-platform'),
            'tap_package'       => __('Packages', 'travel-agency-platform'),
            'tap_agency'        => __('Agencies', 'travel-agency-platform'),
        ];
        $title = isset($type_labels[$post_type]) ? $type_labels[$post_type] : wp_strip_all_tags(get_the_archive_title());
        $is_tap_type_archive = isset($type_labels[$post_type]) || 'tap_equipment' === $post_type;
        $is_custom_tax       = is_tax() && !is_category() && !is_tag();
        $is_blog_archive     = !$is_tap_type_archive && !$is_custom_tax;

        // Active destination filter (?destino=slug), set from a destination ficha.
        $dest_term = null;
        $dest_slug = sanitize_title(wp_unslash($_GET['destino'] ?? ''));
        if ($dest_slug && taxonomy_exists('tap_location')) {
            $maybe = get_term_by('slug', $dest_slug, 'tap_location');
            if ($maybe instanceof WP_Term) {
                $dest_term = $maybe;
            }
        }
        ?>
        <h1><?php echo esc_html($title); ?></h1>
        <?php if ($dest_term): ?>
            <p class="tap-archive-filter">
                <?php esc_html_e('Destino:', 'travel-agency-platform'); ?>
                <strong><?php echo esc_html($dest_term->name); ?></strong>
                <a href="<?php echo esc_url(remove_query_arg('destino')); ?>"><?php esc_html_e('Quitar filtro', 'travel-agency-platform'); ?></a>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="entry-content<?php echo $is_blog_archive ? ' tap-container' : ''; ?>">
    <?php
    if ($post_type === 'tap_agency') {
        echo do_shortcode('[tap_agencies]');
    } elseif ($is_blog_archive) {
        get_template_part('template-parts/blog-index');
    } else {
        echo do_shortcode('[tap_services type="' . esc_attr($post_type) . '" limit="12"]');
    }
    ?>
</div>

<?php get_footer(); ?>
