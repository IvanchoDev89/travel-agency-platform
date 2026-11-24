<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
defined('ABSPATH') || exit;

/**
 * Digital contracts: versioned legal documents and immutable acceptances.
 *
 * Documents are never overwritten: publishing a change creates a new version
 * with a SHA-256 hash of its HTML. Every acceptance stores the exact version
 * and hash the person agreed to, plus server timestamp, signer name and
 * hashed client fingerprint. A receipt is emailed as evidence.
 */
class TAP_Legal {

    const CONTEXT_TRAVELER = 'traveler_terms';
    const CONTEXT_AGENCY   = 'agency_terms';

    public static function table_docs() {
        global $wpdb;
        return $wpdb->prefix . 'tap_legal_documents';
    }

    public static function table_accept() {
        global $wpdb;
        return $wpdb->prefix . 'tap_legal_acceptances';
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_page'], 35);
        add_action('admin_post_tap_legal_accept', [__CLASS__, 'handle_accept']);
        add_action('admin_post_nopriv_tap_legal_accept', [__CLASS__, 'handle_accept']);
        add_shortcode('tap_my_contracts', [__CLASS__, 'my_contracts']);
        add_shortcode('tap_legal_doc', [__CLASS__, 'document_shortcode']);
        add_action('init', [__CLASS__, 'seed_defaults'], 2);
    }

    public static function add_admin_page() {
        add_submenu_page(
            'travel-platform',
            __('Contratos digitales', 'travel-agency-platform'),
            __('Contratos digitales', 'travel-agency-platform'),
            'manage_options',
            'tap-legal',
            [__CLASS__, 'admin_page']
        );
    }

