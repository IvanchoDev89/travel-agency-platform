<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Collaborative itineraries: share, vote, comment.
 */
class TAP_Itinerary_Collab {

    public static function table_itineraries() {
		global $wpdb;
		return $wpdb->prefix . 'tap_itineraries'; }
    public static function table_votes() {
		global $wpdb;
		return $wpdb->prefix . 'tap_itinerary_votes'; }
    public static function table_comments() {
		global $wpdb;
		return $wpdb->prefix . 'tap_itinerary_comments'; }

    /** Create a shareable itinerary. */
    public static function create($user_id, $title, array $service_ids) {
        global $wpdb;
        $token = wp_generate_password(16, false, false);
        $wpdb->insert(self::table_itineraries(), [
            'user_id'     => (int) $user_id,
            'title'       => sanitize_text_field($title ?: __('Mi itinerario', 'travel-agency-platform')),
            'service_ids' => wp_json_encode(array_values(array_map('intval', $service_ids))),
            'share_token'  => $token,
            'is_public'   => 1,
        ]);
        return (int) $wpdb->insert_id ? ['id' => (int) $wpdb->insert_id, 'token' => $token] : null;
    }

    /** Get itinerary by token. */
    public static function by_token($token) {
        global $wpdb;
        $t = self::table_itineraries();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE share_token = %s", sanitize_text_field($token)));
    }

    /** Get itinerary by id. */
    public static function get($id) {
        global $wpdb;
        $t = self::table_itineraries();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
    }

    /** List itineraries for a user. */
    public static function for_user($user_id) {
        global $wpdb;
        $t = self::table_itineraries();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id = %d ORDER BY created_at DESC", (int) $user_id));
    }

