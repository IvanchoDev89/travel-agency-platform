<?php
/**
 * Generic single template for bookable services (tour, transport, car rental, boat, package).
 * Routed via template_include for tap_tour, tap_transport, tap_car_rental, tap_boat, tap_package.
 */
defined('ABSPATH') || exit;
get_header();

$pt        = get_post_type();
$id        = get_the_ID();
$type_info = TAP_Post_Types::get_service_types();
$type_label = $type_info[$pt] ?? ucfirst(str_replace('tap_', '', $pt));

if (!TAP_Approval::is_service_visible($id, $pt)) {
    get_sidebar();
    echo '<div class="tap-agency-pending"><p>' . esc_html__('Este servicio aún no está disponible públicamente.', 'travel-agency-platform') . '</p></div>';
    get_footer();
    return;
}

$agency_meta = [
    'tap_tour'       => '_tap_tour_agency_id',
    'tap_transport'  => '_tap_trans_agency_id',
    'tap_car_rental' => '_tap_car_agency_id',
    'tap_boat'       => '_tap_boat_agency_id',
    'tap_package'    => '_tap_pkg_agency_id',
    'tap_equipment'  => '_tap_eq_agency_id',
];
$price_meta = [
    'tap_tour'       => '_tap_tour_price_adult',
    'tap_transport'  => '_tap_trans_price',
    'tap_car_rental' => '_tap_car_price_per_day',
    'tap_boat'       => '_tap_boat_price_half',
    'tap_package'    => '_tap_pkg_price',
    'tap_equipment'  => '_tap_eq_price_day',
];
$unit_label = [
    'tap_tour'       => __('per adult', 'travel-agency-platform'),
    'tap_transport'  => '',
    'tap_car_rental' => __('per day', 'travel-agency-platform'),
    'tap_boat'       => '',
    'tap_package'    => __('per person', 'travel-agency-platform'),
    'tap_equipment'  => __('per day', 'travel-agency-platform'),
];

$price_key = $price_meta[$pt] ?? '';
$price     = $price_key ? floatval(get_post_meta($id, $price_key, true)) : 0;
$child_price = 'tap_tour' === $pt ? floatval(get_post_meta($id, '_tap_tour_price_child', true)) : 0;

$agency = null;
if (isset($agency_meta[$pt])) {
    $aid = get_post_meta($id, $agency_meta[$pt], true);
    if ($aid) $agency = get_post($aid);
}

$needs_out = 'tap_car_rental' === $pt;
$needs_guests = in_array($pt, ['tap_tour', 'tap_package'], true);
$needs_date = in_array($pt, ['tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_equipment'], true);

$badge = $agency ? __('Verificada', 'travel-agency-platform') : '';
?>

<div class="tap-page-header">
    <div class="tap-container">
        <div class="tap-svc-header">
            <span class="tap-badge tap-badge-primary"><?php echo esc_html($type_label); ?></span>
            <h1><?php the_title(); ?> <?php echo tap_fav_button($id, 'tap-fav-hero'); ?></h1>
            <?php if ($agency): ?>
                <p class="tap-agency-meta"><?php esc_html_e('Operada por', 'travel-agency-platform'); ?>
                    <a href="<?php echo esc_url(home_url('/agency-profile/?id=' . $agency->ID)); ?>"><strong><?php echo esc_html($agency->post_title); ?></strong></a>
                    <?php if ('1' === get_post_meta($agency->ID, '_tap_agency_verified', true)): ?>
                        <span class="tap-verified-badge" title="<?php esc_attr_e('Verified agency', 'travel-agency-platform'); ?>">&#10003;</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$dest_terms = wp_get_post_terms($id, 'tap_location');
$deepest = null;
$highest = -1;
if (is_array($dest_terms) && $dest_terms) {
    foreach ($dest_terms as $dt) {
        $dlv = class_exists('TAP_Destinations') ? TAP_Destinations::term_level($dt->term_id) : null;
        $dlv = $dlv !== null ? $dlv : 0;
        if ($dlv > $highest) {
            $highest = $dlv;
            $deepest = $dt;
        }
    }
}
if ($deepest && class_exists('TAP_Destinations')) {
    echo '<div class="tap-breadcrumb-meta"><nav class="tap-breadcrumb" aria-label="' . esc_attr__('Breadcrumb', 'travel-agency-platform') . '">' . esc_html(TAP_Destinations::breadcrumb($deepest->term_id)) . '</nav></div>';
}
?>

