<?php
defined('ABSPATH') || exit;

/**
 * Commerce handlers: PayPal order creation and capture for bookings,\n * subscriptions and promotions, plus the subscription and promotion requests\n * themselves. Real money, so it lives apart from the listing write handlers.
 */
class TAP_Ajax_Commerce {
    public static function promo_request() {
        check_ajax_referer( 'tap_agency_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required', 'travel-agency-platform' ) ] );
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for( $user->ID );
        if ( ! $agency ) {
            wp_send_json_error( [ 'message' => __( 'Only agencies can promote listings.', 'travel-agency-platform' ) ] );
        }
        $listing_id = isset( $_POST['listing_id'] ) ? intval( $_POST['listing_id'] ) : 0;
        $months     = isset( $_POST['months'] ) ? max(1, intval( $_POST['months'] )) : 1;
        if ( ! $listing_id || ! class_exists( 'TAP_Promotions' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid listing.', 'travel-agency-platform' ) ] );
        }
        if ( (int) TAP_Promotions::agency_of_listing( $listing_id ) !== (int) $agency ) {
            wp_send_json_error( [ 'message' => __( 'You can only promote your own listings.', 'travel-agency-platform' ) ] );
        }
        $result = TAP_Promotions::request( $agency, $listing_id, $months );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [
            'message' => __( 'Solicitud de destacado enviada. Un administrador confirmará el pago para activarlo.', 'travel-agency-platform' ),
            'status'  => 'pending',
            'promo_id' => (int) $result,
        ] );
    }


    public static function current_agency_for( $user_id ) {
        if ( user_can( $user_id, 'manage_options' ) ) {
            return null;
        }
        return TAP_Booking::get_agency_for_user( $user_id );
    }


    public static function capture_promo_paypal() {
        check_ajax_referer('tap_agency_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $paypal_order_id = sanitize_text_field(wp_unslash($_POST['paypal_order_id'] ?? ''));
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid order', 'travel-agency-platform')]);
        }

        $order = TAP_Payment::resolve_order($paypal_order_id);
        if (!$order || 'promotion' !== $order->object_type) {
            wp_send_json_error(['message' => __('Order not found', 'travel-agency-platform')]);
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result['capture_status'] === 'COMPLETED') {
            TAP_Payment::record_order($paypal_order_id, 'promotion', (int) $order->object_id, $order->amount, 'completed', $result['capture_id']);
            TAP_Payment::confirm_promotion_payment((int) $order->object_id, 'paypal', $result['capture_id']);
            wp_send_json_success([
                'message' => __('Pago recibido. Tu listing está destacado.', 'travel-agency-platform'),
                'status'  => 'active',
            ]);
        }

        wp_send_json_error(['message' => __('The payment could not be completed.', 'travel-agency-platform')]);
    }


    public static function promo_request_paypal() {
        check_ajax_referer('tap_agency_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        if (!TAP_PayPal::is_ready()) {
            wp_send_json_error(['message' => __('Online payment is not configured.', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can promote listings.', 'travel-agency-platform')]);
        }
        $listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;
        $months     = isset($_POST['months']) ? max(1, min(24, intval($_POST['months']))) : 1;
        if (!$listing_id || !class_exists('TAP_Promotions')) {
            wp_send_json_error(['message' => __('Invalid listing.', 'travel-agency-platform')]);
        }
        if ((int) TAP_Promotions::agency_of_listing($listing_id) !== (int) $agency) {
            wp_send_json_error(['message' => __('You can only promote your own listings.', 'travel-agency-platform')]);
        }

        $promo_id = TAP_Promotions::request($agency, $listing_id, $months);
        if (is_wp_error($promo_id)) {
            wp_send_json_error(['message' => $promo_id->get_error_message()]);
        }

        $amount = round(TAP_Promotions::get_price() * $months, 2);
        $result = TAP_PayPal::create_order_generic(
            $amount,
            sprintf(__('Destacado %d mes(es) — listing #%d', 'travel-agency-platform'), $months, $listing_id),
            'PROMO-' . $promo_id,
            'promotion',
            (int) $promo_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'order_id' => $result['order_id'],
            'message'  => __('Completa el pago con PayPal para activar el destacado.', 'travel-agency-platform'),
        ]);
    }


    public static function capture_subscription_paypal() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $paypal_order_id = sanitize_text_field(wp_unslash($_POST['paypal_order_id'] ?? ''));
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid order', 'travel-agency-platform')]);
        }

        $order = TAP_Payment::resolve_order($paypal_order_id);
        if (!$order || 'subscription' !== $order->object_type) {
            wp_send_json_error(['message' => __('Order not found', 'travel-agency-platform')]);
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result['capture_status'] === 'COMPLETED') {
            TAP_Payment::record_order($paypal_order_id, 'subscription', (int) $order->object_id, $order->amount, 'completed', $result['capture_id']);
            TAP_Payment::confirm_subscription_payment((int) $order->object_id, 'paypal', $result['capture_id']);
            wp_send_json_success([
                'message' => __('Pago recibido. Tu plan está activo.', 'travel-agency-platform'),
                'status'  => 'paid',
            ]);
        }

        wp_send_json_error(['message' => __('The payment could not be completed.', 'travel-agency-platform')]);
    }


    public static function subscribe_paypal() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        if (!TAP_PayPal::is_ready()) {
            wp_send_json_error(['message' => __('Online payment is not configured.', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can subscribe to a plan.', 'travel-agency-platform')]);
        }
        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        $plan    = class_exists('TAP_Subscriptions') ? TAP_Subscriptions::get_plan($plan_id) : null;
        if (!$plan || !(int) $plan->is_active || (float) $plan->price_monthly <= 0) {
            wp_send_json_error(['message' => __('Invalid plan.', 'travel-agency-platform')]);
        }

        $sub_id = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($sub_id)) {
            wp_send_json_error(['message' => $sub_id->get_error_message()]);
        }

        $result = TAP_PayPal::create_order_generic(
            (float) $plan->price_monthly,
            sprintf(__('%s — suscripción mensual (agencia)', 'travel-agency-platform'), $plan->name),
            'SUB-' . $sub_id,
            'subscription',
            (int) $sub_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'order_id' => $result['order_id'],
            'message'  => __('Completa el pago con PayPal para activar tu plan.', 'travel-agency-platform'),
        ]);
    }


    public static function agency_subscribe() {
        check_ajax_referer('tap_plan_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }
        $user   = wp_get_current_user();
        $agency = self::current_agency_for($user->ID);
        if (!$agency) {
            wp_send_json_error(['message' => __('Only agencies can subscribe to a plan.', 'travel-agency-platform')]);
        }
        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        if (!$plan_id) {
            wp_send_json_error(['message' => __('Invalid plan.', 'travel-agency-platform')]);
        }
        $result = TAP_Subscriptions::subscribe($agency, $plan_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'message' => __('Subscription requested. An administrator will confirm the payment to activate your plan.', 'travel-agency-platform'),
            'status'  => 'pending',
        ]);
    }


    public static function capture_paypal_order() {
        check_ajax_referer('tap_nonce', 'nonce');

        $paypal_order_id = sanitize_text_field(wp_unslash($_POST['paypal_order_id'] ?? ''));
        if (!$paypal_order_id) {
            wp_send_json_error(['message' => __('Invalid PayPal order', 'travel-agency-platform')]);
        }

        global $wpdb;
        if (class_exists('TAP_Payment')) {
            $booking_id = (int) TAP_Payment::find_booking_by_order($paypal_order_id);
        } else {
            $booking_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tap_paypal_order_id' AND meta_value = %s",
                $paypal_order_id
            ));
        }

        if (!$booking_id) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        $booking = TAP_Booking::get_booking($booking_id);
        $uid = get_current_user_id();
        $owner_ok = $uid && $booking && (int) $booking->client_id === $uid;
        $guest_ok = !$uid && $booking && (int) $booking->client_id === 0 && TAP_Guest_Checkout::get_guest_pay_token($booking->booking_code);
        if (!$booking || (!$owner_ok && !$guest_ok)) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        // Never capture for a reservation that is no longer active: the client
        // is still taken to PayPal's approval, but their money must not reach
        // us for a dead booking (a capture that slips through this guard is
        // auto-refunded by record_paid_capture).
        if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
            wp_send_json_error(['message' => __('La reserva ya no está vigente; el pago no será procesado.', 'travel-agency-platform')]);
        }

        // Idempotency: a booking already marked paid must not be captured or
        // processed again (protects against retries and double clicks).
        if ('paid' === $booking->payment_status) {
            wp_send_json_success([
                'status'       => 'COMPLETED',
                'capture_id'   => get_post_meta($booking_id, '_tap_paypal_capture_id', true),
                'booking_id'   => $booking_id,
                'message'      => __('Payment completed successfully!', 'travel-agency-platform'),
            ]);
        }

        // Only capture payments that are still owed (never a refunded booking).
        if (!in_array($booking->payment_status, ['pending', 'partial', 'failed'], true)) {
            wp_send_json_error(['message' => __('Payment status does not allow capture', 'travel-agency-platform')]);
        }

        $result = TAP_PayPal::capture_order($paypal_order_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($booking_id && $result['capture_status'] === 'COMPLETED') {
            // Receipts, agency notification and the owed commission all hang off
            // this single event (mark_commission_owed subscribes at priority 20).
            TAP_Booking::record_paid_capture($booking_id, 'paypal', $result['capture_id'], $result['full_response'] ?? null);
        }

        wp_send_json_success([
            'status'       => $result['status'],
            'capture_id'   => $result['capture_id'],
            'booking_id'   => $booking_id,
            'message'      => __('Payment completed successfully!', 'travel-agency-platform'),
        ]);
    }


    public static function create_paypal_order() {
        check_ajax_referer('tap_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking', 'travel-agency-platform')]);
        }

        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        $uid = get_current_user_id();
        $owner_ok = $uid && (int) $booking->client_id === $uid;
        $guest_ok = !$uid && (int) $booking->client_id === 0 && TAP_Guest_Checkout::get_guest_pay_token($booking->booking_code);
        if (!$owner_ok && !$guest_ok) {
            wp_send_json_error(['message' => __('Booking not found', 'travel-agency-platform')]);
        }

        if ($booking->payment_status === 'paid') {
            wp_send_json_error(['message' => __('Booking already paid', 'travel-agency-platform')]);
        }

        if (!TAP_Booking::is_payable($booking)) {
            wp_send_json_error(['message' => __('Esta solicitud aún no ha sido aceptada por la agencia. Cuando sea confirmada podrás pagar.', 'travel-agency-platform')]);
        }

        $return_url = home_url('/checkout?code=' . $booking->booking_code . '&status=success');
        $cancel_url = home_url('/checkout?code=' . $booking->booking_code . '&status=cancel');

        $result = TAP_PayPal::create_order($booking_id, $return_url, $cancel_url);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

}
