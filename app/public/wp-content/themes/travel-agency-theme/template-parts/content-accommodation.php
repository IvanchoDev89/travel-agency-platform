<article <?php post_class('tap-service-card'); ?>>
    <div class="tap-card-thumb">
        <?php if (has_post_thumbnail()): ?>
            <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
        <?php endif; ?>
        <?php echo tap_fav_button(); ?>
    </div>
    <div class="tap-service-card-body">
        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        <?php echo tap_card_rating(get_the_ID(), 'tap_accommodation'); ?>
        <?php $price = get_post_meta(get_the_ID(), '_tap_acc_price_per_night', true); ?>
        <?php if ($price): ?>
            <p class="tap-price"><?php echo esc_html(TAP_Currency::fmt(floatval($price))); ?> / <?php _e('night', 'travel-agency-theme'); ?></p>
        <?php endif; ?>
        <p><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
        <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php _e('View Details', 'travel-agency-theme'); ?></a>
    </div>
</article>
