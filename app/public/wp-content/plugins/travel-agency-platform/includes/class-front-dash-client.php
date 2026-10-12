<?php
/**
 * TAP_Front_Dash_Client — Client-facing dashboard renderer.
 *
 * Renders the client sections of the /mi-cuenta/ hub: overview stats,
 * recent bookings, favorites and reviews. Split out of TAP_Front_Dash so
 * the agency renderer and the client renderer no longer share one file.
 *
 * The sidebar shell is shared with TAP_Front_Dash and stays there
 * (@internal); this class calls into it.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Front_Dash_Client {

    /* ==================================================================
     *  CLIENT DASHBOARD
     * ================================================================== */

    /** Entry point called by TAP_Front_Dash::render() for non-agency users. */
    public static function render_client_dash($user, $section) {
        $sections = self::client_nav($section);
        $content  = self::client_section($user, $section);

        ob_start();
        ?>
        <div class="tap-dash">
            <?php TAP_Front_Dash::render_sidebar($user, $sections, $section); ?>
            <main class="tap-dash-main">
                <?php echo $content; ?>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function client_nav($active) {
        $base = home_url('/mi-cuenta/');
        return [
            ['key' => 'overview', 'label' => __('Resumen', 'travel-agency-platform'), 'icon' => 'home', 'url' => $base],
            ['key' => 'bookings', 'label' => __('Mis reservas', 'travel-agency-platform'), 'icon' => 'calendar', 'url' => add_query_arg('seccion', 'bookings', $base)],
            ['key' => 'favorites', 'label' => __('Favoritos', 'travel-agency-platform'), 'icon' => 'heart', 'url' => add_query_arg('seccion', 'favorites', $base)],
            ['key' => 'reviews', 'label' => __('Mis reseñas', 'travel-agency-platform'), 'icon' => 'star', 'url' => add_query_arg('seccion', 'reviews', $base)],
        ];
    }

    private static function client_section($user, $section) {
        switch ($section) {
            case 'bookings':
                return self::client_bookings($user);
            case 'favorites':
                return self::client_favorites($user);
            case 'reviews':
                return self::client_reviews($user);
            default:
                return self::client_overview($user);
        }
    }

    /* ── client: overview ──────────────────────────────────────────── */

    private static function client_overview($user) {
        global $wpdb;

        $bookings = TAP_Booking::get_client_bookings($user->ID);
        $total    = count($bookings);
        $upcoming = 0;
        $spent    = 0.0;
        foreach ($bookings as $b) {
            if (in_array($b->status, ['pending', 'confirmed', 'request'], true)) {
                ++$upcoming;
            }
            if (in_array($b->status, ['confirmed', 'completed', 'paid'], true)) {
                $spent += floatval($b->total_amount);
            }
        }

        $favorites = get_user_meta($user->ID, 'tap_favorites', true);
        $fav_count = is_array($favorites) ? count($favorites) : 0;

        $review_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d",
            $user->ID
        ));

        $base = home_url('/mi-cuenta/');

        ob_start();
        ?>
        <div class="tap-dash-header">
            <div class="tap-dash-header-text">
                <h1><?php printf(/* translators: %s: nombre del usuario */ esc_html__('Hola, %s', 'travel-agency-platform'), esc_html($user->display_name)); ?></h1>
                <p><?php esc_html_e('Bienvenido a tu panel personal. Desde aquí puedes gestionar todo tu viaje.', 'travel-agency-platform'); ?></p>
            </div>
            <div class="tap-dash-header-avatar">
                <?php echo get_avatar($user->ID, 72); ?>
            </div>
        </div>

        <div class="tap-dash-stats">
            <div class="tap-dash-stat-card">
                <div class="tap-dash-stat-icon tap-dash-stat-teal">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="tap-dash-stat-body">
                    <span class="tap-dash-stat-value"><?php echo (int) $total; ?></span>
                    <span class="tap-dash-stat-label"><?php esc_html_e('Reservas totales', 'travel-agency-platform'); ?></span>
                </div>
            </div>
            <div class="tap-dash-stat-card">
                <div class="tap-dash-stat-icon tap-dash-stat-amber">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="tap-dash-stat-body">
                    <span class="tap-dash-stat-value"><?php echo (int) $upcoming; ?></span>
                    <span class="tap-dash-stat-label"><?php esc_html_e('Próximas', 'travel-agency-platform'); ?></span>
                </div>
            </div>
            <div class="tap-dash-stat-card">
                <div class="tap-dash-stat-icon tap-dash-stat-violet">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div class="tap-dash-stat-body">
                    <span class="tap-dash-stat-value"><?php echo esc_html(TAP_Currency::fmt0($spent)); ?></span>
                    <span class="tap-dash-stat-label"><?php esc_html_e('Total invertido', 'travel-agency-platform'); ?></span>
                </div>
            </div>
            <div class="tap-dash-stat-card">
                <div class="tap-dash-stat-icon tap-dash-stat-rose">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </div>
                <div class="tap-dash-stat-body">
                    <span class="tap-dash-stat-value"><?php echo (int) $fav_count; ?></span>
                    <span class="tap-dash-stat-label"><?php esc_html_e('Favoritos', 'travel-agency-platform'); ?></span>
                </div>
            </div>
        </div>

        <?php
        // Recent bookings (last 5)
        $recent = array_slice($bookings, 0, 5);
        if ($recent) :
			?>
            <div class="tap-dash-section">
                <div class="tap-dash-section-header">
                    <h2><?php esc_html_e('Reservas recientes', 'travel-agency-platform'); ?></h2>
                    <a href="<?php echo esc_url(add_query_arg('seccion', 'bookings', $base)); ?>" class="tap-btn tap-btn-ghost tap-btn-sm"><?php esc_html_e('Ver todas', 'travel-agency-platform'); ?></a>
                </div>
                <div class="tap-table-scroll">
                    <table class="tap-table tap-table-compact">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Fechas', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($recent as $b) :
                                $svc = get_post($b->service_id);
                                ?>
                                <tr>
                                    <td class="tap-dash-code"><?php echo esc_html($b->booking_code); ?></td>
                                    <td><?php echo $svc ? esc_html($svc->post_title) : '—'; ?></td>
                                    <td><?php echo esc_html($b->check_in . ($b->check_out ? ' → ' . $b->check_out : '')); ?></td>
                                    <td class="tap-dash-amount"><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                                    <td><span class="tap-status tap-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(TAP_Emails::status_label($b->status)); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Quick links
        $services = get_posts(['post_type' => ['tap_tour', 'tap_accommodation'], 'post_status' => 'publish', 'posts_per_page' => 3, 'orderby' => 'rand']);
        if ($services) :
			?>
            <div class="tap-dash-section">
                <h2><?php esc_html_e('Explora experiencias', 'travel-agency-platform'); ?></h2>
                <div class="tap-dash-grid-3">
                    <?php
                    foreach ($services as $s) :
                        $img = get_the_post_thumbnail_url($s->ID, 'medium');
                        $price_key = TAP_API::get_price_key($s->post_type);
                        $price = $price_key ? floatval(get_post_meta($s->ID, $price_key, true)) : 0;
                        ?>
                        <a href="<?php echo esc_url(get_permalink($s->ID)); ?>" class="tap-dash-explore-card">
                            <?php if ($img) : ?>
                                <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($s->post_title); ?>" loading="lazy">
                            <?php endif; ?>
                            <div class="tap-dash-explore-body">
                                <h3><?php echo esc_html($s->post_title); ?></h3>
                                <?php if ($price > 0) : ?>
                                    <span class="tap-dash-explore-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
			<?php
        endif;

        return ob_get_clean();
    }

    /* ── client: bookings ──────────────────────────────────────────── */

    private static function client_bookings($user) {
        $bookings = TAP_Booking::get_client_bookings($user->ID);
        $reviewed = TAP_Booking::reviewed_markers($user->ID);

        ob_start();
        ?>
        <div class="tap-dash-header">
            <h1><?php esc_html_e('Mis reservas', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Gestiona tus reservas activas, pasadas y pendientes de pago.', 'travel-agency-platform'); ?></p>
        </div>

        <?php if (empty($bookings)) : ?>
            <div class="tap-dash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <h3><?php esc_html_e('Sin reservas aún', 'travel-agency-platform'); ?></h3>
                <p><?php esc_html_e('Explora nuestros tours y alojamientos para planear tu próximo viaje.', 'travel-agency-platform'); ?></p>
                <a href="<?php echo esc_url(home_url('/tours/')); ?>" class="tap-btn tap-btn-primary"><?php esc_html_e('Explorar tours', 'travel-agency-platform'); ?></a>
            </div>
        <?php else : ?>
            <div class="tap-dash-bookings-list">
                <?php
                foreach ($bookings as $b) :
                    $svc   = get_post($b->service_id);
                    $img   = $svc ? get_the_post_thumbnail_url($svc->ID, 'thumbnail') : '';
                    $can_rev = TAP_Booking::can_review_booking($b, $reviewed);
                    $can_cancel = TAP_Booking::can_cancel_booking($b);
                    $can_pay = TAP_Booking::is_payable($b);
                    ?>
                    <div class="tap-dash-booking-card">
                        <?php if ($img) : ?>
                            <div class="tap-dash-booking-img">
                                <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="tap-dash-booking-body">
                            <div class="tap-dash-booking-top">
                                <div>
                                    <span class="tap-dash-booking-code"><?php echo esc_html($b->booking_code); ?></span>
                                    <h3><?php echo $svc ? esc_html($svc->post_title) : '—'; ?></h3>
                                </div>
                                <span class="tap-status tap-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(TAP_Emails::status_label($b->status)); ?></span>
                            </div>
                            <div class="tap-dash-booking-meta">
                                <span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo esc_html($b->check_in . ($b->check_out ? ' → ' . $b->check_out : '—')); ?>
                                </span>
                                <span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    <?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?>
                                </span>
                                <?php if ($b->adults || $b->children) : ?>
                                    <span>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <?php echo esc_html(($b->adults ?: 0) . '+' . ($b->children ?: 0)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="tap-dash-booking-actions">
                                <a href="<?php echo esc_url(home_url('/booking-detail/?code=' . $b->booking_code)); ?>" class="tap-btn tap-btn-outline tap-btn-sm"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
                                <?php if ($can_pay) : ?>
                                    <a href="<?php echo esc_url(home_url('/checkout?code=' . $b->booking_code)); ?>" class="tap-btn tap-btn-primary tap-btn-sm"><?php esc_html_e('Pagar ahora', 'travel-agency-platform'); ?></a>
                                <?php endif; ?>
                                <?php if ($can_rev) : ?>
                                    <a href="<?php echo esc_url(add_query_arg('tap_review', $b->booking_code, get_permalink($b->service_id))); ?>" class="tap-btn tap-btn-outline tap-btn-sm tap-btn-amber"><?php esc_html_e('Reseña ★', 'travel-agency-platform'); ?></a>
                                <?php endif; ?>
                                <?php if ($can_cancel) : ?>
                                    <button type="button" class="tap-btn tap-btn-outline tap-btn-sm tap-btn-danger tap-dash-cancel" data-id="<?php echo (int) $b->id; ?>" data-code="<?php echo esc_attr($b->booking_code); ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
			<?php
        endif;

        return ob_get_clean();
    }

    /* ── client: favorites ─────────────────────────────────────────── */

    private static function client_favorites($user) {
        $favorites = get_user_meta($user->ID, 'tap_favorites', true);
        $favorites = is_array($favorites) ? array_filter(array_map('absint', $favorites)) : [];

        ob_start();
        ?>
        <div class="tap-dash-header">
            <h1><?php esc_html_e('Favoritos', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Los servicios que guardaste para tu próximo viaje.', 'travel-agency-platform'); ?></p>
        </div>

        <?php if (empty($favorites)) : ?>
            <div class="tap-dash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <h3><?php esc_html_e('Sin favoritos', 'travel-agency-platform'); ?></h3>
                <p><?php esc_html_e('Guarda servicios haciendo clic en el corazón para encontrarlos fácilmente después.', 'travel-agency-platform'); ?></p>
                <a href="<?php echo esc_url(home_url('/tours/')); ?>" class="tap-btn tap-btn-primary"><?php esc_html_e('Explorar tours', 'travel-agency-platform'); ?></a>
            </div>
        <?php else : ?>
            <div class="tap-dash-grid-3">
                <?php
                foreach ($favorites as $fav_id) :
                    $p = get_post($fav_id);
                    if (!$p || 'publish' !== $p->post_status) continue;
                    $img = get_the_post_thumbnail_url($p->ID, 'medium');
                    $price_key = TAP_API::get_price_key($p->post_type);
                    $price = $price_key ? floatval(get_post_meta($p->ID, $price_key, true)) : 0;
                    ?>
                    <div class="tap-dash-fav-card">
                        <a href="<?php echo esc_url(get_permalink($p->ID)); ?>">
                            <?php if ($img) : ?>
                                <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($p->post_title); ?>" loading="lazy">
                            <?php endif; ?>
                        </a>
                        <div class="tap-dash-fav-body">
                            <a href="<?php echo esc_url(get_permalink($p->ID)); ?>"><h3><?php echo esc_html($p->post_title); ?></h3></a>
                            <?php if ($price > 0) : ?>
                                <span class="tap-dash-fav-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></span>
                            <?php endif; ?>
                            <button type="button" class="tap-dash-fav-remove tap-btn tap-btn-ghost tap-btn-sm" data-id="<?php echo (int) $fav_id; ?>" title="<?php esc_attr_e('Quitar de favoritos', 'travel-agency-platform'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
			<?php
        endif;

        return ob_get_clean();
    }

    /* ── client: reviews ───────────────────────────────────────────── */

    private static function client_reviews($user) {
        global $wpdb;
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d ORDER BY created_at DESC",
            $user->ID
        ));

        ob_start();
        ?>
        <div class="tap-dash-header">
            <h1><?php esc_html_e('Mis reseñas', 'travel-agency-platform'); ?></h1>
            <p><?php esc_html_e('Reseñas que publicaste sobre tus experiencias.', 'travel-agency-platform'); ?></p>
        </div>

        <?php if (empty($reviews)) : ?>
            <div class="tap-dash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <h3><?php esc_html_e('Sin reseñas', 'travel-agency-platform'); ?></h3>
                <p><?php esc_html_e('Después de completar una reserva, podrás dejar tu reseña para ayudar a otros viajeros.', 'travel-agency-platform'); ?></p>
            </div>
        <?php else : ?>
            <div class="tap-dash-reviews-list">
                <?php
                foreach ($reviews as $r) :
                    $svc = $r->service_id ? get_post($r->service_id) : null;
                    ?>
                    <div class="tap-dash-review-card">
                        <div class="tap-dash-review-header">
                            <div class="tap-dash-review-stars">
                                <?php for ($i = 1; $i <= 5; $i++) : ?>
                                    <span class="tap-star <?php echo $i <= $r->rating ? 'tap-star-filled' : ''; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <span class="tap-dash-review-date"><?php echo esc_html(date('d/m/Y', strtotime($r->created_at))); ?></span>
                        </div>
                        <h3><?php echo $svc ? esc_html($svc->post_title) : 'Servicio eliminado'; ?></h3>
                        <?php if ($r->title) : ?>
                            <p class="tap-dash-review-title"><?php echo esc_html($r->title); ?></p>
                        <?php endif; ?>
                        <p class="tap-dash-review-content"><?php echo esc_html($r->content); ?></p>
                        <?php if ($r->reply) : ?>
                            <div class="tap-dash-review-reply">
                                <strong><?php esc_html_e('Respuesta:', 'travel-agency-platform'); ?></strong>
                                <?php echo esc_html($r->reply); ?>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="tap-btn tap-btn-ghost tap-btn-sm tap-dash-review-delete" data-id="<?php echo (int) $r->id; ?>">
                            <?php esc_html_e('Eliminar', 'travel-agency-platform'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
			<?php
        endif;

        return ob_get_clean();
    }

}