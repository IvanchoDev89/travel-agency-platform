<?php
/**
 * Blog pillar (category) archive. Uses the shared blog listing so pillar
 * pages render posts — not the service shortcode like the old archive.php.
 */
get_header();

$current_term = get_queried_object();
$term_name    = ($current_term instanceof WP_Term) ? $current_term->name : get_the_archive_title();
$term_desc    = ($current_term instanceof WP_Term) ? term_description($current_term) : '';
?>

<header class="tap-page-header">
    <div class="tap-container">
        <h1><?php echo esc_html($term_name); ?></h1>
        <?php if ($term_desc) : ?>
            <div class="tap-blog-term-desc"><?php echo wp_kses_post($term_desc); ?></div>
        <?php endif; ?>
    </div>
</header>

<div class="entry-content tap-container">
    <?php get_template_part('template-parts/blog-index'); ?>
</div>

<?php get_footer(); ?>
