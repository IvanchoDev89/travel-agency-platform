<?php
defined('ABSPATH') || exit;

class TAP_Shortcodes {
    public static function init() {
        $shortcodes = [
            'tap_search'        => [TAP_Shortcode_Search::class, 'search_form'],
            'tap_services'      => [TAP_Shortcode_Search::class, 'services_list'],
            'tap_service_detail' => [TAP_Shortcode_Search::class, 'service_detail'],
            'tap_agencies'      => [TAP_Shortcode_Search::class, 'agencies_list'],
            'tap_agency_detail' => [TAP_Shortcode_Search::class, 'agency_detail'],
            'tap_booking_form'  => [__CLASS__, 'booking_form'],
            'tap_my_bookings'   => [__CLASS__, 'my_bookings'],
            'tap_dashboard'     => [__CLASS__, 'dashboard'],
            'tap_featured'      => [TAP_Shortcode_Search::class, 'featured_services'],
            'tap_agency_services' => [__CLASS__, 'agency_services'],
            'tap_agency_register' => [__CLASS__, 'agency_register'],
            'tap_agency_manage'  => [TAP_Listing_Editor::class, 'agency_manage'],
            'tap_booking_detail' => [__CLASS__, 'booking_detail'],
            'tap_favorites'      => [__CLASS__, 'favorites'],
            'tap_plans'         => [__CLASS__, 'plans'],
            'tap_search_results' => [TAP_Shortcode_Search::class, 'search_results'],
            'tap_chatbot'       => [__CLASS__, 'chatbot'],
        ];

        foreach ($shortcodes as $tag => $callback) {
            add_shortcode($tag, $callback);
        }
    }

