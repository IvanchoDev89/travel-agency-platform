<?php
/**
 * Taxonomy archive for tap_help_category.
 */
defined('ABSPATH') || exit;
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <nav class="tap-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumb', 'travel-agency-platform'); ?>">
            <a href="<?php echo esc_url(home_url('/centro-de-soporte/')); ?>"><?php esc_html_e('Soporte', 'travel-agency-platform'); ?></a>
            <span class="tap-breadcrumb-sep">›</span>
            <span class="tap-breadcrumb-current"><?php single_term_title(); ?></span>
        </nav>
        <h1><?php single_term_title(); ?></h1>
        <?php $term = get_queried_object(); if ($term && !is_wp_error($term) && $term->description): ?>
            <p><?php echo esc_html($term->description); ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="entry-content">
    <div class="tap-container">
        <div class="tap-support-category-list">
            <?php if (have_posts()): ?>
                <ul class="tap-support-article-list">
                    <?php while (have_posts()): the_post(); ?>
                        <li>
                            <a href="<?php echo esc_url(get_permalink()); ?>">
                                <span class="tap-support-article-list-title"><?php the_title(); ?></span>
                                <span class="tap-support-article-list-excerpt"><?php echo esc_html(get_the_excerpt()); ?></span>
                            </a>
                        </li>
                    <?php endwhile; ?>
                </ul>
                <?php the_posts_pagination(['prev_text' => '←', 'next_text' => '→']); ?>
            <?php else: ?>
                <p class="tap-empty"><?php esc_html_e('No hay artículos en esta categoría.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
