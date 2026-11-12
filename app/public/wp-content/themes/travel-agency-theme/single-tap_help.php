<?php
/**
 * Single help article template.
 */
defined('ABSPATH') || exit;
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <nav class="tap-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumb', 'travel-agency-platform'); ?>">
            <a href="<?php echo esc_url(home_url('/centro-de-soporte/')); ?>"><?php esc_html_e('Soporte', 'travel-agency-platform'); ?></a>
            <span class="tap-breadcrumb-sep">›</span>
            <?php
            $cats = get_the_terms(get_the_ID(), 'tap_help_category');
            if ($cats && !is_wp_error($cats)): ?>
                <a href="<?php echo esc_url(get_term_link($cats[0])); ?>"><?php echo esc_html($cats[0]->name); ?></a>
                <span class="tap-breadcrumb-sep">›</span>
            <?php endif; ?>
            <span class="tap-breadcrumb-current"><?php the_title(); ?></span>
        </nav>
    </div>
</div>

<div class="entry-content">
    <div class="tap-container">
        <div class="tap-support-article">
            <article class="tap-support-article-main">
                <header class="tap-support-article-header">
                    <h1><?php the_title(); ?></h1>
                    <p class="tap-support-article-meta">
                        <?php echo esc_html(sprintf(__('Última actualización: %s', 'travel-agency-platform'), get_the_modified_date())); ?>
                    </p>
                </header>
                <div class="tap-support-article-content">
                    <?php the_content(); ?>
                </div>

                <div class="tap-support-article-feedback">
                    <p><?php esc_html_e('¿Te fue útil este artículo?', 'travel-agency-platform'); ?></p>
                    <button type="button" class="tap-btn tap-btn-sm" data-helpful="1"><?php esc_html_e('Sí', 'travel-agency-platform'); ?></button>
                    <button type="button" class="tap-btn tap-btn-sm tap-btn-outline" data-helpful="0"><?php esc_html_e('No', 'travel-agency-platform'); ?></button>
                </div>
            </article>

            <aside class="tap-support-article-sidebar">
                <?php
                $cats = get_the_terms(get_the_ID(), 'tap_help_category');
                if ($cats && !is_wp_error($cats)):
                    $related = get_posts([
                        'post_type'      => 'tap_help',
                        'posts_per_page' => 8,
                        'post_status'    => 'publish',
                        'tax_query'      => [
                            [
                                'taxonomy' => 'tap_help_category',
                                'field'    => 'term_id',
                                'terms'    => $cats[0]->term_id,
                            ],
                        ],
                        'post__not_in'   => [get_the_ID()],
                        'orderby'        => 'menu_order',
                        'order'          => 'ASC',
                    ]);
                    if ($related):
                ?>
                <div class="tap-support-related">
                    <h3><?php echo esc_html(sprintf(__('Más en %s', 'travel-agency-platform'), $cats[0]->name)); ?></h3>
                    <ul>
                        <?php foreach ($related as $r): ?>
                            <li><a href="<?php echo esc_url(get_permalink($r)); ?>"><?php echo esc_html($r->post_title); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; endif; ?>
            </aside>
        </div>
    </div>
</div>

<?php get_footer(); ?>