<div class="tap-container">
  <div class="tap-acc-layout">
    <div class="tap-acc-main">
      <?php if (has_post_thumbnail()): ?>
        <div class="tap-acc-gallery">
          <?php the_post_thumbnail('large'); ?>
        </div>
      <?php endif; ?>

      <div class="tap-acc-content">
        <h2><?php esc_html_e('About this service', 'travel-agency-platform'); ?></h2>
        <div class="tap-acc-description">
          <?php the_content(); ?>
        </div>
        <?php
        $specs = [];
        if ('tap_tour' === $pt) {
            foreach (['_tap_tour_duration' => __('Duración', 'travel-agency-platform'), '_tap_tour_departure_city' => __('Salida', 'travel-agency-platform'), '_tap_tour_meeting_point' => __('Punto de encuentro', 'travel-agency-platform')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_transport' === $pt) {
            foreach (['_tap_trans_from' => __('Desde', 'travel-agency-platform'), '_tap_trans_to' => __('Hasta', 'travel-agency-platform'), '_tap_trans_vehicle' => __('Vehículo', 'travel-agency-platform')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_car_rental' === $pt) {
            foreach (['_tap_car_type' => __('Tipo', 'travel-agency-platform'), '_tap_car_transmission' => __('Transmisión', 'travel-agency-platform'), '_tap_car_seats' => __('Asientos', 'travel-agency-platform')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_boat' === $pt) {
            foreach (['_tap_boat_capacity' => __('Capacidad', 'travel-agency-platform'), '_tap_boat_route' => __('Ruta', 'travel-agency-platform')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if (!empty($specs)):
        ?>
        <div class="tap-acc-facts">
          <?php foreach ($specs as [$label, $value]): ?>
            <div class="tap-acc-fact"><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html($value); ?></span></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ('tap_tour' === $pt): ?>
        <!-- Ecotourism badges -->
        <?php
        $eco_badges = [];
        if (get_post_meta($id, '_tap_tour_leave_no_trace', true)) $eco_badges[] = __('Leave No Trace', 'travel-agency-platform');
        if (get_post_meta($id, '_tap_tour_carbon_offset', true)) $eco_badges[] = __('Carbono compensado', 'travel-agency-platform');
        if (get_post_meta($id, '_tap_tour_local_community', true)) $eco_badges[] = __('Apoya comunidad local', 'travel-agency-platform');
        if (get_post_meta($id, '_tap_tour_wildlife', true)) $eco_badges[] = __('Observación de fauna', 'travel-agency-platform');
        if (get_post_meta($id, '_tap_tour_equipment_rental', true)) $eco_badges[] = __('Alquiler de equipo', 'travel-agency-platform');
        if ($eco_badges):
        ?>
        <div class="tap-tour-eco-badges">
          <?php foreach ($eco_badges as $b): ?>
            <span class="tap-tour-eco-badge">🌿 <?php echo esc_html($b); ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Meeting point + Map -->
        <?php
        $tlat = get_post_meta($id, '_tap_tour_lat', true);
        $tlng = get_post_meta($id, '_tap_tour_lng', true);
        $meeting = get_post_meta($id, '_tap_tour_meeting_point', true);
        if ($meeting || ($tlat && $tlng)):
        ?>
        <div class="tap-acc-section tap-tour-meeting">
          <h2><?php _e('Punto de encuentro', 'travel-agency-platform'); ?></h2>
          <?php if ($meeting): ?><p><?php echo esc_html($meeting); ?></p><?php endif; ?>
          <?php if ($tlat && $tlng): ?>
          <div id="tap-tour-map" style="height:320px;border-radius:8px;overflow:hidden;margin-top:12px;"></div>
          <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
          <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
          <script>
          (function(){
            var map = L.map('tap-tour-map').setView([<?php echo (float)$tlat; ?>, <?php echo (float)$tlng; ?>], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap'}).addTo(map);
            L.marker([<?php echo (float)$tlat; ?>, <?php echo (float)$tlng; ?>]).addTo(map).bindPopup(<?php echo wp_json_encode($meeting ?: get_the_title()); ?>);
          })();
          </script>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Inclusions -->
        <?php
        $includes = get_post_meta($id, '_tap_tour_includes', true);
        $excludes = get_post_meta($id, '_tap_tour_excludes', true);
        $what_bring = get_post_meta($id, '_tap_tour_what_bring', true);
        $equipment = get_post_meta($id, '_tap_tour_equipment', true);
        if ($includes || $excludes || $what_bring || $equipment):
        ?>
        <div class="tap-acc-section tap-tour-inclusions">
          <h2><?php _e('Detalles del tour', 'travel-agency-platform'); ?></h2>
          <?php if ($includes): ?>
          <div class="tap-tour-inc"><h3><?php _e('Qué incluye', 'travel-agency-platform'); ?></h3><p><?php echo nl2br(esc_html($includes)); ?></p></div>
          <?php endif; ?>
          <?php if ($excludes): ?>
          <div class="tap-tour-inc"><h3><?php _e('Qué no incluye', 'travel-agency-platform'); ?></h3><p><?php echo nl2br(esc_html($excludes)); ?></p></div>
          <?php endif; ?>
          <?php if ($what_bring): ?>
          <div class="tap-tour-inc"><h3><?php _e('Qué traer', 'travel-agency-platform'); ?></h3><p><?php echo nl2br(esc_html($what_bring)); ?></p></div>
          <?php endif; ?>
          <?php if ($equipment): ?>
          <div class="tap-tour-inc"><h3><?php _e('Equipo provisto', 'travel-agency-platform'); ?></h3><p><?php echo nl2br(esc_html($equipment)); ?></p></div>
          <?php endif; ?>
          <?php if (get_post_meta($id, '_tap_tour_equipment_rental', true)): ?>
          <p><strong><?php _e('Alquiler de equipo disponible.', 'travel-agency-platform'); ?></strong></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Extras -->
        <?php
        $extras_html = TAP_Service_Extras::render_selector('tap_tour', $id);
        if ($extras_html):
        ?>
        <div class="tap-acc-section tap-tour-extras">
          <h2><?php _e('Servicios extra', 'travel-agency-platform'); ?></h2>
          <?php echo $extras_html; ?>
        </div>
        <?php endif; ?>

        <!-- Legals (collapsible) -->
        <?php
        $terms = get_post_meta($id, '_tap_tour_terms', true);
        $privacy = get_post_meta($id, '_tap_tour_privacy', true);
        $waiver = get_post_meta($id, '_tap_tour_waiver', true);
        $waiver_required = get_post_meta($id, '_tap_tour_waiver_required', true);
        if ($terms || $privacy || $waiver):
        ?>
        <div class="tap-acc-section tap-tour-legals">
          <h2><?php _e('Términos y condiciones', 'travel-agency-platform'); ?></h2>
          <?php if ($terms): ?>
          <details class="tap-tour-legal"><summary><?php _e('Términos y condiciones', 'travel-agency-platform'); ?></summary><div><?php echo wp_kses_post(nl2br($terms)); ?></div></details>
          <?php endif; ?>
          <?php if ($privacy): ?>
          <details class="tap-tour-legal"><summary><?php _e('Política de privacidad', 'travel-agency-platform'); ?></summary><div><?php echo wp_kses_post(nl2br($privacy)); ?></div></details>
          <?php endif; ?>
          <?php if ($waiver): ?>
          <details class="tap-tour-legal"><summary><?php _e('Waiver (descargo de responsabilidad)', 'travel-agency-platform'); ?><?php if ($waiver_required): ?> *<?php endif; ?></summary><div><?php echo wp_kses_post(nl2br($waiver)); ?></div></details>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ('tap_tour' === $pt): ?>
        <!-- Availability calendar (same design as accommodations) -->
        <?php
        $cal_month = isset($_GET['cal_month']) ? sanitize_text_field(wp_unslash($_GET['cal_month'])) : gmdate('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $cal_month)) $cal_month = gmdate('Y-m');
        list($cal_y, $cal_m) = explode('-', $cal_month);
        $cal_y = (int) $cal_y; $cal_m = (int) $cal_m;
        $first_day = mktime(0, 0, 0, $cal_m, 1, $cal_y);
        $days_in_month = (int) date('t', $first_day);
        $start_dow = (int) date('w', $first_day);
        $prev_month = date('Y-m', strtotime('-1 month', $first_day));
        $next_month = date('Y-m', strtotime('+1 month', $first_day));
        $month_label = date_i18n('F Y', $first_day);

        $from = sprintf('%04d-%02d-01', $cal_y, $cal_m);
        $to = sprintf('%04d-%02d-%02d', $cal_y, $cal_m, $days_in_month);
        $month_slots = TAP_Tour_Availability::slots_for('tap_tour', $id, $from, $to);
        $slots_by_date = [];
        foreach ($month_slots as $sl) {
            $slots_by_date[$sl->date][] = $sl;
        }
        ?>
        <div class="tap-acc-section tap-avcal-wrap" data-tour="<?php echo esc_attr($id); ?>">
          <h2><?php _e('Availability & Prices', 'travel-agency-platform'); ?></h2>
          <div class="tap-avcal-header">
            <div class="tap-avcal-nav">
              <a href="<?php echo esc_url(add_query_arg('cal_month', $prev_month)); ?>" class="tap-avcal-nav-btn" data-dir="-1">&larr;</a>
              <span class="tap-avcal-label"><?php echo esc_html($month_label); ?></span>
              <a href="<?php echo esc_url(add_query_arg('cal_month', $next_month)); ?>" class="tap-avcal-nav-btn" data-dir="1">&rarr;</a>
            </div>
            <div class="tap-avcal-legend">
              <span><span class="tap-avcal-swatch available"></span> <?php echo esc_html__('Disponible', 'travel-agency-platform'); ?></span>
              <span><span class="tap-avcal-swatch blocked"></span> <?php echo esc_html__('Bloqueado', 'travel-agency-platform'); ?></span>
              <span><span class="tap-avcal-swatch full"></span> <?php echo esc_html__('Completo', 'travel-agency-platform'); ?></span>
            </div>
          </div>
          <div class="tap-avcal-grid">
            <?php
            $weekdays = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
            foreach ($weekdays as $wd) echo '<div class="tap-avcal-weekday">' . esc_html($wd) . '</div>';
            for ($p = 0; $p < $start_dow; $p++) echo '<div class="tap-avcal-day disabled"></div>';
            for ($d = 1; $d <= $days_in_month; $d++) {
                $date = sprintf('%04d-%02d-%02d', $cal_y, $cal_m, $d);
                $day_slots = isset($slots_by_date[$date]) ? $slots_by_date[$date] : [];
                $is_blocked = false; $has_avail = false; $total_cap = 0; $total_booked = 0;
                foreach ($day_slots as $sl) {
                    if ($sl->is_blocked) $is_blocked = true;
                    else { $has_avail = true; $total_cap += (int)$sl->capacity ?: 999; $total_booked += (int)$sl->booked; }
                }
                $cls = 'tap-avcal-day';
                if ($is_blocked && !$has_avail) $cls .= ' blocked';
                elseif ($has_avail && $total_booked >= $total_cap) $cls .= ' full';
                elseif ($has_avail) $cls .= ' available';
                else $cls .= ' disabled';
                $today = gmdate('Y-m-d');
                if ($date === $today) $cls .= ' today';
                $min_price = 0;
                foreach ($day_slots as $sl) { if (!$sl->is_blocked && $sl->price !== null) { if ($min_price === 0 || (float)$sl->price < $min_price) $min_price = (float)$sl->price; } }
                ?>
                <div class="<?php echo esc_attr($cls); ?>" data-date="<?php echo esc_attr($date); ?>">
                    <span class="tap-avcal-num"><?php echo (int) $d; ?></span>
                    <?php if ($day_slots): ?>
                        <?php foreach ($day_slots as $sl): ?>
                            <div class="tap-avcal-slot">
                                <?php if ($sl->time_slot): ?><span class="tap-avcal-time"><?php echo esc_html(substr($sl->time_slot,0,5)); ?></span><?php endif; ?>
                                <?php if ($sl->label): ?><span class="tap-avcal-slotlabel"><?php echo esc_html($sl->label); ?></span><?php endif; ?>
                                <?php if (!$sl->is_blocked): ?>
                                    <span class="tap-avcal-cap"><?php echo (int)$sl->booked; ?>/<?php echo (int)$sl->capacity ?: '∞'; ?></span>
                                <?php else: ?>
                                    <span class="tap-avcal-blocked">🚫</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($min_price > 0): ?>
                            <span class="tap-avcal-price"><?php echo esc_html(TAP_Currency::fmt0($min_price)); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php } ?>
          </div>
        </div>
        <?php endif; // tap_tour ?>
        <style>
        .tap-tour-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:8px;}
        .tap-tour-cal-month{font-size:1.1rem;color:var(--tap-neutral-800,#1f2937);}
        .tap-tour-cal-legend{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;font-size:12px;color:var(--tap-neutral-500,#6b7280);}
        .tap-tour-cal-swatch{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:middle;}
        .tap-tour-cal-swatch--avail{background:#10b981;}
        .tap-tour-cal-swatch--blocked{background:#ef4444;}
        .tap-tour-cal-swatch--full{background:#f59e0b;}
        .tap-tour-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
        .tap-tour-cal-wd{text-align:center;font-size:11px;font-weight:700;color:var(--tap-neutral-500,#6b7280);padding:4px 0;}
        .tap-tour-cal-day{min-height:70px;border:1px solid var(--tap-border,#e5e7eb);border-radius:6px;padding:4px;font-size:11px;background:#fff;}
        .tap-tour-cal-day--empty{border:none;background:transparent;}
        .tap-tour-cal-day--avail{border-color:#10b981;background:#f0fdf4;}
        .tap-tour-cal-day--blocked{border-color:#ef4444;background:#fef2f2;}
        .tap-tour-cal-day--full{border-color:#f59e0b;background:#fffbeb;}
        .tap-tour-cal-day--none{background:#f9fafb;}
        .tap-tour-cal-day--today{box-shadow:0 0 0 2px #2563eb;}
        .tap-tour-cal-num{display:block;font-weight:700;font-size:13px;margin-bottom:2px;}
        .tap-tour-cal-slots{display:flex;flex-direction:column;gap:2px;}
        .tap-tour-cal-slot{display:flex;flex-wrap:wrap;gap:3px;align-items:center;font-size:10px;padding:2px 4px;background:rgba(0,0,0,.04);border-radius:3px;}
        .tap-tour-cal-slot.is-blocked{background:#fee2e2;text-decoration:line-through;}
        .tap-tour-cal-time{font-weight:600;}
        .tap-tour-cal-label{color:var(--tap-neutral-500,#6b7280);}
        .tap-tour-cal-cap{margin-left:auto;font-weight:600;}
        .tap-tour-cal-price{color:#047857;font-weight:700;}
        .tap-tour-cal-blocked{margin-left:auto;}
        @media (max-width:600px){.tap-tour-cal-day{min-height:50px;font-size:10px;}.tap-tour-cal-slot{font-size:9px;}}
        </style>
        <?php endif; // tap_tour ?>

        <?php if ('tap_tour' === $pt): ?>
        <?php
        $_tour_slots_list = class_exists('TAP_Tour_Availability') ? TAP_Tour_Availability::slots_for('tap_tour', $id) : [];
        $_by_date = [];
        foreach ($_tour_slots_list as $_tsl) {
            if ($_tsl->is_blocked) continue;
            $_by_date[$_tsl->date][] = $_tsl;
        }
        ?>
        <script>
        (function(){
            var slotsData = <?php echo wp_json_encode($_by_date, JSON_UNESCAPED_UNICODE); ?>;
            var dateSel = document.getElementById('bw-tour-date');
            var slotSel = document.getElementById('bw-tour-slot');
            var slotId = document.getElementById('bw-slot-id');
            var checkIn = document.getElementById('bw-check-in');
            var checkOut = document.getElementById('bw-check-out');
            if (!dateSel || !slotSel) return;
            dateSel.addEventListener('change', function(){
                var d = dateSel.value;
                slotSel.innerHTML = '<option value=""><?php echo esc_js(__('— Selecciona horario —', 'travel-agency-platform')); ?></option>';
                slotSel.disabled = true;
                if (slotsData[d]) {
                    slotsData[d].forEach(function(sl){
                        var opt = document.createElement('option');
                        opt.value = sl.id;
                        var label = (sl.time_slot ? sl.time_slot.substring(0,5) : '') + (sl.label ? ' - ' + sl.label : '') + ' (' + sl.booked + '/' + (sl.capacity || '∞') + ')';
                        if (sl.is_blocked) label += ' BLOQUEADO';
                        opt.textContent = label;
                        opt.disabled = !!sl.is_blocked;
                        opt.dataset.price = sl.price || 0;
                        slotSel.appendChild(opt);
                    });
                    slotSel.disabled = false;
                }
                if (checkIn) checkIn.value = d;
                if (checkOut) checkOut.value = d;
            });
            slotSel.addEventListener('change', function(){
                if (slotId) slotId.value = slotSel.value;
                if (checkIn) checkIn.value = dateSel.value;
                if (checkOut) checkOut.value = dateSel.value;
                $('#bw-adults').trigger('change');
            });
        })();
        </script>
        <?php endif; ?>

        <?php get_template_part('templates/partials/service-reviews'); ?>
        <?php get_template_part('templates/partials/service-reviews'); ?>
      </div>
    </div>

    <div class="tap-acc-sidebar">
      <div class="tap-acc-booking-widget" id="tap-booking-widget">
        <div class="tap-bw-header">
          <span class="tap-bw-price-amount"><?php echo esc_html(TAP_Currency::fmt0($price)); ?></span>
          <span class="tap-bw-price-label"><?php echo esc_html($unit_label[$pt] ?? ''); ?></span>
        </div>

        <div class="tap-bw-body">
          <form id="tap-booking-form" class="tap-bw-form">
            <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
              <input type="text" name="tap_hp" tabindex="-1" autocomplete="off" value="">
            </div>
            <input type="hidden" name="service_type" value="<?php echo esc_attr($pt); ?>">
            <input type="hidden" name="service_id" value="<?php echo esc_attr($id); ?>">

            <?php if ($needs_date): ?>
            <?php if ('tap_tour' === $pt): ?>
            <input type="hidden" name="slot_id" id="bw-slot-id" value="">
            <div class="tap-bw-field">
              <label><?php esc_html_e('Fecha del tour', 'travel-agency-platform'); ?></label>
              <select name="bw_tour_date" id="bw-tour-date" class="tap-input" required>
                <option value=""><?php esc_html_e('— Selecciona fecha —', 'travel-agency-platform'); ?></option>
                <?php
                $tour_slots_list = class_exists('TAP_Tour_Availability') ? TAP_Tour_Availability::slots_for('tap_tour', $id) : [];
                $by_date = [];
                foreach ($tour_slots_list as $tsl) {
                    if ($tsl->is_blocked) continue;
                    $by_date[$tsl->date][] = $tsl;
                }
                foreach (array_keys($by_date) as $dt): ?>
                    <option value="<?php echo esc_attr($dt); ?>"><?php echo esc_html($dt); ?> (<?php echo count($by_date[$dt]); ?> horarios)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="tap-bw-field">
              <label><?php esc_html_e('Horario', 'travel-agency-platform'); ?></label>
              <select name="bw_tour_slot" id="bw-tour-slot" class="tap-input" required disabled>
                <option value=""><?php esc_html_e('— Selecciona fecha primero —', 'travel-agency-platform'); ?></option>
              </select>
            </div>
            <input type="hidden" name="check_in" id="bw-check-in" value="">
            <input type="hidden" name="check_out" id="bw-check-out" value="">
            <?php else: ?>
            <div class="tap-bw-dates <?php echo $needs_out ? '' : 'tap-bw-single'; ?>">
              <div class="tap-bw-field">
                <label><?php echo 'tap_car_rental' === $pt ? __('Recogida', 'travel-agency-platform') : __('Fecha', 'travel-agency-platform'); ?></label>
                <input type="date" name="check_in" id="bw-check-in" class="tap-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
              </div>
              <?php if ($needs_out): ?>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Devolución', 'travel-agency-platform'); ?></label>
                <input type="date" name="check_out" id="bw-check-out" class="tap-input" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($needs_guests): ?>
            <div class="tap-bw-guests">
              <div class="tap-bw-field">
                <label><?php esc_html_e('Adults', 'travel-agency-platform'); ?></label>
                <input type="number" name="adults" id="bw-adults" class="tap-input" value="1" min="1" max="20">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Children', 'travel-agency-platform'); ?></label>
                <input type="number" name="children" id="bw-children" class="tap-input" value="0" min="0" max="10">
              </div>
            </div>
            <?php endif; ?>

            <div class="tap-bw-guests tap-bw-guest-data">
              <div class="tap-bw-field tap-bw-field-full">
                <label><?php esc_html_e('Nombre del huésped principal', 'travel-agency-platform'); ?></label>
                <input type="text" name="guest_name" id="bw-guest-name" class="tap-input" autocomplete="name" placeholder="<?php esc_attr_e('Tu nombre', 'travel-agency-platform'); ?>" value="<?php echo esc_attr(wp_get_current_user()->display_name ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Email de contacto', 'travel-agency-platform'); ?></label>
                <input type="email" name="guest_email" id="bw-guest-email" class="tap-input" autocomplete="email" value="<?php echo esc_attr(wp_get_current_user()->user_email ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Teléfono', 'travel-agency-platform'); ?></label>
                <input type="tel" name="guest_phone" id="bw-guest-phone" class="tap-input" autocomplete="tel" placeholder="<?php esc_attr_e('Opcional', 'travel-agency-platform'); ?>">
              </div>
            </div>

            <div class="tap-bw-total" id="bw-total" style="display:none;">
              <div class="tap-bw-total-row tap-bw-fee-row" id="bw-fee" style="display:none;">
                <span><?php esc_html_e('Tarifa de servicio', 'travel-agency-platform'); ?></span>
                <span id="bw-fee-amount">$0</span>
              </div>
              <div class="tap-bw-total-row tap-bw-total-final">
                <span><?php esc_html_e('Total', 'travel-agency-platform'); ?></span>
                <span id="bw-total-amount">$0</span>
              </div>
              <div class="tap-capacity-note" id="bw-capacity" style="display:none;"></div>
            </div>

            <div class="tap-bw-actions tap-bw-consent">
              <label class="tap-check-label tap-privacy-consent">
                <input type="checkbox" name="tap_privacy_consent" value="1" required>
                <?php printf(__('Acepto el <a href="%s" target="_blank" rel="noopener">Aviso de Privacidad</a> y consiento el tratamiento de mis datos personales para gestionar esta solicitud.', 'travel-agency-platform'), esc_url(TAP_Privacy::policy_url())); ?>
              </label>
            </div>

            <div class="tap-bw-actions">
            <?php if ('tap_tour' === $pt && class_exists('TAP_Service_Extras')): ?>
            <div class="tap-bw-field tap-bw-field-full" style="margin-bottom:8px;">
                <?php echo TAP_Service_Extras::render_selector('tap_tour', $id); ?>
            </div>
            <?php endif; ?>
            <?php if ('tap_tour' === $pt && class_exists('TAP_Waiver') && TAP_Waiver::is_required('tap_tour', $id)): ?>
            <div class="tap-bw-field tap-bw-field-full" style="margin-bottom:8px;">
                <p style="font-size:12px;color:#6b7280;"><?php esc_html_e('Esta actividad requiere firmar un waiver. Se pedirá después de la reserva.', 'travel-agency-platform'); ?></p>
            </div>
            <?php endif; ?>
              <button type="submit" class="tap-btn tap-btn-primary tap-btn-block" id="bw-submit" disabled>
                <?php echo TAP_Booking::booking_mode($pt, $id) === 'request' ? esc_html__('Solicitar reserva', 'travel-agency-platform') : esc_html__('Book Now', 'travel-agency-platform'); ?>
              </button>
            </div>
          </form>

          <div class="tap-bw-footer">
            <p><?php esc_html_e('You won\'t be charged yet', 'travel-agency-platform'); ?></p>
          </div>
        </div>
        <?php if ($agency): ?>
          <?php echo do_shortcode('[tap_lead_form agency="' . (int) $agency->ID . '" service="' . (int) $id . '"]'); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(document).ready(function($) {
  var tapI18n = window.tapI18n || {};
  var tapSym = tap_ajax.currency_symbol + ' ';
  var tapDecimals = parseInt(tap_ajax.currency_decimals || '2', 10);
  var tapFull = false;
  var debounce = null;

  function checkFormReady() {
    var ready = true;
    var needsDate = <?php echo $needs_date ? 'true' : 'false'; ?>;
    if (needsDate && !$('#bw-check-in').val()) ready = false;
    if (tapFull) ready = false;
    $('#bw-submit').prop('disabled', !ready);
  }

  function calculateTotal() {
    var checkIn = $('#bw-check-in').val() || '';
    var checkOut = $('#bw-check-out').val() || '';
    var slotId = $('#bw-slot-id').val() || 0;
    $.post(tap_ajax.ajax_url, {
      action: 'tap_calculate_booking_total',
      nonce: tap_ajax.nonce,
      service_type: '<?php echo esc_js($pt); ?>',
      service_id: <?php echo (int) $id; ?>,
      check_in: checkIn,
      check_out: checkOut,
      adults: parseInt($('#bw-adults').val() || '1', 10),
      children: parseInt($('#bw-children').val() || '0', 10),
      slot_id: slotId
    }).done(function(res) {
      if (res && res.success) {
        $('#bw-total').show();
        var fee = Number(res.data.fee || 0);
        if (fee > 0) {
          $('#bw-fee-amount').text(tapSym + fee.toFixed(tapDecimals));
          $('#bw-fee').show();
        } else {
          $('#bw-fee').hide();
        }
        $('#bw-total-amount').text(tapSym + res.data.total.toFixed(tapDecimals));
        tapFull = false;
        $('#bw-capacity').hide().empty();
        if (res.data.capacity) {
          var cap = res.data.capacity;
          if (cap.remaining > 0) {
            $('#bw-capacity').show().removeClass('tap-full').text((tapI18n.capacityAvailable || 'Cupos disponibles: %s de %s').replace('%s', cap.remaining).replace('%s', cap.capacity));
          } else {
            tapFull = true;
            $('#bw-capacity').show().addClass('tap-full').text((tapI18n.capacityFull || 'Cupo completo para esta fecha (%s/%s). Elige otra fecha.').replace('%s', cap.capacity).replace('%s', cap.capacity));
          }
        }
        checkFormReady();
      }
    });
  }

  $('#bw-check-in, #bw-check-out, #bw-adults, #bw-children').on('change', function() {
    checkFormReady();
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(calculateTotal, 300);
  });

  $('#tap-booking-form').on('submit', function(e) {
    e.preventDefault();
    var $btn = $('#bw-submit');
    var $form = $('#tap-booking-form');
    if (tapFull) {
      alert('<?php echo esc_js(__('El tour está completo para esta fecha.', 'travel-agency-platform')); ?>');
      return;
    }
    if (!$form.find('input[name="tap_privacy_consent"]').is(':checked')) {
      alert('<?php echo esc_js(__('Debes aceptar el Aviso de Privacidad para continuar.', 'travel-agency-platform')); ?>');
      return;
    }
    $btn.prop('disabled', true).text('...');
    $.post(tap_ajax.ajax_url, {
      action: 'tap_booking_create',
      nonce: '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>',
      idempotency_key: '<?php echo esc_js(wp_generate_uuid4()); ?>',
      service_type: '<?php echo esc_js($pt); ?>',
      service_id: <?php echo (int) $id; ?>,
      check_in: $('#bw-check-in').val() || '',
      check_out: $('#bw-check-out').val() || '',
      adults: parseInt($('#bw-adults').val() || '1', 10),
      children: parseInt($('#bw-children').val() || '0', 10),
      guest_name: $('#bw-guest-name').val() || '',
      guest_email: $('#bw-guest-email').val() || '',
      guest_phone: $('#bw-guest-phone').val() || '',
      tap_privacy_consent: $form.find('input[name="tap_privacy_consent"]').is(':checked') ? '1' : ''
    }).done(function(res) {
      if (res && res.success && res.data.booking_code) {
        window.location.href = (res.data.redirect || '<?php echo esc_url(home_url('/checkout')); ?>?code=' + res.data.booking_code);
      } else {
        $btn.prop('disabled', false).text('<?php echo TAP_Booking::booking_mode($pt, $id) === 'request' ? esc_js(__('Solicitar reserva', 'travel-agency-platform')) : esc_js(__('Book Now', 'travel-agency-platform')); ?>');
        alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>');
      }
    }).fail(function() {
      $btn.prop('disabled', false).text('<?php echo TAP_Booking::booking_mode($pt, $id) === 'request' ? esc_js(__('Solicitar reserva', 'travel-agency-platform')) : esc_js(__('Book Now', 'travel-agency-platform')); ?>');
      alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>');
    });
  });
});
</script>
<?php if ('tap_tour' === $pt): ?>
<script type="application/ld+json"><?php
$eco = [];
if (get_post_meta($id, '_tap_tour_leave_no_trace', true)) $eco[] = 'Leave No Trace';
if (get_post_meta($id, '_tap_tour_carbon_offset', true)) $eco[] = 'Carbon offset';
$ld = [
    '@context' => 'https://schema.org',
    '@type' => 'TouristAttraction',
    'name' => get_the_title(),
    'description' => wp_strip_all_tags(get_the_content()),
    'url' => get_permalink(),
    'touristType' => get_post_meta($id, '_tap_tour_category', true) ?: 'general',
];
if (get_post_meta($id, '_tap_tour_lat', true) && get_post_meta($id, '_tap_tour_lng', true)) {
    $ld['geo'] = [
        '@type' => 'GeoCoordinates',
        'latitude' => (float) get_post_meta($id, '_tap_tour_lat', true),
        'longitude' => (float) get_post_meta($id, '_tap_tour_lng', true),
    ];
}
if ($agency) {
    $ld['provider'] = ['@type' => 'TravelAgency', 'name' => $agency->post_title, 'url' => get_permalink($agency)];
}
echo wp_json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?></script>
<?php endif; ?>
<?php get_footer(); ?>