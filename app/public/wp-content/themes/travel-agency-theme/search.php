<?php get_header(); ?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php printf(__('Search Results for: %s', 'travel-agency-platform'), get_search_query()); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php if (have_posts()): ?>
        <div class="tap-services-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
            <?php while (have_posts()): the_post(); ?>
                <div class="tap-service-card">
                    <div class="tap-card-thumb">
                        <?php if (has_post_thumbnail()): ?>
                            <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
                        <?php endif; ?>
                        <?php echo tap_fav_button(); ?>
                    </div>
                    <div class="tap-service-card-body">
                        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                        <?php echo tap_card_rating(get_the_ID(), get_post_type()); ?>
                        <p><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
                        <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php _e('View Details', 'travel-agency-platform'); ?></a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        <?php the_posts_pagination(); ?>
    <?php else: ?>
        <p><?php _e('No results found. Try different keywords.', 'travel-agency-platform'); ?></p>
        <?php echo do_shortcode('[tap_search]'); ?>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