    /** Default document catalogue (Spanish source; English mirror). */
    public static function default_documents() {
        return [
            'traveler_terms' => [
                'context' => self::CONTEXT_TRAVELER,
                'title'   => 'Términos y condiciones del viajero',
                'es'      => '<h3>1. Objeto</h3><p>Estos términos regulan la reserva de alojamientos, tours, traslados y demás servicios publicados por agencias verificadas en la plataforma. La plataforma actúa únicamente como intermediario tecnológico y de pago; el contrato de prestación del servicio se celebra directamente entre el viajero y la agencia.</p>'
                    . '<h3>2. Precio y pago</h3><p>El precio mostrado incluye el servicio y, cuando aplica, la prima de seguro contratada. La tarifa de plataforma y la comisión son cargos de la agencia y no se cobran al viajero. El importe total a pagar se muestra antes de confirmar el pago y queda registrado en la reserva.</p>'
                    . '<h3>3. Confirmación</h3><p>La reserva queda confirmada cuando el pago es capturado y la agencia acepta la solicitud en los casos de reserva por solicitud. El voucher con el código de reserva es el comprobante de la compra.</p>'
                    . '<h3>4. Cancelaciones y reembolsos</h3><p>La política de cancelación aplicable es la publicada en el servicio contratado y se congela en el momento de la reserva. El reembolso, cuando procede, se calcula sobre el importe efectivamente pagado y se devuelve al mismo medio de pago.</p>'
                    . '<h3>5. Datos personales</h3><p>Los datos se tratan conforme al Aviso de Privacidad. El viajero puede ejercer sus derechos escribiendo al correo de contacto indicado en dicho aviso.</p>'
                    . '<h3>6. Responsabilidad</h3><p>La agencia es responsable de la prestación del servicio y de cumplir lo ofrecido. La plataforma facilita la reserva y el pago, y atiende las reclamaciones conforme a sus políticas de soporte y disputas.</p>',
                'en'      => '<h3>1. Purpose</h3><p>These terms govern bookings of accommodation, tours, transfers and other services listed by verified agencies. The platform acts only as a technology and payment intermediary; the service contract is between the traveler and the agency.</p>'
                    . '<h3>2. Price and payment</h3><p>The displayed price includes the service and, where applicable, the insurance premium. The platform fee and commission are agency charges and are not billed to the traveler. The total is shown before payment and recorded in the booking.</p>'
                    . '<h3>3. Confirmation</h3><p>A booking is confirmed once payment is captured and, for request-to-book services, the agency accepts. The voucher with the booking code is the proof of purchase.</p>'
                    . '<h3>4. Cancellations and refunds</h3><p>The applicable cancellation policy is the one published for the service and is frozen at booking time. Refunds, when due, are calculated on the amount actually paid and returned to the same payment method.</p>'
                    . '<h3>5. Personal data</h3><p>Data is processed under the Privacy Notice. Travelers may exercise their rights using the contact email in that notice.</p>'
                    . '<h3>6. Liability</h3><p>The agency is responsible for delivering the service as described. The platform facilitates booking and payment and handles claims under its support and dispute policies.</p>',
            ],
            'agency_terms' => [
                'context' => self::CONTEXT_AGENCY,
                'title'   => 'Contrato de agencia y términos de plataforma',
                'es'      => '<h3>1. Relación</h3><p>La agencia se registra en la plataforma para publicar servicios y recibir reservas. La plataforma presta servicios tecnológicos, de visibilidad y de cobro; no presta el servicio turístico contratado por el viajero.</p>'
                    . '<h3>2. Verificación</h3><p>La agencia se somete a verificación de identidad y datos de contacto. Hasta ser aprobada podrá preparar listados, pero estos no serán visibles para viajeros ni podrán recibir reservas.</p>'
                    . '<h3>3. Comisión y tarifa</h3><p>La agencia paga una comisión sobre el subtotal neto de la reserva, sin incluir la tarifa de plataforma ni la prima de seguro. El porcentaje aplicable depende de su plan y del volumen mensual de reservas pagadas, con un piso contractual. La tarifa de plataforma se deduce del neto de la agencia.</p>'
                    . '<h3>4. Liquidaciones</h3><p>Las comisiones devengadas se liquidan previa solicitud o por gestión de la plataforma. Las reservas canceladas, reembolsadas o en disputa no generan comisión exigible.</p>'
                    . '<h3>5. Publicidad y cumplimiento</h3><p>La agencia garantiza que la información publicada es veraz, que cuenta con los permisos y seguros aplicables, y que cumplirá lo ofrecido. Responderá por reclamaciones derivadas del servicio.</p>'
                    . '<h3>6. Datos y vigencia</h3><p>La agencia trata los datos de viajeros conforme a la ley y al Aviso de Privacidad. Este contrato quedará registrado con su versión y fecha de aceptación; las modificaciones se notifican y requieren nueva aceptación.</p>',
                'en'      => '<h3>1. Relationship</h3><p>The agency registers to list services and receive bookings. The platform provides technology, visibility and payment services; it does not provide the travel service purchased by the traveler.</p>'
                    . '<h3>2. Verification</h3><p>The agency undergoes identity verification. Until approved it may prepare listings, but they will not be visible to travelers or accept bookings.</p>'
                    . '<h3>3. Commission and fee</h3><p>The agency pays commission on the net service subtotal, excluding the platform fee and insurance premium. The rate depends on its plan and monthly paid-booking volume, subject to a contractual floor. The platform fee is deducted from the agency net.</p>'
                    . '<h3>4. Settlements</h3><p>Earned commissions are settled on request or by the platform. Cancelled, refunded or disputed bookings do not earn payable commission.</p>'
                    . '<h3>5. Advertising and compliance</h3><p>The agency warrants that listings are accurate, that it holds required permits and insurance, and that it will deliver what was offered. It is liable for service-related claims.</p>'
                    . '<h3>6. Data and term</h3><p>The agency processes traveler data under applicable law and the Privacy Notice. This agreement is recorded with its version and acceptance date; amendments require a new acceptance.</p>',
            ],
            'cancellation_policy' => [
                'context' => self::CONTEXT_TRAVELER,
                'title'   => 'Política general de cancelación y reembolso',
                'es'      => '<p>La política específica de cada servicio prevalece sobre esta política general y queda congelada en la reserva. Como regla general: flexible (reembolso total hasta 24 horas antes), moderada (100% hasta 5 días y 50% hasta 2 días), estricta (50% hasta 7 días) y no reembolsable. El reembolso se limita al importe efectivamente pagado y se procesa al mismo medio de pago. La plataforma no retorna comisiones sobre reservas canceladas.</p>',
                'en'      => '<p>The policy published for each service prevails over this general policy and is frozen at booking time. As a rule: flexible (full refund up to 24 hours before), moderate (100% up to 5 days and 50% up to 2 days), strict (50% up to 7 days) and non-refundable. Refunds are capped at the amount actually paid and returned to the same payment method. The platform does not return commissions on cancelled bookings.</p>',
            ],
            'privacy_notice' => [
                'context' => self::CONTEXT_TRAVELER,
                'title'   => 'Aviso de privacidad',
                'es'      => '<p>El tratamiento de datos personales se rige por el Aviso de Privacidad publicado en el sitio y por la legislación aplicable de Costa Rica, incluida la Ley 8968. Para ejercer derechos de acceso, rectificación, supresión u oposición, utiliza el formulario de privacidad o escribe al correo de contacto indicado en el aviso.</p>',
                'en'      => '<p>Personal data processing is governed by the Privacy Notice published on the site and applicable Costa Rican law, including Law 8968. To exercise access, rectification, erasure or objection rights, use the privacy form or write to the contact email in the notice.</p>',
            ],
        ];
    }

