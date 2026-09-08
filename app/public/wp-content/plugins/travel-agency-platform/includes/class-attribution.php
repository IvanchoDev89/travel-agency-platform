<?php
/**
 * Fase 4 — anti-fuga y atribución de ventas.
 *
 * Los datos de contacto de los viajeros (nombre completo, correo, teléfono)
 * solo se revelan a una agencia cuando esa agencia ya tiene una reserva
 * pagada (estado confirmed) del cliente. Antes, se muestran enmascarados.
 * Además, cada reserva se vincula al lead de origen (mismo correo y agencia)
 * para que el administrador pueda medir la conversión lead -> venta.
 */
defined('ABSPATH') || exit;

class TAP_Attribution {

    public static function init() {
        add_action('tap_booking_created', [__CLASS__, 'link_booking_to_lead'], 10, 2);
    }

    /**
     * Contact fields of a client matching any given email are unlocked for an
     * agency once that agency has at least one confirmed (paid) booking whose
     * guest email or account email matches.
     *
     * @param int   $agency_id tap_agency post id (bookings.agency_id namespace).
     * @param array|string $emails One or more client emails.
     * @return bool
     */
    public static function contact_unlocked($agency_id, $emails) {
        $emails = self::normalize_emails($emails);
        $agency_id = self::agency_post_id($agency_id);
        if ($agency_id < 1 || empty($emails)) {
            return false;
        }
        global $wpdb;
        $t  = $wpdb->prefix . 'tap_bookings';
        $in = implode(',', array_map(function ($e) {
            return "'" . esc_sql(strtolower($e)) . "'";
        }, $emails));
        $n  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} b
             LEFT JOIN {$wpdb->users} u ON u.ID = b.client_id
             WHERE b.agency_id = %d AND b.status = 'confirmed'
               AND (LOWER(COALESCE(b.guest_email, '')) IN ({$in})
                    OR LOWER(COALESCE(u.user_email, '')) IN ({$in}))",
            $agency_id
        ));
        return $n > 0;
    }

    /**
     * True when the booking itself is paid (contact may be shown in full).
     */
    public static function booking_unlocked($booking) {
        return is_object($booking) && isset($booking->status) && $booking->status === 'confirmed';
    }

    /**
     * Mask an email keeping the first character of the local part and the domain.
     */
    public static function mask_email($email) {
        $email = (string) $email;
        if (!$email) {
            return '';
        }
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return $email;
        }
        $local = $parts[0];
        $masked_local = mb_substr($local, 0, 1) . '***';
        return $masked_local . '@' . $parts[1];
    }

    /**
     * Mask a phone number keeping the first three characters and the last two digits.
     */
    public static function mask_phone($phone) {
        $phone = (string) $phone;
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 4) {
            return $phone === '' ? '' : '***';
        }
        return mb_substr($phone, 0, 3) . '***' . substr($digits, -2);
    }

    /**
     * Mask a full name: first name kept, last names reduced to initials.
     */
    public static function mask_name($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0];
        if (count($parts) === 1) {
            return $first;
        }
        return $first . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }

    /**
     * On booking creation, link the booking to the most recent contact lead
     * from the same agency and email, if any. Stores the lead id in the
     * booking row so the admin can measure lead -> sale attribution.
     *
     * @param int   $booking_id
     * @param array $data        Raw create() data (may contain guest_email).
     */
    public static function link_booking_to_lead($booking_id, $data = []) {
        global $wpdb;
        $b = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $booking_id
        ));
        if (!$b) {
            return;
        }

        $email = '';
        if (is_email($b->guest_email)) {
            $email = $b->guest_email;
        } elseif ((int) $b->client_id > 0) {
            $user = get_userdata((int) $b->client_id);
            if ($user) {
                $email = $user->user_email;
            }
        }
        if (!is_email($email)) {
            return;
        }

        $scope = self::lead_agency_scope((int) $b->agency_id);
        if ($scope < 1) {
            return;
        }

        $lead_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tap_leads
             WHERE agency_id = %d AND LOWER(email) = LOWER(%s)
             ORDER BY created_at DESC, id DESC LIMIT 1",
            $scope,
            $email
        ));
        if ($lead_id < 1) {
            return;
        }
        $wpdb->update(
            $wpdb->prefix . 'tap_bookings',
            ['lead_id' => $lead_id],
            ['id' => (int) $booking_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Map a booking agency (tap_agency post id) to the tap_leads.agency_id
     * namespace (tap_agencies row id), or 0 when no row exists.
     */
    public static function lead_agency_scope($agency_id) {
        $agency_id = (int) $agency_id;
        if ($agency_id < 1 || !class_exists('TAP_Leads') || !method_exists('TAP_Leads', 'agency_row_id')) {
            return 0;
        }
        return (int) TAP_Leads::agency_row_id($agency_id);
    }

    /**
     * Normalize an agency identifier (post id or tap_agencies row id) to the
     * tap_agency post id used by bookings.
     */
    public static function agency_post_id($agency_id) {
        $agency_id = (int) $agency_id;
        if ($agency_id < 1) {
            return 0;
        }
        $post = get_post($agency_id);
        if ($post && $post->post_type === 'tap_agency') {
            return $agency_id;
        }
        global $wpdb;
        $uid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}tap_agencies WHERE id = %d",
            $agency_id
        ));
        if ($uid < 1) {
            return 0;
        }
        $posts = get_posts([
            'post_type'      => 'tap_agency',
            'post_status'    => 'any',
            'meta_key'       => '_tap_agency_user_id',
            'meta_value'     => $uid,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        return $posts ? (int) $posts[0] : 0;
    }

    /**
     * @param array|string $emails
     * @return string[] Lowercased, deduplicated, non-empty emails.
     */
    private static function normalize_emails($emails) {
        $emails = is_array($emails) ? $emails : [$emails];
        $out = [];
        foreach ($emails as $e) {
            $e = is_email((string) $e) ? strtolower(sanitize_email($e)) : '';
            if ($e !== '' && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }
}