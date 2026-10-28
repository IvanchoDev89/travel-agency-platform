<?php
defined('ABSPATH') || exit;

/**
 * Dashboard AJAX handlers: the client actions (cancel booking, favourites,\n * delete review, chat) and the agency back-office actions (booking and payout\n * state). Every handler verifies tap_front_dash_nonce. Split out of\n * TAP_Front_Dash so that class keeps only views and routing.
 */
class TAP_Ajax_Dash {
    public static function ajax_agency_payout() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }

        $user = wp_get_current_user();
        if (!TAP_Roles::is_agency_admin($user)) {
            wp_send_json_error(['message' => __('Solo el administrador de la agencia puede solicitar liquidaciones.', 'travel-agency-platform')], 403);
        }

        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('No tienes una agencia vinculada.', 'travel-agency-platform')], 400);
        }

        $method = sanitize_key(wp_unslash($_POST['method'] ?? 'bank_transfer'));
        $note   = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        $res    = TAP_Payouts::request($agency, $method, $note);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], 400);
        }
        wp_send_json_success(['message' => __('Solicitud de liquidación enviada al administrador.', 'travel-agency-platform'), 'payout_id' => (int) $res]);
    }

    public static function ajax_chat_send() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $booking = $booking_id ? TAP_Booking::get_booking($booking_id) : null;
        if (!$booking) {
            wp_send_json_error(['message' => __('Reserva no encontrada.', 'travel-agency-platform')], 404);
        }
        if (!TAP_Booking::user_can_manage_booking($user->ID, $booking)) {
            wp_send_json_error(['message' => __('No tienes permiso sobre esta reserva.', 'travel-agency-platform')], 403);
        }
        // Agency-side only: the client of the booking must not be able to post
        // as the agency or clear the agency's unread badge.
        $my_agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!user_can($user, 'manage_options') && (!$my_agency || $my_agency !== (int) $booking->agency_id)) {
            wp_send_json_error(['message' => __('No tienes permiso sobre esta reserva.', 'travel-agency-platform')], 403);
        }

        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $agency  = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            $agency = (int) $booking->agency_id;
        }
        $name = get_the_title($agency);
        $name = $name ?: __('Agencia', 'travel-agency-platform');

        $result = TAP_Chat::send($booking->id, 'agency', $agency, $name, $message);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        TAP_Chat::mark_read($booking->id, 'agency');
        $rows = TAP_Chat::messages($booking->id, 200);
        wp_send_json_success(['row' => TAP_Chat::row_markup(end($rows), 'agency')]);
    }


    public static function ajax_chat_unread() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        wp_send_json_success(['total' => $agency ? TAP_Chat::unread_total_for_agency($agency) : 0]);
    }


    public static function ajax_chat_thread() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $booking = $booking_id ? TAP_Booking::get_booking($booking_id) : null;
        if (!$booking) {
            wp_send_json_error(['message' => __('Reserva no encontrada.', 'travel-agency-platform')], 404);
        }
        if (!TAP_Booking::user_can_manage_booking($user->ID, $booking)) {
            wp_send_json_error(['message' => __('No tienes permiso sobre esta reserva.', 'travel-agency-platform')], 403);
        }
        // Agency-side only (same guard as ajax_chat_send): fetching the thread
        // marks the agency's messages read, so clients must not reach it.
        $my_agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!user_can($user, 'manage_options') && (!$my_agency || $my_agency !== (int) $booking->agency_id)) {
            wp_send_json_error(['message' => __('No tienes permiso sobre esta reserva.', 'travel-agency-platform')], 403);
        }

        TAP_Chat::mark_read($booking->id, 'agency');
        $rows = TAP_Chat::messages($booking->id);
        $html = '';
        foreach ($rows as $m) {
            $html .= TAP_Chat::row_markup($m, 'agency');
        }
        wp_send_json_success([
            'html'    => $html,
            'unread'  => 0,
            'count'   => count($rows),
            'last_id' => $rows ? (int) end($rows)->id : 0,
            'booking' => (int) $booking->id,
        ]);
    }


    public static function ajax_agency_booking() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }

        $booking_id = absint($_POST['booking_id'] ?? 0);
        $op         = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        if (!$booking_id || !$op) {
            wp_send_json_error(['message' => __('Parámetros inválidos.', 'travel-agency-platform')], 400);
        }

        $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));

        $res = TAP_Booking::agency_booking_action($booking_id, $op, 0, $reason);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], 400);
        }
        wp_send_json_success(['message' => __('Reserva actualizada.', 'travel-agency-platform'), 'op' => $op]);
    }


    public static function ajax_agency_bulk_booking() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $user = wp_get_current_user();
        $my_agency = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$my_agency) {
            wp_send_json_error(['message' => __('Sin agencia vinculada.', 'travel-agency-platform')], 403);
        }

        $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
        $ids = array_values(array_filter(array_map('absint', $ids)));
        $op  = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        if (!$ids || !in_array($op, ['confirm', 'complete', 'cancel'], true)) {
            wp_send_json_error(['message' => __('Parámetros inválidos.', 'travel-agency-platform')], 400);
        }

        $ok = 0;
