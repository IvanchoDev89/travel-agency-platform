<?php
/**
 * TAP_Shortcode_Account — account + agency dashboards and services:
 * [tap_dashboard], [tap_agency_panel], [tap_plans], [tap_agency_services],
 * [tap_agency_register] and [tap_chatbot].
 *
 * Extracted from TAP_Shortcodes (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Shortcode_Account {
    public static function dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to access the dashboard.', 'travel-agency-platform') . '</p>';
        }

        $user = wp_get_current_user();
        $is_agency = in_array('tap_agency_admin', (array) $user->roles, true) || in_array('tap_agency_employee', (array) $user->roles, true);

        if ($is_agency) {
            return TAP_Shortcode_Agency_Panel::agency_panel($user);
        }

        ob_start();
        ?>
        <div class="tap-dashboard">
            <h2><?php printf(/* translators: %s: nombre del usuario */ esc_html__('Welcome, %s!', 'travel-agency-platform'), esc_html($user->display_name)); ?></h2>
            <div class="tap-dashboard-links">
                <a href="<?php echo esc_url(home_url('/my-bookings')); ?>" class="tap-btn"><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></a>
                <a href="<?php echo esc_url(home_url('/agency-profile')); ?>" class="tap-btn"><?php esc_html_e('Agency Profile', 'travel-agency-platform'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
        // Contracting a plan is gated by the approval review, so the paying
        // buttons must not be rendered for an agency that cannot subscribe
        // yet: they only promise a dead end.
        $agency_ok  = $agency_id && TAP_Approval::is_approved($agency_id);
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
                /* translators: %d: número máximo de listados permitidos por el plan */
                : sprintf(__('Hasta %d listados', 'travel-agency-platform'), (int) $plan->listing_limit);
            $commission_txt = null !== $plan->commission_rate
                /* translators: %s: porcentaje de comisión */
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
            /* translators: %d: número de destacados incluidos en el plan */
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
            } elseif ($agency_ok && (float) $plan->price_monthly > 0) {
                // Free plans are never sold: both subscription endpoints reject a
                // price of 0, so a button on that card would only lead to an error.
                $html .= $paypal_ready
                    ? '<button type="button" class="tap-btn tap-btn-primary tap-plan-paypal" data-plan-id="' . (int) $plan->id . '" data-plan-name="' . esc_attr($plan->name) . '" data-amount="' . esc_attr((float) $plan->price_monthly) . '">' . esc_html__('Pagar con PayPal', 'travel-agency-platform') . '</button>'
                    : '<button type="button" class="tap-btn tap-btn-primary tap-plan-subscribe" data-plan-id="' . (int) $plan->id . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html__('Seleccionar plan', 'travel-agency-platform') . '</button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        if ($agency_id && !$agency_ok) {
            $html .= '<p class="tap-plan-note tap-plan-review">' . esc_html__('Tu agencia está en revisión. Podrás contratar un plan cuando un administrador la apruebe.', 'travel-agency-platform') . '</p>';
        } elseif ($agency_id && !$current) {
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
            $ids = array_merge($ids, TAP_Shortcodes::ids_by_meta([$prefix], $agency_id));
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
                            if (res.data.redirect) {
                                window.location.href = res.data.redirect;
                            } else {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear mi cuenta de agencia', 'travel-agency-platform')); ?>');
                            }
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