    /** Add a vote. */
    public static function vote($itinerary_id, $service_id, $voter_email, $voter_name = '', $vote = 1) {
        global $wpdb;
        $t = self::table_votes();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE itinerary_id = %d AND service_id = %d AND voter_email = %s",
            (int) $itinerary_id, (int) $service_id, sanitize_email($voter_email)
        ));
        if ($existing) {
            $wpdb->update($t, ['vote' => (int) $vote], ['id' => (int) $existing], ['%d'], ['%d']);
            return (int) $existing;
        }
        $wpdb->insert($t, [
            'itinerary_id' => (int) $itinerary_id,
            'service_id'   => (int) $service_id,
            'voter_email'  => sanitize_email($voter_email),
            'voter_name'   => sanitize_text_field($voter_name),
            'vote'         => (int) $vote,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Get votes for an itinerary. */
    public static function votes($itinerary_id) {
        global $wpdb;
        $t = self::table_votes();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE itinerary_id = %d", (int) $itinerary_id));
    }

    /** Add a comment. */
    public static function add_comment($itinerary_id, $user_id, $author_name, $comment) {
        global $wpdb;
        $wpdb->insert(self::table_comments(), [
            'itinerary_id' => (int) $itinerary_id,
            'user_id'      => (int) $user_id,
            'author_name'  => sanitize_text_field($author_name),
            'comment'      => sanitize_textarea_field($comment),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Get comments for an itinerary. */
    public static function comments($itinerary_id) {
        global $wpdb;
        $t = self::table_comments();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE itinerary_id = %d ORDER BY created_at ASC", (int) $itinerary_id));
    }

    /** Init: register shortcode + REST. */
    public static function init() {
        add_shortcode('tap_itinerary_share', [__CLASS__, 'render_share']);
        add_action('rest_api_init', function () {
            register_rest_route('tap/v1', '/itinerary/(?P<token>[a-zA-Z0-9]+)', [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'rest_get'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('tap/v1', '/itinerary/vote', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_vote'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('tap/v1', '/itinerary/comment', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_comment'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function rest_get($request) {
        $token = $request->get_param('token');
        $itin = self::by_token($token);
        if (!$itin) {
            return new WP_Error('not_found', __('Itinerario no encontrado.', 'travel-agency-platform'), ['status' => 404]);
        }
        $ids = json_decode($itin->service_ids, true) ?: [];
        $services = [];
        foreach ($ids as $sid) {
            $p = get_post((int) $sid);
            if ($p) $services[] = ['id' => (int) $sid, 'title' => $p->post_title, 'url' => get_permalink($p)];
        }
        $votes = self::votes((int) $itin->id);
        $comments = self::comments((int) $itin->id);
        return rest_ensure_response([
            'id'       => (int) $itin->id,
            'title'    => $itin->title,
            'services' => $services,
            'votes'    => $votes,
            'comments' => $comments,
        ]);
    }



    /** Simple per-IP rate limiter using transients. Returns true if allowed. */
    private static function rate_limit($key, $max, $window) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $transient = 'tap_rl_' . md5($key . '|' . $ip);
        $count = (int) get_transient($transient);
        if ($count >= $max) {
            return false;
        }
        set_transient($transient, $count + 1, $window);
        return true;
    }

    public static function rest_vote($request) {
        if (!self::rate_limit('itin_vote', 20, 60)) {
            return new WP_Error('rate_limited', __('Demasiadas solicitudes. Intenta más tarde.', 'travel-agency-platform'), ['status' => 429]);
        }
        $params = $request->get_params();
        $token  = sanitize_text_field($params['token'] ?? '');
        $itin   = self::by_token($token);
        if (!$itin) return new WP_Error('not_found', __('Itinerario no encontrado.', 'travel-agency-platform'), ['status' => 404]);
        $service_id = absint($params['service_id'] ?? 0);
        $email      = sanitize_email($params['email'] ?? '');
        $name       = sanitize_text_field($params['name'] ?? '');
        $vote       = (int) ($params['vote'] ?? 1);
        if (!$service_id || !$email) {
            return new WP_Error('invalid', __('Datos inválidos.', 'travel-agency-platform'), ['status' => 400]);
        }
        $id = self::vote((int) $itin->id, $service_id, $email, $name, $vote);
        return rest_ensure_response(['id' => $id]);
    }

    public static function rest_comment($request) {
        if (!self::rate_limit('itin_comment', 20, 60)) {
            return new WP_Error('rate_limited', __('Demasiadas solicitudes. Intenta más tarde.', 'travel-agency-platform'), ['status' => 429]);
        }
        $params = $request->get_params();
        $token  = sanitize_text_field($params['token'] ?? '');
        $itin   = self::by_token($token);
        if (!$itin) return new WP_Error('not_found', __('Itinerario no encontrado.', 'travel-agency-platform'), ['status' => 404]);
        $user_id = get_current_user_id();
        $name    = sanitize_text_field($params['name'] ?? '');
        $comment = sanitize_textarea_field($params['comment'] ?? '');
        if ('' === $comment) {
            return new WP_Error('invalid', __('Comentario vacío.', 'travel-agency-platform'), ['status' => 400]);
        }
        $id = self::add_comment((int) $itin->id, $user_id, $name, $comment);
        return rest_ensure_response(['id' => $id]);
    }

    /** Render a share view for an itinerary token (shortcode). */
    public static function render_share($atts) {
        $atts = shortcode_atts(['token' => ''], $atts);
        $token = sanitize_text_field($atts['token']);
        if (!$token && !empty($_GET['itin_token'])) {
            $token = sanitize_text_field(wp_unslash($_GET['itin_token']));
        }
        if (!$token) {
            return '<p>' . __('Token requerido.', 'travel-agency-platform') . '</p>';
        }
        $itin = self::by_token($token);
        if (!$itin) {
            return '<p>' . __('Itinerario no encontrado.', 'travel-agency-platform') . '</p>';
        }
        $ids = json_decode($itin->service_ids, true) ?: [];
        ob_start();
        ?>
        <div class="tap-itinerary-share" data-token="<?php echo esc_attr($token); ?>">
            <h2><?php echo esc_html($itin->title); ?></h2>
            <p class="tap-agency-meta"><?php /* translators: %d: number of services */ echo esc_html(sprintf(__(' %d servicios', 'travel-agency-platform'), count($ids))); ?></p>
            <ul class="tap-itinerary-share-list" style="list-style:none;padding:0;display:grid;gap:12px;">
                <?php
                foreach ($ids as $sid): $p = get_post((int) $sid);
					if (!$p) continue;
					?>
                    <li class="tap-itinerary-share-item" data-service-id="<?php echo (int) $sid; ?>" style="border:1px solid var(--tap-border);border-radius:var(--tap-radius-lg);padding:12px;display:flex;align-items:center;gap:12px;">
                        <?php if (has_post_thumbnail($p->ID)): ?>
                            <img src="<?php echo esc_url(get_the_post_thumbnail_url($p->ID, 'thumbnail')); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:var(--tap-radius-md);">
                        <?php endif; ?>
                        <div style="flex:1;">
                            <strong><?php echo esc_html($p->post_title); ?></strong>
                            <div class="tap-itinerary-share-votes" data-service-id="<?php echo (int) $sid; ?>" style="font-size:var(--tap-text-sm);color:var(--tap-neutral-500);margin-top:4px;">Cargando votos…</div>
                        </div>
                        <div class="tap-itinerary-share-actions" style="display:flex;gap:6px;">
                            <button type="button" class="tap-btn tap-btn-sm tap-vote-btn" data-vote="1" data-service-id="<?php echo (int) $sid; ?>">👍</button>
                            <button type="button" class="tap-btn tap-btn-sm tap-vote-btn" data-vote="-1" data-service-id="<?php echo (int) $sid; ?>">👎</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tap-itinerary-share-comments" style="margin-top:24px;">
                <h3><?php esc_html_e('Comentarios', 'travel-agency-platform'); ?></h3>
                <div id="tap-itin-comments" style="display:grid;gap:8px;"></div>
                <form id="tap-itin-comment-form" style="margin-top:12px;display:grid;gap:8px;">
                    <input type="text" name="name" placeholder="<?php esc_attr_e('Tu nombre', 'travel-agency-platform'); ?>" style="padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);">
                    <textarea name="comment" rows="3" placeholder="<?php esc_attr_e('Comentario…', 'travel-agency-platform'); ?>" style="padding:8px 12px;border:1px solid var(--tap-border);border-radius:var(--tap-radius-md);"></textarea>
                    <button type="submit" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Comentar', 'travel-agency-platform'); ?></button>
                </form>
            </div>
        </div>
        <script>
        (function(){
            var root = document.querySelector('.tap-itinerary-share');
            if (!root) return;
            var token = root.dataset.token;
            var base = '<?php echo esc_js(rest_url('tap/v1/')); ?>';
            function load() {
                fetch(base + 'itinerary/' + encodeURIComponent(token))
                .then(function(r){return r.json();})
                .then(function(d){
                    if (!d || !d.services) return;
                    d.services.forEach(function(svc){
                        var votes = (d.votes || []).filter(function(v){ return parseInt(v.service_id,10) === svc.id; });
                        var up = votes.filter(function(v){ return parseInt(v.vote,10) > 0; }).length;
                        var down = votes.filter(function(v){ return parseInt(v.vote,10) < 0; }).length;
                        var el = root.querySelector('.tap-itinerary-share-votes[data-service-id="' + svc.id + '"]');
                        if (el) el.textContent = up + ' 👍 · ' + down + ' 👎';
                    });
                    var cbox = document.getElementById('tap-itin-comments');
                    if (cbox) {
                        cbox.innerHTML = '';
                        (d.comments || []).forEach(function(c){
                            var div = document.createElement('div');
                            div.style.cssText = 'padding:8px 12px;background:var(--tap-surface-muted);border-radius:var(--tap-radius-md);font-size:var(--tap-text-sm);';
                            div.innerHTML = '<strong>' + (c.author_name || 'Anónimo') + '</strong>: ' + c.comment;
                            cbox.appendChild(div);
                        });
                    }
                });
            }
            load();
            root.querySelectorAll('.tap-vote-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var email = prompt('Tu email para votar:');
                    if (!email) return;
                    var fd = new FormData();
                    fd.append('token', token);
                    fd.append('service_id', btn.dataset.serviceId);
                    fd.append('email', email);
                    fd.append('vote', btn.dataset.vote);
                    fetch(base + 'itinerary/vote', {method:'POST', body:fd})
                    .then(function(){ load(); });
                });
            });
            var cform = document.getElementById('tap-itin-comment-form');
            if (cform) {
                cform.addEventListener('submit', function(e){
                    e.preventDefault();
                    var fd = new FormData(cform);
                    fd.append('token', token);
                    fetch(base + 'itinerary/comment', {method:'POST', body:fd})
                    .then(function(){ cform.reset(); load(); });
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
