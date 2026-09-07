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
        $title = isset($type_labels[$post_type]) ? $type_labels[$post_type] : get_the_archive_title();
        ?>
        <h1><?php echo esc_html($title); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php
    if ($post_type === 'tap_agency') {
        echo do_shortcode('[tap_agencies]');
    } else {
        echo do_shortcode('[tap_services type="' . $post_type . '" limit="12"]');
    }
    ?>
</div>

<?php get_footer(); ?>
