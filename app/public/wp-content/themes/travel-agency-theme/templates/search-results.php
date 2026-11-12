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
    <div class="tap-container">
        <?php echo do_shortcode('[tap_search_results per_page="20"]'); ?>
    </div>
</div>

<?php get_footer(); ?>
