<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Community forum: posts (tips/questions/photos) per destination.
 */
class TAP_Forum {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_forum_posts';
    }

    /** Post types. */
    public static function post_types() {
        return [
            'tip'   => __('Tip', 'travel-agency-platform'),
            'question' => __('Pregunta', 'travel-agency-platform'),
            'photo' => __('Foto', 'travel-agency-platform'),
        ];
    }

    /** Submit a post. Returns id or WP_Error. */
    public static function submit($destination_id, $user_id, $post_type, $title, $content, $image_id = 0, $author_name = '') {
        global $wpdb;
        $post_type = sanitize_key($post_type);
        if (!isset(self::post_types()[$post_type])) $post_type = 'tip';
        $content = sanitize_textarea_field($content);
        if ('' === $content) {
            return new WP_Error('empty', __('El contenido es obligatorio.', 'travel-agency-platform'));
        }
        $hit = TAP_Moderation::assess($title . ' ' . $content, 'review');
        if (TAP_Moderation::BLOCK === $hit['status']) {
            return new WP_Error('blocked', __('Tu publicación no pasó los controles de seguridad.', 'travel-agency-platform'));
        }
        $wpdb->insert(self::table(), [
            'destination_id' => (int) $destination_id,
            'user_id'        => (int) $user_id,
            'author_name'    => $author_name ? sanitize_text_field($author_name) : null,
            'post_type'      => $post_type,
            'title'          => $title ? sanitize_text_field($title) : null,
            'content'        => $content,
            'image_id'       => $image_id ? (int) $image_id : null,
            'status'         => 'pending',
            'mod_status'     => $hit['status'],
            'mod_reason'     => $hit['reason'],
        ]);
        return (int) $wpdb->insert_id;
    }

    /** List approved posts for a destination. */
    public static function for_destination($destination_id, $limit = 20) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE destination_id = %d AND status = 'approved' ORDER BY created_at DESC LIMIT %d",
            (int) $destination_id, (int) $limit
        ));
    }

    /** Approve a post (admin). */
    public static function approve($id) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), ['status' => 'approved'], ['id' => (int) $id], ['%s'], ['%d']);
    }

    /** Reject a post (admin). */
    public static function reject($id) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), ['status' => 'rejected'], ['id' => (int) $id], ['%s'], ['%d']);
    }

    /** Like a post. */
    public static function like($id) {
        global $wpdb;
        $t = self::table();
        return (bool) $wpdb->query($wpdb->prepare("UPDATE {$t} SET likes = likes + 1 WHERE id = %d", (int) $id));
    }

    /** Render forum for a destination (shortcode). */
    public static function render($atts) {
        $atts = shortcode_atts(['destination_id' => 0, 'limit' => 20], $atts);
        $dest_id = (int) $atts['destination_id'];
        if (!$dest_id) {
            $dest_id = (int) get_queried_object_id();
        }
        $posts = self::for_destination($dest_id, (int) $atts['limit']);
        ob_start();
        ?>
        <div class="tap-forum" data-destination="<?php echo (int) $dest_id; ?>">
            <h3><?php esc_html_e('Comunidad del destino', 'travel-agency-platform'); ?></h3>
            <?php if (!$posts): ?>
                <p class="tap-agency-meta"><?php esc_html_e('Aún no hay publicaciones. ¡Sé el primero en compartir un tip!', 'travel-agency-platform'); ?></p>
            <?php else: ?>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($posts as $p): ?>
                        <article style="border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);padding:12px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="font-size:11px;padding:2px 8px;border-radius:999px;background:var(--tap-primary-bg);color:var(--tap-primary-dark);font-weight:700;"><?php echo esc_html(self::post_types()[$p->post_type] ?? $p->post_type); ?></span>
                                <strong><?php echo esc_html($p->author_name ?: __('Viajero', 'travel-agency-platform')); ?></strong>
                                <small style="color:var(--tap-neutral-400);margin-left:auto;"><?php echo esc_html($p->created_at); ?></small>
                            </div>
                            <?php if ($p->title): ?><h4 style="margin:0 0 4px;"><?php echo esc_html($p->title); ?></h4><?php endif; ?>
                            <p style="margin:0 0 8px;color:var(--tap-neutral-700);"><?php echo esc_html($p->content); ?></p>
                            <?php if ($p->image_id): $iu = wp_get_attachment_image_url($p->image_id, 'medium'); if ($iu): ?>
                                <img src="<?php echo esc_url($iu); ?>" alt="" loading="lazy" style="max-width:100%;border-radius:var(--tap-radius-md);margin-bottom:8px;">
									<?php
                            endif;
endif;
							?>
                            <button type="button" class="tap-btn tap-btn-sm tap-forum-like" data-id="<?php echo (int) $p->id; ?>">👍 <?php echo (int) $p->likes; ?></button>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (is_user_logged_in()): ?>
                <form class="tap-forum-form" style="margin-top:16px;display:grid;gap:8px;">
                    <input type="hidden" name="destination_id" value="<?php echo (int) $dest_id; ?>">
                    <select name="post_type" style="padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);">
                        <?php foreach (self::post_types() as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="title" placeholder="<?php esc_attr_e('Título (opcional)', 'travel-agency-platform'); ?>" style="padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);">
                    <textarea name="content" rows="3" placeholder="<?php esc_attr_e('Comparte un tip, pregunta o experiencia…', 'travel-agency-platform'); ?>" required style="padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);"></textarea>
                    <button type="submit" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Publicar', 'travel-agency-platform'); ?></button>
                    <span class="tap-forum-msg" style="font-size:var(--tap-text-sm);"></span>
                </form>
                <script>
                (function(){
                    var form = document.querySelector('.tap-forum-form');
                    if (!form) return;
                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        var fd = new FormData(form);
                        fd.append('action', 'tap_forum_submit');
                        fd.append('nonce', (window.tapDash && window.tapDash.nonce) || (window.tapAgencyWizard && window.tapAgencyWizard.listingNonce) || '');
                        fetch((window.tapDash && window.tapDash.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(j){
                            var msg = form.querySelector('.tap-forum-msg');
                            if (msg) msg.textContent = (j && j.data && j.data.message) || '';
                            if (j && j.success) { form.reset(); setTimeout(function(){ location.reload(); }, 800); }
                        });
                    });
                    document.querySelectorAll('.tap-forum-like').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            var fd = new FormData();
                            fd.append('action', 'tap_forum_like');
                            fd.append('id', btn.dataset.id || btn.getAttribute('data-id'));
                            fd.append('nonce', (window.tapDash && window.tapDash.nonce) || (window.tapAgencyWizard && window.tapAgencyWizard.listingNonce) || '');
                            fetch((window.tapDash && window.tapDash.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                            .then(function(){ var n = parseInt(btn.textContent.replace(/[^0-9]/g,''),10)||0; btn.textContent = '👍 ' + (n+1); });
                        });
                    });
                })();
                </script>
            <?php else: ?>
                <p class="tap-agency-meta"><?php esc_html_e('Inicia sesión para participar.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Init: register shortcode + AJAX. */
    public static function init() {
        add_shortcode('tap_forum', [__CLASS__, 'render']);
        add_action('wp_ajax_tap_forum_submit', [__CLASS__, 'ajax_submit']);
        add_action('wp_ajax_nopriv_tap_forum_submit', [__CLASS__, 'ajax_submit']);
        add_action('wp_ajax_tap_forum_like', [__CLASS__, 'ajax_like']);
        add_action('wp_ajax_nopriv_tap_forum_like', [__CLASS__, 'ajax_like']);
    }

    public static function ajax_submit() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Inicia sesión para publicar.', 'travel-agency-platform')], 401);
        }
        global $wpdb;
        $dest_id  = absint($_POST['destination_id'] ?? 0);
        $type     = sanitize_key(wp_unslash($_POST['post_type'] ?? 'tip'));
        $title    = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $content  = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        $user     = wp_get_current_user();
        $res = self::submit($dest_id, $user->ID, $type, $title, $content, 0, $user->display_name);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], 400);
        }
        // Auto-approve if moderation passed.
        $mod = $wpdb->get_var($wpdb->prepare("SELECT mod_status FROM {$wpdb->prefix}tap_forum_posts WHERE id = %d", $res));
        if ($mod === 'ok' || $mod === 'review') {
            self::approve($res);
        }
        wp_send_json_success(['message' => __('Publicación enviada.', 'travel-agency-platform'), 'id' => $res]);
    }

    public static function ajax_like() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => __('ID inválido.', 'travel-agency-platform')], 400);
        }
        self::like($id);
        wp_send_json_success(['ok' => true]);
    }
}
