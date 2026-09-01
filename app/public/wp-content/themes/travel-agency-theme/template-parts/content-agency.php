<article <?php post_class('tap-agency-card'); ?>>
    <div class="tap-card-thumb">
        <?php if (has_post_thumbnail()): ?>
            <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
        <?php endif; ?>
        <?php echo tap_fav_button(); ?>
    </div>
    <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
    <p><?php echo wp_trim_words(get_the_excerpt() ?: get_the_content(), 25); ?></p>
    <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php _e('View Profile', 'travel-agency-theme'); ?></a>
</article>
