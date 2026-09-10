<?php
defined('ABSPATH') || exit;

class TAP_Emails {
    const STATUS_LABELS = [
        'pending'   => 'Pendiente',
        'request'   => 'Solicitud',
        'confirmed' => 'Confirmada',
        'cancelled' => 'Cancelada',
        'completed' => 'Completada',
        'refunded'  => 'Reembolsada',
    ];
    const PAYMENT_LABELS = [
        'pending' => 'Pendiente',
        'paid'    => 'Pagada',
        'partial' => 'Parcial',
        'refunded'=> 'Reembolsada',
        'failed'  => 'Fallida',
    ];

    public static function init() {
        add_action('tap_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
        add_action('tap_booking_status_updated', [__CLASS__, 'on_status_updated'], 10, 3);
        add_action('tap_payment_completed', [__CLASS__, 'on_payment_completed'], 10, 3);
        add_action('tap_agency_registered', [__CLASS__, 'on_agency_registered'], 10, 2);
        add_action('tap_agency_approved', [__CLASS__, 'on_agency_approved'], 10, 1);
        add_action('tap_agency_rejected', [__CLASS__, 'on_agency_rejected'], 10, 1);
        add_action('tap_subscription_paid', [__CLASS__, 'on_subscription_paid'], 10, 3);
        add_action('tap_promo_active', [__CLASS__, 'on_promo_active'], 10, 4);
        add_action('tap_commission_paid', [__CLASS__, 'on_commission_paid'], 10, 2);
        add_action('tap_lead_created', [__CLASS__, 'on_lead_created'], 10, 1);
        add_action('tap_payment_failed', [__CLASS__, 'on_payment_failed'], 10, 3);
        add_action('tap_payment_refunded', [__CLASS__, 'on_payment_refunded'], 10, 3);
        add_action('tap_dispute_opened', [__CLASS__, 'on_dispute_opened'], 10, 3);
        add_action('tap_dispute_resolved', [__CLASS__, 'on_dispute_resolved'], 10, 3);
        add_action('tap_payout_completed', [__CLASS__, 'on_payout_changed'], 10, 1);
        add_action('tap_payout_cancelled', [__CLASS__, 'on_payout_changed'], 10, 1);
        add_action('tap_subscription_requested', [__CLASS__, 'on_subscription_requested'], 10, 2);
        add_action('tap_promo_requested', [__CLASS__, 'on_promo_requested'], 10, 3);
        add_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);
        add_action('wp_mail_failed', function ($error) {
            error_log('TAP email failed: ' . $error->get_error_message());
        });
    }

    public static function set_html_content_type() {
        return 'text/html';
    }

    /* ===== Event handlers ===== */

    public static function on_booking_created($booking_id, $data) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        self::send_user_confirmation($booking_id);

