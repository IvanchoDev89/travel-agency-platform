<?php
/**
 * TAP_Dashboard_Settings — admin pages of settings: plans, subscriptions,
 * promotions and the settings screen itself.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Settings {
    public static function register_settings() {
        register_setting('tap_settings', 'tap_commission_default');
        register_setting('tap_settings', 'tap_currency', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_key($value));
                return in_array($value, TAP_Currency::codes(), true) ? $value : 'USD';
            },
        ]);
        register_setting('tap_settings', 'tap_booking_auto_confirm');
        register_setting('tap_settings', 'tap_stale_booking_hours');
        register_setting('tap_settings', 'tap_payment_methods');
        register_setting('tap_settings', 'tap_terms_page');
        register_setting('tap_settings', 'tap_paypal_enabled');
        register_setting('tap_settings', 'tap_paypal_sandbox');
        register_setting('tap_settings', 'tap_paypal_client_id');
        register_setting('tap_settings', 'tap_paypal_secret');
        register_setting('tap_settings', 'tap_paypal_webhook_id');
        register_setting('tap_settings', 'tap_default_gateway');
        register_setting('tap_settings', 'tap_booking_fee_type', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['none', 'fixed', 'percent'], true) ? $value : 'none';
            },
        ]);
        register_setting('tap_settings', 'tap_booking_fee_value', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(0, floatval($value));
            },
        ]);
        register_setting('tap_settings', 'tap_auto_payment_reminders');
        register_setting('tap_settings', 'tap_payment_reminder_hours', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(1, (int) $value);
            },
        ]);
        register_setting('tap_settings', 'tap_auto_prearrival');
        register_setting('tap_settings', 'tap_auto_review_request');
        register_setting('tap_settings', 'tap_auto_expiry_warnings');
        register_setting('tap_settings', 'tap_expiry_warn_days', [
            'type' => 'number',
            'sanitize_callback' => function ($value) {
                return max(1, (int) $value);
            },
        ]);
        register_setting('tap_settings', 'tap_auto_lead_ack');

        foreach (TAP_Booking::service_type_slugs() as $slug) {
            register_setting('tap_settings', 'tap_cancel_policy_' . $slug, [
                'type' => 'string',
                'sanitize_callback' => function ($value) {
                    return in_array($value, array_keys(TAP_Refunds::cancellation_policies()), true) ? $value : 'flexible';
                },
            ]);
        }
        register_setting('tap_settings', 'tap_booking_auto_complete');
    }

    /**
     * Cached published-listing counts by post type (10 min transient) so the
     * dashboard does not run up to 13 wp_count_posts queries on every view.
     */

    public static function plans_page() {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_plans';

        if (TAP_Dashboard::post_verified('tap_save_plans', 'tap_save_plans')) {
            foreach ((array) wp_unslash($_POST['tap_plans'] ?? []) as $id => $row) {
                $id = (int) $id;
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
                if (!$existing) {
                    continue;
                }
                $wpdb->update(
                    $t,
                    [
                        'name'           => sanitize_text_field(wp_unslash($row['name'] ?? $existing->name)),
                        'price_monthly'  => max(0, floatval($row['price_monthly'] ?? $existing->price_monthly)),
                        'commission_rate'=> (isset($row['commission_rate']) && $row['commission_rate'] !== '') ? max(0, floatval($row['commission_rate'])) : null,
                        'listing_limit'  => (int) ($row['listing_limit'] ?? $existing->listing_limit),
                        'featured_slots' => max(0, (int) ($row['featured_slots'] ?? $existing->featured_slots)),
                        'features'       => sanitize_textarea_field(wp_unslash($row['features'] ?? '')),
                        'is_active'      => !empty($row['is_active']) ? 1 : 0,
                    ],
                    ['id' => $id]
                );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plans saved.', 'travel-agency-platform') . '</p></div>';
        }

        echo '<div class="wrap"><h1>' . esc_html__('Subscription Plans', 'travel-agency-platform') . '</h1>';
        echo '<p class="description">' . esc_html__('Commission rate (%) is used while the plan is active; leave blank to keep the agency/global commission. Listing limit -1 = unlimited.', 'travel-agency-platform') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('tap_save_plans');
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Plan', 'Precio/mes', 'Comisión %', 'Límite listados', 'Destacados', 'Features', 'Activo'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($wpdb->get_results("SELECT * FROM $t ORDER BY price_monthly ASC, id ASC") as $p) {
            $features_txt = implode("\n", (array) json_decode($p->features ?: '', true)) ?: $p->features;
            echo '<tr>';
            echo '<td><input type="text" name="tap_plans[' . (int) $p->id . '][name]" value="' . esc_attr($p->name) . '" class="regular-text"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][price_monthly]" value="' . esc_attr($p->price_monthly) . '" min="0" step="0.01" style="width:90px;"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][commission_rate]" value="' . esc_attr($p->commission_rate === null ? '' : $p->commission_rate) . '" min="0" step="0.01" style="width:70px;" placeholder="' . esc_attr__('estándar', 'travel-agency-platform') . '"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][listing_limit]" value="' . esc_attr($p->listing_limit) . '" style="width:80px;"></td>';
            echo '<td><input type="number" name="tap_plans[' . (int) $p->id . '][featured_slots]" value="' . esc_attr($p->featured_slots) . '" min="0" style="width:70px;"></td>';
            echo '<td><textarea name="tap_plans[' . (int) $p->id . '][features]" rows="3" cols="30" placeholder="' . esc_attr__('una por línea', 'travel-agency-platform') . '">' . esc_textarea($features_txt) . '</textarea></td>';
            echo '<td><input type="checkbox" name="tap_plans[' . (int) $p->id . '][is_active]" value="1" ' . checked(1, $p->is_active, false) . '></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Save plans', 'travel-agency-platform'), 'primary', 'tap_save_plans');
        echo '</form></div>';
    }


    public static function subscriptions_page() {
        global $wpdb;
        $t_sub  = $wpdb->prefix . 'tap_agency_subscriptions';
        $t_plan = $wpdb->prefix . 'tap_plans';
        $t_age  = $wpdb->prefix . 'tap_agencies';

        if (TAP_Dashboard::post_verified('tap_mark_paid', 'tap_mark_subscription_paid')) {
            $sub_id = intval($_POST['sub_id'] ?? 0);
            $months = intval($_POST['months'] ?? 1);
            $result = TAP_Subscriptions::mark_paid($sub_id, get_current_user_id(), $months);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(/* translators: %s: fecha de vencimiento de la suscripción. */ __('Subscription marked as paid until %s', 'travel-agency-platform'), $result)) . '</p></div>';
            }
        }

        if (TAP_Dashboard::post_verified('tap_expire_sub', 'tap_expire_subscription')) {
            $wpdb->update($t_sub, ['status' => 'expired'], ['id' => intval($_POST['sub_id'] ?? 0)]);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Subscription expired.', 'travel-agency-platform') . '</p></div>';
        }

        $subs = $wpdb->get_results(
            "SELECT s.*, a.name AS agency_name, p.name AS plan_name, p.price_monthly
             FROM $t_sub s
             LEFT JOIN $t_age a ON a.id = s.agency_id
             LEFT JOIN $t_plan p ON p.id = s.plan_id
             ORDER BY s.created_at DESC LIMIT 200"
        );
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_sub WHERE status = 'pending'");
        $active  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_sub WHERE status = 'active'");

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agency Subscriptions', 'travel-agency-platform') . '</h1>';
        echo '<div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html($active) . '</span><span class="tap-stat-label">' . esc_html__('Activas', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;">' . esc_html($pending) . '</span><span class="tap-stat-label">' . esc_html__('Pendientes de pago', 'travel-agency-platform') . '</span></div>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Agencia', 'Plan', 'Precio', 'Estado', 'Pagado hasta', 'Pago', 'Creado', 'Acciones'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$subs) {
            echo '<tr><td colspan="8">' . esc_html__('No subscriptions yet.', 'travel-agency-platform') . '</td></tr>';
        }
        foreach ($subs as $s) {
            $status_color = 'active' === $s->status ? '#047857' : ('pending' === $s->status ? '#b45309' : '#94a3b8');
            echo '<tr>';
            echo '<td>' . esc_html($s->agency_name ?: ('#' . $s->agency_id)) . '</td>';
            echo '<td>' . esc_html($s->plan_name ?: '—') . '</td>';
            echo '<td>' . esc_html(TAP_Currency::fmt($s->price_monthly)) . '/mes</td>';
            echo '<td style="color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($s->status) . '</td>';
            echo '<td>' . esc_html($s->paid_until ?: '—') . '</td>';
            echo '<td>' . esc_html($s->payment_method . ' · ' . $s->payment_status) . '</td>';
            echo '<td>' . esc_html($s->created_at) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:8px;">';
            wp_nonce_field('tap_mark_subscription_paid');
            echo '<input type="hidden" name="sub_id" value="' . (int) $s->id . '">';
            echo '<input type="number" name="months" value="1" min="1" max="24" style="width:60px;">';
            submit_button(__('Marcar pagado', 'travel-agency-platform'), 'small', 'tap_mark_paid', false);
            echo '</form>';
            if ('expired' !== $s->status) {
                echo '<form method="post" style="display:inline-block;">';
                wp_nonce_field('tap_expire_subscription');
                echo '<input type="hidden" name="sub_id" value="' . (int) $s->id . '">';
                submit_button(__('Expirar', 'travel-agency-platform'), 'small', 'tap_expire_sub', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }


    public static function promotions_page() {
        global $wpdb;
        $t_promo = $wpdb->prefix . 'tap_promos';
        $t_age   = $wpdb->prefix . 'tap_agencies';

        if (TAP_Dashboard::post_verified('tap_promo_activate', 'tap_promo_activate_nonce')) {
            $months = min(24, max(1, intval($_POST['months'] ?? 1)));
            $result = class_exists('TAP_Promotions') ? TAP_Promotions::activate(intval($_POST['promo_id'] ?? 0), get_current_user_id(), $months) : null;
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(/* translators: %s: fecha de vencimiento de la promoción. */ __('Promotion activated until %s', 'travel-agency-platform'), $result)) . '</p></div>';
            }
        }

        if (TAP_Dashboard::post_verified('tap_promo_expire', 'tap_promo_expire_nonce')) {
            if (class_exists('TAP_Promotions')) {
                TAP_Promotions::expire(intval($_POST['promo_id'] ?? 0));
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Promotion expired.', 'travel-agency-platform') . '</p></div>';
        }

        $promos = $wpdb->get_results(
            "SELECT p.*, a.name AS agency_name
             FROM $t_promo p
             LEFT JOIN $t_age a ON a.id = p.agency_id
             ORDER BY p.created_at DESC LIMIT 200"
        );
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_promo WHERE status = 'pending'");
        $active  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_promo WHERE status = 'active'");
        $confirmed_total = (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM $t_promo WHERE status = 'active'");
        $price = class_exists('TAP_Promotions') ? TAP_Promotions::get_price() : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Listado Promotions (Destacados)', 'travel-agency-platform') . '</h1>';
        /* translators: %s: precio mensual del destacado */
        echo '<p class="description">' . esc_html(sprintf(__('Precio por destacado: %s/mes por listado. Confirma el pago para activar la promoción hasta la fecha correspondiente.', 'travel-agency-platform'), TAP_Currency::fmt($price))) . '</p>';
        echo '<div class="tap-stat-grid" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;">' . esc_html($active) . '</span><span class="tap-stat-label">' . esc_html__('Activos', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;">' . esc_html($pending) . '</span><span class="tap-stat-label">' . esc_html__('Pendientes de pago', 'travel-agency-platform') . '</span></div>';
        echo '<div class="tap-stat-card"><span class="tap-stat-number">' . esc_html(TAP_Currency::fmt($confirmed_total)) . '</span><span class="tap-stat-label">' . esc_html__('Facturado en destacados', 'travel-agency-platform') . '</span></div>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Agencia', 'Listado', 'Meses', 'Monto', 'Estado', 'Hasta', 'Solicitado', 'Acciones'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$promos) {
            echo '<tr><td colspan="8">' . esc_html__('No promotions yet.', 'travel-agency-platform') . '</td></tr>';
        }
        foreach ($promos as $p) {
            $title = get_the_title($p->listing_id);
            if (empty($title)) {
                $title = __('Listado borrado', 'travel-agency-platform');
            }
            $status_color = 'active' === $p->status ? '#047857' : ('pending' === $p->status ? '#b45309' : '#94a3b8');
            echo '<tr>';
            echo '<td>' . esc_html($p->agency_name ?: ('#' . $p->agency_id)) . '</td>';
            echo '<td>' . esc_html($title) . ' <small>(' . esc_html(str_replace('tap_', '', (string) get_post_type($p->listing_id))) . ')</small></td>';
            echo '<td>' . esc_html($p->months) . '</td>';
            echo '<td>' . esc_html(TAP_Currency::fmt($p->amount)) . '</td>';
            echo '<td style="color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($p->status) . '</td>';
            echo '<td>' . esc_html($p->paid_until ?: '—') . '</td>';
            echo '<td>' . esc_html($p->created_at) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:8px;">';
            wp_nonce_field('tap_promo_activate_nonce');
            echo '<input type="hidden" name="promo_id" value="' . (int) $p->id . '">';
            echo '<input type="number" name="months" value="' . esc_attr($p->months) . '" min="1" max="24" style="width:60px;">';
            submit_button(__('Marcar activo', 'travel-agency-platform'), 'small', 'tap_promo_activate', false);
            echo '</form>';
            if ('expired' !== $p->status) {
                echo '<form method="post" style="display:inline-block;">';
                wp_nonce_field('tap_promo_expire_nonce');
                echo '<input type="hidden" name="promo_id" value="' . (int) $p->id . '">';
                submit_button(__('Expirar', 'travel-agency-platform'), 'small', 'tap_promo_expire', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }


    public static function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Travel Platform Settings', 'travel-agency-platform'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('tap_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_commission_default"><?php esc_html_e('Default Commission %', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_commission_default" name="tap_commission_default" value="<?php echo esc_attr(get_option('tap_commission_default', '10')); ?>" step="0.01" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_currency"><?php esc_html_e('Default Currency', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_currency" name="tap_currency">
                                <?php foreach (TAP_Currency::codes() as $tap_code): ?>
                                <option value="<?php echo esc_attr($tap_code); ?>" <?php selected(TAP_Currency::code(), $tap_code); ?>>
                                    <?php echo esc_html($tap_code . ' (' . TAP_Currency::symbol($tap_code) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_booking_auto_confirm"><?php esc_html_e('Auto-confirm Bookings', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_booking_auto_confirm" name="tap_booking_auto_confirm" value="1" <?php checked('1', get_option('tap_booking_auto_confirm', '0')); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="tap_stale_booking_hours"><?php esc_html_e('Cancelar pendientes tras (horas)', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_stale_booking_hours" name="tap_stale_booking_hours" value="<?php echo esc_attr(get_option('tap_stale_booking_hours', '24')); ?>" min="1" step="1" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_terms_page"><?php esc_html_e('Terms & Conditions Page', 'travel-agency-platform'); ?></label></th>
                        <td><?php wp_dropdown_pages(['name' => 'tap_terms_page', 'selected' => get_option('tap_terms_page', ''), 'show_option_none' => __('-- Select --', 'travel-agency-platform')]); ?></td>
                    </tr>
                    <tr>
                        <th><label for="tap_default_gateway"><?php esc_html_e('Default Payment Gateway', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_default_gateway" name="tap_default_gateway">
                                <option value="paypal" <?php selected('paypal', get_option('tap_default_gateway', 'paypal')); ?>><?php esc_html_e('PayPal', 'travel-agency-platform'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('Booking Fee (client)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_booking_fee_type"><?php esc_html_e('Fee Type', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select id="tap_booking_fee_type" name="tap_booking_fee_type">
                                <option value="none" <?php selected('none', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('No fee', 'travel-agency-platform'); ?></option>
                                <option value="fixed" <?php selected('fixed', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('Fixed amount', 'travel-agency-platform'); ?></option>
                                <option value="percent" <?php selected('percent', get_option('tap_booking_fee_type', 'none')); ?>><?php esc_html_e('Percentage of total', 'travel-agency-platform'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Charged to the client on top of the booking total. This revenue belongs to the platform (not the agency).', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_booking_fee_value"><?php esc_html_e('Fee Value', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_booking_fee_value" name="tap_booking_fee_value" min="0" step="0.01" value="<?php echo esc_attr(get_option('tap_booking_fee_value', '0')); ?>" class="regular-text" style="width: 140px;"></td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('Cancellation policies (T7, booking lifecycle)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Definiciones', 'travel-agency-platform'); ?></th>
                        <td>
                            <ul style="margin:0;padding-left:18px;list-style:disc;">
                                <li><?php esc_html_e('Flexible — reembolso total si cancelas al menos 24h antes del check-in.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('Moderada — reembolso total 5 días antes; 50% entre 2 y 5 días; 0% menos de 2 días.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('Estricta — 50% de reembolso hasta 7 días antes; 0% después.', 'travel-agency-platform'); ?></li>
                                <li><?php esc_html_e('No reembolsable — sin reembolso.', 'travel-agency-platform'); ?></li>
                            </ul>
                            <p class="description"><?php esc_html_e('La política efectiva de un listado se resuelve así: política propia del listado → política por defecto del tipo de servicio (abajo). La agencia puede fijar una política distinta por listado en el editor de su alojamiento/tour.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <?php foreach (TAP_Booking::service_type_slugs() as $slug) : ?>
                        <tr>
                            <th><label for="tap_cancel_policy_<?php echo esc_attr($slug); ?>"><?php echo esc_html(TAP_Booking::service_type_labels()[$slug] ?? $slug); ?></label></th>
                            <td>
                                <select id="tap_cancel_policy_<?php echo esc_attr($slug); ?>" name="tap_cancel_policy_<?php echo esc_attr($slug); ?>">
                                    <?php foreach (TAP_Refunds::cancellation_policies() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, get_option('tap_cancel_policy_' . $slug, TAP_Refunds::default_cancellation_policy($slug))); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><label for="tap_booking_auto_complete"><?php esc_html_e('Auto-completar reservas', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_booking_auto_complete" name="tap_booking_auto_complete" value="1" <?php checked('1', get_option('tap_booking_auto_complete', '1')); ?>>
                            <p class="description"><?php esc_html_e('Marcar automáticamente como "completada" toda reserva confirmada cuya fecha de salida ya pasó.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('Automations (T4, daily cron)', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_auto_payment_reminders"><?php esc_html_e('Payment reminders', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_payment_reminders" name="tap_auto_payment_reminders" value="1" <?php checked('1', get_option('tap_auto_payment_reminders', '0')); ?>>
                            <p class="description"><?php esc_html_e('Email guests with pending/request bookings that never got paid.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_payment_reminder_hours"><?php esc_html_e('Remind after (hours)', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_payment_reminder_hours" name="tap_payment_reminder_hours" min="1" step="1" value="<?php echo esc_attr(get_option('tap_payment_reminder_hours', '12')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_prearrival"><?php esc_html_e('Pre-arrival message', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_prearrival" name="tap_auto_prearrival" value="1" <?php checked('1', get_option('tap_auto_prearrival', '0')); ?>>
                            <p class="description"><?php esc_html_e('Send guests a heads-up when their confirmed stay starts within 48h.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_review_request"><?php esc_html_e('Post-stay review request', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_review_request" name="tap_auto_review_request" value="1" <?php checked('1', get_option('tap_auto_review_request', '0')); ?>>
                            <p class="description"><?php esc_html_e('Ask guests to leave a review a few days after a completed stay.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_expiry_warnings"><?php esc_html_e('Expiry warnings', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_expiry_warnings" name="tap_auto_expiry_warnings" value="1" <?php checked('1', get_option('tap_auto_expiry_warnings', '0')); ?>>
                            <p class="description"><?php esc_html_e('Email agencies when their plan subscription or a featured listing is near expiry.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap_expiry_warn_days"><?php esc_html_e('Warn days before expiry', 'travel-agency-platform'); ?></label></th>
                        <td><input type="number" id="tap_expiry_warn_days" name="tap_expiry_warn_days" min="1" step="1" value="<?php echo esc_attr(get_option('tap_expiry_warn_days', '3')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_auto_lead_ack"><?php esc_html_e('Contact auto-reply', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="checkbox" id="tap_auto_lead_ack" name="tap_auto_lead_ack" value="1" <?php checked('1', get_option('tap_auto_lead_ack', '1')); ?>>
                            <p class="description"><?php esc_html_e('Send an acknowledgment email to people who use the contact form.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;"><?php esc_html_e('PayPal Settings', 'travel-agency-platform'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_paypal_enabled"><?php esc_html_e('Enable PayPal', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_paypal_enabled" name="tap_paypal_enabled" value="1" <?php checked('1', get_option('tap_paypal_enabled', '0')); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_sandbox"><?php esc_html_e('Sandbox Mode', 'travel-agency-platform'); ?></label></th>
                        <td><input type="checkbox" id="tap_paypal_sandbox" name="tap_paypal_sandbox" value="1" <?php checked('1', get_option('tap_paypal_sandbox', '1')); ?>> <span class="description"><?php esc_html_e('Enable to test payments in PayPal Sandbox', 'travel-agency-platform'); ?></span></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_client_id"><?php esc_html_e('Client ID', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="tap_paypal_client_id" name="tap_paypal_client_id" value="<?php echo esc_attr(get_option('tap_paypal_client_id', '')); ?>" class="regular-text" style="width: 400px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_secret"><?php esc_html_e('Secret Key', 'travel-agency-platform'); ?></label></th>
                        <td><input type="password" id="tap_paypal_secret" name="tap_paypal_secret" value="<?php echo esc_attr(get_option('tap_paypal_secret', '')); ?>" class="regular-text" style="width: 400px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tap_paypal_webhook_id"><?php esc_html_e('Webhook ID', 'travel-agency-platform'); ?></label></th>
                        <td><input type="text" id="tap_paypal_webhook_id" name="tap_paypal_webhook_id" value="<?php echo esc_attr(get_option('tap_paypal_webhook_id', '')); ?>" class="regular-text" style="width: 400px;">
                        <p class="description"><?php esc_html_e('Webhook URL:', 'travel-agency-platform'); ?> <code><?php echo esc_url(home_url('/wp-json/tap/v1/paypal-webhook')); ?></code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
