<?php get_header(); ?>

<?php
// Landing builder config (sections on/off + order).
$tap_landing_enabled = function_exists('TAP_Landing_Builder::is_enabled') ? true : false;
function tap_lp_enabled($type) {
    return class_exists('TAP_Landing_Builder') ? TAP_Landing_Builder::is_enabled($type) : true;
}

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
    'posts_per_page' => 6,
    'ignore_sticky_posts' => true,
]);
$articles = $postsQuery->have_posts() ? array_map(function ($p) {
    $cats = get_the_category($p->ID);
    return [
        'title' => get_the_title($p),
        'link'  => get_permalink($p),
        'date'  => get_the_date('', $p),
        'img'   => (string) get_the_post_thumbnail_url($p->ID, 'medium_large'),
        'excerpt' => wp_trim_words(get_the_excerpt($p) ?: '', 18),
        'cat' => $cats ? $cats[0]->name : '',
        'cat_link' => $cats ? (string) get_category_link($cats[0]) : '',
        'read' => function_exists('tap_reading_time') ? tap_reading_time($p->ID) : 0,
    ];
}, $postsQuery->posts) : [];
wp_reset_postdata();

// ---------- Destinations (tap_spot teaser) ----------
$destPage = get_page_by_path('destinos');
$destUrl  = $destPage ? get_permalink($destPage) : home_url('/destinos/');
$destWanted = 6;
$spots = [];
$spotsQuery = new WP_Query([
    'post_type'        => 'tap_spot',
    'post_status'      => 'publish',
    'posts_per_page'   => 80,
    'orderby'          => ['menu_order' => 'ASC', 'title' => 'ASC'],
    'suppress_filters' => true,
]);
if ($spotsQuery->have_posts()) {
    $withImage = [];
    $phOnly    = [];
    foreach ($spotsQuery->posts as $spot) {
        if (preg_match($fixtureRx, $spot->post_title)) continue;
        $thumbId = (int) get_post_thumbnail_id($spot->ID);
        $img     = $thumbId ? wp_get_attachment_image_url($thumbId, 'medium_large') : '';
        $types   = class_exists('TAP_Destination_Spots') && taxonomy_exists(TAP_Destination_Spots::TAX)
            ? TAP_Destination_Spots::types_of($spot->ID)
            : [];
        $row = [
            'title'  => get_the_title($spot->ID),
            'link'   => get_permalink($spot->ID),
            'img'    => $img,
            'region' => (string) get_post_meta($spot->ID, '_tap_spot_region', true),
            'type'   => (is_array($types) && $types) ? $types[0]->name : '',
        ];
        if ($row['img']) { $withImage[] = $row; } else { $phOnly[] = $row; }
        if (count($withImage) >= $destWanted) break;
    }
    $spots = array_slice(array_merge($withImage, $phOnly), 0, $destWanted);
}
wp_reset_postdata();

$searchUrl  = home_url('/search-results/');
$builderPage = get_page_by_path('armar-mi-viaje');
$itinUrl    = $builderPage ? get_permalink($builderPage) : $searchUrl;
$fest     = function ($n) { return (int) $n; };

$hero_eyebrow  = get_theme_mod('tap_hero_eyebrow', __('Costa Rica, de verdad', 'travel-agency-platform'));
$hero_title    = get_theme_mod('tap_hero_title', __('Descubrí lo que tu viaje puede ser', 'travel-agency-platform'));
$hero_subtitle = get_theme_mod('tap_hero_subtitle', __('Tours, alojamientos y experiencias de agencias locales verificadas. Elegí tus intereses y armá tu propio itinerario.', 'travel-agency-platform'));
$hero_video    = tap_media_url(get_theme_mod('tap_hero_video_url', ''));
$hero_poster   = tap_media_url(get_theme_mod('tap_hero_video_poster', ''));

/*
 * Hero pool: when the admin registered several Pexels videos, one is picked
 * at random on every render (their file is verified to still exist; deleted
 * Media Library entries simply drop out of the rotation).
 */
$hero_pick = tap_pexels_pool_pick(tap_pexels_pool_get());
if (null !== $hero_pick) {
    $hero_video  = $hero_pick['url'];
    $hero_poster = $hero_pick['poster'];
}

