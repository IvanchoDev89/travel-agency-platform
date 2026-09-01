<?php get_header(); ?>

<section class="tap-hero">
  <div class="tap-hero-bg"></div>
  <div class="tap-container">
    <div class="tap-hero-content">
      <h1 class="tap-hero-title">Descubre tu próxima aventura</h1>
      <p class="tap-hero-subtitle">Hoteles, villas, tours y más — todo en un solo lugar</p>
      <div class="tap-hero-search">
          <form method="get" action="<?php echo esc_url(get_post_type_archive_link('tap_accommodation')); ?>" class="tap-hs-form">
          <div class="tap-hs-field tap-hs-destino">
            <label for="hs-destino">Destino</label>
            <div class="tap-hs-input-wrap">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.4 8 12 8 12s8-6.6 8-12a8 8 0 0 0-8-8z"/></svg>
              <input type="text" name="keyword" id="hs-destino" placeholder="¿A dónde vas?" class="tap-hs-input" autocomplete="off">
            </div>
            <div class="tap-hs-suggestions" style="display:none;"></div>
          </div>
          <div class="tap-hs-field tap-hs-date">
            <label for="hs-checkin">Llegada</label>
            <input type="date" name="check_in" id="hs-checkin" class="tap-hs-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
          </div>
          <div class="tap-hs-field tap-hs-date">
            <label for="hs-checkout">Salida</label>
            <input type="date" name="check_out" id="hs-checkout" class="tap-hs-input" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
          </div>
          <div class="tap-hs-field tap-hs-huespedes">
            <label for="hs-guests">Huéspedes</label>
            <div class="tap-hs-input-wrap">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <select name="guests" id="hs-guests" class="tap-hs-input">
                <option value="1">1 huésped</option>
                <option value="2" selected>2 huéspedes</option>
                <option value="3">3 huéspedes</option>
                <option value="4">4 huéspedes</option>
                <option value="5">5 huéspedes</option>
                <option value="6">6+ huéspedes</option>
              </select>
            </div>
          </div>
          <button type="submit" class="tap-btn tap-btn-lg tap-btn-primary tap-hs-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Buscar
          </button>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="tap-section">
  <div class="tap-container">
    <div class="tap-section-header">
      <h2>Alojamientos Destacados</h2>
      <a href="<?php echo esc_url(get_post_type_archive_link('tap_accommodation')); ?>" class="tap-btn tap-btn-outline">Ver todos</a>
    </div>
    <div class="tap-property-grid">
      <?php
      $featured = get_posts([
        'post_type' => 'tap_accommodation',
        'posts_per_page' => 4,
        'post_status' => 'publish',
      ]);
      foreach ($featured as $post): setup_postdata($post);
        $id = get_the_ID();
        $stars = (int) get_post_meta($id, '_tap_acc_stars', true);
        $type = get_post_meta($id, '_tap_acc_type', true);
        $location = get_post_meta($id, '_tap_acc_city', true);
        $gallery = get_post_meta($id, '_tap_acc_gallery', true);
        $img = '';
        if ($gallery) {
          $ids = explode(',', $gallery);
          $img = wp_get_attachment_image_url($ids[0], 'medium');
        }
        if (!$img) $img = get_the_post_thumbnail_url($id, 'medium');
        $amenities = wp_get_object_terms($id, 'tap_amenity', ['fields' => 'names']);
      ?>
      <a href="<?php the_permalink(); ?>" class="tap-acc-card">
        <div class="tap-acc-card-img">
          <?php if ($img): ?>
            <img src="<?php echo esc_url($img); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
          <?php else: ?>
            <div class="tap-acc-card-placeholder">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
          <?php endif; ?>
          <?php if ($stars): ?>
            <div class="tap-acc-card-rating"><?php echo str_repeat('★', $stars); ?></div>
          <?php endif; ?>
        </div>
        <div class="tap-acc-card-body">
          <h3 class="tap-acc-card-title"><?php the_title(); ?></h3>
          <?php if ($location): ?>
            <p class="tap-acc-card-location"><?php echo esc_html($location); ?></p>
          <?php endif; ?>
          <div class="tap-acc-card-footer">
            <span class="tap-acc-card-price">
              Desde <?php
                $rooms = get_posts(['post_type' => 'tap_room', 'numberposts' => 1, 'meta_key' => '_tap_room_accommodation_id', 'meta_value' => $id, 'orderby' => 'meta_value_num', 'meta_key' => '_tap_room_price_per_night', 'order' => 'ASC']);
                if ($rooms) {
                  echo esc_html(TAP_Currency::fmt0((float) get_post_meta($rooms[0]->ID, '_tap_room_price_per_night', true)));
                } else {
                  echo esc_html(TAP_Currency::fmt0(0));
                }
              ?>/noche
            </span>
          </div>
        </div>
      </a>
      <?php endforeach; wp_reset_postdata(); ?>
    </div>
  </div>
</section>

<section class="tap-section tap-section-alt">
  <div class="tap-container">
    <div class="tap-section-header">
      <h2>Servicios</h2>
    </div>
    <div class="tap-services-grid">
      <a href="<?php echo esc_url(home_url('/accommodation/')); ?>" class="tap-service-card">
        <div class="tap-service-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
        <h3>Alojamientos</h3>
        <p>Hoteles, villas, cabañas y apartamentos</p>
      </a>
      <a href="<?php echo esc_url(home_url('/tour/')); ?>" class="tap-service-card">
        <div class="tap-service-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.4 8 12 8 12s8-6.6 8-12a8 8 0 0 0-8-8z"/></svg></div>
        <h3>Tours</h3>
        <p>Experiencias guiadas y aventuras</p>
      </a>
      <a href="<?php echo esc_url(home_url('/transport/')); ?>" class="tap-service-card">
        <div class="tap-service-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></div>
        <h3>Transportes</h3>
        <p>Traslados y transportación</p>
      </a>
      <a href="<?php echo esc_url(home_url('/car-rental/')); ?>" class="tap-service-card">
        <div class="tap-service-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg></div>
        <h3>Renta de Autos</h3>
        <p>Vehículos para tu viaje</p>
      </a>
    </div>
  </div>
</section>

<section class="tap-section tap-section-cta">
  <div class="tap-container">
    <div class="tap-cta-content">
      <h2>¿Eres agencia de viajes?</h2>
      <p>Únete a nuestra plataforma y llega a miles de viajeros</p>
      <a href="<?php echo esc_url(home_url('/register')); ?>" class="tap-btn tap-btn-lg tap-btn-primary">Registra tu agencia</a>
    </div>
  </div>
</section>

<?php get_footer(); ?>
