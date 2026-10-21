<?php
defined('ABSPATH') || exit;

class TAP_Ajax {
    public static function create_booking() {
        // Two front-end booking forms historically send distinct nonces:
        // the accommodation template sends a 'tap_booking_nonce' nonce, while the
        // [tap_booking_form] shortcode sends tap_ajax.nonce (action 'tap_nonce').
        // Accept either to keep both forms working.
        $valid = check_ajax_referer('tap_nonce', 'nonce', false)
            || check_ajax_referer('tap_booking_nonce', 'nonce', false);
        if (!$valid) {
            wp_send_json_error(['message' => __('Security check failed.', 'travel-agency-platform')], 403);
        }

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['fake' => true]);
        }

        // Guest checkout: logged-out visitors book with client_id = 0 and their
        // contact data in the guest_* columns. Emails are spam-guarded by a
        // per-IP+email rate limit, then a short-lived token allows paying on /checkout.
        $guest_name  = sanitize_text_field(wp_unslash($_POST['guest_name'] ?? ''));
        $guest_email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));

        if (empty($_POST['tap_privacy_consent'])) {
            wp_send_json_error(['message' => __('Debes aceptar el Aviso de Privacidad y consentir el tratamiento de tus datos para completar la reserva.', 'travel-agency-platform')]);
        }

        if (!is_user_logged_in()) {
            if ('' === $guest_name || !is_email($guest_email)) {
                wp_send_json_error(['message' => __('Indica tu nombre y un correo electrónico válido para completar la reserva.', 'travel-agency-platform')]);
            }

            if (TAP_Guest_Checkout::guest_book_rate_blocked($guest_email)) {
                wp_send_json_error(['message' => __('Demasiadas reservas en poco tiempo. Intenta nuevamente más tarde.', 'travel-agency-platform')]);
            }
        } elseif (TAP_Rate_Limit::too_many('booking', 20, HOUR_IN_SECONDS, 'u' . get_current_user_id())) {
            // The guest bucket above only guards logged-out visitors, so a
            // single registered account could otherwise flood agencies with
            // unlimited bookings. Same throttle, keyed by the account.
            wp_send_json_error(['message' => __('Demasiadas reservas en poco tiempo. Intenta nuevamente más tarde.', 'travel-agency-platform')]);
        }

        $required = ['service_type', 'service_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                /* translators: %s: nombre del campo obligatorio */
                wp_send_json_error(['message' => sprintf(__('Field %s is required', 'travel-agency-platform'), $field)]);
            }
        }

        $room_id  = !empty($_POST['room_id']) ? intval($_POST['room_id']) : 0;
        $check_in = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $adults   = intval($_POST['adults'] ?? 1);
        $children = intval($_POST['children'] ?? 0);

        $total = TAP_Booking_Pricing::calculate_price(
            sanitize_text_field(wp_unslash($_POST['service_type'])),
            intval($_POST['service_id']),
            $check_in, $check_out, $adults, $children, $room_id
        );

        $data = [
            'service_type' => sanitize_text_field(wp_unslash($_POST['service_type'])),
            'service_id'   => intval($_POST['service_id']),
            'room_id'      => $room_id,
            'check_in'     => $check_in,
            'check_out'    => $check_out,
            'adults'       => $adults,
            'children'     => $children,
            'total_amount' => $total,
            'notes'        => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            'guest_name'   => sanitize_text_field(wp_unslash($_POST['guest_name'] ?? '')),
            'guest_email'  => sanitize_email(wp_unslash($_POST['guest_email'] ?? '')),
            'guest_phone'  => sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? '')),
            'idempotency_key' => sanitize_text_field(wp_unslash($_POST['idempotency_key'] ?? '')),
        ];

        $result = TAP_Booking::create($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (!is_user_logged_in() && is_email($guest_email)) {
            TAP_Guest_Checkout::guest_book_rate_bump($guest_email);
            TAP_Guest_Checkout::set_guest_pay_token($result['booking_code']);
        }

        $consent_email = is_user_logged_in() ? wp_get_current_user()->user_email : $guest_email;
        if (is_email($consent_email)) {
            TAP_Privacy::record_consent($consent_email, 'booking', get_current_user_id());
        }

        wp_send_json_success($result + ['redirect' => TAP_Booking::redirect_target(TAP_Booking::get_booking($result['booking_id']))]);
    }

    /* ===== Guest checkout helpers (testable, no die) ===== */

    public static function cancel_booking() {
        check_ajax_referer('tap_booking_nonce', 'nonce');

        $booking_id   = intval($_POST['booking_id'] ?? 0);
        $booking_code = sanitize_text_field(wp_unslash($_POST['booking_code'] ?? ''));

        if (!$booking_id && !$booking_code) {
            wp_send_json_error(['message' => __('Reserva inválida.', 'travel-agency-platform')]);
        }

        $uid = get_current_user_id();
        $guest_email = $uid ? '' : sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));

        // Guests should always present the printed booking code along with the
        // numeric id (see client_cancel_request), but a valid id + matching
        // email (proving ownership) remains accepted for older clients.
        $result = TAP_Booking::client_cancel_request($booking_id, $uid, $guest_email, $booking_code);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Tu reserva ha sido cancelada.', 'travel-agency-platform')]);
    }

    /** Guest/registered client sends a message on their booking thread. */
    public static function chat_client_send() {
        check_ajax_referer('tap_booking_nonce', 'nonce');

        $booking_id   = intval($_POST['booking_id'] ?? 0);
        $booking_code = sanitize_text_field(wp_unslash($_POST['booking_code'] ?? ''));
        $message      = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $uid          = get_current_user_id();

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking && $booking_code) {
            $booking = TAP_Booking::get_booking_by_code($booking_code);
        }
        if (!$booking) {
            wp_send_json_error(['message' => __('Reserva no encontrada.', 'travel-agency-platform')]);
        }

        $guest_email = '';
        if (!$uid) {
            $guest_email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
        }
        // This endpoint writes 'client' messages. An agency member may pass the
        // ownership check on their own bookings, which would let them post as
        // the traveler: the guest UI would render it as the guest's own bubble,
        // and mark_read() would clear the agency's unread badge. Agency staff
        // have tap_dash_chat_send for the other side of the thread.
        if ($uid && self::caller_is_agency_member($uid)) {
            wp_send_json_error(['message' => __('Usa el panel de la agencia para responder en el chat.', 'travel-agency-platform')], 403);
        }
        list($can, $name) = TAP_Chat::can_client_chat($booking, $uid, $guest_email);
        if (!$can) {
            $msg = $uid ? __('No tienes permiso para escribir aquí.', 'travel-agency-platform') : __('Verifica el correo de la reserva para escribir.', 'travel-agency-platform');
            wp_send_json_error(['message' => $msg], 403);
        }

        $result = TAP_Chat::send($booking->id, 'client', $uid, $name, $message);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        TAP_Chat::mark_read($booking->id, 'client');
        $row = TAP_Chat::messages($booking->id, 200);
        wp_send_json_success(['row' => TAP_Chat::row_markup(end($row), 'client')]);
    }

    /** Client/guest polls their booking thread for live updates. */
    public static function chat_client_thread() {
        check_ajax_referer('tap_booking_nonce', 'nonce');

        $booking_id   = intval($_POST['booking_id'] ?? 0);
        $booking_code = sanitize_text_field(wp_unslash($_POST['booking_code'] ?? ''));
        $uid          = get_current_user_id();

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking && $booking_code) {
            $booking = TAP_Booking::get_booking_by_code($booking_code);
        }
        if (!$booking) {
            wp_send_json_error(['message' => __('Reserva no encontrada.', 'travel-agency-platform')], 404);
        }

        $guest_email = $uid ? '' : sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
        list($can, ) = TAP_Chat::can_client_chat($booking, $uid, $guest_email);
        if (!$can) {
            wp_send_json_error(['message' => __('No tienes permiso sobre esta reserva.', 'travel-agency-platform')], 403);
        }

        TAP_Chat::mark_read($booking->id, 'client');
        $rows = TAP_Chat::messages($booking->id);
        $html = '';
        foreach ($rows as $m) {
            $html .= TAP_Chat::row_markup($m, 'client');
        }
        wp_send_json_success([
            'html'    => $html,
            'count'   => count($rows),
            'last_id' => $rows ? (int) end($rows)->id : 0,
        ]);
    }

    public static function update_booking_status() {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $status     = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

        $current_user = is_user_logged_in() ? wp_get_current_user() : null;
        $is_admin  = current_user_can('manage_options') || current_user_can('tap_manage_bookings');
        $is_agency = $current_user &&
            (in_array('tap_agency_admin', (array) $current_user->roles, true) ||
            in_array('tap_agency_employee', (array) $current_user->roles, true));

        if (!$is_admin && !$is_agency) {
            wp_send_json_error(['message' => __('Unauthorized', 'travel-agency-platform')]);
        }

        if ($is_admin) {
            check_ajax_referer('tap_admin_booking', '_ajax_nonce');
        }

        if ($is_agency && !$is_admin) {
            check_ajax_referer('tap_agency_nonce', 'nonce');

            $agency_id = TAP_Booking::get_agency_for_user($current_user->ID);
            if (!$agency_id) {
                wp_send_json_error(['message' => __('No agency linked to your account.', 'travel-agency-platform')]);
            }

            $booking = TAP_Booking::get_booking($booking_id);
            if (!$booking || intval($booking->agency_id) !== $agency_id) {
                wp_send_json_error(['message' => __('You can only manage bookings from your own agency.', 'travel-agency-platform')]);
            }

            // A paid booking cannot be cancelled by agency staff: cancelling it
            // triggers a real PayPal refund, which only platform managers may
            // authorise.
            if (in_array($status, ['cancelled', 'refunded'], true) && $booking->payment_status === 'paid') {
                wp_send_json_error(['message' => __('Solo los administradores pueden cancelar reservas pagadas.', 'travel-agency-platform')]);
            }

            if ($booking->status === 'request') {
                if (!in_array($status, ['pending', 'cancelled'], true)) {
                    wp_send_json_error(['message' => __('Solo puedes Aceptar (pasa a pendiente de pago) o Rechazar una solicitud.', 'travel-agency-platform')]);
                }
            } elseif (!in_array($status, ['confirmed', 'completed', 'cancelled'], true)) {
                wp_send_json_error(['message' => __('Invalid status.', 'travel-agency-platform')]);
            }
        }

        $result = TAP_Booking::update_status($booking_id, $status);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Status updated', 'travel-agency-platform')]);
    }

    public static function agency_register() {
        check_ajax_referer('tap_register_nonce', 'nonce');

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['message' => __('Gracias por tu interés.', 'travel-agency-platform')]);
        }

        // Tanda C (C15): rate limit per IP so bots cannot mass-register agencies.
        if (TAP_Rate_Limit::too_many('agency_reg', 5, HOUR_IN_SECONDS)) {
            wp_send_json_error(['message' => __('Demasiados registros en poco tiempo. Intenta nuevamente más tarde.', 'travel-agency-platform')]);
        }

        $fullname    = sanitize_text_field(wp_unslash($_POST['agency_name'] ?? ''));
        $username    = sanitize_user(wp_unslash($_POST['username'] ?? ''), true);
        $email       = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password    = (string) wp_unslash($_POST['password'] ?? '');
        $phone       = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $whatsapp    = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));
        $website     = esc_url_raw(wp_unslash($_POST['website'] ?? ''));
        $address     = sanitize_textarea_field(wp_unslash($_POST['address'] ?? ''));
        $city        = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $country     = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));

        $kyc = [
            'legal_name'   => sanitize_text_field(wp_unslash($_POST['kyc_legal_name'] ?? '')),
            'doc_type'     => in_array((string) ($_POST['kyc_doc_type'] ?? ''), ['fisica', 'juridica'], true) ? sanitize_text_field(wp_unslash($_POST['kyc_doc_type'])) : '',
            'doc_number'   => sanitize_text_field(wp_unslash($_POST['kyc_doc_number'] ?? '')),
            'legal_tax_id' => sanitize_text_field(wp_unslash($_POST['kyc_legal_tax_id'] ?? '')),
        ];
        $kyc_accept = !empty($_POST['kyc_accept']);

        if (empty($fullname)) {
            wp_send_json_error(['message' => __('Agency name is required.', 'travel-agency-platform')]);
        }
        if (empty($username) || !validate_username($username)) {
            wp_send_json_error(['message' => __('Please enter a valid username (letters and numbers only).', 'travel-agency-platform')]);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'travel-agency-platform')]);
        }
        if (strlen($password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters.', 'travel-agency-platform')]);
        }
        if (username_exists($username)) {
            wp_send_json_error(['message' => __('That username is already taken.', 'travel-agency-platform')]);
        }
        if (email_exists($email)) {
            wp_send_json_error(['message' => __('That email is already registered.', 'travel-agency-platform')]);
        }

        if (empty($kyc['legal_name'])) {
            wp_send_json_error(['message' => __('Indica el nombre del representante legal o de la empresa.', 'travel-agency-platform')]);
        }
        if (!in_array($kyc['doc_type'], ['fisica', 'juridica'], true) || empty($kyc['doc_number'])) {
            wp_send_json_error(['message' => __('Indica el tipo y número de documento (cédula).', 'travel-agency-platform')]);
        }
        if ($kyc['doc_type'] === 'juridica' && empty($kyc['legal_tax_id'])) {
            wp_send_json_error(['message' => __('Para persona jurídica indica la cédula jurídica.', 'travel-agency-platform')]);
        }
        if (!$kyc_accept) {
            wp_send_json_error(['message' => __('Debes aceptar los términos y condiciones.', 'travel-agency-platform')]);
        }
        if (empty($_POST['tap_privacy_consent'])) {
            wp_send_json_error(['message' => __('Debes aceptar el Aviso de Privacidad y consentir el tratamiento de tus datos para registrar tu agencia.', 'travel-agency-platform')]);
        }

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $fullname,
            'first_name'   => $fullname,
            'role'         => 'tap_agency_admin',
            'user_url'     => $website,
        ]);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        $user_id = (int) $user_id;
        $agency_id = wp_insert_post([
            'post_type'    => 'tap_agency',
            'post_status'  => 'publish',
            'post_title'   => $fullname,
            'post_name'    => sanitize_title($username),
            'post_content' => $description,
        ]);

        if (is_wp_error($agency_id) || !$agency_id) {
            wp_delete_user($user_id);
            wp_send_json_error(['message' => __('Could not create the agency. Please try again.', 'travel-agency-platform')]);
        }

        $commission = get_option('tap_commission_default', 10);

        $metas = [
            '_tap_agency_user_id'    => $user_id,
            '_tap_agency_email'      => $email,
            '_tap_agency_phone'      => $phone,
            '_tap_agency_whatsapp'   => $whatsapp,
            '_tap_agency_website'    => $website,
            '_tap_agency_address'    => $address,
            '_tap_agency_city'       => $city,
            '_tap_agency_country'    => $country,
            '_tap_agency_commission' => $commission,
            '_tap_agency_verified'   => '',
            '_tap_agency_is_active'  => '0',
            '_tap_agency_status'     => TAP_Approval::PENDING,
        ];
        foreach ($metas as $key => $value) {
            update_post_meta($agency_id, $key, $value);
        }
        TAP_Approval::save_kyc($agency_id, $kyc);

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'tap_agencies',
            [
                'user_id'            => $user_id,
                'name'               => $fullname,
                'slug'               => get_post_field('post_name', $agency_id),
                'description'        => $description,
                'email'              => $email,
                'phone'              => $phone,
                'whatsapp'           => $whatsapp,
                'website'            => $website,
                'address'            => $address,
                'city'               => $city,
                'country'            => $country,
                'commission_percent' => $commission,
                'is_verified'        => 0,
                'is_active'          => 0,
            ]
        );

        do_action('tap_agency_registered', $user_id, $agency_id);

        if (is_email($email)) {
            TAP_Privacy::record_consent($email, 'agency_registration', $user_id);
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        wp_send_json_success([
            'message'  => __('Tu agencia está en revisión. Te avisaremos por correo cuando sea aprobada.', 'travel-agency-platform'),
            'redirect' => home_url('/mi-cuenta/'),
        ]);
    }

    public static function submit_lead() {
        check_ajax_referer('tap_lead_nonce', 'nonce');

        if (!empty($_POST['tap_hp'])) {
            wp_send_json_success(['message' => __('Gracias por tu mensaje.', 'travel-agency-platform')]);
        }

        $result = TAP_Leads::submit([
            'name'       => sanitize_text_field(wp_unslash($_POST['tap_name'] ?? '')),
            'email'      => sanitize_email(wp_unslash($_POST['tap_email'] ?? '')),
            'phone'      => sanitize_text_field(wp_unslash($_POST['tap_phone'] ?? '')),
            'message'    => sanitize_textarea_field(wp_unslash($_POST['tap_message'] ?? '')),
            'agency_id'  => intval($_POST['agency_id'] ?? 0),
            'service_id' => intval($_POST['service_id'] ?? 0),
            'consent'    => sanitize_key(wp_unslash($_POST['tap_privacy_consent'] ?? '')),
            'source'     => isset($_POST['service_id']) && (int) $_POST['service_id'] > 0 ? 'service' : 'agency',
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Mensaje enviado. La agencia te contactará pronto.', 'travel-agency-platform')]);
    }

    public static function toggle_favorite() {
        check_ajax_referer('tap_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['needs_login' => true, 'message' => __('Please log in to save favorites.', 'travel-agency-platform')]);
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !in_array(get_post_type($post_id), ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], true)) {
            wp_send_json_error(['message' => __('Invalid service.', 'travel-agency-platform')]);
        }

        $user_id  = get_current_user_id();
        $favorites = get_user_meta($user_id, 'tap_favorites', true);
        if (!is_array($favorites)) {
            $favorites = [];
        }

        $added = false;
        if (in_array($post_id, $favorites, true)) {
            $favorites = array_values(array_diff($favorites, [$post_id]));
        } else {
            $favorites[] = $post_id;
            $added = true;
        }

        update_user_meta($user_id, 'tap_favorites', array_map('intval', array_unique($favorites)));

        wp_send_json_success([
            'added'      => $added,
            'count'      => count($favorites),
            'favorites'  => array_map('intval', $favorites),
        ]);
    }

    /** Fase 4 — Support chat: answer a visitor message (rate-limited). */
    public static function chatbot_message() {
        if (!wp_verify_nonce(isset($_POST['nonce']) ? (string) $_POST['nonce'] : '', 'tap_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'travel-agency-platform')], 403);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
        if (TAP_Chatbot::rate_limited($ip)) {
            wp_send_json_error(['message' => __('Too many messages. Try again in a moment.', 'travel-agency-platform')], 429);
        }

        $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
        if ('' === trim($message)) {
            wp_send_json_error(['message' => __('Type your question...', 'travel-agency-platform')], 400);
        }

        $answer = TAP_Chatbot::answer($message);

        $lang = TAP_Localization::current_lang();
        TAP_Chatbot::log_event($answer['intent'], $lang);

        wp_send_json_success($answer);
    }

    /**
     * Whether the given user belongs to an agency (admin or employee) or is a
     * platform administrator. Used to keep the client-side chat endpoint from
     * accepting the agency as the traveler.
     */
    private static function caller_is_agency_member($user_id) {
        if (user_can((int) $user_id, 'manage_options')) {
            return true;
        }
        $user = get_userdata((int) $user_id);
        return $user && (in_array('tap_agency_admin', (array) $user->roles, true) || in_array('tap_agency_employee', (array) $user->roles, true));
    }
}
