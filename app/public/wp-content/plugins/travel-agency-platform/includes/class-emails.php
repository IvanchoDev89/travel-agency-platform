<?php
defined('ABSPATH') || exit;

class TAP_Emails {
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
        add_action('tap_payout_failed', [__CLASS__, 'on_payout_failed'], 10, 1);
        add_action('tap_chat_message_sent', [__CLASS__, 'on_chat_message_sent'], 10, 3);
        add_action('tap_payout_requested', [__CLASS__, 'on_payout_requested'], 10, 3);
        add_action('tap_payment_approved', [__CLASS__, 'on_payment_approved'], 10, 3);
        add_action('tap_booking_cancelled', [__CLASS__, 'on_booking_cancelled'], 10, 2);
        add_action('tap_booking_refund_retried', [__CLASS__, 'on_booking_refund_retried'], 10, 1);
        add_action('tap_subscription_requested', [__CLASS__, 'on_subscription_requested'], 10, 2);
        add_action('tap_promo_requested', [__CLASS__, 'on_promo_requested'], 10, 3);
        add_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);
        add_action('wp_mail_failed', function ($error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('TAP email failed: code=' . $error->get_error_code() . ' msg_hash=' . md5($error->get_error_message()));
        });
    }

    public static function set_html_content_type() {
        return 'text/html';
    }

    /* ===== Event handlers ===== */

    public static function on_booking_created($booking_id, $data) {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) return;

        TAP_Emails_Send::send_user_confirmation($booking_id);

        $agency_email = TAP_Emails_Send::get_agency_email($booking->agency_id);
        if ($agency_email) {
            TAP_Emails_Send::send_agency_notification($booking_id, $agency_email);
        } else {
            TAP_Emails_Send::send_admin_notification($booking_id);
        }
    }

    public static function on_status_updated($booking_id, $status, $prev = '') {
        TAP_Emails_Send::send_user_status_update($booking_id, $status, $prev);
    }

    public static function on_payment_completed($booking_id, $gateway, $transaction_id) {
        TAP_Emails_Send::send_payment_receipt($booking_id);
    }

    public static function on_agency_registered($user_id, $agency_id) {
        $agency = get_post($agency_id);
        $user = get_userdata($user_id);
        if (!$agency || !$user) return;

        $agency_email = get_post_meta($agency_id, '_tap_agency_email', true);
        if (!is_email($agency_email)) $agency_email = $user->user_email;

        /* translators: %s: nombre de la agencia */
        $welcome_headline = sprintf(__('Bienvenido, %s!', 'travel-agency-platform'), $agency->post_title);
        $welcome_intro = sprintf(
            /* translators: 1: nombre de la agencia, 2: enlace al panel de agencia */
            __('Tu agencia <strong>%s</strong> ha sido registrada y ahora está <strong>en revisión</strong>.<br><br>Un administrador verificará tus datos de identificación. En cuanto tu agencia sea aprobada te avisaremos por correo y podrás publicar tus servicios y recibir reservas. Accede a tu <a href="%s" style="color:#0d9488;">panel de agencia</a> para adelantar tus listados.', 'travel-agency-platform'),
            esc_html($agency->post_title),
            esc_url(home_url('/mi-cuenta/'))
        );
        TAP_Emails_Send::send($agency_email, __('Tu agencia está en revisión', 'travel-agency-platform'), TAP_Emails_Send::info_template($welcome_headline, $welcome_intro));

        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $kyc = TAP_Approval::kyc($agency_id);
            $kyc_line = esc_html(trim(($kyc['legal_name'] ?? '') . ' ' . ($kyc['doc_number'] ?? ''))) . ($kyc['legal_tax_id'] ? ' · C. jurídica ' . esc_html($kyc['legal_tax_id']) : '');
            TAP_Emails_Send::send(
                $admin_email,
                /* translators: %s: nombre de la agencia */
                sprintf(__('[Aprobar agencia] %s', 'travel-agency-platform'), $agency->post_title),
                TAP_Emails_Send::info_template(
                    __('Nueva agencia pendiente de aprobación', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: nombre de la agencia, 2: correo del usuario registrador, 3: resumen del estado del KYC, 4: enlace al panel de agencias */
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
        $email  = TAP_Emails_Send::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        TAP_Emails_Send::send(
            $email,
            __('¡Tu agencia fue aprobada!', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                /* translators: %s: nombre de la agencia */
                sprintf(__('Aprobada — %s', 'travel-agency-platform'), $agency->post_title),
                sprintf(
                    /* translators: 1: nombre de la agencia, 2: enlace al panel de agencia */
                    __('Tu agencia <strong>%s</strong> fue aprobada y ya es visible para los viajeros.<br><br>Ya puedes publicar servicios y recibir reservas desde tu <a href="%s" style="color:#0d9488;">panel de agencia</a>.', 'travel-agency-platform'),
                    esc_html($agency->post_title),
                    esc_url(home_url('/mi-cuenta/'))
                )
            )
        );
    }

    public static function on_agency_rejected($agency_id) {
        $agency = get_post($agency_id);
        $email  = TAP_Emails_Send::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        TAP_Emails_Send::send(
            $email,
            __('Tu agencia no fue aprobada', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                /* translators: %s: nombre de la agencia */
                sprintf(__('Agencia en revisión — %s', 'travel-agency-platform'), $agency->post_title),
                sprintf(
                    /* translators: 1: nombre de la agencia, 2: enlace de contacto de soporte */
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
        $email  = TAP_Emails_Send::get_agency_email($agency_id);
        if (!$agency || !$email) return;

        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        if (!$payment) return;

        /* translators: %s: nombre de la agencia */
        $headline = sprintf(__('Liquidación registrada — %s', 'travel-agency-platform'), $agency->post_title);
        $intro = sprintf(
            /* translators: 1: importe de la liquidación, 2: identificador de la liquidación */
            __('Hemos registrado una liquidación de comisiones por <strong>%s</strong> (referencia #%d).<br><br>Los montos ya figuran como cobrados en tu panel de agencia. Si necesitas el comprobante, contacta a soporte.', 'travel-agency-platform'),
            esc_html(TAP_Emails_Send::money($payment->amount)),
            (int) $payment->id
        );
        TAP_Emails_Send::send($email, __('Liquidación de comisiones', 'travel-agency-platform'), TAP_Emails_Send::info_template($headline, $intro));
    }

    public static function on_lead_created($lead_id) {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_leads WHERE id = %d", $lead_id));
        if (!$lead) return;

        $to = TAP_Emails_Send::get_agency_email($lead->agency_id);
        if (!$to) return;

        $service_name = $lead->service_id ? get_the_title($lead->service_id) : '';
        $contact = trim($lead->name . ($lead->phone ? ' — ' . $lead->phone : ''));

        $headline = __('Nuevo mensaje de contacto', 'travel-agency-platform');
        $lines = [];
        /* translators: %s: nombre de quien envió el mensaje */
        $lines[] = sprintf(__('Recibiste un mensaje de <strong>%s</strong>.', 'travel-agency-platform'), esc_html($lead->name));
        if ($service_name) {
            /* translators: %s: nombre del servicio de interés */
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

        TAP_Emails_Send::send(
            $to,
            __('[Nuevo lead] Mensaje de contacto en tu agencia', 'travel-agency-platform'),
            TAP_Emails_Send::info_template($headline, $intro)
        );

        if ('1' === (string) get_option('tap_auto_lead_ack', '1') && is_email($lead->email)) {
            TAP_Emails_Send::send(
                $lead->email,
                __('Recibimos tu mensaje', 'travel-agency-platform'),
                TAP_Emails_Send::info_template(
                    __('Gracias por contactarnos', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: nombre del servicio, 2: enlace a la página de búsqueda */
                        __('Recibimos tu mensaje sobre <strong>%s</strong>. La agencia te responderá lo antes posible.<br><br>Mientras tanto, puedes seguir explorando nuestras <a href="%s" style="color:#0d9488;">experiencias y alojamientos</a>.', 'travel-agency-platform'),
                        esc_html($service_name ?: __('nuestros servicios', 'travel-agency-platform')),
                        esc_url(home_url('/search-results/'))
                    )
                )
            );
        }
    }

    public static function on_subscription_paid($agency_id, $plan_id, $until) {
        $plan = class_exists('TAP_Subscriptions') ? TAP_Subscriptions::get_plan($plan_id) : null;
        TAP_Emails_Send::send_subscription_paid($agency_id, $plan ? $plan->name : __('tu plan', 'travel-agency-platform'), $until);
    }

    public static function on_promo_active($agency_id, $listing_id, $until, $amount) {
        $title = $listing_id ? get_the_title($listing_id) : '';
        TAP_Emails_Send::send_promo_active($agency_id, $title, $until, $amount);
    }

    public static function on_payment_failed($booking_id, $gateway = '', $txn = '') {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        list($email, $name) = TAP_Emails_Send::client_contact($booking);
        if ($email) {
            TAP_Emails_Send::send(
                $email,
                /* translators: %s: código de la reserva */
                sprintf(__('El pago de la reserva %s no pudo completarse', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('No pudimos procesar tu pago', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: código de la reserva, 2: enlace de pago */
                        __('Tu reserva <strong>%s</strong> sigue activa, pero su pago falló. Puedes intentarlo de nuevo desde el <a href="%s" style="color:#0d9488;">checkout</a>.', 'travel-agency-platform'),
                        esc_html($booking->booking_code),
                        esc_url(home_url('/checkout?code=' . rawurlencode($booking->booking_code)))
                    )
                )
            );
        }
        $admin = get_option('admin_email');
        if (is_email($admin)) {
            TAP_Emails_Send::send(
                $admin,
                /* translators: %s: código de la reserva */
                sprintf(__('[Pago fallido] Reserva %s', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('Fallo de pago', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: código de la reserva, 2: tipo de servicio */
                        __('El pago de la reserva <strong>%s</strong> (%s) falló y requiere revisión.', 'travel-agency-platform'),
                        esc_html($booking->booking_code),
                        esc_html(TAP_Emails_Send::get_service_name($booking))
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
        list($email, $name) = TAP_Emails_Send::client_contact($booking);
        if (!$email) {
            return;
        }
        $amount = (float) ($booking->refund_amount ?? 0) > 0 ? (float) $booking->refund_amount : (float) $booking->total_amount;
        TAP_Emails_Send::send(
            $email,
            /* translators: %s: código de la reserva */
            sprintf(__('Reembolso de la reserva %s', 'travel-agency-platform'), $booking->booking_code),
            TAP_Emails_Send::info_template(
                __('Reembolso procesado', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: importe reembolsado, 2: código de la reserva */
                    __('Hemos reembolsado <strong>%s</strong> por la reserva <strong>%s</strong>. El dinero regresará a tu cuenta en los próximos días.', 'travel-agency-platform'),
                    esc_html(TAP_Emails_Send::money($amount)),
                    esc_html($booking->booking_code)
                )
            )
        );
    }

    public static function on_dispute_opened($dispute_id, $booking_id, $agency_id) {
        $to = TAP_Emails_Send::get_agency_email($agency_id);
        if (!$to) {
            return;
        }
        $booking = TAP_Booking::get_booking($booking_id);
        TAP_Emails_Send::send(
            $to,
            __('Se abrió una disputa en una de tus reservas', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                __('Disputa abierta', 'travel-agency-platform'),
                sprintf(
                    /* translators: %s: código de la reserva */
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
        $to = TAP_Emails_Send::get_agency_email($dispute->agency_id);
        if (!$to) {
            return;
        }
        $labels = [
            'for_agency'     => __('Resuelta a favor de la agencia', 'travel-agency-platform'),
            'against_agency' => __('Resuelta a favor del viajero', 'travel-agency-platform'),
            'withdrawn'      => __('Retirada por el viajero', 'travel-agency-platform'),
        ];
        $label = $labels[$outcome] ?? $outcome;
        TAP_Emails_Send::send(
            $to,
            __('Actualización de tu disputa', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                __('Disputa resuelta', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: código de la reserva, 2: resultado de la disputa */
                    __('La disputa asociada a la reserva <strong>%s</strong> fue resuelta: <strong>%s</strong>.', 'travel-agency-platform'),
                    esc_html((string) $booking_id),
                    esc_html($label)
                )
            )
        );
    }

    public static function on_booking_cancelled($booking_id, $booking = null) {
        $booking = $booking ?: TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        $reason_line = '';
        if (!empty($booking->cancel_reason)) {
            $reason_line = '<p><strong>' . esc_html__('Motivo:', 'travel-agency-platform') . '</strong> ' . esc_html($booking->cancel_reason) . '</p>';
        }
        list($email, $name) = TAP_Emails_Send::client_contact($booking);
        if ($email) {
            TAP_Emails_Send::send(
                $email,
                /* translators: %s: código de la reserva */
                sprintf(__('Tu reserva %s fue cancelada', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('Reserva cancelada', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: nombre del cliente, 2: código de la reserva, 3: nombre del servicio, 4: texto sobre el motivo de cancelación, 5: enlace al detalle de la reserva */
                        __('Hola %s, tu reserva <strong>%s</strong> (%s) quedó cancelada. %sSi esperabas un reembolso, lo verás según la política de cancelación. <a href="%s" style="color:#0d9488;">Ver detalle</a>.', 'travel-agency-platform'),
                        esc_html($name),
                        esc_html($booking->booking_code),
                        esc_html(TAP_Emails_Send::get_service_name($booking)),
                        $reason_line,
                        esc_url(home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code)))
                    )
                )
            );
        }
        $agency_email = TAP_Emails_Send::get_agency_email($booking->agency_id);
        if ($agency_email) {
            TAP_Emails_Send::send(
                $agency_email,
                /* translators: %s: código de la reserva */
                sprintf(__('Reserva %s cancelada', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('Cancelación de reserva', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: código de la reserva, 2: nombre del servicio, 3: texto sobre el motivo de cancelación */
                        __('La reserva <strong>%s</strong> (%s) quedó cancelada. %sSi tenía comisión devengada, se anuló.', 'travel-agency-platform'),
                        esc_html($booking->booking_code),
                        esc_html(TAP_Emails_Send::get_service_name($booking)),
                        $reason_line
                    )
                )
            );
        }
    }

    public static function on_payment_approved($booking_id, $gateway = '', $transaction_id = '') {
        $booking = TAP_Booking::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        $agency_email = TAP_Emails_Send::get_agency_email($booking->agency_id);
        if ($agency_email) {
            TAP_Emails_Send::send(
                $agency_email,
                /* translators: %s: código de la reserva */
                sprintf(__('Pago aprobado — reserva %s', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('¡Pago recibido!', 'travel-agency-platform'),
                    sprintf(
                        /* translators: 1: nombre del servicio, 2: código de la reserva, 3: método de pago, 4: enlace a tu cuenta */
                        __('Se aprobó el pago de <strong>%s</strong> para la reserva <strong>%s</strong> mediante %s. Puedes coordinar el servicio con el huésped desde <a href="%s" style="color:#0d9488;">tu cuenta</a>.', 'travel-agency-platform'),
                        esc_html(TAP_Emails_Send::get_service_name($booking)),
                        esc_html($booking->booking_code),
                        esc_html($gateway ?: 'PayPal'),
                        esc_url(home_url('/mi-cuenta/'))
                    )
                )
            );
        }
    }

    public static function on_payout_requested($payment_id, $agency_id, $amount) {
        global $wpdb;
        $admin = get_option('admin_email');
        if (!is_email($admin)) {
            return;
        }
        $agency = get_post($agency_id);
        $row    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        TAP_Emails_Send::send(
            $admin,
            /* translators: %d: identificador de la agencia */
            sprintf(__('Solicitud de liquidación — agencia #%d', 'travel-agency-platform'), (int) $agency_id),
            TAP_Emails_Send::info_template(
                __('Nueva solicitud de pago', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: nombre de la agencia, 2: importe solicitado, 3: identificador de la liquidación, 4: enlace al panel de comisiones */
                    __('La agencia <strong>%s</strong> solicitó una liquidación de <strong>%s</strong> (ref. #%d). Revisa el pago en el <a href="%s" style="color:#0d9488;">panel de comisiones</a>.', 'travel-agency-platform'),
                    esc_html($agency ? $agency->post_title : (string) $agency_id),
                    esc_html(TAP_Emails_Send::money($amount)),
                    (int) $payment_id,
                    esc_url(admin_url('admin.php?page=tap-commissions'))
                )
            )
        );
    }

    public static function on_booking_refund_retried($booking_id) {
        $booking = TAP_Booking::get_booking($booking_id);
        $admin   = get_option('admin_email');
        if (!$booking || !is_email($admin)) {
            return;
        }
        TAP_Emails_Send::send(
            $admin,
            /* translators: %s: código de la reserva */
            sprintf(__('[Reintento de reembolso] Reserva %s', 'travel-agency-platform'), $booking->booking_code),
            TAP_Emails_Send::info_template(
                __('Reembolso reintentado', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: código de la reserva, 2: tipo de servicio */
                    __('Se reintentó el reembolso automático de la reserva <strong>%s</strong> (%s). Si volvió a fallar, requiere revisión manual del equipo de soporte.', 'travel-agency-platform'),
                    esc_html($booking->booking_code),
                    esc_html(TAP_Emails_Send::get_service_name($booking))
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
        $to = TAP_Emails_Send::get_agency_email($payment->agency_id);
        if (!$to) {
            return;
        }
        $completed = ('completed' === $payment->status);
        TAP_Emails_Send::send(
            $to,
            $completed ? __('Pago de comisiones confirmado', 'travel-agency-platform') : __('Liquidación cancelada', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                $completed ? __('Liquidación transferida', 'travel-agency-platform') : __('Liquidación cancelada', 'travel-agency-platform'),
                $completed
                    ? sprintf(
                        /* translators: 1: importe de la liquidación, 2: identificador de la liquidación */
                        __('Tu liquidación de comisiones por <strong>%s</strong> (referencia #%d) fue confirmada como transferida.', 'travel-agency-platform'),
                        esc_html(TAP_Emails_Send::money($payment->amount)),
                        (int) $payment->id
                    )
                    : sprintf(
                        /* translators: 1: importe de la liquidación, 2: identificador de la liquidación */
                        __('La liquidación de comisiones por <strong>%s</strong> (referencia #%d) fue cancelada y los montos volvieron a estado "por cobrar".', 'travel-agency-platform'),
                        esc_html(TAP_Emails_Send::money($payment->amount)),
                        (int) $payment->id
                    )
            )
        );
    }

    public static function on_payout_failed($payment_id) {
        global $wpdb;
        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE id = %d", $payment_id));
        if (!$payment) {
            return;
        }
        $to = TAP_Emails_Send::get_agency_email($payment->agency_id);
        if (!$to) {
            return;
        }
        TAP_Emails_Send::send(
            $to,
            __('Liquidación fallida', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                __('No se pudo completar tu liquidación', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: importe de la liquidación, 2: identificador de la liquidación */
                    __('La liquidación de comisiones por <strong>%s</strong> (referencia #%d) no pudo completarse. Los montos volvieron a estado "por cobrar" para que puedas solicitarla de nuevo.', 'travel-agency-platform'),
                    esc_html(TAP_Emails_Send::money($payment->amount)),
                    (int) $payment->id
                )
            )
        );
    }

    /**
     * Booking-scoped chat: notify the counterparty of a new message.
     * Client message → agency; agency message → guest/client.
     */
    public static function on_chat_message_sent($booking_id, $author_type, $message_id) {
        global $wpdb;
        $booking = TAP_Booking::get_booking((int) $booking_id);
        if (!$booking) {
            return;
        }
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT message FROM {$wpdb->prefix}tap_chat_messages WHERE id = %d",
            (int) $message_id
        ));
        if (!$message) {
            return;
        }
        $excerpt = wp_trim_words((string) $message->message, 40, '…');

        if ('agency' === $author_type) {
            $to = '';
            if (!empty($booking->client_id)) {
                $user = get_userdata((int) $booking->client_id);
                if ($user && is_email($user->user_email)) {
                    $to = $user->user_email;
                }
            }
            if (!$to && !empty($booking->guest_email) && is_email($booking->guest_email)) {
                $to = $booking->guest_email;
            }
            if (!$to) {
                return;
            }
            TAP_Emails_Send::send(
                $to,
                /* translators: %s: código de la reserva */
                sprintf(__('Nuevo mensaje de la agencia — reserva %s', 'travel-agency-platform'), $booking->booking_code),
                TAP_Emails_Send::info_template(
                    __('Tienes un nuevo mensaje', 'travel-agency-platform'),
                    sprintf(
                        /* translators: %s: código de la reserva */
                        __('La agencia escribió sobre tu reserva <strong>%s</strong>:', 'travel-agency-platform'),
                        esc_html($booking->booking_code)
                    )
                    . '<br><br>&ldquo;' . esc_html($excerpt) . '&rdquo;<br><br>'
                    . sprintf(
                        /* translators: %s: enlace al detalle de la reserva */
                        __('<a href="%s" style="color:#0d9488;">Responde desde el detalle de la reserva</a>.', 'travel-agency-platform'),
                        esc_url(home_url('/booking-detail/?code=' . rawurlencode($booking->booking_code)))
                    )
                )
            );
            return;
        }

        $to = TAP_Emails_Send::get_agency_email($booking->agency_id);
        if (!$to) {
            return;
        }
        TAP_Emails_Send::send(
            $to,
            /* translators: %s: código de la reserva */
            sprintf(__('Nuevo mensaje del huésped — reserva %s', 'travel-agency-platform'), $booking->booking_code),
            TAP_Emails_Send::info_template(
                __('Tienes un nuevo mensaje', 'travel-agency-platform'),
                sprintf(
                    /* translators: %s: código de la reserva */
                    __('El huésped escribió sobre la reserva <strong>%s</strong>:', 'travel-agency-platform'),
                    esc_html($booking->booking_code)
                )
                . '<br><br>&ldquo;' . esc_html($excerpt) . '&rdquo;<br><br>'
                . sprintf(
                    /* translators: %s: enlace al panel de agencia */
                    __('<a href="%s" style="color:#0d9488;">Responde desde tu panel</a>.', 'travel-agency-platform'),
                    esc_url(home_url('/mi-cuenta/'))
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
        TAP_Emails_Send::send(
            $admin,
            __('Solicitud de suscripción pendiente', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                __('Nueva suscripción por revisar', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: nombre de la agencia, 2: nombre del plan, 3: enlace al panel de suscripciones */
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
        TAP_Emails_Send::send(
            $admin,
            __('Solicitud de promoción pendiente', 'travel-agency-platform'),
            TAP_Emails_Send::info_template(
                __('Nueva promoción por revisar', 'travel-agency-platform'),
                sprintf(
                    /* translators: 1: nombre de la agencia, 2: nombre del listado, 3: enlace al panel de promociones */
                    __('La agencia <strong>%s</strong> solicitó destacar <strong>%s</strong>. Revisa su pago en el <a href="%s" style="color:#0d9488;">panel de promociones</a>.', 'travel-agency-platform'),
                    esc_html($agency ? $agency->post_title : (string) $agency_id),
                    esc_html($listing),
                    esc_url(admin_url('admin.php?page=tap-promotions'))
                )
            )
        );
    }
}
