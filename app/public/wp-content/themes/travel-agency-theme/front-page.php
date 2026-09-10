<?php get_header(); ?>

<?php
/**
 * Travel landing — modern, light, bilingual (ES/EN), mobile-first.
 * Every section degrades gracefully when there is no content yet.
 */

$tapTypes = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

// ---------- Counts (stats strip) ----------
$statServices = 0;
$statAgencies = 0;
$statDestinos = 0;
$statTours    = 0;
foreach ($tapTypes as $tapType) {
    $statServices += (int) wp_count_posts($tapType)->publish;
}
$statTours = (int) wp_count_posts('tap_tour')->publish;
$statAgencies = (int) wp_count_posts('tap_agency')->publish;
$locTerms = get_terms(['taxonomy' => 'tap_location', 'hide_empty' => false, 'parent' => 0]);
if (is_array($locTerms) && !is_wp_error($locTerms)) {
    $statDestinos = count($locTerms);
}

// ---------- Featured experiences ----------
$fixtureRx = '/(E2E|NoMode|RTB|Lead Tour|Instant|Request|Saved)/i';
$experiences = [];
$xpQuery = new WP_Query([
    'post_type'        => $tapTypes,
    'post_status'      => 'publish',
    'posts_per_page'   => 60,
    'orderby'          => ['menu_order' => 'DESC', 'date' => 'DESC'],
    'suppress_filters' => true,
]);
if ($xpQuery->have_posts()) {
    foreach ($xpQuery->posts as $xp) {
        if (count($experiences) >= 8) break;
        if (preg_match($fixtureRx, $xp->post_title)) continue;
        $type   = $xp->post_type;
        $priceK = class_exists('TAP_API') ? TAP_API::get_price_key($type) : null;
        $price  = $priceK ? (float) get_post_meta($xp->ID, $priceK, true) : 0.0;
        $gallery = get_post_meta($xp->ID, '_tap_' . $xp->post_type . '_gallery', true);
        $img = '';
        if ($gallery) {
            $first = (int) explode(',', (string) $gallery)[0];
            $img = $first ? wp_get_attachment_image_url($first, 'medium_large') : '';
        }
        if (!$img) $img = (string) get_the_post_thumbnail_url($xp->ID, 'medium_large');

        $location = '';
        $terms = get_the_terms($xp->ID, 'tap_location');
        if (is_array($terms) && $terms) {
            $location = $terms[0]->name;
        } elseif ($type === 'tap_accommodation') {
            $location = (string) get_post_meta($xp->ID, '_tap_acc_city', true);
        }

        $rating = ['avg' => 0, 'count' => 0];
        if (class_exists('TAP_API')) {
            $rating = TAP_API::get_rating_stats($type, $xp->ID);
            if (!is_array($rating)) $rating = ['avg' => 0, 'count' => 0];
        }

        $experiences[] = [
            'id'       => $xp->ID,
            'type'     => $type,
            'title'    => get_the_title($xp->ID),
            'link'     => get_permalink($xp->ID),
            'img'      => $img,
            'location' => $location,
            'price'    => $price,
            'avg'      => (float) ($rating['avg'] ?? 0),
            'count'    => (int) ($rating['count'] ?? 0),
        ];
    }
}
wp_reset_postdata();

$typeLabels = [
    'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
    'tap_tour'          => __('Tour', 'travel-agency-platform'),
    'tap_transport'     => __('Transporte', 'travel-agency-platform'),
    'tap_car_rental'    => __('Renta de auto', 'travel-agency-platform'),
    'tap_boat'          => __('Barco', 'travel-agency-platform'),
    'tap_package'       => __('Paquete', 'travel-agency-platform'),
];
$priceSuffix = [
    'tap_accommodation' => __('/noche', 'travel-agency-platform'),
    'tap_tour'          => __('/persona', 'travel-agency-platform'),
    'tap_transport'     => __('/trayecto', 'travel-agency-platform'),
    'tap_car_rental'    => __('/día', 'travel-agency-platform'),
    'tap_boat'          => __('/día', 'travel-agency-platform'),
    'tap_package'       => '',
];

