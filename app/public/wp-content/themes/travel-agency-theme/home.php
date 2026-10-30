<?php
/**
 * Blog index (posts page). Used when a static front page is configured,
 * so it only covers the "Blog" posts index.
 */
get_header();

$blog_page = get_post((int) get_option('page_for_posts'));
$blog_bg = $blog_page ? get_the_post_thumbnail_url((int) $blog_page->ID, 'large') : '';
$blog_title = $blog_page ? $blog_page->post_title : __('Blog', 'travel-agency-platform');
// Optional background video (first mp4 attachment). Configurable via _tap_blog_hero_video meta on the posts page.
$blog_video = '';
if ($blog_page) {
    $vid = (int) get_post_meta($blog_page->ID, '_tap_blog_hero_video', true);
    if (!$vid) {
        $vids = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'video/mp4', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids']);
        $vid = $vids ? (int) $vids[0] : 0;
    }
    if ($vid) {
        $blog_video = wp_get_attachment_url($vid);
        $poster = get_post_meta($blog_page->ID, '_thumbnail_id', true);
        if (!$poster) $poster = $vid;
        $poster_url = wp_get_attachment_image_url($poster, 'large') ?: $blog_bg;
        if ($poster_url) $blog_bg = $poster_url;
    }
}
?>
<header class="tap-page-header tap-blog-hero tap-blog-hero--video"<?php if ($blog_bg && !$blog_video) : ?> style="background-image:url('<?php echo esc_url($blog_bg); ?>');"<?php endif; ?>>
    <?php if ($blog_video) : ?>
        <video class="tap-blog-hero-video" autoplay muted loop playsinline preload="metadata" <?php if ($blog_bg) : ?>poster="<?php echo esc_url($blog_bg); ?>"<?php endif; ?>>
            <source src="<?php echo esc_url($blog_video); ?>" type="video/mp4">
        </video>
        <div class="tap-blog-hero-overlay"></div>
    <?php elseif ($blog_bg) : ?>
        <div class="tap-hero-bg"><img src="<?php echo esc_url($blog_bg); ?>" alt="" loading="eager" fetchpriority="high"></div>
        <div class="tap-hero-overlay"></div>
    <?php endif; ?>
    <div class="tap-hero-content">
        <div class="tap-container">
            <h1><?php echo esc_html($blog_title); ?></h1>
            <p><?php _e('Explore curated accommodations, tours, and travel experiences from trusted local agencies.', 'travel-agency-platform'); ?></p>
        </div>
    </div>
</header>

<div class="entry-content tap-container">
    <?php get_template_part('template-parts/blog-index'); ?>
</div>

<?php get_footer(); ?>
