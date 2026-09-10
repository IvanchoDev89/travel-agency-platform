<?php
/**
 * Taxonomy landing template: tap_location — SEO-facing destination page.
 *
 * Hero (name + description), real listings for that destination, related
 * child destinations and a CTA to the itinerary builder. Meta/JSON-LD are
 * handled by the plugin's TAP_SEO (works on tax archives out of the box).
 */
get_header();

$term = get_queried_object();

/* Cover image: term meta cover, else first published listing thumbnail. */
function tap_dest_image($term_id) {
    $cover = get_term_meta((int) $term_id, '_tap_dest_cover', true);
    if ($cover) {
        return $cover;
    }
    $q = new WP_Query([
        'post_type'      => ['tap_tour', 'tap_accommodation', 'tap_transport', 'tap_boat', 'tap_package'],
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'tax_query'      => [['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => (int) $term_id]],
    ]);
    if ($q->have_posts()) {
        $thumb = get_the_post_thumbnail_url($q->posts[0], 'large');
        if ($thumb) {
            return $thumb;
        }
    }
    return '';
}

/* Parent path for breadcrumb-style subtitle */
$crumbs = [];
$node = $term;
while (is_object($node) && $node->parent > 0) {
    $parent = get_term($node->parent, 'tap_location');
    if (!$parent || is_wp_error($parent)) {
        break;
    }
    $crumbs[] = $parent->name;
    $node = $parent;
}
$crumbs = array_reverse($crumbs);
$term_image = tap_dest_image((int) $term->term_id);
$term_description = term_description($term);
$children = get_terms([
    'taxonomy'   => 'tap_location',
    'hide_empty' => false,
    'parent'     => (int) $term->term_id,
    'number'     => 9,
]);
?>

<div class="tap-page-header tap-dest-hero"<?php if ($term_image) : ?> style="background-image:url('<?php echo esc_url($term_image); ?>');"<?php endif; ?>>
    <div class="tap-container">
        <?php if ($crumbs) : ?>
            <p class="tap-dest-crumbs"><?php echo esc_html(implode(' / ', $crumbs)); ?></p>
        <?php endif; ?>
        <h1><?php echo esc_html($term->name); ?></h1>
        <?php if ($term_description) : ?>
            <div class="tap-dest-intro"><?php echo wp_kses_post(wpautop($term_description)); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="entry-content tap-container">

    <?php if ($term_description) : ?>
        <div class="tap-dest-seo">
            <?php echo wp_kses_post(wpautop($term_description)); ?>
        </div>
    <?php endif; ?>

    <h2 class="tap-dest-section"><?php
        printf(
            esc_html__('Experiencias en %s', 'travel-agency-platform'),
            esc_html($term->name)
        );
    ?></h2>

    <?php echo do_shortcode('[tap_services location="' . (int) $term->term_id . '" limit="12" columns="3"]'); ?>

    <?php if (!empty($children) && !is_wp_error($children)) : ?>
        <h2 class="tap-dest-section"><?php esc_html_e('Descubre otros rincones de la zona', 'travel-agency-platform'); ?></h2>
        <div class="tap-dest-grid">
            <?php foreach ($children as $child) :
                $child_image = tap_dest_image((int) $child->term_id);
                $count = (int) $child->count; ?>
                <a class="tap-dest-card" href="<?php echo esc_url(get_term_link($child)); ?>">
                    <?php if ($child_image) : ?>
                        <img src="<?php echo esc_url($child_image); ?>" alt="<?php echo esc_attr($child->name); ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="tap-dest-card-body">
                        <h3><?php echo esc_html($child->name); ?></h3>
                        <?php if ($count) :
                            printf('<p class="tap-dest-count">%s</p>', esc_html(sprintf(_n('%d experiencia', '%d experiencias', $count, 'travel-agency-platform'), $count)));
                        endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="tap-dest-cta">
        <h2><?php esc_html_e('Arma tu viaje a tu medida', 'travel-agency-platform'); ?></h2>
        <p><?php esc_html_e('Combina tours, alojamientos y transportes en un itinerario propio.', 'travel-agency-platform'); ?></p>
        <a class="tap-btn" href="<?php echo esc_url(get_permalink(get_page_by_path('armar-mi-viaje'))); ?>"><?php esc_html_e('Comenzar a planear', 'travel-agency-platform'); ?></a>
    </div>

</div>

<?php get_footer(); ?>