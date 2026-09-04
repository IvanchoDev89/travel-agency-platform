<?php
defined('ABSPATH') || exit;

class TAP_Promotions {
    public static function init() {
        add_action('init', [__CLASS__, 'maybe_expire']);
    }

    public static function promos_table() {
        global $wpdb;
        return $wpdb->prefix . 'tap_promos';
    }

    public static function get_price() {
        return max(0, (float) get_option('tap_featured_price', 5));
    }

    public static function prefix_for_type($type) {
        $prefix = TAP_Post_Types::meta_prefix($type);
        return $prefix !== '' ? $prefix : null;
    }

    public static function keys_for_type($type) {
        $prefix = self::prefix_for_type($type);
        if ($prefix === null) {
            return null;
        }
        return [
            'type'       => (string) $type,
            'prefix'     => (string) $prefix,
            'agency_key' => '_tap_' . $prefix . '_agency_id',
            'flag_key'   => '_tap_' . $prefix . '_is_featured',
            'until_key'  => '_tap_' . $prefix . '_featured_until',
            'active_key' => '_tap_' . $prefix . '_is_active',
        ];
    }

    public static function listing_meta_keys($post_id) {
        return self::keys_for_type(get_post_type($post_id));
    }

    public static function agency_of_listing($post_id) {
        $keys = self::listing_meta_keys($post_id);
        if (!$keys) {
            return 0;
        }
        return (int) get_post_meta($post_id, $keys['agency_key'], true);
    }

    public static function is_featured($post_id) {
        $keys = self::listing_meta_keys($post_id);
        if (!$keys) {
            return false;
        }
        if ('1' !== (string) get_post_meta($post_id, $keys['flag_key'], true)) {
            return false;
        }
        $until = self::featured_until($post_id);
        if ($until && $until < current_time('Y-m-d')) {
            return false;
        }
        return true;
    }

    public static function featured_until($post_id) {
        $keys = self::listing_meta_keys($post_id);
        if (!$keys) {
            return '';
        }
        return (string) get_post_meta($post_id, $keys['until_key'], true);
    }

    public static function featured_slots($agency_id) {
        if (class_exists('TAP_Subscriptions')) {
            $plan = TAP_Subscriptions::active_plan($agency_id);
            if ($plan && isset($plan->featured_slots)) {
                return (int) $plan->featured_slots;
            }
        }
        return 0;
    }