$fail = 0;
$errs = [];
        foreach ($ids as $bid) {
            global $wpdb;
            $bk = $wpdb->get_row($wpdb->prepare("SELECT agency_id FROM {$wpdb->prefix}tap_bookings WHERE id = %d", $bid));
            if (!$bk || (int) $bk->agency_id !== $my_agency) {
                ++$fail;
$errs[] = $bid;
continue;
            }
            $res = TAP_Booking::agency_booking_action($bid, $op, 0, '');
            if (is_wp_error($res)) { ++$fail;
} else { ++$ok; }
        }
        wp_send_json_success([
            'message' => sprintf(__('Procesadas: %d OK, %d con error.', 'travel-agency-platform'), $ok, $fail),
            'ok'      => $ok,
            'fail'    => $fail,
        ]);
    }

    public static function ajax_agency_profile_save() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $user = wp_get_current_user();
        $agency_id = (int) TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency_id || !TAP_Roles::can_manage_agency_panel($user)) {
            wp_send_json_error(['message' => __('Sin permiso.', 'travel-agency-platform')], 403);
        }

        $name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $desc     = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone    = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $whatsapp = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));
        $website  = esc_url_raw(wp_unslash($_POST['website'] ?? ''));
        $address  = sanitize_textarea_field(wp_unslash($_POST['address'] ?? ''));
        $city     = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $country  = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));

        if ($name) {
            wp_update_post(['ID' => $agency_id, 'post_title' => $name, 'post_content' => $desc]);
        }

        $metas = [
            '_tap_agency_email'    => $email,
            '_tap_agency_phone'    => $phone,
            '_tap_agency_whatsapp' => $whatsapp,
            '_tap_agency_website'  => $website,
            '_tap_agency_address'  => $address,
            '_tap_agency_city'     => $city,
            '_tap_agency_country'  => $country,
        ];
        foreach ($metas as $k => $v) {
            update_post_meta($agency_id, $k, $v);
        }

        // Sync tap_agencies table.
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}tap_agencies", [
            'name'    => $name ?: null,
            'email'   => $email,
            'phone'   => $phone,
            'whatsapp'=> $whatsapp,
            'website' => $website,
            'address' => $address,
            'city'    => $city,
            'country' => $country,
        ], ['user_id' => (int) $user->ID]);

        if (class_exists('TAP_Audit_Log')) {
            TAP_Audit_Log::log('agency_profile_updated', 'agency', $agency_id, null, $agency_id, $user->ID);
        }

        wp_send_json_success(['message' => __('Perfil actualizado.', 'travel-agency-platform')]);
    }

    public static function ajax_delete_review() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }

        $review_id = absint($_POST['review_id'] ?? 0);
        if (!$review_id) {
            wp_send_json_error(['message' => __('ID inválido.', 'travel-agency-platform')], 400);
        }

        global $wpdb;
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_reviews WHERE id = %d AND user_id = %d",
            $review_id,
            get_current_user_id()
        ));

        if (!$review) {
            wp_send_json_error(['message' => __('Reseña no encontrada.', 'travel-agency-platform')], 404);
        }

        $wpdb->delete(
            "{$wpdb->prefix}tap_reviews",
            ['id' => $review_id],
            ['%d']
        );

        wp_send_json_success(['message' => __('Reseña eliminada.', 'travel-agency-platform')]);
    }


    public static function ajax_toggle_favorite() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(['message' => __('ID inválido.', 'travel-agency-platform')], 400);
        }

        $user_id    = get_current_user_id();
        $favorites  = get_user_meta($user_id, 'tap_favorites', true);
        $favorites  = is_array($favorites) ? $favorites : [];
        $key = array_search($post_id, $favorites, true);

        if ($key !== false) {
            unset($favorites[$key]);
            $action = 'removed';
        } else {
            $favorites[] = $post_id;
            $action = 'added';
        }

        update_user_meta($user_id, 'tap_favorites', array_values($favorites));

        wp_send_json_success(['action' => $action]);
    }


    public static function ajax_cancel_booking() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }

        $booking_id = absint($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('ID inválido.', 'travel-agency-platform')], 400);
        }

        // Full cancellation pipeline: ownership guard, policy penalty, real
        // refund when the client already paid, and commission voiding.
        $result = TAP_Booking::client_cancel_request($booking_id, get_current_user_id());

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => __('Reserva cancelada.', 'travel-agency-platform')]);
    }

    /* ── agency team (employee ↔ agency mapping) ────────────────────── */

    /**
     * Add an existing WP user to the agency staff: grants the
     * tap_agency_employee role and stores `_tap_agency_id` on the user.
     * Core is testable: returns TRUE or a WP_Error, never dies.
     */
    public static function team_add($agency_id, $email) {
        $agency_id = (int) $agency_id;
        if (!$agency_id || 'tap_agency' !== get_post_type($agency_id)) {
            return new WP_Error('bad_agency', __('Agencia no encontrada.', 'travel-agency-platform'));
        }
        $email = sanitize_email($email);
        if (!$email || !is_email($email)) {
            return new WP_Error('bad_email', __('Introduce un email válido.', 'travel-agency-platform'));
        }
        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error('unknown_user', __('No existe ningún usuario con ese email. Debe registrarse primero.', 'travel-agency-platform'));
        }
        if (user_can($user, 'manage_options')) {
            return new WP_Error('is_admin', __('No puedes agregar usuarios administradores de la plataforma.', 'travel-agency-platform'));
        }
        $current = (int) TAP_Booking::get_agency_for_user($user->ID);
        if ($current === $agency_id) {
            // Already linked (owner or staff): idempotent success.
            return true;
        }
        if ($current) {
            return new WP_Error('other_agency', __('Este usuario ya pertenece a otra agencia.', 'travel-agency-platform'));
        }
        $user->add_role('tap_agency_employee');
        update_user_meta($user->ID, '_tap_agency_id', $agency_id);
        return true;
    }

    /**
     * Remove a staff member from the agency: clears `_tap_agency_id` and the
     * employee role. Only actual staff (by meta) can be removed, so the
     * agency owner is never affected. Core is testable: TRUE or WP_Error.
     */
    public static function team_remove($agency_id, $user_id) {
        $agency_id = (int) $agency_id;
        $user_id   = (int) $user_id;
        if (!$agency_id || !$user_id) {
            return new WP_Error('bad_args', __('Parámetros inválidos.', 'travel-agency-platform'));
        }
        // Meta-based guard: the owner maps through the agency post, not this
        // meta, so removing "the owner" is a no-op error by design.
        if ((int) get_user_meta($user_id, '_tap_agency_id', true) !== $agency_id) {
            return new WP_Error('not_staff', __('Este usuario no pertenece a tu equipo.', 'travel-agency-platform'));
        }
        delete_user_meta($user_id, '_tap_agency_id');
        $user = get_userdata($user_id);
        if ($user) {
            $user->remove_role('tap_agency_employee');
        }
        return true;
    }

    public static function ajax_team_add() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $user = wp_get_current_user();
        if (!TAP_Roles::is_agency_admin($user)) {
            wp_send_json_error(['message' => __('Solo el administrador de la agencia gestiona el equipo.', 'travel-agency-platform')], 403);
        }
        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('No tienes una agencia vinculada.', 'travel-agency-platform')], 400);
        }
        $email = sanitize_text_field(wp_unslash($_POST['email'] ?? ''));
        $res   = self::team_add($agency, $email);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], 400);
        }
        wp_send_json_success(['message' => __('Empleado agregado al equipo.', 'travel-agency-platform')]);
    }

    public static function ajax_team_remove() {
        check_ajax_referer('tap_front_dash_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('No autenticado.', 'travel-agency-platform')], 401);
        }
        $user = wp_get_current_user();
        if (!TAP_Roles::is_agency_admin($user)) {
            wp_send_json_error(['message' => __('Solo el administrador de la agencia gestiona el equipo.', 'travel-agency-platform')], 403);
        }
        $agency = TAP_Booking::get_agency_for_user($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('No tienes una agencia vinculada.', 'travel-agency-platform')], 400);
        }
        $target = absint($_POST['user_id'] ?? 0);
        $res    = self::team_remove($agency, $target);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], 400);
        }
        wp_send_json_success(['message' => __('Empleado quitado del equipo.', 'travel-agency-platform')]);
    }
}
