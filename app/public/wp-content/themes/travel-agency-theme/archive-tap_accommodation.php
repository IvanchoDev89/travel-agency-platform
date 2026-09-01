<?php
defined('ABSPATH') || exit;
get_header();

$keyword   = sanitize_text_field($_GET['keyword'] ?? '');
$check_in  = sanitize_text_field($_GET['check_in'] ?? '');
$check_out = sanitize_text_field($_GET['check_out'] ?? '');
$guests    = intval($_GET['guests'] ?? 0);
$type_slug = sanitize_text_field($_GET['type'] ?? '');
$amenity_slug = sanitize_text_field($_GET['amenity'] ?? '');
$sort      = sanitize_text_field($_GET['sort'] ?? '');
$view      = sanitize_text_field($_GET['view'] ?? 'grid');
$min_price = floatval($_GET['min_price'] ?? 0);
$max_price = floatval($_GET['max_price'] ?? 0);
$stars     = intval($_GET['stars'] ?? 0);

global $wp_query;
$total_posts = $wp_query->found_posts;
?>
<div class="tap-acc-archive">
  <div class="tap-acc-archive-header">
    <div>
      <h1><?php post_type_archive_title(); ?></h1>
      <p><?php echo esc_html($total_posts); ?> propiedades encontradas</p>
    </div>
    <div class="tap-archive-actions">
      <div class="tap-view-toggle">
        <button type="button" class="tap-view-btn <?php echo $view !== 'list' ? 'active' : ''; ?>" data-view="grid" title="Cuadrícula">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        </button>
        <button type="button" class="tap-view-btn <?php echo $view === 'list' ? 'active' : ''; ?>" data-view="list" title="Lista">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
        <button type="button" class="tap-view-btn" data-view="map" title="Mapa">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
        </button>
      </div>
    </div>
  </div>

  <div class="tap-archive-layout">
    <!-- Sidebar Filters -->
    <aside class="tap-archive-sidebar">
      <form method="get" id="tap-filter-form">
        <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">

        <div class="tap-filter-group">
          <h4>Destino</h4>
          <div class="tap-filter-search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="keyword" placeholder="Ciudad, propiedad..." value="<?php echo esc_attr($keyword); ?>" autocomplete="off">
          </div>
        </div>

        <div class="tap-filter-group">
          <h4>Fechas</h4>
          <label>Llegada</label>
          <input type="date" name="check_in" value="<?php echo esc_attr($check_in); ?>" min="<?php echo esc_attr(date('Y-m-d')); ?>" class="tap-input">
          <label>Salida</label>
          <input type="date" name="check_out" value="<?php echo esc_attr($check_out); ?>" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>" class="tap-input">
        </div>

        <div class="tap-filter-group">
          <h4>Huéspedes</h4>
          <select name="guests" class="tap-input">
            <option value="">Cualquier</option>
            <?php foreach ([1,2,3,4,5,6,8,10] as $g): ?>
            <option value="<?php echo $g; ?>" <?php selected($guests, $g); ?>><?php echo $g; ?>+</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="tap-filter-group">
          <h4>Tipo</h4>
          <select name="type" class="tap-input">
            <option value="">Todos</option>
            <?php
            $types = get_terms(['taxonomy' => 'tap_property_type', 'hide_empty' => true]);
            foreach ($types as $t):
            ?>
            <option value="<?php echo esc_attr($t->slug); ?>" <?php selected($type_slug, $t->slug); ?>><?php echo esc_html($t->name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="tap-filter-group">
          <h4>Estrellas</h4>
          <select name="stars" class="tap-input">
            <option value="">Cualquier</option>
            <?php foreach ([5,4,3,2,1] as $s): ?>
            <option value="<?php echo $s; ?>" <?php selected($stars, $s); ?>><?php echo $s; ?> ★</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="tap-filter-group">
          <h4>Precio por noche</h4>
          <div class="tap-filter-range">
            <input type="number" name="min_price" placeholder="Min $" value="<?php echo $min_price ?: ''; ?>" class="tap-input">
            <span>—</span>
            <input type="number" name="max_price" placeholder="Max $" value="<?php echo $max_price ?: ''; ?>" class="tap-input">
          </div>
        </div>

        <div class="tap-filter-group">
          <h4>Amenidades</h4>
          <?php
          $amenities_terms = get_terms(['taxonomy' => 'tap_amenity', 'hide_empty' => true]);
          foreach ($amenities_terms as $a):
          ?>
          <label class="tap-filter-check">
            <input type="checkbox" name="amenities[]" value="<?php echo esc_attr($a->slug); ?>" <?php checked(in_array($a->slug, (array)($amenity_slug ? [$amenity_slug] : []))); ?>>
            <span><?php echo esc_html($a->name); ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="tap-filter-actions">
          <button type="submit" class="tap-btn tap-btn-primary tap-btn-block">Filtrar</button>
          <a href="<?php echo esc_url(get_post_type_archive_link('tap_accommodation')); ?>" class="tap-btn tap-btn-ghost tap-btn-block">Limpiar</a>
        </div>
      </form>
    </aside>

    <!-- Results -->
    <div class="tap-archive-content">
      <div class="tap-archive-toolbar">
        <span class="tap-archive-count"><?php echo esc_html($total_posts); ?> resultados</span>
        <select name="sort" class="tap-input tap-sort-select" form="tap-filter-form">
          <option value="">Más relevantes</option>
          <option value="price_asc" <?php selected($sort, 'price_asc'); ?>>Precio: menor a mayor</option>
          <option value="price_desc" <?php selected($sort, 'price_desc'); ?>>Precio: mayor a menor</option>
          <option value="rating" <?php selected($sort, 'rating'); ?>>Mejor calificación</option>
          <option value="name" <?php selected($sort, 'name'); ?>>Nombre A-Z</option>
        </select>
      </div>

      <div class="tap-acc-archive-grid <?php echo $view === 'list' ? 'tap-archive-list' : ''; ?>">
        <?php
          $map_data = [];
          if (have_posts()): while (have_posts()): the_post();
          $id = get_the_ID();
          $type = get_post_meta($id, '_tap_acc_type', true);
          $type_labels = ['hotel' => 'Hotel', 'hostel' => 'Hostel', 'resort' => 'Resort', 'villa' => 'Villa', 'cabin' => 'Cabin', 'apartment' => 'Apartment', 'boutique' => 'Boutique', 'eco' => 'Eco-Lodge'];
          $stars_val = get_post_meta($id, '_tap_acc_stars', true);
          $city = get_post_meta($id, '_tap_acc_city', true);
          $country = get_post_meta($id, '_tap_acc_country', true);
          $price = floatval(get_post_meta($id, '_tap_acc_price_per_night', true));
          $rooms = TAP_Post_Types::get_accommodation_rooms($id);
          if (!$price && !empty($rooms) && $rooms[0]) {
            $price = floatval(get_post_meta($rooms[0]->ID, '_tap_room_price_per_night', true));
          }
          $amenities = wp_get_post_terms($id, 'tap_amenity', ['fields' => 'names']);
          $rating_stats = TAP_API::get_rating_stats('tap_accommodation', $id);
          $rating_avg = $rating_stats['avg'];
          $rating_count = $rating_stats['count'];

          list($map_lat, $map_lng) = TAP_Post_Types::get_accommodation_coords($id);
          $map_data[] = [
            'title' => get_the_title(),
            'url'   => get_permalink() . ($check_in ? '?check_in=' . urlencode($check_in) . '&check_out=' . urlencode($check_out) . '&guests=' . $guests : ''),
            'lat'   => $map_lat,
            'lng'   => $map_lng,
            'city'  => $city,
            'price' => number_format($price, 0),
            'img'   => get_the_post_thumbnail_url($id, 'thumbnail'),
          ];
        ?>
        <a href="<?php the_permalink(); ?><?php echo $check_in ? '?check_in=' . urlencode($check_in) . '&check_out=' . urlencode($check_out) . '&guests=' . $guests : ''; ?>" class="tap-acc-card <?php echo $view === 'list' ? 'tap-acc-card-list' : ''; ?>">
          <div class="tap-acc-card-img">
            <?php if (has_post_thumbnail()): ?>
              <?php the_post_thumbnail('medium_large', ['loading' => 'lazy']); ?>
            <?php else: ?>
              <div class="tap-acc-card-placeholder">🏨</div>
            <?php endif; ?>
            <?php if ($type): ?>
              <span class="tap-badge tap-badge-primary top-right"><?php echo esc_html($type_labels[$type] ?? $type); ?></span>
            <?php endif; ?>
            <?php echo tap_fav_button(); ?>
          </div>
          <div class="tap-acc-card-body">
            <h3 class="tap-acc-card-title"><?php the_title(); ?></h3>
            <?php if ($stars_val): ?>
            <div class="tap-acc-card-stars"><?php echo str_repeat('★', intval($stars_val)) . str_repeat('☆', 5 - intval($stars_val)); ?></div>
            <?php endif; ?>
            <?php if ($rating_count > 0): ?>
            <div class="tap-acc-card-rating">
              <?php echo tap_rating_stars($rating_avg, $rating_count); ?>
              <span class="tap-acc-card-rating-count"><?php echo (int) $rating_count; ?> <?php esc_html_e('reseñas', 'travel-agency-platform'); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($city || $country): ?>
            <p class="tap-acc-card-location">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?php echo esc_html(implode(', ', array_filter([$city, $country]))); ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($amenities)): ?>
            <div class="tap-acc-card-amenities">
              <?php foreach (array_slice($amenities, 0, 4) as $a): ?>
                <span><?php echo esc_html($a); ?></span>
              <?php endforeach; ?>
              <?php if (count($amenities) > 4): ?><span>+<?php echo count($amenities) - 4; ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($view === 'list'): ?>
            <p class="tap-acc-card-excerpt"><?php echo wp_trim_words(get_the_excerpt() ?: get_the_content(), 20); ?></p>
            <?php endif; ?>
          </div>
          <div class="tap-acc-card-footer">
            <div class="tap-acc-card-price">
              <?php if ($check_in && $check_out && !empty($rooms)): ?>
                <span class="tap-acc-card-price-from">desde</span>
              <?php endif; ?>
              <span class="tap-acc-card-price-amount"><?php echo esc_html(TAP_Currency::fmt0($price)); ?></span>
              <span class="tap-acc-card-price-label">/ noche</span>
            </div>
            <?php if ($rating_avg > 0): ?>
            <div class="tap-acc-card-rating-sm">
              <span class="tap-acc-card-rating-val"><?php echo esc_html(number_format($rating_avg, 1)); ?></span>
              <?php if ($rating_count > 0): ?><span class="tap-acc-card-rating-count">(<?php echo (int) $rating_count; ?>)</span><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </a>
        <?php endwhile; else: ?>
        <div class="tap-empty" style="grid-column:1/-1;">
          <div class="tap-empty-icon">🔍</div>
          <h3>No encontramos propiedades</h3>
          <p>Intentá con otros filtros o fechas diferentes.</p>
        </div>
        <?php endif; ?>
      </div>

      <textarea id="tap-map-data" hidden><?php echo esc_html(wp_json_encode($map_data)); ?></textarea>

      <div id="tap-map-wrap">
        <div class="tap-map-header">
          <h3><?php echo esc_html(count($map_data)); ?> propiedades en el mapa</h3>
          <button type="button" class="tap-map-close">Ver lista</button>
        </div>
        <div id="tap-map"></div>
      </div>

      <?php the_posts_pagination([
        'mid_size' => 2,
        'prev_text' => '&larr;',
        'next_text' => '&rarr;',
        'class' => 'tap-pagination',
      ]); ?>
    </div>
  </div>
</div>

<script>
jQuery(function($) {
  // View toggle (grid/list handled here; map handled by public-map.js)
  $('.tap-view-btn[data-view]').on('click', function() {
    var view = $(this).data('view');
    if (view === 'map') return; // handled by tapMap
    $('.tap-view-btn').removeClass('active');
    $(this).addClass('active');
    $('.tap-acc-archive-grid').toggleClass('tap-archive-list', view === 'list');
    $('input[name="view"]').val(view);
  });

  // Sort change → submit
  $('.tap-sort-select').on('change', function() {
    $('#tap-filter-form').submit();
  });

  // Auto-submit on filter change (with debounce)
  var filterTimer;
  $('#tap-filter-form select, #tap-filter-form input[type="date"], #tap-filter-form input[type="number"]').on('change', function() {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(function() { $('#tap-filter-form').submit(); }, 500);
  });
});
</script>
<?php get_footer(); ?>
