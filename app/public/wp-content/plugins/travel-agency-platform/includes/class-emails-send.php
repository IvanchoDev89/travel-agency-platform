<?php
/**
 * TAP_Emails_Send — Message composition and delivery.
 *
 * Builds the HTML body of every notification (context, service labels,
 * contact resolution, the shared table/row markup) and hands the finished
 * message to wp_mail. TAP_Emails keeps the event handlers that decide
 * *when* to send; this class owns *how* the message is built and sent.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Emails_Send {

    /** Localized booking status → label map. Source strings are Spanish. */
    public static function status_labels() {
        return [
            'pending'   => __('Pendiente', 'travel-agency-platform'),
            'request'   => __('Solicitud', 'travel-agency-platform'),
            'confirmed' => __('Confirmada', 'travel-agency-platform'),
            'cancelled' => __('Cancelada', 'travel-agency-platform'),
            'completed' => __('Completada', 'travel-agency-platform'),
            'refunded'  => __('Reembolsada', 'travel-agency-platform'),
        ];
    }


    public static function payment_labels() {
        return [
            'pending' => __('Pendiente', 'travel-agency-platform'),
            'paid'    => __('Pagada', 'travel-agency-platform'),
            'partial' => __('Parcial', 'travel-agency-platform'),
            'refunded'=> __('Reembolsada', 'travel-agency-platform'),
            'failed'  => __('Fallida', 'travel-agency-platform'),
        ];
    }


    public static function status_label($status) {
        $labels = self::status_labels();
        return $labels[$status] ?? $status;
    }


    public static function payment_label($status) {
        $labels = self::payment_labels();
        return $labels[$status] ?? $status;
    }


    public static function info_template($headline, $intro) {
        $primary = '#0d9488';
        $bg      = '#f1f5f9';
        $card    = '#ffffff';
        $text    = '#0f172a';
        $muted   = '#64748b';

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background-color:' . $bg . ';font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:' . $bg . ';padding:32px 16px;">
          <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:' . $card . ';border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
              <tr><td style="background-color:' . $primary . ';padding:24px 32px;">
                <div style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.01em;">Travel Agency</div>
              </td></tr>
              <tr><td style="padding:32px;">
                <h1 style="font-size:22px;color:' . $text . ';margin:0 0 12px;line-height:1.3;">' . esc_html($headline) . '</h1>
                <p style="font-size:15px;color:' . $muted . ';line-height:1.6;margin:0;">' . $intro . '</p>
                <p style="font-size:12px;color:' . $muted . ';line-height:1.5;margin:24px 0 0;">' . __('Este es un correo automático. Por favor no respondas a este mensaje.', 'travel-agency-platform') . '</p>
              </td></tr>
              <tr><td style="background-color:' . $bg . ';padding:16px 32px;text-align:center;font-size:12px;color:' . $muted . ';">© ' . date('Y') . ' Travel Agency Platform</td></tr>
            </table>
          </td></tr>
        </table></body></html>';
    }

    /* ===== Senders ===== */


    public static function send_user_confirmation($booking_id) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        list($email, $name) = self::client_contact($booking);
        if (!$email) return;

        $ctx = self::build_context($booking);
        if ($booking->status === 'request') {
            /* translators: %s: código de la reserva */
            $subject = sprintf(__('Solicitud de reserva %s recibida', 'travel-agency-platform'), $booking->booking_code);
            $body = self::template(
                /* translators: %s: nombre del cliente */
                sprintf(__('¡Gracias %s! Hemos recibido tu solicitud.', 'travel-agency-platform'), $name),
                __('La agencia revisará tu solicitud y te avisaremos por correo cuando sea aceptada. No se realizará ningún cobro hasta entonces.', 'travel-agency-platform'),
                $ctx,
                'request'
            );
            self::send($email, $subject, $body);
            return;
        }

        /* translators: %s: código de la reserva */
        $subject = sprintf(__('Reserva %s recibida', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            /* translators: %s: nombre del cliente */
            sprintf(__('¡Gracias %s! Tu reserva ha sido recibida.', 'travel-agency-platform'), $name),
            __('Estamos procesando tu solicitud. En breve recibirás la confirmación una vez el pago sea verificado.', 'travel-agency-platform'),
            $ctx,
            'pending'
        );

        self::send($email, $subject, $body);
    }


    public static function send_user_status_update($booking_id, $status, $prev = '') {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        list($email, $name) = self::client_contact($booking);
        if (!$email) return;

        $ctx = self::build_context($booking);
        $label = self::status_label($status);

        $headline = [
            'confirmed' => __('¡Tu reserva ha sido confirmada!', 'travel-agency-platform'),
            'cancelled' => __('Tu reserva ha sido cancelada', 'travel-agency-platform'),
            'completed' => __('Tu reserva se ha completado', 'travel-agency-platform'),
            'refunded'  => __('Tu reserva ha sido reembolsada', 'travel-agency-platform'),
        /* translators: %s: estado de la reserva */
        ][$status] ?? sprintf(__('Estado de tu reserva: %s', 'travel-agency-platform'), $label);

        $intro = [
            'confirmed' => __('Todo está listo. Tu estancia ha sido confirmada por la agencia.', 'travel-agency-platform'),
            'cancelled' => __('Lamentablemente tu reserva ha sido cancelada. Si realizaste un pago, el reembolso se procesará según la política de cancelación.', 'travel-agency-platform'),
            'completed' => __('Esperamos que hayas disfrutado tu viaje. Te invitamos a dejar una reseña.', 'travel-agency-platform'),
            'refunded'  => __('El monto pagado ha sido reembolsado a tu cuenta.', 'travel-agency-platform'),
        ][$status] ?? '';

        if ($status === 'pending' && $prev === 'request') {
            $headline = __('¡Tu solicitud fue aceptada!', 'travel-agency-platform');
            $intro = sprintf(
                /* translators: %s: enlace de pago */
                __('La agencia aceptó tu solicitud de reserva. Completa el pago para confirmar: <a href="%s" style="color:#0d9488;">Pagar ahora</a>.', 'travel-agency-platform'),
                esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
            );
        } elseif ($status === 'cancelled' && $prev === 'request') {
            $headline = __('Solicitud rechazada', 'travel-agency-platform');
            $intro = __('La agencia no pudo aceptar tu solicitud de reserva. No se realizó ningún cobro. Contacta a la agencia o prueba con otras fechas.', 'travel-agency-platform');
        } elseif ($status === 'cancelled' && (float) ($booking->refund_amount ?? 0) > 0) {
            $intro .= '<br><br>' . sprintf(
                /* translators: %s: importe reembolsado */
                __('Reembolso aplicado según la política de cancelación: %s.', 'travel-agency-platform'),
                esc_html(TAP_Currency::fmt((float) $booking->refund_amount))
            );
        } elseif ($status === 'cancelled' && !empty($booking->cancellation_policy)) {
            $policies = TAP_Refunds::cancellation_policies();
            $intro .= '<br><br>' . sprintf(
                /* translators: %s: nombre de la política de cancelación */
                __('Política aplicada: %s.', 'travel-agency-platform'),
                esc_html($policies[$booking->cancellation_policy] ?? $booking->cancellation_policy)
            );
        }

        /* translators: 1: código de la reserva, 2: nuevo estado de la reserva */
        $subject = sprintf(__('Actualización de reserva %s: %s', 'travel-agency-platform'), $booking->booking_code, $label);
        $body = self::template($headline, $intro, $ctx, $status);

        self::send($email, $subject, $body);
    }


    public static function send_payment_receipt($booking_id) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        list($email, $name) = self::client_contact($booking);
        if (!$email) return;

        $ctx = self::build_context($booking);
        $ctx['payment_status'] = __('Pagada', 'travel-agency-platform');
        /* translators: %s: código de la reserva */
        $subject = sprintf(__('Pago recibido para la reserva %s', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            __('¡Pago confirmado!', 'travel-agency-platform'),
            __('Hemos recibido tu pago correctamente. Tu reserva ha sido confirmada y los detalles están a continuación.', 'travel-agency-platform'),
            $ctx,
            'confirmed'
        );

        self::send($email, $subject, $body);
    }


    public static function send_admin_notification($booking_id) {
        $admin_email = get_option('admin_email');
        if (!$admin_email) return;
        self::send_agency_notification($booking_id, $admin_email);
    }


    public static function send_agency_notification($booking_id, $to) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        $ctx = self::build_context($booking, true);
        if ($booking->status === 'request') {
            /* translators: 1: código de la reserva, 2: tipo de servicio */
            $subject = sprintf(__('[Nueva solicitud] %s — %s', 'travel-agency-platform'), $booking->booking_code, $booking->service_type);
            $body = self::template(
                __('Nueva solicitud de reserva', 'travel-agency-platform'),
                /* translators: 1: código de la reserva, 2: importe total de la reserva */
                sprintf(__('Tienes una nueva solicitud de reserva (<strong>%s</strong>) por un total de <strong>%s</strong>. Revisa el panel para aceptarla o rechazarla.', 'travel-agency-platform'),
                    $booking->booking_code,
                    self::money($booking->total_amount)),
                $ctx,
                'request',
                true
            );
            self::send($to, $subject, $body);
            return;
        }

        /* translators: 1: código de la reserva, 2: tipo de servicio */
        $subject = sprintf(__('[Nueva reserva] %s — %s', 'travel-agency-platform'), $booking->booking_code, $booking->service_type);
        $body = self::template(
            __('Nueva reserva recibida', 'travel-agency-platform'),
            /* translators: 1: código de la reserva, 2: importe total de la reserva */
            sprintf(__('Se ha registrado una nueva reserva (<strong>%s</strong>) por un total de <strong>%s</strong>. Revisa el panel para gestionarla.', 'travel-agency-platform'),
                $booking->booking_code,
                self::money($booking->total_amount)),
            $ctx,
            $booking->status,
            true
        );

        self::send($to, $subject, $body);
    }


    public static function send_subscription_paid($agency_id, $plan_name, $until) {
        $to = self::get_agency_email($agency_id);
        if (!$to) return;
        /* translators: %s: nombre del plan */
        $subject = sprintf(__('[Plan activado] %s', 'travel-agency-platform'), $plan_name);
        $heading = __('Tu plan está activo', 'travel-agency-platform');
        $body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 16px;">
          <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:16px;overflow:hidden;">
              <tr><td style="background-color:#0d9488;padding:24px 32px;">
                <div style="color:#ffffff;font-size:22px;font-weight:700;">Travel Agency</div>
              </td></tr>
              <tr><td style="padding:32px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#16a34a;margin-bottom:8px;">' . esc_html__('Suscripción', 'travel-agency-platform') . '</div>
                <h1 style="font-size:22px;color:#0f172a;margin:0 0 8px;line-height:1.3;">' . esc_html($heading) . '</h1>
                <p style="font-size:15px;color:#64748b;line-height:1.6;margin:0 0 24px;">' .
                    /* translators: 1: nombre del plan, 2: fecha de vencimiento del plan */
                    sprintf(esc_html__('Se confirmó el pago de tu suscripción al plan <strong>%s</strong>. Tu plan es válido hasta el <strong>%s</strong>. Gracias por confiar en la plataforma.', 'travel-agency-platform'),
                        esc_html($plan_name),
                        esc_html($until)) . '</p>
                <p style="font-size:13px;color:#94a3b8;margin:0;">' . esc_html__('Travel Agency Platform', 'travel-agency-platform') . '</p>
              </td></tr>
            </table>
          </td></tr>
        </table></body></html>';
        self::send($to, $subject, $body);
    }


    public static function send_promo_active($agency_id, $listing_title, $until, $amount) {
        $to = self::get_agency_email($agency_id);
        if (!$to) return;
        $subject = __('[Destacado activado] Tu listado está promocionado', 'travel-agency-platform');
        $heading = __('Tu listado ahora es destacado', 'travel-agency-platform');
        $body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 16px;">
          <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:16px;overflow:hidden;">
              <tr><td style="background-color:#0d9488;padding:24px 32px;">
                <div style="color:#ffffff;font-size:22px;font-weight:700;">Travel Agency</div>
              </td></tr>
              <tr><td style="padding:32px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#16a34a;margin-bottom:8px;">' . esc_html__('Promoción', 'travel-agency-platform') . '</div>
                <h1 style="font-size:22px;color:#0f172a;margin:0 0 8px;line-height:1.3;">' . esc_html($heading) . '</h1>
                <p style="font-size:15px;color:#64748b;line-height:1.6;margin:0 0 24px;">' .
                    sprintf(
                        /* translators: 1: nombre del listado, 2: fecha de fin de la promoción, 3: importe pagado */
                        esc_html__('Se confirmó el pago de tu promoción para <strong>%s</strong>. El listado estará destacado hasta el <strong>%s</strong> (monto: %s).', 'travel-agency-platform'),
                        esc_html($listing_title ?: __('su listado', 'travel-agency-platform')),
                        esc_html($until),
                        esc_html(self::money($amount))
                    ) . '</p>
                <p style="font-size:13px;color:#94a3b8;margin:0;">' . esc_html__('Travel Agency Platform', 'travel-agency-platform') . '</p>
              </td></tr>
            </table>
          </td></tr>
        </table></body></html>';
        self::send($to, $subject, $body);
    }

    /* ===== Helpers ===== */

    /* =========================================================
     * Orphaned-hook notifications (Fase 4 / T4)
     * ========================================================= */


    public static function send_payment_reminder($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        /* translators: %s: código de la reserva */
        $subject = sprintf(__('Recordatorio de pago — reserva %s', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            /* translators: %s: nombre del cliente */
            sprintf(__('Hola %s, tu reserva está esperando pago', 'travel-agency-platform'), $name),
            sprintf(
                /* translators: %s: enlace de pago */
                __('Tienes una reserva pendiente de pago. Completa el pago para mantenerla confirmada: <a href="%s" style="color:#0d9488;">Pagar ahora</a>.', 'travel-agency-platform'),
                esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
            ),
            self::build_context($booking),
            'pending'
        );
        return self::send($email, $subject, $body);
    }


    public static function send_prearrival_msg($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        $ctx = self::build_context($booking);
        /* translators: %s: código de la reserva */
        $subject = sprintf(__('Tu viaje comienza muy pronto — %s', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            /* translators: %s: nombre del cliente */
            sprintf(__('¡Hola %s! Tu viaje está por comenzar', 'travel-agency-platform'), $name),
            sprintf(
                /* translators: 1: nombre del servicio, 2: fecha de llegada, 3: código de la reserva, 4: enlace al voucher */
                __('Tu estancia en <strong>%s</strong> comienza el <strong>%s</strong>. Ten a mano el código <strong>%s</strong> y tu <a href="%s" style="color:#0d9488;">voucher</a> para el check-in.', 'travel-agency-platform'),
                esc_html($ctx['service_name']),
                esc_html($ctx['check_in']),
                esc_html($booking->booking_code),
                esc_url(home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code)))
            ),
            $ctx,
            'confirmed'
        );
        return self::send($email, $subject, $body);
    }


    public static function send_review_request($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        $review_url = add_query_arg('tap_review', $booking->booking_code, get_permalink($booking->service_id));
        $service = self::get_service_name($booking);
        /* translators: %s: nombre del servicio */
        $subject = sprintf(__('¿Cómo fue tu experiencia en %s?', 'travel-agency-platform'), $service);
        $body = self::info_template(
            __('Gracias por viajar con nosotros', 'travel-agency-platform'),
            sprintf(
                /* translators: 1: nombre del cliente, 2: nombre del servicio */
                __('¡Hola %s! Esperamos que hayas disfrutado tu estancia en <strong>%s</strong>. Tu opinión ayuda a otros viajeros: <a href="%s" style="color:#0d9488;">Deja tu reseña</a>.', 'travel-agency-platform'),
                esc_html($name),
                esc_html($service),
                esc_url($review_url)
            )
        );
        return self::send($email, $subject, $body);
    }


    public static function send_expiry_warning($agency_id, $type, $label, $until) {
        $to = self::get_agency_email($agency_id);
        if (!$to) {
            return false;
        }
        $date = self::format_date($until);
        if ('subscription' === $type) {
            $headline = __('Tu plan está por vencer', 'travel-agency-platform');
            $intro = sprintf(
                /* translators: 1: nombre del plan, 2: fecha de vencimiento */
                __('Tu suscripción al plan <strong>%s</strong> finaliza el <strong>%s</strong>. Renueva para no perder el acceso a sus funciones.', 'travel-agency-platform'),
                esc_html($label),
                esc_html($date)
            );
            $subject = __('Tu plan está por vencer', 'travel-agency-platform');
        } else {
            $headline = __('Tu destacado está por vencer', 'travel-agency-platform');
            $intro = sprintf(
                /* translators: 1: nombre del listado, 2: fecha de fin de la promoción */
                __('El servicio <strong>%s</strong> dejará de estar destacado el <strong>%s</strong>. Puedes renovar su promoción desde tu panel de agencia.', 'travel-agency-platform'),
                esc_html($label),
                esc_html($date)
            );
            $subject = __('Tu promoción está por vencer', 'travel-agency-platform');
        }
        return self::send($to, $subject, self::info_template($headline, $intro));
    }


    public static function send($to, $subject, $body) {
        $body .= TAP_Privacy::email_footer();
        return (bool) wp_mail($to, $subject, $body);
    }


    public static function money($amount) {
        return TAP_Currency::fmt((float) $amount);
    }


    public static function get_agency_email($agency_id) {
        if (!$agency_id) return '';

        $email = get_post_meta($agency_id, '_tap_agency_email', true);
        if (is_email($email)) return $email;

        global $wpdb;
        $agency_user = get_post_meta($agency_id, '_tap_agency_user_id', true);
        if ($agency_user) {
            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}tap_agencies WHERE user_id = %d AND is_active = 1",
                $agency_user
            ));
        } else {
            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}tap_agencies WHERE id = %d AND is_active = 1",
                $agency_id
            ));
        }

        if (is_email($email)) return $email;

        if ($agency_user) {
            $user = get_userdata($agency_user);
            if ($user && is_email($user->user_email)) return $user->user_email;
        }

        return '';
    }


    public static function get_service_name($booking) {
        if ($booking->room_id) {
            return get_the_title($booking->room_id);
        }
        return get_the_title($booking->service_id) ?: $booking->service_type;
    }


    public static function client_contact($booking) {
        $client = get_userdata($booking->client_id);
        if ($client && is_email($client->user_email)) {
            return [$client->user_email, $client->display_name];
        }
        $guest_email = sanitize_email($booking->guest_email);
        if (is_email($guest_email)) {
            return [$guest_email, $booking->guest_name ?: __('Estimado cliente', 'travel-agency-platform')];
        }
        return ['', ''];
    }


    private static function format_date($date) {
        if (!$date) return '—';
        return date_i18n(get_option('date_format'), strtotime($date));
    }


    private static function build_context($booking, $include_client = false) {
        $ctx = [
            'booking_code'     => $booking->booking_code,
            'service_name'     => self::get_service_name($booking),
            'service_type'     => self::service_type_label($booking->service_type),
            'check_in'         => self::format_date($booking->check_in),
            'check_out'        => self::format_date($booking->check_out),
            'nights'           => (int) $booking->nights,
            'adults'           => (int) $booking->adults,
            'children'         => (int) $booking->children,
            'total'            => self::money($booking->total_amount),
            'status'           => self::status_label($booking->status),
            'payment_status'   => self::payment_label($booking->payment_status),
            'notes'            => $booking->notes ? nl2br(esc_html($booking->notes)) : '',
            'management_url'   => admin_url('admin.php?page=tap-bookings'),
            'voucher_url'      => home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code)),
        ];

        if ($include_client || $booking->service_id) {
            list($client_email, $client_name) = self::client_contact($booking);
            $ctx['client'] = $client_name ?: __('Cliente', 'travel-agency-platform');
            $ctx['client_email'] = $client_email;
        }

        return $ctx;
    }


    private static function service_type_label($type) {
        $labels = [
            'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
            'tap_room'          => __('Habitación', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de auto', 'travel-agency-platform'),
            'tap_boat'          => __('Barco', 'travel-agency-platform'),
            'tap_package'       => __('Paquete', 'travel-agency-platform'),
            'tap_equipment'     => __('Equipo', 'travel-agency-platform'),
        ];
        return $labels[$type] ?? $type;
    }


    private static function template($headline, $intro, $ctx, $status = 'pending', $is_agency = false) {
        $primary  = '#0d9488';
        $bg       = '#f1f5f9';
        $card     = '#ffffff';
        $text     = '#0f172a';
        $muted    = '#64748b';
        $border   = '#e2e8f0';
        $status_color = '#d97706';
        if (in_array($status, ['confirmed', 'completed'], true)) $status_color = '#16a34a';
        if (in_array($status, ['cancelled', 'refunded'], true)) $status_color = '#dc2626';

        $rows = '';
        $rows .= self::row(__('Código', 'travel-agency-platform'), esc_html($ctx['booking_code']));
        $rows .= self::row(__('Servicio', 'travel-agency-platform'), esc_html($ctx['service_name']));
        $rows .= self::row(__('Tipo', 'travel-agency-platform'), esc_html($ctx['service_type']));
        $rows .= self::row(__('Check-in', 'travel-agency-platform'), esc_html($ctx['check_in']));
        $rows .= self::row(__('Check-out', 'travel-agency-platform'), esc_html($ctx['check_out']));
        $rows .= self::row(__('Noches', 'travel-agency-platform'), (string) $ctx['nights']);
        $rows .= self::row(__('Huéspedes', 'travel-agency-platform'), sprintf('%d adultos, %d niños', $ctx['adults'], $ctx['children']));
        $rows .= self::row(__('Total', 'travel-agency-platform'), '<strong style="color:' . $primary . '">' . esc_html($ctx['total']) . '</strong>');

        if (!empty($ctx['client'])) {
            $rows .= self::row(__('Cliente', 'travel-agency-platform'), esc_html($ctx['client']));
            if (!empty($ctx['client_email'])) {
                $rows .= self::row(__('Email', 'travel-agency-platform'), esc_html($ctx['client_email']));
            }
        }

        if (!empty($ctx['notes'])) {
            $rows .= self::row(__('Notas', 'travel-agency-platform'), $ctx['notes']);
        }

        $cta = '';
        if ($is_agency) {
            $cta = '<tr><td align="center" style="padding:24px 0 0;"><a href="' . esc_url($ctx['management_url']) . '" style="background-color:' . $primary . ';color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">' . __('Gestionar reserva', 'travel-agency-platform') . '</a></td></tr>';
        } elseif (!empty($ctx['voucher_url'])) {
            $cta = '<tr><td align="center" style="padding:24px 0 0;"><a href="' . esc_url($ctx['voucher_url']) . '" style="background-color:' . $primary . ';color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">' . __('Ver detalle y voucher', 'travel-agency-platform') . '</a></td></tr>';
        }

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background-color:' . $bg . ';font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:' . $bg . ';padding:32px 16px;">
          <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:' . $card . ';border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
              <tr><td style="background-color:' . $primary . ';padding:24px 32px;">
                <div style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.01em;">Travel Agency</div>
              </td></tr>
              <tr><td style="padding:32px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:' . $status_color . ';margin-bottom:8px;">' . ($is_agency ? __('Notificación interna', 'travel-agency-platform') . ' · ' : '') . esc_html($ctx['status']) . ' · ' . esc_html($ctx['payment_status']) . '</div>
                <h1 style="font-size:22px;color:' . $text . ';margin:0 0 8px;line-height:1.3;">' . esc_html($headline) . '</h1>
                <p style="font-size:15px;color:' . $muted . ';line-height:1.6;margin:0 0 24px;">' . $intro . '</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $border . ';border-radius:12px;border-collapse:collapse;">
                  ' . $rows . '
                </table>
                ' . $cta . '
                <p style="font-size:12px;color:' . $muted . ';line-height:1.5;margin:24px 0 0;">' . __('Este es un correo automático. Por favor no respondas a este mensaje.', 'travel-agency-platform') . '</p>
              </td></tr>
              <tr><td style="background-color:' . $bg . ';padding:16px 32px;text-align:center;font-size:12px;color:' . $muted . ';">© ' . date('Y') . ' Travel Agency Platform</td></tr>
            </table>
          </td></tr>
        </table></body></html>';
    }


    private static function row($label, $value) {
        return '<tr>
          <td style="padding:12px 16px;border-bottom:1px solid #eef2f7;font-size:13px;color:#94a3b8;width:35%;background:#f8fafc;">' . $label . '</td>
          <td style="padding:12px 16px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a;">' . $value . '</td>
        </tr>';
    }
}