        $agency_email = self::get_agency_email($booking->agency_id);
        if ($agency_email) {
            self::send_agency_notification($booking_id, $agency_email);
        } else {
            self::send_admin_notification($booking_id);
        }
    }

    public static function on_status_updated($booking_id, $status, $prev = '') {
        self::send_user_status_update($booking_id, $status, $prev);
    }

    public static function on_payment_completed($booking_id, $gateway, $transaction_id) {
        self::send_payment_receipt($booking_id);
    }

    public static function on_agency_registered($user_id, $agency_id) {
        $agency = get_post($agency_id);
        $user = get_userdata($user_id);
        if (!$agency || !$user) return;

        $agency_email = get_post_meta($agency_id, '_tap_agency_email', true);
        if (!is_email($agency_email)) $agency_email = $user->user_email;

        $welcome_headline = sprintf(__('Bienvenido, %s!', 'travel-agency-platform'), $agency->post_title);
        $welcome_intro = sprintf(
            __('Tu agencia <strong>%s</strong> ha sido registrada y ahora está <strong>en revisión</strong>.<br><br>Un administrador verificará tus datos de identificación. En cuanto tu agencia sea aprobada te avisaremos por correo y podrás publicar tus servicios y recibir reservas. Accede a tu <a href="%s" style="color:#0d9488;">panel de agencia</a> para adelantar tus listados.', 'travel-agency-platform'),
            esc_html($agency->post_title),
            esc_url(home_url('/dashboard/'))
        );
        self::send($agency_email, __('Tu agencia está en revisión', 'travel-agency-platform'), self::info_template($welcome_headline, $welcome_intro));

        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $kyc = TAP_Approval::kyc($agency_id);
            $kyc_line = esc_html(trim(($kyc['legal_name'] ?? '') . ' ' . ($kyc['doc_number'] ?? ''))) . ($kyc['legal_tax_id'] ? ' · C. jurídica ' . esc_html($kyc['legal_tax_id']) : '');
            self::send(
                $admin_email,
                sprintf(__('[Aprobar agencia] %s', 'travel-agency-platform'), $agency->post_title),
                self::info_template(
                    __('Nueva agencia pendiente de aprobación', 'travel-agency-platform'),
                    sprintf(
                        __('La agencia <strong>%s</strong> (%s) se registró y espera la verificación de sus datos.<br>KYC: %s<br><br><a href="%s" style="color:#0d9488;">Revisar en el panel de agencias</a>.', 'travel-agency-platform'),
                        esc_html($agency->post_title),
                        esc_html($user->user_email),
                        $kyc_line ?: '—',
                        esc_url(admin_url('admin.php?page=tap-agencies'))
                    )
                )
            );
        }
    }

    public static function on_agency_approved($agency_id) {
        $agency = get_post($agency_id);
        $email  = self::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        self::send(
            $email,
            __('¡Tu agencia fue aprobada!', 'travel-agency-platform'),
            self::info_template(
                sprintf(__('Aprobada — %s', 'travel-agency-platform'), $agency->post_title),
                sprintf(
                    __('Tu agencia <strong>%s</strong> fue aprobada y ya es visible para los viajeros.<br><br>Ya puedes publicar servicios y recibir reservas desde tu <a href="%s" style="color:#0d9488;">panel de agencia</a>.', 'travel-agency-platform'),
                    esc_html($agency->post_title),
                    esc_url(home_url('/dashboard/'))
                )
            )
        );
    }

    public static function on_agency_rejected($agency_id) {
        $agency = get_post($agency_id);
        $email  = self::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        self::send(
            $email,
            __('Tu agencia no fue aprobada', 'travel-agency-platform'),
            self::info_template(
                sprintf(__('Agencia en revisión — %s', 'travel-agency-platform'), $agency->post_title),
                sprintf(
                    __('Lamentablemente tu agencia <strong>%s</strong> no fue aprobada en esta revisión.<br><br>Contacta a soporte en <a href="%s" style="color:#0d9488;">info@visitnuevoarenal.com</a> para corregir los datos de identificación y volver a intentarlo.', 'travel-agency-platform'),
                    esc_html($agency->post_title),
                    esc_url(home_url('/'))
                )
            )
        );
    }

    public static function on_commission_paid($agency_id, $payment_id) {
        global $wpdb;
        $agency = get_post($agency_id);
        $email  = self::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        if (!$payment) return;

        $headline = sprintf(__('Liquidación registrada — %s', 'travel-agency-platform'), $agency->post_title);
        $intro = sprintf(
            __('Hemos registrado una liquidación de comisiones por <strong>%s</strong> (referencia #%d).<br><br>Los montos ya figuran como cobrados en tu panel de agencia. Si necesitas el comprobante, contacta a soporte.', 'travel-agency-platform'),
            esc_html(self::money($payment->amount)),
            (int) $payment->id
        );
        self::send($email, __('Liquidación de comisiones', 'travel-agency-platform'), self::info_template($headline, $intro));
    }

    public static function on_lead_created($lead_id) {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE id = %d", $lead_id));
        if (!$lead) return;

        $to = self::get_agency_email($lead->agency_id);
        if (!$to) return;

        $service_name = $lead->service_id ? get_the_title($lead->service_id) : '';
        $contact = trim($lead->name . ($lead->phone ? ' — ' . $lead->phone : ''));

        $headline = __('Nuevo mensaje de contacto', 'travel-agency-platform');
        $lines = [];
        $lines[] = sprintf(__('Recibiste un mensaje de <strong>%s</strong>.', 'travel-agency-platform'), esc_html($lead->name));
        if ($service_name) {
            $lines[] = sprintf(__('Servicio de interés: <strong>%s</strong>', 'travel-agency-platform'), esc_html($service_name));
        }
        $lines[] = __('Correo: ', 'travel-agency-platform') . esc_html($lead->email);
        if ($lead->phone) {
            $lines[] = __('Teléfono: ', 'travel-agency-platform') . esc_html($lead->phone);
        }
        if ($lead->message) {
            $lines[] = '<br>' . nl2br(esc_html($lead->message));
        }
        $intro = implode('<br>', $lines);

        self::send(
            $to,
            __('[Nuevo lead] Mensaje de contacto en tu agencia', 'travel-agency-platform'),
            self::info_template($headline, $intro)
        );

        if ('1' === (string) get_option('tap_auto_lead_ack', '1') && is_email($lead->email)) {
            self::send(
                $lead->email,
                __('Recibimos tu mensaje', 'travel-agency-platform'),
                self::info_template(
                    __('Gracias por contactarnos', 'travel-agency-platform'),
                    sprintf(
                        __('Recibimos tu mensaje sobre <strong>%s</strong>. La agencia te responderá lo antes posible.<br><br>Mientras tanto, puedes seguir explorando nuestras <a href="%s" style="color:#0d9488;">experiencias y alojamientos</a>.', 'travel-agency-platform'),
                        esc_html($service_name ?: __('nuestros servicios', 'travel-agency-platform')),
                        esc_url(home_url('/search-results/'))
                    )
                )
            );
        }
    }

    private static function info_template($headline, $intro) {
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
            $subject = sprintf(__('Solicitud de reserva %s recibida', 'travel-agency-platform'), $booking->booking_code);
            $body = self::template(
                sprintf(__('¡Gracias %s! Hemos recibido tu solicitud.', 'travel-agency-platform'), $name),
                __('La agencia revisará tu solicitud y te avisaremos por correo cuando sea aceptada. No se realizará ningún cobro hasta entonces.', 'travel-agency-platform'),
                $ctx,
                'request'
            );
            self::send($email, $subject, $body);
            return;
        }

        $subject = sprintf(__('Reserva %s recibida', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
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
        $label = self::STATUS_LABELS[$status] ?? $status;

        $headline = [
            'confirmed' => __('¡Tu reserva ha sido confirmada!', 'travel-agency-platform'),
            'cancelled' => __('Tu reserva ha sido cancelada', 'travel-agency-platform'),
            'completed' => __('Tu reserva se ha completado', 'travel-agency-platform'),
            'refunded'  => __('Tu reserva ha sido reembolsada', 'travel-agency-platform'),
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
                __('La agencia aceptó tu solicitud de reserva. Completa el pago para confirmar: <a href="%s" style="color:#0d9488;">Pagar ahora</a>.', 'travel-agency-platform'),
                esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
            );
        } elseif ($status === 'cancelled' && $prev === 'request') {
            $headline = __('Solicitud rechazada', 'travel-agency-platform');
            $intro = __('La agencia no pudo aceptar tu solicitud de reserva. No se realizó ningún cobro. Contacta a la agencia o prueba con otras fechas.', 'travel-agency-platform');
        }

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
            $subject = sprintf(__('[Nueva solicitud] %s — %s', 'travel-agency-platform'), $booking->booking_code, $booking->service_type);
            $body = self::template(
                __('Nueva solicitud de reserva', 'travel-agency-platform'),
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

        $subject = sprintf(__('[Nueva reserva] %s — %s', 'travel-agency-platform'), $booking->booking_code, $booking->service_type);
        $body = self::template(
            __('Nueva reserva recibida', 'travel-agency-platform'),
            sprintf(__('Se ha registrado una nueva reserva (<strong>%s</strong>) por un total de <strong>%s</strong>. Revisa el panel para gestionarla.', 'travel-agency-platform'),
                $booking->booking_code,
                self::money($booking->total_amount)),
            $ctx,
            $booking->status,
            true
        );

        self::send($to, $subject, $body);
    }

    public static function on_subscription_paid($agency_id, $plan_id, $until) {
        $plan = class_exists('TAP_Subscriptions') ? TAP_Subscriptions::get_plan($plan_id) : null;
        self::send_subscription_paid($agency_id, $plan ? $plan->name : __('tu plan', 'travel-agency-platform'), $until);
    }

    public static function send_subscription_paid($agency_id, $plan_name, $until) {
        $to = self::get_agency_email($agency_id);
        if (!$to) return;
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

    public static function on_promo_active($agency_id, $listing_id, $until, $amount) {
        $title = $listing_id ? get_the_title($listing_id) : '';
        self::send_promo_active($agency_id, $title, $until, $amount);
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

    public static function on_payment_failed($booking_id, $gateway = '', $txn = '') {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        list($email, $name) = self::client_contact($booking);
        if ($email) {
            self::send(
                $email,
                sprintf(__('El pago de la reserva %s no pudo completarse', 'travel-agency-platform'), $booking->booking_code),
                self::info_template(
                    __('No pudimos procesar tu pago', 'travel-agency-platform'),
                    sprintf(
                        __('Tu reserva <strong>%s</strong> sigue activa, pero su pago falló. Puedes intentarlo de nuevo desde el <a href="%s" style="color:#0d9488;">checkout</a>.', 'travel-agency-platform'),
                        esc_html($booking->booking_code),
                        esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
                    )
                )
            );
        }
        $admin = get_option('admin_email');
        if (is_email($admin)) {
            self::send(
                $admin,
                sprintf(__('[Pago fallido] Reserva %s', 'travel-agency-platform'), $booking->booking_code),
                self::info_template(
                    __('Fallo de pago', 'travel-agency-platform'),
                    sprintf(
                        __('El pago de la reserva <strong>%s</strong> (%s) falló y requiere revisión.', 'travel-agency-platform'),
                        esc_html($booking->booking_code),
                        esc_html(self::get_service_name($booking))
                    )
                )
            );
        }
    }

    public static function on_payment_refunded($booking_id, $gateway = '', $txn = '') {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return;
        }
        self::send(
            $email,
            sprintf(__('Reembolso de la reserva %s', 'travel-agency-platform'), $booking->booking_code),
            self::info_template(
                __('Reembolso procesado', 'travel-agency-platform'),
                sprintf(
                    __('Hemos reembolsado <strong>%s</strong> por la reserva <strong>%s</strong>. El dinero regresará a tu cuenta en los próximos días.', 'travel-agency-platform'),
                    esc_html(self::money($booking->total_amount)),
                    esc_html($booking->booking_code)
                )
            )
        );
    }

    public static function on_dispute_opened($dispute_id, $booking_id, $agency_id) {
        $to = self::get_agency_email($agency_id);
        if (!$to) {
            return;
        }
        $booking = TAP_Booking::get_booking($booking_id);
        self::send(
            $to,
            __('Se abrió una disputa en una de tus reservas', 'travel-agency-platform'),
            self::info_template(
                __('Disputa abierta', 'travel-agency-platform'),
                sprintf(
                    __('Un viajero abrió una disputa sobre la reserva <strong>%s</strong>. La revisaremos y te notificaremos el resultado.', 'travel-agency-platform'),
                    esc_html($booking ? $booking->booking_code : (string) $booking_id)
                )
            )
        );
    }

    public static function on_dispute_resolved($dispute_id, $outcome, $booking_id) {
        global $wpdb;
        $dispute = $wpdb->get_row($wpdb->prepare("SELECT agency_id FROM {$wpdb->prefix}tap_disputes WHERE id = %d", $dispute_id));
        if (!$dispute) {
            return;
        }
        $to = self::get_agency_email($dispute->agency_id);
        if (!$to) {
            return;
        }
        $labels = [
            'for_agency'     => __('Resuelta a favor de la agencia', 'travel-agency-platform'),
            'against_agency' => __('Resuelta a favor del viajero', 'travel-agency-platform'),
            'withdrawn'      => __('Retirada por el viajero', 'travel-agency-platform'),
        ];
        $label = $labels[$outcome] ?? $outcome;
        self::send(
            $to,
            __('Actualización de tu disputa', 'travel-agency-platform'),
            self::info_template(
                __('Disputa resuelta', 'travel-agency-platform'),
                sprintf(
                    __('La disputa asociada a la reserva <strong>%s</strong> fue resuelta: <strong>%s</strong>.', 'travel-agency-platform'),
                    esc_html((string) $booking_id),
                    esc_html($label)
                )
            )
        );
    }

    public static function on_payout_changed($payment_id) {
        global $wpdb;
        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        if (!$payment) {
            return;
        }
        $to = self::get_agency_email($payment->agency_id);
        if (!$to) {
            return;
        }
        $completed = ('completed' === $payment->status);
        self::send(
            $to,
            $completed ? __('Pago de comisiones confirmado', 'travel-agency-platform') : __('Liquidación cancelada', 'travel-agency-platform'),
            self::info_template(
                $completed ? __('Liquidación transferida', 'travel-agency-platform') : __('Liquidación cancelada', 'travel-agency-platform'),
                $completed
                    ? sprintf(
                        __('Tu liquidación de comisiones por <strong>%s</strong> (referencia #%d) fue confirmada como transferida.', 'travel-agency-platform'),
                        esc_html(self::money($payment->amount)),
                        (int) $payment->id
                    )
                    : sprintf(
                        __('La liquidación de comisiones por <strong>%s</strong> (referencia #%d) fue cancelada y los montos volvieron a estado "por cobrar".', 'travel-agency-platform'),
                        esc_html(self::money($payment->amount)),
                        (int) $payment->id
                    )
            )
        );
    }

    public static function on_subscription_requested($agency_id, $plan_id) {
        $admin = get_option('admin_email');
        if (!is_email($admin)) {
            return;
        }
        $agency = get_post($agency_id);
        $plan   = get_the_title($plan_id) ?: __('Plan', 'travel-agency-platform');
        self::send(
            $admin,
            __('Solicitud de suscripción pendiente', 'travel-agency-platform'),
            self::info_template(
                __('Nueva suscripción por revisar', 'travel-agency-platform'),
                sprintf(
                    __('La agencia <strong>%s</strong> solicitó el plan <strong>%s</strong>. Revisa su pago en el <a href="%s" style="color:#0d9488;">panel de suscripciones</a>.', 'travel-agency-platform'),
                    esc_html($agency ? $agency->post_title : (string) $agency_id),
                    esc_html($plan),
                    esc_url(admin_url('admin.php?page=tap-subscriptions'))
                )
            )
        );
    }

    public static function on_promo_requested($agency_id, $listing_id, $promo_id) {
        $admin = get_option('admin_email');
        if (!is_email($admin)) {
            return;
        }
        $agency = get_post($agency_id);
        $listing = get_the_title($listing_id) ?: __('Servicio', 'travel-agency-platform');
        self::send(
            $admin,
            __('Solicitud de promoción pendiente', 'travel-agency-platform'),
            self::info_template(
                __('Nueva promoción por revisar', 'travel-agency-platform'),
                sprintf(
                    __('La agencia <strong>%s</strong> solicitó destacar <strong>%s</strong>. Revisa su pago en el <a href="%s" style="color:#0d9488;">panel de promociones</a>.', 'travel-agency-platform'),
                    esc_html($agency ? $agency->post_title : (string) $agency_id),
                    esc_html($listing),
                    esc_url(admin_url('admin.php?page=tap-promotions'))
                )
            )
        );
    }

    /* =========================================================
     * Automation senders (driven by TAP_Automations cron)
     * ========================================================= */

    public static function send_payment_reminder($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        $subject = sprintf(__('Recordatorio de pago — reserva %s', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            sprintf(__('Hola %s, tu reserva está esperando pago', 'travel-agency-platform'), $name),
            sprintf(
                __('Tienes una reserva pendiente de pago. Completa el pago para mantenerla confirmada: <a href="%s" style="color:#0d9488;">Pagar ahora</a>.', 'travel-agency-platform'),
                esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
            ),
            self::build_context($booking),
            'pending'
        );
        self::send($email, $subject, $body);
        return true;
    }

    public static function send_prearrival_msg($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        $ctx = self::build_context($booking);
        $subject = sprintf(__('Tu viaje comienza muy pronto — %s', 'travel-agency-platform'), $booking->booking_code);
        $body = self::template(
            sprintf(__('¡Hola %s! Tu viaje está por comenzar', 'travel-agency-platform'), $name),
            sprintf(
                __('Tu estancia en <strong>%s</strong> comienza el <strong>%s</strong>. Ten a mano el código <strong>%s</strong> y tu <a href="%s" style="color:#0d9488;">voucher</a> para el check-in.', 'travel-agency-platform'),
                esc_html($ctx['service_name']),
                esc_html($ctx['check_in']),
                esc_html($booking->booking_code),
                esc_url(home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code)))
            ),
            $ctx,
            'confirmed'
        );
        self::send($email, $subject, $body);
        return true;
    }

    public static function send_review_request($booking) {
        list($email, $name) = self::client_contact($booking);
        if (!$email) {
            return false;
        }
        $review_url = add_query_arg('tap_review', $booking->booking_code, get_permalink($booking->service_id));
        $service = self::get_service_name($booking);
        $subject = sprintf(__('¿Cómo fue tu experiencia en %s?', 'travel-agency-platform'), $service);
        $body = self::info_template(
            __('Gracias por viajar con nosotros', 'travel-agency-platform'),
            sprintf(
                __('¡Hola %s! Esperamos que hayas disfrutado tu estancia en <strong>%s</strong>. Tu opinión ayuda a otros viajeros: <a href="%s" style="color:#0d9488;">Deja tu reseña</a>.', 'travel-agency-platform'),
                esc_html($name),
                esc_html($service),
                esc_url($review_url)
            )
        );
        self::send($email, $subject, $body);
        return true;
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
                __('Tu suscripción al plan <strong>%s</strong> finaliza el <strong>%s</strong>. Renueva para no perder el acceso a sus funciones.', 'travel-agency-platform'),
                esc_html($label),
                esc_html($date)
            );
            $subject = __('Tu plan está por vencer', 'travel-agency-platform');
        } else {
            $headline = __('Tu destacado está por vencer', 'travel-agency-platform');
            $intro = sprintf(
                __('El servicio <strong>%s</strong> dejará de estar destacado el <strong>%s</strong>. Puedes renovar su promoción desde tu panel de agencia.', 'travel-agency-platform'),
                esc_html($label),
                esc_html($date)
            );
            $subject = __('Tu promoción está por vencer', 'travel-agency-platform');
        }
        self::send($to, $subject, self::info_template($headline, $intro));
        return true;
    }

    private static function send($to, $subject, $body) {
        $body .= TAP_Privacy::email_footer();
        wp_mail($to, $subject, $body);
    }

    private static function money($amount, $currency = '') {
        return TAP_Currency::fmt((float) $amount);
    }

    private static function get_agency_email($agency_id) {
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

    private static function get_service_name($booking) {
        if ($booking->room_id) {
            return get_the_title($booking->room_id);
        }
        return get_the_title($booking->service_id) ?: $booking->service_type;
    }

    private static function client_contact($booking) {
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
            'status'           => self::STATUS_LABELS[$booking->status] ?? $booking->status,
            'payment_status'   => self::PAYMENT_LABELS[$booking->payment_status] ?? $booking->payment_status,
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
        if (in_array($status, ['confirmed', 'completed'])) $status_color = '#16a34a';
        if (in_array($status, ['cancelled', 'refunded'])) $status_color = '#dc2626';

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
