<?php
/**
 * TAP_Shortcode_Agency_Panel — The agency section of the account dashboard.
 *
 * Renders the full agency panel: bookings, contact leads, listings, featured
 * promotions, commission payouts, disputes and the commission ledger, plus
 * the inline script that drives the status buttons and the PayPal promo SDK.
 * Split out of TAP_Shortcode_Account in 1.5.17; that class keeps the
 * dispatcher and the smaller account shortcodes.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Shortcode_Agency_Panel {

    public static function agency_panel($user) {
        global $wpdb;

        $paypal_ready = class_exists('TAP_PayPal') ? TAP_PayPal::is_ready() : false;
        $paypal_price = class_exists('TAP_Promotions') ? (float) TAP_Promotions::get_price() : 5;

        $agency_id  = TAP_Booking::get_agency_for_user($user->ID);
        $agency     = $agency_id ? get_post($agency_id) : null;
        $agency_name = $agency ? $agency->post_title : __('Your Agency', 'travel-agency-platform');
        $verified   = $agency_id ? get_post_meta($agency_id, '_tap_agency_verified', true) : false;
        $commission = $agency_id ? TAP_Booking::get_agency_commission($agency_id) : 10;
        $stats      = TAP_Booking_Stats::get_booking_stats($agency_id);
        // Tanda C: employees only see operations (bookings/leads/listings);
        // money sections and their queries stay with the agency admin.
        $can_money  = TAP_Roles::can_manage_agency_panel($user);

		$comm_rows = ($can_money && $agency_id) ? $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN commission_status = 'owed' THEN commission_amount END),0) owed,
                COALESCE(SUM(CASE WHEN commission_status = 'disputed' THEN commission_amount END),0) disputed,
                COALESCE(SUM(CASE WHEN commission_status = 'paid' THEN commission_amount END),0) settled
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d
               AND commission_amount > 0
               AND payment_status = 'paid'
               AND status NOT IN ('cancelled', 'refunded')",
            $agency_id
        )) : (object) ['owed' => 0, 'disputed' => 0, 'settled' => 0];
        $settlements = ($can_money && $agency_id) ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE agency_id = %d ORDER BY created_at DESC LIMIT 10",
            $agency_id
        )) : [];

        $comm_ledger = ($can_money && $agency_id) ? $wpdb->get_results($wpdb->prepare(
            "SELECT booking_code, CAST(created_at AS CHAR) created_at, total_amount, commission_amount, commission_status
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d AND commission_amount > 0
             ORDER BY created_at DESC LIMIT 30",
            $agency_id
        )) : [];

        $ab_status = sanitize_key(wp_unslash($_GET['ab_status'] ?? 'all'));
        if (!in_array($ab_status, ['all', 'request', 'pending', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
            $ab_status = 'all';
        }
        $ab_page    = max(1, intval($_GET['ab_page'] ?? 1));
        $ab_per_page = 20;
        $where = $agency_id
            ? $wpdb->prepare('WHERE agency_id = %d', $agency_id)
            : 'WHERE 1=0';
        if ('all' !== $ab_status) {
            $where .= $wpdb->prepare(' AND status = %s', $ab_status);
        }
        $ab_count_rows = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d GROUP BY status",
            $agency_id
        )) : [];
        $ab_map = ['all' => 0, 'request' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($ab_count_rows as $rc) {
            $ab_map[$rc->status] = (int) $rc->c;
        }
        $ab_map['all'] = array_sum($ab_map);
        $ab_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings {$where}");
        $ab_offset = ($ab_page - 1) * $ab_per_page;
        $bookings  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $ab_per_page, $ab_offset
        ));
        $ab_pages = (int) ceil($ab_total / $ab_per_page);
        $booking_counts = [];
        if ($agency_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT service_type, service_id, COUNT(*) as c,
                        SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END) as rev
                 FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d
                 GROUP BY service_type, service_id", $agency_id
            ));
            foreach ($rows as $r) {
                $booking_counts[$r->service_type . ':' . $r->service_id] = [$r->c, (float) $r->rev];
            }
        }

        $listing_labels = [
            'tap_accommodation' => __('Accommodations', 'travel-agency-platform'),
            'tap_tour'          => __('Tours', 'travel-agency-platform'),
            'tap_transport'     => __('Transports', 'travel-agency-platform'),
            'tap_car_rental'    => __('Car Rentals', 'travel-agency-platform'),
            'tap_boat'          => __('Boats', 'travel-agency-platform'),
            'tap_package'       => __('Packages', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipment', 'travel-agency-platform'),
        ];

        $listings = [];
        foreach ($listing_labels as $type => $label) {
            $ids = get_posts([
                'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => TAP_Post_Types::agency_meta_key($type), 'meta_value' => $agency_id, 'fields' => 'ids',
            ]);
            if ($ids) {
                $listings[$type] = [
                    'label'  => $label,
                    'ids'    => $ids,
                    'count'  => count($ids),
                    'edit'   => admin_url('edit.php?post_type=' . $type),
                ];
            }
        }

        /* translators: %s: porcentaje de comisión */
        $empty_state = sprintf(__('No bookings yet. Share your listing and start receiving reservations. Commission: %s%%', 'travel-agency-platform'), esc_html($commission));
        ?>
        <div class="tap-agency-panel">
            <div class="tap-agency-header">
                <div>
                    <h2><?php echo esc_html($agency_name); ?>
                        <?php if ($verified): ?>
                            <span class="tap-verified-badge" title="<?php esc_attr_e('Verified agency', 'travel-agency-platform'); ?>">&#10003; <?php esc_html_e('Verified', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="tap-agency-meta"><?php esc_html_e('Commission', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></p>
                </div>
                <a href="<?php echo esc_url(home_url('/agency-profile/?id=' . $agency_id)); ?>" class="tap-btn"><?php esc_html_e('View Public Profile', 'travel-agency-platform'); ?></a>
            </div>

            <?php
            // Tanda C (C20): approval status banner for the agency's team.
            if ($agency_id && !current_user_can('manage_options')) {
                $tap_ap_status = TAP_Approval::status($agency_id);
                if ('pending' === $tap_ap_status) {
                    ?>
                    <div class="tap-notice"><span class="tap-notice-icon" aria-hidden="true">⚠</span><span><strong><?php esc_html_e('Tu agencia está en revisión', 'travel-agency-platform'); ?>.</strong> <?php esc_html_e('Puedes preparar tus listados, pero serán visibles para los viajeros solo cuando el administrador apruebe tu agencia.', 'travel-agency-platform'); ?></span></div>
                    <?php
                } elseif ('rejected' === $tap_ap_status) {
                    ?>
                    <div class="tap-notice tap-notice-error"><span class="tap-notice-icon" aria-hidden="true">⚠</span><span><strong><?php esc_html_e('Tu agencia no fue aprobada', 'travel-agency-platform'); ?>.</strong> <?php esc_html_e('Contacta a soporte para corregir tus datos y volver a solicitar la revisión.', 'travel-agency-platform'); ?></span></div>
                    <?php
                }
            }
            ?>

            <?php
            if ($agency_id && class_exists('TAP_Subscriptions')):
                $tap_plan    = TAP_Subscriptions::active_plan($agency_id);
                $tap_current = TAP_Subscriptions::current_subscription($agency_id);
                $tap_limit   = TAP_Subscriptions::listing_limit($agency_id);
                $tap_used    = TAP_Subscriptions::listing_count($agency_id);
				?>
            <div class="tap-plan-summary">
                <span><?php esc_html_e('Plan', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->name); ?></strong></span>
                <?php if (!empty($tap_plan->paid_until)): ?>
                    <span><?php esc_html_e('Válido hasta', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->paid_until); ?></strong></span>
                <?php endif; ?>
                <span><?php esc_html_e('Listados', 'travel-agency-platform'); ?>: <strong><?php echo (int) $tap_limit < 0 ? esc_html__('Ilimitados', 'travel-agency-platform') : esc_html($tap_used . ' / ' . $tap_limit); ?></strong></span>
                <span><?php esc_html_e('Comisión', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></span>
                <a href="<?php echo esc_url(home_url('/planes/')); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
            </div>
				<?php if ($tap_current && 'pending' === $tap_current->status): ?>
                <div class="tap-capacity-note" style="background:#fffbeb;color:#92400e;font-size:13px;font-weight:600;padding:10px 14px;border-radius:10px;margin-bottom:16px;">
                    <?php esc_html_e('Tienes una suscripción pendiente de confirmación de pago.', 'travel-agency-platform'); ?>
                </div>
            <?php elseif ((int) $tap_limit >= 0 && $tap_used >= (int) $tap_limit): ?>
                <div class="tap-capacity-note tap-full" style="margin-bottom:16px;">
                    <?php echo esc_html(sprintf(/* translators: %d: número máximo de listados permitidos por el plan */ __('Alcanzaste el límite de %d listados de tu plan. Mejora de plan para publicar más.', 'travel-agency-platform'), (int) $tap_limit)); ?>
                </div>
				<?php
            endif;
endif;
			?>

            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['total']); ?></span><span class="tap-stat-label"><?php esc_html_e('Total Bookings', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['pending']); ?></span><span class="tap-stat-label"><?php esc_html_e('Pending', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html($stats['request'] ?? 0); ?></span><span class="tap-stat-label"><?php esc_html_e('Solicitudes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['confirmed']); ?></span><span class="tap-stat-label"><?php esc_html_e('Confirmed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format($stats['completed'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Completed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Revenue', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Commission', 'travel-agency-platform'); ?></span></div>
                <?php if ($can_money): ?>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($stats['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Net to you', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->owed)); ?></span><span class="tap-stat-label"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b91c1c;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->disputed)); ?></span><span class="tap-stat-label"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->settled)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
                <?php endif; ?>
            </div>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></h3>
                <div class="tap-agency-tabs">
                    <?php
                    $tab_labels = [
                        'all'       => __('All', 'travel-agency-platform'),
                        'request'   => __('Solicitudes', 'travel-agency-platform'),
                        'pending'   => __('Pending', 'travel-agency-platform'),
                        'confirmed' => __('Confirmed', 'travel-agency-platform'),
                        'completed' => __('Completed', 'travel-agency-platform'),
                        'cancelled' => __('Cancelled', 'travel-agency-platform'),
                    ];
                    foreach ($tab_labels as $k => $l):
						?>
                    <a class="tap-tab <?php echo $ab_status === $k ? 'active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['ab_status' => $k, 'ab_page' => 1])); ?>">
                        <?php echo esc_html($l); ?> <span class="tap-tab-count"><?php echo (int) ($ab_map[$k] ?? 0); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($bookings)): ?>
                    <p class="tap-empty"><?php echo esc_html($empty_state); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll">
                <table class="tap-booking-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Code', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Service', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Guest', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Dates', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <?php if ($can_money): ?>
                            <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Net', 'travel-agency-platform'); ?></th>
                            <?php endif; ?>
                            <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($bookings as $b):
                            $service_title = get_the_title($b->service_id);
                            if (empty($service_title)) {
                                $service_title = __('Deleted listing', 'travel-agency-platform');
                            }
                            $client = get_userdata($b->client_id);
                            $b_contact_open = class_exists('TAP_Attribution') && TAP_Attribution::booking_unlocked($b);
                            if ((int) $b->client_id === 0) {
                                $b_name  = $b->guest_name ?: '';
                                $b_email = $b->guest_email ?: '';
                                $b_phone = $b->guest_phone ?: '';
                            } elseif ($client) {
                                $b_name  = $client->display_name;
                                $b_email = $client->user_email;
                                $b_phone = '';
                            } else {
                                $b_name  = '';
                                $b_email = '';
                                $b_phone = '';
                            }
                            $b_display = $b_contact_open && $b_name ? $b_name : TAP_Attribution::mask_name($b_name ?: __('Deleted user', 'travel-agency-platform'));
                            $date_label = $b->check_in . ($b->check_out ? ' &rarr; ' . $b->check_out : '');
							?>
                        <tr>
                            <td><span class="tap-booking-code"><?php echo esc_html($b->booking_code); ?></span><br>
                                <a class="tap-btn tap-btn-xs" href="<?php echo esc_url(home_url('/booking-detail/?code=' . $b->booking_code)); ?>"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></a>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(get_permalink($b->service_id) ?: home_url()); ?>"><?php echo esc_html($service_title); ?></a>
                                <?php if ($b->room_id): $room = get_post($b->room_id); if ($room): ?>
                                    <span class="tap-booking-room"><?php echo esc_html($room->post_title); ?></span>
										<?php
                                endif;
