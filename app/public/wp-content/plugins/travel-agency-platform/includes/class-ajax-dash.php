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
        if (!in_array('tap_agency_admin', (array) $user->roles, true)) {
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

}
