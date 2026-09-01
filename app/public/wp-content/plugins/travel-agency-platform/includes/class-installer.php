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
                rating decimal(2,1) NOT NULL,
                title varchar(255) DEFAULT NULL,
                content text,
                is_approved tinyint(1) DEFAULT 0,
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
                created_by bigint(20) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY agency_id (agency_id)
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

        $cols_bookings = $wpdb->get_col("DESCRIBE {$wpdb->prefix}tap_bookings");
        if (!in_array('guest_name', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN guest_name varchar(100) DEFAULT NULL AFTER notes");
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
        if (!in_array('booking_fee', $cols_bookings)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}tap_bookings ADD COLUMN booking_fee decimal(15,2) DEFAULT 0.00 AFTER total_amount");
        }
    }
}
