<?php
// Hero section.
$settings = $sec['settings'] ?? [];
$title = $settings['title'] ?? get_bloginfo('name');
$subtitle = $settings['subtitle'] ?? __('Explora alojamientos, tours y experiencias de agencias locales de confianza.', 'travel-agency-platform');
$bg = $settings['background'] ?? '';
$show_search = !isset($settings['show_search']) || $settings['show_search'];
?>
<section class="tap-landing-hero" <?php if ($bg): ?>style="background-image:url('<?php echo esc_url($bg); ?>');"<?php endif; ?>>
    <div class="tap-landing-hero-overlay"></div>
    <div class="tap-container">
        <h1><?php echo esc_html($title); ?></h1>
        <p><?php echo esc_html($subtitle); ?></p>
        <?php if ($show_search): ?>
            <?php echo do_shortcode('[tap_search]'); ?>
        <?php endif; ?>
    </div>
</section>