    /** Install the first immutable version of every document. */
    public static function seed_defaults() {
        global $wpdb;
        $t = self::table_docs();
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}") > 0) { return; }
        foreach (self::default_documents() as $key => $doc) {
            foreach (['es' => $doc['es'], 'en' => $doc['en']] as $lang => $body) {
                $wpdb->insert($t, [
                    'doc_key'   => $key,
                    'context'   => $doc['context'],
                    'lang'      => $lang,
                    'version'   => 1,
                    'title'     => $doc['title'],
                    'body'      => $body,
                    'sha256'    => hash('sha256', $body),
                    'published' => 1,
                ]);
            }
        }
    }

    public static function resolve_lang() {
        return function_exists('TAP_Localization') ? TAP_Localization::resolve_lang() : 'es';
    }

    /** Current published document for a key and language. */
    public static function current($doc_key, $lang = null) {
        global $wpdb;
        $lang = $lang ?: self::resolve_lang();
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_docs() . ' WHERE doc_key = %s AND lang = %s AND published = 1 ORDER BY version DESC LIMIT 1',
            $doc_key,
            $lang
        ));
    }

    /** All published documents required for a context. */
    public static function for_context($context, $lang = null) {
        global $wpdb;
        $lang = $lang ?: self::resolve_lang();
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_docs() . ' d
             WHERE d.context = %s AND d.lang = %s AND d.published = 1
               AND d.version = (SELECT MAX(x.version) FROM ' . self::table_docs() . ' x WHERE x.doc_key = d.doc_key AND x.lang = d.lang AND x.published = 1)
             ORDER BY d.doc_key',
            $context,
            $lang
        ));
    }

    public static function by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_docs() . ' WHERE id = %d', (int) $id));
    }

    /** True when a party already accepted every current document in a context. */
    public static function is_current($context, $booking_id = 0, $agency_id = 0, $user_id = 0, $email = '') {
        $docs = self::for_context($context);
        if (!$docs) { return true; }
        global $wpdb;
        foreach ($docs as $doc) {
            $sql = 'SELECT COUNT(*) FROM ' . self::table_accept()
                . ' WHERE document_id = %d AND version = %d AND document_hash = %s';
            $params = [(int) $doc->id, (int) $doc->version, $doc->sha256];
            if ($booking_id) {
                $sql .= ' AND booking_id = %d';
                $params[] = (int) $booking_id;
            } elseif ($agency_id) {
                $sql .= ' AND agency_id = %d';
                $params[] = (int) $agency_id;
            } elseif ($email) {
                $sql .= ' AND guest_email = %s';
                $params[] = strtolower(sanitize_email($email));
            } else {
                $sql .= ' AND user_id = %d';
                $params[] = (int) $user_id;
            }
            if (!(int) $wpdb->get_var($wpdb->prepare($sql, $params))) {
                return false;
            }
        }
        return true;
    }

    public static function booking_current($booking_id, $context = self::CONTEXT_TRAVELER) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) { return false; }
        return self::is_current($context, (int) $booking->id, 0, (int) $booking->client_id, (string) $booking->guest_email);
    }

    public static function agency_current($agency_id) {
        return self::is_current(self::CONTEXT_AGENCY, 0, (int) $agency_id, 0, '');
    }

    /** Render the acceptance UI (checkbox + typed name) for a context. */
    public static function render_acceptance($context, $booking_id = 0, $agency_id = 0) {
        $docs = self::for_context($context);
        if (!$docs) { return ''; }
        $nonce_action = 'tap_legal_' . $context . '_' . (int) ($booking_id ?: $agency_id);
        ob_start();
        ?>
        <div class="tap-legal-accept" data-context="<?php echo esc_attr($context); ?>">
            <h3><?php esc_html_e('Términos y condiciones', 'travel-agency-platform'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-legal-form">
                <input type="hidden" name="action" value="tap_legal_accept">
                <input type="hidden" name="context" value="<?php echo esc_attr($context); ?>">
                <input type="hidden" name="booking_id" value="<?php echo (int) $booking_id; ?>">
                <input type="hidden" name="agency_id" value="<?php echo (int) $agency_id; ?>">
                <?php wp_nonce_field($nonce_action, 'tap_legal_nonce'); ?>
                <?php foreach ($docs as $doc): ?>
                    <details class="tap-legal-doc">
                        <summary><?php echo esc_html($doc->title . ' · v' . (int) $doc->version); ?></summary>
                        <div class="tap-legal-body"><?php echo wp_kses_post($doc->body); ?></div>
                    </details>
                <?php endforeach; ?>
                <p>
                    <label>
                        <input type="checkbox" name="tap_legal_consent" value="1" required>
                        <?php esc_html_e('He leído y acepto los términos anteriores.', 'travel-agency-platform'); ?>
                    </label>
                </p>
                <p>
                    <label for="tap-legal-name"><?php esc_html_e('Nombre completo del firmante', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap-legal-name" name="signer_name" class="regular-text" required autocomplete="name">
                </p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Aceptar y firmar', 'travel-agency-platform'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Store an acceptance with full evidence and email a receipt. */
    public static function handle_accept() {
        $context = sanitize_key(wp_unslash($_POST['context'] ?? ''));
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $agency_id = absint($_POST['agency_id'] ?? 0);
        check_admin_referer('tap_legal_' . $context . '_' . ($booking_id ?: $agency_id), 'tap_legal_nonce');
        if (empty($_POST['tap_legal_consent'])) {
            wp_safe_redirect(wp_get_referer() ?: home_url());
            exit;
        }
        $signer = sanitize_text_field(wp_unslash($_POST['signer_name'] ?? ''));
        if ('' === $signer) {
            wp_safe_redirect(add_query_arg('tap_legal_error', 'name', wp_get_referer() ?: home_url()));
            exit;
        }

        $booking = $booking_id ? TAP_Booking::get_booking($booking_id) : null;
        $uid = get_current_user_id();
        $email = '';
        if ($booking) {
            $email = (string) $booking->guest_email;
            $ok = $uid
                ? ((int) $booking->client_id === $uid || current_user_can('manage_options'))
                : ((int) $booking->client_id === 0 && TAP_Guest_Checkout::get_guest_pay_token($booking->booking_code));
            if (!$ok && $email && strcasecmp(sanitize_email(wp_unslash($_GET['email'] ?? '')), $email) !== 0) {
                wp_die(esc_html__('No tienes permiso para firmar estos términos.', 'travel-agency-platform'), '', ['response' => 403]);
            }
        } elseif ($agency_id) {
            $linked = TAP_Booking::get_agency_for_user($uid);
            if (!$linked || (int) $linked !== $agency_id) {
                wp_die(esc_html__('No tienes permiso para firmar estos términos.', 'travel-agency-platform'), '', ['response' => 403]);
            }
        } elseif (!$uid) {
            wp_die(esc_html__('Debes iniciar sesión.', 'travel-agency-platform'), '', ['response' => 403]);
        }

        global $wpdb;
        $documents = self::for_context($context);
        foreach ($documents as $doc) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $wpdb->insert(self::table_accept(), [
                'document_id'   => (int) $doc->id,
                'doc_key'       => $doc->doc_key,
                'version'       => (int) $doc->version,
                'document_hash' => $doc->sha256,
                'booking_id'    => (int) $booking_id,
                'agency_id'     => (int) $agency_id,
                'user_id'       => (int) $uid,
                'guest_email'   => $email ? strtolower(sanitize_email($email)) : null,
                'context'       => $context,
                'signer_name'   => $signer,
                'ip_hash'       => hash('sha256', $ip . wp_salt('auth')),
                'ua_hash'       => hash('sha256', $ua . wp_salt('auth')),
                'accepted_at'   => current_time('mysql'),
            ]);
            self::send_receipt((int) $wpdb->insert_id);
        }

        if ($booking) {
            update_post_meta((int) $booking_id, '_tap_legal_snapshot', wp_json_encode(array_map(static function ($d) {
                return ['key' => $d->doc_key, 'version' => (int) $d->version, 'hash' => $d->sha256];
            }, $documents)));
        }
        wp_safe_redirect(add_query_arg('tap_legal_ok', '1', wp_get_referer() ?: home_url()));
        exit;
    }

    /** Email a signed copy as evidence of acceptance. */
    public static function send_receipt($acceptance_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_accept() . ' WHERE id = %d', (int) $acceptance_id));
        if (!$row || $row->email_sent_at) { return false; }
        $doc = self::by_id((int) $row->document_id);
        $to = $row->guest_email;
        if (!$to && $row->user_id) {
            $user = get_userdata((int) $row->user_id);
            $to = $user ? $user->user_email : '';
        }
        if (!is_email($to)) { return false; }
        $body = '<p>' . sprintf(
            /* translators: 1: document title, 2: version, 3: date, 4: signer */
            esc_html__('Has aceptado %1$s (versión %2$d) el %3$s. Firmante: %4$s.', 'travel-agency-platform'),
            esc_html($doc ? $doc->title : $row->doc_key),
            (int) $row->version,
            esc_html($row->accepted_at),
            esc_html($row->signer_name)
        ) . '</p><p>' . esc_html__('Hash del documento:', 'travel-agency-platform') . ' <code>' . esc_html($row->document_hash) . '</code></p>';
        if (class_exists('TAP_Emails_Send')) {
            $ok = TAP_Emails_Send::send($to, __('Confirmación de términos aceptados', 'travel-agency-platform'), $body);
        } else {
            $ok = wp_mail($to, __('Confirmación de términos aceptados', 'travel-agency-platform'), $body, ['Content-Type: text/html; charset=UTF-8']);
        }
        if ($ok) {
            $wpdb->update(self::table_accept(), ['email_sent_at' => current_time('mysql')], ['id' => (int) $acceptance_id]);
        }
        return (bool) $ok;
    }

    /** [tap_legal_doc key="traveler_terms"] — public, read-only document view. */
    public static function document_shortcode($atts = []) {
        $atts = shortcode_atts(['key' => 'traveler_terms', 'lang' => ''], $atts, 'tap_legal_doc');
        $doc = self::current(sanitize_key($atts['key']), $atts['lang'] ? sanitize_key($atts['lang']) : null);
        if (!$doc) {
            return '<p>' . esc_html__('Documento no disponible.', 'travel-agency-platform') . '</p>';
        }
        return '<article class="tap-legal-doc-public"><h1>' . esc_html($doc->title)
            . '</h1><p class="tap-legal-version">' . esc_html(sprintf(__('Versión %d · vigente desde %s', 'travel-agency-platform'), (int) $doc->version, $doc->effective_from)) . '</p>'
            . wp_kses_post($doc->body)
            . '<p class="tap-legal-hash"><small>' . esc_html__('Hash del documento:', 'travel-agency-platform')
            . ' <code>' . esc_html($doc->sha256) . '</code></small></p></article>';
    }

    /** [tap_my_contracts] — signed documents for the current user. */
    public static function my_contracts($atts = []) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Inicia sesión para ver tus contratos.', 'travel-agency-platform') . '</p>';
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT a.*, d.title FROM ' . self::table_accept() . ' a
             LEFT JOIN ' . self::table_docs() . ' d ON d.id = a.document_id
             WHERE a.user_id = %d ORDER BY a.accepted_at DESC LIMIT 100',
            get_current_user_id()
        ));
        ob_start();
        echo '<div class="tap-my-contracts"><h2>' . esc_html__('Mis contratos', 'travel-agency-platform') . '</h2>';
        if (!$rows) {
            echo '<p>' . esc_html__('Aún no has firmado contratos.', 'travel-agency-platform') . '</p>';
        } else {
            echo '<table class="tap-table"><thead><tr><th>' . esc_html__('Documento', 'travel-agency-platform') . '</th><th>' . esc_html__('Versión', 'travel-agency-platform') . '</th><th>' . esc_html__('Fecha', 'travel-agency-platform') . '</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . esc_html($r->title ?: $r->doc_key) . '</td><td>v' . (int) $r->version . '</td><td>' . esc_html($r->accepted_at) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /** Admin: document versions and recent acceptances. */
    public static function admin_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        global $wpdb;
        self::seed_defaults();
        if (!empty($_POST['tap_legal_publish'])) {
            check_admin_referer('tap_legal_publish');
            $id = absint($_POST['doc_id'] ?? 0);
            $doc = self::by_id($id);
            if ($doc) {
                $body = wp_unslash($_POST['body'] ?? '');
                $wpdb->insert(self::table_docs(), [
                    'doc_key'   => $doc->doc_key,
                    'context'   => $doc->context,
                    'lang'      => $doc->lang,
                    'version'   => (int) $doc->version + 1,
                    'title'     => $doc->title,
                    'body'      => $body,
                    'sha256'    => hash('sha256', $body),
                    'published' => 1,
                ]);
                echo '<div class="notice notice-success"><p>' . esc_html__('Nueva versión publicada.', 'travel-agency-platform') . '</p></div>';
            }
        }
        $docs = $wpdb->get_results('SELECT * FROM ' . self::table_docs() . ' ORDER BY doc_key, lang, version DESC');
        $accepts = $wpdb->get_results('SELECT * FROM ' . self::table_accept() . ' ORDER BY accepted_at DESC LIMIT 30');
        echo '<div class="wrap"><h1>' . esc_html__('Contratos digitales', 'travel-agency-platform') . '</h1>';
        echo '<h2>' . esc_html__('Documentos', 'travel-agency-platform') . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Documento', 'travel-agency-platform') . '</th><th>' . esc_html__('Idioma', 'travel-agency-platform') . '</th><th>' . esc_html__('Versión', 'travel-agency-platform') . '</th><th>' . esc_html__('Hash', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($docs as $d) {
            echo '<tr><td>' . esc_html($d->title) . '</td><td>' . esc_html($d->lang) . '</td><td>v' . (int) $d->version . '</td><td><code>' . esc_html(substr($d->sha256, 0, 12)) . '</code>'
                . '<form method="post" style="display:inline;">' . wp_nonce_field('tap_legal_publish', '_wpnonce', true, false)
                . '<input type="hidden" name="doc_id" value="' . (int) $d->id . '">'
                . '<textarea name="body" style="display:none;">' . esc_textarea($d->body) . '</textarea>'
                . '<button class="button button-small" name="tap_legal_publish" value="1">' . esc_html__('Nueva versión', 'travel-agency-platform') . '</button></form></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Aceptaciones recientes', 'travel-agency-platform') . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Documento', 'travel-agency-platform') . '</th><th>' . esc_html__('Firmante', 'travel-agency-platform') . '</th><th>' . esc_html__('Reserva', 'travel-agency-platform') . '</th><th>' . esc_html__('Fecha', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($accepts as $a) {
            echo '<tr><td>' . esc_html($a->doc_key . ' v' . (int) $a->version) . '</td><td>' . esc_html($a->signer_name) . '</td><td>#' . (int) $a->booking_id . '</td><td>' . esc_html($a->accepted_at) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
