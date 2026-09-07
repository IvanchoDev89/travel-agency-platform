<article <?php post_class('tap-service-card'); ?>>
    <div class="tap-card-thumb">
        <?php if (has_post_thumbnail()): ?>
            <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
        <?php endif; ?>
        <?php echo tap_fav_button(); ?>
    </div>
    <div class="tap-service-card-body">
        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        <?php echo tap_card_rating(get_the_ID(), 'tap_package'); ?>
        <?php $price = get_post_meta(get_the_ID(), '_tap_pkg_price', true); ?>
        <?php $duration = get_post_meta(get_the_ID(), '_tap_pkg_duration', true); ?>
        <?php if ($price): ?><p class="tap-price"><?php echo esc_html(TAP_Currency::fmt(floatval($price))); ?></p><?php endif; ?>
        <?php if ($duration): ?><p class="tap-duration"><?php echo esc_html($duration); ?></p><?php endif; ?>
        <p><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
        <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php _e('View Details', 'travel-agency-platform'); ?></a>
    </div>
</article>
