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

    public static function agency_profile($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $agency_id = (int) $atts['id'];
        if (!$agency_id && isset($_GET['id'])) {
            $agency_id = (int) $_GET['id'];
        }
        $agency = $agency_id ? get_post($agency_id) : null;
        if (!$agency || $agency->post_type !== 'tap_agency') {
            return '<p class="tap-empty">' . esc_html__('Agencia no encontrada.', 'travel-agency-platform') . '</p>';
        }

        $verified    = (bool) get_post_meta($agency_id, '_tap_agency_verified', true);
        $commission  = TAP_Booking::get_agency_commission($agency_id);
        $email       = (string) get_post_meta($agency_id, '_tap_agency_email', true);
        $phone       = (string) get_post_meta($agency_id, '_tap_agency_phone', true);
        $whatsapp    = (string) get_post_meta($agency_id, '_tap_agency_whatsapp', true);
        $website     = (string) get_post_meta($agency_id, '_tap_agency_website', true);
        $address     = (string) get_post_meta($agency_id, '_tap_agency_address', true);
        $city        = (string) get_post_meta($agency_id, '_tap_agency_city', true);
        $country     = (string) get_post_meta($agency_id, '_tap_agency_country', true);
        $is_active   = get_post_meta($agency_id, '_tap_agency_is_active', true);
        // Sync from tap_agencies table if meta is empty.
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_agencies WHERE user_id = %d LIMIT 1", (int) get_post_meta($agency_id, '_tap_agency_user_id', true)));
        if ($row) {
            if (!$email) $email = (string) $row->email;
            if (!$phone) $phone = (string) $row->phone;
            if (!$whatsapp) $whatsapp = (string) $row->whatsapp;
            if (!$website) $website = (string) $row->website;
            if (!$address) $address = (string) $row->address;
            if (!$city) $city = (string) $row->city;
            if (!$country) $country = (string) $row->country;
        }

        // Services published by this agency.
        $types = TAP_Post_Types::get_service_types();
        unset($types['tap_room'], $types['tap_agency']);
        $services_by_type = [];
        $all_services = [];
        foreach ($types as $pt => $label) {
            $ids = get_posts([
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_key'       => TAP_Post_Types::agency_meta_key($pt),
                'meta_value'     => $agency_id,
                'fields'         => 'ids',
            ]);
            if ($ids) {
                $services_by_type[$pt] = ['label' => $label, 'ids' => $ids];
                $all_services = array_merge($all_services, $ids);
            }
        }

        // Reviews for this agency's services.
        $reviews = [];
        if ($all_services) {
            $placeholders = implode(',', array_fill(0, count($all_services), '%d'));
            $reviews = $wpdb->get_results($wpdb->prepare(
                "SELECT r.* FROM {$wpdb->prefix}tap_reviews r
                 WHERE r.service_id IN ({$placeholders}) AND r.is_approved = 1
                 ORDER BY r.created_at DESC LIMIT 20",
                $all_services
            ));
        }

        // Rating stats.
        $rating_avg = 0;
$rating_count = 0;
        if ($reviews) {
            $sum = 0;
            foreach ($reviews as $rv) { $sum += (float) $rv->rating; }
            $rating_count = count($reviews);
            $rating_avg = $rating_count ? $sum / $rating_count : 0;
        }

        // Leads count (for social proof).
        $leads_count = class_exists('TAP_Leads') ? TAP_Leads::count_for_agency($agency_id) : 0;

        ob_start();
        ?>
        <div class="tap-agency-profile">
            <!-- Hero -->
            <section class="tap-agency-hero" style="position:relative;background:linear-gradient(135deg,var(--tap-primary-bg),#fff);border:1px solid var(--tap-primary-border);border-radius:var(--tap-radius-xl);padding:var(--tap-space-8);margin-bottom:var(--tap-space-6);overflow:hidden;">
                <div style="display:flex;align-items:center;gap:var(--tap-space-5);flex-wrap:wrap;">
                    <div style="flex:0 0 96px;width:96px;height:96px;border-radius:50%;overflow:hidden;background:var(--tap-primary-bg);display:flex;align-items:center;justify-content:center;border:3px solid var(--tap-primary-border);">
                        <?php if (has_post_thumbnail($agency_id)): ?>
                            <?php echo get_the_post_thumbnail($agency_id, 'thumbnail', ['style' => 'width:100%;height:100%;object-fit:cover;']); ?>
                        <?php else: ?>
                            <span style="font-size:2rem;color:var(--tap-primary-dark);font-weight:800;"><?php echo esc_html(mb_substr($agency->post_title, 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:240px;">
                        <h1 style="margin:0 0 var(--tap-space-2);font-size:clamp(1.5rem,3vw,2rem);color:var(--tap-neutral-900);"><?php echo esc_html($agency->post_title); ?></h1>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:var(--tap-space-3);">
                            <?php if ($verified): ?>
                                <span style="padding:3px 10px;border-radius:999px;background:var(--tap-primary);color:#fff;font-size:var(--tap-text-xs);font-weight:700;">✓ <?php esc_html_e('Verificada', 'travel-agency-platform'); ?></span>
                            <?php endif; ?>
                            <?php if ($rating_count > 0): ?>
                                <span style="padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:var(--tap-text-xs);font-weight:700;">★ <?php echo esc_html(number_format($rating_avg, 1)); ?> (<?php echo (int) $rating_count; ?>)</span>
                            <?php endif; ?>
                            <?php if ($city || $country): ?>
                                <span style="padding:3px 10px;border-radius:999px;background:var(--tap-neutral-100);color:var(--tap-neutral-600);font-size:var(--tap-text-xs);font-weight:600;">📍 <?php echo esc_html(trim($city . ', ' . $country, ', ')); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($agency->post_content): ?>
                            <p style="margin:0;color:var(--tap-neutral-700);line-height:1.6;max-width:60ch;"><?php echo esc_html($agency->post_content); ?></p>
                        <?php endif; ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:var(--tap-space-4);">
                            <?php if ($email): ?><a href="mailto:<?php echo esc_attr($email); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Email', 'travel-agency-platform'); ?></a><?php endif; ?>
                            <?php if ($phone): ?><a href="tel:<?php echo esc_attr($phone); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Llamar', 'travel-agency-platform'); ?></a><?php endif; ?>
                            <?php if ($whatsapp): ?><a href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/', '', $whatsapp)); ?>" target="_blank" rel="noopener" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('WhatsApp', 'travel-agency-platform'); ?></a><?php endif; ?>
                            <?php if ($website): ?><a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener" class="tap-btn tap-btn-sm tap-btn-outline"><?php esc_html_e('Sitio web', 'travel-agency-platform'); ?></a><?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Services -->
            <?php if ($services_by_type): ?>
            <section style="margin-bottom:var(--tap-space-6);">
                <h2 style="margin:0 0 var(--tap-space-4);"><?php esc_html_e('Servicios', 'travel-agency-platform'); ?></h2>
                <?php foreach ($services_by_type as $pt => $info): ?>
                    <div style="margin-bottom:var(--tap-space-5);">
                        <h3 style="margin:0 0 var(--tap-space-3);font-size:var(--tap-text-lg);color:var(--tap-neutral-800);"><?php echo esc_html($info['label']); ?> <span style="font-size:var(--tap-text-sm);color:var(--tap-neutral-400);font-weight:400;">(<?php echo count($info['ids']); ?>)</span></h3>
                        <div class="tap-services-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                            <?php
                            foreach ($info['ids'] as $sid): $sp = get_post($sid);
if (!$sp) continue;
?>
                                <div class="tap-service-card">
                                    <?php if (has_post_thumbnail($sid)): ?>
                                        <a href="<?php echo esc_url(get_permalink($sid)); ?>"><?php echo get_the_post_thumbnail($sid, 'medium', ['loading' => 'lazy', 'decoding' => 'async']); ?></a>
                                    <?php endif; ?>
                                    <div class="tap-service-card-body">
                                        <h3><a href="<?php echo esc_url(get_permalink($sid)); ?>"><?php echo esc_html($sp->post_title); ?></a></h3>
                                        <p class="tap-service-excerpt"><?php echo esc_html(wp_trim_words($sp->post_excerpt ?: $sp->post_content, 18)); ?></p>
                                        <a href="<?php echo esc_url(get_permalink($sid)); ?>" class="tap-btn tap-btn-outline tap-btn-sm"><?php esc_html_e('Ver detalle', 'travel-agency-platform'); ?></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php else: ?>
                <p class="tap-empty"><?php esc_html_e('Esta agencia aún no tiene servicios publicados.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>

            <!-- Reviews -->
            <?php if ($reviews): ?>
            <section style="margin-bottom:var(--tap-space-6);">
                <h2 style="margin:0 0 var(--tap-space-4);"><?php esc_html_e('Reseñas', 'travel-agency-platform'); ?> <span style="font-size:var(--tap-text-sm);color:var(--tap-neutral-400);font-weight:400;">(<?php echo (int) $rating_count; ?>)</span></h2>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($reviews as $rv): ?>
                        <article style="border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);padding:var(--tap-space-4);">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <strong><?php echo esc_html(get_the_author_meta('display_name', $rv->user_id) ?: __('Viajero', 'travel-agency-platform')); ?></strong>
                                <?php if ($rv->is_verified): ?><span style="font-size:11px;color:var(--tap-primary-dark);font-weight:700;">✓ <?php esc_html_e('Verificado', 'travel-agency-platform'); ?></span><?php endif; ?>
                                <span style="color:#d97706;font-weight:700;margin-left:auto;"><?php echo str_repeat('★', (int) $rv->rating); ?></span>
                            </div>
                            <?php if ($rv->title): ?><h4 style="margin:0 0 4px;"><?php echo esc_html($rv->title); ?></h4><?php endif; ?>
                            <p style="margin:0 0 8px;color:var(--tap-neutral-700);"><?php echo esc_html($rv->content); ?></p>
                            <?php
                            $imgs = array_filter(array_map('absint', explode(',', (string) $rv->images)));
                            if ($imgs):
                            ?>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                                    <?php foreach ($imgs as $img_id): ?>
                                        <?php
                                        $iu = wp_get_attachment_image_url($img_id, 'thumbnail');
if (!$iu) continue;
?>
                                        <img src="<?php echo esc_url($iu); ?>" alt="" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:var(--tap-radius-md);">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <small style="color:var(--tap-neutral-400);"><?php echo esc_html($rv->created_at); ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Contact -->
            <section style="margin-bottom:var(--tap-space-6);background:var(--tap-surface-muted);border-radius:var(--tap-radius-xl);padding:var(--tap-space-6);">
                <h2 style="margin:0 0 var(--tap-space-3);"><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></h2>
                <div style="display:flex;flex-wrap:wrap;gap:var(--tap-space-4);">
                    <?php if ($email): ?><div><strong><?php esc_html_e('Email', 'travel-agency-platform'); ?>:</strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></div><?php endif; ?>
                    <?php if ($phone): ?><div><strong><?php esc_html_e('Teléfono', 'travel-agency-platform'); ?>:</strong> <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a></div><?php endif; ?>
                    <?php if ($whatsapp): ?><div><strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/', '', $whatsapp)); ?>" target="_blank" rel="noopener"><?php echo esc_html($whatsapp); ?></a></div><?php endif; ?>
                    <?php if ($address || $city || $country): ?><div><strong><?php esc_html_e('Dirección', 'travel-agency-platform'); ?>:</strong> <?php echo esc_html(trim($address . ', ' . $city . ', ' . $country, ', ')); ?></div><?php endif; ?>
                </div>
                <?php if ($website): ?>
                    <div style="margin-top:var(--tap-space-3);"><a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener" class="tap-btn tap-btn-outline"><?php esc_html_e('Visitar sitio web', 'travel-agency-platform'); ?></a></div>
                <?php endif; ?>
            </section>

            <!-- Chat de contacto directo -->
            <section style="margin-bottom:var(--tap-space-6);">
                <h2 style="margin:0 0 var(--tap-space-3);"><?php esc_html_e('Chat directo', 'travel-agency-platform'); ?></h2>
                <p class="tap-agency-meta" style="margin-bottom:var(--tap-space-3);"><?php esc_html_e('Envía un mensaje a la agencia. Te responderán lo antes posible.', 'travel-agency-platform'); ?></p>
                <?php echo do_shortcode('[tap_lead_form agency="' . (int) $agency_id . '" source="agency"]'); ?>
            </section>

            <!-- Newsletter -->
            <section style="margin-bottom:var(--tap-space-6);background:var(--tap-primary-bg);border:1px solid var(--tap-primary-border);border-radius:var(--tap-radius-xl);padding:var(--tap-space-6);">
                <h2 style="margin:0 0 var(--tap-space-2);"><?php esc_html_e('Newsletter', 'travel-agency-platform'); ?></h2>
                <p style="margin:0 0 var(--tap-space-3);color:var(--tap-neutral-600);font-size:var(--tap-text-sm);"><?php esc_html_e('Recibe ofertas y novedades de esta agencia.', 'travel-agency-platform'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="tap_lead_form">
                    <input type="hidden" name="agency_id" value="<?php echo (int) $agency_id; ?>">
                    <input type="email" name="email" placeholder="<?php esc_attr_e('Tu email', 'travel-agency-platform'); ?>" required style="flex:1;min-width:200px;padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);">
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-sm"><?php esc_html_e('Suscribirme', 'travel-agency-platform'); ?></button>
                </form>
            </section>
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
        $types = ['tap_accommodation', 'tap_tour', 'tap_experience', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

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