$hero_overlay  = (float) get_theme_mod('tap_hero_overlay_opacity', 0.4);
$hero_search   = get_theme_mod('tap_hero_show_search', true);
if ($hero_overlay < 0) { $hero_overlay = 0; }
if ($hero_overlay > 0.8) { $hero_overlay = 0.8; }

// ---------- Knowledge hub: blog url, pillars, FAQ ----------
$blogUrl   = get_permalink((int) get_option('page_for_posts')) ?: home_url('/blog/');
$faqItems  = get_theme_mod('tap_landing_show_faq', true) ? tap_landing_faq_items() : [];

$pillarDefs = [
    ['slug' => 'destinos', 'blurb' => __('Región por región: Guanacaste, Arenal, Valle Central, Caribe y Península de Osa.', 'travel-agency-platform'), 'icon' => 'pin'],
    ['slug' => 'planificacion', 'blurb' => __('Mejor época, presupuestos, documentos e itinerarios listos para copiar.', 'travel-agency-platform'), 'icon' => 'calendar'],
    ['slug' => 'aventura', 'blurb' => __('Parques nacionales, volcanes, selva tropical y deportes extremos.', 'travel-agency-platform'), 'icon' => 'mountain'],
    ['slug' => 'cultura-y-gastronomia', 'blurb' => __('Ferias, tradiciones, café de altura y la cocina tica de verdad.', 'travel-agency-platform'), 'icon' => 'cup'],
    ['slug' => 'consejos-de-viaje', 'blurb' => __('Seguridad, transporte, equipaje y todo lo práctico antes de salir.', 'travel-agency-platform'), 'icon' => 'bulb'],
];
$faqIcons = [
    'pin'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    'calendar' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'mountain' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3l4 8 5-5 5 14H2L8 3z"/></svg>',
    'cup'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
    'bulb'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/></svg>',
];
$pillars = [];
foreach ($pillarDefs as $pDef) {
    $term = get_category_by_slug($pDef['slug']);
    if (!$term) { continue; }
    $pillars[] = [
        'link'  => get_category_link($term),
        'name'  => $term->name,
        'count' => (int) $term->count,
        'blurb' => $pDef['blurb'],
        'icon'  => $faqIcons[$pDef['icon']] ?? '',
    ];
}
?>
<?php if (tap_lp_enabled('hero')): ?>
<section class="tap-hero tap-landing-hero" id="home">
  <?php if (!empty($hero_video)): ?>
    <video class="tap-landing-hero-video" autoplay muted loop playsinline preload="metadata"<?php if (!empty($hero_poster)) echo ' poster="' . esc_url($hero_poster) . '"'; ?> aria-hidden="true">
      <source src="<?php echo esc_url($hero_video); ?>" type="video/mp4">
    </video>
    <div class="tap-landing-hero-overlay" style="opacity:<?php echo esc_attr($hero_overlay); ?>"></div>
  <?php else: ?>
    <div class="tap-hero-bg tap-landing-hero-bg" aria-hidden="true"></div>
  <?php endif; ?>
  <div class="tap-container tap-landing-hero-inner">
    <div class="tap-landing-hero-content">
      <?php if ($hero_eyebrow): ?>
      <p class="tap-landing-eyebrow"><?php echo esc_html($hero_eyebrow); ?></p>
      <?php endif; ?>
      <?php if ($hero_title): ?>
      <h1 class="tap-landing-hero-title"><?php echo esc_html($hero_title); ?></h1>
      <?php endif; ?>
      <?php if ($hero_subtitle): ?>
      <p class="tap-landing-hero-sub"><?php echo wp_kses_post($hero_subtitle); ?></p>
      <?php endif; ?>

      <?php if ($hero_search): ?>
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

      <?php endif; ?>

      <ul class="tap-landing-trust">
        <li><?php esc_html_e('Solo agencias verificadas', 'travel-agency-platform'); ?></li>
        <li><?php esc_html_e('Pago seguro', 'travel-agency-platform'); ?></li>
        <li><?php esc_html_e('Soporte local', 'travel-agency-platform'); ?></li>
      </ul>
    </div>
  </div>
</section>

<?php endif; // hero ?>

