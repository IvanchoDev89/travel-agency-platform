<?php
defined('ABSPATH') || exit;
get_header();

while (have_posts()): the_post();
    $id = get_the_ID();
    $agency_id = get_post_meta($id, '_tap_acc_agency_id', true);
    $agency = $agency_id ? get_post($agency_id) : null;
    $stars = get_post_meta($id, '_tap_acc_stars', true);
    $type = get_post_meta($id, '_tap_acc_type', true);
    $checkin = get_post_meta($id, '_tap_acc_checkin_time', true) ?: '15:00';
    $checkout = get_post_meta($id, '_tap_acc_checkout_time', true) ?: '11:00';
    $cancellation = get_post_meta($id, '_tap_acc_cancellation', true) ?: 'flexible';
    $house_rules = get_post_meta($id, '_tap_acc_house_rules', true);
    $address = get_post_meta($id, '_tap_acc_address', true);
    $city = get_post_meta($id, '_tap_acc_city', true);
    $country = get_post_meta($id, '_tap_acc_country', true);
    $lat = get_post_meta($id, '_tap_acc_lat', true);
    $lng = get_post_meta($id, '_tap_acc_lng', true);
    $gallery = get_post_meta($id, '_tap_acc_gallery', true);
    $gallery_ids = $gallery ? explode(',', $gallery) : [];
    $amenities = wp_get_post_terms($id, 'tap_amenity', ['fields' => 'names']);
    $bedrooms = get_post_meta($id, '_tap_acc_bedrooms', true);
    $bathrooms = get_post_meta($id, '_tap_acc_bathrooms', true);
    $capacity = get_post_meta($id, '_tap_acc_capacity', true);
    $price_fallback = floatval(get_post_meta($id, '_tap_acc_price_per_night', true));
    $rooms = TAP_Booking::get_rooms_with_availability($id);

    $has_rooms = !empty($rooms);
    $type_labels = [
        'hotel' => 'Hotel', 'hostel' => 'Hostel', 'resort' => 'Resort', 'villa' => 'Villa',
        'cabin' => 'Cabin', 'apartment' => 'Apartment', 'boutique' => 'Boutique Hotel',
        'eco' => 'Eco-Lodge', 'guesthouse' => 'Guest House', 'bedbreakfast' => 'Bed & Breakfast',
    ];
    $type_name = $type_labels[$type] ?? $type;
    $cancellation_labels = [
        'flexible' => 'Flexible — Free cancellation up to 24h before check-in',
        'moderate' => 'Moderate — Free cancellation up to 5 days before check-in',
        'strict' => 'Strict — 50% refund up to 7 days before check-in',
        'non_refundable' => 'Non-Refundable',
    ];
