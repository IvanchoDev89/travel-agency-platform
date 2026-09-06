<?php
/**
 * Template Name: Search Results
 */
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php _e('Search Results', 'travel-agency-theme'); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php echo do_shortcode('[tap_search]'); ?>
    <div class="tap-search-results-container">
        <?php
        $keyword = sanitize_text_field($_GET['keyword'] ?? '');
        $type = sanitize_text_field($_GET['type'] ?? '');
        $check_in = sanitize_text_field($_GET['check_in'] ?? '');
        $check_out = sanitize_text_field($_GET['check_out'] ?? '');
        $guests = intval($_GET['guests'] ?? 1);

        $types = $type ? [$type] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $results = [];

        foreach ($types as $pt) {
            $args = [
                'post_type'      => $pt,
                'posts_per_page' => 20,
                'post_status'    => 'publish',
                's'              => $keyword,
                'meta_query'     => [
                    ['key' => '_tap_' . str_replace('tap_', '', $pt) . '_is_active', 'value' => '1'],
                ],
            ];

            $query = new WP_Query($args);
            while ($query->have_posts()) {
                $query->the_post();
                $results[] = $query->post;
            }
            wp_reset_postdata();
        }

        if (!empty($results)):
        ?>
            <p><strong><?php printf(__('%d results found', 'travel-agency-theme'), count($results)); ?></strong></p>
            <div class="tap-services-grid">
                <?php foreach ($results as $post): setup_postdata($post); ?>
                    <div class="tap-service-card">
                        <div class="tap-card-thumb">
                            <?php if (has_post_thumbnail()): ?>
                                <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
                            <?php endif; ?>
                            <?php echo tap_fav_button(); ?>
                        </div>
                        <div class="tap-service-card-body">
                            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                            <p class="tap-service-type-label"><?php echo esc_html(TAP_Post_Types::get_service_types()[get_post_type()] ?? get_post_type()); ?></p>
                            <?php echo tap_card_rating(get_the_ID(), get_post_type()); ?>
                            <p><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
                            <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php _e('View Details', 'travel-agency-theme'); ?></a>
                        </div>
                    </div>
                <?php endforeach; wp_reset_postdata(); ?>
            </div>
        <?php else: ?>
            <p><?php _e('No results found. Try different search terms.', 'travel-agency-theme'); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