// ---------- Interests (chips) ----------
$interests = get_terms(['taxonomy' => 'tap_tour_type', 'hide_empty' => false, 'number' => 8]);
if (is_wp_error($interests)) $interests = [];

// ---------- Agencies ----------
$agenciesRaw = get_posts([
    'post_type'      => 'tap_agency',
    'post_status'    => 'publish',
    'posts_per_page' => 12,
    'orderby'        => 'date',
    'order'          => 'ASC',
]);
$agenciesClean = [];
foreach ($agenciesRaw as $ag) {
    if (count($agenciesClean) >= 4) break;
    if (preg_match($fixtureRx, $ag->post_title)) continue;
    if (class_exists('TAP_Approval') && !TAP_Approval::is_approved($ag->ID)) continue;
    $agenciesClean[] = [
        'id'    => $ag->ID,
        'name'  => get_the_title($ag->ID),
        'link'  => get_permalink($ag->ID),
        'logo'  => (string) get_the_post_thumbnail_url($ag->ID, 'thumbnail'),
        'city'  => (string) get_post_meta($ag->ID, '_tap_agency_city', true),
    ];
}

// ---------- Reviews (testimonials) ----------
$testimonials = [];
global $wpdb;
$tapReviewsTable = $wpdb->prefix . 'tap_reviews';
$tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$tapReviewsTable}'");
if ($tableExists) {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.rating, r.content, r.created_at, r.service_id
         FROM `{$tapReviewsTable}` r
         WHERE r.is_approved = 1 AND (r.mod_status IS NULL OR r.mod_status = 'ok')
         ORDER BY r.created_at DESC LIMIT %d",
        3
    ));
    foreach ((array) $rows as $rw) {
        if (!preg_match($fixtureRx, (string) $rw->content) && trim((string) $rw->content)) {
            $testimonials[] = [
                'text'   => $rw->content,
                'rating' => (int) $rw->rating,
                'date'   => $rw->created_at,
                'title'  => get_the_title($rw->service_id),
                'link'   => get_permalink($rw->service_id),
            ];
        }
    }
}

// ---------- Articles ----------
$postsQuery = new WP_Query([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 3,
    'ignore_sticky_posts' => true,
]);
$articles = $postsQuery->have_posts() ? array_map(function ($p) {
    return [
        'title' => get_the_title($p),
        'link'  => get_permalink($p),
        'date'  => get_the_date('', $p),
        'img'   => (string) get_the_post_thumbnail_url($p->ID, 'medium_large'),
        'excerpt' => wp_trim_words(get_the_excerpt($p) ?: '', 22),
    ];
}, $postsQuery->posts) : [];
wp_reset_postdata();

$searchUrl  = home_url('/search-results/');
$fest     = function ($n) { return (int) $n; };
?>

