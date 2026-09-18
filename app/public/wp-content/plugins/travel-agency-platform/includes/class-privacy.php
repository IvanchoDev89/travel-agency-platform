<?php
defined('ABSPATH') || exit;

class TAP_Privacy {
    const POLICY_VERSION = '1.0';

    const RIGHTS = [
        'access'       => 'Acceso',
        'rectification'=> 'Rectificación',
        'update'       => 'Actualización',
        'deletion'     => 'Supresión',
        'opposition'   => 'Oposición',
    ];

    const SCOPES = ['booking', 'agency_registration', 'lead'];

    public static function init() {
        add_shortcode('tap_privacy', [__CLASS__, 'privacy_shortcode']);
        add_shortcode('tap_privacy_consent', [__CLASS__, 'consent_shortcode']);
        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 60);
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_eraser']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_tap_privacy_request', [__CLASS__, 'handle_request']);
        add_action('admin_post_nopriv_tap_privacy_request', [__CLASS__, 'handle_request']);
    }

    /* ===== Page / policy ===== */

    public static function ensure_privacy_page() {
        $page_id = (int) get_option('tap_privacy_page');
        if ($page_id && get_post($page_id)) return $page_id;

        $existing = get_posts([
            'name'           => 'aviso-de-privacidad',
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'numberposts'    => 1,
            'fields'         => 'ids',
        ]);
        if ($existing) {
            update_option('tap_privacy_page', (int) $existing[0]);
            return (int) $existing[0];
        }

        if (!isset($GLOBALS['wp_rewrite']) || !($GLOBALS['wp_rewrite'] instanceof WP_Rewrite)) {
            $GLOBALS['wp_rewrite'] = new WP_Rewrite();
        }

        $new_id = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Aviso de Privacidad',
            'post_name'   => 'aviso-de-privacidad',
            'post_content'=> '[tap_privacy]',
        ]);
        if ($new_id && !is_wp_error($new_id)) {
            update_option('tap_privacy_page', (int) $new_id);
        }
        return $new_id && !is_wp_error($new_id) ? (int) $new_id : 0;
    }

    public static function policy_url() {
        $page_id = (int) get_option('tap_privacy_page');
        if ($page_id && get_post($page_id)) return get_permalink($page_id);
        return home_url('/aviso-de-privacidad');
    }

    public static function contact_email() {
        $email = get_option('tap_privacy_contact', '');
        if (is_email($email)) return $email;
        return get_option('admin_email');
    }

    public static function privacy_shortcode($atts) {
        ob_start();
        self::render_policy();
        echo self::render_request_form();
        return ob_get_clean();
    }

    public static function render_policy() {
        $contact = self::contact_email();
        $policy_url = self::policy_url();
        $stamp = '<time>' . esc_html(date_i18n(get_option('date_format'), strtotime('2026-09-09'))) . '</time>';
        ?>
        <div class="tap-privacy-policy">
            <h2><?php esc_html_e('Aviso de Privacidad', 'travel-agency-platform'); ?></h2>
            <p class="tap-privacy-meta"><?php echo esc_html(sprintf(__('Versión %s', 'travel-agency-platform'), self::POLICY_VERSION)); ?> · <?php echo $stamp; ?></p>

            <h3><?php esc_html_e('1. Responsable del tratamiento', 'travel-agency-platform'); ?></h3>
            <p><?php echo wp_kses_post(sprintf(__('%s (en adelante "la Plataforma") es responsable del tratamiento de los datos personales que se recogen a través de este sitio web.', 'travel-agency-platform'), '<strong>' . esc_html(get_bloginfo('name')) . '</strong>')); ?></p>
            <p><?php echo wp_kses_post(sprintf(__('Contacto para asuntos de protección de datos: <a href="mailto:%s">%s</a>.', 'travel-agency-platform'), esc_attr($contact), esc_html($contact))); ?></p>

            <h3><?php esc_html_e('2. Datos que recopilamos', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Recopilamos únicamente los datos necesarios para prestar el servicio de reservas: nombre, correo electrónico, teléfono, datos de la reserva (fechas, servicio, montos), datos de la agencia y, cuando aplica, datos de identificación requeridos para la verificación de agencias y la facturación.', 'travel-agency-platform'); ?></p>

            <h3><?php esc_html_e('3. Finalidad y base legal', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Tratamos sus datos para: (a) gestionar reservas y su pago; (b) comunicarse sobre el estado de sus reservas; (c) verificar y administrar las agencias; (d) prevenir fraude y atender reclamaciones; y (e) cumplir obligaciones legales. La base legal es el consentimiento que usted otorga, la ejecución del contrato de reserva y el interés legítimo de la Plataforma.', 'travel-agency-platform'); ?></p>

            <h3><?php esc_html_e('4. Conservación', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Conservamos los datos mientras sean necesarios para las finalidades descritas y, posteriormente, por los plazos exigidos por la legislación aplicable (incluida la Ley 8968 y normas tributarias).', 'travel-agency-platform'); ?></p>

            <h3><?php esc_html_e('5. Transferencias', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('No vendemos ni compartimos sus datos personales con terceros ajenos a la Plataforma, salvo: agencias responsables de su reserva, procesadores de pago, proveedores de servicios técnicos y cuando la ley lo exija.', 'travel-agency-platform'); ?></p>

            <h3><?php esc_html_e('6. Sus derechos', 'travel-agency-platform'); ?></h3>
            <p><?php echo wp_kses_post(sprintf(__('Puede ejercer los derechos de información, acceso, rectificación, actualización, supresión y oposición, así como revocar su consentimiento, mediante el formulario de esta página o escribiendo a <a href="mailto:%s">%s</a>.', 'travel-agency-platform'), esc_attr($contact), esc_html($contact))); ?></p>
            <ul class="tap-privacy-list">
                <li><?php esc_html_e('Acceso: conocer qué datos tenemos y cómo los tratamos.', 'travel-agency-platform'); ?></li>
                <li><?php esc_html_e('Rectificación y actualización: corregir o actualizar sus datos.', 'travel-agency-platform'); ?></li>
                <li><?php esc_html_e('Supresión: solicitar la eliminación de sus datos cuando ya no sean necesarios.', 'travel-agency-platform'); ?></li>
                <li><?php esc_html_e('Oposición: oponerse al tratamiento por interés legítimo.', 'travel-agency-platform'); ?></li>
            </ul>

            <h3><?php esc_html_e('7. Cookies', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Este sitio utiliza cookies técnicas necesarias para su funcionamiento y, cuando usted lo permite, cookies analíticas.', 'travel-agency-platform'); ?></p>

            <h3><?php esc_html_e('8. Actualización del aviso', 'travel-agency-platform'); ?></h3>
            <p><?php esc_html_e('Podemos actualizar este aviso. Le notificaremos los cambios relevantes publicando la versión vigente en esta página.', 'travel-agency-platform'); ?></p>
        </div>
        <?php
    }

    public static function render_request_form() {
        $message = '';
        if (isset($_GET['privacy'])) {
            if ($_GET['privacy'] === 'ok') {
                $message = '<div class="tap-success">' . esc_html__('Solicitud enviada. La revisaremos y le responderemos a la mayor brevedad.', 'travel-agency-platform') . '</div>';
            } elseif ($_GET['privacy'] === 'error') {
                $message = '<div class="tap-error">' . esc_html__('No se pudo enviar la solicitud. Revise los datos e intente nuevamente.', 'travel-agency-platform') . '</div>';
            }
        }

        $current_email = is_user_logged_in() ? wp_get_current_user()->user_email : '';

        ob_start();
        if ($message) echo $message;
        ?>
        <div class="tap-privacy-request" id="derechos">
            <h2><?php esc_html_e('Ejercer sus derechos de datos personales', 'travel-agency-platform'); ?></h2>
            <p><?php esc_html_e('Complete este formulario para ejercer sus derechos ante la Plataforma. Los campos con * son obligatorios.', 'travel-agency-platform'); ?></p>
            <form class="tap-privacy-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tap_privacy_request">
                <?php wp_nonce_field('tap_privacy_request', '_tap_privacy_nonce'); ?>

                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_privacy_email"><?php esc_html_e('Correo electrónico', 'travel-agency-platform'); ?> *</label>
                        <input type="email" id="tap_privacy_email" name="email" class="tap-input" required autocomplete="email" value="<?php echo esc_attr($current_email); ?>">
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_privacy_name"><?php esc_html_e('Nombre (opcional)', 'travel-agency-platform'); ?></label>
                        <input type="text" id="tap_privacy_name" name="name" class="tap-input" autocomplete="name">
                    </div>
                </div>

                <div class="tap-form-group">
                    <span class="tap-field-label"><?php esc_html_e('Derechos que desea ejercer', 'travel-agency-platform'); ?> *</span>
                    <div class="tap-privacy-rights">
                        <?php foreach (self::RIGHTS as $key => $label): ?>
                        <label class="tap-check-label">
                            <input type="checkbox" name="rights[]" value="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tap-form-group">
                    <label for="tap_privacy_details"><?php esc_html_e('Detalles de su solicitud', 'travel-agency-platform'); ?> *</label>
                    <textarea id="tap_privacy_details" name="details" class="tap-input" rows="4" required></textarea>
                </div>

                <div class="tap-form-group">
                    <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Enviar solicitud', 'travel-agency-platform'); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ===== Consent ===== */

    public static function consent_shortcode($atts) {
        $atts = shortcode_atts(['scope' => 'booking'], $atts);
        return self::consent_field($atts['scope']);
    }

    public static function consent_field($scope = 'booking') {
        $url = self::policy_url();
        ob_start();
        ?>
        <label class="tap-check-label tap-privacy-consent">
            <input type="checkbox" name="tap_privacy_consent" value="1" required>
            <?php echo wp_kses_post(sprintf(__('Acepto el <a href="%s" target="_blank" rel="noopener">Aviso de Privacidad</a> y consiento el tratamiento de mis datos personales para gestionar esta solicitud.', 'travel-agency-platform'), esc_url($url))); ?>
        </label>
        <?php
        return ob_get_clean();
    }

    public static function record_consent($email, $scope, $wp_user_id = 0) {
        if (!is_email($email) || !in_array($scope, self::SCOPES, true)) return 0;

        global $wpdb;
        $result = $wpdb->insert($wpdb->prefix . 'tap_consents', [
            'user_id'        => max(0, (int) $wp_user_id),
            'email'          => sanitize_email($email),
            'scope'          => $scope,
            'policy_version' => self::POLICY_VERSION,
            'ip'             => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ]);
        return $result ? (int) $wpdb->insert_id : 0;
    }

    public static function has_consent($email, $scope) {
        if (!is_email($email) || !in_array($scope, self::SCOPES, true)) return false;

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_consents WHERE email = %s AND scope = %s",
            sanitize_email($email),
            $scope
        ));
        return $count > 0;
    }

    public static function recent_consents($limit = 50) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_consents ORDER BY id DESC LIMIT %d", max(1, (int) $limit))
        );
    }

    /* ===== ARCO requests ===== */

    public static function save_request($email, $rights, $details, $name = '') {
        if (!is_email($email)) return new WP_Error('tap_bad_email', __('Ingresa un correo electrónico válido.', 'travel-agency-platform'));

        $rights = (array) $rights;
        $clean_rights = [];
        foreach ($rights as $right) {
            if (isset(self::RIGHTS[$right])) $clean_rights[] = $right;
        }
        if (!$clean_rights) {
            return new WP_Error('tap_no_rights', __('Selecciona al menos un derecho.', 'travel-agency-platform'));
        }
        if (mb_strlen($details) < 10) {
            return new WP_Error('tap_short_details', __('Describe brevemente tu solicitud.', 'travel-agency-platform'));
        }

        global $wpdb;
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $result = $wpdb->insert($wpdb->prefix . 'tap_privacy_requests', [
            'user_id'    => (int) $user_id,
            'email'      => sanitize_email($email),
            'name'       => sanitize_text_field($name),
            'rights'     => implode(',', $clean_rights),
            'details'    => sanitize_textarea_field($details),
            'status'     => 'pending',
            'ip'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ]);
        return $result ? (int) $wpdb->insert_id : new WP_Error('tap_db_error', __('No se pudo guardar la solicitud.', 'travel-agency-platform'));
    }

    public static function handle_request() {
        if (!isset($_POST['_tap_privacy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tap_privacy_nonce'])), 'tap_privacy_request')) {
            wp_safe_redirect(add_query_arg('privacy', 'error', self::policy_url()));
            exit;
        }

        $email   = sanitize_email($_POST['email'] ?? '');
        $rights  = isset($_POST['rights']) && is_array($_POST['rights']) ? array_map('sanitize_text_field', $_POST['rights']) : [];
        $details = sanitize_textarea_field($_POST['details'] ?? '');
        $name    = sanitize_text_field($_POST['name'] ?? '');

        $result = self::save_request($email, $rights, $details, $name);
        $status = is_wp_error($result) ? 'error' : 'ok';

        wp_safe_redirect(add_query_arg('privacy', $status, self::policy_url()));
        exit;
    }

    public static function all_requests($status = '') {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}tap_privacy_requests";
        $args = [];
        if (in_array($status, ['pending', 'done'], true)) {
            $sql .= " WHERE status = %s";
            $args[] = $status;
        }
        $sql .= " ORDER BY id DESC LIMIT 500";
        return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
    }

    public static function mark_handled($request_id) {
        $request_id = (int) $request_id;
        if ($request_id <= 0) return false;

        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'tap_privacy_requests',
            ['status' => 'done', 'handled_at' => current_time('mysql'), 'handled_by' => get_current_user_id()],
            ['id' => $request_id]
        );
    }

    public static function rights_label($key) {
        return self::RIGHTS[$key] ?? $key;
    }

    public static function rights_labels($csv) {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $csv))) as $key) {
            if (isset(self::RIGHTS[$key])) $out[] = self::RIGHTS[$key];
        }
        return $out;
    }

    /* ===== Email footer ===== */

    public static function email_footer() {
        $contact = self::contact_email();
        $url = self::policy_url();
        $sep = '<br>-----------------------------------<br>';
        $note = sprintf(
            __('Procesamos tus datos personales de acuerdo con nuestro <a href="%1$s">Aviso de Privacidad</a>. Puedes ejercer tus derechos de acceso, rectificación, supresión u oposición escribiendo a <a href="mailto:%2$s">%2$s</a>.', 'travel-agency-platform'),
            esc_url($url),
            esc_attr($contact)
        );
        return $sep . '<p style="font-size:12px;color:#6b7280;">' . $note . '</p>';
    }

    /* ===== WP core privacy (exporters / erasers) ===== */

    public static function register_exporter(array $exporters) {
        $exporters[] = [
            'exporter_friendly_name' => __('Travel Agency Platform', 'travel-agency-platform'),
            'callback'               => [__CLASS__, 'export_personal_data'],
        ];
        return $exporters;
    }

    public static function register_eraser(array $erasers) {
        $erasers[] = [
            'eraser_friendly_name' => __('Travel Agency Platform', 'travel-agency-platform'),
            'callback'             => [__CLASS__, 'erase_personal_data'],
        ];
        return $erasers;
    }

    public static function export_personal_data($email, $page = 1) {
        global $wpdb;
        $email  = sanitize_email($email);
        $user   = get_user_by('email', $email);
        $user_id = $user ? (int) $user->ID : 0;

        $groups = [];

        // Bookings (linked by account or printed guest email).
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, u.user_email linked_email
             FROM {$wpdb->prefix}tap_bookings b
             LEFT JOIN {$wpdb->users} u ON b.client_id = u.ID
             WHERE (b.client_id = %d AND %d > 0) OR b.guest_email = %s
             ORDER BY b.id",
            $user_id, $user_id, $email
        ));
        $b_items = [];
        foreach ((array) $bookings as $b) {
            $b_items[] = [
                'group_id'    => 'tap_bookings',
                'group_label' => __('Travel bookings', 'travel-agency-platform'),
                'item_id'     => 'booking-' . (int) $b->id,
                'data'        => [
                    ['name' => __('Código', 'travel-agency-platform'),   'value' => $b->booking_code],
                    ['name' => __('Estado', 'travel-agency-platform'),   'value' => $b->status],
                    ['name' => __('Pago', 'travel-agency-platform'),     'value' => $b->payment_status],
                    ['name' => __('Servicio', 'travel-agency-platform'), 'value' => $b->service_type . ' #' . (int) $b->service_id],
                    ['name' => __('Entrada', 'travel-agency-platform'),  'value' => $b->check_in],
                    ['name' => __('Salida', 'travel-agency-platform'),   'value' => $b->check_out],
                    ['name' => __('Total', 'travel-agency-platform'),    'value' => (string) $b->total_amount],
                    ['name' => __('Invitado', 'travel-agency-platform'), 'value' => ($b->guest_name ?? '') . ' ' . ($b->guest_email ?? '')],
                ],
            ];
        }
        if ($b_items) {
            $groups[] = ['data' => $b_items, 'done' => true];
        }

        // Contact leads.
        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_leads WHERE email = %s ORDER BY id",
            $email
        ));
        $l_items = [];
        foreach ((array) $leads as $l) {
            $l_items[] = [
                'group_id'    => 'tap_leads',
                'group_label' => __('Contact messages', 'travel-agency-platform'),
                'item_id'     => 'lead-' . (int) $l->id,
                'data'        => [
                    ['name' => __('Nombre', 'travel-agency-platform'),  'value' => $l->name],
                    ['name' => __('Correo', 'travel-agency-platform'),  'value' => $l->email],
                    ['name' => __('Teléfono', 'travel-agency-platform'), 'value' => $l->phone ?? ''],
                    ['name' => __('Mensaje', 'travel-agency-platform'), 'value' => $l->message ?? ''],
                    ['name' => __('Fecha', 'travel-agency-platform'),   'value' => $l->created_at],
                ],
            ];
        }
        if ($l_items) {
            $groups[] = ['data' => $l_items, 'done' => true];
        }

        // Explicit consents recorded for this email.
        $consents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_consents WHERE email = %s ORDER BY id",
            $email
        ));
        $c_items = [];
        foreach ((array) $consents as $c) {
            $c_items[] = [
                'group_id'    => 'tap_consents',
                'group_label' => __('Privacy consents', 'travel-agency-platform'),
                'item_id'     => 'consent-' . (int) $c->id,
                'data'        => [
                    ['name' => __('Alcance', 'travel-agency-platform'),       'value' => $c->scope],
                    ['name' => __('Versión política', 'travel-agency-platform'), 'value' => $c->policy_version],
                    ['name' => __('Fecha', 'travel-agency-platform'),         'value' => $c->created_at],
                ],
            ];
        }
        if ($c_items) {
            $groups[] = ['data' => $c_items, 'done' => true];
        }

        // Reviews written by the account.
        if ($user_id) {
            $reviews = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d ORDER BY id",
                $user_id
            ));
            $r_items = [];
            foreach ((array) $reviews as $r) {
                $r_items[] = [
                    'group_id'    => 'tap_reviews',
                    'group_label' => __('Reviews', 'travel-agency-platform'),
                    'item_id'     => 'review-' . (int) $r->id,
                    'data'        => [
                        ['name' => __('Servicio', 'travel-agency-platform'), 'value' => $r->service_type . ' #' . (int) $r->service_id],
                        ['name' => __('Valoración', 'travel-agency-platform'), 'value' => (string) $r->rating],
                        ['name' => __('Título', 'travel-agency-platform'),  'value' => $r->title ?? ''],
                        ['name' => __('Contenido', 'travel-agency-platform'), 'value' => $r->content ?? ''],
                        ['name' => __('Fecha', 'travel-agency-platform'),   'value' => $r->created_at],
                    ],
                ];
            }
            if ($r_items) {
                $groups[] = ['data' => $r_items, 'done' => true];
            }
        }

        return ['data' => $groups, 'done' => true];
    }

    public static function erase_personal_data($email, $page = 1) {
        global $wpdb;
        $email   = sanitize_email($email);
        $user    = get_user_by('email', $email);
        $user_id = $user ? (int) $user->ID : 0;

        $removed = 0;

        // Leads: hard delete the row (contact requests are transient data).
        $removed += (int) $wpdb->delete($wpdb->prefix . 'tap_leads', ['email' => $email]);

        // Consents: the record of the consent itself is deleted with it.
        $removed += (int) $wpdb->delete($wpdb->prefix . 'tap_consents', ['email' => $email]);

        // Reviews: textual personal data, remove when owned by the account.
        if ($user_id) {
            $removed += (int) $wpdb->delete($wpdb->prefix . 'tap_reviews', ['user_id' => $user_id]);
        }

        // Bookings keep operational rows but all PII is anonymised; guest
        // bookings owned purely by this email are wiped entirely.
        $guest_rows = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_bookings WHERE client_id = 0 AND guest_email = %s",
            $email
        ));
        foreach ((array) $guest_rows as $bid) {
            $removed += (int) $wpdb->delete($wpdb->prefix . 'tap_bookings', ['id' => (int) $bid]);
        }
        if ($user_id) {
            $removed += (int) $wpdb->update(
                $wpdb->prefix . 'tap_bookings',
                ['guest_name' => '', 'guest_email' => ''],
                ['client_id' => $user_id]
            );
        }

        return [
            'items_removed'  => (bool) $removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }

    /* ===== Admin ===== */

    public static function add_admin_menu() {
        add_submenu_page(
            'travel-platform',
            __('Privacidad', 'travel-agency-platform'),
            __('Privacidad', 'travel-agency-platform'),
            'tap_manage_settings',
            'tap-privacy',
            [__CLASS__, 'privacy_page']
        );
    }

    public static function register_settings() {
        register_setting('tap_settings', 'tap_privacy_contact', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
        ]);
    }

    public static function privacy_page() {
        if (!current_user_can('tap_manage_settings')) {
            wp_die(__('No tienes permisos para acceder.', 'travel-agency-platform'));
        }

        if (isset($_POST['_tap_privacy_nonce'], $_POST['request_id']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tap_privacy_nonce'])), 'tap_privacy_mark')) {
            self::mark_handled((int) $_POST['request_id']);
        }

        $status = 'pending';
        if (isset($_GET['status']) && in_array($_GET['status'], ['pending', 'done'], true)) {
            $status = sanitize_key($_GET['status']);
        }

        $requests = self::all_requests($status);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Privacidad y Datos Personales (Ley 8968)', 'travel-agency-platform'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach (['pending' => __('Pendientes', 'travel-agency-platform'), 'done' => __('Atendidas', 'travel-agency-platform')] as $key => $label): ?>
                    <a class="nav-tab <?php echo $status === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'tap-privacy', 'status' => $key], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <h2><?php esc_html_e('Solicitudes de derechos de datos', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Correo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Nombre', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Derechos', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Detalles', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Estado', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Acción', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr><td colspan="8"><?php esc_html_e('No hay solicitudes.', 'travel-agency-platform'); ?></td></tr>
                    <?php else: foreach ($requests as $req): ?>
                        <tr>
                            <td><?php echo (int) $req->id; ?></td>
                            <td><?php echo esc_html(self::format_dt($req->created_at)); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($req->email); ?>"><?php echo esc_html($req->email); ?></a></td>
                            <td><?php echo esc_html($req->name ?: '—'); ?></td>
                            <td>
                                <?php foreach (self::rights_labels($req->rights) as $label): ?>
                                    <span class="tap-status tap-status-<?php echo in_array($label, ['Acceso', 'Rectificación'], true) ? 'pending' : 'cancelled'; ?>"><?php echo esc_html($label); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo esc_html(wp_trim_words($req->details, 20)); ?></td>
                            <td><?php echo esc_html($req->status === 'done' ? __('Atendida', 'travel-agency-platform') : __('Pendiente', 'travel-agency-platform')); ?></td>
                            <td>
                                <?php if ($req->status !== 'done'): ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('tap_privacy_mark', '_tap_privacy_nonce'); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int) $req->id; ?>">
                                    <button type="submit" class="button button-small"><?php esc_html_e('Marcar atendida', 'travel-agency-platform'); ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Registro de consentimientos', 'travel-agency-platform'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Correo', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Ámbito', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('Versión', 'travel-agency-platform'); ?></th>
                        <th><?php esc_html_e('IP', 'travel-agency-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $consents = self::recent_consents(50); ?>
                    <?php if (!$consents): ?>
                        <tr><td colspan="6"><?php esc_html_e('No hay consentimientos registrados.', 'travel-agency-platform'); ?></td></tr>
                    <?php else: foreach ($consents as $consent): ?>
                        <tr>
                            <td><?php echo (int) $consent->id; ?></td>
                            <td><?php echo esc_html(self::format_dt($consent->created_at)); ?></td>
                            <td><?php echo esc_html($consent->email); ?></td>
                            <td><?php echo esc_html($consent->scope); ?></td>
                            <td><?php echo esc_html($consent->policy_version); ?></td>
                            <td><?php echo esc_html($consent->ip ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Configuración de contacto', 'travel-agency-platform'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('tap_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tap_privacy_contact"><?php esc_html_e('Correo de contacto para datos personales', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <input type="email" id="tap_privacy_contact" name="tap_privacy_contact" class="regular-text" value="<?php echo esc_attr(get_option('tap_privacy_contact', '')); ?>">
                            <p class="description"><?php esc_html_e('Si se deja vacío se usa el correo del administrador del sitio.', 'travel-agency-platform'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function format_dt($dt) {
        if (!$dt) return '—';
        return esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($dt)));
    }
}