<?php if (($statServices + $statAgencies + $statDestinos) > 0 && tap_lp_enabled('stats')): ?>
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

<?php if (tap_lp_enabled('facts')): ?>
<section class="tap-landing-facts" aria-label="<?php esc_attr_e('Costa Rica en datos', 'travel-agency-platform'); ?>">
  <div class="tap-container">
    <ul class="tap-landing-facts-grid">
      <li><b>6%</b><span><?php esc_html_e('de la biodiversidad del planeta vive aquí', 'travel-agency-platform'); ?></span></li>
      <li><b>25%+</b><span><?php esc_html_e('del territorio son áreas protegidas', 'travel-agency-platform'); ?></span></li>
      <li><b>27&nbsp;°C</b><span><?php esc_html_e('de temperatura promedio todo el año', 'travel-agency-platform'); ?></span></li>
      <li><b>2</b><span><?php esc_html_e('costas: Pacífico y Caribe, mundos distintos', 'travel-agency-platform'); ?></span></li>
    </ul>
  </div>
</section>

<?php if (!empty($spots) && tap_lp_enabled('destinations')): ?>
<section class="tap-section tap-landing-destinations" id="destinos">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Parques, reservas y pueblos', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Explorá los destinos', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('De los volcanes del norte a la península de Osa: guías editoriales con datos prácticos, biodiversidad y su ubicación exacta en el mapa.', 'travel-agency-platform'); ?></p>
      <a class="tap-btn tap-btn-outline" href="<?php echo esc_url($destUrl); ?>"><?php esc_html_e('Explorar el mapa de destinos', 'travel-agency-platform'); ?></a>
    </div>
    <div class="tap-landing-dest-grid">
      <?php foreach ($spots as $spot): ?>
        <a class="tap-landing-dest-card" href="<?php echo esc_url($spot['link']); ?>">
          <div class="tap-landing-dest-media">
            <?php if ($spot['img']): ?>
              <img src="<?php echo esc_url($spot['img']); ?>" alt="<?php echo esc_attr($spot['title']); ?>" loading="lazy" decoding="async">
            <?php else: ?>
              <div class="tap-landing-dest-ph" aria-hidden="true"><?php echo esc_html(mb_substr($spot['title'], 0, 1)); ?></div>
            <?php endif; ?>
            <?php if ($spot['type']): ?>
              <span class="tap-landing-dest-type"><?php echo esc_html($spot['type']); ?></span>
            <?php endif; ?>
          </div>
          <div class="tap-landing-dest-body">
            <h3 class="tap-landing-dest-title"><?php echo esc_html($spot['title']); ?></h3>
            <?php if ($spot['region']): ?>
              <p class="tap-landing-dest-region"><?php echo esc_html($spot['region']); ?></p>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php endif; // facts ?>

<?php if (!empty($interests) && tap_lp_enabled('categories')): ?>
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

<?php if (!empty($experiences) && tap_lp_enabled('featured')): ?>
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
              <img src="<?php echo esc_url($xp['img']); ?>" alt="<?php echo esc_attr($xp['title']); ?>" loading="lazy" decoding="async">
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

<?php if (!empty($pillars) && tap_lp_enabled('blog')): ?>
<section class="tap-section tap-landing-pillars">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Antes de armar la maleta', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Todo lo que querés saber de Costa Rica', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('Guías escritas con información local: la mejor época, presupuestos reales y cómo moverse pueblo por pueblo.', 'travel-agency-platform'); ?></p>
    </div>
    <div class="tap-landing-pillar-grid">
      <?php foreach ($pillars as $pillar): ?>
        <a class="tap-landing-pillar-card" href="<?php echo esc_url($pillar['link']); ?>">
          <span class="tap-landing-pillar-icon" aria-hidden="true"><?php echo $pillar['icon']; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
          <h3><?php echo esc_html($pillar['name']); ?></h3>
          <p><?php echo esc_html($pillar['blurb']); ?></p>
          <span class="tap-landing-pillar-count">
            <?php printf(esc_html(_n('%s guía', '%s guías', $pillar['count'], 'travel-agency-platform')), esc_html(number_format_i18n($pillar['count']))); ?>
            <span aria-hidden="true">→</span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (tap_lp_enabled('itinerary')): ?>
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
        <a class="tap-btn tap-btn-lg tap-btn-secondary" href="<?php echo esc_url($itinUrl); ?>"><?php esc_html_e('Comenzar mi itinerario', 'travel-agency-platform'); ?></a>
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

