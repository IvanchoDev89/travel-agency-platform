<?php
/**
 * TAP_Dashboard_Content — admin pages of content: reviews, moderation,
 * agencies registry and agency column on listings.
 *
 * Extracted from TAP_Dashboard (god class split, Fase 2).
 */
defined('ABSPATH') || exit;

class TAP_Dashboard_Content {
    public static function reviews_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_reviews';

        if (isset($_POST['tap_review_reply_nonce']) && isset($_POST['review_id'])) {
            $rid = intval($_POST['review_id']);
            if ($rid && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tap_review_reply_nonce'])), 'tap_review_reply_' . $rid)) {
                $reply = sanitize_textarea_field(wp_unslash($_POST['reply'] ?? ''));
                $wpdb->update($table, [
                    'reply'        => $reply,
                    'reply_author' => wp_get_current_user()->display_name,
                    'reply_at'     => current_time('mysql'),
                ], ['id' => $rid]);
            }
        }

        if (isset($_GET['tap_action'], $_GET['review_id']) && isset($_GET['_wpnonce'])) {
            $action = sanitize_key(wp_unslash($_GET['tap_action']));
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'tap_review_' . $action . '_' . intval($_GET['review_id']))) {
                $rid = intval($_GET['review_id']);
                if ('approve' === $action) {
                    $wpdb->update($table, ['is_approved' => 1], ['id' => $rid]);
                } elseif ('unapprove' === $action) {
                    $wpdb->update($table, ['is_approved' => 0], ['id' => $rid]);
                } elseif ('delete' === $action) {
                    $wpdb->delete($table, ['id' => $rid]);
                }
                $status = sanitize_key(wp_unslash($_GET['status'] ?? 'all'));
                wp_safe_redirect(admin_url('admin.php?page=tap-reviews&status=' . rawurlencode($status)));
                exit;
            }
        }

        $filter = sanitize_key(wp_unslash($_GET['status'] ?? 'all'));
        $where  = 'all' === $filter ? '1=1' : 'r.is_approved = ' . ($filter === 'pending' ? '0' : '1');

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_approved = 0");
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name AS user_name
            FROM $table r
            JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT 100",
            []
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reviews', 'travel-agency-platform'); ?></h1>
            <div class="notice notice-info inline" style="margin:10px 0;">
                <p><?php printf(/* translators: %d: número de reseñas pendientes de aprobación */ esc_html__('Reseñas pendientes de aprobación: %d', 'travel-agency-platform'), $pending); ?> —
                <?php esc_html_e('Solo las aprobadas se muestran en el sitio público.', 'travel-agency-platform'); ?></p>
            </div>
            <nav class="nav-tab-wrapper">
                <?php
                foreach (['all' => __('Todas', 'travel-agency-platform'), 'pending' => __('Pendientes', 'travel-agency-platform'), 'approved' => __('Aprobadas', 'travel-agency-platform')] as $k => $label):
                    $class = $filter === $k ? ' nav-tab-active' : '';
                    ?>
                    <a class="nav-tab<?php echo esc_attr($class); ?>" href="<?php echo esc_url(admin_url('admin.php?page=tap-reviews&status=' . $k)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:110px;"><?php esc_html_e('Rating', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reseña', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Servicio', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Usuario', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$reviews): ?>
                    <tr><td colspan="6"><em><?php esc_html_e('No hay reseñas.', 'travel-agency-platform'); ?></em></td></tr>
                    <?php endif; ?>
                    <?php
                    foreach ($reviews as $r):
                        $service = get_post($r->service_id);
						?>
                    <tr>
                        <td><span class="tap-admin-stars" style="color:#f59e0b;"><?php echo str_repeat('★', intval($r->rating)); ?></span> <span class="description"><?php echo esc_html($r->rating); ?></span></td>
                        <td>
                            <?php if ($r->title): ?><strong><?php echo esc_html($r->title); ?></strong><br><?php endif; ?>
                            <?php echo esc_html($r->content); ?>
                        </td>
                        <td><?php echo $service ? esc_html($service->post_title) : esc_html($r->service_type . ' #' . $r->service_id); ?></td>
                        <td><?php echo esc_html($r->user_name); ?> <span class="description">#<?php echo (int) $r->user_id; ?></span><?php if ((int) $r->is_verified): ?> <span class="tap-verified-badge" title="<?php esc_attr_e('Basada en una reserva confirmada', 'travel-agency-platform'); ?>">&#10003; <?php esc_html_e('Verificada', 'travel-agency-platform'); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td>
                            <?php if (!$r->is_approved): ?>
                                <a class="button button-primary button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=approve&review_id=' . $r->id . '&status=' . $filter), 'tap_review_approve_' . $r->id)); ?>"><?php esc_html_e('Aprobar', 'travel-agency-platform'); ?></a>
                            <?php else: ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=unapprove&review_id=' . $r->id . '&status=' . $filter), 'tap_review_unapprove_' . $r->id)); ?>"><?php esc_html_e('Desaprobar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <a class="button button-link-delete button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tap-reviews&tap_action=delete&review_id=' . $r->id . '&status=' . $filter), 'tap_review_delete_' . $r->id)); ?>" onclick="return confirm('Delete this review?');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;"></td>
                        <td colspan="5" style="background:#f9fafb;">
                            <details>
                                <summary style="cursor:pointer;font-weight:600;color:#0a84ff;">
                                    <?php $r->reply ? esc_html_e('Ver respuesta de la agencia', 'travel-agency-platform') : esc_html_e('Responder a esta reseña', 'travel-agency-platform'); ?>
                                </summary>
                                <?php if ($r->reply): ?>
                                    <blockquote style="margin:10px 0;padding:10px 14px;border-left:4px solid #0a84ff;background:#fff;">
                                        <?php echo nl2br(esc_html($r->reply)); ?>
                                        <div class="description" style="margin-top:6px;color:#64748b;">
                                            <?php echo esc_html((string) $r->reply_author) . ' · ' . esc_html((string) $r->reply_at); ?>
                                        </div>
                                    </blockquote>
                                <?php endif; ?>
                                <form method="post" style="margin-top:8px;">
                                    <input type="hidden" name="review_id" value="<?php echo (int) $r->id; ?>">
                                    <?php wp_nonce_field('tap_review_reply_' . $r->id, 'tap_review_reply_nonce'); ?>
                                    <textarea name="reply" rows="3" placeholder="<?php esc_attr_e('Escribe tu respuesta como agencia…', 'travel-agency-platform'); ?>" class="large-text"><?php echo esc_textarea($r->reply ?? ''); ?></textarea>
                                    <button class="button button-primary"><?php esc_html_e('Guardar respuesta', 'travel-agency-platform'); ?></button>
                                </form>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Fase 4 — moderation queue (auto-flagged content needing human review). */

    public static function moderation_page() {
        $items = TAP_Moderation::queue();
        $blocked = ($GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}tap_reviews WHERE mod_status = 'block'")) +
                    (int) ($GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}tap_leads WHERE mod_status = 'block'"));
        $reviewed = (int) ($GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}tap_reviews WHERE mod_status = 'review'")) +
                    (int) ($GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}tap_leads WHERE mod_status = 'review'"));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Moderación de contenido', 'travel-agency-platform'); ?></h1>
            <div class="notice notice-info inline" style="margin:10px 0;">
                <p>
                    <?php printf(/* translators: 1: elementos puestos en revisión, 2: elementos bloqueados */ esc_html__('Reseñas y mensajes marcados automáticamente: %d en revisión, %d bloqueados.', 'travel-agency-platform'), $reviewed, $blocked); ?>
                    — <?php esc_html_e('Aprobar publica la reseña / limpia el mensaje; bloquear los oculta; eliminar los borra.', 'travel-agency-platform'); ?>
                </p>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Contenido', 'travel-agency-platform'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Motivo', 'travel-agency-platform'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th style="width:250px;"><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$items): ?>
                    <tr><td colspan="5"><em><?php esc_html_e('No hay contenido en la cola de moderación.', 'travel-agency-platform'); ?></em></td></tr>
                    <?php endif; ?>
                    <?php
                    foreach ($items as $it):
                        $is_review = 'review' === $it->kind;
                        $base = admin_url('admin.php?page=tap-moderation&kind=' . ($is_review ? 'review' : 'lead') . '&item_id=' . (int) $it->id);
                        $aw = wp_nonce_url($base . '&mod_action=approve', 'tap_mod_approve_' . ($is_review ? 'review' : 'lead') . '_' . (int) $it->id);
                        $bl = wp_nonce_url($base . '&mod_action=block', 'tap_mod_block_' . ($is_review ? 'review' : 'lead') . '_' . (int) $it->id);
                        $dl = wp_nonce_url($base . '&mod_action=delete', 'tap_mod_delete_' . ($is_review ? 'review' : 'lead') . '_' . (int) $it->id);
						?>
                    <tr>
                        <td><span class="dashicons <?php echo $is_review ? 'dashicons-star-filled' : 'dashicons-email-alt'; ?>"></span> <?php echo $is_review ? esc_html__('Reseña', 'travel-agency-platform') : esc_html__('Mensaje', 'travel-agency-platform'); ?></td>
                        <td>
                            <?php if ($it->rating > 0): ?><span class="tap-admin-stars" style="color:#f59e0b;"><?php echo str_repeat('★', (int) $it->rating); ?></span> <?php endif; ?>
                            <?php echo esc_html(mb_substr((string) $it->content, 0, 160)); ?>
                            <div class="description" style="color:#64748b;"><?php echo esc_html((string) $it->owner); ?></div>
                        </td>
                        <td>
                            <span class="description" style="font-size:12px;"><?php echo esc_html(TAP_Moderation::reason_label($it->mod_reason)); ?></span>
                            <div class="description"><?php echo esc_html((string) $it->mod_status); ?></div>
                        </td>
                        <td class="description"><?php echo esc_html((string) $it->created_at); ?></td>
                        <td>
                            <a class="button button-primary button-small" href="<?php echo esc_url($aw); ?>"><?php esc_html_e('Aprobar', 'travel-agency-platform'); ?></a>
                            <a class="button button-small" href="<?php echo esc_url($bl); ?>"><?php esc_html_e('Bloquear', 'travel-agency-platform'); ?></a>
                            <a class="button button-link-delete button-small" href="<?php echo esc_url($dl); ?>" onclick="return confirm('Delete?');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    public static function agencies_page() {
        global $wpdb;
        self::sync_agency_registry();

        if (isset($_GET['tap_action'], $_GET['agency_id'], $_GET['_wpnonce'])) {
            $aid   = intval($_GET['agency_id']);
            $nonce = sanitize_key(wp_unslash($_GET['tap_action'])) . '_' . $aid;
            if ($aid && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce)) {
                self::handle_agency_action(sanitize_key(wp_unslash($_GET['tap_action'])), $aid, $_GET);
                wp_safe_redirect(admin_url('admin.php?page=tap-agencies'));
                exit;
            }
        }

        $agencies = get_posts([
            'post_type'  => 'tap_agency',
            'post_status' => ['publish', 'trash'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $visible = array_values(array_filter($agencies, function ($a) {
			return 'trash' !== $a->post_status;
		}));

        $verified = 0;
        $active   = 0;
        $pending  = 0;
        foreach ($visible as $a) {
            if ('1' === get_post_meta($a->ID, '_tap_agency_verified', true)) $verified++;
            if ('0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1')) $active++;
            if (TAP_Approval::is_pending($a->ID)) $pending++;
        }
        $rows_rev = $wpdb->get_results("SELECT agency_id, COUNT(*) AS c, SUM(total_amount) AS rev, SUM(" . TAP_Booking_Stats::commission_case() . ") AS comm FROM {$wpdb->prefix}tap_bookings WHERE status NOT IN ('cancelled','refunded') GROUP BY agency_id");
        $revenue_map = [];
        foreach ($rows_rev as $r) { $revenue_map[(int) $r->agency_id] = $r; }
        $listing_keys = array_values(TAP_Post_Types::agency_meta_keys());
        $listing_ph   = implode(',', array_fill(0, count($listing_keys), '%s'));
        $listing_count = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS c FROM {$wpdb->postmeta}
            WHERE meta_key IN ($listing_ph)
            GROUP BY meta_value",
            $listing_keys
        ));
        $listings_map = [];
        foreach ($listing_count as $l) { $listings_map[(int) $l->meta_value] = (int) $l->c; }
        $total_commission = $wpdb->get_var("SELECT SUM(" . TAP_Booking_Stats::commission_case() . ") FROM {$wpdb->prefix}tap_bookings WHERE status NOT IN ('cancelled','refunded')");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Agencies', 'travel-agency-platform'); ?></h1>
            <div class="notice notice-info inline" style="margin:10px 0;">
                <p>
                <?php
                    /* translators: 1: total de agencias, 2: pendientes de verificación, 3: verificadas, 4: activas, 5: comisiones acumuladas */
                    printf(esc_html__('Total: %1$d · Pendientes: %2$d · Verificadas: %3$d · Activas: %4$d · Comisiones acumuladas: %5$s', 'travel-agency-platform'),
                        count($visible), $pending, $verified, $active, esc_html(TAP_Currency::fmt((float) $total_commission)));
				?>
                    <span style="opacity:.7"> — <?php esc_html_e('El registro se sincroniza automáticamente al abrir esta página.', 'travel-agency-platform'); ?></span>
                </p>
            </div>
            <?php
            $paged    = max(1, intval($_GET['paged'] ?? 1));
            $per_page = 20;
            $page_set = array_slice($visible, ($paged - 1) * $per_page, $per_page);
            echo TAP_Dashboard::paginate(count($visible), $per_page, $paged);
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Contacto', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Listados', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Reservas', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Comisión', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acciones', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($page_set as $a):
                        if ('trash' === $a->post_status) continue;
                        $is_verified = '1' === get_post_meta($a->ID, '_tap_agency_verified', true);
                        $is_active = '0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1');
                        $commission = get_post_meta($a->ID, '_tap_agency_commission', true);
                        $commission = '' === (string) $commission ? '10' : $commission;
                        $uid   = get_post_meta($a->ID, '_tap_agency_user_id', true);
                        $u     = $uid ? get_userdata($uid) : null;
                        $email = get_post_meta($a->ID, '_tap_agency_email', true);
                        if (!$email && $u) $email = $u->user_email;
                        $rev      = $revenue_map[$a->ID] ?? null;
                        $listings = $listings_map[$a->ID] ?? 0;
                        $verify_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=' . ($is_verified ? 'unverify' : 'verify') . '&agency_id=' . $a->ID), ($is_verified ? 'unverify' : 'verify') . '_' . $a->ID);
                        $active_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=' . ($is_active ? 'deactivate' : 'activate') . '&agency_id=' . $a->ID), ($is_active ? 'deactivate' : 'activate') . '_' . $a->ID);
                        $delete_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=delete&agency_id=' . $a->ID), 'delete_' . $a->ID);
                        $approve_url = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=approve&agency_id=' . $a->ID), 'approve_' . $a->ID);
                        $reject_url  = wp_nonce_url(admin_url('admin.php?page=tap-agencies&tap_action=reject&agency_id=' . $a->ID), 'reject_' . $a->ID);
                        $status = TAP_Approval::status($a->ID);
                        $kyc    = TAP_Approval::kyc($a->ID);
						?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($a->post_title); ?></strong>
                            <?php if ($is_verified): ?><span class="dashicons dashicons-yes" style="color:#16a34a;" title="<?php esc_attr_e('Verificada', 'travel-agency-platform'); ?>"></span><?php endif; ?>
                            <div class="description"><?php echo esc_html($a->post_excerpt ?: ('#' . $a->ID)); ?></div>
                            <?php if (!empty($kyc['legal_name']) || !empty($kyc['doc_number'])): ?>
                                <div class="description" style="color:#57534e;font-size:11px;">
                                    <?php esc_html_e('KYC:', 'travel-agency-platform'); ?>
                                    <?php echo esc_html($kyc['legal_name']); ?>
                                    <?php if (!empty($kyc['doc_type'])): ?>· <?php echo esc_html(('juridica' === $kyc['doc_type']) ? __('Jurídica', 'travel-agency-platform') : __('Física', 'travel-agency-platform')); ?><?php endif; ?>
                                    <?php if (!empty($kyc['doc_number'])): ?>· <?php echo esc_html($kyc['doc_number']); ?><?php endif; ?>
                                    <?php if (!empty($kyc['legal_tax_id'])): ?>· <?php esc_html_e('C. jurídica', 'travel-agency-platform'); ?> <?php echo esc_html($kyc['legal_tax_id']); ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ($u): echo esc_html($u->display_name) . ' <span class="description">@' . esc_html($u->user_login) . '</span><br>';
