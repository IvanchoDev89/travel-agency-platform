<?php
/**
 * Template Name: Checkout
 */
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php the_title(); ?></h1>
    </div>
</div>

<div class="entry-content">
    <div class="tap-container">
        <?php echo do_shortcode('[tap_checkout]'); ?>
    </div>
</div>

<?php get_footer(); ?>