<section class="tap-hero tap-landing-hero" id="home">
  <div class="tap-hero-bg tap-landing-hero-bg" aria-hidden="true"></div>
  <div class="tap-container tap-landing-hero-inner">
    <div class="tap-landing-hero-content">
      <p class="tap-landing-eyebrow"><?php esc_html_e('Costa Rica, de verdad', 'travel-agency-platform'); ?></p>
      <h1 class="tap-landing-hero-title"><?php esc_html_e('Descubrí lo que tu viaje puede ser', 'travel-agency-platform'); ?></h1>
      <p class="tap-landing-hero-sub"><?php esc_html_e('Tours, alojamientos y experiencias de agencias locales verificadas. Elegí tus intereses y armá tu propio itinerario.', 'travel-agency-platform'); ?></p>

      <div class="tap-hero-search tap-landing-search">
        <form method="get" action="<?php echo esc_url(get_post_type_archive_link('tap_accommodation')); ?>" class="tap-hs-form tap-landing-hs">
          <div class="tap-hs-field tap-hs-destino">
            <label for="hs-destino"><?php esc_html_e('Destino', 'travel-agency-platform'); ?></label>
            <div class="tap-hs-input-wrap">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.4 8 12 8 12s8-6.6 8-12a8 8 0 0 0-8-8z"/></svg>
              <input type="text" name="keyword" id="hs-destino" placeholder="<?php esc_attr_e('¿A dónde querés ir?', 'travel-agency-platform'); ?>" class="tap-hs-input" autocomplete="off">
            </div>
            <div class="tap-hs-suggestions" style="display:none;"></div>
          </div>
          <div class="tap-hs-field tap-hs-date">
            <label for="hs-checkin"><?php esc_html_e('Llegada', 'travel-agency-platform'); ?></label>
            <input type="date" name="check_in" id="hs-checkin" class="tap-hs-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
          </div>
          <div class="tap-hs-field tap-hs-date">
            <label for="hs-checkout"><?php esc_html_e('Salida', 'travel-agency-platform'); ?></label>
            <input type="date" name="check_out" id="hs-checkout" class="tap-hs-input" min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
          </div>
          <div class="tap-hs-field tap-hs-huespedes">
            <label for="hs-guests"><?php esc_html_e('Huéspedes', 'travel-agency-platform'); ?></label>
            <div class="tap-hs-input-wrap">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <select name="guests" id="hs-guests" class="tap-hs-input">
                <option value="1"><?php esc_html_e('1 huésped', 'travel-agency-platform'); ?></option>
                <option value="2" selected><?php esc_html_e('2 huéspedes', 'travel-agency-platform'); ?></option>
                <option value="3"><?php esc_html_e('3 huéspedes', 'travel-agency-platform'); ?></option>
                <option value="4"><?php esc_html_e('4 huéspedes', 'travel-agency-platform'); ?></option>
                <option value="5"><?php esc_html_e('5 huéspedes', 'travel-agency-platform'); ?></option>
                <option value="6"><?php esc_html_e('6+ huéspedes', 'travel-agency-platform'); ?></option>
              </select>
            </div>
          </div>
          <button type="submit" class="tap-btn tap-btn-lg tap-btn-primary tap-hs-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?php esc_html_e('Buscar', 'travel-agency-platform'); ?>
          </button>
        </form>
      </div>

      <ul class="tap-landing-trust">
        <li><?php esc_html_e('Solo agencias verificadas', 'travel-agency-platform'); ?></li>
        <li><?php esc_html_e('Pago seguro', 'travel-agency-platform'); ?></li>
        <li><?php esc_html_e('Soporte local', 'travel-agency-platform'); ?></li>
      </ul>
    </div>
  </div>
</section>

<?php if (($statServices + $statAgencies + $statDestinos) > 0): ?>
<section class="tap-section tap-landing-stats">
  <div class="tap-container">
    <div class="tap-landing-stats-grid">
      <div class="tap-landing-stat">
        <span class="tap-landing-stat-num" data-count="<?php echo esc_attr($fest($statServices)); ?>"><?php echo esc_html(number_format_i18n($statServices)); ?></span>
        <span class="tap-landing-stat-label"><?php esc_html_e('servicios listados', 'travel-agency-platform'); ?></span>
      </div>
      <div class="tap-landing-stat">
        <span class="tap-landing-stat-num" data-count="<?php echo esc_attr($fest($statAgencies)); ?>"><?php echo esc_html(number_format_i18n($statAgencies)); ?></span>
        <span class="tap-landing-stat-label"><?php esc_html_e('agencias locales', 'travel-agency-platform'); ?></span>
      </div>
      <div class="tap-landing-stat">
        <span class="tap-landing-stat-num" data-count="<?php echo esc_attr($fest($statTours)); ?>"><?php echo esc_html(number_format_i18n($statTours)); ?></span>
        <span class="tap-landing-stat-label"><?php esc_html_e('tours y experiencias', 'travel-agency-platform'); ?></span>
      </div>
      <div class="tap-landing-stat">
        <span class="tap-landing-stat-num">100%</span>
        <span class="tap-landing-stat-label"><?php esc_html_e('contacto sin intermediarios', 'travel-agency-platform'); ?></span>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($interests)): ?>
<section class="tap-section tap-landing-interests">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Empezá por lo que te apasiona', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Explorá por interés', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('Aventura, naturaleza, gastronomía… cada interés abre una forma distinta de viajar por Costa Rica.', 'travel-agency-platform'); ?></p>
    </div>
    <div class="tap-landing-chips">
      <?php foreach ($interests as $interest): ?>
        <a class="tap-landing-chip" href="<?php echo esc_url(add_query_arg('tour_type', rawurlencode($interest->slug), $searchUrl)); ?>">
          <span class="tap-landing-chip-dot" aria-hidden="true"></span>
          <?php echo esc_html($interest->name); ?>
        </a>
      <?php endforeach; ?>
      <a class="tap-landing-chip tap-landing-chip-all" href="<?php echo esc_url($searchUrl); ?>"><?php esc_html_e('Ver todo', 'travel-agency-platform'); ?></a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($experiences)): ?>