    public static function booking_form($atts) {
        $atts = shortcode_atts([
            'service_type' => '',
            'service_id'   => 0,
        ], $atts);

        if (empty($atts['service_type']) || empty($atts['service_id'])) {
            return '<p>' . __('Invalid service configuration.', 'travel-agency-platform') . '</p>';
        }

        $service = get_post(intval($atts['service_id']));
        if (!$service) return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';

        $is_request = TAP_Booking::booking_mode($atts['service_type'], $atts['service_id']) === 'request';
        $prefix = '_tap_' . TAP_Post_Types::meta_prefix($atts['service_type']);

        ob_start();
        ?>
        <div class="tap-booking-form">
            <h3><?php echo $is_request ? esc_html__('Solicitar reserva', 'travel-agency-platform') : esc_html__('Book Now', 'travel-agency-platform'); ?></h3>
            <form id="tap-booking-form" class="tap-form" method="post">
                <input type="hidden" name="action" value="tap_booking_create">
                <input type="hidden" name="service_type" value="<?php echo esc_attr($atts['service_type']); ?>">
                <input type="hidden" name="service_id" value="<?php echo esc_attr($atts['service_id']); ?>">
                <?php wp_nonce_field('tap_booking_nonce', 'tap_booking_nonce'); ?>

                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_check_in"><?php esc_html_e('Check-in Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_in" name="check_in" class="tap-input" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_check_out"><?php esc_html_e('Check-out Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_out" name="check_out" class="tap-input" required>
                    </div>
                </div>
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_adults"><?php esc_html_e('Adults', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_adults" name="adults" min="1" value="1" class="tap-input tap-input-sm" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_children"><?php esc_html_e('Children', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_children" name="children" min="0" value="0" class="tap-input tap-input-sm">
                    </div>
                </div>
                <div class="tap-form-group">
                    <label for="tap_notes"><?php esc_html_e('Special Requests', 'travel-agency-platform'); ?></label>
                    <textarea id="tap_notes" name="notes" rows="3" class="tap-input"></textarea>
                </div>
                <div class="tap-form-group">
                    <span class="tap-total-display"><?php esc_html_e('Total: ', 'travel-agency-platform'); ?>$<span id="tap-total-amount">0.00</span></span>
                </div>
                <div class="tap-form-group">
                    <?php echo TAP_Privacy::consent_field('booking'); ?>
                </div>
                <div class="tap-form-group">
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-lg"><?php echo $is_request ? esc_html__('Enviar solicitud', 'travel-agency-platform') : esc_html__('Book Now', 'travel-agency-platform'); ?></button>
                </div>
                <div class="tap-booking-message"></div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function calculateTotal() {
                var data = {
                    action: 'tap_calculate_booking_total',
                    service_type: '<?php echo esc_js($atts['service_type']); ?>',
                    service_id: '<?php echo esc_js($atts['service_id']); ?>',
                    check_in: $('#tap_check_in').val(),
                    check_out: $('#tap_check_out').val(),
                    adults: $('#tap_adults').val(),
                    children: $('#tap_children').val(),
                    nonce: tap_ajax.nonce
                };

                $.post(tap_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        $('#tap-total-amount').text(res.data.total.toFixed(2));
                    }
                });
            }

            $('#tap_check_in, #tap_check_out, #tap_adults, #tap_children').on('change', calculateTotal);

            $('#tap-booking-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var msg = form.find('.tap-booking-message');
                var total = parseFloat($('#tap-total-amount').text()) || 0;

                if (total <= 0) {
                    msg.removeClass('tap-success').addClass('tap-error')
                       .html('<p><?php echo esc_js(__('Please select dates to calculate the price.', 'travel-agency-platform')); ?></p>');
                    return;
                }

                if (!form.find('input[name="tap_privacy_consent"]').is(':checked')) {
                    msg.removeClass('tap-success').addClass('tap-error')
                       .html('<p><?php echo esc_js(__('Debes aceptar el Aviso de Privacidad para continuar.', 'travel-agency-platform')); ?></p>');
                    return;
                }

                var data = form.serialize();
                data += '&total_amount=' + total + '&nonce=' + tap_ajax.nonce;

                msg.html('<p><?php echo esc_js(__('Creating booking...', 'travel-agency-platform')); ?></p>');

                $.post(tap_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        msg.removeClass('tap-error').addClass('tap-success')
                           .html('<p><?php echo $is_request ? esc_js(__('Solicitud enviada. La agencia la revisará y te avisaremos.', 'travel-agency-platform')) : esc_js(__('Booking created! Redirecting to payment...', 'travel-agency-platform')); ?></p>');
                        setTimeout(function() {
                            window.location.href = res.data.redirect || '<?php echo esc_js(home_url('/checkout')); ?>?code=' + res.data.booking_code;
                        }, 1000);
                    } else {
                        msg.removeClass('tap-success').addClass('tap-error')
                           .html('<p>' + (res.data.message || '<?php echo esc_js(__('Error creating booking.', 'travel-agency-platform')); ?>') + '</p>');
                    }
                }).fail(function() {
                    msg.removeClass('tap-success').addClass('tap-error')
                        .html('<p><?php echo esc_js(__('Connection error.', 'travel-agency-platform')); ?></p>');
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public static function my_bookings($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your bookings.', 'travel-agency-platform') . '</p>';
        }

        $user_id = get_current_user_id();
        $bookings = TAP_Booking::get_client_bookings($user_id);
        $reviewed = TAP_Booking::reviewed_markers($user_id);

        ob_start();
        ?>
        <div class="tap-my-bookings">
            <h2><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></h2>
            <?php if (empty($bookings)): ?>
                <p><?php esc_html_e('No bookings found.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
                <div class="tap-table-scroll"><table class="tap-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Code', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Service', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Dates', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Payment', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Actions', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking):
                            $service = get_post($booking->service_id);
                            $service_marker = $booking->service_type . ':' . $booking->service_id;
                            $can_review = TAP_Booking::can_review_booking($booking, $reviewed);
                        ?>
                        <tr>
                            <td><?php echo esc_html($booking->booking_code); ?></td>
                            <td><?php echo $service ? esc_html($service->post_title) : 'N/A'; ?></td>
                            <td><?php echo esc_html($booking->check_in . ($booking->check_out ? ' - ' . $booking->check_out : '')); ?></td>
                            <td>
                                <?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?>
                                <?php if ((float) ($booking->refund_amount ?? 0) > 0): ?>
                                    <br><span class="tap-refund-note"><?php echo esc_html(sprintf(__('Reembolso: %s', 'travel-agency-platform'), TAP_Currency::fmt((float) $booking->refund_amount))); ?></span>
                                <?php elseif (in_array($booking->status, ['cancelled', 'refunded'], true) && !empty($booking->cancellation_policy) && in_array($booking->service_type, TAP_Booking::service_type_slugs(), true)): ?>
                                    <br><span class="tap-refund-note"><?php esc_html_e('Política no reembolsable', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                            </td>
<td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(TAP_Emails::status_label($booking->status)); ?></span></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($booking->payment_status); ?>"><?php echo esc_html(ucfirst($booking->payment_status)); ?></span></td>
                            <td>
                                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/booking-detail/?code=' . $booking->booking_code)); ?>"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></a>
                                <?php if (TAP_Booking::is_payable($booking)): ?>
                                    <a class="tap-btn tap-btn-sm tap-btn-primary" href="<?php echo esc_url(home_url('/checkout?code=' . $booking->booking_code)); ?>"><?php esc_html_e('Pagar ahora', 'travel-agency-platform'); ?></a>
                                <?php endif; ?>
                                <?php if ($can_review): ?>
                                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(add_query_arg('tap_review', $booking->booking_code, get_permalink($booking->service_id))); ?>"><?php esc_html_e('Deja una reseña ★', 'travel-agency-platform'); ?></a>
                                <?php elseif ('completed' === $booking->status && in_array($service_marker, $reviewed, true)): ?>
                                    <span class="tap-review-done"><?php esc_html_e('✓ Reseña enviada', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                                <?php if (TAP_Booking::can_cancel_booking($booking)): ?>
                                    <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-cancel-booking-btn" data-booking-id="<?php echo (int) $booking->id; ?>" data-confirm="<?php echo esc_attr($booking->booking_code); ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>';
            $('.tap-cancel-booking-btn').on('click', function() {
                var $btn = $(this), id = $btn.data('booking-id');
                var code = $btn.data('confirm') || '';
                if (!confirm('<?php echo esc_js(__('¿Cancelar la reserva ', 'travel-agency-platform')); ?>' + code + '?')) return;
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, { action: 'tap_cancel_booking', nonce: nonce, booking_id: id, booking_code: code })
                    .done(function(res) {
                        if (res && res.success) { location.reload(); }
                        else { alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar', 'travel-agency-platform')); ?>'); }
                    })
                    .fail(function() { alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar', 'travel-agency-platform')); ?>'); });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to access the dashboard.', 'travel-agency-platform') . '</p>';
        }

        $user = wp_get_current_user();
        $is_agency = in_array('tap_agency_admin', (array) $user->roles) || in_array('tap_agency_employee', (array) $user->roles);

        if ($is_agency) {
            return self::agency_panel($user);
        }

        ob_start();
        ?>
        <div class="tap-dashboard">
            <h2><?php printf(__('Welcome, %s!', 'travel-agency-platform'), esc_html($user->display_name)); ?></h2>
            <div class="tap-dashboard-links">
                <a href="<?php echo esc_url(home_url('/my-bookings')); ?>" class="tap-btn"><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></a>
                <a href="<?php echo esc_url(home_url('/agency-profile')); ?>" class="tap-btn"><?php esc_html_e('Agency Profile', 'travel-agency-platform'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_panel($user) {
        global $wpdb;

        $paypal_ready = class_exists('TAP_PayPal') ? TAP_PayPal::is_ready() : false;
        $paypal_price = class_exists('TAP_Promotions') ? (float) TAP_Promotions::get_price() : 5;

        $agency_id  = TAP_Booking::get_agency_for_user($user->ID);
        $agency     = $agency_id ? get_post($agency_id) : null;
        $agency_name = $agency ? $agency->post_title : __('Your Agency', 'travel-agency-platform');
        $verified   = $agency_id ? get_post_meta($agency_id, '_tap_agency_verified', true) : false;
        $commission = $agency_id ? TAP_Booking::get_agency_commission($agency_id) : 10;
        $stats      = TAP_Booking::get_booking_stats($agency_id);

$comm_rows = $agency_id ? $wpdb->get_row($wpdb->prepare(
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
        $settlements = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE agency_id = %d ORDER BY created_at DESC LIMIT 10",
            $agency_id
        )) : [];

        $comm_ledger = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT booking_code, CAST(created_at AS CHAR) created_at, total_amount, commission_amount, commission_status
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d AND commission_amount > 0
             ORDER BY created_at DESC LIMIT 30",
            $agency_id
        )) : [];

        $ab_status = sanitize_key($_GET['ab_status'] ?? 'all');
        if (!in_array($ab_status, ['all', 'request', 'pending', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
            $ab_status = 'all';
        }
        $ab_page    = max(1, intval($_GET['ab_page'] ?? 1));
        $ab_per_page = 20;
        $where = $agency_id
            ? $wpdb->prepare("WHERE agency_id = %d", $agency_id)
            : 'WHERE 1=0';
        if ('all' !== $ab_status) {
            $where .= $wpdb->prepare(" AND status = %s", $ab_status);
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

        $listing_types = [
            'tap_accommodation' => ['meta' => '_tap_acc_agency_id',  'label' => __('Accommodations', 'travel-agency-platform')],
            'tap_tour'          => ['meta' => '_tap_tour_agency_id', 'label' => __('Tours', 'travel-agency-platform')],
            'tap_transport'     => ['meta' => '_tap_trans_agency_id', 'label' => __('Transports', 'travel-agency-platform')],
            'tap_car_rental'    => ['meta' => '_tap_car_agency_id',  'label' => __('Car Rentals', 'travel-agency-platform')],
            'tap_boat'          => ['meta' => '_tap_boat_agency_id', 'label' => __('Boats', 'travel-agency-platform')],
            'tap_package'       => ['meta' => '_tap_pkg_agency_id',  'label' => __('Packages', 'travel-agency-platform')],
        ];

        $listings = [];
        foreach ($listing_types as $type => $cfg) {
            $ids = get_posts([
                'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => $cfg['meta'], 'meta_value' => $agency_id, 'fields' => 'ids',
            ]);
            if ($ids) {
                $listings[$type] = [
                    'label'  => $cfg['label'],
                    'ids'    => $ids,
                    'count'  => count($ids),
                    'edit'   => admin_url('edit.php?post_type=' . $type),
                ];
            }
        }

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

            <?php if ($agency_id && class_exists('TAP_Subscriptions')):
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
                    <?php echo esc_html(sprintf(__('Alcanzaste el límite de %d listados de tu plan. Mejora de plan para publicar más.', 'travel-agency-platform'), (int) $tap_limit)); ?>
                </div>
            <?php endif; endif; ?>

            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['total']); ?></span><span class="tap-stat-label"><?php esc_html_e('Total Bookings', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['pending']); ?></span><span class="tap-stat-label"><?php esc_html_e('Pending', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html($stats['request'] ?? 0); ?></span><span class="tap-stat-label"><?php esc_html_e('Solicitudes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['confirmed']); ?></span><span class="tap-stat-label"><?php esc_html_e('Confirmed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format($stats['completed'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Completed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Revenue', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Commission', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($stats['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Net to you', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->owed)); ?></span><span class="tap-stat-label"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b91c1c;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->disputed)); ?></span><span class="tap-stat-label"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->settled)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
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
                            <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Net', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b):
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
                                <?php endif; endif; ?>
                            </td>
                            <td><?php echo esc_html($b_display); ?><br>
                                <?php if ($b_email): ?><small><?php echo esc_html($b_contact_open ? $b_email : TAP_Attribution::mask_email($b_email)); ?></small><?php endif; ?>
                                <?php if ($b_phone && $b_contact_open): ?><br><small><?php echo esc_html($b_phone); ?></small><?php endif; ?>
                                <?php if (!$b_contact_open): ?><br><small class="tap-muted"><?php esc_html_e('Contacto protegido — visible tras una reserva confirmada.', 'travel-agency-platform'); ?></small><?php endif; ?>
                                <small><?php echo esc_html($b->adults . ' ' . __('adults', 'travel-agency-platform') . ($b->children ? ', ' . $b->children . ' ' . __('children', 'travel-agency-platform') : '')); ?></small>
                            </td>
                            <td><?php echo esc_html($date_label); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                            <td>
                                <?php if ($b->commission_status === 'void'): ?>
                                    <span class="tap-muted">—</span>
                                <?php else: ?>
                                    <?php echo esc_html(TAP_Currency::fmt($b->commission_amount)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(TAP_Currency::fmt(max(0, (float) $b->total_amount - (float) ($b->booking_fee ?? 0) - ($b->commission_status === 'void' ? 0 : (float) $b->commission_amount)))); ?></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(TAP_Emails::status_label($b->status)); ?></span></td>
                            <td class="tap-actions">
                                <?php if ($b->status === 'request'):
                                    $options = ['pending' => __('Aceptar', 'travel-agency-platform'), 'cancelled' => __('Rechazar', 'travel-agency-platform')];
                                    foreach ($options as $s => $label):
                                ?>
                                    <button class="tap-btn tap-status-btn" data-booking-id="<?php echo intval($b->id); ?>" data-status="<?php echo esc_attr($s); ?>"><?php echo esc_html($label); ?></button>
                                <?php endforeach; elseif (in_array($b->status, ['pending', 'confirmed'])):
                                    $options = ['confirmed' => __('Confirm', 'travel-agency-platform'), 'completed' => __('Complete', 'travel-agency-platform'), 'cancelled' => __('Cancel', 'travel-agency-platform')];
                                    foreach ($options as $s => $label):
                                        if ($b->status === $s) continue;
                                ?>
                                    <button class="tap-btn tap-status-btn" data-booking-id="<?php echo intval($b->id); ?>" data-status="<?php echo esc_attr($s); ?>" <?php echo ($b->status === 'pending' && $s === 'completed') ? 'disabled' : ''; ?>><?php echo esc_html($label); ?></button>
                                <?php endforeach; endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($ab_pages > 1): ?>
                <nav class="tap-pagination"><?php
                    echo paginate_links([
                        'base'      => add_query_arg('ab_page', '%#%'),
                        'format'    => '',
                        'current'   => $ab_page,
                        'total'     => $ab_pages,
                        'prev_text' => '&larr;',
                        'next_text' => '&rarr;',
                    ]);
                ?></nav>
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
                        <?php foreach ($leads as $ld):
                            $ld_service = $ld->service_id ? get_the_title($ld->service_id) : '';
                            $ld_open = class_exists('TAP_Attribution') && TAP_Attribution::contact_unlocked($agency_id, $ld->email);
                            $ld_email = $ld_open ? $ld->email : TAP_Attribution::mask_email($ld->email);
                            $ld_phone = $ld_open ? ($ld->phone ?? '') : TAP_Attribution::mask_phone($ld->phone ?? '');
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($ld_open ? $ld->name : TAP_Attribution::mask_name($ld->name)); ?></strong></td>
                            <td>
                                <?php if (is_email($ld->email)): ?><a href="mailto:<?php echo esc_attr($ld_open ? $ld->email : ''); ?>"><?php echo esc_html($ld_email); ?></a><?php else: echo esc_html($ld_email); endif; ?>
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
                    <?php foreach ($listings as $type => $info):
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

            <?php if ($agency_id && class_exists('TAP_Promotions')):
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
                        <span class="tap-agency-meta"><?php echo esc_html(sprintf(__('Disponibles: %d de %d · %s/mes por listado', 'travel-agency-platform'), count($promo_active_ids), $promo_slots, TAP_Currency::fmt($promo_price))); ?></span>
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
                            <?php foreach ($promo_listings as $pl):
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
                                                    <option value="<?php echo $m; ?>"><?php echo esc_html($m); ?> <?php esc_html_e('mes(es)', 'travel-agency-platform'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="tap-promo-paypal-btn" data-listing-id="<?php echo (int) $pl['id']; ?>" data-price="<?php echo esc_attr((float) TAP_Promotions::get_price()); ?>"></div>
                                        </form>
                                    <?php else: ?>
                                        <form class="tap-promo-form">
                                            <input type="hidden" name="listing_id" value="<?php echo (int) $pl['id']; ?>">
                                            <select name="months" class="tap-promo-months">
                                                <?php foreach ([1, 3, 6, 12, 24] as $m): ?>
                                                    <option value="<?php echo $m; ?>"><?php echo esc_html($m); ?> <?php esc_html_e('mes(es)', 'travel-agency-platform'); ?></option>
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
                            <td><?php
                                $ids = array_filter(array_map('intval', explode(',', $s->booking_ids)));
                                if ($ids) {
                                    $ph = implode(',', array_fill(0, count($ids), '%d'));
                                    $codes = $wpdb->get_col($wpdb->prepare("SELECT booking_code FROM {$wpdb->prefix}tap_bookings WHERE id IN ({$ph})", $ids));
                                    echo esc_html(implode(', ', $codes ?: $ids));
                                } else {
                                    echo '—';
                                }
                            ?></td>
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
                        <?php foreach ($agency_disputes as $ad):
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
                            <td><?php
                                $cl_label = [
                                    'paid'     => __('Cobrada', 'travel-agency-platform'),
                                    'disputed' => __('En disputa', 'travel-agency-platform'),
                                    'void'     => __('Comisión no pagada', 'travel-agency-platform'),
                                ];
                                $cl_ok = 'paid' === $cl->commission_status;
                                echo '<span class="tap-status ' . ($cl_ok ? 'tap-status-confirmed' : ($cl->commission_status === 'owed' ? 'tap-status-pending' : 'tap-muted')) . '">' . esc_html($cl_label[$cl->commission_status] ?? __('Por cobrar', 'travel-agency-platform')) . '</span>';
                            ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

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

    public static function plans($atts) {
        if (!class_exists('TAP_Subscriptions')) {
            return '<p class="tap-empty">' . esc_html__('Plans are not available.', 'travel-agency-platform') . '</p>';
        }
        $plans = TAP_Subscriptions::get_plans();
        if (!$plans) {
            return '<p class="tap-empty">' . esc_html__('No plans configured yet.', 'travel-agency-platform') . '</p>';
        }

        $user      = wp_get_current_user();
        $agency_id = 0;
        if (is_user_logged_in()) {
            $agency_id = (int) TAP_Booking::get_agency_for_user($user->ID);
        }
        $current      = $agency_id ? TAP_Subscriptions::current_subscription($agency_id) : null;
        $active_plan  = $agency_id ? TAP_Subscriptions::active_plan($agency_id) : null;
        $nonce        = wp_create_nonce('tap_plan_nonce');
        $paypal_ready = class_exists('TAP_PayPal') ? TAP_PayPal::is_ready() : false;

        $html = '<div class="tap-plans-grid">';
        foreach ($plans as $plan) {
            $is_current  = $active_plan && (int) $active_plan->id === (int) $plan->id;
            $is_pending  = $current && 'pending' === $current->status && (int) $current->plan_id === (int) $plan->id;
            $features    = json_decode($plan->features ?: '', true);
            if (!is_array($features)) {
                $features = array_filter(array_map('trim', explode("\n", (string) $plan->features)));
            }
            $limit_txt = (int) $plan->listing_limit < 0
                ? __('Listados ilimitados', 'travel-agency-platform')
                : sprintf(__('Hasta %d listados', 'travel-agency-platform'), (int) $plan->listing_limit);
            $commission_txt = null !== $plan->commission_rate
                ? sprintf(__('Comisión solo %s%%', 'travel-agency-platform'), (float) $plan->commission_rate)
                : __('Comisión estándar', 'travel-agency-platform');

            $html .= '<div class="tap-plan-card' . ($is_current ? ' tap-plan-active' : '') . '">';
            $html .= '<div class="tap-plan-head">';
            $html .= '<h3>' . esc_html($plan->name) . '</h3>';
            $html .= '<p class="tap-plan-price">' . esc_html(TAP_Currency::fmt($plan->price_monthly)) . ' <span>' . esc_html__('/mes', 'travel-agency-platform') . '</span></p>';
            $html .= '</div>';
            $html .= '<ul class="tap-plan-features">';
            $html .= '<li>' . esc_html($limit_txt) . '</li>';
            $html .= '<li>' . esc_html($commission_txt) . '</li>';
            $html .= '<li>' . sprintf(esc_html__('%d destacado(s)', 'travel-agency-platform'), (int) $plan->featured_slots) . '</li>';
            foreach ($features as $f) {
                if (is_string($f) && '' !== trim($f)) {
                    $html .= '<li>' . esc_html($f) . '</li>';
                }
            }
            $html .= '</ul>';
            if ($is_current) {
                $html .= '<p class="tap-plan-badge">' . esc_html__('Plan actual', 'travel-agency-platform') . '</p>';
            } elseif ($is_pending) {
                $html .= '<p class="tap-plan-badge tap-plan-pending">' . esc_html__('Pago pendiente de confirmación', 'travel-agency-platform') . '</p>';
            } elseif ($agency_id) {
                $html .= $paypal_ready && (float) $plan->price_monthly > 0
                    ? '<button type="button" class="tap-btn tap-btn-primary tap-plan-paypal" data-plan-id="' . (int) $plan->id . '" data-plan-name="' . esc_attr($plan->name) . '" data-amount="' . esc_attr((float) $plan->price_monthly) . '">' . esc_html__('Pagar con PayPal', 'travel-agency-platform') . '</button>'
                    : '<button type="button" class="tap-btn tap-btn-primary tap-plan-subscribe" data-plan-id="' . (int) $plan->id . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html__('Seleccionar plan', 'travel-agency-platform') . '</button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        if ($agency_id && !$current) {
            $html .= '<p class="tap-plan-note">' . esc_html__('Tu agencia usa el plan Gratis. Elige un plan para desbloquear más listados y una comisión menor.', 'travel-agency-platform') . '</p>';
        } elseif (!$agency_id) {
            $html .= '<p class="tap-plan-note">' . esc_html__('Inicia sesión como agencia para seleccionar un plan.', 'travel-agency-platform') . '</p>';
        }

        $html .= '<script>
        (function($){
          $(document).on("click", ".tap-plan-subscribe", function(){
            var $btn = $(this).prop("disabled", true);
            $.post(tap_ajax.ajax_url, {
              action: "tap_agency_subscribe",
              plan_id: $btn.data("plan-id"),
              nonce: $btn.data("nonce")
            }).done(function(res){
              if (res && res.success) {
                alert(res.data.message);
                window.location.reload();
              } else {
                alert(res && res.data && res.data.message ? res.data.message : "Error");
                $btn.prop("disabled", false);
              }
            }).fail(function(){ alert("Error"); $btn.prop("disabled", false); });
          });

          var paypalReady = false;
          function initPlanPayPal() {
            if (typeof tapPayPal === "undefined" || paypalReady) return;
            paypalReady = true;
            document.querySelectorAll(".tap-plan-paypal").forEach(function(btn){
              var planId = btn.getAttribute("data-plan-id");
              tapPayPal.Buttons({
                createOrder: function(){
                  return $.post(tap_ajax.ajax_url, {
                    action: "tap_subscribe_paypal",
                    plan_id: planId,
                    nonce: ' . wp_json_encode($nonce) . '
                  }).then(function(res){
                    if (res.success) return res.data.order_id;
                    throw new Error(res.data.message || "Error");
                  });
                },
                onApprove: function(data){
                  return $.post(tap_ajax.ajax_url, {
                    action: "tap_capture_subscription_paypal",
                    paypal_order_id: data.orderID,
                    nonce: ' . wp_json_encode($nonce) . '
                  }).then(function(res){
                    if (res.success) { alert(res.data.message); window.location.reload(); }
                    else { alert(res.data.message || "Error"); }
                  });
                }
              }).render(btn);
            });
          }
          var planCheck = setInterval(function(){ if (typeof tapPayPal !== "undefined") { initPlanPayPal(); clearInterval(planCheck); } }, 300);
          setTimeout(function(){ clearInterval(planCheck); }, 15000);
        })(jQuery);
        </script>';

        if ($paypal_ready) {
            $html .= '<script src="https://www.paypal.com/sdk/js?client-id=' . esc_attr(TAP_PayPal::get_client_id()) . '&currency=' . esc_attr(TAP_Currency::code()) . '" data-namespace="tapPayPal"></script>';
        }

        return $html;
    }

    private static function ids_by_meta(array $meta_keys, $value) {
        global $wpdb;
        $meta_keys = array_values(array_filter(array_map('strval', $meta_keys)));
        if (empty($meta_keys)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ($placeholders)",
            array_merge([(string) $value], $meta_keys)
        )));
    }

    private static function active_listing_ids(array $types) {
        $active_keys = [];
        foreach ($types as $pt) {
            $keys = TAP_Promotions::keys_for_type($pt);
            if ($keys) {
                $active_keys[] = $keys['active_key'];
            }
        }
        return self::exclude_pending(self::ids_by_meta($active_keys, '1'));
    }

    /**
     * Pending-agency listings are hidden by exclusion (post__in + post__not_in
     * cannot be combined in WP_Query, so the exclusion is folded into the id list).
     */
    private static function exclude_pending(array $ids) {
        $excluded = array_map('intval', TAP_Approval::excluded_listing_ids());
        if (empty($excluded)) {
            return $ids;
        }
        return array_values(array_diff($ids, $excluded));
    }

    public static function agency_services($atts) {
        $atts = shortcode_atts(['agency' => 0, 'limit' => -1], $atts);
        $agency_id = intval($atts['agency']);
        if (!$agency_id) return '';

        if (!TAP_Approval::is_approved($agency_id)) {
            $can_view = current_user_can('manage_options');
            if (!$can_view) {
                $owner_id = (int) get_post_meta($agency_id, '_tap_agency_user_id', true);
                $can_view = $owner_id === get_current_user_id();
            }
            if (!$can_view) {
                return '';
            }
        }

        $all_services = [];
        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        // IDs resolved per type with indexed meta lookups (no OR meta_query).
        $ids = [];
        foreach ($types as $type) {
            $prefix = '_tap_' . TAP_Post_Types::meta_prefix($type) . '_agency_id';
            $ids = array_merge($ids, self::ids_by_meta([$prefix], $agency_id));
        }
        $posts = get_posts([
            'post_type'      => $types,
            'posts_per_page' => intval($atts['limit']),
            'post_status'    => 'publish',
            'post__in'       => array_values(array_unique($ids)) ?: [0],
            'no_found_rows'  => true,
        ]);

        foreach ($posts as $p) {
            $all_services[] = $p;
        }

        if (empty($all_services)) {
            return '<p>' . __('No services from this agency.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        ?>
        <div class="tap-agency-services">
            <?php foreach ($all_services as $service): ?>
                <div class="tap-service-mini">
                    <a href="<?php echo esc_url(get_permalink($service->ID)); ?>">
                        <?php echo esc_html($service->post_title); ?>
                    </a>
                    <span class="tap-service-type">(<?php echo esc_html(TAP_Post_Types::get_service_types()[$service->post_type] ?? $service->post_type); ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_register($atts) {
        if (is_user_logged_in()) {
            return '<p>' . __('You already have an account. Go to your', 'travel-agency-platform') . ' <a href="' . esc_url(home_url('/mi-cuenta/')) . '">' . __('dashboard', 'travel-agency-platform') . '</a>.</p>';
        }

        ob_start();
        ?>
        <div class="tap-register">
            <h2><?php esc_html_e('Registra tu agencia', 'travel-agency-platform'); ?></h2>
            <p class="tap-register-intro"><?php esc_html_e('Crea una cuenta de agencia, publica tus alojamientos y tours, y empieza a recibir reservas de miles de viajeros.', 'travel-agency-platform'); ?></p>

            <form id="tap-agency-register-form" class="tap-form tap-form-grid" autocomplete="on">
                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <input type="text" name="tap_hp" tabindex="-1" autocomplete="off" value="">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_agency_name"><?php esc_html_e('Agency Name', 'travel-agency-platform'); ?> *</label>
                    <input type="text" id="tap_reg_agency_name" name="agency_name" class="tap-input" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_email"><?php esc_html_e('Email', 'travel-agency-platform'); ?> *</label>
                    <input type="email" id="tap_reg_email" name="email" class="tap-input" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_username"><?php esc_html_e('Username', 'travel-agency-platform'); ?> *</label>
                    <input type="text" id="tap_reg_username" name="username" class="tap-input" pattern="[a-zA-Z0-9_]+" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_password"><?php esc_html_e('Password', 'travel-agency-platform'); ?> *</label>
                    <input type="password" id="tap_reg_password" name="password" class="tap-input" minlength="8" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_phone"><?php esc_html_e('Phone', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_phone" name="phone" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_whatsapp"><?php esc_html_e('WhatsApp', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_whatsapp" name="whatsapp" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_website"><?php esc_html_e('Website', 'travel-agency-platform'); ?></label>
                    <input type="url" id="tap_reg_website" name="website" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_city"><?php esc_html_e('City', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_city" name="city" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_country"><?php esc_html_e('Country', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_country" name="country" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_address"><?php esc_html_e('Address', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_address" name="address" class="tap-input">
                </div>
                <div class="tap-input-group tap-span-2">
                    <label for="tap_reg_description"><?php esc_html_e('Describe tu agencia', 'travel-agency-platform'); ?></label>
                    <textarea id="tap_reg_description" name="description" class="tap-input" rows="4"></textarea>
                </div>
                <div class="tap-span-2 tap-register-kyc">
                    <h3><?php esc_html_e('Identificación (verificación de la agencia)', 'travel-agency-platform'); ?></h3>
                    <p class="tap-register-intro"><?php esc_html_e('Tus datos de identificación solo los revisa el administrador para aprobar tu agencia.', 'travel-agency-platform'); ?></p>
                    <div class="tap-form-grid">
                        <div class="tap-input-group">
                            <label for="tap_reg_kyc_legal_name"><?php esc_html_e('Representante legal / razón social', 'travel-agency-platform'); ?> *</label>
                            <input type="text" id="tap_reg_kyc_legal_name" name="kyc_legal_name" class="tap-input" required>
                        </div>
                        <div class="tap-input-group">
                            <label><?php esc_html_e('Tipo de documento', 'travel-agency-platform'); ?> *</label>
                            <select name="kyc_doc_type" id="tap_reg_kyc_doc_type" class="tap-input" required>
                                <option value="">—</option>
                                <option value="fisica"><?php esc_html_e('Física', 'travel-agency-platform'); ?></option>
                                <option value="juridica"><?php esc_html_e('Jurídica', 'travel-agency-platform'); ?></option>
                            </select>
                        </div>
                        <div class="tap-input-group">
                            <label for="tap_reg_kyc_doc_number"><?php esc_html_e('Cédula', 'travel-agency-platform'); ?> *</label>
                            <input type="text" id="tap_reg_kyc_doc_number" name="kyc_doc_number" class="tap-input" required>
                        </div>
                        <div class="tap-input-group" id="tap_reg_kyc_tax_wrap" style="display:none;">
                            <label for="tap_reg_kyc_legal_tax_id"><?php esc_html_e('Cédula jurídica', 'travel-agency-platform'); ?></label>
                            <input type="text" id="tap_reg_kyc_legal_tax_id" name="kyc_legal_tax_id" class="tap-input">
                        </div>
                        <div class="tap-input-group tap-span-2">
                            <label class="tap-check-label"><input type="checkbox" name="kyc_accept" value="1" required> <?php esc_html_e('Confirmo que los datos son verídicos y acepto los términos y condiciones de verificación.', 'travel-agency-platform'); ?></label>
                        </div>
                        <div class="tap-input-group tap-span-2">
                            <?php echo TAP_Privacy::consent_field('agency_registration'); ?>
                        </div>
                    </div>
                </div>
                <div class="tap-span-2">
                    <div id="tap-register-message" class="tap-booking-message"></div>
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-block"><?php esc_html_e('Crear mi cuenta de agencia', 'travel-agency-platform'); ?></button>
                </div>
            </form>
        </div>
        <script>
        (function($) {
            $('#tap-agency-register-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this), $btn = $form.find('button[type=submit]'), $msg = $('#tap-register-message');
                var data = {
                    action: 'tap_agency_register',
                    nonce: tap_ajax.register_nonce
                };
                $.each($form.serializeArray(), function(i, f) { data[f.name] = f.value; });
                $msg.removeClass('tap-success tap-error').html('');
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, data)
                    .done(function(res) {
                        if (res && res.success) {
                            $msg.removeClass('tap-error').addClass('tap-success')
                                .html('<p>' + (res.data.message || 'OK') + '</p>');
                            window.location.href = res.data.redirect;
                        } else {
                            $msg.removeClass('tap-success').addClass('tap-error')
                                .html('<p>' + (res && res.data ? res.data.message : 'Error') + '</p>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear mi cuenta de agencia', 'travel-agency-platform')); ?>');
                        }
                    })
                    .fail(function() {
                        $msg.removeClass('tap-success').addClass('tap-error')
                            .html('<p><?php echo esc_js(__('Network error. Please try again.', 'travel-agency-platform')); ?></p>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear mi cuenta de agencia', 'travel-agency-platform')); ?>');
                    });
            });

            $('#tap_reg_kyc_doc_type').on('change', function() {
                var v = $(this).val();
                var $tax = $('#tap_reg_kyc_tax_wrap');
                if (v === 'juridica') {
                    $tax.show();
                    $tax.find('input').prop('required', true);
                } else {
                    $tax.hide();
                    $tax.find('input').prop('required', false);
                }
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function checkout($atts) {
        $atts = shortcode_atts(['code' => ''], $atts);

        $booking_code = $atts['code'] ?: sanitize_text_field($_GET['code'] ?? '');
        if (!$booking_code) {
            return '<p>' . __('No booking specified.', 'travel-agency-platform') . '</p>';
        }

        $booking = TAP_Booking::get_booking_by_code($booking_code);
        if (!$booking) {
            return '<p>' . __('Booking not found.', 'travel-agency-platform') . '</p>';
        }

        $uid = get_current_user_id();
        $can = false;
        if ($uid) {
            $can = (int) $booking->client_id === $uid || current_user_can('manage_options');
            if (!$can && $booking->agency_id) {
                $agency = TAP_Booking::get_agency_for_user($uid);
                if ($agency && (int) $agency === (int) $booking->agency_id) $can = true;
            }
        }

        $is_guest = !$uid && (int) $booking->client_id === 0;

        if (!$uid && !$is_guest) {
            return '<p>' . __('Please log in to view this booking.', 'travel-agency-platform') . '</p>';
        }

        if ($uid && !$can) {
            return '<p>' . __('You do not have permission to view this booking.', 'travel-agency-platform') . '</p>';
        }

        if ($can) {
            $guest_verified = true;
        } elseif ($is_guest) {
            $guest_verified = TAP_Ajax::get_guest_pay_token($booking->booking_code);
            if (!$guest_verified) {
                $email = sanitize_email($_GET['email'] ?? '');
                if (is_email($email) && strcasecmp($email, (string) $booking->guest_email) === 0) {
                    TAP_Ajax::set_guest_pay_token($booking->booking_code);
                    $guest_verified = true;
                }
            }
        } else {
            $guest_verified = false;
        }

        $service = get_post($booking->service_id);
        $status_success = isset($_GET['status']) && $_GET['status'] === 'success';
        $status_cancel  = isset($_GET['status']) && $_GET['status'] === 'cancel';

        ob_start();
        ?>
        <div class="tap-checkout">
            <h2><?php esc_html_e('Complete Your Payment', 'travel-agency-platform'); ?></h2>

            <?php if ($status_success): ?>
                <div class="tap-success"><?php esc_html_e('Payment completed! Thank you for your booking.', 'travel-agency-platform'); ?></div>
            <?php elseif ($status_cancel): ?>
                <div class="tap-error"><?php esc_html_e('Payment was cancelled. You can try again.', 'travel-agency-platform'); ?></div>
            <?php endif; ?>

            <?php if (!$can && $is_guest && !$guest_verified): ?>
                <div class="tap-voucher-lookup">
                    <p><?php esc_html_e('Para pagar tu reserva, verifica el correo electrónico con el que la realizaste.', 'travel-agency-platform'); ?></p>
                    <form class="tap-manage-form" method="get" action="">
                        <div class="tap-form-grid">
                            <div class="tap-field tap-span-2">
                                <label><?php esc_html_e('Correo de la reserva', 'travel-agency-platform'); ?></label>
                                <input type="email" name="email" required autocomplete="email" placeholder="tucorreo@ejemplo.com">
                            </div>
                            <input type="hidden" name="code" value="<?php echo esc_attr($booking->booking_code); ?>">
                            <div class="tap-field tap-field-actions">
                                <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Continuar al pago', 'travel-agency-platform'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <?php if ($booking->payment_status === 'paid'): ?>
                <div class="tap-success">
                    <p><?php esc_html_e('This booking has been paid.', 'travel-agency-platform'); ?></p>
                    <p><strong><?php esc_html_e('Booking Code:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($booking->booking_code); ?></p>
                    <a href="<?php echo esc_url($uid ? home_url('/my-bookings') : home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code))); ?>" class="tap-btn tap-btn-primary"><?php esc_html_e('View voucher', 'travel-agency-platform'); ?></a>
                </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <?php if ($booking->status === 'request'): ?>
                <div class="tap-booking-message tap-success">
                    <p><?php esc_html_e('Tu solicitud de reserva está pendiente de confirmación de la agencia. Te avisaremos por correo cuando sea aceptada para que completes el pago.', 'travel-agency-platform'); ?></p>
                </div>
            <?php elseif (in_array($booking->status, ['cancelled', 'refunded'], true)): ?>
                <div class="tap-error">
                    <p><?php esc_html_e('Esta reserva fue cancelada o rechazada y ya no puede pagarse.', 'travel-agency-platform'); ?></p>
                </div>
            <?php endif; ?>

            <div class="tap-checkout-summary">
                <h3><?php esc_html_e('Booking Summary', 'travel-agency-platform'); ?></h3>
                <table class="tap-table">
                    <tr><td><strong><?php esc_html_e('Code:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->booking_code); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Service:', 'travel-agency-platform'); ?></strong></td><td><?php echo $service ? esc_html($service->post_title) : 'N/A'; ?></td></tr>
                    <?php if ($booking->check_in): ?>
                    <tr><td><strong><?php esc_html_e('Check-in:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_in); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking->check_out): ?>
                    <tr><td><strong><?php esc_html_e('Check-out:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_out); ?></td></tr>
                    <?php endif; ?>
                    <tr><td><strong><?php esc_html_e('Adults:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->adults); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Children:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->children); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Total:', 'travel-agency-platform'); ?></strong></td><td><strong><?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?></strong></td></tr>
                    <?php if ((float) ($booking->refund_amount ?? 0) > 0): ?>
                    <tr><td><strong><?php esc_html_e('Reembolso aplicado:', 'travel-agency-platform'); ?></strong></td><td><span class="tap-refund-note">-<?php echo esc_html(TAP_Currency::fmt((float) $booking->refund_amount)); ?></span></td></tr>
                    <?php endif; ?>
                    <tr><td><strong><?php esc_html_e('Status:', 'travel-agency-platform'); ?></strong></td><td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></td></tr>
                </table>
            </div>

<?php if ($booking->total_amount > 0 && $booking->payment_status !== 'paid' && $booking->status !== 'request'): ?>
                <div class="tap-checkout-payment">
                    <h3><?php esc_html_e('Pay with PayPal', 'travel-agency-platform'); ?></h3>
                    <?php if (TAP_PayPal::is_ready()): ?>
                    <div id="tap-paypal-button-container"></div>
                    <div id="tap-paypal-message" class="tap-booking-message"></div>

                    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(TAP_PayPal::get_client_id()); ?>&currency=<?php echo esc_attr(TAP_Currency::code()); ?>" data-namespace="tapPayPal"></script>
                <script>
                jQuery(document).ready(function($) {
                    var bookingId = <?php echo intval($booking->id); ?>;
                    var paypalLoaded = false;

                    function loadPayPal() {
                        if (typeof tapPayPal === 'undefined' || paypalLoaded) return;
                        paypalLoaded = true;

                        tapPayPal.Buttons({
                            createOrder: function() {
                                return $.ajax({
                                    url: tap_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'tap_create_paypal_order',
                                        booking_id: bookingId,
                                        nonce: tap_ajax.nonce
                                    }
                                }).then(function(res) {
                                    if (res.success) {
                                        return res.data.order_id;
                                    }
                                    throw new Error(res.data.message || '<?php echo esc_js(__('Error creating PayPal order', 'travel-agency-platform')); ?>');
                                });
                            },
                            onApprove: function(data) {
                                var msg = $('#tap-paypal-message');
                                msg.removeClass('tap-error tap-success').html('<p><?php echo esc_js(__('Processing payment...', 'travel-agency-platform')); ?></p>');

                                $.ajax({
                                    url: tap_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'tap_capture_paypal_order',
                                        paypal_order_id: data.orderID,
                                        nonce: tap_ajax.nonce
                                    }
                                }).then(function(res) {
                                    if (res.success) {
                                        msg.removeClass('tap-error').addClass('tap-success')
                                           .html('<p><?php echo esc_js(__('Payment successful! Your booking is confirmed.', 'travel-agency-platform')); ?></p>');
                                        setTimeout(function() {
                                            window.location.href = '<?php echo esc_url($uid ? home_url('/my-bookings') : home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code))); ?>';
                                        }, 2000);
                                    } else {
                                        msg.removeClass('tap-success').addClass('tap-error')
                                           .html('<p>' + (res.data.message || '<?php echo esc_js(__('Payment failed.', 'travel-agency-platform')); ?>') + '</p>');
                                    }
                                });
                            },
                            onCancel: function() {
                                $('#tap-paypal-message').removeClass('tap-success').addClass('tap-error')
                                    .html('<p><?php echo esc_js(__('Payment cancelled.', 'travel-agency-platform')); ?></p>');
                            },
                            onError: function(err) {
                                $('#tap-paypal-message').removeClass('tap-success').addClass('tap-error')
                                    .html('<p><?php echo esc_js(__('An error occurred with PayPal.', 'travel-agency-platform')); ?></p>');
                                console.error('PayPal Error:', err);
                            }
                        }).render('#tap-paypal-button-container');
                    }

                    var checkInterval = setInterval(function() {
                        if (typeof tapPayPal !== 'undefined') {
                            loadPayPal();
                            clearInterval(checkInterval);
                        }
                    }, 300);

                    setTimeout(function() { clearInterval(checkInterval); }, 15000);
                });
                </script>
                    <?php else: ?>
                    <p class="tap-empty"><?php esc_html_e('Online payment is temporarily unavailable. Your reservation is registered and will be confirmed by the agency.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function booking_detail($atts) {
        global $wpdb;
        $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $email = isset($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        $uid   = get_current_user_id();

        if ('' === $code) {
            ob_start();
            ?>
            <div class="tap-voucher-lookup">
                <p><?php esc_html_e('Enter your booking code to view the detail and voucher.', 'travel-agency-platform'); ?></p>
                <form class="tap-manage-form" method="get" action="">
                    <div class="tap-form-grid">
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Código de reserva', 'travel-agency-platform'); ?></label>
                            <input type="text" name="code" required placeholder="TAP-XXXXXXXX-XXXXXX">
                        </div>
                        <?php if (!$uid): ?>
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Correo electrónico de la reserva', 'travel-agency-platform'); ?></label>
                            <input type="email" name="email" required placeholder="tucorreo@ejemplo.com">
                        </div>
                        <?php endif; ?>
                        <div class="tap-field tap-field-actions">
                            <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
            <?php
            return ob_get_clean();
        }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s",
            $code
        ));
        if (!$booking) {
            return '<p class="tap-empty">' . esc_html__('Booking not found.', 'travel-agency-platform') . '</p>';
        }

        if ($uid) {
            $can  = (int) $booking->client_id === $uid;
            if (!$can && user_can($uid, 'manage_options')) $can = true;
            if (!$can && $booking->agency_id) {
                $agency = TAP_Booking::get_agency_for_user($uid);
                if ($agency && (int) $agency === (int) $booking->agency_id) $can = true;
            }
            if (!$can) {
                return '<p class="tap-empty">' . esc_html__('You do not have permission to view this booking.', 'travel-agency-platform') . '</p>';
            }
        } else {
            if ((int) $booking->client_id !== 0) {
                return '<p class="tap-empty"><a href="' . esc_url(wp_login_url(home_url('/booking-detail/'))) . '">' . esc_html__('Log in to view your booking voucher', 'travel-agency-platform') . '</a></p>';
            }
            if ('' === $email) {
                ob_start();
                ?>
                <div class="tap-voucher-lookup">
                    <p><?php esc_html_e('Verifica el correo con el que hiciste la reserva para ver tu voucher.', 'travel-agency-platform'); ?></p>
                    <form class="tap-manage-form" method="get" action="">
                        <div class="tap-form-grid">
                            <div class="tap-field tap-span-2">
                                <label><?php esc_html_e('Correo electrónico de la reserva', 'travel-agency-platform'); ?></label>
                                <input type="email" name="email" required autocomplete="email" placeholder="tucorreo@ejemplo.com">
                            </div>
                            <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>">
                            <div class="tap-field tap-field-actions">
                                <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php
                return ob_get_clean();
            }
            if (strcasecmp($email, (string) $booking->guest_email) !== 0) {
                return '<p class="tap-empty">' . esc_html__('El correo introducido no coincide con el de la reserva.', 'travel-agency-platform') . '</p>';
            }
            $can = true;
        }
        if (!$can) {
            return '<p class="tap-empty">' . esc_html__('You do not have permission to view this booking.', 'travel-agency-platform') . '</p>';
        }

        $user    = get_userdata($booking->client_id);
        $dispute_msg  = '';
        $dispute_err  = '';
        $dispute_open = $booking->id ? TAP_Disputes::active_for_booking($booking->id) : null;

        if (isset($_POST['tap_open_dispute']) && $can
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'tap_open_dispute_' . $booking->booking_code)) {
            $reason  = sanitize_key(wp_unslash($_POST['reason'] ?? ''));
            $details = sanitize_textarea_field(wp_unslash($_POST['details'] ?? ''));
            $res = TAP_Disputes::open($booking->id, $uid ? $uid : 0, $uid ? '' : (string) $booking->guest_email, $reason, $details);
            if (is_wp_error($res)) {
                $dispute_err = $res->get_error_message();
            } else {
                $dispute_msg  = __('Disputa abierta. La comisión de esta reserva queda retenida hasta resolver.', 'travel-agency-platform');
                $dispute_open = TAP_Disputes::active_for_booking($booking->id);
            }
        }

        $service = get_post($booking->service_id);
        $room    = $booking->room_id ? get_post($booking->room_id) : null;
        $agency  = $booking->agency_id ? get_post($booking->agency_id) : null;

        $service_name = $room ? $room->post_title : ($service ? $service->post_title : 'N/A');
        $nights = max(1, (int) $booking->nights);
        $lines  = [
            [
                'desc' => sprintf('%s · %d %s', $service_name, $nights, _n('night', 'nights', $nights, 'travel-agency-platform')),
                'qty'  => 1,
                'unit' => (float) $booking->total_amount,
            ],
        ];

        $status_labels = TAP_Emails::status_labels();
        $pstatus = [
            'pending' => __('Pendiente', 'travel-agency-platform'),
            'paid'    => __('Pagado', 'travel-agency-platform'),
            'partial' => __('Parcial', 'travel-agency-platform'),
            'refunded'=> __('Reembolsado', 'travel-agency-platform'),
            'failed'  => __('Fallido', 'travel-agency-platform'),
        ];

        $agency_email = $agency ? get_post_meta($agency->ID, '_tap_agency_email', true) : '';
        $agency_phone = $agency ? get_post_meta($agency->ID, '_tap_agency_phone', true) : '';
        $agency_wa    = $agency ? get_post_meta($agency->ID, '_tap_agency_whatsapp', true) : '';
        $agency_web   = $agency ? get_post_meta($agency->ID, '_tap_agency_website', true) : '';
        $agency_city  = $agency ? get_post_meta($agency->ID, '_tap_agency_city', true) : '';
        $agency_country = $agency ? get_post_meta($agency->ID, '_tap_agency_country', true) : '';

        ob_start();
        ?>
        <div class="tap-voucher">
            <div class="tap-voucher-tools">
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/my-bookings/')); ?>">&larr; <?php esc_html_e('Mis reservas', 'travel-agency-platform'); ?></a>
                <button type="button" class="tap-btn tap-btn-sm" onclick="window.print()">🖨 <?php esc_html_e('Imprimir voucher', 'travel-agency-platform'); ?></button>
                <?php if ((int) $booking->client_id === $uid && in_array($booking->status, ['pending', 'confirmed', 'request'], true) && (!$booking->check_in || $booking->check_in >= gmdate('Y-m-d'))): ?>
                    <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-cancel-booking-btn" data-booking-id="<?php echo (int) $booking->id; ?>" data-confirm="<?php echo esc_attr($booking->booking_code); ?>" data-guest-email="<?php echo !$uid ? esc_attr($booking->guest_email) : ''; ?>"><?php esc_html_e('Cancelar reserva', 'travel-agency-platform'); ?></button>
                <?php endif; ?>
                <?php if ($uid && (int) $booking->client_id === $uid && TAP_Booking::is_payable($booking) && $booking->total_amount > 0): ?>
                    <a class="tap-btn tap-btn-sm tap-btn-primary" href="<?php echo esc_url(home_url('/checkout?code=' . $booking->booking_code)); ?>"><?php esc_html_e('Pagar ahora', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </div>
            <div class="tap-voucher-inner">
                <div class="tap-voucher-head">
                    <div>
                        <h2><?php esc_html_e('Voucher de Reserva', 'travel-agency-platform'); ?></h2>
                        <span class="tap-voucher-ref"><?php esc_html_e('Ref', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($booking->booking_code); ?></strong></span>
                    </div>
                    <div class="tap-voucher-badges">
                        <span class="tap-badge tap-badge-ok"><?php echo esc_html($status_labels[$booking->status] ?? ucfirst($booking->status)); ?></span>
                        <span class="tap-badge"><?php echo esc_html($pstatus[$booking->payment_status] ?? ucfirst($booking->payment_status)); ?></span>
                    </div>
                </div>

                <?php if ($booking->status === 'request'): ?>
                    <div class="tap-booking-message tap-success">
                        <p><?php esc_html_e('Tu solicitud está pendiente de confirmación de la agencia. Te avisaremos por correo cuando sea aceptada para que completes el pago.', 'travel-agency-platform'); ?></p>
                    </div>
                <?php endif; ?>

                <div class="tap-voucher-body">
                    <div class="tap-voucher-service">
                        <h3><?php echo esc_html($service_name); ?></h3>
                        <?php if ($agency): ?>
                        <p><?php esc_html_e('Operada por', 'travel-agency-platform'); ?> <strong><?php echo esc_html($agency->post_title); ?></strong></p>
                        <?php endif; ?>
                    </div>

                    <div class="tap-voucher-grid">
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->check_in))); ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->check_out))); ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Noches', 'travel-agency-platform'); ?></span>
                            <strong><?php echo (int) $nights; ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Huéspedes', 'travel-agency-platform'); ?></span>
                            <strong><?php echo (int) $booking->adults; ?> <?php esc_html_e('adultos', 'travel-agency-platform'); ?><?php echo $booking->children ? ' · ' . (int) $booking->children . ' ' . esc_html__('niños', 'travel-agency-platform') : ''; ?></strong>
                        </div>
                    </div>

                    <div class="tap-table-scroll"><table class="tap-voucher-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Cant.', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Precio', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['desc']); ?></td>
                                <td><?php echo (int) $item['qty']; ?></td>
                                <td><?php echo esc_html(TAP_Currency::fmt($item['unit'] / max(1, $item['qty']))); ?></td>
                                <td><?php echo esc_html(TAP_Currency::fmt($item['unit'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <?php if ((float) ($booking->booking_fee ?? 0) > 0): ?>
                            <tr>
                                <td colspan="3" class="tap-voucher-total-label"><?php esc_html_e('Tarifa de servicio', 'travel-agency-platform'); ?></td>
                                <td class="tap-voucher-total"><?php echo esc_html(TAP_Currency::fmt((float) $booking->booking_fee)); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="3" class="tap-voucher-total-label"><?php esc_html_e('Total', 'travel-agency-platform'); ?></td>
                                <td class="tap-voucher-total"><?php echo esc_html(TAP_Currency::fmt((float) $booking->total_amount)); ?></td>
                            </tr>
                        </tfoot>
                    </table></div>

                    <div class="tap-voucher-two-col">
                        <div class="tap-voucher-info">
                            <h4><?php esc_html_e('Titular', 'travel-agency-platform'); ?></h4>
                            <p><strong><?php echo $user ? esc_html($user->display_name) : 'N/A'; ?></strong><br><?php echo $user ? esc_html($user->user_email) : ''; ?></p>
                            <?php if ($booking->guest_name): ?>
                            <h4><?php esc_html_e('Huésped de contacto', 'travel-agency-platform'); ?></h4>
                            <p>
                                <strong><?php echo esc_html($booking->guest_name); ?></strong>
                                <?php if ($booking->guest_email): ?><br><?php echo esc_html($booking->guest_email); ?><?php endif; ?>
                                <?php if ($booking->guest_phone): ?><br><?php echo esc_html($booking->guest_phone); ?><?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($agency): ?>
                        <div class="tap-voucher-info">
                            <h4><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></h4>
                            <p>
                                <strong><?php echo esc_html($agency->post_title); ?></strong><br>
                                <?php echo esc_html(implode(', ', array_filter([$agency_city, $agency_country]))); ?><br>
                                <?php if ($agency_email): ?><?php echo esc_html($agency_email); ?><br><?php endif; ?>
                                <?php if ($agency_phone || $agency_wa): ?><?php echo esc_html($agency_wa ?: $agency_phone); ?><?php endif; ?>
                                <?php if ($agency_web): ?><br><?php echo esc_html($agency_web); ?><?php endif; ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="tap-voucher-notes">
                        <small><?php esc_html_e('Presenta este voucher el día del check-in. Cualquier cambio debe gestionarse con la agencia.', 'travel-agency-platform'); ?></small>
                    </div>

                    <div class="tap-voucher-notes tap-voucher-privacy">
                        <small><?php echo wp_kses_post(sprintf(__('Consulta nuestro <a href="%s" target="_blank" rel="noopener">Aviso de Privacidad</a> para saber cómo tratamos tus datos y cómo ejercer tus derechos.', 'travel-agency-platform'), esc_url(TAP_Privacy::policy_url()))); ?></small>
                    </div>

                    <?php $is_traveler = ($uid && (int) $booking->client_id === $uid) || ((int) $booking->client_id === 0 && '' !== $email); ?>
                    <?php if ($is_traveler): ?>
                    <div class="tap-voucher-dispute">
                        <?php if ($dispute_msg): ?>
                            <div class="tap-booking-message tap-success"><p><?php echo esc_html($dispute_msg); ?></p></div>
                        <?php endif; ?>
                        <?php if ($dispute_err): ?>
                            <div class="tap-booking-message tap-error"><p><?php echo esc_html($dispute_err); ?></p></div>
                        <?php endif; ?>
                        <?php if ($dispute_open): ?>
                            <div class="tap-booking-message">
                                <p><?php esc_html_e('Hay una disputa abierta para esta reserva. El equipo revisará el caso y la resolverá lo antes posible.', 'travel-agency-platform'); ?></p>
                                <p><strong><?php echo esc_html(TAP_Disputes::status_label($dispute_open->status)); ?></strong> — <?php echo esc_html(TAP_Disputes::reasons()[$dispute_open->reason] ?? $dispute_open->reason); ?></p>
                            </div>
                        <?php elseif (in_array($booking->status, ['confirmed', 'completed'], true) && 'paid' === $booking->payment_status): ?>
                            <h3><?php esc_html_e('Abrir una disputa', 'travel-agency-platform'); ?></h3>
                            <p class="tap-agency-meta"><?php esc_html_e('Si el servicio no coincidió con lo prometido, cuéntanos qué pasó. Revisaremos tu reserva y ayudaremos a resolverlo.', 'travel-agency-platform'); ?></p>
                            <form method="post" class="tap-manage-form">
                                <?php wp_nonce_field('tap_open_dispute_' . $booking->booking_code); ?>
                                <div class="tap-form-grid">
                                    <div class="tap-field">
                                        <label><?php esc_html_e('Motivo de la disputa', 'travel-agency-platform'); ?></label>
                                        <select name="reason" required>
                                            <?php foreach (TAP_Disputes::reasons() as $rk => $rl): ?>
                                                <option value="<?php echo esc_attr($rk); ?>"><?php echo esc_html($rl); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="tap-field tap-span-2">
                                        <label><?php esc_html_e('Detalles', 'travel-agency-platform'); ?></label>
                                        <textarea name="details" rows="3" required placeholder="<?php esc_attr_e('Describe el problema...', 'travel-agency-platform'); ?>"></textarea>
                                    </div>
                                    <div class="tap-field tap-field-actions">
                                        <button type="submit" name="tap_open_dispute" value="1" class="tap-btn tap-btn-primary"><?php esc_html_e('Enviar disputa', 'travel-agency-platform'); ?></button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>';
            $('.tap-cancel-booking-btn').on('click', function() {
                var $btn = $(this), id = $btn.data('booking-id');
                var code = $btn.data('confirm') || '';
                if (!confirm('<?php echo esc_js(__('¿Cancelar la reserva ', 'travel-agency-platform')); ?>' + code + '?')) return;
                $btn.prop('disabled', true).text('...');
                var data = { action: 'tap_cancel_booking', nonce: nonce, booking_id: id, booking_code: $btn.data('confirm') || '' };
                var gemail = $btn.data('guest-email') || '';
                if (gemail) data.guest_email = gemail;
                $.post(tap_ajax.ajax_url, data)
                    .done(function(res) {
                        if (res && res.success) { location.reload(); }
                        else { alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar reserva', 'travel-agency-platform')); ?>'); }
                    })
                    .fail(function() { alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar reserva', 'travel-agency-platform')); ?>'); });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function favorites() {
        if (!is_user_logged_in()) {
            return '<div class="tap-box tap-box-warn"><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Inicia sesión para ver tus favoritos.', 'travel-agency-platform') . '</a></div>';
        }

        $ids = TAP_API::get_favorites();
        if (empty($ids)) {
            return '<p class="tap-empty-state">' . esc_html__('Aún no tienes favoritos. Toca el corazón ♥ en cualquier servicio para guardarlo aquí.', 'travel-agency-platform') . '</p>';
        }

        $posts = get_posts([
            'post_type'      => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'],
            'post__in'       => $ids,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'post__in',
        ]);

        if (empty($posts)) {
            return '<p class="tap-empty-state">' . esc_html__('No se encontraron servicios favoritos.', 'travel-agency-platform') . '</p>';
        }

        $type_labels = [
            'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de autos', 'travel-agency-platform'),
            'tap_boat'          => __('Paseo en bote', 'travel-agency-platform'),
            'tap_package'       => __('Paquete', 'travel-agency-platform'),
        ];

        ob_start();
        ?>
        <div class="tap-fav-grid">
            <?php foreach ($posts as $post):
                $type  = $post->post_type;
                $price = floatval(get_post_meta($post->ID, TAP_API::get_price_key($type) ?: '', true));
                $rat   = TAP_API::get_rating_stats($type, $post->ID);
                $img   = get_the_post_thumbnail($post->ID, 'medium', ['loading' => 'lazy', 'decoding' => 'async']);
                ?>
                <article class="tap-service-card tap-fav-card">
                    <?php if ($img): ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-fav-thumb"><?php echo $img; ?></a>
                    <?php endif; ?>
                    <div class="tap-service-card-body">
                        <span class="tap-badge tap-badge-primary"><?php echo esc_html($type_labels[$type] ?? $type); ?></span>
                        <h3><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                        <?php if ($price): ?>
                            <p class="tap-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></p>
                        <?php endif; ?>
                        <?php if ($rat['count'] > 0): ?>
                            <p class="tap-fav-rating"><?php echo esc_html(number_format($rat['avg'], 1)); ?> ★ (<?php echo (int) $rat['count']; ?>)</p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
                        <button class="tap-btn tap-btn-xs tap-fav-btn active" data-post-id="<?php echo (int) $post->ID; ?>" type="button" title="<?php esc_attr_e('Quitar de favoritos', 'travel-agency-platform'); ?>">
                            <span class="tap-fav-heart">♥</span> <?php esc_html_e('Quitar', 'travel-agency-platform'); ?>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Fase 4 — Support chat widget shortcode. */
    public static function chatbot() {
        $id = 'tap-chat-' . wp_unique_id();
        ob_start();
        ?>
        <section class="tap-chat" id="<?php echo esc_attr($id); ?>" role="dialog" aria-label="<?php esc_attr_e('Asistente de viajes', 'travel-agency-platform'); ?>">
            <header class="tap-chat-header">
                <h2 class="tap-chat-title"><?php esc_html_e('Asistente de viajes', 'travel-agency-platform'); ?></h2>
            </header>
            <div class="tap-chat-panel" aria-hidden="true">
                <div class="tap-chat-messages" aria-live="polite">
                    <div class="tap-chat-msg tap-chat-msg--bot tap-chat-msg--intro"><?php esc_html_e('¡Hola! Soy el asistente de viajes. Pregunta por alojamientos, tours, bonos o agencias, o toca una sugerencia.', 'travel-agency-platform'); ?></div>
                    <div class="tap-chat-chips">
                        <?php foreach (['How do I book a stay?', 'How do I search trips?', 'How do I pay my voucher?', 'How do I cancel a booking?', 'How do I join as an agency?'] as $chip): ?>
                            <button type="button" class="tap-chat-chip" aria-label="<?php echo esc_attr(__($chip, 'travel-agency-platform')); ?>"><?php echo esc_html__($chip, 'travel-agency-platform'); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form class="tap-chat-input" autocomplete="off">
                    <label for="<?php echo esc_attr($id); ?>-input" class="screen-reader-text"><?php esc_html_e('Escribe tu pregunta…', 'travel-agency-platform'); ?></label>
                    <input type="text" id="<?php echo esc_attr($id); ?>-input" class="tap-chat-field" placeholder="<?php esc_attr_e('Escribe tu pregunta…', 'travel-agency-platform'); ?>" required>
                    <button type="submit" class="tap-chat-send" aria-label="<?php esc_attr_e('Enviar', 'travel-agency-platform'); ?>"><?php esc_html_e('Enviar', 'travel-agency-platform'); ?></button>
                </form>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
