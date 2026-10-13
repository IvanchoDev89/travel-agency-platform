<?php
/**
 * TAP_Shortcode_Booking — reservation presentation shortcodes:
 * [tap_booking_form], [tap_my_bookings], [tap_checkout],
 * [tap_booking_detail] and [tap_favorites].
 *
 * Extracted from TAP_Shortcodes (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Shortcode_Booking {
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
                        <?php
                        foreach ($bookings as $booking):
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
                                    <br><span class="tap-refund-note"><?php echo esc_html(sprintf(/* translators: %s: importe reembolsado */ __('Reembolso: %s', 'travel-agency-platform'), TAP_Currency::fmt((float) $booking->refund_amount))); ?></span>
                                <?php elseif (in_array($booking->status, ['cancelled', 'refunded'], true) && !empty($booking->cancellation_policy) && in_array($booking->service_type, TAP_Booking::service_type_slugs(), true)): ?>
                                    <br><span class="tap-refund-note"><?php esc_html_e('Política no reembolsable', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                            </td>
<td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(TAP_Emails_Send::status_label($booking->status)); ?></span></td>
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


    public static function checkout($atts) {
        $atts = shortcode_atts(['code' => ''], $atts);

        $booking_code = $atts['code'] ?: sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
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
            $guest_verified = TAP_Guest_Checkout::get_guest_pay_token($booking->booking_code);
            if (!$guest_verified) {
                $email = sanitize_email(wp_unslash($_GET['email'] ?? ''));
                if (is_email($email) && strcasecmp($email, (string) $booking->guest_email) === 0) {
                    TAP_Guest_Checkout::set_guest_pay_token($booking->booking_code);
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
                    <?php if (!empty($booking->cancel_reason)): ?>
                        <p><strong><?php esc_html_e('Motivo:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($booking->cancel_reason); ?></p>
                    <?php endif; ?>
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
                    <?php $tap_remaining = TAP_Booking::remaining_amount($booking); ?>
                    <?php if (TAP_Booking::amount_already_paid($booking) > 0 && $tap_remaining > 0): ?>
                        <div class="tap-booking-message">
                            <p>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: importe ya pagado, 2: importe total, 3: saldo pendiente */
                                __('Ya pagaste %1$s de %2$s. El saldo pendiente es de %3$s.', 'travel-agency-platform'),
                                TAP_Currency::fmt(TAP_Booking::amount_already_paid($booking)),
                                TAP_Currency::fmt((float) $booking->total_amount),
                                TAP_Currency::fmt($tap_remaining)
                            ));
							?>
                            </p>
                        </div>
                    <?php elseif ('failed' === $booking->payment_status): ?>
                        <p class="tap-refund-note"><?php esc_html_e('Un intento de pago anterior falló. Reinicia el pago y vuelve a intentarlo.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                    <h3><?php esc_html_e('Pay with PayPal', 'travel-agency-platform'); ?></h3>
							<?php if (TAP_PayPal::is_ready()): ?>
                    <div id="tap-paypal-button-container"></div>
                    <div id="tap-paypal-message" class="tap-booking-message"></div>
                    <p class="tap-pay-label">
								<?php
								echo $tap_remaining > 0 && TAP_Booking::amount_already_paid($booking) > 0
								/* translators: %s: importe del saldo pendiente */
								? esc_html(sprintf(__('Pagar el saldo restante de %s', 'travel-agency-platform'), TAP_Currency::fmt($tap_remaining)))
								: esc_html__('Completar el pago con PayPal', 'travel-agency-platform');
								?>
                        </p>

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

        $status_labels = TAP_Emails_Send::status_labels();
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
                        <small><?php echo wp_kses_post(sprintf(/* translators: %s: enlace al aviso de privacidad */ __('Consulta nuestro <a href="%s" target="_blank" rel="noopener">Aviso de Privacidad</a> para saber cómo tratamos tus datos y cómo ejercer tus derechos.', 'travel-agency-platform'), esc_url(TAP_Privacy::policy_url()))); ?></small>
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
                <div class="tap-voucher-chat">
                        <h3><?php esc_html_e('Mensajes con tu agencia', 'travel-agency-platform'); ?></h3>
                        <div class="tap-chat-thread">
                            <?php
                            TAP_Chat::mark_read($booking->id, 'client');
                            $rows = TAP_Chat::messages($booking->id);
                            if (empty($rows)) {
                                echo '<p class="tap-empty">' . esc_html__('Aún no hay mensajes. Escribe para coordinar horarios o detalles con tu agencia.', 'travel-agency-platform') . '</p>';
                            } else {
                                foreach ($rows as $m) {
                                    echo TAP_Chat::row_markup($m, 'client');
                                }
                            }
                            ?>
                        </div>
                        <form class="tap-chat-form" data-booking-id="<?php echo esc_attr($booking->id); ?>" data-booking-code="<?php echo esc_attr($booking->booking_code); ?>" <?php echo ($uid ? '' : ' data-guest-email="' . esc_attr($email) . '"'); ?>>
                            <div class="tap-chat-input">
                                <textarea name="message" rows="2" required maxlength="2000" placeholder="<?php esc_attr_e('Escribe un mensaje para la agencia...', 'travel-agency-platform'); ?>"></textarea>
                                <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Enviar', 'travel-agency-platform'); ?></button>
                            </div>
                        </form>
                    </div>
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

            $('.tap-chat-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this),
                    $box = $form.closest('.tap-voucher-chat').find('.tap-chat-thread'),
                    $ta = $form.find('textarea[name=message]'),
                    msg = $.trim($ta.val()),
                    $btn = $form.find('button[type=submit]');
                if (!msg) return;
                $btn.prop('disabled', true);
                var data = { action: 'tap_chat_client_send', nonce: nonce, booking_id: $form.data('booking-id'), booking_code: $form.data('booking-code'), message: msg };
                var gemail = $form.data('guest-email') || '';
                if (gemail) data.guest_email = gemail;
                $.post(tap_ajax.ajax_url, data)
                    .done(function(res) {
                        if (res && res.success) {
                            $box.find('.tap-empty').remove();
                            $box.append(res.data.row);
                            $ta.val('');
                            chatCount = $box.find('.tap-chat-msg').length;
                        } else {
                            alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>');
                        }
                    })
                    .fail(function() { alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); })
                    .always(function() { $btn.prop('disabled', false); });
            });

            var $chatWrap = $('.tap-voucher-chat'),
                chatBase = 15000,
                chatMax = 120000,
                chatDelay = chatBase,
                chatTimer = null,
                chatCount = $chatWrap.find('.tap-chat-thread .tap-chat-msg').length,
                chatPoll = function() {
                    var $form = $chatWrap.find('.tap-chat-form');
                    if (!$form.length) return;
                    var data = { action: 'tap_chat_client_thread', nonce: nonce, booking_id: $form.data('booking-id'), booking_code: $form.data('booking-code') };
                    var gemail = $form.data('guest-email') || '';
                    if (gemail) data.guest_email = gemail;
                    $.post(tap_ajax.ajax_url, data)
                        .done(function(res) {
                            if (!res || !res.success) return;
                            var count = parseInt(res.data.count, 10) || 0;
                            if (count === chatCount) return;
                            chatCount = count;
                            var $box = $chatWrap.find('.tap-chat-thread');
                            $box.html(res.data.html || '<p class="tap-empty"><?php echo esc_js(__('Aún no hay mensajes. Escribe para coordinar horarios o detalles con tu agencia.', 'travel-agency-platform')); ?></p>');
                        })
                        .always(function(xhr, status) {
                            chatDelay = (status === 'error' || status === 'timeout') ? Math.min(chatDelay * 2, chatMax) : chatBase;
                            chatSchedule();
                        });
                },
                chatSchedule = function() {
                    if (chatTimer) { clearTimeout(chatTimer); chatTimer = null; }
                    if (!$chatWrap.length || document.hidden) return;
                    chatTimer = setTimeout(chatPoll, chatDelay);
                };
            if ($chatWrap.length) {
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden) {
                        if (chatTimer) { clearTimeout(chatTimer); chatTimer = null; }
                    } else {
                        chatDelay = chatBase;
                        chatPoll();
                    }
                });
                chatSchedule();
            }
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
            <?php
            foreach ($posts as $post):
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
}
