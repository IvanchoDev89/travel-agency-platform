<?php
// Stats section.
$settings = $sec['settings'] ?? [];
$title = $settings['title'] ?? __('Números que nos respaldan', 'travel-agency-platform');
?>
<section class="tap-landing-stats">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php echo esc_html($title); ?></h2>
        <div class="tap-stats-grid">
            <?php
            $counts = class_exists('TAP_Dashboard') && method_exists('TAP_Dashboard', 'listing_counts') ? TAP_Dashboard::listing_counts() : [];
            $stats = [
                ['num' => $counts['total_services'] ?? 0, 'label' => __('Servicios', 'travel-agency-platform')],
                ['num' => count(TAP_Destinations::all_terms()), 'label' => __('Destinos', 'travel-agency-platform')],
            ];
            if (class_exists('TAP_Agency')) {
                $stats[] = ['num' => wp_count_posts('tap_agency')->publish, 'label' => __('Agencias', 'travel-agency-platform')];
            }
            foreach ($stats as $st):
            ?>
                <div class="tap-stat-card">
                    <span class="tap-stat-num"><?php echo esc_html(number_format_i18n($st['num'])); ?></span>
                    <span class="tap-stat-label"><?php echo esc_html($st['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>