    public static function active_promos_count($agency_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::promos_table() . " WHERE agency_id = %d AND status = 'active' AND paid_until >= CURDATE()",
            (int) $agency_id
        ));
    }

    public static function pending_promos($agency_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::promos_table() . " WHERE agency_id = %d AND status = 'pending' ORDER BY created_at DESC",
            (int) $agency_id
        ));
    }

    public static function active_promos($agency_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::promos_table() . " WHERE agency_id = %d AND status = 'active' ORDER BY paid_until DESC",
            (int) $agency_id
        ));
    }

    public static function request($agency_id, $listing_id, $months = 1) {
        global $wpdb;
        $agency_id  = (int) $agency_id;
        $listing_id = (int) $listing_id;
        $months     = min(24, max(1, (int) $months));

        $post = get_post($listing_id);
        $keys = self::listing_meta_keys($listing_id);
        if (!$post || !$keys || 'publish' !== $post->post_status) {
            return new WP_Error('listing_invalid', __('The listing is not available for promotion.', 'travel-agency-platform'));
        }
        if ((int) get_post_meta($listing_id, $keys['agency_key'], true) !== $agency_id) {
            return new WP_Error('not_owner', __('You can only promote your own listings.', 'travel-agency-platform'));
        }

        $slots = self::featured_slots($agency_id);
        if ($slots <= 0) {
            $plan = class_exists('TAP_Subscriptions') ? TAP_Subscriptions::active_plan($agency_id) : null;
            $plan_name = $plan ? $plan->name : __('tu plan', 'travel-agency-platform');
            return new WP_Error('no_slots', sprintf(
                __('Tu plan actual (%s) no incluye destacados. Mejora tu plan para promocionar tus listados.', 'travel-agency-platform'),
                $plan_name
            ));
        }

        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::promos_table() . " WHERE listing_id = %d AND status = 'pending'",
            $listing_id
        ));
        if ((int) $pending > 0) {
            return new WP_Error('promo_pending', __('Ya tienes una solicitud de destacado pendiente para este listado. El administrador confirmará el pago.', 'travel-agency-platform'));
        }

        if (self::active_promos_count($agency_id) >= $slots) {
            return new WP_Error('slots_full', sprintf(
                __('Ya tienes %d destacados activos (el máximo de tu plan). Espera a que expire uno o mejora de plan.', 'travel-agency-platform'),
                $slots
            ));
        }

        $amount = round(self::get_price() * $months, 2);
        $inserted = $wpdb->insert(
            self::promos_table(),
            [
                'agency_id'      => $agency_id,
                'listing_id'     => $listing_id,
                'months'         => $months,
                'amount'         => $amount,
                'status'         => 'pending',
                'payment_status' => 'pending',
                'payment_method' => 'manual',
                'notes'          => sprintf(__('Promoción de %d mes(es) solicitada', 'travel-agency-platform'), $months),
            ]
        );
        if (!$inserted) {
            return new WP_Error('promo_failed', __('No se pudo procesar la solicitud. Inténtalo de nuevo.', 'travel-agency-platform'));
        }
        $promo_id = (int) $wpdb->insert_id;
        do_action('tap_promo_requested', $agency_id, $listing_id, $promo_id);
        return $promo_id;
    }

    public static function activate($promo_id, $admin_id = 0, $months = 0) {
        global $wpdb;
        $promo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::promos_table() . " WHERE id = %d",
            (int) $promo_id
        ));
        if (!$promo) {
            return new WP_Error('promo_not_found', __('Promotion not found.', 'travel-agency-platform'));
        }
        $months = min(24, max(1, (int) $months ?: (int) $promo->months));

        $keys = self::listing_meta_keys($promo->listing_id);
        if (!$keys) {
            return new WP_Error('listing_invalid', __('The listing is not available for promotion.', 'travel-agency-platform'));
        }

        $base = new DateTime('today');
        $existing_until = (string) get_post_meta($promo->listing_id, $keys['until_key'], true);
        if ($existing_until) {
            $existing = DateTime::createFromFormat('Y-m-d', $existing_until);
            if ($existing && $existing >= $base) {
                $base = $existing;
            }
        }
        $until = $base->add(new DateInterval('P' . $months . 'M'))->format('Y-m-d');

        $wpdb->update(
            self::promos_table(),
            [
                'status'         => 'active',
                'months'         => $months,
                'amount'         => round(self::get_price() * $months, 2),
                'paid_until'     => $until,
                'payment_status' => 'paid',
                'payment_method' => $promo->payment_method ?: 'manual',
                'notes'          => sprintf(__('Confirmada el %s por el administrador', 'travel-agency-platform'), current_time('Y-m-d')),
            ],
            ['id' => (int) $promo_id]
        );

        update_post_meta($promo->listing_id, $keys['flag_key'], '1');
        update_post_meta($promo->listing_id, $keys['until_key'], $until);

        do_action('tap_promo_active', (int) $promo->agency_id, (int) $promo->listing_id, $until, (float) $promo->amount);
        return $until;
    }

    public static function expire($promo_id) {
        global $wpdb;
        $promo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::promos_table() . " WHERE id = %d",
            (int) $promo_id
        ));
        if (!$promo) {
            return false;
        }
        $wpdb->update(self::promos_table(), ['status' => 'expired'], ['id' => (int) $promo_id]);
        self::unfeature_listing($promo->listing_id);
        return true;
    }

    public static function expire_active() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, listing_id FROM " . self::promos_table() . " WHERE status = 'active' AND paid_until < CURDATE()"
        );
        foreach ($rows as $row) {
            self::expire((int) $row->id);
        }
    }

    public static function maybe_expire() {
        $key = 'tap_promos_expiry_check';
        if (get_transient($key)) {
            return;
        }
        self::expire_active();
        set_transient($key, 1, 12 * HOUR_IN_SECONDS);
    }

    public static function unfeature_listing($listing_id) {
        $keys = self::listing_meta_keys($listing_id);
        if (!$keys) {
            return;
        }
        $has_active = self::listing_has_other_active_promo($listing_id);
        if (!$has_active) {
            delete_post_meta($listing_id, $keys['flag_key']);
            delete_post_meta($listing_id, $keys['until_key']);
        }
    }

    private static function listing_has_other_active_promo($listing_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::promos_table() . " WHERE listing_id = %d AND status = 'active'",
            (int) $listing_id
        ));
    }
}