<?php endif; // itinerary ?>

<?php if (!empty($agenciesClean) && tap_lp_enabled('destinations')): ?>
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
              <img src="<?php echo esc_url($ag['logo']); ?>" alt="<?php echo esc_attr($ag['name']); ?>" loading="lazy" decoding="async">
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

<?php if (!empty($testimonials) && tap_lp_enabled('testimonials')): ?>
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

<?php if (!empty($articles) && tap_lp_enabled('blog')): ?>
<section class="tap-section tap-section-alt tap-landing-articles">
  <div class="tap-container">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Recién publicado', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Guías para planificar tu viaje', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('Itinerarios, temporadas y consejos prácticos para viajar mejor por Costa Rica.', 'travel-agency-platform'); ?></p>
      <a class="tap-btn tap-btn-outline" href="<?php echo esc_url($blogUrl); ?>"><?php esc_html_e('Ver todas las guías', 'travel-agency-platform'); ?></a>
    </div>
    <div class="tap-landing-article-grid">
      <?php foreach ($articles as $art): ?>
        <a class="tap-landing-article-card" href="<?php echo esc_url($art['link']); ?>">
          <div class="tap-landing-article-media">
            <?php if ($art['img']): ?>
              <img src="<?php echo esc_url($art['img']); ?>" alt="<?php echo esc_attr($art['title']); ?>" loading="lazy" decoding="async">
            <?php else: ?>
              <div class="tap-landing-article-ph"><?php echo esc_html(mb_substr($art['title'], 0, 1)); ?></div>
            <?php endif; ?>
            <?php if ($art['cat']): ?>
              <span class="tap-landing-article-cat"><?php echo esc_html($art['cat']); ?></span>
            <?php endif; ?>
          </div>
          <h3><?php echo esc_html($art['title']); ?></h3>
          <p><?php echo esc_html($art['excerpt']); ?></p>
          <span class="tap-landing-article-date">
            <?php echo esc_html($art['date']); ?>
            <?php if ($art['read']): ?> · <?php printf(esc_html__('%d min de lectura', 'travel-agency-platform'), (int) $art['read']); ?><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($faqItems) && tap_lp_enabled('text')): ?>
<section class="tap-section tap-landing-faq" id="preguntas">
  <div class="tap-container tap-landing-faq-wrap">
    <div class="tap-landing-head">
      <p class="tap-landing-eyebrow tap-landing-eyebrow-dark"><?php esc_html_e('Resolvé tus dudas', 'travel-agency-platform'); ?></p>
      <h2><?php esc_html_e('Preguntas frecuentes sobre viajar a Costa Rica', 'travel-agency-platform'); ?></h2>
      <p class="tap-landing-sub"><?php esc_html_e('Lo que más nos preguntan, respondido con información actualizada y experiencia local.', 'travel-agency-platform'); ?></p>
    </div>
    <div class="tap-landing-faq-list">
      <?php foreach ($faqItems as $faqIndex => $faqItem): ?>
        <details class="tap-landing-faq-item"<?php echo 0 === $faqIndex ? ' open' : ''; ?>>
          <summary>
            <span class="tap-landing-faq-q"><?php echo esc_html($faqItem['q']); ?></span>
            <span class="tap-landing-faq-icon" aria-hidden="true"></span>
          </summary>
          <div class="tap-landing-faq-a"><p><?php echo esc_html($faqItem['a']); ?></p></div>
        </details>
      <?php endforeach; ?>
    </div>
    <p class="tap-landing-faq-more">
      <?php printf(esc_html__('¿Seguís con dudas? Explorá todas nuestras guías: %s', 'travel-agency-platform'), '<a href="' . esc_url($blogUrl) . '">' . esc_html__('ver guías de viaje', 'travel-agency-platform') . '</a>'); ?>
    </p>
  </div>
</section>
<?php endif; ?>

<?php if (tap_lp_enabled('cta')): ?>
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

<?php endif; // cta ?>

<?php get_footer(); ?>