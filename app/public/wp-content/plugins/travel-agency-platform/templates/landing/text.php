<?php
// Text section.
$settings = $sec['settings'] ?? [];
$title = $settings['title'] ?? '';
$content = $settings['content'] ?? '';
$align = $settings['align'] ?? 'left';
?>
<?php if ($title || $content): ?>
<section class="tap-landing-text" style="text-align:<?php echo esc_attr($align); ?>;">
    <div class="tap-container">
        <?php if ($title): ?><h2 class="tap-section-title"><?php echo esc_html($title); ?></h2><?php endif; ?>
        <?php if ($content): ?><div class="tap-landing-text-body"><?php echo wp_kses_post($content); ?></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>