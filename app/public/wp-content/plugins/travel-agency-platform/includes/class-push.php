<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Web Push notifications (best-effort).
 *
 * Stores subscriptions and sends push messages. Actual delivery requires
 * VAPID keys configured (tap_vapid_public, tap_vapid_private). If not
 * configured, falls back to email.
 */
class TAP_Push {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_push_subscriptions';
    }

    /** VAPID public key (base64url). */
    public static function vapid_public() {
        return (string) get_option('tap_vapid_public', '');
    }

    /** VAPID private key (base64url). */
    public static function vapid_private() {
        return (string) get_option('tap_vapid_private', '');
    }

    /** Is push configured? */
    public static function is_ready() {
        return '' !== self::vapid_public() && '' !== self::vapid_private();
    }

    /** Subscribe a user. */
    public static function subscribe($user_id, $endpoint, $p256dh, $auth) {
        global $wpdb;
        $t = self::table();
        // Remove existing subscription for same endpoint.
        $wpdb->delete($t, ['endpoint' => $endpoint], ['%s']);
        $wpdb->insert($t, [
            'user_id'    => (int) $user_id,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Unsubscribe. */
    public static function unsubscribe($endpoint) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['endpoint' => $endpoint], ['%s']);
    }

    /** Get subscriptions for a user (or all if 0). */
    public static function for_user($user_id = 0) {
        global $wpdb;
        $t = self::table();
        if ($user_id) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id = %d", (int) $user_id));
        }
        return $wpdb->get_results("SELECT * FROM {$t}");
    }

    /** Send 24h reminders for upcoming tours. */
    public static function send_reminders() {
        global $wpdb;
        $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE check_in = %s AND status IN ('pending','confirmed','request')",
            $tomorrow
        ));
        foreach ($bookings as $b) {
            if (get_post_meta($b->id, '_tap_reminder_sent', true)) {
                continue;
            }
            if (!empty($b->client_id)) {
                self::send((int) $b->client_id, __('Recordatorio de tu tour', 'travel-agency-platform'), sprintf(__('Tu tour es mañana. Reserva %s.', 'travel-agency-platform'), $b->booking_code), home_url('/mi-cuenta/?seccion=bookings'), 'reminder');
            }
            $agency_user = (int) get_post_meta((int) $b->agency_id, '_tap_agency_user_id', true);
            if ($agency_user) {
                self::send($agency_user, __('Recordatorio de tour', 'travel-agency-platform'), sprintf(__('Tienes un tour mañana: reserva %s.', 'travel-agency-platform'), $b->booking_code), home_url('/mi-cuenta/?seccion=bo-operations'), 'reminder');
            }
            update_post_meta($b->id, '_tap_reminder_sent', 1);
        }
    }

    /** Notify recipient of a new chat message. */
    public static function on_chat_message($booking_id, $author_type, $message_id) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", (int) $booking_id));
        if (!$booking) {
            return;
        }
        if ('client' === $author_type) {
            $agency_user = (int) get_post_meta((int) $booking->agency_id, '_tap_agency_user_id', true);
            if ($agency_user) {
                self::send($agency_user, __('Nuevo mensaje de cliente', 'travel-agency-platform'), sprintf(__('Tienes un mensaje nuevo en la reserva %s.', 'travel-agency-platform'), $booking->booking_code), home_url('/mi-cuenta/?seccion=bo-operations'), 'chat');
            }
        } else {
            if (!empty($booking->client_id)) {
                self::send((int) $booking->client_id, __('Nuevo mensaje de la agencia', 'travel-agency-platform'), sprintf(__('La agencia respondió en la reserva %s.', 'travel-agency-platform'), $booking->booking_code), home_url('/mi-cuenta/?seccion=bookings'), 'chat');
            }
        }
    }

    /** Count subscriptions. */
    public static function count() {
        global $wpdb;
        $t = self::table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Send a push notification. Best-effort: if VAPID not configured or
     * sending fails, falls back to email (if user has email).
     *
     * @return int Number of successful deliveries.
     */
    public static function send($user_id, $title, $body, $url = '', $category = '') {
        $user_id = (int) $user_id;
        if ($user_id && $category) {
            $prefs = get_user_meta($user_id, '_tap_push_prefs', true);
            if (is_array($prefs) && !empty($prefs) && !in_array($category, $prefs, true)) {
                return 0; // user opted out of this category
            }
        }
        $subs = self::for_user($user_id);
        $sent = 0;
        if (self::is_ready() && $subs) {
            foreach ($subs as $sub) {
                if (self::send_push($sub, $title, $body, $url)) {
                    ++$sent;
                }
            }
        }
        // Fallback to email if no push delivered.
        if ($sent === 0 && $user_id) {
            $user = get_userdata($user_id);
            if ($user && $user->user_email) {
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                $content = '<p>' . esc_html($body) . '</p>';
                if ($url) $content .= '<p><a href="' . esc_url($url) . '">' . esc_html(__('Ver más', 'travel-agency-platform')) . '</a></p>';
                if (wp_mail($user->user_email, $title, $content, $headers)) {
                    $sent = 1;
                }
            }
        }
        return $sent;
    }

    /**
     * Send a single push message via Web Push protocol.
     * Simplified implementation using VAPID. Returns true on success.
     */
    private static function send_push($sub, $title, $body, $url) {
        // Without a full web-push library, we attempt a best-effort POST.
        // Real delivery requires VAPID JWT signing; if openssl is available
        // we sign, otherwise we skip (email fallback handles delivery).
        if (!function_exists('openssl_pkey_get_private')) return false;

        $payload = wp_json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
        if (!$payload) return false;

        // Build VAPID JWT.
        $jwt = self::vapid_jwt($sub->endpoint);
        if (!$jwt) return false;

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Authorization: vapid t=' . $jwt . ', k=' . self::vapid_public(),
        ];

        $resp = wp_remote_post($sub->endpoint, [
            'headers' => $headers,
            'body'    => $payload,
            'timeout' => 10,
        ]);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        return $code >= 200 && $code < 300;
    }

    /** Build a VAPID JWT (simplified). */
    private static function vapid_jwt($endpoint) {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!$host) return '';
        $aud = 'https://' . $host;
        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => 'mailto:' . get_option('admin_email'),
        ];
        $b64h = self::b64url(json_encode($header));
        $b64c = self::b64url(json_encode($claims));
        $signing = $b64h . '.' . $b64c;

        $key = openssl_pkey_get_private(self::vapid_private_pem());
        if (!$key) return '';
        $sig = '';
        if (!openssl_sign($signing, $sig, $key, OPENSSL_ALGO_SHA256)) return '';
        // Convert DER signature to raw R+S (ES256).
        $raw = self::der_to_raw($sig);
        if (!$raw) return '';
        return $signing . '.' . self::b64url($raw);
    }

    private static function vapid_private_pem() {
        // Expect stored as PEM; if stored as base64url, wrap.
        $key = self::vapid_private();
        if (strpos($key, '-----BEGIN') !== false) return $key;
        // Assume base64url-encoded raw key (32 bytes) -> convert to PEM.
        $raw = self::b64url_decode($key);
        if (strlen($raw) !== 32) return '';
        // Build PKCS#8 PEM for P-256 private key.
        $der = "\x30\x41\x02\x01\x00\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x04\x27\x30\x25\x02\x01\x01\x04\x20" . $raw;
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    private static function der_to_raw($der) {
        // Parse DER ECDSA signature: SEQUENCE { INTEGER r, INTEGER s }
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) return '';
        $len = ord($der[$pos++]);
        if ($len & 0x80) { $n = $len & 0x7f;
			$len = 0; for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($der[$pos++]); }
        if (ord($der[$pos++]) !== 0x02) return '';
        $rlen = ord($der[$pos++]);
        if ($rlen & 0x80) { $n = $rlen & 0x7f;
			$rlen = 0; for ($i = 0; $i < $n; $i++) $rlen = ($rlen << 8) | ord($der[$pos++]); }
        $r = substr($der, $pos, $rlen);
		$pos += $rlen;
        if (ord($der[$pos++]) !== 0x02) return '';
        $slen = ord($der[$pos++]);
        if ($slen & 0x80) { $n = $slen & 0x7f;
			$slen = 0; for ($i = 0; $i < $n; $i++) $slen = ($slen << 8) | ord($der[$pos++]); }
        $s = substr($der, $pos, $slen);
        // Pad to 32 bytes each.
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode($data) {
        $pad = 4 - (strlen($data) % 4);
        if ($pad !== 4) $data .= str_repeat('=', $pad);
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /** Notify a user (push + email fallback). */
    public static function notify($user_id, $title, $body, $url = '') {
        $user_id = (int) $user_id;
        return self::send($user_id, $title, $body, $url);
    }

    /** Init: register REST endpoints. */
    public static function init() {
        self::ensure_keys();
        add_action('rest_api_init', function () {
            register_rest_route('tap/v1', '/push/subscribe', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_subscribe'],
                'permission_callback' => function () {
					return is_user_logged_in(); },
            ]);
            register_rest_route('tap/v1', '/push/unsubscribe', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_unsubscribe'],
                'permission_callback' => function () {
					return is_user_logged_in(); },
            ]);
        });
        add_action('tap_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 20, 1);
        add_action('tap_chat_message_sent', [__CLASS__, 'on_chat_message'], 10, 3);
    }

    /** Generate VAPID keys if missing. */
    public static function ensure_keys() {
        if (self::is_ready()) {
            return;
        }
        if (!function_exists('openssl_pkey_new')) {
            return;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            return;
        }
        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
            return;
        }
        $pub = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $priv = $details['ec']['d'];
        update_option('tap_vapid_public', self::b64url($pub));
        update_option('tap_vapid_private', self::b64url($priv));
    }

    /** Notify agency of new booking. */
    public static function on_booking_created($booking_id, $data) {
        $booking_id = (int) $booking_id;
        if (!$booking_id) {
            return;
        }
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) {
            return;
        }
        $agency_id = (int) $booking->agency_id;
        $agency_user = (int) get_post_meta($agency_id, '_tap_agency_user_id', true);
        if ($agency_user) {
            self::send($agency_user, __('Nueva reserva', 'travel-agency-platform'), sprintf(__('Reserva %s creada.', 'travel-agency-platform'), $booking->booking_code), home_url('/mi-cuenta/?seccion=bo-operations'), 'new_booking');
        }
    }

    /** Notify client and agency of payment. */
    public static function on_payment_completed($booking_id) {
        $booking_id = (int) $booking_id;
        if (!$booking_id) {
            return;
        }
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $booking_id));
        if (!$booking) {
            return;
        }
        if (!empty($booking->client_id)) {
            self::send((int) $booking->client_id, __('Pago recibido', 'travel-agency-platform'), sprintf(__('Tu pago para la reserva %s fue recibido.', 'travel-agency-platform'), $booking->booking_code), home_url('/mi-cuenta/?seccion=bookings'), 'payment');
        }
        $agency_user = (int) get_post_meta((int) $booking->agency_id, '_tap_agency_user_id', true);
        if ($agency_user) {
            self::send($agency_user, __('Pago recibido', 'travel-agency-platform'), sprintf(__('Pago recibido para la reserva %s.', 'travel-agency-platform'), $booking->booking_code), home_url('/mi-cuenta/?seccion=bo-operations'), 'payment');
        }
    }

    public static function rest_subscribe($request) {
        $params = $request->get_params();
        $endpoint = esc_url_raw($params['endpoint'] ?? '');
        $p256dh   = sanitize_text_field($params['p256dh'] ?? '');
        $auth     = sanitize_text_field($params['auth'] ?? '');
        if (!$endpoint || !$p256dh || !$auth) {
            return new WP_Error('invalid', __('Datos de suscripción inválidos.', 'travel-agency-platform'), ['status' => 400]);
        }
        $id = self::subscribe(get_current_user_id(), $endpoint, $p256dh, $auth);
        return rest_ensure_response(['id' => $id, 'vapid_public' => self::vapid_public()]);
    }

    public static function rest_unsubscribe($request) {
        $params = $request->get_params();
        $endpoint = esc_url_raw($params['endpoint'] ?? '');
        if (!$endpoint) {
            return new WP_Error('invalid', __('Endpoint requerido.', 'travel-agency-platform'), ['status' => 400]);
        }
        self::unsubscribe($endpoint);
        return rest_ensure_response(['ok' => true]);
    }
    /** Render a subscribe button (shortcode). */
    public static function render_subscribe_button($atts = []) {
        if (!self::is_ready()) {
            return '<p class="tap-agency-meta">' . esc_html__('Las notificaciones push no están configuradas.', 'travel-agency-platform') . '</p>';
        }
        ob_start();
        ?>
        <button type="button" id="tap-push-subscribe" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Activar notificaciones', 'travel-agency-platform'); ?></button>
        <span id="tap-push-status" class="tap-agency-meta" style="margin-left:8px;"></span>
        <script>
        (function(){
            var btn = document.getElementById('tap-push-subscribe');
            var status = document.getElementById('tap-push-status');
            if (!btn) return;
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                if (status) status.textContent = 'Tu navegador no soporta notificaciones push.';
                btn.disabled = true;
                return;
            }
            var swUrl = '<?php echo esc_js(TAP_PLUGIN_URL); ?>assets/sw.js';
            var vapidPublic = '<?php echo esc_js(self::vapid_public()); ?>';
            function urlB64ToUint8Array(base64String) {
                var padding = '='.repeat((4 - base64String.length % 4) % 4);
                var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                var rawData = window.atob(base64);
                var outputArray = new Uint8Array(rawData.length);
                for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
                return outputArray;
            }
            navigator.serviceWorker.register(swUrl).then(function (reg) {
                btn.addEventListener('click', function () {
                    Notification.requestPermission().then(function (perm) {
                        if (perm !== 'granted') { if (status) status.textContent = 'Permiso denegado.'; return; }
                        reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(vapidPublic) })
                        .then(function (sub) {
                            var json = sub.toJSON();
                            var fd = new FormData();
                            fd.append('action', 'tap_push_subscribe_client');
                            fd.append('endpoint', json.endpoint);
                            fd.append('p256dh', json.keys.p256dh);
                            fd.append('auth', json.keys.auth);
                            fd.append('nonce', (window.tapDash && window.tapDash.nonce) || (window.tapAgencyWizard && window.tapAgencyWizard.listingNonce) || '');
                            fetch((window.tapDash && window.tapDash.ajax_url) || '/wp-admin/admin-ajax.php', {method:'POST', body:fd, credentials:'same-origin'})
                            .then(function(){ if (status) status.textContent = 'Notificaciones activadas.'; btn.disabled = true; });
                        });
                    });
                });
            }).catch(function (e) { if (status) status.textContent = 'Error: ' + e.message; });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