?>
<div class="tap-acc-single">

  <!-- Gallery -->
  <div class="tap-acc-gallery" data-lightbox>
    <div class="tap-acc-gallery-main">
      <?php if (has_post_thumbnail()): ?>
        <?php the_post_thumbnail('large', ['class' => 'tap-acc-main-img tap-lightbox-trigger', 'style' => 'cursor:pointer']); ?>
      <?php else: ?>
        <div class="tap-acc-main-img tap-acc-main-img-placeholder"></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($gallery_ids)): ?>
    <div class="tap-acc-gallery-thumbs">
      <?php $count = 0; $all_imgs = [];
      foreach ($gallery_ids as $img_id):
        $img = wp_get_attachment_image_src($img_id, 'medium');
        if (!$img) continue;
        $all_imgs[] = $img[0];
        $count++;
        if ($count > 4) break;
      ?>
        <div class="tap-acc-gallery-thumb">
          <img src="<?php echo esc_url($img[0]); ?>" alt="" class="tap-lightbox-trigger" style="cursor:pointer">
        </div>
      <?php endforeach; ?>
      <?php if (count($gallery_ids) > 4): ?>
        <div class="tap-acc-gallery-thumb tap-acc-gallery-more">
          <span>+<?php echo count($gallery_ids) - 4; ?></span>
        </div>
      <?php endif; ?>
      <div style="display:none">
        <?php foreach ($all_imgs as $src): ?>
        <img src="<?php echo esc_url($src); ?>">
        <?php endforeach; ?>
        <?php if (has_post_thumbnail()): $ft = wp_get_attachment_image_src(get_post_thumbnail_id(), 'large'); if ($ft): ?>
        <img src="<?php echo esc_url($ft[0]); ?>">
        <?php endif; endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="tap-acc-layout">
    <div class="tap-acc-main">

      <!-- Header -->
      <div class="tap-acc-header">
        <div class="tap-acc-header-top">
          <h1 class="tap-acc-title"><?php the_title(); ?></h1>
          <?php echo tap_fav_button($id, 'tap-fav-hero'); ?>
          <?php if ($stars): ?>
          <div class="tap-acc-stars"><?php echo str_repeat('★', intval($stars)) . str_repeat('☆', 5 - intval($stars)); ?></div>
          <?php endif; ?>
        </div>
        <div class="tap-acc-meta">
          <span class="tap-acc-type"><?php echo esc_html($type_name); ?></span>
          <?php if ($address || $city): ?>
          <span class="tap-acc-location">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <?php echo esc_html(implode(', ', array_filter([$address, $city, $country]))); ?>
          </span>
          <?php endif; ?>
          <?php if ($agency): ?>
          <span class="tap-acc-hosted"><?php esc_html_e('Hosted by', 'travel-agency-theme'); ?> <strong><?php echo esc_html($agency->post_title); ?></strong></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Highlights -->
      <div class="tap-acc-highlights">
        <?php if ($bedrooms): ?>
        <div class="tap-acc-highlight">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"/><path d="M21 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2"/><line x1="3" y1="12" x2="21" y2="12"/></svg>
          <span><?php echo esc_html($bedrooms); ?> <?php _e('Bedrooms', 'travel-agency-theme'); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($bathrooms): ?>
        <div class="tap-acc-highlight">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h16a1 1 0 0 1 1 1v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3a1 1 0 0 1 1-1z"/><path d="M6 12V5a2 2 0 0 1 2-2h3v2.25"/><path d="M14 12V5a2 2 0 0 1 2-2h3v2.25"/></svg>
          <span><?php echo esc_html($bathrooms); ?> <?php _e('Bathrooms', 'travel-agency-theme'); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($capacity): ?>
        <div class="tap-acc-highlight">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span><?php echo esc_html($capacity); ?> <?php _e('Guests', 'travel-agency-theme'); ?></span>
        </div>
        <?php endif; ?>
        <div class="tap-acc-highlight">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span><?php echo esc_html($checkin); ?> — <?php echo esc_html($checkout); ?></span>
        </div>
      </div>

      <!-- Description -->
      <div class="tap-acc-section">
        <h2><?php _e('About this property', 'travel-agency-theme'); ?></h2>
        <div class="tap-acc-description"><?php the_content(); ?></div>
      </div>

      <!-- Amenities -->
      <?php if (!empty($amenities)): ?>
      <div class="tap-acc-section">
        <h2><?php _e('Amenities', 'travel-agency-theme'); ?></h2>
        <div class="tap-acc-amenities">
          <?php foreach ($amenities as $amenity): ?>
            <span class="tap-acc-amenity">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              <?php echo esc_html($amenity); ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Availability Calendar -->
      <?php if ($has_rooms): ?>
      <div class="tap-acc-section tap-avcal-wrap" data-accommodation="<?php echo esc_attr($id); ?>">
        <h2><?php _e('Availability & Prices', 'travel-agency-theme'); ?></h2>
        <div class="tap-avcal-header">
          <div class="tap-avcal-nav">
            <button type="button" class="tap-avcal-nav-btn" data-dir="-1">&larr;</button>
            <span class="tap-avcal-label">--</span>
            <button type="button" class="tap-avcal-nav-btn" data-dir="1">&rarr;</button>
          </div>
          <div class="tap-avcal-legend">
            <span><span class="tap-avcal-swatch available"></span> Disponible</span>
            <span><span class="tap-avcal-swatch selected"></span> Seleccionado</span>
            <span><span class="tap-avcal-swatch blocked"></span> Bloqueado</span>
          </div>
        </div>
        <div class="tap-avcal-grid"></div>
      </div>
      <?php endif; ?>

      <!-- Room Types -->
      <?php if ($has_rooms): ?>
      <div class="tap-acc-section" id="tap-rooms-section">
        <h2><?php _e('Available Room Types', 'travel-agency-theme'); ?></h2>
        <div class="tap-rooms-list">
          <?php foreach ($rooms as $room): ?>
          <div class="tap-room-card <?php echo !$room['available'] ? 'tap-room-unavailable' : ''; ?>" data-room-id="<?php echo esc_attr($room['id']); ?>">
            <div class="tap-room-card-img">
              <?php if ($room['thumbnail']): ?>
                <img src="<?php echo esc_url($room['thumbnail']); ?>" alt="<?php echo esc_attr($room['title']); ?>">
              <?php else: ?>
                <div class="tap-room-card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>
              <?php endif; ?>
            </div>
            <div class="tap-room-card-body">
              <div class="tap-room-card-top">
                <h3><?php echo esc_html($room['title']); ?></h3>
                <?php if ($room['size']): ?><span class="tap-room-size"><?php echo esc_html($room['size']); ?></span><?php endif; ?>
              </div>
              <?php if ($room['bed_summary']): ?>
              <p class="tap-room-beds">🛏 <?php echo esc_html($room['bed_summary']); ?></p>
              <?php endif; ?>
              <p class="tap-room-capacity">👥 <?php printf(__('Up to %d guests', 'travel-agency-theme'), $room['max_occupancy']); ?></p>
              <?php if ($room['view']): ?><p class="tap-room-view">🌅 <?php echo esc_html($room['view']); ?></p><?php endif; ?>
              <?php if ($room['amenities']): ?>
              <div class="tap-room-amenities-sm">
                <?php foreach (array_slice($room['amenities'], 0, 4) as $a): ?>
                  <span><?php echo esc_html($a); ?></span>
                <?php endforeach; ?>
                <?php if (count($room['amenities']) > 4): ?>
                  <span>+<?php echo count($room['amenities']) - 4; ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if (!$room['available']): ?>
                <span class="tap-room-unavailable-badge"><?php _e('Sold out', 'travel-agency-theme'); ?></span>
              <?php endif; ?>
            </div>
            <div class="tap-room-card-footer">
              <div class="tap-room-price">
                <?php if (!empty($room['discounts']) && $room['savings'] > 0): ?>
                <span class="tap-room-price-base"><?php echo esc_html(TAP_Currency::fmt0($room['base_total'] / max(1, $room['nights']))); ?></span>
                <?php endif; ?>
                <span class="tap-room-price-amount"><?php echo esc_html(TAP_Currency::fmt0($room['total'] / max(1, ($room['nights'] ?: 1)))); ?></span>
                <span class="tap-room-price-label">/ <?php _e('night', 'travel-agency-theme'); ?></span>
                <?php if ($room['min_stay'] > 1): ?>
                <span class="tap-room-min-stay"><?php printf(__('Min %d nights', 'travel-agency-theme'), $room['min_stay']); ?></span>
                <?php endif; ?>
                <?php if (!empty($room['discounts'])): ?>
                <span class="tap-room-promo-badge">-<?php echo esc_html(array_sum(array_column($room['discounts'], 'percent'))); ?>%</span>
                <?php endif; ?>
                <?php if (!empty($room['price_breakdown'])): ?>
                <span class="tap-room-total"><?php echo esc_html(TAP_Currency::fmt($room['total'])); ?> total</span>
                <?php if ($room['savings'] > 0): ?>
                <span class="tap-room-saved"><?php printf(__('Ahorras %s', 'travel-agency-theme'), esc_html(TAP_Currency::fmt($room['savings']))); ?></span>
                <?php endif; ?>
                <?php endif; ?>
              </div>
              <button class="tap-btn tap-btn-primary tap-btn-sm tap-select-room" data-room-id="<?php echo esc_attr($room['id']); ?>" data-room-price="<?php echo esc_attr($room['total']); ?>" data-room-discounts='<?php echo esc_attr(wp_json_encode($room['discounts'])); ?>' <?php echo !$room['available'] ? 'disabled' : ''; ?>>
                <?php $room['available'] ? _e('Select', 'travel-agency-theme') : _e('Unavailable', 'travel-agency-theme'); ?>
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

