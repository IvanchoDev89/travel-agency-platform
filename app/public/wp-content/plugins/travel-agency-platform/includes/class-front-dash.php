<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders
// phpcs:disable WordPress.WP.EnqueuedResources
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
        add_action('wp_ajax_tap_dash_client_profile_save', ['TAP_Ajax_Dash', 'ajax_client_profile_save']);
        add_action('wp_ajax_tap_dash_delete_review', ['TAP_Ajax_Dash', 'ajax_delete_review']);
        add_action('admin_post_tap_agency_review_reply', [__CLASS__, 'handle_review_reply']);

        // AJAX handlers (agency back-office, Fase 16)
        add_action('wp_ajax_tap_dash_agency_booking', ['TAP_Ajax_Dash', 'ajax_agency_booking']);
        add_action('wp_ajax_tap_dash_agency_profile_save', ['TAP_Ajax_Dash', 'ajax_agency_profile_save']);
        add_action('wp_ajax_tap_dash_agency_bulk_booking', ['TAP_Ajax_Dash', 'ajax_agency_bulk_booking']);
        add_action('wp_ajax_tap_dash_chat_thread', ['TAP_Ajax_Dash', 'ajax_chat_thread']);
        add_action('wp_ajax_tap_dash_chat_send', ['TAP_Ajax_Dash', 'ajax_chat_send']);
        add_action('wp_ajax_tap_dash_client_chat_thread', ['TAP_Ajax_Dash', 'ajax_client_chat_thread']);
        add_action('wp_ajax_tap_dash_client_chat_send', ['TAP_Ajax_Dash', 'ajax_client_chat_send']);
        add_action('wp_ajax_tap_calendar_download', ['TAP_Ajax_Dash', 'ajax_calendar_download']);
        add_action('wp_ajax_nopriv_tap_calendar_download', ['TAP_Ajax_Dash', 'ajax_calendar_download']);
        add_action('wp_ajax_tap_dash_chat_unread', ['TAP_Ajax_Dash', 'ajax_chat_unread']);
        add_action('wp_ajax_tap_dash_team_add', ['TAP_Ajax_Dash', 'ajax_team_add']);
        add_action('wp_ajax_tap_dash_team_remove', ['TAP_Ajax_Dash', 'ajax_team_remove']);

        // 301 redirect legacy /dashboard/ page to the unified /mi-cuenta/ back-office
        add_action('template_redirect', [__CLASS__, 'dashboard_redirect']);
        add_action('admin_init', [__CLASS__, 'restrict_agency_admin_access']);
        add_filter('show_admin_bar', [__CLASS__, 'maybe_hide_admin_bar']);

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

    /**
     * Keep agency users out of wp-admin: everything they need lives in the
     * front-end panel (/mi-cuenta/). AJAX and REST requests are exempt so the
     * uploader, chat and bulk actions keep working.
     */
    public static function restrict_agency_admin_access() {
        if (wp_doing_ajax()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (!is_user_logged_in()) return;
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        $is_agency = in_array('tap_agency_admin', $roles, true) || in_array('tap_agency_employee', $roles, true);
        if (!$is_agency) return;
        if (current_user_can('manage_options')) return; // platform admins keep access

        // Allow admin-post.php (CSV exports, etc.) to run, then redirect.
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME']))) : '';
        if (in_array($script, ['admin-post.php', 'admin-ajax.php'], true)) return;

        wp_safe_redirect(home_url('/mi-cuenta/'));
        exit;
    }

    public static function maybe_hide_admin_bar($show) {
        if (!is_user_logged_in()) return $show;
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        $is_agency = in_array('tap_agency_admin', $roles, true) || in_array('tap_agency_employee', $roles, true);
        if ($is_agency && !current_user_can('manage_options')) {
            return false;
        }
        return $show;
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
                'no_messages'    => __('Aún no hay mensajes en esta conversación.', 'travel-agency-platform'),
                'error'          => __('Ocurrió un error. Intenta de nuevo.', 'travel-agency-platform'),
                'saved'          => __('Guardado', 'travel-agency-platform'),
                'confirm_team_remove' => __('¿Quitar a este usuario del equipo?', 'travel-agency-platform'),
                'confirm_title'  => __('Confirma la acción', 'travel-agency-platform'),
                'prompt_title'   => __('Indica los datos', 'travel-agency-platform'),
                'confirm_yes'    => __('Confirmar', 'travel-agency-platform'),
                'confirm_no'     => __('Cancelar', 'travel-agency-platform'),
                'reject_reason'  => __('Motivo del rechazo (se envía al viajero):', 'travel-agency-platform'),
                'offline_receipt'=> __('Comprobante de la liquidación offline (obligatorio):', 'travel-agency-platform'),
                'bulk_title'     => __('Aplicar acción masiva', 'travel-agency-platform'),
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
        // Canonical role for routing/nav: agency roles win over the user's
        // original role (team members keep e.g. tap_client plus the employee
        // role added by team_add).
        $role = 'subscriber';
        if (in_array('tap_agency_admin', $roles, true)) {
            $role = 'tap_agency_admin';
        } elseif (in_array('tap_agency_employee', $roles, true)) {
            $role = 'tap_agency_employee';
        } elseif (in_array('tap_client', $roles, true)) {
            $role = 'tap_client';
        } elseif (current($roles)) {
            $role = current($roles);
        }

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
        // Agency-role users must resolve to an agency (owner or staff meta);
        // unmapped employees get a friendly dead-end instead of scattered errors.
        if (!TAP_Booking::get_agency_for_user($user->ID)) {
            $user_roles  = (array) $user->roles;
            $is_employee = in_array('tap_agency_employee', $user_roles, true) && !in_array('tap_agency_admin', $user_roles, true);
            $message = $is_employee
                ? __('Tu cuenta de empleado aún no está vinculada a una agencia. Pide al administrador que te agregue desde la sección Equipo de su panel.', 'travel-agency-platform')
                : __('Tu cuenta aún no está vinculada a una agencia. Si registraste una agencia, pide al administrador que confirme la vinculación; si aún no la creaste, puedes registrarla de nuevo.', 'travel-agency-platform');
            ob_start();
            ?>
            <div class="tap-dash tap-dash-agency">
                <div class="tap-dash-login-card tap-login-card-centered">
                    <h2><?php esc_html_e('Cuenta sin agencia vinculada', 'travel-agency-platform'); ?></h2>
                    <p><?php echo esc_html($message); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // Agency admin/employee get the existing panel wrapped in a sidebar
        $sections = self::agency_nav($role, $section);

        ob_start();
        ?>
        <div class="tap-dash tap-dash-agency" id="tap-dash-content">
            <?php self::render_sidebar($user, $sections, $section); ?>
            <main class="tap-dash-main">
                <?php
                // Approval status banner (C20): same notice the legacy panel
                // shows, on every section. Without it a freshly registered
                // agency has no idea /mi-cuenta/ is still waiting on review.
                $tap_ap_agency = (int) TAP_Booking::get_agency_for_user($user->ID);
                if ($tap_ap_agency && !current_user_can('manage_options')) {
                    $tap_ap_status = TAP_Approval::status($tap_ap_agency);
                    if ('pending' === $tap_ap_status) {
                        ?>
                        <div class="tap-notice"><span class="tap-notice-icon" aria-hidden="true">⚠</span><span><strong><?php esc_html_e('Tu agencia está en revisión', 'travel-agency-platform'); ?>.</strong> <?php esc_html_e('Puedes preparar tus listados, pero serán visibles para los viajeros solo cuando el administrador apruebe tu agencia.', 'travel-agency-platform'); ?></span></div>
                        <?php
                    } elseif ('rejected' === $tap_ap_status) {
                        ?>
                        <?php $tap_reject_reason = (string) get_post_meta($tap_ap_agency, 'tap_kyc_rejection_reason', true); ?>
                        <div class="tap-notice tap-notice-error"><span class="tap-notice-icon" aria-hidden="true">⚠</span><span><strong><?php esc_html_e('Tu agencia no fue aprobada', 'travel-agency-platform'); ?>.</strong>
                            <?php if ('' !== $tap_reject_reason): ?>
                                <?php echo esc_html(sprintf(__('Motivo: %s', 'travel-agency-platform'), $tap_reject_reason)); ?>
                                <?php esc_html_e('Corrige los documentos en tu perfil y vuelve a enviarlos para nueva revisión.', 'travel-agency-platform'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Contacta a soporte para corregir tus datos y volver a solicitar la revisión.', 'travel-agency-platform'); ?>
                            <?php endif; ?>
                        </span></div>
                        <?php
                    }
                }
                // Back-office section routing (Fase 16)
                switch ($section) {
                    case 'bo-operations':
                        echo self::agency_operations($user);
                        break;
                    case 'bo-calendar':
                        echo self::agency_calendar($user);
                        break;
                    case 'bo-invoices':
                        echo self::agency_invoices($user);
                        break;
                    case 'bo-reviews':
                        echo self::agency_reviews($user);
                        break;
                    case 'bo-finances':
                        echo self::agency_finances($user);
                        break;
                    case 'bo-analytics':
                        echo self::agency_analytics($user);
                        break;
                    case 'bo-audit':
                        echo self::agency_audit($user);
                        break;
                    case 'bo-disputas':
                        echo self::agency_disputes($user);
                        break;
                    case 'bo-listings':
                        echo TAP_Listing_Editor::agency_manage([]);
                        break;
                    case 'bo-profile':
                        echo self::agency_profile($user);
                        break;
                    case 'bo-promos':
                        echo self::agency_promos($user);
                        break;
                    case 'bo-referrals':
                        echo self::agency_referrals($user);
                        break;
                    case 'loyalty':
                        echo self::agency_personal_loyalty($user);
                        break;
                    case 'bo-team':
                        echo self::agency_team($user);
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

    /**
     * Prime post caches.
     *
     * @internal Prime post caches so get_the_title()/get_post() inside a loop
     *           does not query per row. Shared with TAP_Front_Dash_Client.
     */
    public static function prime_titles($ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if ($ids) {
            _prime_post_caches($ids, false, false);
        }
    }

    /* ── agency back-office: operations ─────────────────────────────── */

    private static function agency_operations($user) {
        global $wpdb;
        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $is_admin = TAP_Roles::can_manage_agency_panel($user);

        $filters = ['all', 'request', 'pending', 'confirmed', 'completed', 'cancelled', 'refunded'];
        $status  = sanitize_key(wp_unslash($_GET['bo_status'] ?? 'all'));
        if (!in_array($status, $filters, true)) {
            $status = 'all';
        }
        $search = sanitize_text_field(wp_unslash($_GET['bo_q'] ?? ''));
        $page   = max(1, (int) wp_unslash($_GET['bo_page'] ?? 1));
        $per_page = 20;
        $date_from = sanitize_text_field(wp_unslash($_GET['bo_from'] ?? ''));
        $date_to   = sanitize_text_field(wp_unslash($_GET['bo_to'] ?? ''));
        if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = ''; }
        if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) { $date_to = ''; }

        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d GROUP BY status",
            $agency
        ));
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->c;
        }
        $counts['all'] = array_sum($counts);

        // Search: booking code, guest name/email, or service title.
        $search_svc_ids = [];
        if ('' !== $search) {
            $matching = get_posts([
                'post_type'      => 'any',
                'post_status'    => 'any',
                's'              => $search,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            $search_svc_ids = array_map('intval', (array) $matching);
        }

        $where  = 'WHERE agency_id = %d';
        $params = [$agency];
        if ('all' !== $status) {
            $where .= $wpdb->prepare(' AND status = %s', $status);
            $params[] = $status;
        }
        if ($date_from) {
            $where .= ' AND check_in >= %s';
            $params[] = $date_from;
        }
        if ($date_to) {
            $where .= ' AND check_in <= %s';
            $params[] = $date_to;
        }
        if ('' !== $search) {
            $clauses = [];
            $clauses[] = 'booking_code LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $clauses[] = 'guest_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $clauses[] = 'guest_email LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            if ($search_svc_ids) {
                $placeholders = implode(',', array_fill(0, count($search_svc_ids), '%d'));
                $clauses[] = "service_id IN ({$placeholders})";
                foreach ($search_svc_ids as $sid) {
                    $params[] = $sid;
                }
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings {$where}",
            $params
        ));
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        ));
        self::prime_titles(array_map(static fn($b) => (int) $b->service_id, $bookings));
        // Prime the room posts and batch the unread chat counts so the row loop
        // below issues no per-booking queries.
        $room_ids = array_filter(array_map(static fn($b) => (int) $b->room_id, $bookings));
        if ($room_ids) {
            get_posts(['post__in' => $room_ids, 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1]);
        }
        $unread_by_booking = TAP_Chat::unread_counts_for_bookings(array_map(static fn($b) => (int) $b->id, $bookings), 'agency');

        $today = current_time('Y-m-d');
        $base  = home_url('/mi-cuenta/');
        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Operaciones de reservas', 'travel-agency-platform'); ?></h2>
                <?php
                $agency_id = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
                if ($agency_id) :
					?>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php') . '?action=tap_export_bookings&agency=' . $agency_id, 'tap_export_bookings_' . get_current_user_id())); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </div>
            <div class="tap-agency-tabs">
                <?php
                foreach ($filters as $fk):
                    $furl = add_query_arg(['seccion' => 'bo-operations', 'bo_status' => $fk], $base);
                    ?>
                    <a class="tap-tab <?php echo $status === $fk ? 'active' : ''; ?>" href="<?php echo esc_url($furl); ?>">
                        <?php echo esc_html('all' === $fk ? __('Todas', 'travel-agency-platform') : TAP_Emails_Send::status_label($fk)); ?>
                        <span class="tap-tab-count"><?php echo (int) ($counts[$fk] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" class="tap-bo-search">
                <input type="hidden" name="seccion" value="bo-operations">
                <input type="hidden" name="bo_status" value="<?php echo esc_attr($status); ?>">
                <input type="date" name="bo_from" value="<?php echo esc_attr($date_from); ?>" title="<?php esc_attr_e('Desde', 'travel-agency-platform'); ?>">
                <input type="date" name="bo_to" value="<?php echo esc_attr($date_to); ?>" title="<?php esc_attr_e('Hasta', 'travel-agency-platform'); ?>">
                <input type="search" name="bo_q" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Buscar por código, huésped o servicio…', 'travel-agency-platform'); ?>">
                <button type="submit" class="tap-btn tap-btn-sm"><?php esc_html_e('Buscar', 'travel-agency-platform'); ?></button>
                <?php if ('' !== $search): ?>
                    <a class="tap-btn tap-btn-sm tap-btn-outline" href="<?php echo esc_url(add_query_arg(['seccion'=>'bo-operations','bo_status'=>$status], home_url('/mi-cuenta/'))); ?>"><?php esc_html_e('Limpiar', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
                <span class="tap-bo-count"><?php /* translators: %d: number of results */ echo esc_html(sprintf(__(' %d resultados', 'travel-agency-platform'), $total)); ?></span>
            </form>

            <div class="tap-bulk-bar">
                <label class="tap-bulk-label">
                    <input type="checkbox" id="tap-bulk-select-all"> <?php esc_html_e('Seleccionar todo', 'travel-agency-platform'); ?>
                </label>
                <select id="tap-bulk-op" class="tap-input">
                    <option value=""><?php esc_html_e('Acción masiva…', 'travel-agency-platform'); ?></option>
                    <option value="confirm"><?php esc_html_e('Confirmar', 'travel-agency-platform'); ?></option>
                    <option value="complete"><?php esc_html_e('Completar', 'travel-agency-platform'); ?></option>
                    <option value="cancel"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></option>
                </select>
                <button type="button" id="tap-bulk-apply" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Aplicar', 'travel-agency-platform'); ?></button>
                <span id="tap-bulk-msg" class="tap-bulk-msg" role="status" aria-live="polite"></span>
            </div>

            <?php if (!$bookings): ?>
                <?php
                echo TAP_Dashboard_UI::empty_state(
                    'all' === $status ? __('No hay reservas todavía.', 'travel-agency-platform') : __('No hay reservas en este estado.', 'travel-agency-platform'),
                    __('Cuando publiques tus servicios y recibas solicitudes, aparecerán aquí.', 'travel-agency-platform'),
                    add_query_arg('seccion', 'bo-listings', $base),
                    __('Gestionar listados', 'travel-agency-platform')
                );
                ?>
            <?php else: ?>
            <div class="tap-table-scroll">
            <table class="tap-table tap-table-compact">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="tap-bulk-cb-all" aria-label="<?php esc_attr_e('Seleccionar', 'travel-agency-platform'); ?>"></th>
                        <th><?php esc_html_e('Código', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Huésped', 'travel-agency-platform'); ?></th>
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
                        <td><input type="checkbox" class="tap-bulk-cb" value="<?php echo (int) $b->id; ?>" aria-label="<?php esc_attr_e('Seleccionar reserva', 'travel-agency-platform'); ?>"></td>
                        <td><a class="tap-booking-code" href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($b->booking_code))); ?>"><?php echo esc_html($b->booking_code); ?></a>
                            <br><a class="tap-booking-voucher" href="<?php echo esc_url(home_url('/booking-detail/?code=' . rawurlencode($b->booking_code))); ?>"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></a></td>
                        <td>
                        <?php
                        echo esc_html($b_title);
						$room = $b->room_id ? get_post($b->room_id) : null;
						if ($room):
                            ?>
                            <span class="tap-booking-room"><?php echo esc_html($room->post_title); ?></span><?php endif; ?></td>
                        <td>
                            <?php echo esc_html($b->guest_name ?: __('Invitado', 'travel-agency-platform')); ?>
                            <?php if ($b->guest_email): ?><br><small><?php echo esc_html($b->guest_email); ?></small><?php endif; ?>
                            <?php if ($b->guest_phone): ?><br><small><?php echo esc_html($b->guest_phone); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html($b->check_in . ($b->check_out ? ' &rarr; ' . $b->check_out : '')); ?></td>
                        <td><?php echo esc_html($b->adults . ' ' . __('Adults', 'travel-agency-platform') . ($b->children ? ', ' . $b->children . ' ' . __('niños', 'travel-agency-platform') : '')); ?></td>
                        <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                        <td><?php $pay_cls = 'paid' === $b->payment_status ? 'confirmed' : ('refunded' === $b->payment_status ? 'cancelled' : 'pending'); ?><span class="tap-status tap-status-<?php echo $pay_cls; ?>"><?php echo esc_html(TAP_Emails_Send::payment_label($b->payment_status)); ?></span></td>
                        <td><span class="tap-status tap-status-<?php echo in_array($b->status, ['confirmed', 'completed'], true) ? 'confirmed' : 'pending'; ?>"><?php echo esc_html(TAP_Emails_Send::status_label($b->status)); ?></span><?php if (!empty($b->cancel_reason)): ?><br><span class="tap-cancel-reason"><?php echo esc_html($b->cancel_reason); ?></span><?php endif; ?></td>
                        <td>
								<?php if (in_array($b->status, ['request', 'pending'], true)): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="confirm" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Confirmar', 'travel-agency-platform'); ?></button>
                                <?php if ('request' === $b->status): ?>
                                    <button class="tap-btn tap-btn-sm tap-btn-danger tap-bo-api" data-op="reject" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Rechazar solicitud', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                            <?php endif; ?>
								<?php if ('confirmed' === $b->status): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="complete" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Completar', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php if ($is_admin && user_can($user, 'manage_options') && in_array($b->status, ['confirmed', 'completed'], true) && in_array($b->payment_status, ['pending', 'partial', 'failed'], true)): ?>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="mark_paid" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Marcar pagada', 'travel-agency-platform'); ?></button>
                                <button class="tap-btn tap-btn-sm tap-bo-api" data-op="settle_offline" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Liquidar resto offline', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php if (in_array($b->status, ['request', 'pending', 'confirmed'], true) && (!$b->check_in || $b->check_in >= $today)): ?>
                                <button class="tap-btn tap-btn-sm tap-btn-danger tap-bo-api" data-op="cancel" data-name="<?php echo esc_attr($b->booking_code); ?>" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                            <?php endif; ?>
								<?php $b_unread = (int) ($unread_by_booking[(int) $b->id] ?? 0); ?>
                            <button class="tap-btn tap-btn-sm tap-chat-toggle" data-id="<?php echo (int) $b->id; ?>"><?php esc_html_e('Chat', 'travel-agency-platform'); ?><?php if ($b_unread): ?> <span class="tap-chat-badge"><?php echo (int) $b_unread; ?></span><?php endif; ?></button>
                        </td>
                    </tr>
                    <tr class="tap-chat-row" data-id="<?php echo (int) $b->id; ?>" style="display:none;">
                        <td colspan="10">
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
            <script>
            (function(){
                var all = document.getElementById('tap-bulk-select-all');
                var cbs = function(){ return Array.prototype.slice.call(document.querySelectorAll('.tap-bulk-cb')); };
                if (all) {
                    all.addEventListener('change', function(){
                        cbs().forEach(function(cb){ cb.checked = all.checked; });
                        var h = document.querySelector('.tap-bulk-cb-all');
                        if (h) h.checked = all.checked;
                    });
                }
                var hall = document.querySelector('.tap-bulk-cb-all');
                if (hall) {
                    hall.addEventListener('change', function(){
                        cbs().forEach(function(cb){ cb.checked = hall.checked; });
                        if (all) all.checked = hall.checked;
                    });
                }
                var apply = document.getElementById('tap-bulk-apply');
                if (apply) {
                    apply.addEventListener('click', function(){
                        var op = document.getElementById('tap-bulk-op').value;
                        if (!op) { tapToast(<?php echo wp_json_encode(__('Selecciona una acción.', 'travel-agency-platform')); ?>, 'error'); return; }
                        var ids = cbs().filter(function(cb){ return cb.checked; }).map(function(cb){ return cb.value; });
                        if (!ids.length) { tapToast(<?php echo wp_json_encode(__('Selecciona al menos una reserva.', 'travel-agency-platform')); ?>, 'error'); return; }
                        window.tapConfirm(<?php echo wp_json_encode(__('Aplicar acción masiva', 'travel-agency-platform')); ?>, <?php echo wp_json_encode(__('¿Aplicar la acción a las reservas seleccionadas?', 'travel-agency-platform')); ?>, function(){
                            var fd = new FormData();
                            fd.append('action', 'tap_dash_agency_bulk_booking');
                            fd.append('nonce', (window.tapDash && window.tapDash.nonce) || '');
                            fd.append('op', op);
                            ids.forEach(function(id){ fd.append('ids[]', id); });
                            fetch((window.tapDash && window.tapDash.ajax_url) || (window.ajaxurl || '/wp-admin/admin-ajax.php'), {method:'POST', body:fd, credentials:'same-origin'})
                            .then(function(r){return r.json();})
                            .then(function(j){
                                var msg = document.getElementById('tap-bulk-msg');
                                var text = (j && j.data && j.data.message) || (j && j.success ? 'Listo' : 'Error');
                                if (msg) msg.textContent = text;
                                window.tapToast(text, j && j.success ? 'success' : 'error');
                                if (j && j.success) { window.location.reload(); }
                            })
                            .catch(function(){ window.tapToast(<?php echo wp_json_encode(__('Error de red', 'travel-agency-platform')); ?>, 'error'); });
                        });
                    });
                }
            })();
            </script>
            </div>
	            <?php
            echo TAP_Dashboard_UI::pagination($base, [
                'seccion'  => 'bo-operations',
                'bo_status'=> $status,
                'bo_q'     => $search,
                'bo_from'  => $date_from,
                'bo_to'    => $date_to,
            ], $page, $total_pages);
            ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: finances ───────────────────────────────── */

    /** Agency disputes list (the Finanzas "En disputa" metric is now actionable). */
    private static function agency_disputes($user) {
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        ob_start();
        echo '<div class="tap-panel-section"><h2>' . esc_html__('Disputas', 'travel-agency-platform') . '</h2>';
        if (!$agency || !class_exists('TAP_Disputes')) {
            echo '<p class="tap-empty">' . esc_html__('No hay disputas que mostrar.', 'travel-agency-platform') . '</p></div>';
            return ob_get_clean();
        }
        $rows = TAP_Disputes::for_agency($agency);
        if (!$rows) {
            echo '<p class="tap-empty">' . esc_html__('No tienes disputas abiertas ni cerradas.', 'travel-agency-platform') . '</p>';
        } else {
            echo '<div class="tap-table-scroll"><table class="tap-table tap-table-compact"><thead><tr>'
                . '<th>' . esc_html__('Reserva', 'travel-agency-platform') . '</th>'
                . '<th>' . esc_html__('Motivo', 'travel-agency-platform') . '</th>'
                . '<th>' . esc_html__('Estado', 'travel-agency-platform') . '</th>'
                . '<th>' . esc_html__('Fecha', 'travel-agency-platform') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $d) {
                echo '<tr>';
                echo '<td>#' . (int) ($d->booking_id ?? 0) . '</td>';
                echo '<td>' . esc_html((string) ($d->reason ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($d->status ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($d->created_at ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /** Human-readable labels for raw audit codes in the agency UI. */
    public static function audit_action_label($code) {
        $labels = [
            'booking_confirmed'       => __('Reserva confirmada', 'travel-agency-platform'),
            'agency_profile_updated'  => __('Perfil de agencia actualizado', 'travel-agency-platform'),
            'listing_published'       => __('Listado publicado', 'travel-agency-platform'),
            'team_member_added'       => __('Miembro del equipo añadido', 'travel-agency-platform'),
        ];
        $code = (string) $code;
        if (isset($labels[$code])) return $labels[$code];
        if (preg_match('/^booking\s+#(\d+)$/i', $code, $m)) {
            return sprintf(__('Reserva #%d', 'travel-agency-platform'), (int) $m[1]);
        }
        return $code;
    }

    /**
     * Canonical agency role check for section access. A user with both the
     * admin and employee roles must be treated as an admin (same rule as the
     * nav), otherwise they see Finanzas/Equipo and the handler rejects them.
     */
    private static function is_employee_only($user) {
        $roles = (array) $user->roles;
        return in_array('tap_agency_employee', $roles, true)
            && !in_array('tap_agency_admin', $roles, true)
            && !user_can($user, 'manage_options');
    }

    /**
     * Enqueue the shared Chart.js handle (registered once in the footer by the
     * plugin bootstrap) and attach this chart's initializer to it. Replaces the
     * old raw <script src> + inline <script> pairs, which downloaded a second
     * copy of Chart.js and ran before the library was guaranteed to exist.
     */
    private static function enqueue_chart($canvas_id, $labels, $data, $label) {
        wp_enqueue_script('tap-chart');
        $js = '(function(){var c=document.getElementById(' . wp_json_encode($canvas_id) . ');'
            . 'if(!c||typeof Chart==="undefined"){return;}'
            . 'new Chart(c,{type:"bar",data:{labels:' . wp_json_encode($labels) . ','
            . 'datasets:[{label:' . wp_json_encode($label) . ',data:' . wp_json_encode($data) . ','
            . 'backgroundColor:"rgba(13,148,136,.7)",borderColor:"rgba(13,148,136,1)",'
            . 'borderWidth:1,borderRadius:6}]},'
            . 'options:{responsive:true,maintainAspectRatio:false,'
            . 'plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});})();';
        wp_add_inline_script('tap-chart', $js, 'after');
    }

    /** Invoices for the agency's bookings, with a real PDF download. */
    public static function agency_invoices($user) {
        global $wpdb;
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, b.booking_code FROM {$wpdb->prefix}tap_invoices i
             INNER JOIN {$wpdb->prefix}tap_bookings b ON b.id = i.booking_id
             WHERE b.agency_id = %d ORDER BY i.issued_at DESC LIMIT 100",
            $agency
        )) ?: [];
        ob_start();
        echo '<div class="tap-dash-section"><h2>' . esc_html__('Facturas de tus reservas', 'travel-agency-platform') . '</h2>';
        echo '<table class="tap-table tap-table-compact"><thead><tr><th>' . esc_html__('Factura', 'travel-agency-platform')
            . '</th><th>' . esc_html__('Reserva', 'travel-agency-platform')
            . '</th><th>' . esc_html__('Monto', 'travel-agency-platform')
            . '</th><th>' . esc_html__('Fecha', 'travel-agency-platform')
            . '</th><th>' . esc_html__('PDF', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($rows as $inv) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=tap_agency_invoice_pdf&invoice_id=' . (int) $inv->id), 'tap_agency_invoice_' . (int) $inv->id);
            echo '<tr><td>' . esc_html($inv->invoice_number) . '</td><td>' . esc_html($inv->booking_code) . '</td><td>'
                . esc_html(TAP_Currency::fmt((float) $inv->amount)) . '</td><td>' . esc_html(substr((string) $inv->issued_at, 0, 10))
                . '</td><td><a class="button button-small" href="' . esc_url($url) . '" target="_blank">PDF</a></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="5">' . esc_html__('Aún no hay facturas.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    /** Agency reviews inbox with a public reply flow. */
    public static function agency_reviews($user) {
        global $wpdb;
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $keys = class_exists('TAP_Post_Types') ? TAP_Post_Types::agency_meta_keys() : [];
        $ids = [];
        if ($keys) {
            $ph = implode(',', array_fill(0, count($keys), '%s'));
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$ph}) AND meta_value = %d",
                array_merge(array_values($keys), [$agency])
            ));
        }
        $rows = [];
        if ($ids) {
            $iph = implode(',', array_map('intval', $ids));
            $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tap_reviews WHERE service_id IN ({$iph}) ORDER BY created_at DESC LIMIT 100") ?: [];
        }
        ob_start();
        echo '<div class="tap-dash-section"><h2>' . esc_html__('Reseñas de tus servicios', 'travel-agency-platform') . '</h2>';
        foreach ($rows as $r) {
            echo '<div class="tap-review-card tap-review-card-list">';
            echo '<strong>' . esc_html((string) $r->rating) . ' ★</strong> · ' . esc_html($r->title ?: get_the_title((int) $r->service_id)) . '<br>';
            echo esc_html(wp_trim_words((string) $r->content, 30)) . '<br>';
            echo '<small>' . esc_html($r->created_at) . ' · ' . esc_html($r->mod_status) . '</small>';
            if ($r->reply) {
                echo '<p><strong>' . esc_html__('Tu respuesta:', 'travel-agency-platform') . '</strong> ' . esc_html($r->reply) . '</p>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="tap-form-spaced">'
                    . '<input type="hidden" name="action" value="tap_agency_review_reply">'
                    . '<input type="hidden" name="review_id" value="' . (int) $r->id . '">';
                wp_nonce_field('tap_agency_review_' . (int) $r->id);
                echo '<textarea name="reply" rows="2" class="large-text" placeholder="' . esc_attr__('Responde públicamente…', 'travel-agency-platform') . '" required></textarea>';
                echo '<button class="button button-small">' . esc_html__('Responder', 'travel-agency-platform') . '</button></form>';
            }
            echo '</div>';
        }
        if (!$rows) {
            echo '<p class="tap-empty">' . esc_html__('Aún no hay reseñas.', 'travel-agency-platform') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /** Save an agency's public reply to a review. */
    public static function handle_review_reply() {
        if (!is_user_logged_in()) { wp_die(esc_html__('No autorizado.', 'travel-agency-platform'), '', ['response' => 403]); }
        $review_id = absint($_POST['review_id'] ?? 0);
        check_admin_referer('tap_agency_review_' . $review_id);
        global $wpdb;
        $review = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'tap_reviews WHERE id = %d', $review_id));
        if (!$review) { wp_die(esc_html__('Reseña no encontrada.', 'travel-agency-platform'), '', ['response' => 404]); }
        $agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $owner = (int) get_post_meta((int) $review->service_id, '_tap_' . (TAP_Post_Types::meta_prefix($review->service_type) ?: 'tour') . '_agency_id', true);
        if (!$agency || $agency !== $owner) {
            wp_die(esc_html__('No tienes permiso sobre esta reseña.', 'travel-agency-platform'), '', ['response' => 403]);
        }
        $reply = sanitize_textarea_field(wp_unslash($_POST['reply'] ?? ''));
        if ('' === $reply) { wp_safe_redirect(wp_get_referer() ?: home_url('/mi-cuenta/'));
exit; }
        $user = wp_get_current_user();
        $wpdb->update($wpdb->prefix . 'tap_reviews', [
            'reply' => $reply,
            'reply_author' => $user->display_name,
            'reply_at' => current_time('mysql'),
        ], ['id' => $review_id]);
        wp_safe_redirect(add_query_arg('reply_ok', '1', wp_get_referer() ?: home_url('/mi-cuenta/?seccion=bo-reviews')));
        exit;
    }

    /** Daily operations calendar with arrivals, departures and no-show control. */
    public static function agency_calendar($user) {
        global $wpdb;
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $month = sanitize_text_field(wp_unslash($_GET['month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = current_time('Y-m'); }
        $start = $month . '-01';
        $end = gmdate('Y-m-t', strtotime($start));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_code, guest_name, check_in, check_out, status, adults, children
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d AND status NOT IN ('cancelled','refunded')
               AND ((check_in BETWEEN %s AND %s) OR (check_out BETWEEN %s AND %s))
             ORDER BY check_in ASC, id ASC LIMIT 300",
            $agency, $start, $end, $start, $end
        )) ?: [];
        $by_day = [];
        foreach ($rows as $b) {
            if ($b->check_in) { $by_day[$b->check_in]['in'][] = $b; }
            if ($b->check_out) { $by_day[$b->check_out]['out'][] = $b; }
        }
        $prev = gmdate('Y-m', strtotime($start . ' -1 month'));
        $next = gmdate('Y-m', strtotime($start . ' +1 month'));
        $base = home_url('/mi-cuenta/');
        $today = current_time('Y-m-d');

        ob_start();
        echo '<div class="tap-dash-section"><div class="tap-dash-section-header"><h2>'
            . esc_html(sprintf(__('Calendario de operaciones — %s', 'travel-agency-platform'), $month)) . '</h2><div class="tap-inline-row">';
        foreach ([[$prev, __('« Mes anterior', 'travel-agency-platform')], [$next, __('Mes siguiente »', 'travel-agency-platform')]] as $link) {
            echo '<a class="button button-small" href="' . esc_url(add_query_arg(['seccion' => 'bo-calendar', 'month' => $link[0]], $base)) . '">' . esc_html($link[1]) . '</a>';
        }
        echo '</div></div>';
        echo '<table class="tap-table tap-table-compact"><thead><tr><th>' . esc_html__('Día', 'travel-agency-platform')
            . '</th><th>' . esc_html__('Entradas', 'travel-agency-platform')
            . '</th><th>' . esc_html__('Salidas', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        $days = (int) gmdate('t', strtotime($start));
        $found = false;
        for ($d = 1; $d <= $days; $d++) {
            $day = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            $in = $by_day[$day]['in'] ?? [];
            $out = $by_day[$day]['out'] ?? [];
            if (!$in && !$out) { continue; }
            $found = true;
            echo '<tr' . ($day === $today ? ' class="is-today"' : '') . '><td><strong>' . esc_html($day) . '</strong></td><td>';
            foreach ($in as $b) {
                $voucher = home_url('/booking-detail/?code=' . rawurlencode($b->booking_code));
                echo '<div><a href="' . esc_url($voucher) . '">' . esc_html($b->booking_code) . '</a> · '
                    . esc_html($b->guest_name ?: __('Invitado', 'travel-agency-platform'))
                    . ' <span class="tap-status tap-status-' . esc_attr($b->status) . '">' . esc_html(TAP_Emails_Send::status_label($b->status)) . '</span>';
                if ('confirmed' === $b->status && $day <= $today) {
                    echo ' <button class="tap-btn tap-btn-sm tap-bo-api" data-op="no_show" data-id="' . (int) $b->id . '" data-name="' . esc_attr($b->booking_code) . '">'
                        . esc_html__('No show', 'travel-agency-platform') . '</button>';
                }
                echo '</div>';
            }
            echo '</td><td>';
            foreach ($out as $b) {
                echo '<div>' . esc_html($b->booking_code) . ' · '
                    . esc_html($b->guest_name ?: __('Invitado', 'travel-agency-platform')) . '</div>';
            }
            echo '</td></tr>';
        }
        if (!$found) {
            echo '<tr><td colspan="3">' . esc_html__('No hay reservas para este mes.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    private static function agency_finances($user) {
        global $wpdb;
        if (self::is_employee_only($user)) {
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

        // Monthly revenue for the last 6 months (for the chart).
        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthly[gmdate('Y-m', strtotime("-{$i} months"))] = 0.0;
        }
        $mrows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COALESCE(SUM(commission_amount),0) amt
             FROM {$wpdb->prefix}tap_bookings
             WHERE agency_id = %d AND payment_status = 'paid'
               AND commission_amount > 0
               AND status NOT IN ('cancelled', 'refunded')
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY ym",
            $agency
        ));
        foreach ($mrows as $mr) {
            if (isset($monthly[$mr->ym])) {
                $monthly[$mr->ym] = (float) $mr->amt;
            }
        }
        $chart_labels = array_keys($monthly);
        $chart_data   = array_values($monthly);
        // Simple projection: average of last 3 months * 3.
        $last3 = array_slice($chart_data, -3);
        $avg3 = count($last3) ? array_sum($last3) / count($last3) : 0;
        $projection = round($avg3 * 3, 2);

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Finanzas de la agencia', 'travel-agency-platform'); ?></h2>
                <?php $f_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id()); if ($f_agency): ?>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php') . '?action=tap_export_finances&agency=' . $f_agency, 'tap_export_finances_' . get_current_user_id())); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </div>
            <div class="tap-notice tap-notice-info">
                <span class="tap-notice-icon" aria-hidden="true">ℹ</span>
                <span><?php esc_html_e('Tu ingreso neto es el total de las reservas menos la comisión de la plataforma. Las transferencias a tu agencia se coordinan directamente con el administrador; esta sección es informativa.', 'travel-agency-platform'); ?></span>
            </div>
            <?php if (class_exists('TAP_Agency_Payouts')): ?>
                <?php
                $earn = TAP_Agency_Payouts::earnings((int) $agency);
                $payouts = TAP_Agency_Payouts::requests((int) $agency, 10);
                ?>
                <div class="tap-panel-section tap-section-spaced">
                    <h3><?php esc_html_e('Tus ingresos', 'travel-agency-platform'); ?></h3>
                    <div class="tap-stats-grid">
                        <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($earn['gross'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Ingreso bruto', 'travel-agency-platform'); ?></span></div>
                        <div class="tap-stat-card"><span class="tap-stat-number tap-stat-warn">-<?php echo esc_html(TAP_Currency::fmt($earn['fees'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Tarifa de plataforma', 'travel-agency-platform'); ?></span></div>
                        <div class="tap-stat-card"><span class="tap-stat-number tap-stat-warn">-<?php echo esc_html(TAP_Currency::fmt($earn['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión de plataforma', 'travel-agency-platform'); ?></span></div>
                        <div class="tap-stat-card"><span class="tap-stat-number tap-stat-success"><?php echo esc_html(TAP_Currency::fmt($earn['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Neto para tu agencia', 'travel-agency-platform'); ?></span></div>
                        <div class="tap-stat-card"><span class="tap-stat-number tap-stat-success"><?php echo esc_html(TAP_Currency::fmt($earn['available'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Disponible para cobrar', 'travel-agency-platform'); ?></span></div>
                    </div>
                    <?php if ($earn['available'] > 0): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-form-spaced">
                            <input type="hidden" name="action" value="tap_agency_payout_request">
                            <input type="hidden" name="agency_id" value="<?php echo (int) $agency; ?>">
                            <?php wp_nonce_field('tap_agency_payout_' . (int) $agency, 'tap_agency_nonce'); ?>
                            <button class="button button-primary"><?php echo esc_html(sprintf(__('Solicitar liquidación de %s', 'travel-agency-platform'), TAP_Currency::fmt($earn['available']))); ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (isset($_GET['payout_ok'])): ?>
                        <p class="tap-agency-meta" class="tap-stat-success"><?php esc_html_e('Solicitud enviada. El administrador procesará el pago.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                    <?php if ($payouts): ?>
                        <table class="tap-table tap-table-compact tap-table-spaced"><thead><tr>
                            <th><?php esc_html_e('Solicitud', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th><th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr></thead><tbody>
                            <?php foreach ($payouts as $po): ?>
                                <tr><td>#<?php echo (int) $po->id; ?></td><td><?php echo esc_html(TAP_Currency::fmt((float) $po->amount)); ?></td><td><?php echo esc_html($po->status); ?></td><td><?php echo esc_html(substr((string) $po->requested_at, 0, 10)); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-warn"><?php echo esc_html(TAP_Currency::fmt($totals->owed ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión de la plataforma pendiente', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-danger"><?php echo esc_html(TAP_Currency::fmt($totals->disputed ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión en disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-success"><?php echo esc_html(TAP_Currency::fmt($totals->settled ?? 0)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión de la plataforma cobrada', 'travel-agency-platform'); ?></span></div>
            </div>

            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Estado de liquidaciones', 'travel-agency-platform'); ?></h3>
                </div>
                <?php if ($pending): ?>
                    <p class="tap-agency-meta"><?php echo esc_html(sprintf(__('Liquidación #%d por %s en proceso.', 'travel-agency-platform'), (int) $pending->id, TAP_Currency::fmt($pending->amount))); ?></p>
                <?php elseif ($failed_count > 0): ?>
                    <p class="tap-agency-meta"><?php echo esc_html(sprintf(__('Hay %d liquidación(es) fallida(s). Contacta al administrador.', 'travel-agency-platform'), $failed_count)); ?></p>
                <?php else: ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no hay liquidaciones. El administrador coordina las transferencias cuando hay comisiones cobradas.', 'travel-agency-platform'); ?></p>
                <?php endif; ?>
            </div>

            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Comisión de la plataforma por mes', 'travel-agency-platform'); ?></h3>
                </div>
                <div class="tap-chart-wrap">
                    <canvas id="tap-finances-chart"></canvas>
                </div>
                <?php self::enqueue_chart('tap-finances-chart', $chart_labels, $chart_data, __('Comisión de la plataforma', 'travel-agency-platform')); ?>
                <p class="tap-agency-meta tap-meta-spaced"><?php printf(esc_html__('Proyección próximos 3 meses: %s (basado en promedio de los últimos 3 meses).', 'travel-agency-platform'), esc_html(TAP_Currency::fmt($projection))); ?></p>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: team (employees) ──────────────────────── */

    private static function agency_referrals($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia gestiona los referidos.', 'travel-agency-platform') . '</p>';
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        if (!class_exists('TAP_Referrals')) {
            return '<p class="tap-empty">' . esc_html__('Módulo de referidos no disponible.', 'travel-agency-platform') . '</p>';
        }

        $code = TAP_Referrals::code_for_agency($agency);
        $url  = TAP_Referrals::share_url($agency);
        $stats = TAP_Referrals::stats($agency);
        $rows  = TAP_Referrals::for_agency($agency);

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Programa de referidos', 'travel-agency-platform'); ?></h2>
            </div>
            <div class="tap-dash-card tap-card-spaced">
                <h3><?php esc_html_e('Tu enlace de referido', 'travel-agency-platform'); ?></h3>
                <p class="tap-agency-meta"><?php esc_html_e('Comparte este enlace. Cuando una nueva agencia se registre usando tu código, ganarás una comisión del 10% sobre sus comisiones durante 12 meses.', 'travel-agency-platform'); ?></p>
                <div class="tap-inline-row">
                    <input type="text" class="tap-input tap-input-block" readonly value="<?php echo esc_url($url); ?>">
                    <button type="button" class="tap-btn tap-btn-sm" onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo esc_js($url); ?>');"><?php esc_html_e('Copiar', 'travel-agency-platform'); ?></button>
                </div>
                <p class="tap-agency-meta tap-meta-spaced"><?php /* translators: %s: referral code */ echo esc_html(sprintf(__('Código: %s', 'travel-agency-platform'), $code)); ?></p>
            </div>

            <div class="tap-stats-grid tap-section-spaced">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $stats['clicks']; ?></span><span class="tap-stat-label"><?php esc_html_e('Clicks', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $stats['registrations']; ?></span><span class="tap-stat-label"><?php esc_html_e('Registros', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $stats['conversions']; ?></span><span class="tap-stat-label"><?php esc_html_e('Conversiones', 'travel-agency-platform'); ?></span></div>
            </div>

            <div class="tap-dash-section-header tap-section-spaced">
                <h3><?php esc_html_e('Referidos', 'travel-agency-platform'); ?></h3>
            </div>
            <?php if (!$rows): ?>
                <p class="tap-empty"><?php esc_html_e('Aún no tienes referidos. Comparte tu enlace para empezar.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
            <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                <thead><tr>
                    <th><?php esc_html_e('Agencia referida', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo $r->referred_agency_id ? esc_html(get_the_title((int) $r->referred_agency_id)) : esc_html__('—', 'travel-agency-platform'); ?></td>
                        <td><span class="tap-status tap-status-<?php echo in_array($r->status, ['converted'], true) ? 'confirmed' : 'pending'; ?>"><?php echo esc_html($r->status); ?></span></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function agency_personal_loyalty($user) {
        ob_start();
        if (class_exists('TAP_Loyalty')) {
            echo TAP_Front_Dash_Client::render_loyalty_panel($user);
        } else {
            echo '<p class="tap-empty">' . esc_html__('Módulo de fidelidad no disponible.', 'travel-agency-platform') . '</p>';
        }
        // render_loyalty_panel() already renders [tap_referral_dashboard];
        // echoing it again showed the referral box twice to agency admins.
        return ob_get_clean();
    }

    public static function mark_onboarding_tour_seen() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autorizado.', 'travel-agency-platform')], 403);
        }
        update_user_meta(get_current_user_id(), '_tap_onboarding_tour_seen', 1);
        wp_send_json_success();
    }

    private static function agency_analytics($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia ve los analytics.', 'travel-agency-platform') . '</p>';
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        if (!class_exists('TAP_Analytics')) {
            return '<p class="tap-empty">' . esc_html__('Módulo de analytics no disponible.', 'travel-agency-platform') . '</p>';
        }

        $from = sanitize_text_field(wp_unslash($_GET['an_from'] ?? gmdate('Y-m', strtotime('-5 months'))));
        $to   = sanitize_text_field(wp_unslash($_GET['an_to'] ?? gmdate('Y-m')));
        $stats = TAP_Analytics::agency_stats($agency, $from, $to);
        $funnel = TAP_Analytics::booking_funnel($from, $to, (int) $agency);

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Analytics de la agencia', 'travel-agency-platform'); ?></h2>
            </div>

            <form method="get" class="tap-filter-bar">
                <input type="hidden" name="seccion" value="bo-analytics">
                <label class="tap-filter-label"><?php esc_html_e('Desde', 'travel-agency-platform'); ?>
                    <input type="month" class="tap-input" name="an_from" value="<?php echo esc_attr($from); ?>">
                </label>
                <label class="tap-filter-label"><?php esc_html_e('Hasta', 'travel-agency-platform'); ?>
                    <input type="month" class="tap-input" name="an_to" value="<?php echo esc_attr($to); ?>">
                </label>
                <button type="submit" class="tap-btn tap-btn-sm"><?php esc_html_e('Aplicar', 'travel-agency-platform'); ?></button>
            </form>

            <div class="tap-stats-grid tap-section-spaced">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $stats['views']; ?></span><span class="tap-stat-label"><?php esc_html_e('Vistas de listados', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo (int) $stats['bookings']; ?></span><span class="tap-stat-label"><?php esc_html_e('Reservas', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Ingresos (pagados)', 'travel-agency-platform'); ?></span></div>
            </div>

            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Embudo de conversión', 'travel-agency-platform'); ?></h3>
                </div>
                <div class="tap-stats-grid">
                    <?php foreach ($funnel as $stage): ?>
                        <div class="tap-stat-card">
                            <span class="tap-stat-number"><?php echo (int) $stage['value']; ?></span>
                            <span class="tap-stat-label"><?php echo esc_html($stage['label']); ?><?php if ($stage['rate'] > 0): ?> (<?php echo esc_html($stage['rate']); ?>%)<?php endif; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($stats['top_services'])): ?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Top servicios por vistas', 'travel-agency-platform'); ?></h3>
                </div>
                <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                    <thead><tr>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Vistas', 'travel-agency-platform'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($stats['top_services'] as $ts): ?>
                        <tr>
                            <td><?php echo esc_html($ts['title']); ?></td>
                            <td><?php echo (int) $ts['views']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function agency_audit($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia ve el historial de actividad.', 'travel-agency-platform') . '</p>';
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        if (!class_exists('TAP_Audit_Log')) {
            return '<p class="tap-empty">' . esc_html__('Módulo de auditoría no disponible.', 'travel-agency-platform') . '</p>';
        }
        $logs = TAP_Audit_Log::for_agency($agency, 50);
        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Historial de actividad', 'travel-agency-platform'); ?></h2>
            </div>
            <?php if (!$logs): ?>
                <p class="tap-empty"><?php esc_html_e('Sin actividad registrada.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
            <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                <thead><tr>
                    <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Usuario', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acción', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Objeto', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Detalles', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?php echo esc_html($l->created_at); ?></td>
                        <td>
                        <?php
                        $u = get_userdata((int) $l->user_id);
						echo esc_html($u ? $u->display_name : '#' . $l->user_id);
						?>
                        </td>
                        <td><?php echo esc_html(self::audit_action_label($l->action)); ?></td>
                        <td><?php echo esc_html(($l->object_type ?: '') . ($l->object_id ? ' #' . $l->object_id : '')); ?></td>
                        <td><?php echo esc_html($l->details ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function agency_profile($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia edita el perfil.', 'travel-agency-platform') . '</p>';
        }
        $agency_id = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency_id) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $agency = get_post($agency_id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_agencies WHERE user_id = %d LIMIT 1", $user->ID));

        $name     = $agency ? $agency->post_title : '';
        $desc     = $agency ? $agency->post_content : '';
        $email    = $row ? $row->email : get_post_meta($agency_id, '_tap_agency_email', true);
        $phone    = $row ? $row->phone : get_post_meta($agency_id, '_tap_agency_phone', true);
        $whatsapp = $row ? $row->whatsapp : get_post_meta($agency_id, '_tap_agency_whatsapp', true);
        $website  = $row ? $row->website : get_post_meta($agency_id, '_tap_agency_website', true);
        $address  = $row ? $row->address : get_post_meta($agency_id, '_tap_agency_address', true);
        $city     = $row ? $row->city : get_post_meta($agency_id, '_tap_agency_city', true);
        $country  = $row ? $row->country : get_post_meta($agency_id, '_tap_agency_country', true);

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Perfil de la agencia', 'travel-agency-platform'); ?></h2>
            </div>
            <div class="tap-dash-card tap-card-spaced">
                <form id="tap-agency-profile-form" class="tap-profile-form">
                    <input type="hidden" name="agency_id" value="<?php echo (int) $agency_id; ?>">
                    <div class="tap-profile-grid">
                        <div class="tap-profile-field tap-profile-field--full">
                            <label><?php esc_html_e('Nombre de la agencia', 'travel-agency-platform'); ?></label>
                            <input type="text" name="name" value="<?php echo esc_attr($name); ?>" required>
                        </div>
                        <div class="tap-profile-field tap-profile-field--full">
                            <label><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                            <textarea name="description" rows="4"><?php echo esc_textarea($desc); ?></textarea>
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('Email', 'travel-agency-platform'); ?></label>
                            <input type="email" name="email" value="<?php echo esc_attr($email); ?>">
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('Teléfono', 'travel-agency-platform'); ?></label>
                            <input type="text" name="phone" value="<?php echo esc_attr($phone); ?>">
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('WhatsApp', 'travel-agency-platform'); ?></label>
                            <input type="text" name="whatsapp" value="<?php echo esc_attr($whatsapp); ?>">
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('Sitio web', 'travel-agency-platform'); ?></label>
                            <input type="url" name="website" value="<?php echo esc_url($website); ?>">
                        </div>
                        <div class="tap-profile-field tap-profile-field--full">
                            <label><?php esc_html_e('Dirección', 'travel-agency-platform'); ?></label>
                            <input type="text" name="address" value="<?php echo esc_attr($address); ?>">
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('Ciudad', 'travel-agency-platform'); ?></label>
                            <input type="text" name="city" value="<?php echo esc_attr($city); ?>">
                        </div>
                        <div class="tap-profile-field">
                            <label><?php esc_html_e('País', 'travel-agency-platform'); ?></label>
                            <input type="text" name="country" value="<?php echo esc_attr($country); ?>">
                        </div>
                    </div>
                    <div class="tap-profile-actions">
                        <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Guardar perfil', 'travel-agency-platform'); ?></button>
                        <span class="tap-form-msg tap-msg-spaced"></span>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function(){
            var form = document.getElementById('tap-agency-profile-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var fd = new FormData(form);
                fd.append('action', 'tap_dash_agency_profile_save');
                fd.append('nonce', (window.tapDash && window.tapDash.nonce) || '');
                var btn = form.querySelector('button[type="submit"]'); btn.disabled = true;
                fetch((window.tapDash && window.tapDash.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){return r.json();})
                .then(function(j){
                    btn.disabled = false;
                    var msg = form.querySelector('.tap-form-msg');
                    if (msg) msg.textContent = (j && j.data && j.data.message) || (j && j.success ? 'Guardado' : 'Error');
                    if (j && j.success) msg.style.color = '#047857'; else msg.style.color = '#b91c1c';
                })
                .catch(function(){ btn.disabled = false; });
            });
        })();
        </script>
        <?php
        if (class_exists('TAP_KYC_Docs')) {
            echo '<div class="tap-dash-section"><h3>' . esc_html__('Verificación de identidad', 'travel-agency-platform') . '</h3>'
                . TAP_KYC_Docs::render_upload_form((int) $agency_id, TAP_Approval::status($agency_id)) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderer
                . '</div>';
            if (isset($_GET['kyc_ok'])) {
                echo '<p class="notice notice-success inline">' . esc_html__('Documentos enviados para revisión.', 'travel-agency-platform') . '</p>';
            }
        }
        if (class_exists('TAP_Legal') && !TAP_Legal::agency_current((int) $agency_id)) {
            echo '<div class="tap-dash-section"><h3>' . esc_html__('Contrato de agencia', 'travel-agency-platform') . '</h3>'
                . '<p class="tap-agency-meta">' . esc_html__('Debes aceptar el contrato antes de contratar un plan o publicar destacados.', 'travel-agency-platform') . '</p>'
                . TAP_Legal::render_acceptance(TAP_Legal::CONTEXT_AGENCY, 0, (int) $agency_id) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer
                . '</div>';
        }
        return ob_get_clean();
    }

    private static function agency_team($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia gestiona el equipo.', 'travel-agency-platform') . '</p>';
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }
        $staff = TAP_Booking::get_staff_for_agency($agency);
        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Equipo', 'travel-agency-platform'); ?></h2>
            </div>
            <div class="tap-dash-card tap-card-spaced">
                <h3><?php esc_html_e('Agregar empleado', 'travel-agency-platform'); ?></h3>
                <p class="tap-agency-meta"><?php esc_html_e('El usuario debe existir ya en la plataforma (que se haya registrado primero). Se le asignará el rol de empleado y acceso a esta agencia.', 'travel-agency-platform'); ?></p>
                <form id="tap-team-add" class="tap-bo-form">
                    <label><?php esc_html_e('Email del usuario', 'travel-agency-platform'); ?>
                        <input type="email" name="email" required placeholder="<?php echo esc_attr__('usuario@ejemplo.com', 'travel-agency-platform'); ?>">
                    </label>
                    <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Agregar', 'travel-agency-platform'); ?></button>
                </form>
            </div>
            <div class="tap-dash-section-header tap-section-spaced">
                <h3><?php esc_html_e('Miembros del equipo', 'travel-agency-platform'); ?></h3>
            </div>
            <?php if (!$staff): ?>
                <p class="tap-empty"><?php esc_html_e('Aún no hay empleados en el equipo.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
            <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                <thead><tr>
                    <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Email', 'travel-agency-platform'); ?></th>
                    <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($staff as $member): ?>
                    <tr>
                        <td><?php echo esc_html($member->display_name); ?></td>
                        <td><?php echo esc_html($member->user_email); ?></td>
                        <td>
                            <button class="tap-btn tap-btn-sm tap-btn-danger tap-team-remove" data-id="<?php echo (int) $member->ID; ?>" data-name="<?php echo esc_attr($member->display_name); ?>"><?php esc_html_e('Quitar', 'travel-agency-platform'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: destacadoss (promos) ───────────────────── */

    private static function agency_promos($user) {
        if (self::is_employee_only($user)) {
            return '<p class="tap-empty">' . esc_html__('Solo el administrador de la agencia gestiona los destacados.', 'travel-agency-platform') . '</p>';
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency || !class_exists('TAP_Promotions')) {
            return '<p class="tap-empty">' . esc_html__('No tienes una agencia vinculada.', 'travel-agency-platform') . '</p>';
        }

        $price        = TAP_Promotions::get_price();
        $slots        = TAP_Promotions::featured_slots($agency);
        $active_promos = TAP_Promotions::active_promos($agency);
        $pending      = TAP_Promotions::pending_promos($agency);
        $active_ids   = array_map(static fn($p) => (int) $p->listing_id, (array) $active_promos);
        $pending_ids  = array_map(static fn($p) => (int) $p->listing_id, (array) $pending);
        $paypal_ready = class_exists('TAP_PayPal') && TAP_PayPal::is_ready();

        $listing_labels = [
            'tap_accommodation' => __('Alojamientos', 'travel-agency-platform'),
            'tap_tour'          => __('Tours', 'travel-agency-platform'),
            'tap_transport'     => __('Transportes', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de carros', 'travel-agency-platform'),
            'tap_boat'          => __('Barcos', 'travel-agency-platform'),
            'tap_package'       => __('Paquetes', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipo', 'travel-agency-platform'),
        ];
        $rows = [];
        foreach ($listing_labels as $type => $label) {
            $ids = get_posts([
                'post_type'      => $type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_key'       => TAP_Post_Types::agency_meta_key($type),
                'meta_value'     => $agency,
                'fields'         => 'ids',
            ]);
            self::prime_titles($ids);
            foreach ($ids as $pid) {
                $rows[] = ['id' => (int) $pid, 'type' => $type, 'label' => $label, 'title' => get_the_title($pid)];
            }
        }
        $feat_paged = max(1, absint($_GET['fpaged'] ?? 1));
        $feat_per = 20;
        $feat_rows = array_slice($rows, ($feat_paged - 1) * $feat_per, $feat_per);

        ob_start();
        ?>
        <div class="tap-dash-section">
            <div class="tap-dash-section-header">
                <h2><?php esc_html_e('Destacados', 'travel-agency-platform'); ?></h2>
                <?php if ($slots > 0) : ?>
                    <p class="tap-agency-meta"><?php echo esc_html(sprintf(/* translators: 1: destacados activos, 2: máximo de destacados del plan, 3: precio mensual por listado */ __('Disponibles: %d de %d · %s/mes por listado', 'travel-agency-platform'), count($active_ids), $slots, TAP_Currency::fmt($price))); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($slots <= 0) : ?>
                <div class="tap-capacity-note">
                    <?php esc_html_e('Tu plan actual no incluye listados destacados. Mejora tu plan para destacar tus servicios en la plataforma.', 'travel-agency-platform'); ?>
                    <a href="<?php echo esc_url(home_url('/planes/')); ?>" class="tap-btn tap-btn-sm tap-btn-spaced"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
                </div>
            <?php elseif (!$rows) : ?>
                <p class="tap-empty"><?php esc_html_e('Aún no tienes listados publicados para destacar.', 'travel-agency-platform'); ?></p>
            <?php else : ?>
                <?php if ($pending) : ?>
                    <div class="tap-capacity-note tap-capacity-spaced">
                        <?php esc_html_e('Tienes solicitudes de destacado pendientes de confirmación de pago.', 'travel-agency-platform'); ?>
                    </div>
                <?php endif; ?>
                <div class="tap-table-scroll"><table class="tap-table tap-table-compact">
                    <thead><tr>
                        <th><?php esc_html_e('Listado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ($feat_rows as $row) :
                        $is_feat  = TAP_Promotions::is_featured($row['id']);
                        $until    = TAP_Promotions::featured_until($row['id']);
                        $is_pend  = in_array($row['id'], $pending_ids, true);
                        ?>
                        <tr>
                            <td><?php echo esc_html($row['title']); ?></td>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td>
                                <?php if ($is_feat && $until) : ?>
                                    <span class="tap-status tap-status-confirmed">★ <?php esc_html_e('Destacado hasta', 'travel-agency-platform'); ?> <?php echo esc_html($until); ?></span>
                                <?php elseif ($is_pend) : ?>
                                    <span class="tap-status tap-status-pending"><?php esc_html_e('Pendiente de pago', 'travel-agency-platform'); ?></span>
                                <?php else : ?>
                                    <span class="tap-status tap-status-pending"><?php esc_html_e('No destacado', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_feat) : ?>
                                    <span class="tap-promo-badge">★</span>
                                <?php elseif ($is_pend) : ?>
                                    <span class="tap-promo-badge tap-promo-waiting">&#8987;</span>
                                <?php elseif ($paypal_ready) : ?>
                                    <form class="tap-promo-paypal-form">
                                        <input type="hidden" name="listing_id" value="<?php echo (int) $row['id']; ?>">
                                        <select name="months" class="tap-promo-months">
                                            <?php foreach ([1, 3, 6, 12, 24] as $m) : ?>
                                                <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?> <?php esc_html_e('mes(es)', 'travel-agency-platform'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="tap-promo-paypal-btn" data-listing-id="<?php echo (int) $row['id']; ?>" data-price="<?php echo esc_attr((float) $price); ?>"></div>
                                    </form>
                                <?php else : ?>
                                    <form class="tap-promo-form">
                                        <input type="hidden" name="listing_id" value="<?php echo (int) $row['id']; ?>">
                                        <select name="months" class="tap-promo-months">
                                            <?php foreach ([1, 3, 6, 12, 24] as $m) : ?>
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
                <?php echo TAP_Dashboard::paginate(count($rows), $feat_per, $feat_paged, 'fpaged'); ?>
            <?php endif; ?>
        </div>
        <?php if ($paypal_ready && $rows && $slots > 0) : ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(TAP_PayPal::get_client_id()); ?>&currency=<?php echo esc_attr(TAP_Currency::checkout_code()); ?>" data-namespace="tapPayPal"></script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* ── agency back-office: overview (resumen) ─────────────────────── */

    private static function agency_overview($user) {
        global $wpdb;
        $is_admin = TAP_Roles::can_manage_agency_panel($user);
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
        self::prime_titles(array_map(static fn($b) => (int) $b->service_id, $recent));

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
        // One grouped query replaces the 14 get_posts() calls: listing counts
        // and the "publish" onboarding flag share the same result set.
        $meta_keys_for_agency = [];
        foreach (array_keys($listing_labels) as $t) {
            $meta_keys_for_agency[] = TAP_Post_Types::agency_meta_key($t);
        }
        $kph  = implode(',', array_fill(0, count($meta_keys_for_agency), '%s'));
        $tph  = implode(',', array_fill(0, count($listing_labels), '%s'));
        $excl = get_post_stati(['exclude_from_search' => true]);
        $eph  = $excl ? implode(',', array_fill(0, count($excl), '%s')) : "''";
        $owned = [];
        if ($agency_id) {
            $owned = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_type, p.post_status
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->postmeta} m
                           ON m.post_id = p.ID AND m.meta_key IN ($kph) AND m.meta_value = %d
                  WHERE p.post_type IN ($tph)
                    AND p.post_status NOT IN ($eph)
                  GROUP BY p.ID, p.post_type, p.post_status",
                array_merge($meta_keys_for_agency, [$agency_id], array_keys($listing_labels), $excl)
            ));
        }

        $listings = [];
        $any_published = false;
        foreach ($owned as $row) {
            $type = (string) $row->post_type;
            if (!isset($listings[$type])) {
                $listings[$type] = ['label' => $listing_labels[$type], 'count' => 0];
            }
            ++$listings[$type]['count'];
            if ('publish' === $row->post_status) {
                $any_published = true;
            }
        }
        // Preserve the original type order (query rows come back by post ID).
        $ordered_listings = [];
        foreach ($listing_labels as $type => $label) {
            if (isset($listings[$type])) {
                $ordered_listings[$type] = $listings[$type];
            }
        }
        $listings = $ordered_listings;

        $base = home_url('/mi-cuenta/');

        $show_onboarding_tour = empty($listings) && !get_user_meta($user->ID, '_tap_onboarding_tour_seen', true);

        // Onboarding checklist for agencies.
        $onboarding = [
            'create_listing' => !empty($listings),
            'add_photos'     => false,
            'set_prices'     => false,
            'set_availability' => false,
            'publish'        => false,
        ];
        if ($agency_id) {
            // One meta query for thumbnails + every price key, instead of
            // get_post_meta() per listing per key.
            $onboarding_meta_keys = array_merge(['_thumbnail_id'], [
                '_tap_acc_price_per_night', '_tap_tour_price_adult', '_tap_trans_price',
                '_tap_car_price_per_day', '_tap_boat_price_half', '_tap_pkg_price', '_tap_eq_price_day',
            ]);
            $mph = implode(',', array_fill(0, count($onboarding_meta_keys), '%s'));
            $ids = array_map('intval', wp_list_pluck($owned, 'ID'));
            $iph = $ids ? implode(',', $ids) : '0';
            $metas = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                  WHERE meta_key IN ($mph) AND post_id IN ($iph)",
                $onboarding_meta_keys
            ), ARRAY_A);
            $any_photos = false;
            $any_prices = false;
            foreach ($metas as $meta) {
                $truthy = '' !== (string) $meta['meta_value'] && '0' !== (string) $meta['meta_value'];
                if ($truthy) {
                    if ('_thumbnail_id' === $meta['meta_key']) {
                        $any_photos = true;
                    } else {
                        $any_prices = true;
                    }
                }
            }
            // Availability belongs to the agency's linked listings, not to
            // whatever the owner authored personally; include room inventory.
            $owned_ids = array_values(array_unique(array_merge(
                $ids,
                class_exists('TAP_Analytics') ? TAP_Analytics::agency_listing_ids($agency_id) : []
            )));
            $slot_count = 0;
            if ($owned_ids) {
                $iph2 = implode(',', array_map('intval', $owned_ids));
                $slot_count += (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tap_daily_pricing WHERE service_id IN ({$iph2})"
                );
                $slot_count += (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tap_room_accommodation_id'
                     WHERE p.post_type = 'tap_room' AND m.meta_value IN ({$iph2})"
                );
            }
            $onboarding['add_photos'] = $any_photos;
            $onboarding['set_prices'] = $any_prices;
            $onboarding['set_availability'] = $slot_count > 0;
            $onboarding['publish'] = $any_published;
        }
        $onboarding_done = count(array_filter($onboarding));

        // Monthly revenue for the last 6 months (for the chart).
        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthly[gmdate('Y-m', strtotime("-{$i} months"))] = 0.0;
        }
        if ($agency_id) {
            $mrows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COALESCE(SUM(total_amount),0) amt
                 FROM {$wpdb->prefix}tap_bookings
                 WHERE agency_id = %d AND payment_status = 'paid'
                   AND status NOT IN ('cancelled', 'refunded')
                   AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY ym",
                $agency_id
            ));
            foreach ($mrows as $mr) {
                if (isset($monthly[$mr->ym])) {
                    $monthly[$mr->ym] = (float) $mr->amt;
                }
            }
        }
        $chart_labels = array_keys($monthly);
        $chart_data   = array_values($monthly);

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
            <div class="tap-plan-summary" data-onboarding="plan">
                <span><?php esc_html_e('Plan', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->name); ?></strong></span>
                <?php if (!empty($tap_plan->paid_until)): ?>
                    <span><?php esc_html_e('Válido hasta', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->paid_until); ?></strong></span>
                <?php endif; ?>
                <span><?php esc_html_e('Listados', 'travel-agency-platform'); ?>: <strong><?php echo (int) $tap_limit < 0 ? esc_html__('Ilimitados', 'travel-agency-platform') : esc_html($tap_used . ' / ' . $tap_limit); ?></strong></span>
                <span><?php esc_html_e('Comisión', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></span>
                <a href="<?php echo esc_url(home_url('/planes/')); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
            </div>
				<?php if ($tap_current && 'pending' === $tap_current->status): ?>
                <div class="tap-capacity-note tap-capacity-spaced">
                    <?php esc_html_e('Tienes una suscripción pendiente de confirmación de pago.', 'travel-agency-platform'); ?>
                </div>
            <?php elseif ((int) $tap_limit >= 0 && $tap_used >= (int) $tap_limit): ?>
                <div class="tap-capacity-note tap-full tap-capacity-spaced">
                    <?php echo esc_html(sprintf(/* translators: %d: número máximo de listados permitidos por el plan */ __('Alcanzaste el límite de %d listados de tu plan. Mejora de plan para publicar más.', 'travel-agency-platform'), (int) $tap_limit)); ?>
                </div>
				<?php
            endif;
endif;
			?>

            <?php if ($agency_id && $onboarding_done < 5) : ?>
            <div class="tap-onboarding-checklist" data-onboarding="checklist">
                <h3><?php esc_html_e('Primeros pasos', 'travel-agency-platform'); ?></h3>
                <p><?php esc_html_e('Completa estos pasos para empezar a recibir reservas.', 'travel-agency-platform'); ?></p>
                <ul>
                    <?php
                    $onboarding_links = [
                        'create_listing' => [__('Crear tu primer servicio', 'travel-agency-platform'), home_url('/manage-listing/?new=tap_tour')],
                        'add_photos'     => [__('Añadir fotos', 'travel-agency-platform'), home_url('/manage-listing/')],
                        'set_prices'     => [__('Configurar precios', 'travel-agency-platform'), home_url('/manage-listing/')],
                        'set_availability'=> [__('Configurar disponibilidad', 'travel-agency-platform'), home_url('/manage-listing/')],
                        'publish'        => [__('Publicar tu primer servicio', 'travel-agency-platform'), home_url('/manage-listing/')],
                    ];
                    foreach ($onboarding_links as $step => $link):
                    ?>
                        <li class="<?php echo $onboarding[$step] ? 'is-done' : ''; ?>">
                            <a href="<?php echo esc_url($link[1]); ?>"><?php echo esc_html($link[0]); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="tap-onboarding-note"><?php esc_html_e('Tu agencia será revisada en 24-48h. Podrás preparar tus listados mientras tanto.', 'travel-agency-platform'); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($agency_id) : ?>
            <div class="tap-onboarding-help">
                <h4><?php esc_html_e('Recursos para empezar', 'travel-agency-platform'); ?></h4>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/planes/')); ?>"><?php esc_html_e('Ver planes y límites', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/centro-de-soporte/')); ?>"><?php esc_html_e('Centro de soporte', 'travel-agency-platform'); ?></a></li>
                </ul>
            </div>
            <?php endif; ?>

            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['total']); ?></span><span class="tap-stat-label"><?php esc_html_e('Reservas totales', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-warn"><?php echo esc_html($stats['request'] ?? 0); ?></span><span class="tap-stat-label"><?php esc_html_e('Solicitudes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['pending']); ?></span><span class="tap-stat-label"><?php esc_html_e('Pendientes', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['confirmed']); ?></span><span class="tap-stat-label"><?php esc_html_e('Confirmadas', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format((float) $stats['completed'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Completadas', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Ingresos', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión de la plataforma', 'travel-agency-platform'); ?></span></div>
                <?php if ($is_admin): ?>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-success"><?php echo esc_html(TAP_Currency::fmt($stats['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Neto para ti', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-warn"><?php echo esc_html(TAP_Currency::fmt($comm_rows->owed)); ?></span><span class="tap-stat-label"><?php esc_html_e('Comisión de la plataforma pendiente', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-danger"><?php echo esc_html(TAP_Currency::fmt($comm_rows->disputed)); ?></span><span class="tap-stat-label"><?php esc_html_e('En disputa', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number tap-stat-success"><?php echo esc_html(TAP_Currency::fmt($comm_rows->settled)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($agency_id && !empty($listings)): ?>
            <div class="tap-panel-section">
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
            <?php elseif ($agency_id): ?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Tus listados', 'travel-agency-platform'); ?></h3>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>"><?php esc_html_e('Nuevo alojamiento', 'travel-agency-platform'); ?></a>
                </div>
                <p class="tap-empty"><?php esc_html_e('Aún no tienes listados.', 'travel-agency-platform'); ?></p>
            </div>
            <?php endif; ?>

            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Ingresos por mes', 'travel-agency-platform'); ?></h3>
                </div>
                <div class="tap-chart-wrap">
                    <canvas id="tap-revenue-chart"></canvas>
                </div>
                <?php self::enqueue_chart('tap-revenue-chart', $chart_labels, $chart_data, __('Ingresos', 'travel-agency-platform')); ?>
            </div>

            <div class="tap-panel-section">
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
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Leads de contacto', 'travel-agency-platform'); ?> <span class="tap-tab-count"><?php echo (int) TAP_Leads::count_for_agency($agency_id); ?></span></h3>
                    <?php if (TAP_Leads::count_for_agency($agency_id) > 0): ?>
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php') . '?action=tap_export_leads&agency=' . (int) $agency_id, 'tap_export_leads_' . $user->ID)); ?>"><?php esc_html_e('Exportar CSV', 'travel-agency-platform'); ?></a>
                    <?php endif; ?>
                </div>
                <?php
                $leads = TAP_Leads::for_agency($agency_id, 10);
                self::prime_titles(array_map(static fn($ld) => (int) $ld->service_id, $leads));
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
        if (!empty($show_onboarding_tour)) :
        ?>
        <script>
        (function(){
            var steps = [
                { sel: 'a[href*="manage-listing"]', text: 'Usa "Nuevo" para crear tu primer servicio (tour, alojamiento, etc.).' },
                { sel: '[data-onboarding="checklist"]', text: 'Este checklist te guía paso a paso para publicar tu primer servicio.' },
                { sel: '[data-onboarding="plan"]', text: 'Aquí ves tu plan, límites y comisión.' },
            ];
            var idx = 0;
            var overlay = document.createElement('div');
            overlay.className = 'tap-onboarding-overlay';
            document.body.appendChild(overlay);
            var tooltip = document.createElement('div');
            tooltip.className = 'tap-onboarding-tooltip';
            document.body.appendChild(tooltip);

            function show(i) {
                if (i >= steps.length) { finish(); return; }
                idx = i;
                var step = steps[i];
                var el = document.querySelector(step.sel);
                if (!el) { show(i+1); return; }
                var rect = el.getBoundingClientRect();
                tooltip.innerHTML = '<p>' + step.text + '</p><div class="tap-onboarding-actions"><button type="button" class="tap-btn tap-btn-sm" data-prev>Anterior</button><button type="button" class="tap-btn tap-btn-primary tap-btn-sm" data-next>Siguiente</button><button type="button" class="tap-btn tap-btn-sm" data-skip>Saltar</button></div>';
                // Clamp horizontally so the tooltip never leaves a narrow screen.
                var tipLeft = rect.left + window.scrollX;
                var tipWidth = Math.min(320, window.innerWidth - 24);
                tipLeft = Math.max(12, Math.min(tipLeft, window.scrollX + window.innerWidth - tipWidth - 12));
                tooltip.style.top = (rect.bottom + 12 + window.scrollY) + 'px';
                tooltip.style.left = tipLeft + 'px';
                // highlight
                el.style.outline = '3px solid #0f766e';
                el.style.outlineOffset = '4px';
                el.scrollIntoView({behavior:'smooth', block:'center'});
            }
            function clearHighlight() {
                steps.forEach(function(s){ var e=document.querySelector(s.sel); if(e){ e.style.outline=''; e.style.outlineOffset=''; } });
            }
            function finish() {
                clearHighlight();
                overlay.remove();
                tooltip.remove();
                if (typeof tap_ajax !== 'undefined') {
                    jQuery.post((window.tapDash && window.tapDash.ajax_url) || tap_ajax.ajax_url, { action: 'tap_onboarding_tour_done', nonce: (window.tapDash && window.tapDash.nonce) || tap_ajax.nonce });
                }
            }
            tooltip.addEventListener('click', function(e){
                if (e.target.getAttribute('data-next') !== null) { clearHighlight(); show(idx+1); }
                else if (e.target.getAttribute('data-prev') !== null) { clearHighlight(); if (idx>0) show(idx-1); }
                else if (e.target.getAttribute('data-skip') !== null) { finish(); }
            });
            overlay.addEventListener('click', finish);
            show(0);
        })();
        </script>
        <style>
        .tap-onboarding-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 9998; }
        .tap-onboarding-tooltip { position: absolute; z-index: 9999; background: #fff; border-radius: 12px; padding: 14px 16px; max-width: 320px; box-shadow: 0 12px 40px rgba(0,0,0,0.25); }
        .tap-onboarding-tooltip p { margin: 0 0 10px; font-size: 14px; color: #1e293b; }
        .tap-onboarding-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        @media (max-width: 640px) {
            .tap-onboarding-tooltip { position: fixed; left: 12px !important; right: 12px; top: auto !important; bottom: 12px; max-width: none; width: auto; }
        }
        </style>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    private static function agency_nav($role, $active) {
        $base = home_url('/mi-cuenta/');
        $agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $unread = $agency ? TAP_Chat::unread_total_for_agency($agency) : 0;
        $new_bookings = 0;
        if ($agency) {
            global $wpdb;
            $new_bookings = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d AND status IN ('request','pending')",
                $agency
            ));
        }
        $is_team_admin = ('tap_agency_employee' !== $role);
        $nav = [
            ['key' => 'overview', 'label' => __('Resumen', 'travel-agency-platform'), 'icon' => 'home', 'url' => $base],
            ['key' => 'bo-operations', 'label' => __('Operaciones', 'travel-agency-platform'), 'icon' => 'clipboard', 'url' => add_query_arg('seccion', 'bo-operations', $base), 'badge' => ($unread + $new_bookings)],
            ['key' => 'bo-calendar', 'label' => __('Calendario', 'travel-agency-platform'), 'icon' => 'calendar', 'url' => add_query_arg('seccion', 'bo-calendar', $base)],
        ];
        // Finanzas y Equipo son solo del administrador de la agencia;
        // los empleados ven Resumen, Operaciones y Listados.
        if ($is_team_admin) {
            $nav[] = ['key' => 'bo-finances', 'label' => __('Finanzas', 'travel-agency-platform'), 'icon' => 'wallet', 'url' => add_query_arg('seccion', 'bo-finances', $base)];
            $nav[] = ['key' => 'bo-invoices', 'label' => __('Facturas', 'travel-agency-platform'), 'icon' => 'receipt', 'url' => add_query_arg('seccion', 'bo-invoices', $base)];
            $nav[] = ['key' => 'bo-reviews', 'label' => __('Reseñas', 'travel-agency-platform'), 'icon' => 'chat', 'url' => add_query_arg('seccion', 'bo-reviews', $base)];
            $nav[] = ['key' => 'bo-analytics', 'label' => __('Analytics', 'travel-agency-platform'), 'icon' => 'chart', 'url' => add_query_arg('seccion', 'bo-analytics', $base)];
            $nav[] = ['key' => 'bo-audit', 'label' => __('Actividad', 'travel-agency-platform'), 'icon' => 'clock', 'url' => add_query_arg('seccion', 'bo-audit', $base)];
            $nav[] = ['key' => 'bo-disputas', 'label' => __('Disputas', 'travel-agency-platform'), 'icon' => 'flag', 'url' => add_query_arg('seccion', 'bo-disputas', $base)];
        }
        $nav[] = ['key' => 'bo-listings', 'label' => __('Listados', 'travel-agency-platform'), 'icon' => 'grid', 'url' => add_query_arg('seccion', 'bo-listings', $base)];
        $nav[] = ['key' => 'loyalty', 'label' => __('Mis puntos', 'travel-agency-platform'), 'icon' => 'award', 'url' => add_query_arg('seccion', 'loyalty', $base)];
        $nav[] = ['key' => 'help', 'label' => __('Ayuda', 'travel-agency-platform'), 'icon' => 'help', 'url' => home_url('/centro-de-soporte/')];
        if ($is_team_admin) {
            $nav[] = ['key' => 'bo-profile', 'label' => __('Perfil', 'travel-agency-platform'), 'icon' => 'user', 'url' => add_query_arg('seccion', 'bo-profile', $base)];
        }
        if ($is_team_admin) {
            $nav[] = ['key' => 'bo-promos', 'label' => __('Destacados', 'travel-agency-platform'), 'icon' => 'megaphone', 'url' => add_query_arg('seccion', 'bo-promos', $base)];
            $nav[] = ['key' => 'bo-referrals', 'label' => __('Referidos', 'travel-agency-platform'), 'icon' => 'link', 'url' => add_query_arg('seccion', 'bo-referrals', $base)];
            $nav[] = ['key' => 'bo-team', 'label' => __('Equipo', 'travel-agency-platform'), 'icon' => 'users', 'url' => add_query_arg('seccion', 'bo-team', $base)];
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
        // Multi-role users (e.g. tap_client + tap_agency_employee) show the
        // agency label, not the first role in the array.
        $role = 'subscriber';
        foreach (['tap_agency_admin', 'tap_agency_employee', 'tap_client'] as $candidate) {
            if (in_array($candidate, (array) $user->roles, true)) {
                $role = $candidate;
                break;
            }
        }
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
                    <a href="<?php echo esc_url($s['url']); ?>" class="tap-dash-nav-item <?php echo $is_active ? 'tap-dash-nav-active' : ''; ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
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

    /** Render an SVG icon. @internal SVG icon renderer used by render_sidebar only. */
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
            'users'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'chart'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
            'list'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
            'user'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'help'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'map'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>',
            'flag'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
            'clipboard'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/></svg>',
            'chat'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            'clock'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
            'award'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><polyline points="8.5 13 7 22 12 19 17 22 15.5 13"/></svg>',
            'megaphone'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 0 0 1 1h2l2 5h3l-1-5 9 4V5L10 9H4a1 1 0 0 0-1 1z"/></svg>',
            'link'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1"/></svg>',
            'receipt'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="9" y1="12" x2="15" y2="12"/></svg>',
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
    /* ── bookings CSV export ─────────────────────────────────────────── */

    public static function export_bookings_csv($agency_id) {
        global $wpdb;
        $agency_id = (int) $agency_id;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d ORDER BY created_at DESC",
            $agency_id
        ));
        $out = 'codigo,servicio,check_in,check_out,adultos,ninos,total,pago,estado,creado
';
        foreach ((array) $rows as $r) {
            $title = get_the_title((int) $r->service_id);
            $fields = [
                $r->booking_code,
                $title,
                $r->check_in,
                $r->check_out,
                (int) $r->adults,
                (int) $r->children,
                $r->total_amount,
                $r->payment_status,
                $r->status,
                $r->created_at,
            ];
            $out .= implode(',', array_map([__CLASS__, 'csv_cell'], $fields)) . "\n";
        }
        return $out;
    }

    public static function csv_cell($value) {
        $v = class_exists('TAP_Csv') ? TAP_Csv::cell($value) : (string) $value;
        if (false !== strpos($v, ',') || false !== strpos($v, '"') || false !== strpos($v, "\n")) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    public static function bookings_csv_download() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/mi-cuenta/')));
            exit;
        }
        $user_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $agency_id   = isset($_GET['agency']) ? (int) $_GET['agency'] : 0;
        $nonce       = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if (!$user_agency || $agency_id !== $user_agency || !wp_verify_nonce($nonce, 'tap_export_bookings_' . get_current_user_id())) {
            wp_die(__('Acceso denegado.', 'travel-agency-platform'), '', ['response' => 403]);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tap-bookings-' . (int) $agency_id . '.csv"');
        echo self::export_bookings_csv($agency_id);
        exit;
    }

    /* ── finances CSV export ─────────────────────────────────────────── */

    public static function export_finances_csv($agency_id) {
        global $wpdb;
        $agency_id = (int) $agency_id;
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE agency_id = %d ORDER BY created_at DESC",
            $agency_id
        ));
        $out = "id,monto,metodo,origen,nota,estado,fecha\n";
        foreach ((array) $history as $h) {
            $fields = [
                (int) $h->id,
                $h->amount,
                $h->method,
                ('agency' === ($h->source ?? 'admin')) ? 'Solicitud agencia' : 'Admin',
                $h->note,
                $h->status,
                $h->created_at,
            ];
            $out .= implode(',', array_map([__CLASS__, 'csv_cell'], $fields)) . "\n";
        }
        return $out;
    }

    public static function finances_csv_download() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/mi-cuenta/')));
            exit;
        }
        $user_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $agency_id   = isset($_GET['agency']) ? (int) $_GET['agency'] : 0;
        $nonce       = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if (!$user_agency || $agency_id !== $user_agency || !wp_verify_nonce($nonce, 'tap_export_finances_' . get_current_user_id())) {
            wp_die(__('Acceso denegado.', 'travel-agency-platform'), '', ['response' => 403]);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tap-finances-' . (int) $agency_id . '.csv"');
        echo self::export_finances_csv($agency_id);
        exit;
    }
}