<section class="tap-section tap-section-alt tap-landing-experiences">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Hecho para viajeros reales', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Experiencias seleccionadas', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('Servicios publicados por agencias verificadas, con precio claro y reserva directa.', 'travel-agency-platform'); ?></p>
      <a class="tap-btn tap-btn-outline" href="<?php echo esc_url($searchUrl); ?>"><?php esc_html_e('Ver todas las experiencias', 'travel-agency-platform'); ?></a>
    </div>
    <div class="tap-landing-xp-grid">
      <?php foreach ($experiences as $xp): $suffix = $priceSuffix[$xp['type']] ?? ''; ?>
        <a class="tap-landing-card" href="<?php echo esc_url($xp['link']); ?>">
          <div class="tap-landing-card-media">
            <?php if ($xp['img']): ?>
              <img src="<?php echo esc_url($xp['img']); ?>" alt="<?php echo esc_attr($xp['title']); ?>" loading="lazy">
            <?php else: ?>
              <div class="tap-landing-card-ph"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
            <?php endif; ?>
            <?php if ($xp['count'] > 0): ?>
              <span class="tap-landing-card-rating"><?php echo esc_html(str_repeat('★', round($xp['avg']))) . ' ' . esc_html(number_format_i18n($xp['avg'], 1)); ?></span>
            <?php endif; ?>
            <span class="tap-landing-card-type"><?php echo esc_html($typeLabels[$xp['type']] ?? ''); ?></span>
          </div>
          <div class="tap-landing-card-body">
            <h3 class="tap-landing-card-title"><?php echo esc_html($xp['title']); ?></h3>
            <?php if ($xp['location']): ?>
              <p class="tap-landing-card-location"><?php echo esc_html($xp['location']); ?></p>
            <?php endif; ?>
            <div class="tap-landing-card-footer">
              <?php if ($xp['price'] > 0): ?>
                <span class="tap-landing-card-price"><?php echo esc_html(TAP_Currency::fmt0($xp['price'])) . esc_html($suffix); ?></span>
              <?php else: ?>
                <span class="tap-landing-card-price tap-landing-card-price-cta"><?php esc_html_e('Ver experiencia', 'travel-agency-platform'); ?></span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="tap-section tap-landing-itinerary">
  <div class="tap-container">
    <div class="tap-landing-itinerary-card">
      <div class="tap-landing-itinerary-copy">
        <p class="tap-landing-eyebrow"><?php esc_html_e('Tu itinerario, tu forma de viajar', 'travel-agency-platform'); ?></p>
        <h2><?php esc_html_e('Diseñá tu propio viaje en 3 pasos', 'travel-agency-platform'); ?></h2>
        <ol class="tap-landing-itinerary-steps">
          <li><?php esc_html_e('Elegí tus intereses, destinos y presupuesto.', 'travel-agency-platform'); ?></li>
          <li><?php esc_html_e('Armá tu plan día a día con tours y alojamientos reales.', 'travel-agency-platform'); ?></li>
          <li><?php esc_html_e('Pedí tu cotización y reservá directo con la agencia.', 'travel-agency-platform'); ?></li>
        </ol>
        <a class="tap-btn tap-btn-lg tap-btn-secondary" href="<?php echo esc_url($searchUrl); ?>"><?php esc_html_e('Comenzar mi itinerario', 'travel-agency-platform'); ?></a>
      </div>
      <div class="tap-landing-itinerary-art" aria-hidden="true">
        <div class="tap-landing-itinerary-art-inner">
          <span class="tap-landing-itinerary-line"></span>
          <span class="tap-landing-itinerary-stop"><b>1</b><?php esc_html_e('Intereses', 'travel-agency-platform'); ?></span>
          <span class="tap-landing-itinerary-stop"><b>2</b><?php esc_html_e('Destinos', 'travel-agency-platform'); ?></span>
          <span class="tap-landing-itinerary-stop"><b>3</b><?php esc_html_e('Cotización', 'travel-agency-platform'); ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (!empty($agenciesClean)): ?>
