<?php
// Gallery section.
$settings = $sec['settings'] ?? [];
$images = $settings['images'] ?? [];
$title = $settings['title'] ?? __('Galería', 'travel-agency-platform');
?>
<?php if (!empty($images)): ?>
<section class="tap-landing-gallery">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php echo esc_html($title); ?></h2>
        <div class="tap-gallery-grid">
            <?php foreach ($images as $img_id): ?>
                <?php $url = wp_get_attachment_image_url((int) $img_id, 'large'); ?>
                <?php if ($url): ?>
                    <div class="tap-gallery-item">
                        <img src="<?php echo esc_url($url); ?>" alt="" loading="lazy" decoding="async">
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>