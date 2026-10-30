<?php
// Video section.
$settings = $sec['settings'] ?? [];
$video_url = $settings['video_url'] ?? '';
$title = $settings['title'] ?? '';
$poster = $settings['poster'] ?? '';
?>
<?php if ($video_url): ?>
<section class="tap-landing-video">
    <div class="tap-container">
        <?php if ($title): ?><h2 class="tap-section-title"><?php echo esc_html($title); ?></h2><?php endif; ?>
        <div class="tap-video-wrap">
            <video controls preload="metadata" <?php if ($poster): ?>poster="<?php echo esc_url($poster); ?>"<?php endif; ?>>
                <source src="<?php echo esc_url($video_url); ?>" type="video/mp4">
            </video>
        </div>
    </div>
</section>
<?php endif; ?>