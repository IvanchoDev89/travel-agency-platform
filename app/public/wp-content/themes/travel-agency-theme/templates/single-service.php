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

$agency_meta = [
    'tap_tour'       => '_tap_tour_agency_id',
    'tap_transport'  => '_tap_trans_agency_id',
    'tap_car_rental' => '_tap_car_agency_id',
    'tap_boat'       => '_tap_boat_agency_id',
    'tap_package'    => '_tap_pkg_agency_id',
];
$price_meta = [
    'tap_tour'       => '_tap_tour_price_adult',
    'tap_transport'  => '_tap_trans_price',
    'tap_car_rental' => '_tap_car_price_per_day',
    'tap_boat'       => '_tap_boat_price_half',
    'tap_package'    => '_tap_pkg_price',
];
$unit_label = [
    'tap_tour'       => __('per adult', 'travel-agency-theme'),
    'tap_transport'  => '',
    'tap_car_rental' => __('per day', 'travel-agency-theme'),
    'tap_boat'       => '',
    'tap_package'    => __('per person', 'travel-agency-theme'),
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
$needs_date = in_array($pt, ['tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat'], true);

$badge = $agency ? __('Verificada', 'travel-agency-theme') : '';
?>

<div class="tap-page-header">
    <div class="tap-container">
        <div class="tap-svc-header">
            <span class="tap-badge tap-badge-primary"><?php echo esc_html($type_label); ?></span>
            <h1><?php the_title(); ?> <?php echo tap_fav_button($id, 'tap-fav-hero'); ?></h1>
            <?php if ($agency): ?>
                <p class="tap-agency-meta"><?php esc_html_e('Operada por', 'travel-agency-theme'); ?>
                    <a href="<?php echo esc_url(home_url('/agency-profile/?id=' . $agency->ID)); ?>"><strong><?php echo esc_html($agency->post_title); ?></strong></a>
                    <?php if ('1' === get_post_meta($agency->ID, '_tap_agency_verified', true)): ?>
                        <span class="tap-verified-badge" title="<?php esc_attr_e('Verified agency', 'travel-agency-theme'); ?>">&#10003;</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="tap-container">
  <div class="tap-acc-layout">
    <div class="tap-acc-main">
      <?php if (has_post_thumbnail()): ?>
        <div class="tap-acc-gallery">
          <?php the_post_thumbnail('large'); ?>
        </div>
      <?php endif; ?>

      <div class="tap-acc-content">
        <h2><?php esc_html_e('About this service', 'travel-agency-theme'); ?></h2>
        <div class="tap-acc-description">
          <?php the_content(); ?>
        </div>
        <?php
        $specs = [];
        if ('tap_tour' === $pt) {
            foreach (['_tap_tour_duration' => __('Duración', 'travel-agency-theme'), '_tap_tour_departure_city' => __('Salida', 'travel-agency-theme'), '_tap_tour_meeting_point' => __('Punto de encuentro', 'travel-agency-theme')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_transport' === $pt) {
            foreach (['_tap_trans_from' => __('Desde', 'travel-agency-theme'), '_tap_trans_to' => __('Hasta', 'travel-agency-theme'), '_tap_trans_vehicle' => __('Vehículo', 'travel-agency-theme')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_car_rental' === $pt) {
            foreach (['_tap_car_type' => __('Tipo', 'travel-agency-theme'), '_tap_car_transmission' => __('Transmisión', 'travel-agency-theme'), '_tap_car_seats' => __('Asientos', 'travel-agency-theme')] as $k => $l) {
                $v = get_post_meta($id, $k, true);
                if ($v) $specs[] = [$l, $v];
            }
        }
        if ('tap_boat' === $pt) {
            foreach (['_tap_boat_capacity' => __('Capacidad', 'travel-agency-theme'), '_tap_boat_route' => __('Ruta', 'travel-agency-theme')] as $k => $l) {
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
            <div class="tap-bw-dates <?php echo $needs_out ? '' : 'tap-bw-single'; ?>">
              <div class="tap-bw-field">
                <label><?php echo 'tap_car_rental' === $pt ? __('Recogida', 'travel-agency-theme') : __('Fecha', 'travel-agency-theme'); ?></label>
                <input type="date" name="check_in" id="bw-check-in" class="tap-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
              </div>
              <?php if ($needs_out): ?>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Devolución', 'travel-agency-theme'); ?></label>
                <input type="date" name="check_out" id="bw-check-out" class="tap-input" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($needs_guests): ?>
            <div class="tap-bw-guests">
              <div class="tap-bw-field">
                <label><?php esc_html_e('Adults', 'travel-agency-theme'); ?></label>
                <input type="number" name="adults" id="bw-adults" class="tap-input" value="1" min="1" max="20">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Children', 'travel-agency-theme'); ?></label>
                <input type="number" name="children" id="bw-children" class="tap-input" value="0" min="0" max="10">
              </div>
            </div>
            <?php endif; ?>

            <div class="tap-bw-guests tap-bw-guest-data">
              <div class="tap-bw-field tap-bw-field-full">
                <label><?php esc_html_e('Nombre del huésped principal', 'travel-agency-theme'); ?></label>
                <input type="text" name="guest_name" id="bw-guest-name" class="tap-input" autocomplete="name" placeholder="<?php esc_attr_e('Tu nombre', 'travel-agency-theme'); ?>" value="<?php echo esc_attr(wp_get_current_user()->display_name ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Email de contacto', 'travel-agency-theme'); ?></label>
                <input type="email" name="guest_email" id="bw-guest-email" class="tap-input" autocomplete="email" value="<?php echo esc_attr(wp_get_current_user()->user_email ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php esc_html_e('Teléfono', 'travel-agency-theme'); ?></label>
                <input type="tel" name="guest_phone" id="bw-guest-phone" class="tap-input" autocomplete="tel" placeholder="<?php esc_attr_e('Opcional', 'travel-agency-theme'); ?>">
              </div>
            </div>

            <div class="tap-bw-total" id="bw-total" style="display:none;">
              <div class="tap-bw-total-row tap-bw-total-final">
                <span><?php esc_html_e('Total', 'travel-agency-theme'); ?></span>
                <span id="bw-total-amount">$0</span>
              </div>
              <div class="tap-capacity-note" id="bw-capacity" style="display:none;"></div>
            </div>

            <div class="tap-bw-actions">
              <button type="submit" class="tap-btn tap-btn-primary tap-btn-block" id="bw-submit" disabled>
                <?php esc_html_e('Book Now', 'travel-agency-theme'); ?>
              </button>
            </div>
          </form>

          <div class="tap-bw-footer">
            <p><?php esc_html_e('You won\'t be charged yet', 'travel-agency-theme'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(document).ready(function($) {
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
    $.post(tap_ajax.ajax_url, {
      action: 'tap_calculate_booking_total',
      service_type: '<?php echo esc_js($pt); ?>',
      service_id: <?php echo (int) $id; ?>,
      check_in: checkIn,
      check_out: checkOut,
      adults: parseInt($('#bw-adults').val() || '1', 10),
      children: parseInt($('#bw-children').val() || '0', 10)
    }).done(function(res) {
      if (res && res.success) {
        $('#bw-total').show();
        $('#bw-total-amount').text(tapSym + res.data.total.toFixed(tapDecimals));
        tapFull = false;
        $('#bw-capacity').hide().empty();
        if (res.data.capacity) {
          var cap = res.data.capacity;
          if (cap.remaining > 0) {
            $('#bw-capacity').show().removeClass('tap-full').text('Cupos disponibles: ' + cap.remaining + ' de ' + cap.capacity);
          } else {
            tapFull = true;
            $('#bw-capacity').show().addClass('tap-full').text('Cupo completo para esta fecha (' + cap.capacity + '/' + cap.capacity + '). Elige otra fecha.');
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
    if (tapFull) {
      alert('<?php echo esc_js(__('El tour está completo para esta fecha.', 'travel-agency-theme')); ?>');
      return;
    }
    $btn.prop('disabled', true).text('...');
    $.post(tap_ajax.ajax_url, {
      action: 'tap_booking_create',
      nonce: '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>',
      service_type: '<?php echo esc_js($pt); ?>',
      service_id: <?php echo (int) $id; ?>,
      check_in: $('#bw-check-in').val() || '',
      check_out: $('#bw-check-out').val() || '',
      adults: parseInt($('#bw-adults').val() || '1', 10),
      children: parseInt($('#bw-children').val() || '0', 10),
      guest_name: $('#bw-guest-name').val() || '',
      guest_email: $('#bw-guest-email').val() || '',
      guest_phone: $('#bw-guest-phone').val() || ''
    }).done(function(res) {
      if (res && res.success && res.data.booking_code) {
        window.location.href = '<?php echo esc_url(home_url('/checkout')); ?>?code=' + res.data.booking_code;
      } else {
        $btn.prop('disabled', false).text('<?php echo esc_js(__('Book Now', 'travel-agency-theme')); ?>');
        alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-theme')); ?>');
      }
    }).fail(function() {
      $btn.prop('disabled', false).text('<?php echo esc_js(__('Book Now', 'travel-agency-theme')); ?>');
      alert('<?php echo esc_js(__('Error', 'travel-agency-theme')); ?>');
    });
  });
});
</script>
<?php get_footer(); ?>