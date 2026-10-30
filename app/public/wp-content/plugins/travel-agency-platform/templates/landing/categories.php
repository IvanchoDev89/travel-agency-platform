<?php
// Categories section.
$settings = $sec['settings'] ?? [];
$types = [
    'tap_accommodation' => ['label' => __('Hoteles y Estancias', 'travel-agency-platform'), 'icon' => '🏨', 'url' => home_url('/accommodation')],
    'tap_tour'          => ['label' => __('Tours', 'travel-agency-platform'), 'icon' => '🗺️', 'url' => home_url('/tour')],
    'tap_transport'     => ['label' => __('Transfers', 'travel-agency-platform'), 'icon' => '🚗', 'url' => home_url('/transport')],
    'tap_car_rental'    => ['label' => __('Alquiler de autos', 'travel-agency-platform'), 'icon' => '🚙', 'url' => home_url('/car-rental')],
    'tap_boat'          => ['label' => __('Barcos', 'travel-agency-platform'), 'icon' => '⛵', 'url' => home_url('/boat')],
    'tap_package'       => ['label' => __('Paquetes', 'travel-agency-platform'), 'icon' => '📦', 'url' => home_url('/package')],
    'tap_destination'   => ['label' => __('Destinos', 'travel-agency-platform'), 'icon' => '📍', 'url' => home_url('/destinos')],
];
$enabled_types = $settings['types'] ?? array_keys($types);
?>
<section class="tap-landing-categories">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php esc_html_e('¿Qué estás buscando?', 'travel-agency-platform'); ?></h2>
        <div class="tap-categories-grid">
            <?php foreach ($types as $key => $info): ?>
                <?php if (!in_array($key, $enabled_types, true)) continue; ?>
                <a href="<?php echo esc_url($info['url']); ?>" class="tap-category-card">
                    <div class="tap-category-icon"><?php echo esc_html($info['icon']); ?></div>
                    <span class="tap-category-name"><?php echo esc_html($info['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>