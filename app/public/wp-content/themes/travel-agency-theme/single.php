<?php get_header(); ?>

<?php while (have_posts()): the_post(); ?>
    <?php $post_type = get_post_type(); ?>
    <?php if (in_array($post_type, ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'])): ?>
        <div class="entry-content">
            <?php echo do_shortcode('[tap_service_detail id="' . get_the_ID() . '"]'); ?>
        </div>
    <?php elseif ($post_type === 'tap_agency'): ?>
        <div class="entry-content">
            <?php echo do_shortcode('[tap_agency_detail id="' . get_the_ID() . '"]'); ?>
        </div>
    <?php elseif ($post_type === 'post'): ?>
        <?php
        $postCats   = get_the_category();
        $postCat    = $postCats ? $postCats[0] : null;
        $blogUrl    = get_permalink((int) get_option('page_for_posts')) ?: home_url('/blog/');
        $readTime   = function_exists('tap_reading_time') ? tap_reading_time() : 0;
        $relatedQ   = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'post__not_in'   => [get_the_ID()],
            'orderby'        => 'rand',
            'category__in'   => $postCat ? [$postCat->term_id] : [],
            'ignore_sticky_posts' => true,
        ]);
        if (!$relatedQ->have_posts()) {
            wp_reset_postdata();
            $relatedQ = new WP_Query([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 3,
                'post__not_in'   => [get_the_ID()],
                'orderby'         => 'date',
                'ignore_sticky_posts' => true,
            ]);
        }
        ?>
        <div class="tap-container">
            <nav class="tap-breadcrumbs tap-post-breadcrumbs" aria-label="<?php esc_attr_e('Migas de pan', 'travel-agency-platform'); ?>">
                <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Inicio', 'travel-agency-platform'); ?></a>
                <span aria-hidden="true">/</span>
                <a href="<?php echo esc_url($blogUrl); ?>"><?php esc_html_e('Guías de viaje', 'travel-agency-platform'); ?></a>
                <?php if ($postCat): ?>
                    <span aria-hidden="true">/</span>
                    <a href="<?php echo esc_url(get_category_link($postCat)); ?>"><?php echo esc_html($postCat->name); ?></a>
                <?php endif; ?>
                <span aria-hidden="true">/</span>
                <span aria-current="page"><?php the_title(); ?></span>
            </nav>

            <article <?php post_class('tap-post-article'); ?>>
                <header class="tap-post-header">
                    <?php if ($postCat): ?>
                        <a class="tap-post-badge" href="<?php echo esc_url(get_category_link($postCat)); ?>"><?php echo esc_html($postCat->name); ?></a>
                    <?php endif; ?>
                    <h1><?php the_title(); ?></h1>
                    <p class="tap-post-meta">
                        <span><?php echo esc_html(get_the_author()); ?></span>
                        <span aria-hidden="true">·</span>
                        <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                        <?php if (get_the_modified_date('U') !== get_the_date('U')): ?>
                            <span aria-hidden="true">·</span>
                            <span><?php printf(esc_html__('Actualizado el %s', 'travel-agency-platform'), esc_html(get_the_modified_date())); ?></span>
                        <?php endif; ?>
                        <?php if ($readTime): ?>
                            <span aria-hidden="true">·</span>
                            <span><?php printf(esc_html__('%d min de lectura', 'travel-agency-platform'), (int) $readTime); ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if (has_post_thumbnail()): ?>
                        <div class="tap-post-hero"><?php the_post_thumbnail('large'); ?></div>
                    <?php endif; ?>
                </header>

                <div class="entry-content tap-post-content">
                    <?php the_content(); ?>
                </div>
            </article>

            <!-- Author box -->
            <div class="tap-post-author">
                <div class="tap-post-author-avatar"><?php echo get_avatar(get_the_author_meta('ID'), 64); ?></div>
                <div class="tap-post-author-info">
                    <strong><?php echo esc_html(get_the_author()); ?></strong>
                    <?php if (get_the_author_meta('description')): ?>
                        <p><?php echo esc_html(get_the_author_meta('description')); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('Autor de guías de viaje en Travel Agency Platform.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sharing -->
            <div class="tap-post-share">
                <span><?php esc_html_e('Compartir:', 'travel-agency-platform'); ?></span>
                <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(get_permalink()); ?>&text=<?php echo urlencode(get_the_title()); ?>" target="_blank" rel="noopener" class="tap-post-share-btn">X</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(get_permalink()); ?>" target="_blank" rel="noopener" class="tap-post-share-btn">Facebook</a>
                <a href="https://wa.me/?text=<?php echo urlencode(get_the_title() . ' ' . get_permalink()); ?>" target="_blank" rel="noopener" class="tap-post-share-btn">WhatsApp</a>
                <button type="button" class="tap-post-share-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js(get_permalink()); ?>');this.textContent='<?php echo esc_js(__('Copiado!', 'travel-agency-platform')); ?>';"><?php esc_html_e('Copiar link', 'travel-agency-platform'); ?></button>
            </div>

            <!-- Newsletter CTA -->
            <div class="tap-post-newsletter">
                <h3><?php esc_html_e('Recibe guías de viaje en tu email', 'travel-agency-platform'); ?></h3>
                <p><?php esc_html_e('Suscríbete para recibir las mejores guías, tips y ofertas de viaje.', 'travel-agency-platform'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-post-newsletter-form">
                    <input type="hidden" name="action" value="tap_lead_form">
                    <input type="email" name="email" placeholder="<?php esc_attr_e('Tu email', 'travel-agency-platform'); ?>" required>
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-sm"><?php esc_html_e('Suscribirme', 'travel-agency-platform'); ?></button>
                </form>
            </div>

            <?php if ($relatedQ->have_posts()): ?>
            <aside class="tap-post-related">
                <h2><?php esc_html_e('Seguí explorando', 'travel-agency-platform'); ?></h2>
                <div class="tap-post-related-grid">
                    <?php while ($relatedQ->have_posts()): $relatedQ->the_post(); ?>
                        <a class="tap-post-related-card" href="<?php the_permalink(); ?>">
                            <?php if (has_post_thumbnail()): ?>
                                <span class="tap-post-related-thumb"><?php the_post_thumbnail('medium_large'); ?></span>
                            <?php endif; ?>
                            <span class="tap-post-related-title"><?php the_title(); ?></span>
                            <span class="tap-post-related-meta">
                                <?php echo esc_html(get_the_date()); ?>
                                <?php if (function_exists('tap_reading_time')): ?> · <?php printf(esc_html__('%d min', 'travel-agency-platform'), (int) tap_reading_time()); ?><?php endif; ?>
                            </span>
                        </a>
                    <?php endwhile; ?>
                </div>
            </aside>
            <?php endif; ?>
            <?php wp_reset_postdata(); ?>

            <p class="tap-post-back"><a href="<?php echo esc_url($blogUrl); ?>">← <?php esc_html_e('Todas las guías de viaje', 'travel-agency-platform'); ?></a></p>
        </div>
    <?php else: ?>
        <div class="tap-page-header">
            <div class="tap-container">
                <h1><?php the_title(); ?></h1>
            </div>
        </div>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
    <?php endif; ?>
<?php endwhile; ?>

<?php get_footer(); ?>
