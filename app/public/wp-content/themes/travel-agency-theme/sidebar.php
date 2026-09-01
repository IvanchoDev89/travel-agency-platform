<aside class="widget-area">
    <?php if (!dynamic_sidebar('sidebar-1')): ?>
        <div class="widget">
            <h3><?php _e('Search', 'travel-agency-theme'); ?></h3>
            <?php get_search_form(); ?>
        </div>
    <?php endif; ?>
</aside>
