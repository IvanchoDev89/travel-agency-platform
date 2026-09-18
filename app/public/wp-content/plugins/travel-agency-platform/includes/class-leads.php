<?php
defined('ABSPATH') || exit;

class TAP_Leads {
    const EMAIL_HOURLY_LIMIT = 5;
    const IP_HOURLY_LIMIT    = 10;

    public static function init() {
        add_shortcode('tap_lead_form', [__CLASS__, 'lead_form']);
    }

    /**
     * Store a contact lead. CLI-safe: never calls wp_send_json_* nor wp_die.
     *
     * @param array $args name, email, phone, message, agency_id, service_id, ip, source
     * @return int|WP_Error Lead id or WP_Error with code.
     */
    public static function submit($args) {
        $name    = isset($args['name']) ? sanitize_text_field($args['name']) : '';
        $email   = isset($args['email']) ? sanitize_email($args['email']) : '';
        $phone   = isset($args['phone']) ? sanitize_text_field($args['phone']) : '';
        $message = isset($args['message']) ? sanitize_textarea_field($args['message']) : '';
        $agency_id = isset($args['agency_id']) ? (int) $args['agency_id'] : 0;
        $service_id = isset($args['service_id']) ? (int) $args['service_id'] : 0;
        $ip        = isset($args['ip']) ? sanitize_text_field((string) $args['ip']) : self::client_ip();
        $source    = sanitize_key($args['source'] ?? 'agency');
        if ($source === '') {
            $source = 'agency';
        }

        if ($name === '') {
            return new WP_Error('name_required', __('Por favor ingresa tu nombre.', 'travel-agency-platform'));
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('name_too_long', __('El nombre es demasiado largo.', 'travel-agency-platform'));
        }
        if ($email === '') {
            return new WP_Error('email_required', __('Por favor ingresa tu correo.', 'travel-agency-platform'));
        }
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Ingresa un correo válido.', 'travel-agency-platform'));
        }
        if (mb_strlen($message) > 2000) {
            return new WP_Error('message_too_long', __('El mensaje es demasiado largo (máximo 2000 caracteres).', 'travel-agency-platform'));
        }

        if ($agency_id < 1 || !self::agency_is_active($agency_id)) {
            return new WP_Error('invalid_agency', __('La agencia no acepta mensajes en este momento.', 'travel-agency-platform'));
        }

        if ($service_id > 0 && !self::service_belongs_to($service_id, $agency_id)) {
            return new WP_Error('invalid_service', __('El servicio no pertenece a esta agencia.', 'travel-agency-platform'));
        }

        if (self::hit_rate_limit($email, $ip)) {
            return new WP_Error('lead_rate_limit', __('Has enviado demasiados mensajes. Inténtalo de nuevo más tarde.', 'travel-agency-platform'));
        }

        if (empty($args['consent']) || '1' !== (string) $args['consent']) {
            return new WP_Error('lead_consent_required', __('Debes aceptar el Aviso de Privacidad para enviar tu mensaje.', 'travel-agency-platform'));
        }

        $hit = TAP_Moderation::assess($name . ' ' . $message, 'lead');
        if (TAP_Moderation::BLOCK === $hit['status']) {
            return new WP_Error('lead_blocked', __('Tu mensaje no pasó las verificaciones de seguridad. Inténtalo de nuevo.', 'travel-agency-platform'));
        }

        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'tap_leads',
            [
                'agency_id'  => $agency_id,
                'service_id' => $service_id > 0 ? $service_id : null,
                'name'       => $name,
                'email'      => $email,
                'phone'      => $phone,
                'message'    => $message,
                'ip'         => $ip ?: null,
                'source'     => $source,
                'mod_status' => $hit['status'],
                'mod_reason' => $hit['reason'],
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if (!$ok) {
            return new WP_Error('db_error', __('No se pudo guardar el mensaje.', 'travel-agency-platform'));
        }

        $lead_id = (int) $wpdb->insert_id;

        // GDPR: record the explicit consent attached to this message.
        if (class_exists('TAP_Privacy')) {
            TAP_Privacy::record_consent($email, 'lead');
        }

        do_action('tap_lead_created', $lead_id);
        return $lead_id;
    }

