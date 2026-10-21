<?php
/**
 * Educational page for a destination spot (tap_spot).
 *
 * Renders the title, an optional featured image, the editorial description,
 * the educational profile (region, ecosystem, biodiversity, activities, tips,
 * conservation), the quick facts (schedule, fee, area, year, accessibility) and
 * a small locator map centred on the spot.
 */
defined('ABSPATH') || exit;
get_header();

while (have_posts()): the_post();
    $id       = get_the_ID();
    $lat      = get_post_meta($id, '_tap_spot_lat', true);
    $lng      = get_post_meta($id, '_tap_spot_lng', true);
    $province = get_post_meta($id, '_tap_spot_province', true);
    $region   = get_post_meta($id, '_tap_spot_region', true);
    $interest = TAP_Destination_Spots::interest_list($id);
    $biodiversity = TAP_Destination_Spots::list_value($id, '_tap_spot_biodiversity');
    $activities   = TAP_Destination_Spots::list_value($id, '_tap_spot_activities');
    $tips         = TAP_Destination_Spots::list_value($id, '_tap_spot_tips');
    $conservation = get_post_meta($id, '_tap_spot_conservation', true);
    $types    = TAP_Destination_Spots::types_of($id);
    $type     = $types ? $types[0] : null;
    $has_map  = ($lat !== '' && $lng !== '');

    $facts = array_filter([
        __('Horario', 'travel-agency-platform')       => get_post_meta($id, '_tap_spot_schedule', true),
        __('Tarifa', 'travel-agency-platform')        => get_post_meta($id, '_tap_spot_fee', true),
        __('Extensión', 'travel-agency-platform')     => get_post_meta($id, '_tap_spot_area', true),
        __('Año de creación', 'travel-agency-platform') => get_post_meta($id, '_tap_spot_created', true),
        __('Accesibilidad', 'travel-agency-platform') => get_post_meta($id, '_tap_spot_accessibility', true),
        __('Sitio oficial', 'travel-agency-platform') => get_post_meta($id, '_tap_spot_website', true),
    ], function ($v) { return $v !== '' && $v !== null; });

    $profile = array_filter([
        __('Región', 'travel-agency-platform')       => $region,
        __('Ecosistema', 'travel-agency-platform')   => get_post_meta($id, '_tap_spot_ecosystem', true),
        __('Altitud', 'travel-agency-platform')      => get_post_meta($id, '_tap_spot_altitude', true),
        __('Mejor época', 'travel-agency-platform')  => get_post_meta($id, '_tap_spot_best_season', true),
    ], function ($v) { return $v !== '' && $v !== null; });

    if ($has_map) {
        wp_enqueue_style('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tap-leaflet', TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('tap-spot-locator', TAP_PLUGIN_URL . 'assets/js/spot-locator.js', ['tap-leaflet'], TAP_VERSION, true);
        wp_localize_script('tap-spot-locator', 'tapSpotLocator', [
            'pluginUrl' => TAP_PLUGIN_URL,
            'lat'       => (float) $lat,
            'lng'       => (float) $lng,
            'title'     => get_the_title(),
            'zoom'      => 11,
        ]);
    }
    ?>

    <div class="tap-container">
        <nav class="tap-breadcrumbs" aria-label="<?php esc_attr_e('Migas de pan', 'travel-agency-platform'); ?>">
            <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Inicio', 'travel-agency-platform'); ?></a>
            <span aria-hidden="true">/</span>
            <a href="<?php echo esc_url(home_url('/destinos/')); ?>"><?php esc_html_e('Destinos', 'travel-agency-platform'); ?></a>
            <?php if ($type): ?>
                <span aria-hidden="true">/</span>
                <a href="<?php echo esc_url(get_term_link($type)); ?>"><?php echo esc_html($type->name); ?></a>
            <?php endif; ?>
            <span aria-hidden="true">/</span>
            <span aria-current="page"><?php the_title(); ?></span>
        </nav>

        <article class="tap-spot">
            <header class="tap-spot-head">
                <?php if ($type): ?>
                    <span class="tap-spot-badge"><?php echo esc_html($type->name); ?></span>
                <?php endif; ?>
                <h1><?php the_title(); ?></h1>
                <?php if ($province || $region): ?>
                    <p class="tap-spot-province">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?php echo esc_html(implode(' · ', array_filter([$province, $region]))); ?>
                    </p>
                <?php endif; ?>
                <?php if (has_excerpt()): ?>
                    <p class="tap-spot-lead"><?php echo esc_html(get_the_excerpt()); ?></p>
                <?php endif; ?>
            </header>

            <?php if (has_post_thumbnail()): ?>
                <div class="tap-spot-hero"><?php the_post_thumbnail('large', ['loading' => 'eager']); ?></div>
            <?php endif; ?>

            <div class="tap-spot-grid">
                <div class="tap-spot-main">
                    <?php if (get_the_content()): ?>
                        <div class="entry-content tap-spot-content"><?php the_content(); ?></div>
                    <?php endif; ?>

                    <?php if ($interest): ?>
                        <section class="tap-spot-block">
                            <h2><?php esc_html_e('Datos de interés', 'travel-agency-platform'); ?></h2>
                            <ul class="tap-spot-interest">
                                <?php foreach ($interest as $line): ?>
                                    <li><?php echo esc_html($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <?php if ($biodiversity): ?>
                        <section class="tap-spot-block">
                            <h2><?php esc_html_e('Biodiversidad', 'travel-agency-platform'); ?></h2>
                            <ul class="tap-spot-interest tap-spot-interest--grid">
                                <?php foreach ($biodiversity as $line): ?>
                                    <li><?php echo esc_html($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <?php if ($activities): ?>
                        <section class="tap-spot-block">
                            <h2><?php esc_html_e('Actividades recomendadas', 'travel-agency-platform'); ?></h2>
                            <ul class="tap-spot-interest">
                                <?php foreach ($activities as $line): ?>
                                    <li><?php echo esc_html($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <?php if ($tips): ?>
                        <section class="tap-spot-block">
                            <h2><?php esc_html_e('Consejos para el visitante', 'travel-agency-platform'); ?></h2>
                            <ul class="tap-spot-interest tap-spot-interest--tips">
                                <?php foreach ($tips as $line): ?>
                                    <li><?php echo esc_html($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <?php if ($conservation): ?>
                        <section class="tap-spot-block tap-spot-block--conservation">
                            <h2><?php esc_html_e('Conservación', 'travel-agency-platform'); ?></h2>
                            <p><?php echo esc_html($conservation); ?></p>
                        </section>
                    <?php endif; ?>
                </div>

                <aside class="tap-spot-side">
                    <?php if ($profile): ?>
                        <div class="tap-spot-facts tap-spot-profile">
                            <h2><?php esc_html_e('Perfil del destino', 'travel-agency-platform'); ?></h2>
                            <dl>
                                <?php foreach ($profile as $label => $value): ?>
                                    <dt><?php echo esc_html($label); ?></dt>
                                    <dd><?php echo esc_html($value); ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>

                    <?php if ($facts): ?>
                        <div class="tap-spot-facts">
                            <h2><?php esc_html_e('Información práctica', 'travel-agency-platform'); ?></h2>
                            <dl>
                                <?php foreach ($facts as $label => $value): ?>
                                    <dt><?php echo esc_html($label); ?></dt>
                                    <dd>
                                        <?php if (!empty($value) && strpos($value, 'http') === 0): ?>
                                            <a href="<?php echo esc_url($value); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($value); ?></a>
                                        <?php else: ?>
                                            <?php echo esc_html($value); ?>
                                        <?php endif; ?>
                                    </dd>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>

                    <?php if ($has_map): ?>
                        <div class="tap-spot-locator">
                            <h2><?php esc_html_e('Ubicación', 'travel-agency-platform'); ?></h2>
                            <div id="tap-spot-map" class="tap-spot-map"></div>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </article>
    </div>

    <?php
endwhile;

get_footer();
