<?php
/**
 * Template Name: Search Results
 */
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php _e('Search Results', 'travel-agency-platform'); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php echo do_shortcode('[tap_search_results per_page="20"]'); ?>
</div>

<?php get_footer(); ?>