endif;
								?>
                            </td>
                            <td><?php echo esc_html($b_display); ?><br>
                                <?php if ($b_email): ?><small><?php echo esc_html($b_contact_open ? $b_email : TAP_Attribution::mask_email($b_email)); ?></small><?php endif; ?>
                                <?php if ($b_phone && $b_contact_open): ?><br><small><?php echo esc_html($b_phone); ?></small><?php endif; ?>
                                <?php if (!$b_contact_open): ?><br><small class="tap-muted"><?php esc_html_e('Contacto protegido — visible tras una reserva confirmada.', 'travel-agency-platform'); ?></small><?php endif; ?>
                                <small><?php echo esc_html($b->adults . ' ' . __('adults', 'travel-agency-platform') . ($b->children ? ', ' . $b->children . ' ' . __('children', 'travel-agency-platform') : '')); ?></small>
                            </td>
                            <td><?php echo esc_html($date_label); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                            <?php if ($can_money): ?>
                            <td>
                                <?php if ($b->commission_status === 'void'): ?>
                                    <span class="tap-muted">—</span>
                                <?php else: ?>
                                    <?php echo esc_html(TAP_Currency::fmt($b->commission_amount)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(TAP_Currency::fmt(max(0, (float) $b->total_amount - (float) ($b->booking_fee ?? 0) - ($b->commission_status === 'void' ? 0 : (float) $b->commission_amount)))); ?></td>
                            <?php endif; ?>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(TAP_Emails_Send::status_label($b->status)); ?></span></td>
                            <td class="tap-actions">
                                <?php
                                if ($b->status === 'request'):
                                    $options = ['pending' => __('Aceptar', 'travel-agency-platform'), 'cancelled' => __('Rechazar', 'travel-agency-platform')];
                                    foreach ($options as $s => $label):
										?>
                                    <button class="tap-btn tap-status-btn" data-booking-id="<?php echo intval($b->id); ?>" data-status="<?php echo esc_attr($s); ?>"><?php echo esc_html($label); ?></button>
										<?php
                                endforeach; elseif (in_array($b->status, ['pending', 'confirmed'], true)):
                                    $options = ['confirmed' => __('Confirm', 'travel-agency-platform'), 'completed' => __('Complete', 'travel-agency-platform'), 'cancelled' => __('Cancel', 'travel-agency-platform')];
                                    foreach ($options as $s => $label):
                                        if ($b->status === $s) continue;
										?>
                                    <button class="tap-btn tap-status-btn" data-booking-id="<?php echo intval($b->id); ?>" data-status="<?php echo esc_attr($s); ?>" <?php echo ($b->status === 'pending' && $s === 'completed') ? 'disabled' : ''; ?>><?php echo esc_html($label); ?></button>
										<?php
                                endforeach;
endif;
								?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
					<?php if ($ab_pages > 1): ?>
                <nav class="tap-pagination">
						<?php
						echo paginate_links([
                        'base'      => add_query_arg('ab_page', '%#%'),
                        'format'    => '',
                        'current'   => $ab_page,
                        'total'     => $ab_pages,
                        'prev_text' => '&larr;',
                        'next_text' => '&rarr;',
						]);
						?>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($agency_id): ?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Leads de contacto', 'travel-agency-platform'); ?>
                        <span class="tap-tab-count"><?php echo (int) TAP_Leads::count_for_agency($agency_id); ?></span>
                    </h3>
                    <?php if (TAP_Leads::count_for_agency($agency_id) > 0): ?>
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php') . '?action=tap_export_leads&agency=' . (int) $agency_id, 'tap_export_leads_' . $user->ID)); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
                    <?php endif; ?>
                </div>
                <?php
                $leads = TAP_Leads::for_agency($agency_id, 20);
                if (!$leads):
					?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no has recibido mensajes de contacto. Cuando un visitante envíe el formulario de tu perfil o de un servicio, aparecerá aquí.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll">
                <table class="tap-agency-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Mensaje', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($leads as $ld):
                            $ld_service = $ld->service_id ? get_the_title($ld->service_id) : '';
                            $ld_open = class_exists('TAP_Attribution') && TAP_Attribution::contact_unlocked($agency_id, $ld->email);
                            $ld_email = $ld_open ? $ld->email : TAP_Attribution::mask_email($ld->email);
                            $ld_phone = $ld_open ? ($ld->phone ?? '') : TAP_Attribution::mask_phone($ld->phone ?? '');
							?>
                        <tr>
                            <td><strong><?php echo esc_html($ld_open ? $ld->name : TAP_Attribution::mask_name($ld->name)); ?></strong></td>
                            <td>
                                <?php if (is_email($ld->email)): ?><a href="mailto:<?php echo esc_attr($ld_open ? $ld->email : ''); ?>"><?php echo esc_html($ld_email); ?></a>
									<?php
                                else: echo esc_html($ld_email);
endif;
								?>
                                <?php if ($ld_phone): ?><br><small><?php echo esc_html($ld_phone); ?></small><?php endif; ?>
                                <?php if (!$ld_open): ?><br><small class="tap-muted"><?php esc_html_e('Contacto protegido — visible tras una reserva confirmada.', 'travel-agency-platform'); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo $ld_service ? esc_html($ld_service) : '—'; ?></td>
                            <td class="tap-lead-message"><?php echo esc_html(mb_strimwidth($ld->message ?? '', 0, 120, '…')); ?></td>
                            <td><?php echo esc_html($ld->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($listings)): ?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Your Listings', 'travel-agency-platform'); ?></h3>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">+ <?php esc_html_e('Nuevo alojamiento', 'travel-agency-platform'); ?></a>
                </div>
                <div class="tap-listing-grid">
                    <?php
                    foreach ($listings as $type => $info):
                        $booked = 0;
                        $revenue = 0.0;
                        foreach ($info['ids'] as $pid) {
                            if (!empty($booking_counts[$type . ':' . $pid])) {
                                $booked += $booking_counts[$type . ':' . $pid][0];
                                $revenue += $booking_counts[$type . ':' . $pid][1];
                            }
                        }
						?>
                    <div class="tap-listing-card">
                        <div class="tap-listing-title"><?php echo esc_html($info['label']); ?></div>
                        <div class="tap-listing-count"><?php echo esc_html($info['count']); ?></div>
                        <div class="tap-listing-meta"><?php echo esc_html__('listings', 'travel-agency-platform'); ?> &middot; <?php echo esc_html($booked); ?> <?php echo esc_html__('bookings', 'travel-agency-platform'); ?> &middot; <?php echo esc_html(TAP_Currency::fmt($revenue)); ?></div>
                        <?php
                        $manage_url = ('tap_accommodation' === $type)
                            ? home_url('/manage-listing/?id=' . reset($info['ids']))
                            : $info['edit'];
                        ?>
                        <a href="<?php echo esc_url($manage_url); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Manage', 'travel-agency-platform'); ?></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_money): // Destacados, liquidaciones, disputas y libro de comisiones: solo el administrador de la agencia. ?>
            <?php
            if ($agency_id && class_exists('TAP_Promotions')):
                $promo_price = TAP_Promotions::get_price();
                $promo_slots = TAP_Promotions::featured_slots($agency_id);
                $promo_active = TAP_Promotions::active_promos($agency_id);
                $promo_pending = TAP_Promotions::pending_promos($agency_id);
                $promo_listings = [];
                foreach ($listings as $type => $info) {
                    foreach (array_slice($info['ids'], 0, 50) as $pid) {
                        $promo_listings[] = ['id' => (int) $pid, 'type' => $type, 'label' => $info['label'], 'title' => get_the_title($pid)];
                    }
                }
                $promo_listings = array_slice($promo_listings, 0, 15);
                $promo_active_ids = [];
                foreach ($promo_active as $pa) { $promo_active_ids[] = (int) $pa->listing_id; }
                $promo_pending_ids = [];
                foreach ($promo_pending as $pp) { $promo_pending_ids[] = (int) $pp->listing_id; }
				?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Destacados (promociones)', 'travel-agency-platform'); ?></h3>
                    <?php if ($promo_slots > 0): ?>
                        <span class="tap-agency-meta"><?php echo esc_html(sprintf(/* translators: 1: destacados disponibles, 2: máximo de destacados del plan, 3: precio mensual por listado */ __('Disponibles: %d de %d · %s/mes por listado', 'travel-agency-platform'), count($promo_active_ids), $promo_slots, TAP_Currency::fmt($promo_price))); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($promo_slots <= 0): ?>
                    <p class="tap-capacity-note" style="background:#fffbeb;color:#92400e;font-size:13px;font-weight:600;padding:10px 14px;border-radius:10px;">
                        <?php esc_html_e('Tu plan actual no incluye listados destacados. Mejora tu plan para destacar tus servicios en la plataforma.', 'travel-agency-platform'); ?>
                        <a href="<?php echo esc_url(home_url('/planes/')); ?>" class="tap-btn tap-btn-sm" style="margin-left:8px;"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
                    </p>
                <?php elseif (empty($promo_listings)): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no tienes listados publicados para destacar.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                    <?php if ($promo_pending): ?>
                        <p class="tap-capacity-note" style="background:#fffbeb;color:#92400e;font-size:13px;font-weight:600;padding:10px 14px;border-radius:10px;margin-bottom:12px;">
                            <?php esc_html_e('Tienes solicitudes de destacado pendientes de confirmación de pago.', 'travel-agency-platform'); ?>
                        </p>
                    <?php endif; ?>
                    <div class="tap-table-scroll">
                    <table class="tap-agency-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Listado', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($promo_listings as $pl):
                                $is_feat = TAP_Promotions::is_featured($pl['id']);
                                $until = TAP_Promotions::featured_until($pl['id']);
                                $is_pending_promo = in_array((int) $pl['id'], $promo_pending_ids, true);
								?>
                            <tr>
                                <td><?php echo esc_html($pl['title']); ?></td>
                                <td><?php echo esc_html($pl['label']); ?></td>
                                <td>
                                    <?php if ($is_feat && $until): ?>
                                        <span class="tap-status tap-status-confirmed">★ <?php esc_html_e('Destacado hasta', 'travel-agency-platform'); ?> <?php echo esc_html($until); ?></span>
                                    <?php elseif ($is_pending_promo): ?>
                                        <span class="tap-status tap-status-pending"><?php esc_html_e('Pendiente de pago', 'travel-agency-platform'); ?></span>
                                    <?php else: ?>
                                        <span class="tap-status tap-status-pending"><?php esc_html_e('No destacado', 'travel-agency-platform'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tap-actions">
                                    <?php if ($is_feat): ?>
                                        <span class="tap-promo-badge">★</span>
                                    <?php elseif ($is_pending_promo): ?>
                                        <span class="tap-promo-badge" style="background:#ffedd5;color:#c2410c;">&#8987;</span>
                                    <?php elseif ($paypal_ready): ?>
                                        <form class="tap-promo-paypal-form">
                                            <input type="hidden" name="listing_id" value="<?php echo (int) $pl['id']; ?>">
                                            <select name="months" class="tap-promo-months">
                                                <?php foreach ([1, 3, 6, 12, 24] as $m): ?>
                                                    <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?> <?php esc_html_e('mes(es)', 'travel-agency-platform'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="tap-promo-paypal-btn" data-listing-id="<?php echo (int) $pl['id']; ?>" data-price="<?php echo esc_attr((float) TAP_Promotions::get_price()); ?>"></div>
                                        </form>
                                    <?php else: ?>
                                        <form class="tap-promo-form">
                                            <input type="hidden" name="listing_id" value="<?php echo (int) $pl['id']; ?>">
                                            <select name="months" class="tap-promo-months">
                                                <?php foreach ([1, 3, 6, 12, 24] as $m): ?>
                                                    <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?> <?php esc_html_e('mes(es)', 'travel-agency-platform'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="tap-btn tap-btn-sm tap-promo-btn"><?php esc_html_e('Destacar', 'travel-agency-platform'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
</tbody>
                </table></div>
        </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Liquidaciones de comisiones', 'travel-agency-platform'); ?></h3>
                <?php if (!$settlements): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no hay liquidaciones registradas. El administrador procesa los pagos de tus comisiones.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll"><table class="tap-agency-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settlements as $s): ?>
                        <tr>
                            <td>#<?php echo (int) $s->id; ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($s->amount)); ?></td>
                            <td><?php echo esc_html($s->method); ?></td>
                            <td>
                            <?php
                                $ids = array_filter(array_map('intval', explode(',', $s->booking_ids)));
							if ($ids) {
								$ph = implode(',', array_fill(0, count($ids), '%d'));
								$codes = $wpdb->get_col($wpdb->prepare("SELECT booking_code FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$ph})", $ids));
								echo esc_html(implode(', ', $codes ?: $ids));
							} else {
								echo '—';
							}
                            ?>
                            </td>
                            <td><?php echo esc_html($s->note); ?></td>
                            <td>
                                <span class="tap-status <?php echo 'completed' === $s->status ? 'tap-status-confirmed' : ('pending' === $s->status ? 'tap-status-pending' : 'tap-muted'); ?>"><?php echo esc_html(TAP_Payouts::status_label($s->status)); ?></span>
                                <?php if ($s->paid_at): ?><div class="tap-muted"><?php echo esc_html__('Pagada el', 'travel-agency-platform') . ' ' . esc_html($s->paid_at); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo esc_html($s->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Disputas de tus reservas', 'travel-agency-platform'); ?></h3>
                <?php $agency_disputes = $agency_id ? TAP_Disputes::for_agency($agency_id) : []; ?>
                <?php $has_open = array_filter($agency_disputes, fn($d) => 'open' === $d->status); ?>
                <?php if (!$agency_disputes): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('No hay disputas en tus reservas.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll">
                <table class="tap-agency-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Reserva', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Motivo', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($agency_disputes as $ad):
                            $ad_code = $wpdb->get_var($wpdb->prepare("SELECT booking_code FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $ad->booking_id));
							?>
                        <tr>
                            <td><?php echo esc_html($ad_code ?: '#' . (int) $ad->booking_id); ?></td>
                            <td><?php echo esc_html(TAP_Disputes::reasons()[$ad->reason] ?? $ad->reason); ?>
                                <?php if ($ad->details): ?><div class="tap-muted"><?php echo esc_html($ad->details); ?></div><?php endif; ?></td>
                            <td><span class="tap-status <?php echo 'open' === $ad->status ? 'tap-status-pending' : 'tap-status-confirmed'; ?>"><?php echo esc_html(TAP_Disputes::status_label($ad->status)); ?></span></td>
                            <td><?php echo esc_html($ad->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
                <?php if ($has_open): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Mientras una disputa está abierta, la comisión de esa reserva queda retenida.', 'travel-agency-platform'); ?></p>
                <?php endif; ?>
            </div>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Libro de comisiones (30 últimas)', 'travel-agency-platform'); ?></h3>
                <?php if (!$comm_ledger): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no hay comisiones generadas.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll">
                <table class="tap-agency-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Booking', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comm_ledger as $cl): ?>
                        <tr>
                            <td><?php echo esc_html($cl->booking_code); ?></td>
                            <td><?php echo esc_html($cl->created_at); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($cl->total_amount)); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($cl->commission_amount)); ?></td>
                            <td>
                            <?php
                                $cl_label = [
                                    'paid'     => __('Cobrada', 'travel-agency-platform'),
                                    'disputed' => __('En disputa', 'travel-agency-platform'),
                                    'void'     => __('Comisión no pagada', 'travel-agency-platform'),
                                ];
                                $cl_ok = 'paid' === $cl->commission_status;
                                echo '<span class="tap-status ' . ($cl_ok ? 'tap-status-confirmed' : ($cl->commission_status === 'owed' ? 'tap-status-pending' : 'tap-muted')) . '">' . esc_html($cl_label[$cl->commission_status] ?? __('Por cobrar', 'travel-agency-platform')) . '</span>';
								?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; // $can_money: secciones de dinero. ?>

        </div>
        <script>
        (function($) {
            $('body').on('click', '.tap-status-btn', function(e) {
                e.preventDefault();
                var $btn = $(this), bookingId = $btn.data('booking-id'), status = $btn.data('status');
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, {
                    action: 'tap_update_booking_status',
                    booking_id: bookingId,
                    status: status,
                    nonce: tap_ajax.agency_nonce
                }).done(function(res) {
                    if (res && res.success) { window.location.reload(); }
                    else { $btn.prop('disabled', false).text(res && res.data && res.data.message ? '' : '!'); alert(res && res.data ? res.data.message : 'Error'); }
                }).fail(function() {
                    $btn.prop('disabled', false).text('!');
                });
            });
            $('body').on('submit', '.tap-promo-form', function(e) {
                e.preventDefault();
                var $form = $(this), $btn = $form.find('.tap-promo-btn');
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, {
                    action: 'tap_promo_request',
                    listing_id: $form.find('[name="listing_id"]').val(),
                    months: $form.find('[name="months"]').val(),
                    nonce: tap_ajax.agency_nonce
                }).done(function(res) {
                    if (res && res.success) { window.location.reload(); }
                    else { $btn.prop('disabled', false).text('!'); alert(res && res.data ? res.data.message : 'Error'); }
                }).fail(function() {
                    $btn.prop('disabled', false).text('!');
                });
            });
            var paypalPromoInit = false;
            function initPromoPayPal() {
                if (typeof tapPayPal === 'undefined' || paypalPromoInit) return;
                paypalPromoInit = true;
                document.querySelectorAll('.tap-promo-paypal-btn').forEach(function(holder){
                    var $row = jQuery(holder).parents('form.tap-promo-paypal-form');
                    var listingId = holder.getAttribute('data-listing-id');
                    var basePrice = parseFloat(holder.getAttribute('data-price')) || 0;
                    tapPayPal.Buttons({
                        style: { label: 'paypal', layout: 'horizontal', height: 32 },
                        createOrder: function(){
                            var months = $row.find('[name="months"]').val() || 1;
                            return $.post(tap_ajax.ajax_url, {
                                action: 'tap_promo_paypal',
                                listing_id: listingId,
                                months: months,
                                nonce: tap_ajax.agency_nonce
                            }).then(function(res){
                                if (res && res.success) return res.data.order_id;
                                throw new Error(res && res.data && res.data.message ? res.data.message : 'Error');
                            });
                        },
                        onApprove: function(data){
                            return $.post(tap_ajax.ajax_url, {
                                action: 'tap_capture_promo_paypal',
                                paypal_order_id: data.orderID,
                                nonce: tap_ajax.agency_nonce
                            }).then(function(res){
                                if (res && res.success) { alert(res.data.message); window.location.reload(); }
                                else { alert(res && res.data && res.data.message ? res.data.message : 'Error'); }
                            });
                        }
                    }).render(holder);
                });
            }
            var promoCheck = setInterval(function(){ if (typeof tapPayPal !== 'undefined') { initPromoPayPal(); clearInterval(promoCheck); } }, 300);
            setTimeout(function(){ clearInterval(promoCheck); }, 15000);
        })(jQuery);
        </script>
        <?php if ($paypal_ready): ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(TAP_PayPal::get_client_id()); ?>&currency=<?php echo esc_attr(TAP_Currency::code()); ?>" data-namespace="tapPayPal"></script>
        <?php endif; ?>
        <?php
    }
}