<section class="tap-section tap-landing-agencies">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Gente local, servicios reales', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Agencias verificadas en la plataforma', 'travel-agency-platform'); ?></h2>
    </div>
    <div class="tap-landing-agency-grid">
      <?php foreach ($agenciesClean as $ag):
        $initials = function_exists('mb_substr') ? mb_substr(trim($ag['name']), 0, 1) : substr(trim($ag['name']), 0, 1);
      ?>
        <a class="tap-landing-agency-card" href="<?php echo esc_url($ag['link']); ?>">
          <div class="tap-landing-agency-avatar">
            <?php if ($ag['logo']): ?>
              <img src="<?php echo esc_url($ag['logo']); ?>" alt="<?php echo esc_attr($ag['name']); ?>" loading="lazy">
            <?php else: ?>
              <span><?php echo esc_html($initials); ?></span>
            <?php endif; ?>
          </div>
          <div class="tap-landing-agency-info">
            <h3><?php echo esc_html($ag['name']); ?></h3>
            <?php if ($ag['city']): ?><p><?php echo esc_html($ag['city']); ?></p><?php endif; ?>
            <span class="tap-landing-verified"><?php esc_html_e('Verificada', 'travel-agency-platform'); ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($testimonials)): ?>
<section class="tap-section tap-section-alt tap-landing-reviews">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Lo que dicen los viajeros', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Experiencias de quienes ya viajaron', 'travel-agency-platform'); ?></h2>
    </div>
    <div class="tap-landing-review-grid">
      <?php foreach ($testimonials as $testi): ?>
        <figure class="tap-landing-review-card">
          <div class="tap-landing-review-stars" aria-label="<?php echo esc_attr($testi['rating'] . '/5'); ?>">
            <?php echo str_repeat('★', $testi['rating']); ?>
          </div>
          <blockquote><?php echo esc_html($testi['text']); ?></blockquote>
          <?php if ($testi['title']): ?><figcaption><a href="<?php echo esc_url($testi['link']); ?>"><?php echo esc_html($testi['title']); ?></a></figcaption><?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($articles)): ?>
<section class="tap-section tap-landing-articles">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Ideas e inspiración', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Destinos que inspiran', 'travel-agency-platform'); ?></h2>
    </div>
    <div class="tap-landing-article-grid">
      <?php foreach ($articles as $art): ?>
        <a class="tap-landing-article-card" href="<?php echo esc_url($art['link']); ?>">
          <?php if ($art['img']): ?>
            <img src="<?php echo esc_url($art['img']); ?>" alt="<?php echo esc_attr($art['title']); ?>" loading="lazy">
          <?php else: ?>
            <div class="tap-landing-article-ph"><?php echo esc_html(mb_substr($art['title'], 0, 1)); ?></div>
          <?php endif; ?>
          <h3><?php echo esc_html($art['title']); ?></h3>
          <p><?php echo esc_html($art['excerpt']); ?></p>
          <span class="tap-landing-article-date"><?php echo esc_html($art['date']); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="tap-section tap-landing-cta">
  <div class="tap-container">
    <div class="tap-landing-cta-card">
      <h2><?php esc_html_e('¿Tenés una agencia de viajes?', 'travel-agency-platform'); ?></h2>
      <p><?php esc_html_e('Sumá tus servicios a la plataforma y llegá a viajeros que buscan experiencias reales.', 'travel-agency-platform'); ?></p>
      <div class="tap-landing-cta-actions">
        <a href="<?php echo esc_url(home_url('/register')); ?>" class="tap-btn tap-btn-lg tap-btn-primary"><?php esc_html_e('Registrar mi agencia', 'travel-agency-platform'); ?></a>
        <a href="<?php echo esc_url(home_url('/sobre-nosotros')); ?>" class="tap-btn tap-btn-lg tap-btn-ghost"><?php esc_html_e('Conocé más', 'travel-agency-platform'); ?></a>
      </div>
    </div>
  </div>
</section>

<?php get_footer(); ?>