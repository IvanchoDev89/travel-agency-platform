<?php
// Featured services section.
$settings = $sec['settings'] ?? [];
$count = (int) ($settings['count'] ?? 6);
$mode = $settings['mode'] ?? 'auto';
$title = $settings['title'] ?? __('Servicios destacados', 'travel-agency-platform');
?>
<section class="tap-landing-featured">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php echo esc_html($title); ?></h2>
        <?php echo do_shortcode('[tap_featured count="' . (int) $count . '"]'); ?>
    </div>
</section>