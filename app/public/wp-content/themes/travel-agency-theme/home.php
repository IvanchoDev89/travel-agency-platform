<?php
/**
 * Blog index (posts page). Used when a static front page is configured,
 * so it only covers the "Blog" posts index.
 */
get_header();

$blog_page = get_post((int) get_option('page_for_posts'));
$blog_bg = $blog_page ? get_the_post_thumbnail_url((int) $blog_page->ID, 'large') : '';
$blog_title = $blog_page ? $blog_page->post_title : __('Blog', 'travel-agency-platform');
?>
<header class="tap-page-header tap-blog-hero"<?php if ($blog_bg) : ?> style="background-image:url('<?php echo esc_url($blog_bg); ?>');"<?php endif; ?>>
    <div class="tap-container">
        <h1><?php echo esc_html($blog_title); ?></h1>
    </div>
</header>

<div class="entry-content tap-container">

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
                            if ($blog_cats) : ?> · <?php echo esc_html($blog_cats[0]->name);
                            endif; ?>
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
        <p><?php esc_html_e('Aún no hay publicaciones.', 'travel-agency-platform'); ?></p>
    <?php endif; ?>

</div>

<?php get_footer(); ?>