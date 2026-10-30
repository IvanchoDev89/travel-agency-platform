<?php
// CTA section.
$settings = $sec['settings'] ?? [];
$title = $settings['title'] ?? __('¿Listo para tu próximo viaje?', 'travel-agency-platform');
$desc = $settings['description'] ?? __('Contáctanos y te ayudamos a planificar una experiencia inolvidable.', 'travel-agency-platform');
$btn_text = $settings['button_text'] ?? __('Contactar', 'travel-agency-platform');
$btn_url = $settings['button_url'] ?? home_url('/contacto/');
?>
<section class="tap-landing-cta">
    <div class="tap-container">
        <h2><?php echo esc_html($title); ?></h2>
        <p><?php echo esc_html($desc); ?></p>
        <a href="<?php echo esc_url($btn_url); ?>" class="tap-btn tap-btn-primary tap-btn-lg"><?php echo esc_html($btn_text); ?></a>
    </div>
</section>