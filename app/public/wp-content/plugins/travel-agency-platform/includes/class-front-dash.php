<?php
/**
 * TAP_Front_Dash — Frontend role-based dashboard system.
 *
 * Provides a unified /mi-cuenta/ hub with:
 *  - Sidebar navigation scoped to the current user's role
 *  - Client dashboard: overview stats, recent bookings, favorites, reviews
 *  - Agency dashboard: wraps the existing agency_panel with a sidebar
 *  - Security: nonce verification on all mutations, capability checks, data sanitization
 *
 * @package TravelAgencyPlatform
 * @since 1.5.0
 */
defined('ABSPATH') || exit;

class TAP_Front_Dash {

    /* ── bootstrap ─────────────────────────────────────────────────── */

    public static function init() {
        add_shortcode('tap_front_dash', [__CLASS__, 'render']);

        // AJAX handlers (client actions)
        add_action('wp_ajax_tap_dash_cancel_booking', ['TAP_Ajax_Dash', 'ajax_cancel_booking']);
        add_action('wp_ajax_tap_dash_toggle_favorite', ['TAP_Ajax_Dash', 'ajax_toggle_favorite']);
        add_action('wp_ajax_tap_dash_delete_review', ['TAP_Ajax_Dash', 'ajax_delete_review']);

        // AJAX handlers (agency back-office, Fase 16)
        add_action('wp_ajax_tap_dash_agency_booking', ['TAP_Ajax_Dash', 'ajax_agency_booking']);
        add_action('wp_ajax_tap_dash_agency_payout', ['TAP_Ajax_Dash', 'ajax_agency_payout']);
        add_action('wp_ajax_tap_dash_chat_thread', ['TAP_Ajax_Dash', 'ajax_chat_thread']);
        add_action('wp_ajax_tap_dash_chat_send', ['TAP_Ajax_Dash', 'ajax_chat_send']);
        add_action('wp_ajax_tap_dash_chat_unread', ['TAP_Ajax_Dash', 'ajax_chat_unread']);

        // 301 redirect legacy /dashboard/ page to the unified /mi-cuenta/ back-office
        add_action('template_redirect', [__CLASS__, 'dashboard_redirect']);

        // Enqueue dashboard CSS on front-end dashboard pages
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
    }

    /* ── legacy dashboard redirect ────────────────────────────── */

    public static function dashboard_redirect() {
        if (is_page('dashboard') && !is_admin() && !empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            wp_safe_redirect(home_url('/mi-cuenta/'), 301);
            exit;
        }
    }

    /* ── assets ────────────────────────────────────────────────────── */

