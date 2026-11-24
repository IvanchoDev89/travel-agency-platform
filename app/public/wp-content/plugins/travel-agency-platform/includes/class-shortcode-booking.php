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

    /** Per-render counter so multiple forms on a list page never share IDs. */
    private static $form_instance = 0;
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

        $is_tour = in_array($atts['service_type'], ['tap_tour', 'tap_experience'], true);

        // For tours: load available slots.
        $tour_slots = [];
        if ($is_tour && class_exists('TAP_Tour_Availability')) {
            $tour_slots = TAP_Tour_Availability::slots_for($atts['service_type'], (int) $atts['service_id']);
        }
        // Group slots by date.
        $slots_by_date = [];
        foreach ($tour_slots as $sl) {
            if ((int) $sl->is_blocked) continue;
            $slots_by_date[$sl->date][] = $sl;
        }
        $available_dates = array_keys($slots_by_date);

        $n = ++self::$form_instance;
        $form_id = 'tap-booking-form-' . $n;
        ob_start();
        ?>
        <div class="tap-booking-form">
            <h3><?php echo $is_request ? esc_html__('Solicitar reserva', 'travel-agency-platform') : esc_html__('Book Now', 'travel-agency-platform'); ?></h3>
            <form id="<?php echo esc_attr($form_id); ?>" class="tap-form" method="post">
                <input type="hidden" name="action" value="tap_booking_create">
                <input type="hidden" name="service_type" value="<?php echo esc_attr($atts['service_type']); ?>">
                <input type="hidden" name="service_id" value="<?php echo esc_attr($atts['service_id']); ?>">
                <input type="hidden" name="referral_code" value="<?php echo esc_attr(class_exists('TAP_Customer_Referrals') ? TAP_Customer_Referrals::active_code() : ''); ?>">
                <input type="hidden" name="idempotency_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <?php wp_nonce_field('tap_booking_nonce', 'tap_booking_nonce'); ?>

                <?php if (!is_user_logged_in()): ?>
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_guest_name_<?php echo (int) $n; ?>"><?php esc_html_e('Nombre completo', 'travel-agency-platform'); ?></label>
                        <input type="text" id="tap_guest_name_<?php echo (int) $n; ?>" name="guest_name" class="tap-input" required autocomplete="name">
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_guest_email_<?php echo (int) $n; ?>"><?php esc_html_e('Correo electrónico', 'travel-agency-platform'); ?></label>
                        <input type="email" id="tap_guest_email_<?php echo (int) $n; ?>" name="guest_email" class="tap-input" required autocomplete="email">
                    </div>
                </div>
                <div class="tap-form-group">
                    <label for="tap_guest_phone_<?php echo (int) $n; ?>"><?php esc_html_e('Teléfono (opcional)', 'travel-agency-platform'); ?></label>
                    <input type="tel" id="tap_guest_phone_<?php echo (int) $n; ?>" name="guest_phone" class="tap-input" autocomplete="tel">
                </div>
                <?php endif; ?>
                <?php if ($is_tour): ?>
                <input type="hidden" name="slot_id" id="tap_slot_id_<?php echo (int) $n; ?>" value="">
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_tour_date_<?php echo (int) $n; ?>"><?php esc_html_e('Fecha del tour', 'travel-agency-platform'); ?></label>
                        <select id="tap_tour_date_<?php echo (int) $n; ?>" name="tour_date" class="tap-input" required>
                            <option value=""><?php esc_html_e('— Selecciona fecha —', 'travel-agency-platform'); ?></option>
                            <?php foreach ($available_dates as $dt): ?>
                                <option value="<?php echo esc_attr($dt); ?>"><?php echo esc_html($dt); ?> (<?php echo count($slots_by_date[$dt]); ?> horarios)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_tour_slot_<?php echo (int) $n; ?>"><?php esc_html_e('Horario', 'travel-agency-platform'); ?></label>
                        <select id="tap_tour_slot_<?php echo (int) $n; ?>" name="tour_slot" class="tap-input" required disabled>
                            <option value=""><?php esc_html_e('— Selecciona fecha primero —', 'travel-agency-platform'); ?></option>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="check_in" id="tap_check_in_<?php echo (int) $n; ?>" value="">
                <input type="hidden" name="check_out" id="tap_check_out_<?php echo (int) $n; ?>" value="">
                <?php else: ?>
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_check_in_<?php echo (int) $n; ?>"><?php esc_html_e('Check-in Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_in_<?php echo (int) $n; ?>" name="check_in" class="tap-input" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_check_out_<?php echo (int) $n; ?>"><?php esc_html_e('Check-out Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_out_<?php echo (int) $n; ?>" name="check_out" class="tap-input" required>
                    </div>
                </div>
                <?php endif; ?>
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_adults_<?php echo (int) $n; ?>"><?php esc_html_e('Adults', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_adults_<?php echo (int) $n; ?>" name="adults" min="1" value="1" class="tap-input tap-input-sm" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_children_<?php echo (int) $n; ?>"><?php esc_html_e('Children', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_children_<?php echo (int) $n; ?>" name="children" min="0" value="0" class="tap-input tap-input-sm">
                    </div>
                </div>
                <div class="tap-form-group">
                    <label for="tap_notes_<?php echo (int) $n; ?>"><?php esc_html_e('Special Requests', 'travel-agency-platform'); ?></label>
                    <textarea id="tap_notes_<?php echo (int) $n; ?>" name="notes" rows="3" class="tap-input"></textarea>
                </div>
                <div class="tap-form-group">
                    <span class="tap-total-display"><?php esc_html_e('Total: ', 'travel-agency-platform'); ?><?php echo esc_html(TAP_Currency::plain_symbol()); ?><span id="tap-total-amount-<?php echo (int) $n; ?>">0.00</span></span>
                </div>
                <?php if ($is_tour && class_exists('TAP_Service_Extras')): ?>
                <div class="tap-form-group">
                    <?php echo TAP_Service_Extras::render_selector($atts['service_type'], (int) $atts['service_id']); ?>
                </div>
                <?php endif; ?>
                <div class="tap-form-group">
                    <?php echo TAP_Privacy::consent_field('booking'); ?>
                </div>
                <?php if (class_exists('TAP_Travel_Insurance')): ?>
                <div class="tap-form-group">
                    <?php echo TAP_Travel_Insurance::render_options(0); ?>
                </div>
                <?php endif; ?>
                <?php if ($is_tour && class_exists('TAP_Waiver') && TAP_Waiver::is_required($atts['service_type'], (int) $atts['service_id'])): ?>
                <div class="tap-form-group">
                    <p style="font-size:13px;color:#6b7280;"><?php esc_html_e('Esta actividad requiere firmar un waiver (descargo de responsabilidad). Se te pedirá firmarlo antes de confirmar.', 'travel-agency-platform'); ?></p>
                </div>
                <?php endif; ?>
                <div class="tap-form-group">
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-lg"><?php echo $is_request ? esc_html__('Enviar solicitud', 'travel-agency-platform') : esc_html__('Book Now', 'travel-agency-platform'); ?></button>
                </div>
                <div class="tap-booking-message" role="status" aria-live="polite"></div>
            </form>
        </div>

        <?php if ($is_tour): ?>
        <script type="application/json" id="tap-tour-slots-data-<?php echo (int) $n; ?>"><?php echo wp_json_encode($slots_by_date, JSON_UNESCAPED_UNICODE); ?></script>
        <?php endif; ?>
        <script>
        jQuery(document).ready(function($) {
            var $form = $('#<?php echo esc_js($form_id); ?>');
            <?php if ($is_tour): ?>
            // Tour slot selector logic.
            var slotsData = {};
            try { slotsData = JSON.parse($form.find('#tap-tour-slots-data-<?php echo (int) $n; ?>').text() || '{}'); } catch(e) {}
            function populateSlots(date) {
                var $slot = $form.find('#tap_tour_slot_<?php echo (int) $n; ?>');
                $slot.empty();
                if (!date || !slotsData[date]) {
                    $slot.append('<option value=""><?php echo esc_js(__('— Selecciona fecha primero —', 'travel-agency-platform')); ?></option>').prop('disabled', true);
                    return;
                }
                $slot.append('<option value=""><?php echo esc_js(__('— Selecciona horario —', 'travel-agency-platform')); ?></option>').prop('disabled', false);
                slotsData[date].forEach(function(sl) {
                    var cap = sl.capacity > 0 ? sl.booked + '/' + sl.capacity : '';
                    var label = (sl.time_slot ? sl.time_slot.substring(0,5) : '') + (sl.label ? ' - ' + sl.label : '') + (cap ? ' (' + cap + ')' : '');
                    if (sl.is_blocked) label = (sl.time_slot ? sl.time_slot.substring(0,5) : '') + ' - BLOQUEADO';
                    $slot.append('<option value="' + sl.id + '" data-price="' + (sl.price || 0) + '" ' + (sl.is_blocked ? 'disabled' : '') + '>' + label + '</option>');
                });
            }
            $form.find('#tap_tour_date_<?php echo (int) $n; ?>').on('change', function() {
                var date = $(this).val();
                populateSlots(date);
                $form.find('#tap_slot_id_<?php echo (int) $n; ?>').val('');
                $form.find('#tap_check_in_<?php echo (int) $n; ?>').val(date);
                $form.find('#tap_check_out_<?php echo (int) $n; ?>').val(date);
                calculateTotal();
            });
            $form.find('#tap_tour_slot_<?php echo (int) $n; ?>').on('change', function() {
                var slotId = $(this).val();
                $form.find('#tap_slot_id_<?php echo (int) $n; ?>').val(slotId);
                var price = $(this).find('option:selected').data('price');
                calculateTotal();
            });
            <?php endif; ?>

            function calculateTotal() {
                var data = {
                    action: 'tap_calculate_booking_total',
                    service_type: '<?php echo esc_js($atts['service_type']); ?>',
                    service_id: '<?php echo esc_js($atts['service_id']); ?>',
                    check_in: $form.find('#tap_check_in_<?php echo (int) $n; ?>').val(),
                    check_out: $form.find('#tap_check_out_<?php echo (int) $n; ?>').val(),
                    adults: $form.find('#tap_adults_<?php echo (int) $n; ?>').val(),
                    children: $form.find('#tap_children_<?php echo (int) $n; ?>').val(),
                    slot_id: $form.find('#tap_slot_id_<?php echo (int) $n; ?>').val() || 0,
                    insurance_plan: $form.find('input[name="insurance_plan"]:checked').val() || '',
                    nonce: tap_ajax.nonce
                };

                $.post(tap_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        $form.find('#tap-total-amount-<?php echo (int) $n; ?>').text(res.data.total.toFixed(2));
                    }
                });
            }

            $form.find('#tap_check_in_<?php echo (int) $n; ?>, #tap_check_out_<?php echo (int) $n; ?>, #tap_adults_<?php echo (int) $n; ?>, #tap_children_<?php echo (int) $n; ?>, input[name="insurance_plan"]').on('change', calculateTotal);

            $form.on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var msg = form.find('.tap-booking-message');
                var total = parseFloat($form.find('#tap-total-amount-<?php echo (int) $n; ?>').text()) || 0;

                <?php if ($is_tour): ?>
                if (!$form.find('#tap_tour_slot_<?php echo (int) $n; ?>').val()) {
                    msg.removeClass('tap-success').addClass('tap-error')
                        .html('<p><?php echo esc_js(__('Selecciona una fecha y horario disponible.', 'travel-agency-platform')); ?></p>');
                    return;
                }
                <?php endif; ?>
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
            <?php if (!empty($bookings)): ?>
            <div class="tap-booking-filters" role="tablist" aria-label="<?php esc_attr_e('My Bookings', 'travel-agency-platform'); ?>">
                <button type="button" class="tap-booking-filter is-active" data-filter="all"><?php esc_html_e('Todas', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-booking-filter" data-filter="pending"><?php esc_html_e('Pendientes', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-booking-filter" data-filter="paid"><?php esc_html_e('Pagadas', 'travel-agency-platform'); ?></button>
                <button type="button" class="tap-booking-filter" data-filter="cancelled"><?php esc_html_e('Canceladas', 'travel-agency-platform'); ?></button>
            </div>
            <?php endif; ?>
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
                            $prefix = class_exists('TAP_Post_Types') ? TAP_Post_Types::meta_prefix($booking->service_type) : '';
                            $bc = $prefix ? get_post_meta($booking->service_id, '_tap_' . $prefix . '_currency', true) : '';
                            if (empty($bc)) $bc = 'USD';
                            $currency_symbol = class_exists('TAP_Currency') ? TAP_Currency::symbol($bc) : $bc;
							?>
                        <tr data-status="<?php echo esc_attr($booking->status); ?>" data-payment="<?php echo esc_attr($booking->payment_status); ?>">
                            <td data-label="<?php esc_attr_e('Code', 'travel-agency-platform'); ?>"><?php echo esc_html($booking->booking_code); ?></td>
                            <td data-label="<?php esc_attr_e('Service', 'travel-agency-platform'); ?>"><?php echo $service ? esc_html($service->post_title) : 'N/D'; ?></td>
                            <td data-label="<?php esc_attr_e('Dates', 'travel-agency-platform'); ?>"><?php echo esc_html($booking->check_in . ($booking->check_out ? ' - ' . $booking->check_out : '')); ?></td>
                            <td data-label="<?php esc_attr_e('Total', 'travel-agency-platform'); ?>">
                                <?php echo esc_html($currency_symbol . ' ' . number_format((float) $booking->total_amount, 2)); ?>
                                <?php if ((float) ($booking->refund_amount ?? 0) > 0): ?>
                                    <br><span class="tap-refund-note"><?php echo esc_html(sprintf(/* translators: %s: importe reembolsado */ __('Reembolso: %s', 'travel-agency-platform'), $currency_symbol . ' ' . number_format((float) $booking->refund_amount, 2))); ?></span>
                                <?php elseif (in_array($booking->status, ['cancelled', 'refunded'], true) && !empty($booking->cancellation_policy) && in_array($booking->service_type, TAP_Booking::service_type_slugs(), true)): ?>
                                    <br><span class="tap-refund-note"><?php esc_html_e('Política no reembolsable', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                            </td>
<td data-label="<?php esc_attr_e('Status', 'travel-agency-platform'); ?>"><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(TAP_Emails_Send::status_label($booking->status)); ?></span></td>
                            <td data-label="<?php esc_attr_e('Payment', 'travel-agency-platform'); ?>"><span class="tap-status tap-status-<?php echo esc_attr($booking->payment_status); ?>"><?php echo esc_html(ucfirst($booking->payment_status)); ?></span></td>
                            <td data-label="<?php esc_attr_e('Actions', 'travel-agency-platform'); ?>">
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
        <?php if (!empty($bookings)): ?>
        <script>
        (function($) {
            $('.tap-booking-filter').on('click', function() {
                var filter = $(this).data('filter');
                $('.tap-booking-filter').removeClass('is-active');
                $(this).addClass('is-active');
                $('.tap-my-bookings tbody tr').each(function() {
                    var $row = $(this);
                    var status = $row.data('status');
                    var payment = $row.data('payment');
                    var show = false;
                    if (filter === 'all') show = true;
                    else if (filter === 'pending') show = (status === 'pending' || status === 'request');
                    else if (filter === 'paid') show = (payment === 'paid');
                    else if (filter === 'cancelled') show = (status === 'cancelled' || status === 'refunded');
                    $row.toggle(show);
                });
            });
        })(jQuery);
        </script>
        <?php endif; ?>
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

        // PayPal must be initialised in the booking's currency, not the
        // visitor's display currency, or the order can fail to be captured.
        $booking_currency = (string) ($booking->currency ?? '');
        if ('' === $booking_currency) {
            $prefix = class_exists('TAP_Post_Types') ? TAP_Post_Types::meta_prefix($booking->service_type) : '';
            $booking_currency = $prefix ? (string) get_post_meta($booking->service_id, '_tap_' . $prefix . '_currency', true) : '';
        }
        if ('' === $booking_currency) { $booking_currency = 'USD'; }

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

        // Determine the booking's currency (from service meta, default USD).
        $prefix = class_exists('TAP_Post_Types') ? TAP_Post_Types::meta_prefix($booking->service_type) : '';
        $booking_currency = $prefix ? get_post_meta($booking->service_id, '_tap_' . $prefix . '_currency', true) : '';
        if (empty($booking_currency)) $booking_currency = 'USD';
        $currency_symbol = class_exists('TAP_Currency') ? TAP_Currency::symbol($booking_currency) : $booking_currency;

        ob_start();
        ?>
        <div class="tap-checkout">
            <h2><?php esc_html_e('Complete Your Payment', 'travel-agency-platform'); ?></h2>

            <?php if ($status_success): ?>
                <div id="tap-payment-status-box" class="tap-success" role="status" aria-live="polite" aria-atomic="true">
                    <p id="tap-payment-status-msg"><?php esc_html_e('Procesando tu pago...', 'travel-agency-platform'); ?></p>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    var bookingCode = '<?php echo esc_js($booking->booking_code); ?>';
                    var attempts = 0;
                    var maxAttempts = 15;
                    var poll = setInterval(function() {
                        attempts++;
                        $.post(tap_ajax.ajax_url, {
                            action: 'tap_check_payment_status',
                            booking_code: bookingCode,
                            nonce: tap_ajax.nonce
                        }).done(function(res) {
                            if (res && res.success && res.data.payment_status === 'paid') {
                                clearInterval(poll);
                                $('#tap-payment-status-msg').text('<?php echo esc_js(__('Payment completed! Thank you for your booking.', 'travel-agency-platform')); ?>');
                                setTimeout(function() {
                                    window.location.href = '<?php echo esc_url($uid ? home_url('/my-bookings') : home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code))); ?>';
                                }, 2000);
                            } else if (res && res.success && res.data.payment_status === 'failed') {
                                clearInterval(poll);
                                $('#tap-payment-status-box').removeClass('tap-success').addClass('tap-error');
                                $('#tap-payment-status-msg').text('<?php echo esc_js(__('El pago no pudo procesarse. Intenta nuevamente.', 'travel-agency-platform')); ?>');
                            }
                        });
                        if (attempts >= maxAttempts) {
                            clearInterval(poll);
                            $('#tap-payment-status-msg').text('<?php echo esc_js(__('Tu pago está siendo procesado. Recibirás un correo de confirmación cuando se complete.', 'travel-agency-platform')); ?>');
                        }
                    }, 2000);
                });
                </script>
            <?php elseif ($status_cancel): ?>
                <div class="tap-error"><?php esc_html_e('Payment was cancelled. You can try again.', 'travel-agency-platform'); ?></div>
            <?php endif; ?>

            <?php if (!$can && $is_guest && !$guest_verified): ?>
                <div class="tap-voucher-lookup">
                    <p><?php esc_html_e('Para pagar tu reserva, verifica el correo electrónico con el que la realizaste.', 'travel-agency-platform'); ?></p>
                    <p class="tap-guest-checkout-hint"><?php esc_html_e('Esto es para proteger tu reserva y asegurar que solo tú puedas acceder a ella.', 'travel-agency-platform'); ?></p>
                    <form class="tap-manage-form" method="get" action="">
                        <div class="tap-form-grid">
                            <div class="tap-field tap-span-2">
                                <label><?php esc_html_e('Correo de la reserva', 'travel-agency-platform'); ?></label>
                                <input type="email" name="email" required autocomplete="email" placeholder="<?php echo esc_attr__('tucorreo@ejemplo.com', 'travel-agency-platform'); ?>">
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
                    <p><a href="<?php echo esc_url(home_url('/search-results/')); ?>" class="tap-btn tap-btn-primary"><?php esc_html_e('Buscar otras experiencias', 'travel-agency-platform'); ?></a></p>
                </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <div class="tap-checkout-steps">
                <div class="tap-checkout-step is-done"><span>1</span> <?php esc_html_e('Datos', 'travel-agency-platform'); ?></div>
                <div class="tap-checkout-step is-active"><span>2</span> <?php esc_html_e('Pago', 'travel-agency-platform'); ?></div>
                <div class="tap-checkout-step"><span>3</span> <?php esc_html_e('Confirmación', 'travel-agency-platform'); ?></div>
            </div>
            <div class="tap-checkout-summary">
                <?php if ($service && has_post_thumbnail($service)): ?>
                <div class="tap-checkout-summary-thumb">
                    <?php echo get_the_post_thumbnail($service, 'medium', ['class' => 'tap-checkout-summary-img']); ?>
                </div>
                <?php endif; ?>
                <h3><?php esc_html_e('Booking Summary', 'travel-agency-platform'); ?></h3>
                <table class="tap-table">
                    <tr><td><strong><?php esc_html_e('Code:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->booking_code); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Service:', 'travel-agency-platform'); ?></strong></td><td><?php echo $service ? esc_html($service->post_title) : 'N/D'; ?></td></tr>
                    <?php if ($booking->check_in): ?>
                    <tr><td><strong><?php esc_html_e('Check-in:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_in); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking->check_out): ?>
                    <tr><td><strong><?php esc_html_e('Check-out:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_out); ?></td></tr>
                    <?php endif; ?>
                    <tr><td><strong><?php esc_html_e('Adults:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->adults); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Children:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->children); ?></td></tr>
                    <?php if ((float) ($booking->insurance_premium ?? 0) > 0): ?>
                    <tr><td><?php esc_html_e('Seguro de viaje', 'travel-agency-platform'); ?>:</td><td><?php echo esc_html($currency_symbol . ' ' . number_format((float) $booking->insurance_premium, 2)); ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float) ($booking->refund_amount ?? 0) > 0): ?>
                    <tr><td><?php esc_html_e('Reembolso aplicado:', 'travel-agency-platform'); ?></td><td><span class="tap-refund-note">-<?php echo esc_html($currency_symbol . ' ' . number_format((float) $booking->refund_amount, 2)); ?></span></td></tr>
                    <?php endif; ?>
                    <tr><td><strong><?php esc_html_e('Total:', 'travel-agency-platform'); ?></strong></td><td><strong><?php echo esc_html($currency_symbol . ' ' . number_format((float) $booking->total_amount, 2)); ?></strong></td></tr>
                    <tr><td><strong><?php esc_html_e('Status:', 'travel-agency-platform'); ?></strong></td><td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></td></tr>
                </table>
                <?php
                // Commission transparency note.
                $tap_checkout_commission = 10;
                if (!empty($booking->agency_id) && class_exists('TAP_Booking')) {
                    $tap_checkout_commission = TAP_Booking::get_agency_commission((int) $booking->agency_id);
                }
                ?>
                <p class="tap-checkout-commission-note"><?php echo esc_html(sprintf(__('El precio incluye una tarifa de plataforma del %s%% pagada por la agencia. No hay cargos adicionales para ti.', 'travel-agency-platform'), $tap_checkout_commission)); ?></p>
            </div>

        <?php
        // A required waiver is not optional marketing copy: collect it here
        // and block payment until a current-version signature exists.
        $tap_waiver_required = class_exists('TAP_Waiver')
            && TAP_Waiver::is_required($booking->service_type, (int) $booking->service_id)
            && !TAP_Waiver::has_valid((int) $booking->id);
        if ($tap_waiver_required):
        ?>
            <div class="tap-checkout-waiver">
                <h3><?php esc_html_e('Descargo de responsabilidad (Waiver)', 'travel-agency-platform'); ?></h3>
                <?php echo TAP_Waiver::render_form((int) $booking->id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer ?>
            </div>
        <?php endif; ?>

        <?php
        // Traveler terms are a condition of payment, just like the waiver.
        $tap_terms_pending = class_exists('TAP_Legal') && !TAP_Legal::booking_current((int) $booking->id);
        if ($tap_terms_pending) {
            if (isset($_GET['tap_legal_ok'])) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__('Términos aceptados correctamente.', 'travel-agency-platform')
                    . '</p></div>';
            }
            echo '<div class="tap-checkout-legal">'
                . TAP_Legal::render_acceptance(TAP_Legal::CONTEXT_TRAVELER, (int) $booking->id) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer
                . '</div>';
        }
        ?>

        <?php if ($booking->total_amount > 0 && $booking->payment_status !== 'paid' && $booking->status !== 'request' && !in_array($booking->status, ['cancelled', 'refunded'], true)): ?>
                <?php
                $tap_paypal_ready = TAP_PayPal::is_ready();
                $tap_onvo_ready   = TAP_Onvo::is_ready();
                $tap_both_ready   = $tap_paypal_ready && $tap_onvo_ready;
                ?>
                <div class="tap-checkout-hold-timer" id="tap-hold-timer" data-created="<?php echo esc_attr(get_date_from_gmt(get_gmt_from_date($booking->created_at), 'c')); ?>" data-hold-minutes="<?php echo esc_attr(max(1, (int) get_option('tap_payment_hold_minutes', 30))); ?>">
                    <span class="tap-hold-timer-icon" aria-hidden="true">&#x23F1;</span>
                    <span class="tap-hold-timer-text"><?php esc_html_e('Tu reserva expira en', 'travel-agency-platform'); ?> <strong id="tap-hold-countdown">--:--</strong></span>
                </div>
                <div class="tap-checkout-payment">
                    <?php $tap_remaining = TAP_Booking::remaining_amount($booking); ?>
                    <?php if (TAP_Booking::amount_already_paid($booking) > 0 && $tap_remaining > 0): ?>
                        <div class="tap-booking-message">
                            <p>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: importe ya pagado, 2: importe total, 3: saldo pendiente */
                                __('Ya pagaste %1$s de %2$s. El saldo pendiente es de %3$s.', 'travel-agency-platform'),
                                $currency_symbol . ' ' . number_format(TAP_Booking::amount_already_paid($booking), 2),
                                $currency_symbol . ' ' . number_format((float) $booking->total_amount, 2),
                                $currency_symbol . ' ' . number_format($tap_remaining, 2)
                            ));
							?>
                            </p>
                        </div>
                    <?php elseif ('failed' === $booking->payment_status): ?>
                        <p class="tap-refund-note"><?php esc_html_e('Un intento de pago anterior falló. Reinicia el pago y vuelve a intentarlo.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>

                    <?php if ($tap_both_ready): ?>
                    <div class="tap-payment-tabs" role="tablist" aria-label="<?php esc_attr_e('Método de pago', 'travel-agency-platform'); ?>">
                        <button type="button" class="tap-payment-tab is-active" role="tab" aria-selected="true" aria-controls="tap-tab-paypal" id="tap-tab-btn-paypal" data-tab="paypal">
                            <?php esc_html_e('PayPal', 'travel-agency-platform'); ?>
                        </button>
                        <button type="button" class="tap-payment-tab" role="tab" aria-selected="false" aria-controls="tap-tab-onvo" id="tap-tab-btn-onvo" data-tab="onvo">
                            <?php esc_html_e('Onvo Pay', 'travel-agency-platform'); ?>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if ($tap_paypal_ready): ?>
                    <div class="tap-payment-tab-panel<?php echo $tap_both_ready ? '' : ' is-active'; ?>" id="tap-tab-paypal" role="tabpanel" aria-labelledby="tap-tab-btn-paypal" <?php echo $tap_both_ready ? 'hidden' : ''; ?>>
                        <h3><?php esc_html_e('Pay with PayPal', 'travel-agency-platform'); ?></h3>
                        <div id="tap-paypal-button-container"></div>
                        <div id="tap-paypal-message" class="tap-booking-message" role="status" aria-live="polite"></div>
                        <p class="tap-pay-label">
                            <?php
                            echo $tap_remaining > 0 && TAP_Booking::amount_already_paid($booking) > 0
                            /* translators: %s: importe del saldo pendiente */
                            ? esc_html(sprintf(__('Pagar el saldo restante de %s', 'travel-agency-platform'), $currency_symbol . ' ' . number_format($tap_remaining, 2)))
                            : esc_html__('Completar el pago con PayPal', 'travel-agency-platform');
                            ?>
                        </p>

                        <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(TAP_PayPal::get_client_id()); ?>&currency=<?php echo esc_attr($booking_currency); ?>" data-namespace="tapPayPal"></script>
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
                    </div>
                    <?php elseif (!$tap_onvo_ready): ?>
                    <p class="tap-empty"><?php esc_html_e('Online payment is temporarily unavailable. Your reservation is registered and will be confirmed by the agency.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>

                    <?php if ($tap_onvo_ready): ?>
                    <div class="tap-payment-tab-panel<?php echo (!$tap_both_ready && !$tap_paypal_ready) ? ' is-active' : ''; ?>" id="tap-tab-onvo" role="tabpanel" aria-labelledby="tap-tab-btn-onvo" <?php echo ($tap_both_ready || $tap_paypal_ready) ? 'hidden' : ''; ?>>
                        <h3><?php esc_html_e('Pagar con Onvo Pay', 'travel-agency-platform'); ?></h3>
                        <p class="tap-pay-label"><?php esc_html_e('Tarjetas (Visa, Mastercard, Amex) y SINPE Móvil', 'travel-agency-platform'); ?></p>
                        <button type="button" class="tap-btn tap-btn-primary" id="tap-onvo-pay-btn" data-booking-id="<?php echo intval($booking->id); ?>">
                            <span class="tap-onvo-btn-text">
                                <?php
                                echo esc_html($tap_remaining > 0 && TAP_Booking::amount_already_paid($booking) > 0
                                    ? sprintf(__('Pagar el saldo restante de %s', 'travel-agency-platform'), $currency_symbol . ' ' . number_format($tap_remaining, 2))
                                    : __('Completar el pago con Onvo Pay', 'travel-agency-platform'));
                                ?>
                            </span>
                            <span class="tap-onvo-btn-spinner" style="display:none;" aria-hidden="true"></span>
                        </button>
                        <div id="tap-onvo-message" class="tap-booking-message" role="status" aria-live="polite"></div>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#tap-onvo-pay-btn').on('click', function() {
                                var btn = $(this);
                                var msg = $('#tap-onvo-message');
                                btn.prop('disabled', true);
                                btn.find('.tap-onvo-btn-text').text('<?php echo esc_js(__('Procesando...', 'travel-agency-platform')); ?>');
                                btn.find('.tap-onvo-btn-spinner').show();
                                msg.removeClass('tap-error tap-success').html('');

                                $.ajax({
                                    url: tap_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'tap_create_onvo_checkout',
                                        booking_id: btn.data('booking-id'),
                                        nonce: tap_ajax.nonce
                                    }
                                }).then(function(res) {
                                    if (res.success && res.data.redirect_url) {
                                        window.location.href = res.data.redirect_url;
                                    } else {
                                        btn.prop('disabled', false);
                                        btn.find('.tap-onvo-btn-text').text('<?php echo esc_js(__('Completar el pago con Onvo Pay', 'travel-agency-platform')); ?>');
                                        btn.find('.tap-onvo-btn-spinner').hide();
                                        msg.addClass('tap-error').html('<p>' + (res.data.message || '<?php echo esc_js(__('Error al crear la sesión de pago.', 'travel-agency-platform')); ?>') + '</p>');
                                    }
                                }).fail(function() {
                                    btn.prop('disabled', false);
                                    btn.find('.tap-onvo-btn-text').text('<?php echo esc_js(__('Completar el pago con Onvo Pay', 'travel-agency-platform')); ?>');
                                    btn.find('.tap-onvo-btn-spinner').hide();
                                    msg.addClass('tap-error').html('<p><?php echo esc_js(__('Error de conexión.', 'travel-agency-platform')); ?></p>');
                                });
                            });
                        });
                        </script>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Offline gateways are configured but were never offered at
                    // checkout. Show their real instructions so a traveler can
                    // pay even when the online gateways are unavailable.
                    $tap_offline = array_intersect_key(
                        TAP_Payment::get_available_gateways(),
                        ['sinpe' => true, 'manual' => true]
                    );
                    if ($tap_offline):
                    ?>
                        <div class="tap-checkout-offline">
                            <h3><?php esc_html_e('Otros métodos de pago', 'travel-agency-platform'); ?></h3>
                            <?php foreach ($tap_offline as $tap_gw): ?>
                                <div class="tap-checkout-offline-row">
                                    <strong><?php echo esc_html($tap_gw['name']); ?></strong>
                                    <?php if ('sinpe' === $tap_gw['id']): ?>
                                        <p><?php echo esc_html(sprintf(__('Transfiere por SINPE Móvil al número %s. La agencia confirmará tu reserva al verificar el pago.', 'travel-agency-platform'), get_option('tap_sinpe_phone', ''))); ?></p>
                                    <?php else: ?>
                                        <p><?php echo nl2br(esc_html((string) get_option('tap_manual_bank_info', ''))); ?></p>
                                        <p class="description"><?php esc_html_e('La agencia confirmará tu reserva al verificar la transferencia.', 'travel-agency-platform'); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
					<?php endif; ?>

                <?php if (TAP_PayPal::is_ready() && TAP_Onvo::is_ready()): ?>
                <script>
                jQuery(document).ready(function($) {
                    $('.tap-payment-tab').on('click', function() {
                        var tab = $(this).data('tab');
                        $('.tap-payment-tab').removeClass('is-active').attr('aria-selected', 'false');
                        $(this).addClass('is-active').attr('aria-selected', 'true');
                        $('.tap-payment-tab-panel').attr('hidden', true).removeClass('is-active');
                        $('#tap-tab-' + tab).removeAttr('hidden').addClass('is-active');
                    });
                    // Keyboard navigation for tabs
                    $('.tap-payment-tab').on('keydown', function(e) {
                        var $tabs = $('.tap-payment-tab');
                        var idx = $tabs.index(this);
                        var newIdx = null;
                        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                            newIdx = (idx + 1) % $tabs.length;
                        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                            newIdx = (idx - 1 + $tabs.length) % $tabs.length;
                        }
                        if (newIdx !== null) {
                            e.preventDefault();
                            $tabs.eq(newIdx).focus().trigger('click');
                        }
                    });
                });
                </script>
                <?php endif; ?>
        </div>
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $timer = $('#tap-hold-timer');
            if (!$timer.length) return;
            var created = new Date($timer.data('created'));
            var holdMinutes = parseInt($timer.data('hold-minutes'), 10) || 30;
            var expiresAt = created.getTime() + holdMinutes * 60 * 1000;
            var $countdown = $('#tap-hold-countdown');
            var expired = false;

            function updateCountdown() {
                if (expired) return;
                var now = Date.now();
                var remaining = expiresAt - now;
                if (remaining <= 0) {
                    expired = true;
                    $countdown.text('00:00');
                    $timer.addClass('tap-hold-expired');
                    $timer.find('.tap-hold-timer-text').html('<?php echo esc_js(__('Tu reserva ha expirado. El slot ha sido liberado.', 'travel-agency-platform')); ?>');
                    $('#tap-onvo-pay-btn, #tap-paypal-button-container, .tap-onvo-payment, .tap-payment-tab-panel, .tap-payment-tabs').hide();
                    return;
                }
                var mins = Math.floor(remaining / 60000);
                var secs = Math.floor((remaining % 60000) / 1000);
                $countdown.text((mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs);
            }

            updateCountdown();
            setInterval(updateCountdown, 1000);
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /** Reusable voucher lookup form with an inline error message. */
    private static function voucher_lookup_form($error, $uid, $code = '', $email = '') {
        ob_start();
        ?>
        <div class="tap-voucher-lookup">
            <p class="tap-error"><?php echo esc_html($error); ?></p>
            <form class="tap-manage-form" method="get" action="">
                <div class="tap-form-grid">
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Código de reserva', 'travel-agency-platform'); ?></label>
                        <input type="text" name="code" required value="<?php echo esc_attr($code); ?>" placeholder="TAP-XXXXXXXX-XXXXXX">
                    </div>
                    <?php if (!$uid): ?>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Correo electrónico de la reserva', 'travel-agency-platform'); ?></label>
                        <input type="email" name="email" required autocomplete="email" value="<?php echo esc_attr($email); ?>" placeholder="<?php echo esc_attr__('tucorreo@ejemplo.com', 'travel-agency-platform'); ?>">
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
                            <input type="email" name="email" required placeholder="<?php echo esc_attr__('tucorreo@ejemplo.com', 'travel-agency-platform'); ?>">
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
            return self::voucher_lookup_form(
                __('No encontramos esa reserva. Revisa el código e inténtalo de nuevo.', 'travel-agency-platform'),
                $uid, $code, ''
            );
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
                                <input type="email" name="email" required autocomplete="email" placeholder="<?php echo esc_attr__('tucorreo@ejemplo.com', 'travel-agency-platform'); ?>">
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
                return self::voucher_lookup_form(
                    __('Ese correo no coincide con el de la reserva. Inténtalo de nuevo.', 'travel-agency-platform'),
                    $uid, $code, $email
                );
            }
            $can = true;
        }

        $user    = get_userdata($booking->client_id);
        $dispute_msg  = '';
        $dispute_err  = '';
        $dispute_open = $booking->id ? TAP_Disputes::active_for_booking($booking->id) : null;

        if (isset($_POST['tap_open_dispute'])
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

        $service_name = $room ? $room->post_title : ($service ? $service->post_title : 'N/D');
        $nights = max(1, (int) $booking->nights);
        $lines  = [
            [
                'desc' => sprintf('%s · %d %s', $service_name, $nights, _n('night', 'nights', $nights, 'travel-agency-platform')),
                'qty'  => 1,
                // total_amount already includes the booking fee, which is
                // listed separately below; don't count it twice.
                'unit' => max(0, round((float) $booking->total_amount - (float) ($booking->booking_fee ?? 0), 2)),
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
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(add_query_arg(['action' => 'tap_calendar_download', 'code' => $booking->booking_code, 'nonce' => wp_create_nonce('tap_front_dash_nonce'), 'email' => !$uid ? $booking->guest_email : ''], admin_url('admin-ajax.php'))); ?>" download><?php esc_html_e('Añadir a calendario', 'travel-agency-platform'); ?></a>
                <?php
                $share_text = sprintf(__('Booking %s - %s', 'travel-agency-platform'), $booking->booking_code, $service_name);
                $share_url = home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code));
                $wa_url = 'https://wa.me/?text=' . rawurlencode($share_text . ' ' . $share_url);
                $email_url = 'mailto:?subject=' . rawurlencode($share_text) . '&body=' . rawurlencode($share_text . "\n\n" . $share_url);
                ?>
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener">WhatsApp</a>
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($email_url); ?>">Email</a>
                <?php if ((int) $booking->client_id === $uid && in_array($booking->status, ['pending', 'confirmed', 'request'], true) && (!$booking->check_in || $booking->check_in >= current_time('Y-m-d'))): ?>
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
                    <?php
                    $qr_data = rawurlencode($booking->booking_code . ' | ' . $service_name . ' | ' . $booking->check_in);
                    // Third-party QR is opt-in: production can disable it to
                    // avoid sending booking data to an external service.
                    $show_qr = apply_filters('tap_voucher_show_external_qr', false);
                    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . $qr_data;
                    ?>
                    <?php if ($show_qr): ?>
                    <div class="tap-voucher-qr">
                        <img src="<?php echo esc_url($qr_url); ?>" alt="<?php esc_attr_e('Ref', 'travel-agency-platform'); ?>" width="120" height="120" loading="lazy">
                    </div>
                    <?php endif; ?>
                    <div class="tap-voucher-code">
                        <span><?php esc_html_e('Código de reserva', 'travel-agency-platform'); ?></span>
                        <strong><?php echo esc_html($booking->booking_code); ?></strong>
                        <button type="button" class="tap-btn tap-btn-ghost tap-btn-sm tap-voucher-copy" data-code="<?php echo esc_attr($booking->booking_code); ?>"><?php esc_html_e('Copiar', 'travel-agency-platform'); ?></button>
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
                            <strong><?php echo esc_html($booking->check_in ? date_i18n(get_option('date_format'), strtotime($booking->check_in)) : '—'); ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html($booking->check_out ? date_i18n(get_option('date_format'), strtotime($booking->check_out)) : '—'); ?></strong>
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
                            <?php if ((float) ($booking->insurance_premium ?? 0) > 0): ?>
                            <tr>
                                <td colspan="3" class="tap-voucher-total-label"><?php esc_html_e('Seguro de viaje', 'travel-agency-platform'); ?></td>
                                <td class="tap-voucher-total"><?php echo esc_html(TAP_Currency::fmt((float) $booking->insurance_premium)); ?></td>
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
                            <?php
                            $holder_name  = $user ? $user->display_name : ($booking->guest_name ?: __('Invitado', 'travel-agency-platform'));
                            $holder_email = $user ? $user->user_email : ($booking->guest_email ?: '');
                            ?>
                            <p><strong><?php echo esc_html($holder_name); ?></strong><?php echo $holder_email ? '<br>' . esc_html($holder_email) : ''; ?></p>
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

            $('.tap-voucher-copy').on('click', function(){
                var code = $(this).data('code'), $btn = $(this), old = $btn.text();
                function copied(){ $btn.text('<?php echo esc_js(__('Copiado ✓', 'travel-agency-platform')); ?>'); setTimeout(function(){ $btn.text(old); }, 2000); }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(copied).catch(function(){});
                } else {
                    var $t = $('<textarea>').val(code).appendTo('body'); $t.select();
                    try { document.execCommand('copy'); copied(); } catch (e) {}
                    $t.remove();
                }
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
        <div class="tap-print-bar" style="margin-bottom:16px;">
            <button type="button" class="tap-btn tap-btn-outline" onclick="window.print()"><?php esc_html_e('Imprimir voucher', 'travel-agency-platform'); ?></button>
        </div>
        <?php
        // Trip tracking (client-facing).
        $trip_id = (int) ($booking->trip_id ?? 0);
        if ($trip_id && class_exists('TAP_Transport_Trips')) :
            $trip = TAP_Transport_Trips::get($trip_id);
            if ($trip):
        ?>
        <div class="tap-trip-tracking" style="margin-top:24px;padding:16px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);background:var(--tap-surface-muted);">
            <h3><?php esc_html_e('Seguimiento del viaje', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Estado:', 'travel-agency-platform'); ?> <strong id="tap-trip-status"><?php echo esc_html($trip->status); ?></strong></p>
            <div id="tap-trip-map" style="height:280px;border-radius:8px;overflow:hidden;margin-top:12px;"></div>
            <link rel="stylesheet" href="<?php echo esc_url(TAP_PLUGIN_URL . 'assets/leaflet/leaflet.css'); ?>">
            <script src="<?php echo esc_url(TAP_PLUGIN_URL . 'assets/leaflet/leaflet.js'); ?>"></script>
            <script>
            (function(){
                if (typeof L === 'undefined') return;
                var map = L.map('tap-trip-map').setView([9.93, -84.08], 8);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap'}).addTo(map);
                var marker = null;
                var tripId = <?php echo (int) $trip_id; ?>;
                var nonce = '<?php echo esc_js(wp_create_nonce('tap_transport_nonce')); ?>';
                function update() {
                    var fd = new FormData();
                    fd.append('action', 'tap_transport_live');
                    fd.append('trip_id', tripId);
                    fd.append('nonce', nonce);
                    fetch((window.tap_ajax && window.tap_ajax.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.success && res.data.position) {
                            var pos = res.data.position;
                            if (marker) map.removeLayer(marker);
                            marker = L.marker([pos.lat, pos.lng]).addTo(map).bindPopup('Tu conductor');
                            map.setView([pos.lat, pos.lng], 13);
                        }
                        if (res && res.success && res.data.trip) {
                            document.getElementById('tap-trip-status').textContent = res.data.trip.status;
                        }
                    });
                }
                update();
                setInterval(update, 30000);
            })();
            </script>
        </div>
        <?php
            endif;
        endif;
        ?>
        <?php
        // Internal notes (agency/admin only).
        $uid = get_current_user_id();
        $can_note = false;
        if ($uid) {
            if (current_user_can('manage_options')) $can_note = true;
            elseif ($booking->agency_id) {
                $ag = TAP_Booking::get_agency_for_user($uid);
                if ($ag && (int) $ag === (int) $booking->agency_id) $can_note = true;
            }
        }
        if ($can_note):
            $internal = (string) ($booking->internal_notes ?? '');
        ?>
        <div class="tap-internal-notes" style="margin-top:24px;padding:16px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);background:var(--tap-surface-muted);">
            <h3><?php esc_html_e('Notas internas', 'travel-agency-platform'); ?></h3>
            <textarea id="tap-internal-note" rows="3" class="tap-input" style="width:100%;"><?php echo esc_textarea($internal); ?></textarea>
            <button type="button" class="tap-btn tap-btn-primary tap-btn-sm" id="tap-save-note" data-booking="<?php echo (int) $booking->id; ?>"><?php esc_html_e('Guardar nota', 'travel-agency-platform'); ?></button>
            <span id="tap-note-status" class="tap-agency-meta" style="margin-left:8px;"></span>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('tap-save-note');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var note = document.getElementById('tap-internal-note').value;
                var fd = new FormData();
                fd.append('action', 'tap_save_internal_note');
                fd.append('booking_id', btn.getAttribute('data-booking'));
                fd.append('note', note);
                fd.append('nonce', '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>');
                fetch((window.tap_ajax && window.tap_ajax.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    var st = document.getElementById('tap-note-status');
                    if (res && res.success) { st.textContent = (res.data && res.data.message) || 'Guardado.'; }
                    else { st.textContent = (res.data && res.data.message) || 'Error.'; }
                })
                .catch(function(){ document.getElementById('tap-note-status').textContent = '<?php echo esc_js(__('Error de conexión.', 'travel-agency-platform')); ?>'; });
            });
        })();
        </script>
        <?php endif; ?>
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

        // Prime ratings once instead of 2 SQL queries per favorite card.
        $rating_pairs = [];
        foreach ((array) $posts as $p) {
            $rating_pairs[] = [$p->post_type, (int) $p->ID];
        }
        if ($rating_pairs) {
            TAP_API::prime_rating_stats($rating_pairs);
        }

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
        $share_url = home_url('/?favs=' . implode(',', $ids));
        ?>
        <div style="margin-top:16px;text-align:center;">
            <button type="button" class="tap-btn tap-btn-sm" onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo esc_js($share_url); ?>'); this.textContent='<?php echo esc_js(__('Copiado', 'travel-agency-platform')); ?>';"><?php esc_html_e('Compartir mi lista', 'travel-agency-platform'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shared_favorites($atts = []) {
        $ids = [];
        if (!empty($atts['ids'])) {
            $ids = array_map('absint', array_filter(explode(',', (string) $atts['ids'])));
        } elseif (isset($_GET['favs'])) {
            $ids = array_map('absint', array_filter(explode(',', sanitize_text_field(wp_unslash($_GET['favs'])))));
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return '<p class="tap-empty">' . esc_html__('No se especificaron favoritos.', 'travel-agency-platform') . '</p>';
        }

        $posts = get_posts([
            'post_type'      => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'],
            'post__in'       => $ids,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'post__in',
        ]);
        if (empty($posts)) {
            return '<p class="tap-empty">' . esc_html__('No se encontraron servicios.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        ?>
        <div class="tap-fav-grid">
            <?php
            foreach ($posts as $post):
                $type = $post->post_type;
                $price = floatval(get_post_meta($post->ID, TAP_API::get_price_key($type) ?: '', true));
            ?>
                <article class="tap-service-card">
                    <?php if (has_post_thumbnail($post->ID)): ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-fav-thumb"><?php echo get_the_post_thumbnail($post->ID, 'medium', ['loading' => 'lazy']); ?></a>
                    <?php endif; ?>
                    <div class="tap-service-card-body">
                        <h3><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                        <?php if ($price): ?>
                            <p class="tap-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Fase 4 — Support chat widget shortcode. */
}