endif;
							?>
                            <?php echo esc_html($email); ?>
                        </td>
                        <td><?php echo (int) $listings; ?></td>
                        <td>
                            <?php if ($rev): ?>
                                <?php echo (int) $rev->c; ?> · <strong><?php echo esc_html(TAP_Currency::fmt((float) $rev->rev)); ?></strong><br>
                                <span class="description"><?php esc_html_e('comisión', 'travel-agency-platform'); ?> <?php echo esc_html(TAP_Currency::fmt((float) $rev->comm)); ?></span>
								<?php
                            else: echo '—';
endif;
							?>
                        </td>
                        <td>
                            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline-flex;gap:4px;align-items:center;">
                                <input type="hidden" name="page" value="tap-agencies">
                                <input type="hidden" name="tap_action" value="commission">
                                <input type="hidden" name="agency_id" value="<?php echo (int) $a->ID; ?>">
                                <?php wp_nonce_field('commission_' . $a->ID); ?>
                                <input type="number" name="commission" value="<?php echo esc_attr($commission); ?>" min="0" max="100" step="0.01" style="width:70px;">
                                <button class="button button-small">%</button>
                            </form>
                        </td>
                        <td>
                            <?php if (TAP_Approval::APPROVED === $status): ?>
                                <span style="color:#16a34a;"><?php esc_html_e('Aprobada', 'travel-agency-platform'); ?></span>
                            <?php elseif (TAP_Approval::REJECTED === $status): ?>
                                <span style="color:#dc2626;"><?php esc_html_e('Rechazada', 'travel-agency-platform'); ?></span>
                            <?php else: ?>
                                <span style="color:#d97706;"><?php esc_html_e('Pendiente', 'travel-agency-platform'); ?></span>
                            <?php endif; ?>
                            <br>
                            <?php if ($is_verified): ?>
                                <span class="dashicons dashicons-shield" style="color:#0d9488;vertical-align:middle;"></span> <?php esc_html_e('Verificada', 'travel-agency-platform'); ?>
                            <?php else: ?><span style="color:#dc2626;"><?php esc_html_e('No verificada', 'travel-agency-platform'); ?></span><?php endif; ?>
                            <br>
                            <?php if ($is_active): ?><span style="color:#16a34a;"><?php esc_html_e('Activa', 'travel-agency-platform'); ?></span><?php else: ?><span style="color:#64748b;"><?php esc_html_e('Desactivada', 'travel-agency-platform'); ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (TAP_Approval::APPROVED !== $status): ?>
                                <a class="button button-primary button-small" href="<?php echo esc_url($approve_url); ?>"><?php esc_html_e('Aprobar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <?php if (TAP_Approval::REJECTED !== $status): ?>
                                <a class="button button-small" href="<?php echo esc_url($reject_url); ?>" onclick="return confirm('<?php echo esc_js(__('Rechazar esta agencia y ocultarla del sitio?', 'travel-agency-platform')); ?>');"><?php esc_html_e('Rechazar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <?php if (TAP_Approval::APPROVED === $status): ?>
                                <a class="button button-small" href="<?php echo esc_url($verify_url); ?>"><?php echo $is_verified ? esc_html__('Quitar verificación', 'travel-agency-platform') : esc_html__('Verificar', 'travel-agency-platform'); ?></a>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url($active_url); ?>"><?php echo $is_active ? esc_html__('Desactivar', 'travel-agency-platform') : esc_html__('Activar', 'travel-agency-platform'); ?></a>
                            <a class="button button-link-delete button-small" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Borrar esta agencia y desvincular sus listados?');"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo TAP_Dashboard::paginate(count($visible), $per_page, $paged); ?>
        </div>
        <?php
    }


    private static function handle_agency_action($action, $agency_id, $params) {
        // Tanda C (C15): defense in depth — only platform managers may act on
        // agencies, even if this method is ever reached outside the admin page.
        if (!current_user_can('tap_manage_agencies')) {
            wp_die(esc_html__('No tienes permiso para realizar esta acción.', 'travel-agency-platform'));
        }
        $wpdb = $GLOBALS['wpdb'];
        switch ($action) {
            case 'verify':
                if (TAP_Approval::is_approved($agency_id)) {
                    update_post_meta($agency_id, '_tap_agency_verified', '1');
                }
                break;
            case 'unverify':
                update_post_meta($agency_id, '_tap_agency_verified', '');
                break;
            case 'approve':
                TAP_Approval::approve($agency_id);
                break;
            case 'reject':
                TAP_Approval::reject($agency_id);
                break;
            case 'activate':
                update_post_meta($agency_id, '_tap_agency_is_active', '1');
                break;
            case 'deactivate':
                update_post_meta($agency_id, '_tap_agency_is_active', '0');
                break;
            case 'commission':
                $c = isset($params['commission']) ? floatval($params['commission']) : 0;
                update_post_meta($agency_id, '_tap_agency_commission', $c);
                break;
            case 'delete':
                $listing_types = TAP_Post_Types::agency_meta_keys();
                $listing_ids = get_posts(['post_type' => array_keys($listing_types), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['relation' => 'OR', ...array_map(fn($mk) => ['key' => $mk, 'value' => $agency_id], array_values($listing_types))]]]);
                foreach ($listing_ids as $lid) {
                    foreach ($listing_types as $mk) {
                        if ((string) get_post_meta($lid, $mk, true) === (string) $agency_id) {
                            delete_post_meta($lid, $mk);
                        }
                    }
                }
                $uid = get_post_meta($agency_id, '_tap_agency_user_id', true);
                // Tanda C (C7): a deleted agency must not keep an approved
                // record (a restore would skip review) nor its verification flag.
                TAP_Approval::pending($agency_id);
                delete_post_meta($agency_id, '_tap_agency_verified');
                if ($uid) {
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}tap_agencies SET is_active = 0 WHERE user_id = %d", $uid));
                    // Owner loses the agency-admin role with the agency.
                    $owner = get_user_by('id', (int) $uid);
                    if ($owner) {
                        $owner->remove_role('tap_agency_admin');
                        if (!array_intersect(['tap_client', 'subscriber', 'tap_agency_employee', 'tap_agency_admin'], (array) $owner->roles)) {
                            $owner->add_role(get_role('tap_client') ? 'tap_client' : 'subscriber');
                        }
                    }
                }
                // Staff mapped through _tap_agency_id keep agency access after
                // the post is gone (get_post_type() still matches in trash):
                // drop the mapping and the employee role with it.
                foreach (TAP_Booking::get_staff_for_agency($agency_id) as $staff_user) {
                    delete_user_meta($staff_user->ID, '_tap_agency_id');
                    $staff_user->remove_role('tap_agency_employee');
                    if (!array_intersect(['tap_client', 'subscriber', 'tap_agency_admin'], (array) $staff_user->roles)) {
                        $staff_user->add_role(get_role('tap_client') ? 'tap_client' : 'subscriber');
                    }
                }
                wp_trash_post($agency_id);
                break;
        }
        self::sync_agency_registry();
    }


    private static function sync_agency_registry() {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_agencies';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) return;

        $agencies = get_posts(['post_type' => 'tap_agency', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($agencies as $a) {
            $uid     = (int) get_post_meta($a->ID, '_tap_agency_user_id', true);
            $email   = get_post_meta($a->ID, '_tap_agency_email', true);
            $phone   = get_post_meta($a->ID, '_tap_agency_phone', true);
            $whats   = get_post_meta($a->ID, '_tap_agency_whatsapp', true);
            $web     = get_post_meta($a->ID, '_tap_agency_website', true);
            $addr    = get_post_meta($a->ID, '_tap_agency_address', true);
            $city    = get_post_meta($a->ID, '_tap_agency_city', true);
            $country = get_post_meta($a->ID, '_tap_agency_country', true);
            $comm    = get_post_meta($a->ID, '_tap_agency_commission', true);
            $comm    = '' === (string) $comm ? 10 : (float) $comm;
            $isv     = '1' === get_post_meta($a->ID, '_tap_agency_verified', true) ? 1 : 0;
            $isa     = '0' !== get_post_meta($a->ID, '_tap_agency_is_active', '1') ? 1 : 0;

            if (!$uid) {
                $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE slug = %s OR name = %s", $a->post_name, $a->post_title));
            }
            if (!$uid) continue;

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d", $uid));
            $data = [
                'name' => $a->post_title,
                'slug' => $a->post_name,
                'description' => $a->post_excerpt ?: '',
                'email' => $email,
                'phone' => $phone,
                'whatsapp' => $whats,
                'website' => $web,
                'address' => $addr,
                'city' => $city,
                'country' => $country,
                'commission_percent' => $comm,
                'is_verified' => $isv,
                'is_active' => $isa,
                'user_id' => $uid,
            ];
            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }


    /**
     * Tanda D: keep the tap_agencies registry free of orphans. The admin
     * "delete" action only deactivates + trashes the post, but a hard delete
     * (permanent delete, WP-CLI, programmatic wp_delete_post) used to leave the
     * registry row behind forever. Drop it when the agency post is destroyed.
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     */
    public static function on_agency_deleted($post_id, $post = null) {
        $post = $post ?: get_post($post_id);
        if (!$post || 'tap_agency' !== $post->post_type) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tap_agencies';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
            return;
        }
        $uid = (int) get_post_meta($post_id, '_tap_agency_user_id', true);
        if ($uid) {
            $wpdb->delete($table, ['user_id' => $uid]);
        }
        // No owner mapping (legacy/imported rows): fall back to the slug.
        if (!empty($post->post_name)) {
            $wpdb->delete($table, ['slug' => $post->post_name]);
        }
    }


    public static function add_agency_column($columns) {
        $columns['agency'] = __('Agency', 'travel-agency-platform');
        return $columns;
    }


    public static function render_agency_column($column, $post_id) {
        if ($column !== 'agency') return;
        $post_type = get_post_type($post_id);
        $prefix = '_tap_' . TAP_Post_Types::meta_prefix($post_type) . '_agency_id';
        $agency_id = get_post_meta($post_id, $prefix, true);
        if ($agency_id) {
            $agency = get_post($agency_id);
            echo $agency ? esc_html($agency->post_title) : 'N/A';
        } else {
            echo '—';
        }
    }
}

