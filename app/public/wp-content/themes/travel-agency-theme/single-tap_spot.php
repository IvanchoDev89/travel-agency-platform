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
    $location = TAP_Destination_Spots::location_term($id);
    $chain    = $location ? TAP_Destinations::chain($location->term_id) : [];
    // The last link of the chain is the place itself: show its parents only.
    $parents  = array_slice($chain, 0, -1);
    $services = TAP_Destination_Spots::services_for_spot($id);
    $nearby   = $location ? TAP_Destination_Spots::nearby_places($location->term_id) : [];
    $total    = (int) $services['total'];

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
                <?php if (! $parents && ($province || $region)): ?>
                    <p class="tap-spot-province">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?php echo esc_html(implode(' · ', array_filter([$province, $region]))); ?>
                    </p>
                <?php endif; ?>
                <?php if ($parents): ?>
                    <p class="tap-spot-location">
                        <a href="<?php echo esc_url(home_url('/destinos/')); ?>"><?php esc_html_e('Destinos', 'travel-agency-platform'); ?></a>
                        <?php foreach ($parents as $node): ?>
                            <span aria-hidden="true">/</span>
                            <span><?php echo esc_html($node->name); ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <?php if ($total): ?>
                    <p class="tap-spot-service-count">
                        <a href="#servicios">
                            <?php
                            printf(
                                esc_html(_n('%d servicio disponible en este destino', '%d servicios disponibles en este destino', $total, 'travel-agency-platform')),
                                (int) $total
                            );
                            ?>
                        </a>
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

            <?php if ($total): ?>
                <section class="tap-spot-services" id="servicios">
                    <header class="tap-spot-services-head">
                        <h2><?php esc_html_e('Servicios en este destino', 'travel-agency-platform'); ?></h2>
                        <p>
                            <?php
                            printf(
                                esc_html(_n('%d servicio publicado', '%d servicios publicados', $total, 'travel-agency-platform')),
                                (int) $total
                            );
                            ?>
                        </p>
                    </header>

                    <?php foreach ($services['groups'] as $svc_type => $group): ?>
                        <?php if (empty($group['posts'])) { continue; } ?>
                        <div class="tap-spot-service-group">
                            <div class="tap-spot-service-group-head">
                                <h3>
                                    <?php echo esc_html($group['label']); ?>
                                    <span class="tap-spot-service-total"><?php echo (int) $group['total']; ?></span>
                                </h3>
                                <?php
                                $archive  = get_post_type_archive_link($svc_type);
                                $more_url = ($archive && $location) ? add_query_arg('destino', $location->slug, $archive) : '';
                                ?>
                                <?php if ($more_url): ?>
                                    <a class="tap-link-more" href="<?php echo esc_url($more_url); ?>"><?php esc_html_e('Ver todos', 'travel-agency-platform'); ?></a>
                                <?php endif; ?>
                            </div>
                            <?php
                            // Reuse the catalogue card so a service looks identical
                            // on the ficha and in the archive.
                            echo do_shortcode(sprintf(
                                '[tap_services type="%s" location="%d" limit="3" columns="3"]',
                                esc_attr($svc_type),
                                (int) $location->term_id
                            ));
                            ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <section class="tap-spot-services tap-spot-services--empty" id="servicios">
                    <h2><?php esc_html_e('Servicios en este destino', 'travel-agency-platform'); ?></h2>
                    <p><?php esc_html_e('Todavía no hay servicios publicados en este destino.', 'travel-agency-platform'); ?></p>
                    <a class="tap-btn tap-btn-outline" href="<?php echo esc_url(home_url('/destinos/')); ?>"><?php esc_html_e('Explorar otros destinos', 'travel-agency-platform'); ?></a>
                </section>
            <?php endif; ?>

            <?php if ($nearby): ?>
                <section class="tap-spot-nearby">
                    <h2><?php esc_html_e('Otros destinos de la zona', 'travel-agency-platform'); ?></h2>
                    <ul class="tap-spot-nearby-list">
                        <?php foreach ($nearby as $place): ?>
                            <li>
                                <a href="<?php echo esc_url($place['url']); ?>"><?php echo esc_html($place['term']->name); ?></a>
                                <?php if ($place['count']): ?>
                                    <span><?php echo (int) $place['count']; ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
    </div>

    <?php
endwhile;

get_footer();
