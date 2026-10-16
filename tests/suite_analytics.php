<?php
/**
 * suite_analytics.php — admin Analytics page composition (M5 + F-AN KPIs).
 * Run: wp --path="<site>/app/public" eval-file tests/suite_analytics.php
 */
require __DIR__ . '/bootstrap.php';

// The analytics page is capability-gated; render it as an administrator.
$admin_id = tap_t_make_admin();
wp_set_current_user($admin_id);

// render the analytics admin page to an output buffer
ob_start();
TAP_Dashboard_Insights::analytics_page();
$html = ob_get_clean();

wp_set_current_user(0);

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

// booking funnel: rendered section + stage labels
tap_t_assert(strpos($html, 'Embudo de reservas') !== false, 'analytics renders booking funnel');
foreach (['Vistas de listados', 'Reservas creadas', 'Reservas confirmadas', 'Reservas pagadas', 'Conversión de paso'] as $stage_label) {
    tap_t_assert(strpos($html, $stage_label) !== false, "funnel stage: {$stage_label}");
}

// funnel data shape (derived from existing tables, no extra tracking)
$funnel = TAP_Analytics::booking_funnel();
tap_t_assert(count($funnel) === 4, 'funnel exposes 4 stages');
tap_t_assert($funnel[0]['key'] === 'views' && $funnel[3]['key'] === 'paid', 'funnel ordered views -> paid');
tap_t_assert($funnel[0]['rate'] === 0.0, 'first funnel stage has no step rate');
$valid = true;
foreach ($funnel as $stage) {
    if (!is_int($stage['value']) || $stage['value'] < 0 || !is_numeric($stage['rate'])) {
        $valid = false;
    }
}
tap_t_assert($valid, 'funnel stages carry non-negative integer counts + numeric rates');

if ($admin_id) {
    wp_delete_user($admin_id);
}

tap_t_finish();