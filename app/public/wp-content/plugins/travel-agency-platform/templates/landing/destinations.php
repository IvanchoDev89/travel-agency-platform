<?php
// Destinations section.
$settings = $sec['settings'] ?? [];
$show_map = !isset($settings['show_map']) || $settings['show_map'];
?>
<section class="tap-landing-destinations">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php esc_html_e('Explora destinos', 'travel-agency-platform'); ?></h2>
        <?php echo do_shortcode('[tap_destinations_map]'); ?>
    </div>
</section>