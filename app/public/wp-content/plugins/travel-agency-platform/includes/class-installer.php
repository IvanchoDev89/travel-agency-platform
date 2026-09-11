<?php
defined('ABSPATH') || exit;

class TAP_Installer {
    public static function activate() {
        TAP_Post_Types::register();
        TAP_Taxonomies::register();
        TAP_Roles::setup();
        flush_rewrite_rules();

        add_option('tap_version', TAP_VERSION);
        self::create_tables();
    }

    public static function deactivate() {
        TAP_Roles::remove();
        wp_clear_scheduled_hook('tap_maintenance_hook');
        wp_clear_scheduled_hook('tap_auto_hook');
        flush_rewrite_rules();
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $tables = [
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_bookings (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                booking_code varchar(20) NOT NULL,
                agency_id bigint(20) NOT NULL,
                client_id bigint(20) NOT NULL,
                service_type varchar(50) NOT NULL,
                service_id bigint(20) NOT NULL,
                room_id bigint(20) DEFAULT NULL,
                package_id bigint(20) DEFAULT NULL,
                check_in date DEFAULT NULL,
                check_out date DEFAULT NULL,
                adults int(11) DEFAULT 1,
                children int(11) DEFAULT 0,
                nights int(11) DEFAULT 0,
                bed_config text DEFAULT NULL,
                total_amount decimal(15,2) NOT NULL,
                commission_amount decimal(15,2) DEFAULT 0.00,
                commission_percent decimal(5,2) DEFAULT 0.00,
                status varchar(30) DEFAULT 'pending',
                payment_status varchar(30) DEFAULT 'pending',
                payment_method varchar(50) DEFAULT NULL,
                notes text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY booking_code (booking_code),
                KEY agency_id (agency_id),
                KEY client_id (client_id),
                KEY service_type (service_type),
                KEY room_id (room_id),
                KEY status (status)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_booking_items (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                booking_id bigint(20) NOT NULL,
                service_type varchar(50) NOT NULL,
                service_id bigint(20) NOT NULL,
                quantity int(11) DEFAULT 1,
                unit_price decimal(15,2) NOT NULL,
                subtotal decimal(15,2) NOT NULL,
                date_from date DEFAULT NULL,
                date_to date DEFAULT NULL,
                time_from time DEFAULT NULL,
                time_to time DEFAULT NULL,
                PRIMARY KEY (id),
                KEY booking_id (booking_id)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_agencies (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                name varchar(255) NOT NULL,
                slug varchar(255) NOT NULL,
                description text,
                logo_id bigint(20) DEFAULT NULL,
                email varchar(255) DEFAULT NULL,
                phone varchar(50) DEFAULT NULL,
                whatsapp varchar(50) DEFAULT NULL,
                website varchar(255) DEFAULT NULL,
                address text,
                city varchar(100) DEFAULT NULL,
                country varchar(100) DEFAULT NULL,
                commission_percent decimal(5,2) DEFAULT 10.00,
                is_verified tinyint(1) DEFAULT 0,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY slug (slug)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_reviews (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                service_type varchar(50) NOT NULL,
                service_id bigint(20) NOT NULL,
                user_id bigint(20) NOT NULL,
                booking_id bigint(20) DEFAULT NULL,
                is_verified tinyint(1) DEFAULT 0,
                rating decimal(2,1) NOT NULL,
                title varchar(255) DEFAULT NULL,
                content text,
                is_approved tinyint(1) DEFAULT 0,
                mod_status varchar(20) DEFAULT 'ok',
                mod_reason varchar(50) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY service_type_service_id (service_type, service_id),
                KEY user_id (user_id)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_availability (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                service_type varchar(50) NOT NULL,
                service_id bigint(20) NOT NULL,
                date date NOT NULL,
                available int(11) DEFAULT 1,
                price_modifier decimal(15,2) DEFAULT NULL,
                is_blocked tinyint(1) DEFAULT 0,
                PRIMARY KEY (id),
                KEY service_type_service_id (service_type, service_id),
                KEY date (date)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_daily_pricing (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                room_id bigint(20) NOT NULL,
                date date NOT NULL,
                price decimal(15,2) DEFAULT NULL,
                min_stay int(11) DEFAULT NULL,
                is_blocked tinyint(1) DEFAULT 0,
                label varchar(100) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY room_date (room_id, date),
                KEY room_id (room_id),
                KEY date (date)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_chat_events (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                intent varchar(40) NOT NULL,
                lang varchar(10) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY intent (intent),
                KEY created_at (created_at)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_leads (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agency_id bigint(20) NOT NULL,
                service_id bigint(20) DEFAULT NULL,
                name varchar(150) NOT NULL,
                email varchar(150) NOT NULL,
                phone varchar(50) DEFAULT NULL,
                message text,
                ip varchar(45) DEFAULT NULL,
                source varchar(30) DEFAULT 'agency',
                mod_status varchar(20) DEFAULT 'ok',
                mod_reason varchar(50) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id),
                KEY email (email),
                KEY created_at (created_at)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_consents (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL DEFAULT 0,
                email varchar(150) NOT NULL,
                scope varchar(40) NOT NULL,
                policy_version varchar(20) DEFAULT NULL,
                ip varchar(45) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email (email),
                KEY scope (scope),
                KEY created_at (created_at)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tap_privacy_requests (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL DEFAULT 0,
                email varchar(150) NOT NULL,
                name varchar(150) DEFAULT NULL,
                rights varchar(100) NOT NULL,
                details text,
                status varchar(20) DEFAULT 'pending',
                ip varchar(45) DEFAULT NULL,
                handled_by bigint(20) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                handled_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY email (email),
                KEY status (status)
            ) $charset;",
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        self::migrate();
    }

    private static function migrate() {
        global $wpdb;

        $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_bookings");
        if (!in_array('room_id', $columns)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN room_id bigint(20) DEFAULT NULL AFTER service_id");
        }
        if (!in_array('nights', $columns)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN nights int(11) DEFAULT 0 AFTER children");
        }
        if (!in_array('bed_config', $columns)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN bed_config text DEFAULT NULL AFTER nights");
        }

        $columns_items = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_booking_items");
        if (!in_array('room_id', $columns_items)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_booking_items ADD COLUMN room_id bigint(20) DEFAULT NULL AFTER service_id");
        }

        $cols_bookings = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_bookings");
        if (!in_array('commission_status', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN commission_status varchar(20) DEFAULT 'owed' AFTER commission_percent");
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tap_commission_payments'") !== "{$wpdb->prefix}tap_commission_payments") {
            $wpdb->query("CREATE TABLE {$wpdb->prefix}tap_commission_payments (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agency_id bigint(20) NOT NULL,
                amount decimal(15,2) NOT NULL,
                booking_ids text,
                method varchar(50) DEFAULT 'bank_transfer',
                note text,
                source varchar(10) DEFAULT 'admin',
                created_by bigint(20) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id)
            ) {$wpdb->get_charset_collate()}");
        }

        $cols_payouts = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_commission_payments");
        if (!in_array('status', $cols_payouts)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_commission_payments ADD COLUMN status varchar(20) DEFAULT 'pending' AFTER method");
        }
        if (!in_array('paid_at', $cols_payouts)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_commission_payments ADD COLUMN paid_at datetime DEFAULT NULL AFTER status");
        }
        if (!in_array('source', $cols_payouts)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_commission_payments ADD COLUMN source varchar(10) DEFAULT 'admin' AFTER note");
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tap_disputes'") !== "{$wpdb->prefix}tap_disputes") {
            $wpdb->query("CREATE TABLE {$wpdb->prefix}tap_disputes (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                booking_id bigint(20) NOT NULL,
                agency_id bigint(20) NOT NULL,
                client_id bigint(20) NOT NULL,
                guest_email varchar(190) DEFAULT NULL,
                reason varchar(50) NOT NULL,
                details text,
                status varchar(30) DEFAULT 'open',
                resolution_note text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                resolved_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY booking_id (booking_id),
                KEY agency_id (agency_id),
                KEY status (status)
            ) {$wpdb->get_charset_collate()}");
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tap_chat_events'") !== "{$wpdb->prefix}tap_chat_events") {
            $wpdb->query("CREATE TABLE {$wpdb->prefix}tap_chat_events (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                intent varchar(40) NOT NULL,
                lang varchar(10) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY intent (intent),
                KEY created_at (created_at)
            ) {$wpdb->get_charset_collate()}");
        }

        $cols_reviews = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_reviews");
        if (!in_array('reply', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN reply text DEFAULT NULL AFTER content");
        }
        if (!in_array('reply_author', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN reply_author varchar(100) DEFAULT NULL AFTER reply");
        }
        if (!in_array('reply_at', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN reply_at datetime DEFAULT NULL AFTER reply_author");
        }
        if (!in_array('mod_status', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN mod_status varchar(20) DEFAULT 'ok' AFTER is_approved");
        }
        if (!in_array('mod_reason', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN mod_reason varchar(50) DEFAULT NULL AFTER mod_status");
        }
        if (!in_array('is_verified', $cols_reviews)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_reviews ADD COLUMN is_verified tinyint(1) DEFAULT 0 AFTER booking_id");
        }

        $cols_leads = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_leads");
        if (!in_array('mod_status', $cols_leads)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_leads ADD COLUMN mod_status varchar(20) DEFAULT 'ok' AFTER source");
        }
        if (!in_array('mod_reason', $cols_leads)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_leads ADD COLUMN mod_reason varchar(50) DEFAULT NULL AFTER mod_status");
        }

$cols_bookings = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_bookings");
        if (!in_array('booking_fee', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN booking_fee decimal(15,2) DEFAULT 0.00 AFTER total_amount");
        }

        $plans_table = $wpdb->prefix . 'tap_plans';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$plans_table}'") !== $plans_table) {
            $wpdb->query("CREATE TABLE {$plans_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                slug varchar(50) NOT NULL,
                price_monthly decimal(10,2) NOT NULL DEFAULT 0,
                commission_rate decimal(5,2) DEFAULT NULL,
                listing_limit int(11) DEFAULT 3,
                featured_slots int(11) DEFAULT 0,
                features text,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) {$wpdb->get_charset_collate()}");
        }

        $subs_table = $wpdb->prefix . 'tap_agency_subscriptions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$subs_table}'") !== $subs_table) {
            $wpdb->query("CREATE TABLE {$subs_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agency_id bigint(20) NOT NULL,
                plan_id bigint(20) NOT NULL,
                status varchar(20) DEFAULT 'pending',
                paid_until date DEFAULT NULL,
                payment_status varchar(20) DEFAULT 'pending',
                payment_method varchar(20) DEFAULT 'manual',
                notes text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id)
            ) {$wpdb->get_charset_collate()}");
        }

        $promos_table = $wpdb->prefix . 'tap_promos';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$promos_table}'") !== $promos_table) {
            $wpdb->query("CREATE TABLE {$promos_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agency_id bigint(20) NOT NULL,
                listing_id bigint(20) NOT NULL,
                months int(11) NOT NULL DEFAULT 1,
                amount decimal(10,2) NOT NULL DEFAULT 0,
                status varchar(20) DEFAULT 'pending',
                paid_until date DEFAULT NULL,
                payment_status varchar(20) DEFAULT 'pending',
                payment_method varchar(20) DEFAULT 'manual',
                notes text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id),
                KEY listing_id (listing_id)
            ) {$wpdb->get_charset_collate()}");
        }

        $views_table = $wpdb->prefix . 'tap_listing_views';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$views_table}'") !== $views_table) {
            $wpdb->query("CREATE TABLE {$views_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                listing_id bigint(20) NOT NULL,
                service_type varchar(50) NOT NULL,
                view_date date NOT NULL,
                views bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY listing_date (listing_id, view_date),
                KEY view_date (view_date)
            ) {$wpdb->get_charset_collate()}");
        }

        $payment_orders = $wpdb->prefix . 'tap_payment_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$payment_orders}'") !== $payment_orders) {
            $wpdb->query("CREATE TABLE {$payment_orders} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                paypal_order_id varchar(64) NOT NULL,
                object_type varchar(30) NOT NULL,
                object_id bigint(20) NOT NULL,
                amount decimal(10,2) NOT NULL DEFAULT 0,
                status varchar(20) DEFAULT 'created',
                capture_id varchar(64) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY paypal_order (paypal_order_id),
                KEY object (object_type, object_id)
            ) {$wpdb->get_charset_collate()}");
        }

        add_option('tap_featured_price', 5.00);

        $leads_table = $wpdb->prefix . 'tap_leads';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$leads_table}'") !== $leads_table) {
            $wpdb->query("CREATE TABLE {$leads_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agency_id bigint(20) NOT NULL,
                service_id bigint(20) DEFAULT NULL,
                name varchar(150) NOT NULL,
                email varchar(150) NOT NULL,
                phone varchar(50) DEFAULT NULL,
                message text,
                ip varchar(45) DEFAULT NULL,
                source varchar(30) DEFAULT 'agency',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id),
                KEY email (email),
                KEY created_at (created_at)
            ) {$wpdb->get_charset_collate()}");
        }

        $plan_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$plans_table}");
        if (0 === $plan_count) {
            $wpdb->insert($plans_table, [
                'name' => 'Gratis', 'slug' => 'free', 'price_monthly' => 0,
                'commission_rate' => null, 'listing_limit' => 3, 'featured_slots' => 0,
                'features' => wp_json_encode(['3 listados', 'Comisión estándar', 'Sin destacados']),
                'is_active' => 1,
            ]);
            $wpdb->insert($plans_table, [
                'name' => 'Básico', 'slug' => 'basic', 'price_monthly' => 9,
                'commission_rate' => 8, 'listing_limit' => 10, 'featured_slots' => 1,
                'features' => wp_json_encode(['10 listados', 'Comisión 8%', '1 destacado / mes']),
                'is_active' => 1,
            ]);
            $wpdb->insert($plans_table, [
                'name' => 'Pro', 'slug' => 'pro', 'price_monthly' => 19,
                'commission_rate' => 5, 'listing_limit' => -1, 'featured_slots' => 3,
                'features' => wp_json_encode(['Listados ilimitados', 'Comisión 5%', '3 destacados / mes', 'Soporte prioritario']),
                'is_active' => 1,
            ]);
        }
        if (!in_array('guest_email', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN guest_email varchar(150) DEFAULT NULL AFTER guest_name");
        }
        if (!in_array('guest_phone', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN guest_phone varchar(50) DEFAULT NULL AFTER guest_email");
        }
        if (!in_array('cancel_requested_at', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN cancel_requested_at datetime DEFAULT NULL AFTER guest_phone");
        }
        if (!in_array('cancelled_by', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN cancelled_by varchar(20) DEFAULT NULL AFTER cancel_requested_at");
        }
        if (!in_array('lead_id', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN lead_id bigint(20) DEFAULT NULL AFTER cancelled_by");
        }
        if (!in_array('refund_amount', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN refund_amount decimal(15,2) DEFAULT 0.00 AFTER lead_id");
        }
        if (!in_array('refund_percent', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN refund_percent decimal(5,2) DEFAULT 0.00 AFTER refund_amount");
        }
        if (!in_array('cancellation_policy', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN cancellation_policy varchar(30) DEFAULT NULL AFTER refund_percent");
        }
        if (!in_array('refunded_at', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN refunded_at datetime DEFAULT NULL AFTER cancellation_policy");
        }

        // Default cancellation policies per service type (1.5.1).
        $policy_defaults = [
            'tap_cancel_policy_tap_accommodation' => 'flexible',
            'tap_cancel_policy_tap_tour'          => 'strict',
            'tap_cancel_policy_tap_transport'     => 'moderate',
            'tap_cancel_policy_tap_car_rental'    => 'moderate',
            'tap_cancel_policy_tap_boat'          => 'strict',
            'tap_cancel_policy_tap_package'       => 'strict',
            'tap_cancel_policy_tap_equipment'     => 'flexible',
        ];
        foreach ($policy_defaults as $opt => $value) {
            if (!get_option($opt, false)) {
                add_option($opt, $value);
            }
        }
        if (!get_option('tap_booking_auto_complete', false)) {
            add_option('tap_booking_auto_complete', '1');
        }

        if (class_exists('TAP_Privacy')) {
            TAP_Privacy::ensure_privacy_page();
        }
    }
}
