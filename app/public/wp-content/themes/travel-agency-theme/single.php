<?php get_header(); ?>

<?php while (have_posts()): the_post(); ?>
    <?php $post_type = get_post_type(); ?>
    <?php if (in_array($post_type, ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'])): ?>
        <div class="entry-content">
            <?php echo do_shortcode('[tap_service_detail id="' . get_the_ID() . '"]'); ?>
        </div>
    <?php elseif ($post_type === 'tap_agency'): ?>
        <div class="entry-content">
            <?php echo do_shortcode('[tap_agency_detail id="' . get_the_ID() . '"]'); ?>
        </div>
    <?php else: ?>
        <div class="tap-page-header">
            <div class="tap-container">
                <h1><?php the_title(); ?></h1>
            </div>
        </div>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
    <?php endif; ?>
<?php endwhile; ?>

<?php get_footer(); ?>
