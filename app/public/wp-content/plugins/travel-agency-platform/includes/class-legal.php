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
        add_action('admin_post_tap_legal_publish', [__CLASS__, 'handle_publish']);
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

    /**
     * Canonical texts live in docs/legal/*.html so lawyers review one file and
     * the plugin seeds exactly that text. Falls back to a short notice if a
     * file is missing, so a deployment never breaks the acceptance flow.
     *
     * @return array<string,array{context:string,title:string,es:string,en:string}>
     */
    /** True while a document still has unfilled legal placeholders. */
    public static function has_placeholders($body) {
        return (bool) preg_match('/\[(?:COMPLETAR|COMPLETE|NOMBRE LEGAL|LEGAL NAME|DIRECCI|ADDRESS|CORREO|EMAIL|tap_privacy_contact)/i', (string) $body);
    }

    /** Blocks signing while any current document is still a template. */
    public static function catalogue_ready($context) {
        foreach (self::for_context($context) as $doc) {
            if (self::has_placeholders($doc->body)) { return false; }
        }
        return true;
    }

    public static function default_documents() {
        $catalog = [
            'traveler_terms'     => [self::CONTEXT_TRAVELER, 'Términos y condiciones del viajero'],
            'agency_terms'       => [self::CONTEXT_AGENCY, 'Contrato de agencia y términos de plataforma'],
            'cancellation_policy'=> [self::CONTEXT_TRAVELER, 'Política general de cancelación y reembolso'],
            'privacy_notice'     => [self::CONTEXT_TRAVELER, 'Aviso de privacidad'],
        ];
        // Canonical files ship inside the plugin so production works too.
        $dir = dirname(__DIR__, 1) . '/legal/';
        $out = [];
        foreach ($catalog as $key => $meta) {
            $fallback = '<p>' . esc_html__('Texto legal pendiente de publicación. Consulta la versión definitiva en el sitio.', 'travel-agency-platform') . '</p>';
            $load = static function ($lang) use ($dir, $key, $fallback) {
                $file = $dir . $key . '.' . $lang . '.html';
                if (!is_readable($file)) { return $fallback; }
                $html = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                return '' !== trim($html) ? $html : $fallback;
            };
            $out[$key] = [
                'context' => $meta[0],
                'title'   => $meta[1],
                'es'      => $load('es'),
                'en'      => $load('en'),
            ];
        }
        return $out;
    }

    /** Install the first immutable version of every document. */
    public static function seed_defaults() {
        global $wpdb;
        $t = self::table_docs();
        foreach (self::default_documents() as $key => $doc) {
            foreach (['es' => $doc['es'], 'en' => $doc['en']] as $lang => $body) {
                $hash = hash('sha256', $body);
                $current = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . $t . ' WHERE doc_key = %s AND lang = %s AND published = 1 ORDER BY version DESC LIMIT 1',
                    $key,
                    $lang
                ));
                // A changed canonical text creates a new version: old versions
                // stay immutable and previous acceptations become outdated.
                if ($current && $current->sha256 === $hash) { continue; }
                $wpdb->insert($t, [
                    'doc_key'   => $key,
                    'context'   => $doc['context'],
                    'lang'      => $lang,
                    'version'   => $current ? (int) $current->version + 1 : 1,
                    'title'     => $doc['title'],
                    'body'      => $body,
                    'sha256'    => $hash,
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
            <p class="tap-legal-intro"><?php esc_html_e('Revisa los documentos vigentes y acéptalos para continuar. Se guardará la versión exacta, la fecha y tu nombre como constancia.', 'travel-agency-platform'); ?></p>
            <?php if (!self::catalogue_ready($context)): ?>
                <p class="tap-legal-note"><?php esc_html_e('Los términos legales están en revisión. No puedes continuar hasta que se publique la versión definitiva.', 'travel-agency-platform'); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tap-legal-form">
                <input type="hidden" name="action" value="tap_legal_accept">
                <input type="hidden" name="context" value="<?php echo esc_attr($context); ?>">
                <input type="hidden" name="booking_id" value="<?php echo (int) $booking_id; ?>">
                <input type="hidden" name="agency_id" value="<?php echo (int) $agency_id; ?>">
                <?php wp_nonce_field($nonce_action, 'tap_legal_nonce'); ?>
                <div class="tap-legal-docs">
                    <?php foreach ($docs as $doc): ?>
                        <details class="tap-legal-doc">
                            <summary>
                                <span><?php echo esc_html($doc->title); ?></span>
                                <span class="tap-legal-version">v<?php echo (int) $doc->version; ?> · <?php echo esc_html(substr((string) $doc->effective_from, 0, 10)); ?></span>
                            </summary>
                            <div class="tap-legal-body"><?php echo wp_kses_post($doc->body); ?></div>
                        </details>
                        <label class="tap-legal-consent-row">
                            <input type="checkbox" name="tap_legal_consent[]" value="<?php echo (int) $doc->id; ?>" required>
                            <span>
                                <?php
                                /* translators: %s: document title */
                                echo esc_html(sprintf(__('He leído y acepto: %s', 'travel-agency-platform'), $doc->title));
                                ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="tap-legal-name">
                    <label for="tap-legal-name"><?php esc_html_e('Nombre completo del firmante', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap-legal-name" name="signer_name" class="regular-text" required autocomplete="name">
                </p>
                <button type="submit" class="button button-primary tap-legal-submit"><?php esc_html_e('Aceptar y firmar', 'travel-agency-platform'); ?></button>
                <p class="tap-legal-note"><?php esc_html_e('Al firmar se registra la versión del documento, la fecha y una constancia enviada a tu correo.', 'travel-agency-platform'); ?></p>
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
        if (!self::catalogue_ready($context)) {
            wp_die(esc_html__('Los términos legales aún no están publicados. Contacta a soporte.', 'travel-agency-platform'), '', ['response' => 400]);
        }
        $documents = self::for_context($context);
        $checked = array_map('absint', (array) ($_POST['tap_legal_consent'] ?? []));
        $required = array_map(static function ($d) {
            return (int) $d->id;
        }, $documents);
        if (!$documents || array_diff($required, $checked)) {
            wp_safe_redirect(add_query_arg('tap_legal_error', 'consent', wp_get_referer() ?: home_url()));
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

    /** Publish a new immutable version edited by an administrator. */
    public static function handle_publish() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        $id = absint($_POST['doc_id'] ?? 0);
        check_admin_referer('tap_legal_publish_' . $id);
        global $wpdb;
        $doc = self::by_id($id);
        if (!$doc) { wp_die(esc_html__('Documento no encontrado.', 'travel-agency-platform')); }
        $body = wp_unslash($_POST['body'] ?? '');
        if ('' === trim($body)) {
            wp_safe_redirect(add_query_arg('legal_error', 'empty', admin_url('admin.php?page=tap-legal')));
            exit;
        }
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
        wp_safe_redirect(add_query_arg('legal_published', '1', admin_url('admin.php?page=tap-legal')));
        exit;
    }

    /** Admin: document versions, editor and recent acceptances. */
    public static function admin_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Acceso denegado.', 'travel-agency-platform')); }
        global $wpdb;
        self::seed_defaults();
        $docs = $wpdb->get_results('SELECT * FROM ' . self::table_docs() . ' ORDER BY doc_key, lang, version DESC');
        $current_docs = [];
        foreach ($docs as $d) {
            $key = $d->doc_key . '_' . $d->lang;
            if (!isset($current_docs[$key])) { $current_docs[$key] = $d; }
        }
        $accepts = $wpdb->get_results('SELECT * FROM ' . self::table_accept() . ' ORDER BY accepted_at DESC LIMIT 30');

        echo '<div class="wrap"><h1>' . esc_html__('Contratos digitales', 'travel-agency-platform') . '</h1>';
        if (isset($_GET['legal_published'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Nueva versión publicada. Las aceptaciones anteriores quedan desactualizadas.', 'travel-agency-platform') . '</p></div>';
        }
        if (isset($_GET['legal_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('El documento no puede estar vacío.', 'travel-agency-platform') . '</p></div>';
        }
        echo '<p class="description">' . esc_html__('Edita el texto y publica una versión nueva. Cada versión es inmutable y lleva un hash SHA-256; los firmantes deberán aceptar de nuevo.', 'travel-agency-platform') . '</p>';

        foreach ($current_docs as $doc) {
            $ready = !self::has_placeholders($doc->body);
            echo '<div class="tap-legal-admin-doc" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:14px 0;max-width:900px;">';
            echo '<h2>' . esc_html($doc->title . ' · ' . strtoupper($doc->lang) . ' · v' . (int) $doc->version) . '</h2>';
            if (!$ready) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html__('ATENCIÓN: este texto contiene marcadores sin completar. No se permite firmar hasta publicar la versión definitiva.', 'travel-agency-platform') . '</p></div>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="tap_legal_publish">';
            echo '<input type="hidden" name="doc_id" value="' . (int) $doc->id . '">';
            wp_nonce_field('tap_legal_publish_' . (int) $doc->id);
            echo '<textarea name="body" rows="12" style="width:100%;font-family:monospace;">' . esc_textarea($doc->body) . '</textarea>';
            echo '<p><button class="button button-primary" type="submit">' . esc_html__('Publicar nueva versión', 'travel-agency-platform') . '</button>'
                . ' <code>' . esc_html(substr($doc->sha256, 0, 16)) . '</code></p>';
            echo '</form></div>';
        }

        echo '<h2>' . esc_html__('Historial de versiones', 'travel-agency-platform') . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Documento', 'travel-agency-platform') . '</th><th>' . esc_html__('Idioma', 'travel-agency-platform') . '</th><th>' . esc_html__('Versión', 'travel-agency-platform') . '</th><th>' . esc_html__('Hash', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($docs as $d) {
            echo '<tr><td>' . esc_html($d->title) . '</td><td>' . esc_html($d->lang) . '</td><td>v' . (int) $d->version . '</td><td><code>' . esc_html(substr($d->sha256, 0, 12)) . '</code></td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Aceptaciones recientes', 'travel-agency-platform') . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Documento', 'travel-agency-platform') . '</th><th>' . esc_html__('Firmante', 'travel-agency-platform') . '</th><th>' . esc_html__('Reserva', 'travel-agency-platform') . '</th><th>' . esc_html__('Fecha', 'travel-agency-platform') . '</th></tr></thead><tbody>';
        foreach ($accepts as $a) {
            echo '<tr><td>' . esc_html($a->doc_key . ' v' . (int) $a->version) . '</td><td>' . esc_html($a->signer_name) . '</td><td>#' . (int) $a->booking_id . '</td><td>' . esc_html($a->accepted_at) . '</td></tr>';
        }
        if (!$accepts) {
            echo '<tr><td colspan="4">' . esc_html__('Sin aceptaciones.', 'travel-agency-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