    public static function enqueue_assets() {
        // Enqueue on singular pages or when the dashboard shortcode is present
        $should_load = false;
        if (is_singular()) {
            $post = get_queried_object();
            if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'tap_front_dash')) {
                $should_load = true;
            }
        }
        // Also load when navigating dashboard sections (the query arg is set)
        if (!empty($_GET['seccion']) && is_user_logged_in()) {
            $should_load = true;
        }
        if (!$should_load) {
            return;
        }
        wp_enqueue_style(
            'tap-dashboard',
            TAP_PLUGIN_URL . 'assets/css/dashboard.css',
            ['tap-public'],
            TAP_VERSION
        );
        wp_enqueue_script(
            'tap-dashboard',
            TAP_PLUGIN_URL . 'assets/js/dashboard.js',
            ['jquery'],
            TAP_VERSION,
            true
        );
        wp_localize_script('tap-dashboard', 'tapDash', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tap_front_dash_nonce'),
            'i18n'     => [
                'confirm_cancel' => __('¿Estás seguro de cancelar esta reserva?', 'travel-agency-platform'),
                'confirm_delete' => __('¿Eliminar esta reseña?', 'travel-agency-platform'),
                'confirm_op'     => __('¿Realizar esta operación sobre la reserva?', 'travel-agency-platform'),
                'cancel_reason'  => __('Motivo de cancelación (opcional):', 'travel-agency-platform'),
                'confirm_payout' => __('Enviar la solicitud de liquidación al administrador?', 'travel-agency-platform'),
                'no_messages'    => __('Aún no hay mensajes en esta conversación.', 'travel-agency-platform'),
                'error'          => __('Ocurrió un error. Intenta de nuevo.', 'travel-agency-platform'),
                'saved'          => __('Guardado', 'travel-agency-platform'),
            ],
        ]);
    }

    /* ── main render ───────────────────────────────────────────────── */

    public static function render($atts) {
        if (!is_user_logged_in()) {
            return self::render_login_prompt();
        }

        $user  = wp_get_current_user();
        $roles = (array) $user->roles;
        $role  = current($roles) ?: 'subscriber';

        // Determine active section from query var
        $section = sanitize_key(wp_unslash($_GET['seccion'] ?? 'overview'));

        // Route by role
        if (in_array('tap_agency_admin', $roles, true) || in_array('tap_agency_employee', $roles, true)) {
            return self::render_agency_dash($user, $role, $section);
        }

        if (current_user_can('manage_options')) {
            return self::render_admin_redirect();
        }

        return TAP_Front_Dash_Client::render_client_dash($user, $section);
    }

    /* ── login prompt ──────────────────────────────────────────────── */

    private static function render_login_prompt() {
        ob_start(); ?>
        <div class="tap-dash-login">
            <div class="tap-dash-login-card">
                <div class="tap-dash-login-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <h2><?php esc_html_e('Inicia sesión para acceder a tu panel', 'travel-agency-platform'); ?></h2>
                <p><?php esc_html_e('Gestiona tus reservas, favoritos y perfil desde un solo lugar.', 'travel-agency-platform'); ?></p>
                <a href="<?php echo esc_url(wp_login_url(home_url('/mi-cuenta/'))); ?>" class="tap-btn tap-btn-primary tap-btn-lg">
                    <?php esc_html_e('Iniciar sesión', 'travel-agency-platform'); ?>
                </a>
                <p class="tap-dash-login-register">
                    <?php
                    printf(
                        /* translators: %s: enlace de registro */
                        esc_html__('¿No tienes cuenta? %s', 'travel-agency-platform'),
                        '<a href="' . esc_url(home_url('/register/')) . '">' . esc_html__('Regístrate', 'travel-agency-platform') . '</a>'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── admin redirect ────────────────────────────────────────────── */

    private static function render_admin_redirect() {
        ob_start();
        ?>
        <div class="tap-dash-admin-hint">
            <div class="tap-dash-login-card">
                <div class="tap-dash-login-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
                </div>
                <h2><?php esc_html_e('Panel de administración', 'travel-agency-platform'); ?></h2>
                <p><?php esc_html_e('Como administrador, gestiona la plataforma desde el panel de WordPress.', 'travel-agency-platform'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=travel-platform')); ?>" class="tap-btn tap-btn-primary tap-btn-lg">
                    <?php esc_html_e('Ir al panel admin', 'travel-agency-platform'); ?>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    /* ==================================================================
     *  AGENCY DASHBOARD
     * ================================================================== */

    private static function render_agency_dash($user, $role, $section) {
        // Agency admin/employee get the existing panel wrapped in a sidebar
        $sections = self::agency_nav($role, $section);

        ob_start();
        ?>
        <div class="tap-dash tap-dash-agency">
            <?php self::render_sidebar($user, $sections, $section); ?>
            <main class="tap-dash-main">
                <?php
                // Back-office section routing (Fase 16)
                switch ($section) {
                    case 'bo-operations':
                        echo self::agency_operations($user);
                        break;
                    case 'bo-finances':
                        echo self::agency_finances($user);
                        break;
                    case 'bo-listings':
                        echo TAP_Listing_Editor::agency_manage([]);
                        break;
                    default:
                        echo self::agency_overview($user);
                }
                ?>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: operations ─────────────────────────────── */

    private static function agency_operations($user) {
        global $wpdb;
        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $is_admin = !in_array('tap_agency_employee', (array) $user->roles, true);

        $filters = ['all', 'request', 'pending', 'confirmed', 'completed', 'cancelled', 'refunded'];
        $status  = sanitize_key(wp_unslash($_GET['bo_status'] ?? 'all'));
        if (!in_array($status, $filters, true)) {
            $status = 'all';
        }
        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d GROUP BY status",
            $agency
        ));
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->c;
        }
        $counts['all'] = array_sum($counts);

        $where  = $wpdb->prepare('WHERE agency_id = %d', $agency);
        if ('all' !== $status) {
            $where .= $wpdb->prepare(' AND status = %s', $status);
        }
        $bookings = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}tap_bookings {$where} ORDER BY created_at DESC LIMIT 40"
        );

        $today = gmdate('Y-m-d');
        $base  = home_url('/mi-cuenta/');
        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Operaciones de reservas', 'travel-agency-platform'); ?></h2>
            </div>
            <div class="tap-agency-tabs">
                <?php
                foreach ($filters as $fk):
                    $furl = add_query_arg(['seccion' => 'bo-operations', 'bo_status' => $fk, 'bo_page' => 1], $base);
                    ?>
                    <a class="tap-tab <?php echo $status === $fk ? 'active' : ''; ?>" href="<?php echo esc_url($furl); ?>">
                        <?php echo esc_html($fk === 'all' ? __('Todas', 'travel-agency-platform') : ucfirst($fk)); ?>
                        <span class="tap-tab-count"><?php echo (int) ($counts[$fk] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$bookings): ?>
                <p class="tap-empty"><?php esc_html_e('No hay reservas en este estado.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
            <div class="tap-table-scroll">
            <table class="tap-table tap-table-compact">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fechas', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Huéspedes', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Pago', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($bookings as $b):
                    $b_title = get_the_title($b->service_id);
                    $b_title = $b_title ?: __('Listado eliminado', 'travel-agency-platform');
                    ?>
                    <tr>
                        <td><a class="tap-booking-code" href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($b->booking_code))); ?>"><?php echo esc_html($b->booking_code); ?></a></td>
                        <td>
                        <?php
                        echo esc_html($b_title);
						$room = $b->room_id ? get_post($b->room_id) : null;
						if ($room):
                            ?>
                            <span class="tap-booking-room"><?php echo esc_html($room->post_title); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($b->check_in . ($b->check_out ? ' &rarr; ' . $b->check_out : '')); ?></td>
                        <td><?php echo esc_html($b->adults . ' ' . __('ad', 'travel-agency-platform') . ($b->children ? ', ' . $b->children . ' ' . __('niños', 'travel-agency-platform') : '')); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                        <td><?php $pay_cls = 'paid' === $b->payment_status ? 'confirmed' : ('refunded' === $b->payment_status ? 'cancelled' : 'pending'); ?><span class="tap-status tap-status-<?php echo $pay_cls; ?>"><?php echo esc_html(TAP_Emails_Send::payment_label($b->payment_status)); ?></span></td>
                        <td><span class="tap-status tap-status-<?php echo in_array($b->status, ['confirmed', 'completed'], true) ? 'confirmed' : 'pending'; ?>"><?php echo esc_html($b->status); ?></span><?php if (!empty($b->cancel_reason)): ?><br><span class="tap-cancel-reason"><?php echo esc_html($b->cancel_reason); ?></span><?php endif; ?></td>
                        <td>
                            <?php if (!$is_admin): ?>
                                <span class="tap-agency-meta"><?php esc_html_e('Solo lectura para empleados.', 'travel-agency-platform'); ?></span>
                            <?php else: ?>
								<?php if (in_array($b->status, ['request', 'pending'], true)): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="confirm" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Confirmar', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php if ('confirmed' === $b->status): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="complete" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Completar', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php if (user_can($user, 'manage_options') && in_array($b->status, ['confirmed', 'completed'], true) && in_array($b->payment_status, ['pending', 'partial', 'failed'], true)): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="mark_paid" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Marcar pagada', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php if (in_array($b->status, ['request', 'pending', 'confirmed'], true) && (!$b->check_in || $b->check_in >= $today)): ?>
                                <button class="tap-btn tap-btn-sm tap-btn-danger tap-bo-api" data-op="cancel" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php $b_unread = TAP_Chat::unread_for($b->id, 'agency'); ?>
                            <button class="tap-btn tap-btn-sm tap-chat-toggle" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Chat', 'travel-agency-platform'); ?><?php if ($b_unread): ?> <span class="tap-chat-badge"><?php echo (int) $b_unread; ?></span><?php endif; ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="tap-chat-row" data-id="<?php echo (int) $b->id; ?>" style="display:none;">
                        <td colspan="8">
                            <div class="tap-chat-wrap">
                                <div class="tap-chat-thread"><p class="tap-empty"><?php esc_html_e('Cargando conversación...', 'travel-agency-platform'); ?></p></div>
                                <form class="tap-dash-chat-form" data-booking-id="<?php echo (int) $b->id; ?>">
                                    <div class="tap-chat-input">
                                        <textarea name="message" rows="2" maxlength="2000" placeholder="<?php esc_attr_e('Responder al huésped...', 'travel-agency-platform'); ?>"></textarea>
                                        <button type="submit" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Enviar', 'travel-agency-platform'); ?></button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: finances ───────────────────────────────── */

    private static function agency_finances($user) {
        global $wpdb;
        if (in_array('tap_agency_employee', (array) $user->roles, true)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia gestiona las finanzas.', 'travel-agency-platform') . '</p>';
        }
        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN commission_status = 'owed' THEN commission_amount END),0) owed,
                COALESCE(SUM(CASE WHEN commission_status = 'disputed' THEN commission_amount END),0) disputed,
                COALESCE(SUM(CASE WHEN commission_status = 'paid' THEN commission_amount END),0) settled
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d
               AND commission_amount > 0
               AND payment_status = 'paid'
               AND status NOT IN ('cancelled', 'refunded')",
            $agency
        ));
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments
             WHERE agency_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
            $agency
        ));
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments
             WHERE agency_id = %d ORDER BY created_at DESC LIMIT 15",
            $agency
        ));
        $failed_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_commission_payments
             WHERE agency_id = %d AND status = 'failed'",
            $agency
        ));

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Finanzas de la agencia', 'travel-agency-platform'); ?></h2>
            </div>
            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($totals->owed ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b91c1c;"><?php echo esc_html(TAP_Currency::fmt($totals->disputed ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($totals->settled ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
            </div>

            <?php if ($pending): ?>
                <div class="tap-notice">
                    <span class="tap-notice-icon" aria-hidden="true">⚠</span>
                    <span><?php echo esc_html(sprintf(/* translators: 1: importe de la liquidación solicitada, 2: identificador de la liquidación */ __('Tienes una solicitud de liquidación de %s pendiente de confirmación por el administrador (#%d).', 'travel-agency-platform'), TAP_Currency::fmt($pending->amount), (int) $pending->id)); ?></span>
                </div>
            <?php elseif ($failed_count > 0): ?>
                <div class="tap-notice tap-notice-error">
                    <span class="tap-notice-icon" aria-hidden="true">⚠</span>
                    <span><?php echo esc_html(sprintf(/* translators: %d: número de liquidaciones fallidas */ __('Tienes %d liquidación(es) fallida(s): el pago no llegó a completarse. Solicita de nuevo una liquidación o contacta al administrador.', 'travel-agency-platform'), $failed_count)); ?></span>
                </div>
            <?php elseif (isset($totals->owed) && (float) $totals->owed > 0): ?>
                <div class="tap-dash-card" style="margin-top:16px;">
                    <h3><?php esc_html_e('Solicitar liquidación', 'travel-agency-platform'); ?></h3>
                    <p class="tap-agency-meta"><?php esc_html_e('Tu comisión por cobrar queda agrupada en una sola solicitud que el administrador confirma antes de transferirte.', 'travel-agency-platform'); ?></p>
                    <form id="tap-bo-payout" class="tap-bo-form">
                        <label><?php esc_html_e('Método de cobro', 'travel-agency-platform'); ?>
                            <select name="method">
                                <option value="bank_transfer"><?php esc_html_e('Transferencia bancaria', 'travel-agency-platform'); ?></option>
                                <option value="paypal">PayPal</option>
                                <option value="cheque"><?php esc_html_e('Cheque', 'travel-agency-platform'); ?></option>
                                <option value="cash"><?php esc_html_e('Efectivo', 'travel-agency-platform'); ?></option>
                            </select>
                        </label>
                        <label><?php esc_html_e('Nota (opcional)', 'travel-agency-platform'); ?>
                            <textarea name="note" rows="2" placeholder="Referencia para el administrador"></textarea>
                        </label>
                        <button type="submit" class="tap-btn tap-btn-primary"><?php echo esc_html(sprintf(/* translators: %s: importe solicitado */ __('Solicitar %s', 'travel-agency-platform'), TAP_Currency::fmt($totals->owed))); ?></button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="tap-dash-section-header" style="margin-top:24px;">
                <h3><?php esc_html_e('Historial de liquidaciones', 'travel-agency-platform'); ?></h3>
            </div>
            <?php if (!$history): ?>
                <p class="tap-agency-meta"><?php esc_html_e('Aún no hay liquidaciones registradas.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
            <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                <thead><tr>
                    <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Origen', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td>#<?php echo (int) $h->id; ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($h->amount)); ?></td>
                        <td><?php echo esc_html($h->method); ?></td>
                        <td><?php echo 'agency' === ($h->source ?? 'admin') ? esc_html__('Sol. agencia', 'travel-agency-platform') : esc_html__('Admin', 'travel-agency-platform'); ?></td>
                        <td><?php echo esc_html($h->note); ?></td>
                        <td><span class="tap-status <?php echo 'completed' === $h->status ? 'tap-status-confirmed' : ('failed' === $h->status ? 'tap-status-cancelled' : 'tap-status-pending'); ?>"><?php echo esc_html(TAP_Payouts::status_label($h->status)); ?></span></td>
                        <td><?php echo esc_html($h->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: overview (resumen) ─────────────────────── */

    private static function agency_overview($user) {
        global $wpdb;
        $is_admin  = !in_array('tap_agency_employee', (array) $user->roles, true);
        $agency_id = TAP_Booking::get_agency_for_user($user->ID);
        $agency    = $agency_id ? get_post($agency_id) : null;
        if (!$agency_id && !current_user_can('manage_options')) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }

        $agency_name = $agency ? $agency->post_title : __('Tu agencia', 'travel-agency-platform');
        $verified    = $agency_id ? get_post_meta($agency_id, '_tap_agency_verified', true) : false;
        $commission  = $agency_id ? TAP_Booking::get_agency_commission($agency_id) : 10;
        $stats       = TAP_Booking_Stats::get_booking_stats($agency_id);

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

        $recent = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d
             ORDER BY created_at DESC LIMIT 8",
            $agency_id
        )) : [];

        $counts = [];
        if ($agency_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) c FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d GROUP BY status",
                $agency_id
            ));
            foreach ($rows as $r) {
                $counts[$r->status] = (int) $r->c;
            }
        }

        $listing_labels = [
            'tap_accommodation' => __('Alojamientos', 'travel-agency-platform'),
            'tap_tour'          => __('Tours', 'travel-agency-platform'),
            'tap_transport'     => __('Transportes', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de carros', 'travel-agency-platform'),
            'tap_boat'          => __('Barcos', 'travel-agency-platform'),
            'tap_package'       => __('Paquetes', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipo', 'travel-agency-platform'),
        ];
        $listings = [];
        foreach ($listing_labels as $type => $label) {
            $ids = get_posts([
                'post_type'      => $type,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_key'       => TAP_Post_Types::agency_meta_key($type),
                'meta_value'     => $agency_id,
                'fields'         => 'ids',
            ]);
            if ($ids) {
                $listings[$type] = ['label' => $label, 'count' => count($ids)];
            }
        }

        $base = home_url('/mi-cuenta/');
        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-agency-header">
                <div>
                    <h2><?php echo esc_html($agency_name); ?>
                        <?php if ($verified): ?>
                            <span class="tap-verified-badge" title="<?php esc_attr_e('Agencia verificada', 'travel-agency-platform'); ?>">&#10003; <?php esc_html_e('Verificada', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="tap-agency-meta"><?php esc_html_e('Comisión', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></p>
                </div>
                <div class="tap-agency-header-actions">
                    <?php if ($agency_id): ?>
                        <a href="<?php echo esc_url(home_url('/agency-profile/?id=' . (int) $agency_id)); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Ver perfil público', 'travel-agency-platform'); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg('seccion', 'bo-listings', $base)); ?>" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Gestionar listados', 'travel-agency-platform'); ?></a>
                </div>
            </div>

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
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['total']); ?></span><span class="tap-stat-label"><?php esc_html_e('Reservas totales', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html($stats['request'] ?? 0); ?></span><span class="tap-stat-label"><?php esc_html_e('Solicitudes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['pending']); ?></span><span class="tap-stat-label"><?php esc_html_e('Pendientes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['confirmed']); ?></span><span class="tap-stat-label"><?php esc_html_e('Confirmadas', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format((float) $stats['completed'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Completadas', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Ingresos', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisiones', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($stats['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Neto para ti', 'travel-agency-platform'); ?></span></div>
                <?php if ($is_admin): ?>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->owed)); ?></span><span class="tap-stat-label"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b91c1c;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->disputed)); ?></span><span class="tap-stat-label"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->settled)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($agency_id && !empty($listings)): ?>
            <div class="tap-panel-section" style="margin-top:24px;">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Tus listados', 'travel-agency-platform'); ?></h3>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(add_query_arg('seccion', 'bo-listings', $base)); ?>"><?php esc_html_e('Ver todos', 'travel-agency-platform'); ?></a>
                </div>
                <div class="tap-stats-grid">
                    <?php foreach ($listings as $ltype => $linfo): ?>
                        <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $linfo['count']; ?></span><span class="tap-stat-label"><?php echo esc_html($linfo['label']); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="tap-panel-section" style="margin-top:24px;">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Reservas recientes', 'travel-agency-platform'); ?></h3>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(add_query_arg('seccion', 'bo-operations', $base)); ?>"><?php esc_html_e('Ver operaciones', 'travel-agency-platform'); ?></a>
                </div>
                <?php if (!$recent): ?>
                    <p class="tap-agency-meta"><?php echo esc_html(sprintf(/* translators: %s: porcentaje de comisión de la agencia */ __('Aún no tienes reservas. Comparte tus listados para empezar a recibir solicitudes. Comisión: %s%%', 'travel-agency-platform'), esc_html($commission))); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                    <thead><tr>
                        <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fechas', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ($recent as $b):
                        $b_title = get_the_title($b->service_id);
                        $b_title = $b_title ?: __('Listado eliminado', 'travel-agency-platform');
                        ?>
                        <tr>
                            <td><a class="tap-booking-code" href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($b->booking_code))); ?>"><?php echo esc_html($b->booking_code); ?></a></td>
                            <td><?php echo esc_html($b_title); ?></td>
                            <td><?php echo esc_html($b->check_in . ($b->check_out ? ' &rarr; ' . $b->check_out : '')); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                            <td><span class="tap-status tap-status-<?php echo in_array($b->status, ['confirmed', 'completed'], true) ? 'confirmed' : 'pending'; ?>"><?php echo esc_html(TAP_Emails_Send::status_label($b->status)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>

            <?php if ($is_admin && $agency_id && class_exists('TAP_Leads')): ?>
            <div class="tap-panel-section" style="margin-top:24px;">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Leads de contacto', 'travel-agency-platform'); ?> <span class="tap-tab-count"><?php echo (int) TAP_Leads::count_for_agency($agency_id); ?></span></h3>
                    <?php if (TAP_Leads::count_for_agency($agency_id) > 0): ?>
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php') . '?action=tap_export_leads&agency=' . (int) $agency_id, 'tap_export_leads_' . $user->ID)); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
                    <?php endif; ?>
                </div>
                <?php
                $leads = TAP_Leads::for_agency($agency_id, 10);
                if (!$leads):
					?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no has recibido mensajes de contacto. Cuando un visitante envíe el formulario de tu perfil o de un servicio, aparecerá aquí.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                    <thead><tr>
                        <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ($leads as $ld):
                        $ld_service = $ld->service_id ? get_the_title($ld->service_id) : '';
                        $ld_open    = class_exists('TAP_Attribution') && TAP_Attribution::contact_unlocked($agency_id, $ld->email);
                        $ld_email   = $ld_open ? $ld->email : (class_exists('TAP_Attribution') ? TAP_Attribution::mask_email($ld->email) : $ld->email);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($ld_open ? $ld->name : (class_exists('TAP_Attribution') ? TAP_Attribution::mask_name($ld->name) : $ld->name)); ?></strong></td>
                            <td><?php echo esc_html($ld_email); ?></td>
                            <td><?php echo esc_html($ld_service); ?></td>
                            <td><?php echo esc_html($ld->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function agency_nav($role, $active) {
        $base = home_url('/mi-cuenta/');
        $agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $unread = ($agency && 'tap_agency_employee' !== $role) ? TAP_Chat::unread_total_for_agency($agency) : 0;
        $nav = [
            ['key' => 'overview', 'label' => __('Resumen', 'travel-agency-platform'), 'icon' => 'home', 'url' => $base],
            ['key' => 'bo-operations', 'label' => __('Operaciones', 'travel-agency-platform'), 'icon' => 'calendar', 'url' => add_query_arg('seccion', 'bo-operations', $base), 'badge' => $unread],
            ['key' => 'bo-finances', 'label' => __('Finanzas', 'travel-agency-platform'), 'icon' => 'wallet', 'url' => add_query_arg('seccion', 'bo-finances', $base)],
            ['key' => 'bo-listings', 'label' => __('Listados', 'travel-agency-platform'), 'icon' => 'grid', 'url' => add_query_arg('seccion', 'bo-listings', $base)],
        ];
        // Employees see fewer options (resumen + operaciones; sin finanzas).
        if ($role === 'tap_agency_employee') {
            return array_slice($nav, 0, 2);
        }
        return $nav;
    }

    /* ==================================================================
     *  SIDEBAR
     * ================================================================== */

    /**
     * Shared sidebar shell for both dashboards.
     *
     * @internal Called by TAP_Front_Dash_Client as well; public only so the
     *           client renderer can reuse it. Not part of the plugin API.
     */
    public static function render_sidebar($user, $sections, $active_section) {
        $role_labels = [
            'tap_agency_admin'   => __('Agencia', 'travel-agency-platform'),
            'tap_agency_employee'=> __('Empleado', 'travel-agency-platform'),
            'tap_client'         => __('Viajero', 'travel-agency-platform'),
            'subscriber'         => __('Viajero', 'travel-agency-platform'),
        ];
        $role = $user->roles[0] ?? 'subscriber';
        ?>
        <aside class="tap-dash-sidebar">
            <div class="tap-dash-sidebar-header">
                <?php echo get_avatar($user->ID, 48); ?>
                <div class="tap-dash-sidebar-user">
                    <strong><?php echo esc_html($user->display_name); ?></strong>
                    <span class="tap-dash-sidebar-role"><?php echo esc_html($role_labels[$role] ?? __('Usuario', 'travel-agency-platform')); ?></span>
                </div>
            </div>
            <nav class="tap-dash-sidebar-nav">
                <?php
                foreach ($sections as $s) :
                    $is_active = ($s['key'] === $active_section);
                    ?>
                    <a href="<?php echo esc_url($s['url']); ?>" class="tap-dash-nav-item <?php echo $is_active ? 'tap-dash-nav-active' : ''; ?>">
                        <?php self::render_nav_icon($s['icon']); ?>
                        <span><?php echo esc_html($s['label']); ?></span>
                        <?php if (isset($s['badge'])): ?>
                            <span class="tap-chat-global-badge" data-tap-chat-nav<?php echo ((int) $s['badge'] > 0) ? '' : ' hidden'; ?>><?php echo (int) $s['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="tap-dash-sidebar-footer">
                <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="tap-dash-nav-item tap-dash-nav-logout">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span><?php esc_html_e('Cerrar sesión', 'travel-agency-platform'); ?></span>
                </a>
            </div>
        </aside>
        <?php
    }

    /** @internal SVG icon renderer used by render_sidebar only. */
    public static function render_nav_icon($icon) {
        $icons = [
            'home'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'calendar' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'heart'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
            'star'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'building' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="6" x2="9" y2="6.01"/><line x1="15" y1="6" x2="15" y2="6.01"/><line x1="9" y1="10" x2="9" y2="10.01"/><line x1="15" y1="10" x2="15" y2="10.01"/><line x1="9" y1="14" x2="9" y2="14.01"/><line x1="15" y1="14" x2="15" y2="14.01"/><line x1="9" y1="18" x2="15" y2="18"/></svg>',
            'settings' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'wallet'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="14" rx="2" ry="2"/><path d="M2 10h6a2 2 0 0 1 2 2 2 2 0 0 1-2 2H2"/><circle cx="17" cy="14" r="1"/></svg>',
            'grid'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        ];
        echo $icons[$icon] ?? '';
    }

    /* ==================================================================
     *  AJAX HANDLERS (with nonce + capability checks)
     * ================================================================== */

    /* ── agency back-office AJAX: booking ops + payout request ───────── */

    /** Agency opens the guest chat thread for a booking. */
    /** Agency polls the global unread counter for the live nav badge. */
    /** Agency replies on the guest chat thread. */
}
