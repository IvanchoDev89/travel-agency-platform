<?php
/**
 * Template Name: Agency Dashboard
 */
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php the_title(); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php echo do_shortcode('[tap_dashboard]'); ?>
</div>

<?php get_footer(); ?>
