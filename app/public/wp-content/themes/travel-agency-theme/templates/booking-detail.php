<?php
/**
 * Template Name: Booking Detail / Voucher
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
        <?php echo do_shortcode('[tap_booking_detail]'); ?>
    </div>
</div>

<?php get_footer(); ?>