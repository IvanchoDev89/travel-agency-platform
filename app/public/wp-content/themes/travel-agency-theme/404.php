<?php get_header(); ?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php _e('Page Not Found', 'travel-agency-platform'); ?></h1>
    </div>
</div>

<div class="entry-content" style="text-align: center; padding: 60px 20px;">
    <p><?php _e('The page you are looking for might have been removed or is temporarily unavailable.', 'travel-agency-platform'); ?></p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="tap-btn tap-btn-primary"><?php _e('Go Home', 'travel-agency-platform'); ?></a>
</div>

<?php get_footer(); ?>
