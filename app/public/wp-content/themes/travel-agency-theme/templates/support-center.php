<?php
/**
 * Template Name: Support Center
 */
defined('ABSPATH') || exit;
get_header();
?>

<div class="entry-content">
    <div class="tap-container">
        <?php
        while (have_posts()): the_post();
            the_content();
        endwhile;
        ?>
    </div>
</div>

<?php get_footer(); ?>