    public static function for_agency($agency_id, $limit = 20) {
        global $wpdb;
        $row_id = self::agency_row_id($agency_id);
        if ($row_id < 1) {
            return [];
        }
        $t = $wpdb->prefix . 'tap_leads';
        if ($limit > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE agency_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
                $row_id, $limit
            )) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE agency_id = %d ORDER BY created_at DESC, id DESC",
            $row_id
        )) ?: [];
    }

    public static function count_for_agency($agency_id) {
        $row_id = self::agency_row_id($agency_id);
        if ($row_id < 1) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_leads WHERE agency_id = %d",
            $row_id
        ));
    }

    public static function export_csv($agency_id) {
        $rows = self::for_agency($agency_id, 0);
        $out  = '';
        $out .= chr(0xEF) . chr(0xBB) . chr(0xBF);
        $out .= implode(',', ['id', 'name', 'email', 'phone', 'message', 'source', 'service_id', 'created_at']) . "\n";
        foreach ($rows as $r) {
            $unlocked = class_exists('TAP_Attribution')
                && TAP_Attribution::contact_unlocked($agency_id, $r->email);
            $fields = [
                (int) $r->id,
                $unlocked ? $r->name : TAP_Attribution::mask_name($r->name),
                $unlocked ? $r->email : TAP_Attribution::mask_email($r->email),
                $unlocked ? ($r->phone ?? '') : TAP_Attribution::mask_phone($r->phone ?? ''),
                $r->message ?? '',
                $r->source ?? '',
                (int) ($r->service_id ?? 0),
                $r->created_at,
            ];
            $out .= implode(',', array_map([__CLASS__, 'csv_cell'], $fields)) . "\n";
        }
        return $out;
    }

    public static function csv_download() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/login/'));
            exit;
        }
        $user_agency = (int) TAP_Booking::get_agency_for_user(get_current_user_id());
        $agency_id   = isset($_GET['agency']) ? (int) $_GET['agency'] : 0;
        $nonce       = $_GET['_wpnonce'] ?? '';
        if (!$user_agency || $agency_id !== $user_agency || !wp_verify_nonce($nonce, 'tap_export_leads_' . get_current_user_id())) {
            wp_die(__('Acceso denegado.', 'travel-agency-platform'), '', ['response' => 403]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tap-leads-' . (int) $agency_id . '.csv"');
        echo self::export_csv($agency_id);
        exit;
    }

    public static function lead_form($atts) {
        $atts = shortcode_atts([
            'agency'  => 0,
            'service' => 0,
            'title'   => '',
        ], $atts, 'tap_lead_form');

        $agency_id  = (int) $atts['agency'];
        $service_id = (int) $atts['service'];

        if ($service_id > 0) {
            $service = get_post($service_id);
            if (!$service || !in_array($service->post_type, ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], true) || $service->post_status !== 'publish') {
                return '';
            }
            $owner = get_post_meta($service_id, '_tap_' . TAP_Post_Types::meta_prefix($service->post_type) . '_agency_id', true);
            if (!$owner) {
                return '';
            }
            if (!$agency_id) {
                $agency_id = (int) $owner;
            } elseif ($agency_id !== (int) $owner) {
                return '';
            }
        }

        if ($agency_id < 1) {
            return '';
        }
        if (!self::agency_is_active($agency_id)) {
            $resolved = self::row_id_for_agency_post($agency_id);
            if (!$resolved || !self::agency_is_active($resolved)) {
                return '';
            }
            $agency_id = $resolved;
        }

        $title = $atts['title'] !== '' ? $atts['title'] : ($service_id > 0 ? __('Solicitar información', 'travel-agency-platform') : __('Contactar a la agencia', 'travel-agency-platform'));

        ob_start();
        ?>
        <div class="tap-lead-form-wrap" data-agency="<?php echo (int) $agency_id; ?>">
            <h3><?php echo esc_html($title); ?></h3>
            <form class="tap-form tap-lead-form" method="post" novalidate>
                <input type="hidden" name="action" value="tap_lead_submit">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('tap_lead_nonce')); ?>">
                <input type="hidden" name="agency_id" value="<?php echo (int) $agency_id; ?>">
                <input type="hidden" name="service_id" value="<?php echo (int) $service_id; ?>">
                <div class="tap-lead-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
                    <input type="text" name="tap_hp" tabindex="-1" autocomplete="off">
                </div>
                <p><label><?php esc_html_e('Nombre', 'travel-agency-platform'); ?> *</label>
                    <input type="text" name="tap_name" required></p>
                <p><label><?php esc_html_e('Correo', 'travel-agency-platform'); ?> *</label>
                    <input type="email" name="tap_email" required></p>
                <p><label><?php esc_html_e('Teléfono', 'travel-agency-platform'); ?></label>
                    <input type="text" name="tap_phone"></p>
                <p><label><?php esc_html_e('Mensaje', 'travel-agency-platform'); ?></label>
                    <textarea name="tap_message" rows="4" maxlength="2000"></textarea></p>
                <?php if (class_exists('TAP_Privacy')): ?>
                <p class="tap-check-label">
                    <?php echo TAP_Privacy::consent_field('lead'); // WPCS: output already escaped in consent_field. ?>
                </p>
                <?php endif; ?>
                <button type="submit" class="tap-btn"><?php esc_html_e('Enviar mensaje', 'travel-agency-platform'); ?></button>
                <p class="tap-lead-msg" aria-live="polite"></p>
            </form>
        </div>
        <script>
        (function () {
            var wrap = document.querySelector('.tap-lead-form-wrap[data-agency="<?php echo (int) $agency_id; ?>"]');
            if (!wrap) return;
            var form = wrap.querySelector('.tap-lead-form');
            var msg = wrap.querySelector('.tap-lead-msg');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                msg.textContent = '';
                if (!form.tap_name.value.trim() || !form.tap_email.value.trim()) {
                    msg.textContent = '<?php echo esc_js(__('Completa tu nombre y correo.', 'travel-agency-platform')); ?>';
                    msg.style.color = '#b91c1c';
                    return;
                }
                var fd = new FormData(form);
                msg.textContent = '<?php echo esc_js(__('Enviando…', 'travel-agency-platform')); ?>';
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            msg.textContent = '<?php echo esc_js(__('Mensaje enviado. La agencia te contactará pronto.', 'travel-agency-platform')); ?>';
                            msg.style.color = '#047857';
                            form.reset();
                        } else {
                            msg.textContent = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js(__('No se pudo enviar el mensaje.', 'travel-agency-platform')); ?>';
                            msg.style.color = '#b91c1c';
                        }
                    })
                    .catch(function () {
                        msg.textContent = '<?php echo esc_js(__('Error de conexión. Inténtalo de nuevo.', 'travel-agency-platform')); ?>';
                        msg.style.color = '#b91c1c';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function agency_is_active($agency_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_agencies WHERE id = %d AND is_active = 1",
            $agency_id
        )) > 0;
    }

    /**
     * Map an agency identifier to the tap_leads/tap_agencies row id namespace.
     * Accepts the tap_agency post id (canonical in bookings and the agency
     * dashboard) or an already-resolved tap_agencies row id.
     */
    public static function agency_row_id($agency_id) {
        $agency_id = (int) $agency_id;
        if ($agency_id < 1) {
            return 0;
        }
        $post = get_post($agency_id);
        if ($post && $post->post_type === 'tap_agency') {
            return self::row_id_for_agency_post($agency_id);
        }
        return $agency_id;
    }

    /**
     * Map an agency POST id to its tap_agencies row id (registration stores
     * the owner user id in both the post meta and the row).
     */
    private static function row_id_for_agency_post($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'tap_agency') {
            return 0;
        }
        $user_id = (int) get_post_meta($post_id, '_tap_agency_user_id', true);
        if (!$user_id) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_agencies WHERE user_id = %d",
            $user_id
        ));
    }

    private static function service_belongs_to($service_id, $agency_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_agencies';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.id FROM {$t} a WHERE a.id = %d AND a.is_active = 1",
            $agency_id
        ));
        if (!$row) {
            return false;
        }
        $service = get_post($service_id);
        if (!$service || $service->post_status !== 'publish') {
            return false;
        }
        if (!in_array($service->post_type, ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], true)) {
            return false;
        }
        $owner = get_post_meta($service_id, '_tap_' . TAP_Post_Types::meta_prefix($service->post_type) . '_agency_id', true);
        return $owner && (int) $owner === $agency_id;
    }

    private static function hit_rate_limit($email, $ip) {
        global $wpdb;
        $t = $wpdb->prefix . 'tap_leads';

        if ($email !== '') {
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE email = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $email
            ));
            if ($n >= self::EMAIL_HOURLY_LIMIT) {
                return true;
            }
        }
        if ($ip !== '') {
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE ip = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $ip
            ));
            if ($n >= self::IP_HOURLY_LIMIT) {
                return true;
            }
        }
        return false;
    }

    private static function client_ip() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return sanitize_text_field(trim($parts[0]));
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field((string) $_SERVER['REMOTE_ADDR']);
        }
        return '';
    }

    private static function csv_cell($value) {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $value = (string) $value;
        // Neutralise CSV formula injection (=, +, -, @, tab, CR) that
        // spreadsheets would otherwise execute when opening the export.
        if (preg_match('/^[\x00-\x20]*[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        if (strpbrk($value, ",\"\n\r") !== false) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}