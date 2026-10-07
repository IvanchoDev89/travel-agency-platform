<?php
/**
 * Shared blog listing: pillar chips + post cards + pagination.
 * Used by home.php (posts page) and category.php (pillar archives).
 * Expects to run inside a wrapper container.
 */
$blog_page     = get_post((int) get_option('page_for_posts'));
$pillarSlugs   = ['destinos', 'planificacion', 'aventura', 'cultura-y-gastronomia', 'consejos-de-viaje'];
$pillarTerms   = [];
foreach ($pillarSlugs as $pillarSlug) {
    $pillarTerm = get_category_by_slug($pillarSlug);
    if ($pillarTerm) {
        $pillarTerms[] = $pillarTerm;
    }
}
$currentCat = is_category() ? (int) get_queried_object()->term_id : 0;
?>

<?php if ($pillarTerms) : ?>
<nav class="tap-blog-pillars" aria-label="<?php esc_attr_e('Temas', 'travel-agency-platform'); ?>">
    <?php if (!$currentCat) : ?>
        <a class="tap-blog-pillar is-active" href="<?php echo esc_url($blog_page ? get_permalink($blog_page) : home_url('/blog/')); ?>"><?php esc_html_e('Todo', 'travel-agency-platform'); ?></a>
    <?php endif; ?>
    <?php foreach ($pillarTerms as $pillarTerm) : ?>
        <a class="tap-blog-pillar<?php echo $currentCat === (int) $pillarTerm->term_id ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(get_category_link($pillarTerm)); ?>"><?php echo esc_html($pillarTerm->name); ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (have_posts()) : ?>
    <div class="tap-blog-grid">
        <?php while (have_posts()) : the_post(); ?>
            <article <?php post_class('tap-blog-card'); ?>>
                <?php if (has_post_thumbnail()) : ?>
                    <a class="tap-blog-thumb" href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
                <?php endif; ?>
                <div class="tap-blog-card-body">
                    <p class="tap-blog-meta">
                        <?php echo esc_html(get_the_date());
                        $blog_cats = get_the_category();
                        if ($blog_cats) : ?> · <span class="tap-blog-cat"><?php echo esc_html($blog_cats[0]->name); ?></span>
                        endif;
                        if (function_exists('tap_reading_time')) : ?> · <?php printf(esc_html__('%d min de lectura', 'travel-agency-platform'), (int) tap_reading_time()); endif; ?>
                    </p>
                    <h2 class="tap-blog-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p class="tap-blog-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 22)); ?></p>
                    <a class="tap-btn tap-btn-outline" href="<?php the_permalink(); ?>"><?php esc_html_e('Leer más', 'travel-agency-platform'); ?></a>
                </div>
            </article>
        <?php endwhile; ?>
    </div>

    <?php the_posts_pagination(['prev_text' => __('← Anteriores', 'travel-agency-platform'), 'next_text' => __('Siguientes →', 'travel-agency-platform')]); ?>
<?php else : ?>
    <p><?php esc_html_e('Aún no hay publicaciones en esta categoría.', 'travel-agency-platform'); ?></p>
<?php endif; ?>
