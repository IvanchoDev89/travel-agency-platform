<?php
// Newsletter section.
$settings = $sec['settings'] ?? [];
$title = $settings['title'] ?? __('Recibe ofertas y novedades', 'travel-agency-platform');
$desc = $settings['description'] ?? __('Suscríbete para recibir las mejores ofertas de viaje.', 'travel-agency-platform');
?>
<section class="tap-landing-newsletter">
    <div class="tap-container">
        <h2><?php echo esc_html($title); ?></h2>
        <p><?php echo esc_html($desc); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-landing-newsletter-form">
            <input type="hidden" name="action" value="tap_lead_form">
            <input type="email" name="email" placeholder="<?php esc_attr_e('Tu email', 'travel-agency-platform'); ?>" required>
            <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Suscribirme', 'travel-agency-platform'); ?></button>
        </form>
    </div>
</section>