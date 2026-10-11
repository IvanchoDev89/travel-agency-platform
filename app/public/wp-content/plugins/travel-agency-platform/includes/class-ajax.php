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
        }

        $required = ['service_type', 'service_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(['message' => sprintf(__('Field %s is required', 'travel-agency-platform'), $field)]);
            }
        }

        $room_id  = !empty($_POST['room_id']) ? intval($_POST['room_id']) : 0;
        $check_in = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $adults   = intval($_POST['adults'] ?? 1);
        $children = intval($_POST['children'] ?? 0);

        $total = TAP_Booking::calculate_price(
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

    /**
     * Subscribe to a paid plan and charge via PayPal. Creates the pending
     * subscription, then a PayPal Checkout v2 order for 1 month.
     */
    /** Capture a completed subscription PayPal order and mark the sub paid. */
    /** Request a promotion and pay via PayPal. Creates the pending promo then an order. */
    /** Capture a completed promotion PayPal order and activate the promo. */
    /**
     * Resolve the per-type meta prefix from a listing post type.
     */
    private static function listing_prefix($type) {
        return TAP_Post_Types::meta_prefix($type);
    }

    public static function agency_owns_listing($listing_id, $type) {
        global $wpdb;
        $user   = wp_get_current_user();
        $agency = TAP_Ajax_Commerce::current_agency_for($user->ID);
        if (null === $agency) {
            return true;
        }
        $prefix  = self::listing_prefix($type);
        $owner   = (int) get_post_meta($listing_id, '_tap_' . $prefix . '_agency_id', true);
        return $owner > 0 && $owner === (int) $agency;
    }

    public static function agency_owns_accommodation($acc_id) {
        return self::agency_owns_listing($acc_id, 'tap_accommodation');
    }

    public static function agency_save_listing() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }

        $result = self::save_listing_data(get_current_user_id(), $_POST);
        if (true === $result) {
            $id = (int) $GLOBALS['_tap_saved_listing'];
            wp_send_json_success([
                'message'    => isset($_POST['listing_id']) ? __('Listing updated', 'travel-agency-platform') : __('Listing created', 'travel-agency-platform'),
                'listing_id' => $id,
                'edit_url'   => home_url('/manage-listing/?id=' . $id),
            ]);
        } else {
            wp_send_json_error(['message' => $result]);
        }
    }

    /**
     * Filter attachment ids sent by the front-end uploader. Only images the
     * current user owns (or admins) can be attached, so an agency cannot point
     * a listing at somebody else's private media.
     *
     * @param mixed $raw   Array or comma separated list of attachment ids.
     * @param int   $limit Max number of ids to keep (0 = unlimited).
     * @return array<int,int> Ids as values, keyed by id.
     */
    /**
     * Map the attachments a post already uses to their URL, so legacy URL
     * submissions are reused instead of re-downloaded on every save.
     */
    /**
     * Core, testable listing save. Returns TRUE on success (listing id in
     * $GLOBALS['_tap_saved_listing']) or an error message string. Never dies,
     * so it can be exercised directly under wp-cli.
     */
    public static function save_listing_data($user_id, array $input) {
        wp_set_current_user($user_id);
        $agency = TAP_Ajax_Commerce::current_agency_for($user_id);

        $service_types = array_keys(TAP_Post_Types::get_service_types());
        $service_types = array_values(array_filter($service_types, function ($t) {
            return $t !== 'tap_room';
        }));
        $listing_type  = isset($input['listing_type']) ? sanitize_key($input['listing_type']) : 'tap_accommodation';
        if (!in_array($listing_type, $service_types, true)) {
            $listing_type = 'tap_accommodation';
        }
        $prefix = self::listing_prefix($listing_type);

        $listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
        if ($listing_id && !self::agency_owns_listing($listing_id, $listing_type)) {
            return __('You can only manage your own listings.', 'travel-agency-platform');
        }

        $title = isset($input['title']) ? sanitize_text_field(wp_unslash($input['title'])) : '';
        if ('' === $title) {
            return __('Name is required', 'travel-agency-platform');
        }

        // Money integrity: any price field that is provided must be > 0.
        $fields = TAP_Metaboxes::get_fields($listing_type);
        foreach ($fields as $meta_key => $cfg) {
            if (!isset($cfg['type']) || 'number' !== $cfg['type'] || false === strpos($meta_key, 'price')) {
                continue;
            }
            $raw = isset($input[$meta_key]) ? $input[$meta_key] : null;
            if ($raw === null && $listing_type === 'tap_accommodation' && $meta_key === '_tap_acc_price_per_night') {
                $raw = isset($input['price_per_night']) ? $input['price_per_night'] : null;
            }
            if ($raw === null) {
                continue;
            }
            $is_decimal = isset($cfg['step']) && false !== strpos($cfg['step'], '.');
            $value      = $is_decimal ? floatval($raw) : intval($raw);
            if ($value <= 0) {
                return sprintf(__('El precio %s debe ser mayor que 0.', 'travel-agency-platform'), isset($cfg['label']) ? $cfg['label'] : $meta_key);
            }
        }

        if (null !== $agency && class_exists('TAP_Subscriptions')) {
            $limit = TAP_Subscriptions::listing_limit($agency);
            if ($limit >= 0 && !$listing_id && TAP_Subscriptions::listing_count($agency) >= $limit) {
                return sprintf(__('Your plan allows a maximum of %d listings. Upgrading your plan unlocks more.', 'travel-agency-platform'), $limit);
            }
        }

        $listing_obj = get_post_type_object($listing_type);
        $can_publish = ($listing_obj && current_user_can($listing_obj->cap->publish_posts)) || current_user_can('manage_options');

        $post = [
            'post_type'    => $listing_type,
            'post_title'   => $title,
            'post_content' => isset($input['description']) ? wp_kses_post(wp_unslash($input['description'])) : '',
        ];

        if ($listing_id) {
            $post['ID'] = $listing_id;
            // Editing keeps the current visibility: publishing/unpublishing is an
            // explicit action (see set_listing_published) instead of a side
            // effect of saving the form.
            if (array_key_exists('publish', $input)) {
                $post['post_status'] = empty($input['publish']) ? 'draft' : ($can_publish ? 'publish' : 'pending');
            }
            $new_id     = wp_update_post($post, true);
        } else {
            // Creating: only publish when the user can; otherwise it goes to
            // review so an employee cannot self-publish.
            $post['post_status'] = $can_publish ? 'publish' : 'pending';
            $new_id = wp_insert_post($post, true);
        }

        if (is_wp_error($new_id)) {
            return $new_id->get_error_message();
        }
        if (!$new_id) {
            return __('Could not save the listing', 'travel-agency-platform');
        }

        if (null !== $agency) {
            update_post_meta($new_id, '_tap_' . $prefix . '_agency_id', (int) $agency);
        }

        // Legacy short input-name → meta-key map for the accommodation form.
        $legacy_map = [
            'tap_accommodation' => [
                'type' => '_tap_acc_type', 'stars' => '_tap_acc_stars',
                'price_per_night' => '_tap_acc_price_per_night', 'capacity' => '_tap_acc_capacity',
                'bedrooms' => '_tap_acc_bedrooms', 'bathrooms' => '_tap_acc_bathrooms',
                'checkin_time' => '_tap_acc_checkin_time', 'checkout_time' => '_tap_acc_checkout_time',
                'lat' => '_tap_acc_lat', 'lng' => '_tap_acc_lng', 'currency' => '_tap_acc_currency',
                'is_active' => '_tap_acc_is_active',
            ],
        ];

        foreach ($fields as $meta_key => $cfg) {
            if (strpos($meta_key, '_tap_' . $prefix . '_agency_id') !== false) {
                continue;
            }
            if ($meta_key === '_tap_seo_title' || $meta_key === '_tap_seo_description') {
                continue;
            }

            $input_name = $meta_key;
            $raw = isset($input[$input_name]) ? $input[$input_name] : null;

            if ($raw === null && $listing_type === 'tap_accommodation' && isset($legacy_map['tap_accommodation'][substr($meta_key, strlen('_tap_acc_'))])) {
                $legacy_name = array_search($meta_key, $legacy_map['tap_accommodation'], true);
                $raw = isset($input[$legacy_name]) ? $input[$legacy_name] : null;
            }

            if ($raw === null) {
                continue;
            }

            $ftype = isset($cfg['type']) ? $cfg['type'] : 'text';
            switch ($ftype) {
                case 'number':
                    $value = isset($cfg['step']) && strpos($cfg['step'], '.') !== false ? (string) floatval($raw) : (string) intval($raw);
                    break;
                case 'checkbox':
                    $value = '1' === (string) $raw ? '1' : '0';
                    break;
                case 'select':
                    $value = sanitize_key($raw);
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field(wp_unslash((string) $raw));
                    break;
                case 'email':
                    $value = sanitize_email($raw);
                    break;
                case 'url':
                    $value = esc_url_raw($raw);
                    break;
                default:
                    $value = sanitize_text_field(wp_unslash((string) $raw));
            }
            update_post_meta($new_id, $meta_key, $value);
        }

        $GLOBALS['_tap_saved_listing'] = (int) $new_id;

        if (isset($input['destination'])) {
            $dest  = absint($input['destination']);
            $valid = $dest && term_exists($dest, 'tap_location');
            wp_set_object_terms($new_id, $valid ? [$dest] : [], 'tap_location', false);
        }

        $booking_mode = isset($input['booking_mode']) ? sanitize_key($input['booking_mode']) : '';
        if (in_array($booking_mode, ['instant', 'request'], true)) {
            update_post_meta($new_id, '_tap_booking_mode', $booking_mode);
        }

        // Policies (accommodation): not part of the generic field map, but the
        // agency wizard lets users set them from the front-end back-office.
        if ($listing_type === 'tap_accommodation') {
            if (array_key_exists('_tap_acc_cancellation', $input)) {
                $pol = sanitize_key((string) $input['_tap_acc_cancellation']);
                if (in_array($pol, array_keys(TAP_Refunds::cancellation_policies()), true)) {
                    update_post_meta($new_id, '_tap_acc_cancellation', $pol);
                }
            }
            if (array_key_exists('_tap_acc_house_rules', $input)) {
                update_post_meta($new_id, '_tap_acc_house_rules', sanitize_textarea_field(wp_unslash((string) $input['_tap_acc_house_rules'])));
            }
        }

        // ── Images ────────────────────────────────────────────────────
        // The agency wizard uploads through the Media Library and sends
        // attachment ids, so nothing is re-downloaded. The legacy URL fields
        // keep working for the older editors and reuse existing attachments.
        if (array_key_exists('featured_attachment_id', $input)) {
            $att = TAP_Agency_Media::usable_attachment_ids($input['featured_attachment_id'], 1);
            if ($att) {
                set_post_thumbnail($new_id, (int) array_key_first($att));
            } else {
                delete_post_thumbnail($new_id);
            }
        } elseif (array_key_exists('featured_image_url', $input)) {
            $furl = esc_url_raw(trim((string) ($input['featured_image_url'] ?? '')));
            if ('' !== $furl) {
                $current = (int) get_post_thumbnail_id($new_id);
                $known   = TAP_Agency_Media::attachments_by_url([$current]);
                $att     = isset($known[$furl]) ? $known[$furl] : TAP_Agency_Media::sideload_image($furl);
                if ($att) {
                    set_post_thumbnail($new_id, $att);
                }
            } else {
                delete_post_thumbnail($new_id);
            }
        }

        if ($listing_type === 'tap_accommodation') {
            if (array_key_exists('gallery_attachment_ids', $input)) {
                $ids = TAP_Agency_Media::usable_attachment_ids($input['gallery_attachment_ids']);
                update_post_meta($new_id, '_tap_acc_gallery', implode(',', array_keys($ids)));
            } elseif (array_key_exists('gallery_urls', $input)) {
                $urls  = array_values(array_filter(array_map('trim', explode("\n", (string) $input['gallery_urls']))));
                $known = TAP_Agency_Media::attachments_by_url(array_filter(array_map('absint', explode(',', (string) get_post_meta($new_id, '_tap_acc_gallery', true)))));
                $ids   = [];
                foreach ($urls as $u) {
                    if (isset($known[$u])) {
                        $ids[$known[$u]] = $known[$u];
                        continue;
                    }
                    $att = TAP_Agency_Media::sideload_image($u);
                    if ($att) {
                        $ids[$att] = $att;
                    }
                }
                update_post_meta($new_id, '_tap_acc_gallery', implode(',', array_keys($ids)));
            }
        }

        return true;
    }

    /**
     * Reject URLs that point to non-public hosts (SSRF guard for sideload).
     * Blocks literal private/reserved/metadata IPs and hostnames that resolve
     * to any non-public address, so agencies cannot proxy requests to
     * localhost, internal networks or cloud metadata endpoints.
     */
    /**
     * Download a remote image into the Media Library and return its
     * attachment id (0 on any failure). No-op-safe under wp-cli and tests.
     */
    /**
     * Image extensions accepted by the front-end agency uploader.
     */
    /**
     * Max upload size (bytes) accepted by the front-end agency uploader.
     */
    /**
     * Store an image into the Media Library. Testable core: takes a plain
     * file array (as in $_FILES), never dies, and returns the attachment data
     * array or a WP_Error. Handles both real uploads (wp_handle_upload) and
     * local temp files (wp_handle_sideload), which keeps it usable from tests.
     *
     * @param array $file      File array with name/tmp_name/size/type/error.
     * @param int   $parent_id Optional post to attach the media to.
     * @return array|WP_Error  {attachment_id, url, thumb, width, height}
     */
    /**
     * Front-end image upload used by the agency back-office wizard. Auth,
     * ownership and nonce live here; the storage work is in the core above.
     */
    /**
     * Core, testable publish/unpublish toggle. Publishing honours the agency
     * approval rules: users without the publish capability send the listing to
     * moderation instead of making it public.
     *
     * @return array|WP_Error {status, is_active, message}
     */
    public static function set_listing_published($listing_id, $publish) {
        $listing_id = absint($listing_id);
        $type       = $listing_id ? get_post_type($listing_id) : '';
        if (!$listing_id || !$type) {
            return new WP_Error('tap_bad_listing', __('Alojamiento no encontrado.', 'travel-agency-platform'));
        }

        $prefix      = self::listing_prefix($type);
        $active_meta = '_tap_' . $prefix . '_is_active';
        $type_obj = get_post_type_object($type);
        $can_publish = ($type_obj && current_user_can($type_obj->cap->publish_posts)) || current_user_can('manage_options');

        if (!$publish) {
            wp_update_post(['ID' => $listing_id, 'post_status' => 'draft']);
            update_post_meta($listing_id, $active_meta, '0');
            return [
                'status'    => 'draft',
                'is_active' => '0',
                'message'   => __('Alojamiento despublicado. Ya no aparece en el buscador.', 'travel-agency-platform'),
            ];
        }

        update_post_meta($listing_id, $active_meta, '1');

        if ($can_publish) {
            wp_update_post(['ID' => $listing_id, 'post_status' => 'publish']);
            return [
                'status'    => 'publish',
                'is_active' => '1',
                'message'   => __('¡Alojamiento publicado! Ya está visible para los viajeros.', 'travel-agency-platform'),
            ];
        }

        wp_update_post(['ID' => $listing_id, 'post_status' => 'pending']);
        return [
            'status'    => 'pending',
            'is_active' => '1',
            'message'   => __('Enviado a revisión. Un administrador lo aprobará y quedará visible.', 'travel-agency-platform'),
        ];
    }

    /**
     * AJAX: explicit publish / unpublish from the wizard.
     */
    public static function agency_toggle_publish() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'travel-agency-platform')], 403);
        }

        $listing_type = isset($_POST['listing_type']) ? sanitize_key(wp_unslash($_POST['listing_type'])) : 'tap_accommodation';
        $listing_id   = isset($_POST['listing_id']) ? absint($_POST['listing_id']) : 0;
        if (!$listing_id || get_post_type($listing_id) !== $listing_type) {
            wp_send_json_error(['message' => __('Alojamiento no encontrado.', 'travel-agency-platform')]);
        }
        if (!self::agency_owns_listing($listing_id, $listing_type)) {
            wp_send_json_error(['message' => __('Solo puedes gestionar tus propios alojamientos.', 'travel-agency-platform')], 403);
        }

        $result = self::set_listing_published($listing_id, !empty($_POST['publish']));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $result['permalink'] = 'publish' === $result['status'] ? (string) get_permalink($listing_id) : '';
        wp_send_json_success($result);
    }

    public static function agency_save_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required', 'travel-agency-platform' ) ] );
        }

        $acc_id   = isset( $_POST['accommodation_id'] ) ? intval( $_POST['accommodation_id'] ) : 0;
        if ( ! $acc_id || ! self::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        $result  = self::save_room_data( $acc_id, $room_id, $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => $room_id ? __( 'Room updated', 'travel-agency-platform' ) : __( 'Room created', 'travel-agency-platform' ),
            'room_id' => (int) $result,
        ] );
    }

    /**
     * Testable room editor core. Returns the room id on success, or a
     * WP_Error with a translated message. Reads from a plain $input array
     * (usually $_POST) and never dies, so it can run under wp-cli.
     */
    public static function save_room_data( $acc_id, $room_id, array $input ) {
        $acc_id  = (int) $acc_id;
        $room_id = (int) $room_id;

        $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
        if ( '' === $title ) {
            return new WP_Error( 'no_title', __( 'Room name is required', 'travel-agency-platform' ) );
        }

        if ( $room_id && (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true ) !== $acc_id ) {
            return new WP_Error( 'invalid_room', __( 'Invalid room', 'travel-agency-platform' ) );
        }

        $price = isset( $input['price_per_night'] ) ? floatval( $input['price_per_night'] ) : 0;
        if ( $price <= 0 ) {
            return new WP_Error( 'invalid_price', __( 'Price per night must be greater than 0', 'travel-agency-platform' ) );
        }

        $post = [
            'post_type'   => 'tap_room',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_excerpt'=> isset( $input['description'] ) ? wp_kses_post( wp_unslash( $input['description'] ) ) : '',
        ];

        if ( $room_id ) {
            $post['ID'] = $room_id;
            $new_id     = wp_update_post( $post, true );
        } else {
            $new_id     = wp_insert_post( $post, true );
        }

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            return new WP_Error( 'save_failed', __( 'Could not save the room', 'travel-agency-platform' ) );
        }

        $beds_raw = isset( $input['_tap_room_beds'] ) ? wp_unslash( $input['_tap_room_beds'] ) : '';
        $beds     = json_decode( (string) $beds_raw, true );
        $beds_out = [];
        $bed_types = TAP_Post_Types::bed_types();
        if ( is_array( $beds ) ) {
            foreach ( $beds as $bed ) {
                if ( ! is_array( $bed ) || ! isset( $bed['type'], $bed['count'] ) ) {
                    continue;
                }
                $t = sanitize_key( (string) $bed['type'] );
                $c = intval( $bed['count'] );
                if ( $c <= 0 ) {
                    continue;
                }
                if ( ! array_key_exists( $t, $bed_types ) ) {
                    $t = 'double';
                }
                $beds_out[] = [ 'type' => $t, 'count' => $c ];
            }
        }
        if ( $beds_raw !== '' || count( $beds_out ) > 0 ) {
            update_post_meta( $new_id, '_tap_room_beds', json_encode( $beds_out, JSON_UNESCAPED_UNICODE ) );
        }

        $amenities_raw = isset( $input['amenities'] ) ? sanitize_text_field( wp_unslash( $input['amenities'] ) ) : '';
        $amenities = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $amenities_raw ) ) ) ) );
        update_post_meta( $new_id, '_tap_room_amenities', json_encode( $amenities, JSON_UNESCAPED_UNICODE ) );

        // Images: attachment ids from the wizard uploader take precedence over
        // the legacy URL fields, which are reused when already known.
        if ( array_key_exists( 'room_attachment_id', $input ) ) {
            $att = TAP_Agency_Media::usable_attachment_ids( $input['room_attachment_id'], 1 );
            if ( $att ) {
                set_post_thumbnail( $new_id, (int) array_key_first( $att ) );
            } else {
                delete_post_thumbnail( $new_id );
            }
        } elseif ( array_key_exists( 'room_thumbnail_url', $input ) ) {
            $furl = esc_url_raw( trim( (string) ( $input['room_thumbnail_url'] ?? '' ) ) );
            if ( '' !== $furl ) {
                $known = TAP_Agency_Media::attachments_by_url( [ (int) get_post_thumbnail_id( $new_id ) ] );
                $att   = isset( $known[ $furl ] ) ? $known[ $furl ] : TAP_Agency_Media::sideload_image( $furl );
                if ( $att ) {
                    set_post_thumbnail( $new_id, $att );
                }
            } else {
                delete_post_thumbnail( $new_id );
            }
        }

        if ( array_key_exists( 'gallery_attachment_ids', $input ) ) {
            $ids = TAP_Agency_Media::usable_attachment_ids( $input['gallery_attachment_ids'] );
            update_post_meta( $new_id, '_tap_room_gallery', implode( ',', array_keys( $ids ) ) );
        } elseif ( array_key_exists( 'gallery_urls', $input ) ) {
            $urls  = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $input['gallery_urls'] ) ) ) );
            $known = TAP_Agency_Media::attachments_by_url( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $new_id, '_tap_room_gallery', true ) ) ) ) );
            $ids   = [];
            foreach ( $urls as $u ) {
                if ( isset( $known[ $u ] ) ) {
                    $ids[ $known[ $u ] ] = $known[ $u ];
                    continue;
                }
                $att = TAP_Agency_Media::sideload_image( $u );
                if ( $att ) {
                    $ids[ $att ] = $att;
                }
            }
            update_post_meta( $new_id, '_tap_room_gallery', implode( ',', array_keys( $ids ) ) );
        }

        $fields = [
            '_tap_room_accommodation_id' => $acc_id,
            '_tap_room_price_per_night'  => $price,
            '_tap_room_min_stay'         => isset( $input['min_stay'] ) ? intval( $input['min_stay'] ) : 1,
            '_tap_room_currency'         => isset( $input['currency'] ) ? sanitize_text_field( $input['currency'] ) : 'USD',
            '_tap_room_max_adults'       => isset( $input['max_adults'] ) ? intval( $input['max_adults'] ) : 2,
            '_tap_room_max_children'     => isset( $input['max_children'] ) ? intval( $input['max_children'] ) : 0,
            '_tap_room_max_occupancy'    => isset( $input['max_occupancy'] ) ? intval( $input['max_occupancy'] ) : 2,
            '_tap_room_inventory'        => isset( $input['inventory'] ) ? intval( $input['inventory'] ) : 1,
            '_tap_room_size'             => isset( $input['size'] ) ? sanitize_text_field( $input['size'] ) : '',
            '_tap_room_view'             => isset( $input['view'] ) ? sanitize_text_field( $input['view'] ) : '',
            '_tap_room_floor'            => isset( $input['floor'] ) && '' !== $input['floor'] ? intval( $input['floor'] ) : 0,
            '_tap_room_is_active'        => isset( $input['is_active'] ) && '1' === $input['is_active'] ? '1' : '0',
        ];

        foreach ( $fields as $key => $value ) {
            update_post_meta( $new_id, $key, $value );
        }

        return (int) $new_id;
    }

    public static function agency_delete_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        if ( ! $room_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid room', 'travel-agency-platform' ) ] );
        }
        $acc_id = (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true );
        if ( ! $acc_id || ! self::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        global $wpdb;
        $future = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE room_id = %d AND check_out > %s AND status NOT IN ('cancelled','refunded')",
            $room_id,
            gmdate( 'Y-m-d' )
        ) );
        if ( $future > 0 ) {
            wp_send_json_error( [ 'message' => __( 'This room has future bookings and cannot be deleted.', 'travel-agency-platform' ) ] );
        }

        wp_delete_post( $room_id, false );
        wp_send_json_success( [ 'message' => __( 'Room deleted', 'travel-agency-platform' ) ] );
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
}
