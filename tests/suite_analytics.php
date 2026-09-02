<?php
/**
 * suite_analytics.php — admin Analytics page composition (M5 + F-AN KPIs).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_analytics.php
 */
require __DIR__ . '/bootstrap.php';

// render the analytics admin page to an output buffer
ob_start();
TAP_Dashboard::analytics_page();
$html = ob_get_clean();

// KPIs from M5
foreach ([
    'Reservas', 'GMV', 'Comisiones', 'Ingresos', 'Suscripciones', 'Destacados',
] as $kpi) {
    tap_t_assert(strpos($html, $kpi) !== false, "analytics KPI card: {$kpi}");
}

// F-AN additions
tap_t_assert(strpos($html, 'Vistas') !== false, 'analytics shows views KPI');
tap_t_assert(strpos($html, 'Conversión') !== false || strpos($html, 'conversion') !== false, 'analytics shows conversion stat');

// exports available via page links
tap_t_assert(strpos($html, 'export=bookings') !== false, 'bookings CSV export link present');
tap_t_assert(strpos($html, 'export=summary') !== false, 'summary CSV export link present');

tap_t_finish();