<?php
// Blog section.
$settings = $sec['settings'] ?? [];
$count = (int) ($settings['count'] ?? 3);
$title = $settings['title'] ?? __('Últimas guías de viaje', 'travel-agency-platform');
?>
<section class="tap-landing-blog">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php echo esc_html($title); ?></h2>
        <?php
        $blog_query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if ($blog_query->have_posts()) {
            echo '<div class="tap-blog-grid">';
            while ($blog_query->have_posts()) {
                $blog_query->the_post();
                echo '<article class="tap-blog-card">';
                if (has_post_thumbnail()) {
                    echo '<a class="tap-blog-thumb" href="' . esc_url(get_permalink()) . '">' . get_the_post_thumbnail(get_the_ID(), 'medium') . '</a>';
                }
                echo '<div class="tap-blog-card-body">';
                echo '<p class="tap-blog-meta">' . esc_html(get_the_date());
                $cats = get_the_category();
                if ($cats) echo ' · <span class="tap-blog-cat">' . esc_html($cats[0]->name) . '</span>';
                if (function_exists('tap_reading_time')) echo ' · ' . (int) tap_reading_time() . ' min';
                echo '</p>';
                echo '<h2 class="tap-blog-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2>';
                echo '<p class="tap-blog-excerpt">' . esc_html(wp_trim_words(get_the_excerpt(), 20)) . '</p>';
                echo '<a class="tap-btn tap-btn-outline" href="' . esc_url(get_permalink()) . '">' . esc_html__('Leer más', 'travel-agency-platform') . '</a>';
                echo '</div></article>';
            }
            echo '</div>';
            wp_reset_postdata();
        }
        ?>
        <div style="text-align:center;margin-top:24px;">
            <a href="<?php echo esc_url(get_permalink((int) get_option('page_for_posts')) ?: home_url('/blog/')); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('Ver todas las guías', 'travel-agency-platform'); ?></a>
        </div>
    </div>
</section>