<!-- Reviews -->
      <?php get_template_part('templates/partials/service-reviews'); ?>

      <!-- Policies -->
      <div class="tap-acc-section">
        <h2><?php _e('Policies', 'travel-agency-theme'); ?></h2>
        <div class="tap-acc-policies">
          <div class="tap-acc-policy">
            <strong><?php _e('Check-in / Check-out', 'travel-agency-theme'); ?></strong>
            <span><?php printf(__('Check-in from %s — Check-out until %s', 'travel-agency-theme'), esc_html($checkin), esc_html($checkout)); ?></span>
          </div>
          <div class="tap-acc-policy">
            <strong><?php _e('Cancellation', 'travel-agency-theme'); ?></strong>
            <span><?php echo esc_html($cancellation_labels[$cancellation] ?? $cancellation); ?></span>
          </div>
          <?php if ($house_rules): ?>
          <div class="tap-acc-policy">
            <strong><?php _e('House Rules', 'travel-agency-theme'); ?></strong>
            <span><?php echo nl2br(esc_html($house_rules)); ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Sidebar Booking Widget -->
    <div class="tap-acc-sidebar">
      <div class="tap-acc-booking-widget" id="tap-booking-widget">
        <div class="tap-bw-header">
          <?php if ($has_rooms): ?>
            <span class="tap-bw-price"><?php _e('Select dates & room', 'travel-agency-theme'); ?></span>
          <?php elseif ($price_fallback): ?>
            <span class="tap-bw-price-amount"><?php echo esc_html(TAP_Currency::fmt0($price_fallback)); ?></span>
            <span class="tap-bw-price-label">/ <?php _e('night', 'travel-agency-theme'); ?></span>
          <?php endif; ?>
        </div>

        <div class="tap-bw-body">
          <form id="tap-booking-form" class="tap-bw-form">
            <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
              <input type="text" name="tap_hp" tabindex="-1" autocomplete="off" value="">
            </div>
            <input type="hidden" name="service_type" value="tap_accommodation">
            <input type="hidden" name="service_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="room_id" id="bw-room-id" value="">

            <div class="tap-bw-dates">
              <div class="tap-bw-field">
                <label><?php _e('Check-in', 'travel-agency-theme'); ?></label>
                <input type="date" name="check_in" id="bw-check-in" class="tap-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php _e('Check-out', 'travel-agency-theme'); ?></label>
                <input type="date" name="check_out" id="bw-check-out" class="tap-input" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
              </div>
            </div>

            <div class="tap-bw-guests">
              <div class="tap-bw-field">
                <label><?php _e('Adults', 'travel-agency-theme'); ?></label>
                <input type="number" name="adults" id="bw-adults" class="tap-input" value="2" min="1" max="20">
              </div>
              <div class="tap-bw-field">
                <label><?php _e('Children', 'travel-agency-theme'); ?></label>
                <input type="number" name="children" id="bw-children" class="tap-input" value="0" min="0" max="10">
              </div>
            </div>

            <div class="tap-bw-guests tap-bw-guest-data">
              <div class="tap-bw-field tap-bw-field-full">
                <label><?php _e('Nombre del huésped principal', 'travel-agency-theme'); ?></label>
                <input type="text" name="guest_name" id="bw-guest-name" class="tap-input" autocomplete="name" value="<?php echo esc_attr(wp_get_current_user()->display_name ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php _e('Email de contacto', 'travel-agency-theme'); ?></label>
                <input type="email" name="guest_email" id="bw-guest-email" class="tap-input" autocomplete="email" value="<?php echo esc_attr(wp_get_current_user()->user_email ?? ''); ?>">
              </div>
              <div class="tap-bw-field">
                <label><?php _e('Teléfono', 'travel-agency-theme'); ?></label>
                <input type="tel" name="guest_phone" id="bw-guest-phone" class="tap-input" autocomplete="tel" placeholder="<?php esc_attr_e('Opcional', 'travel-agency-theme'); ?>">
              </div>
            </div>

            <div class="tap-bw-total" id="bw-total" style="display:none;">
              <div class="tap-bw-total-row">
                <span><?php _e('Nights', 'travel-agency-theme'); ?></span>
                <span id="bw-nights">0</span>
              </div>
              <div id="bw-price-breakdown" style="display:none;">
                <div class="tap-bw-total-row tap-bw-price-row">
                  <span><span id="bw-price-per-night">$0</span> x <span id="bw-breakdown-nights">0</span> noches</span>
                  <span id="bw-price-subtotal">$0</span>
                </div>
              </div>
              <div id="bw-discounts" style="display:none;"></div>
              <div class="tap-bw-total-row tap-bw-fee-row" id="bw-fee" style="display:none;">
                <span><?php _e('Tarifa de servicio', 'travel-agency-theme'); ?></span>
                <span id="bw-fee-amount">$0</span>
              </div>
              <div class="tap-bw-total-row tap-bw-total-final">
                <span><?php _e('Total', 'travel-agency-theme'); ?></span>
                <span id="bw-total-amount">$0</span>
              </div>
            </div>

            <div class="tap-bw-actions">
              <div id="bw-room-selected" style="display:none;margin-bottom:12px;">
                <small><?php _e('Room:', 'travel-agency-theme'); ?> <strong id="bw-room-name"></strong></small>
              </div>
              <button type="submit" class="tap-btn tap-btn-primary tap-btn-block" id="bw-submit" disabled>
                <?php _e('Book Now', 'travel-agency-theme'); ?>
              </button>
            </div>
          </form>

          <div class="tap-bw-footer">
            <p><?php _e('You won\'t be charged yet', 'travel-agency-theme'); ?></p>
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
  var selectedRoomId = 0;
  var selectedRoomPrice = 0;
  var selectedRoomName = '';

  $('.tap-select-room').on('click', function() {
    var card = $(this).closest('.tap-room-card');
    var roomId = $(this).data('room-id');

    $('.tap-room-card').removeClass('tap-room-selected');
    card.addClass('tap-room-selected');

    selectedRoomId = roomId;
    selectedRoomPrice = parseFloat(card.find('.tap-room-price-amount').text().replace(/[^0-9.]/g, ''));
    selectedRoomName = card.find('h3').text();

    $('#bw-room-id').val(roomId);
    $('#bw-room-name').text(selectedRoomName);
    $('#bw-room-selected').show();
    checkFormReady();
    calculateTotal();
  });

  var totalDebounce = null;
  $('#bw-check-in, #bw-check-out, #bw-adults, #bw-children').on('change', function() {
    checkFormReady();
    if (totalDebounce) clearTimeout(totalDebounce);
    totalDebounce = setTimeout(calculateTotal, 300);
  });

  function calculateTotal() {
    var checkIn = $('#bw-check-in').val();
    var checkOut = $('#bw-check-out').val();

    if (!checkIn || !checkOut || !selectedRoomId) {
      $('#bw-total').hide();
      return;
    }

    var d1 = new Date(checkIn);
    var d2 = new Date(checkOut);
    if (d2 <= d1) { $('#bw-total').hide(); return; }

    $.post(tap_ajax.ajax_url, {
      action: 'tap_calculate_booking_total',
      nonce: '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>',
      service_type: 'tap_accommodation',
      service_id: <?php echo esc_js($id); ?>,
      room_id: selectedRoomId,
      check_in: checkIn,
      check_out: checkOut,
      adults: $('#bw-adults').val(),
      children: $('#bw-children').val(),
    }, function(res) {
      if (!res.success || !res.data) {
        $('#bw-total').hide();
        return;
      }
      var data = res.data;
      var nights = data.nights || 1;
      var total = data.total || 0;
      var minStay = data.min_stay || 1;

      if (nights < minStay) {
        $('#bw-total').show();
        $('#bw-price-breakdown').hide();
        $('#bw-nights').text(nights);
        $('#bw-total-amount').html('<span style="color:#dc2626;">Mín. ' + minStay + ' noches</span>');
        $('#bw-submit').prop('disabled', true);
        return;
      }

      $('#bw-nights').text(nights);
      $('#bw-total-amount').text(tapSym + Number(total).toLocaleString('en-US', {minimumFractionDigits: tapDecimals, maximumFractionDigits: tapDecimals}));

      var fee = Number(data.fee || 0);
      if (fee > 0) {
        $('#bw-fee-amount').text(tapSym + Number(fee).toFixed(tapDecimals));
        $('#bw-fee').show();
      } else {
        $('#bw-fee').hide();
      }

      if (data.price_breakdown && Object.keys(data.price_breakdown).length > 0) {
        var prices = data.price_breakdown;
        var firstPrice = prices[Object.keys(prices)[0]];
        var isUniform = Object.values(prices).every(function(p) { return p === firstPrice; });
        if (isUniform) {
          $('#bw-price-per-night').text(tapSym + Number(firstPrice).toFixed(tapDecimals));
          $('#bw-breakdown-nights').text(nights);
          $('#bw-price-subtotal').text(tapSym + Number(firstPrice * nights).toFixed(tapDecimals));
          $('#bw-price-breakdown').show();
        } else {
          var html = '';
          $.each(prices, function(date, price) {
            var parts = date.split('-');
            var label = parts[2] + '/' + parts[1];
            html += '<div class="tap-bw-total-row tap-bw-price-row"><span>' + label + '</span><span>' + tapSym + Number(price).toFixed(tapDecimals) + '</span></div>';
          });
          $('#bw-price-breakdown').html(html);
          $('#bw-price-breakdown').show();
        }
      } else {
        $('#bw-price-breakdown').hide();
      }

      if (data.discounts && data.discounts.length > 0 && data.savings > 0) {
        var dh = '';
        $.each(data.discounts, function(i, d) {
          dh += '<div class="tap-bw-total-row tap-bw-discount-row"><span>' + d.label + '</span><span style="color:#16a34a;">-' + tapSym + Number(d.amount).toFixed(tapDecimals) + '</span></div>';
        });
        $('#bw-discounts').html(dh).show();
      } else {
        $('#bw-discounts').hide();
      }

      $('#bw-total').show();
      $('#bw-submit').prop('disabled', false);
    });
  }

  function checkFormReady() {
    var checkIn = $('#bw-check-in').val();
    var checkOut = $('#bw-check-out').val();
    if (!selectedRoomId || !checkIn || !checkOut) {
      $('#bw-submit').prop('disabled', true);
    }
  }

  $('#tap-booking-form').on('submit', function(e) {
    e.preventDefault();

    if (!selectedRoomId) { alert('<?php echo esc_js(__('Please select a room type', 'travel-agency-theme')); ?>'); return; }

    var btn = $('#bw-submit');
    btn.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'travel-agency-theme')); ?>');

    $.post(tap_ajax.ajax_url, {
      action: 'tap_booking_create',
      nonce: '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>',
      service_type: 'tap_accommodation',
      service_id: <?php echo esc_js($id); ?>,
      room_id: selectedRoomId,
      check_in: $('#bw-check-in').val(),
      check_out: $('#bw-check-out').val(),
      adults: $('#bw-adults').val(),
      children: $('#bw-children').val(),
      guest_name: $('#bw-guest-name').val() || '',
      guest_email: $('#bw-guest-email').val() || '',
      guest_phone: $('#bw-guest-phone').val() || ''
    }, function(res) {
      if (!res.success) {
        alert(res.data && res.data.message ? res.data.message : 'Error');
        btn.prop('disabled', false).text('<?php echo esc_js(__('Book Now', 'travel-agency-theme')); ?>');
        return;
      }
      window.location.href = '<?php echo esc_url(home_url('/checkout')); ?>?code=' + res.data.booking_code;
    });
  });
});
</script>

<?php
endwhile;
get_footer();
