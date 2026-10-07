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
    <?php get_template_part('template-parts/blog-index'); ?>
</div>

<?php get_footer(); ?>
