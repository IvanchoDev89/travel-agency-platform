<?php
defined('ABSPATH') || exit;

class TAP_Metaboxes {
    private static $fields   = [];
    private static $loaded   = false;

    public static function register() {
        self::register_agency_metabox();
        self::register_accommodation_metabox();
        self::register_room_metabox();
        self::register_tour_metabox();
        self::register_transport_metabox();
        self::register_car_rental_metabox();
        self::register_boat_metabox();
        self::register_package_metabox();
        self::register_booking_metabox();
        self::register_equipment_metabox();
        self::register_destination_metabox();
        self::$loaded = true;
    }

    private static function service_types() {
        return ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package', 'tap_equipment'];
    }

    /**
     * register()/get_fields() are also reached from the front-end back-office,
     * where add_meta_box() (wp-admin/includes/template.php) is not loaded.
     * The field registry must populate everywhere; the meta box UI is admin-only.
     */
    private static function maybe_add_meta_box($id, $title, $callback, $screen, $context = 'advanced', $priority = 'default', $callback_args = null) {
        if (function_exists('add_meta_box') && is_admin()) {
            add_meta_box($id, $title, $callback, $screen, $context, $priority, $callback_args);
        }
    }

    private static function register_destination_metabox() {
        foreach (self::service_types() as $type) {
            self::maybe_add_meta_box('tap_destination', __('Destination', 'travel-agency-platform'), [__CLASS__, 'render_destination'], $type, 'side', 'default');
        }
    }

    public static function render_destination($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $selected = 0;
        $highest  = -1;
        $terms    = get_the_terms($post->ID, 'tap_location');
        if (is_array($terms) && $terms) {
            foreach ($terms as $term) {
                $level = TAP_Destinations::term_level($term->term_id);
                $level = $level !== null ? $level : TAP_Destinations::term_depth($term->term_id);
                if ($level > $highest) {
                    $highest  = $level;
                    $selected = (int) $term->term_id;
                }
            }
        }
        echo '<p class="description">' . esc_html__('Destination hierarchy: país → provincia → cantón → distrito → lugar.', 'travel-agency-platform') . '</p>';
        echo TAP_Destinations::render_picker($selected, 'tap_destination');
    }

    public static function get_fields($post_type) {
        if (!self::$loaded) {
            self::register();
        }
        return isset(self::$fields[$post_type]) ? self::$fields[$post_type] : [];
    }

    private static function register_agency_metabox() {
        self::maybe_add_meta_box('tap_agency_details', __('Agency Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_agency', 'normal', 'high');
        self::$fields['tap_agency'] = [
            '_tap_agency_email'     => ['type' => 'email', 'label' => __('Email', 'travel-agency-platform')],
            '_tap_agency_phone'     => ['type' => 'text', 'label' => __('Phone', 'travel-agency-platform')],
            '_tap_agency_whatsapp'  => ['type' => 'text', 'label' => __('WhatsApp', 'travel-agency-platform')],
            '_tap_agency_website'   => ['type' => 'url', 'label' => __('Website', 'travel-agency-platform')],
            '_tap_agency_address'   => ['type' => 'textarea', 'label' => __('Address', 'travel-agency-platform')],
            '_tap_agency_city'      => ['type' => 'text', 'label' => __('City', 'travel-agency-platform')],
            '_tap_agency_country'   => ['type' => 'text', 'label' => __('Country', 'travel-agency-platform')],
            '_tap_agency_commission'=> ['type' => 'number', 'label' => __('Commission %', 'travel-agency-platform'), 'step' => '0.01', 'default' => '10'],
            '_tap_agency_verified'  => ['type' => 'checkbox', 'label' => __('Verified Agency', 'travel-agency-platform')],
            '_tap_agency_user_id'   => ['type' => 'number', 'label' => __('WordPress User ID', 'travel-agency-platform')],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_accommodation_metabox() {
        self::maybe_add_meta_box('tap_accommodation_details', __('Accommodation Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_location', __('Location & Address', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_location'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_policies', __('Policies & Rules', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_policies'], 'tap_accommodation', 'normal', 'high');
        self::maybe_add_meta_box('tap_accommodation_gallery', __('Photo Gallery', 'travel-agency-platform'), [__CLASS__, 'render_accommodation_gallery'], 'tap_accommodation', 'side', 'low');

        self::$fields['tap_accommodation'] = [
            '_tap_acc_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_acc_type'         => ['type' => 'select', 'label' => __('Type', 'travel-agency-platform'), 'options' => ['hotel' => 'Hotel', 'hostel' => 'Hostel', 'resort' => 'Resort', 'villa' => 'Villa', 'cabin' => 'Cabin', 'apartment' => 'Apartment', 'boutique' => 'Boutique Hotel', 'eco' => 'Eco-Lodge']],
            '_tap_acc_stars'        => ['type' => 'select', 'label' => __('Stars', 'travel-agency-platform'), 'options' => ['1' => '1 Star', '2' => '2 Stars', '3' => '3 Stars', '4' => '4 Stars', '5' => '5 Stars']],
            '_tap_acc_price_per_night' => ['type' => 'number', 'label' => __('Price per Night ($) — fallback if no room types', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_acc_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_acc_capacity'     => ['type' => 'number', 'label' => __('Max Guests', 'travel-agency-platform')],
            '_tap_acc_rooms'        => ['type' => 'number', 'label' => __('Number of Rooms', 'travel-agency-platform')],
            '_tap_acc_bathrooms'    => ['type' => 'number', 'label' => __('Bathrooms', 'travel-agency-platform')],
            '_tap_acc_bedrooms'     => ['type' => 'number', 'label' => __('Bedrooms', 'travel-agency-platform')],
            '_tap_acc_checkin_time' => ['type' => 'time', 'label' => __('Default Check-in Time', 'travel-agency-platform')],
            '_tap_acc_checkout_time'=> ['type' => 'time', 'label' => __('Default Check-out Time', 'travel-agency-platform')],
            '_tap_acc_lat'          => ['type' => 'text', 'label' => __('Latitude', 'travel-agency-platform')],
            '_tap_acc_lng'          => ['type' => 'text', 'label' => __('Longitude', 'travel-agency-platform')],
            '_tap_acc_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_tour_metabox() {
        self::maybe_add_meta_box('tap_tour_details', __('Tour Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_tour', 'normal', 'high');
        self::$fields['tap_tour'] = [
            '_tap_tour_agency_id'   => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_tour_duration'    => ['type' => 'text', 'label' => __('Duration', 'travel-agency-platform'), 'placeholder' => 'e.g. 3 days / 2 nights'],
            '_tap_tour_difficulty'  => ['type' => 'select', 'label' => __('Difficulty', 'travel-agency-platform'), 'options' => ['easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard', 'extreme' => 'Extreme']],
            '_tap_tour_price_adult' => ['type' => 'number', 'label' => __('Price per Adult ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_price_child' => ['type' => 'number', 'label' => __('Price per Child ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_tour_currency'    => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_tour_max_people'  => ['type' => 'number', 'label' => __('Max People per Group', 'travel-agency-platform')],
            '_tap_tour_min_age'     => ['type' => 'number', 'label' => __('Minimum Age', 'travel-agency-platform')],
            '_tap_tour_start_time'  => ['type' => 'time', 'label' => __('Start Time', 'travel-agency-platform')],
            '_tap_tour_end_time'    => ['type' => 'time', 'label' => __('End Time', 'travel-agency-platform')],
            '_tap_tour_meeting_point' => ['type' => 'text', 'label' => __('Meeting Point', 'travel-agency-platform')],
            '_tap_tour_includes'    => ['type' => 'textarea', 'label' => __('What\'s Included', 'travel-agency-platform')],
            '_tap_tour_excludes'    => ['type' => 'textarea', 'label' => __('What\'s Not Included', 'travel-agency-platform')],
            '_tap_tour_what_bring'  => ['type' => 'textarea', 'label' => __('What to Bring', 'travel-agency-platform')],
            '_tap_tour_lat'         => ['type' => 'text', 'label' => __('Latitude', 'travel-agency-platform')],
            '_tap_tour_lng'         => ['type' => 'text', 'label' => __('Longitude', 'travel-agency-platform')],
            '_tap_tour_is_active'   => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_tour_capacity'    => ['type' => 'number', 'label' => __('Cupos por fecha (capacidad)', 'travel-agency-platform')],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_equipment_metabox() {
        self::maybe_add_meta_box('tap_equipment_details', __('Equipment Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_equipment', 'normal', 'high');
        self::$fields['tap_equipment'] = [
            '_tap_eq_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_eq_type'         => ['type' => 'select', 'label' => __('Category', 'travel-agency-platform'), 'options' => ['hiking' => 'Senderismo', 'camping' => 'Camping', 'kayak' => 'Kayak', 'snorkel' => 'Snorkel', 'surf' => 'Surf', 'bicicleta' => 'Bicicleta', 'acuatico' => 'Deportes acuáticos', 'fotografia' => 'Fotografía', 'bebe' => 'Equipo para bebés', 'playa' => 'Playa', 'cocina' => 'Cocina / Parrilla', 'otros' => 'Otros']],
            '_tap_eq_price_day'    => ['type' => 'number', 'label' => __('Price per Day ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_price_hour'   => ['type' => 'number', 'label' => __('Price per Hour ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_deposit'      => ['type' => 'number', 'label' => __('Security Deposit ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_eq_quantity'     => ['type' => 'number', 'label' => __('Units Available', 'travel-agency-platform')],
            '_tap_eq_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_eq_pickup'       => ['type' => 'text', 'label' => __('Pick-up / Delivery Location', 'travel-agency-platform')],
            '_tap_eq_conditions'   => ['type' => 'textarea', 'label' => __('Rental Conditions', 'travel-agency-platform')],
            '_tap_eq_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'       => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description' => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_transport_metabox() {
        self::maybe_add_meta_box('tap_transport_details', __('Transport Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_transport', 'normal', 'high');
        self::$fields['tap_transport'] = [
            '_tap_trans_agency_id'  => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_trans_type'       => ['type' => 'select', 'label' => __('Service Type', 'travel-agency-platform'), 'options' => ['private' => 'Private', 'shared' => 'Shared', 'shuttle' => 'Shuttle', 'luxury' => 'Luxury']],
            '_tap_trans_vehicle'    => ['type' => 'text', 'label' => __('Vehicle Type', 'travel-agency-platform')],
            '_tap_trans_capacity'   => ['type' => 'number', 'label' => __('Passenger Capacity', 'travel-agency-platform')],
            '_tap_trans_price'      => ['type' => 'number', 'label' => __('Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_trans_price_type' => ['type' => 'select', 'label' => __('Price Type', 'travel-agency-platform'), 'options' => ['per_person' => 'Per Person', 'per_vehicle' => 'Per Vehicle', 'per_trip' => 'Per Trip']],
            '_tap_trans_currency'   => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_trans_from'       => ['type' => 'text', 'label' => __('Pick-up Location', 'travel-agency-platform')],
            '_tap_trans_to'         => ['type' => 'text', 'label' => __('Drop-off Location', 'travel-agency-platform')],
            '_tap_trans_duration'   => ['type' => 'text', 'label' => __('Trip Duration', 'travel-agency-platform')],
            '_tap_trans_has_wifi'   => ['type' => 'checkbox', 'label' => __('WiFi on Board', 'travel-agency-platform')],
            '_tap_trans_has_ac'     => ['type' => 'checkbox', 'label' => __('Air Conditioning', 'travel-agency-platform')],
            '_tap_trans_is_active'  => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_car_rental_metabox() {
        self::maybe_add_meta_box('tap_car_rental_details', __('Car Rental Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_car_rental', 'normal', 'high');
        self::$fields['tap_car_rental'] = [
            '_tap_car_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_car_brand'        => ['type' => 'text', 'label' => __('Brand', 'travel-agency-platform')],
            '_tap_car_model'        => ['type' => 'text', 'label' => __('Model', 'travel-agency-platform')],
            '_tap_car_year'         => ['type' => 'number', 'label' => __('Year', 'travel-agency-platform')],
            '_tap_car_type'         => ['type' => 'select', 'label' => __('Car Type', 'travel-agency-platform'), 'options' => ['economy' => 'Economy', 'compact' => 'Compact', 'midsize' => 'Midsize', 'suv' => 'SUV', 'luxury' => 'Luxury', 'van' => 'Van', 'pickup' => 'Pickup']],
            '_tap_car_transmission' => ['type' => 'select', 'label' => __('Transmission', 'travel-agency-platform'), 'options' => ['manual' => 'Manual', 'automatic' => 'Automatic']],
            '_tap_car_seats'        => ['type' => 'number', 'label' => __('Seats', 'travel-agency-platform')],
            '_tap_car_doors'        => ['type' => 'number', 'label' => __('Doors', 'travel-agency-platform')],
            '_tap_car_price_per_day'=> ['type' => 'number', 'label' => __('Price per Day ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_car_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_car_location'     => ['type' => 'text', 'label' => __('Pick-up Location', 'travel-agency-platform')],
            '_tap_car_has_gps'      => ['type' => 'checkbox', 'label' => __('GPS Included', 'travel-agency-platform')],
            '_tap_car_has_insurance'=> ['type' => 'checkbox', 'label' => __('Insurance Included', 'travel-agency-platform')],
            '_tap_car_has_ac'       => ['type' => 'checkbox', 'label' => __('Air Conditioning', 'travel-agency-platform')],
            '_tap_car_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_boat_metabox() {
        self::maybe_add_meta_box('tap_boat_details', __('Boat Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_boat', 'normal', 'high');
        self::$fields['tap_boat'] = [
            '_tap_boat_agency_id'   => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_boat_type'        => ['type' => 'select', 'label' => __('Boat Type', 'travel-agency-platform'), 'options' => ['yacht' => 'Yacht', 'catamaran' => 'Catamaran', 'speedboat' => 'Speedboat', 'fishing' => 'Fishing Boat', 'sailboat' => 'Sailboat', 'kayak' => 'Kayak', 'pontoon' => 'Pontoon']],
            '_tap_boat_capacity'    => ['type' => 'number', 'label' => __('Max Passengers', 'travel-agency-platform')],
            '_tap_boat_length'      => ['type' => 'text', 'label' => __('Length', 'travel-agency-platform')],
            '_tap_boat_price_half'  => ['type' => 'number', 'label' => __('Half Day Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_price_full'  => ['type' => 'number', 'label' => __('Full Day Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_price_hourly'=> ['type' => 'number', 'label' => __('Hourly Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_boat_currency'    => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_boat_departure'   => ['type' => 'text', 'label' => __('Departure Point', 'travel-agency-platform')],
            '_tap_boat_destination' => ['type' => 'text', 'label' => __('Destination', 'travel-agency-platform')],
            '_tap_boat_has_captain' => ['type' => 'checkbox', 'label' => __('Captain Included', 'travel-agency-platform')],
            '_tap_boat_has_crew'    => ['type' => 'checkbox', 'label' => __('Crew Included', 'travel-agency-platform')],
            '_tap_boat_is_active'   => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_package_metabox() {
        self::maybe_add_meta_box('tap_package_details', __('Package Details', 'travel-agency-platform'), [__CLASS__, 'render'], 'tap_package', 'normal', 'high');
        self::$fields['tap_package'] = [
            '_tap_pkg_agency_id'    => ['type' => 'select_post', 'label' => __('Agency', 'travel-agency-platform'), 'post_type' => 'tap_agency'],
            '_tap_pkg_duration'     => ['type' => 'text', 'label' => __('Duration', 'travel-agency-platform'), 'placeholder' => 'e.g. 5 days / 4 nights'],
            '_tap_pkg_price'        => ['type' => 'number', 'label' => __('Total Price ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_pkg_price_per_person' => ['type' => 'number', 'label' => __('Price per Person ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_pkg_currency'     => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_pkg_max_people'   => ['type' => 'number', 'label' => __('Max People', 'travel-agency-platform')],
            '_tap_pkg_is_active'    => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
            '_tap_seo_title'        => ['type' => 'text', 'label' => __('SEO Title', 'travel-agency-platform'), 'placeholder' => __('Falls back to post title', 'travel-agency-platform')],
            '_tap_seo_description'  => ['type' => 'textarea', 'label' => __('Meta Description (SEO)', 'travel-agency-platform')],
        ];
    }

    private static function register_booking_metabox() {
        self::maybe_add_meta_box('tap_booking_details', __('Booking Details', 'travel-agency-platform'), [__CLASS__, 'render_booking'], 'tap_booking', 'normal', 'high');
    }

    private static function register_room_metabox() {
        self::maybe_add_meta_box('tap_room_details', __('Room Details', 'travel-agency-platform'), [__CLASS__, 'render_room'], 'tap_room', 'normal', 'high');
        self::maybe_add_meta_box('tap_room_beds', __('Bed Configuration', 'travel-agency-platform'), [__CLASS__, 'render_room_beds'], 'tap_room', 'normal', 'high');
        self::maybe_add_meta_box('tap_room_gallery', __('Room Photos', 'travel-agency-platform'), [__CLASS__, 'render_room_gallery'], 'tap_room', 'side', 'low');
    }

    public static function render($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $post_type = $post->post_type;

        if (!isset(self::$fields[$post_type])) {
            echo '<p>' . __('No configuration fields found.', 'travel-agency-platform') . '</p>';
            return;
        }

        echo '<table class="form-table">';
        foreach (self::$fields[$post_type] as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);
            if (empty($value) && isset($field['default'])) {
                $value = $field['default'];
            }
            echo '<tr>';
            echo '<th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th>';
            echo '<td>';
            self::render_field($key, $field, $value);
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private static function render_field($key, $field, $value) {
        switch ($field['type']) {
            case 'text':
            case 'email':
            case 'url':
            case 'number':
            case 'time':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" %s %s>',
                    esc_attr($field['type']),
                    esc_attr($key),
                    esc_attr($key),
                    esc_attr($value),
                    isset($field['step']) ? 'step="' . esc_attr($field['step']) . '"' : '',
                    isset($field['placeholder']) ? 'placeholder="' . esc_attr($field['placeholder']) . '"' : ''
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
                    esc_attr($key),
                    esc_attr($key),
                    esc_textarea($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($key),
                    esc_attr($key),
                    checked('1', $value, false)
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($key), esc_attr($key));
                foreach ($field['options'] as $opt_val => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_val),
                        selected($opt_val, $value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;

            case 'select_post':
                $posts = get_posts(['post_type' => $field['post_type'], 'posts_per_page' => -1, 'post_status' => 'publish']);
                printf('<select id="%s" name="%s"><option value="">-- Select --</option>', esc_attr($key), esc_attr($key));
                foreach ($posts as $p) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($p->ID),
                        selected($p->ID, $value, false),
                        esc_html($p->post_title)
                    );
                }
                echo '</select>';
                break;
        }
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['tap_metabox_nonce']) || !wp_verify_nonce($_POST['tap_metabox_nonce'], 'tap_metabox_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $post_type = $post->post_type;

        if (isset(self::$fields[$post_type])) {
            foreach (self::$fields[$post_type] as $key => $field) {
                if ($field['type'] === 'checkbox') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } elseif (isset($_POST[$key])) {
                    $value = sanitize_text_field($_POST[$key]);
                } else {
                    continue;
                }
                update_post_meta($post_id, $key, $value);
            }
        }

        if ($post_type === 'tap_accommodation') {
            $text_fields = ['_tap_acc_address', '_tap_acc_city', '_tap_acc_state', '_tap_acc_zip', '_tap_acc_country', '_tap_acc_cancellation', '_tap_acc_house_rules', '_tap_acc_gallery'];
            foreach ($text_fields as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
        }

        if ($post_type === 'tap_room') {
            $room_text = ['_tap_room_accommodation_id', '_tap_room_price_per_night', '_tap_room_min_stay', '_tap_room_currency', '_tap_room_max_adults', '_tap_room_max_children', '_tap_room_max_occupancy', '_tap_room_inventory', '_tap_room_size', '_tap_room_view', '_tap_room_floor', '_tap_room_gallery'];
            foreach ($room_text as $key) {
                if (isset($_POST[$key])) {
                    $val = sanitize_text_field($_POST[$key]);
                    if (in_array($key, ['_tap_room_price_per_night', '_tap_room_max_adults', '_tap_room_max_children', '_tap_room_max_occupancy', '_tap_room_inventory', '_tap_room_floor'])) {
                        $val = floatval($val);
                    }
                    update_post_meta($post_id, $key, $val);
                }
            }

            $is_active = isset($_POST['_tap_room_is_active']) ? '1' : '0';
            update_post_meta($post_id, '_tap_room_is_active', $is_active);

            if (isset($_POST['_tap_room_beds'])) {
                update_post_meta($post_id, '_tap_room_beds', wp_unslash($_POST['_tap_room_beds']));
            }
        }

        $service_like = in_array($post_type, self::service_types(), true);
        if ($service_like || $post_type === 'tap_room') {
            if (isset($_POST['tap_destination'])) {
                $dest = absint($_POST['tap_destination']);
                $valid = $dest && term_exists($dest, 'tap_location');
                wp_set_object_terms($post_id, $valid ? [$dest] : [], 'tap_location', false);
            }
        }

        if ($post_type === 'tap_tour' && isset($_POST['_tap_tour_difficulty'])) {
            $difficulty = sanitize_key($_POST['_tap_tour_difficulty']);
            $term = $difficulty && term_exists($difficulty, 'tap_tour_difficulty') ? $difficulty : '';
            if ($term) {
                $tt = term_exists($term, 'tap_tour_difficulty');
                $term_id = (int) (is_array($tt) ? $tt['term_id'] : $tt);
                wp_set_object_terms($post_id, [$term_id], 'tap_tour_difficulty', false);
            } else {
                wp_set_object_terms($post_id, [], 'tap_tour_difficulty', false);
            }
        }
    }

    public static function render_accommodation_location($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $fields = [
            '_tap_acc_address'   => [__('Street Address', 'travel-agency-platform'), 'text'],
            '_tap_acc_city'      => [__('City', 'travel-agency-platform'), 'text'],
            '_tap_acc_state'     => [__('State / Province', 'travel-agency-platform'), 'text'],
            '_tap_acc_zip'       => [__('ZIP / Postal Code', 'travel-agency-platform'), 'text'],
            '_tap_acc_country'   => [__('Country', 'travel-agency-platform'), 'text'],
        ];
        echo '<table class="form-table">';
        foreach ($fields as $key => $data) {
            $val = get_post_meta($post->ID, $key, true);
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($data[0]) . '</label></th><td>';
            echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" class="regular-text" style="width:100%">';
            echo '</td></tr>';
        }
        echo '</table>';
    }

    public static function render_accommodation_policies($post) {
        $cancellation = get_post_meta($post->ID, '_tap_acc_cancellation', true);
        $house_rules = get_post_meta($post->ID, '_tap_acc_house_rules', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="_tap_acc_cancellation"><?php esc_html_e('Cancellation Policy', 'travel-agency-platform'); ?></label></th>
                <td>
                    <select id="_tap_acc_cancellation" name="_tap_acc_cancellation" style="width:100%">
                        <option value="flexible" <?php selected('flexible', $cancellation); ?>><?php esc_html_e('Flexible — Free cancellation 24h before', 'travel-agency-platform'); ?></option>
                        <option value="moderate" <?php selected('moderate', $cancellation); ?>><?php esc_html_e('Moderate — Free cancellation 5 days before', 'travel-agency-platform'); ?></option>
                        <option value="strict" <?php selected('strict', $cancellation); ?>><?php esc_html_e('Strict — 50% refund up to 7 days before', 'travel-agency-platform'); ?></option>
                        <option value="non_refundable" <?php selected('non_refundable', $cancellation); ?>><?php esc_html_e('Non-Refundable', 'travel-agency-platform'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="_tap_acc_house_rules"><?php esc_html_e('House Rules', 'travel-agency-platform'); ?></label></th>
                <td><textarea id="_tap_acc_house_rules" name="_tap_acc_house_rules" rows="4" style="width:100%"><?php echo esc_textarea($house_rules); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    public static function render_accommodation_gallery($post) {
        $gallery = get_post_meta($post->ID, '_tap_acc_gallery', true);
        $ids = $gallery ? explode(',', $gallery) : [];
        ?>
        <div>
            <div id="tap-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <?php foreach ($ids as $id): $img = wp_get_attachment_image_src($id, 'thumbnail'); if ($img): ?>
                    <div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;">
                        <img src="<?php echo esc_url($img[0]); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <button type="button" class="tap-gallery-remove" data-id="<?php echo esc_attr($id); ?>" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button>
                    </div>
                <?php endif; endforeach; ?>
            </div>
            <input type="hidden" name="_tap_acc_gallery" id="_tap_acc_gallery" value="<?php echo esc_attr($gallery); ?>">
            <button type="button" class="button" id="tap-gallery-add"><?php esc_html_e('Add Images', 'travel-agency-platform'); ?></button>
            <p class="description"><?php esc_html_e('Upload or select images for the property gallery.', 'travel-agency-platform'); ?></p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            $('#tap-gallery-add').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '<?php echo esc_js(__('Select Images', 'travel-agency-platform')); ?>',
                    button: { text: '<?php echo esc_js(__('Add to Gallery', 'travel-agency-platform')); ?>' },
                    multiple: true
                });
                frame.on('select', function() {
                    var ids = $('#_tap_acc_gallery').val() ? $('#_tap_acc_gallery').val().split(',') : [];
                    var attachments = frame.state().get('selection').toJSON();
                    attachments.forEach(function(att) {
                        if (ids.indexOf(String(att.id)) === -1) ids.push(String(att.id));
                        var preview = $('#tap-gallery-preview');
                        if (!preview.find('[data-id="' + att.id + '"]').length) {
                            preview.append('<div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;"><img src="' + (att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;"><button type="button" class="tap-gallery-remove" data-id="' + att.id + '" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button></div>');
                        }
                    });
                    $('#_tap_acc_gallery').val(ids.join(','));
                });
                frame.open();
            });
            $(document).on('click', '.tap-gallery-remove', function() {
                var id = $(this).data('id');
                $(this).parent().remove();
                var ids = $('#_tap_acc_gallery').val().split(',').filter(function(v) { return v !== String(id); });
                $('#_tap_acc_gallery').val(ids.join(','));
            });
        });
        </script>
        <?php
    }

    public static function render_room($post) {
        wp_nonce_field('tap_metabox_save', 'tap_metabox_nonce');
        $fields = [
            '_tap_room_accommodation_id' => ['type' => 'select_post', 'label' => __('Parent Accommodation', 'travel-agency-platform'), 'post_type' => 'tap_accommodation'],
            '_tap_room_price_per_night'  => ['type' => 'number', 'label' => __('Precio base por noche ($)', 'travel-agency-platform'), 'step' => '0.01'],
            '_tap_room_min_stay'         => ['type' => 'number', 'label' => __('Estadía mínima base (noches)', 'travel-agency-platform'), 'default' => '1'],
            '_tap_room_currency'         => ['type' => 'text', 'label' => __('Currency', 'travel-agency-platform'), 'default' => 'USD'],
            '_tap_room_max_adults'       => ['type' => 'number', 'label' => __('Max Adults', 'travel-agency-platform')],
            '_tap_room_max_children'     => ['type' => 'number', 'label' => __('Max Children', 'travel-agency-platform')],
            '_tap_room_max_occupancy'    => ['type' => 'number', 'label' => __('Max Total Occupancy', 'travel-agency-platform')],
            '_tap_room_inventory'        => ['type' => 'number', 'label' => __('Number of Rooms of This Type', 'travel-agency-platform'), 'default' => '1'],
            '_tap_room_size'             => ['type' => 'text', 'label' => __('Room Size (e.g. 35 m²)', 'travel-agency-platform')],
            '_tap_room_view'             => ['type' => 'text', 'label' => __('View (e.g. Ocean View, City View)', 'travel-agency-platform')],
            '_tap_room_floor'            => ['type' => 'number', 'label' => __('Floor', 'travel-agency-platform')],
            '_tap_room_is_active'        => ['type' => 'checkbox', 'label' => __('Active', 'travel-agency-platform'), 'default' => '1'],
        ];
        echo '<table class="form-table">';
        foreach ($fields as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value === '' && isset($field['default'])) $value = $field['default'];
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';
            self::render_field($key, $field, $value);
            echo '</td></tr>';
        }
        echo '</table>';
    }

    public static function render_room_beds($post) {
        $beds = get_post_meta($post->ID, '_tap_room_beds', true);
        $beds = $beds ? (is_string($beds) ? json_decode($beds, true) : $beds) : [];
        ?>
        <div id="tap-beds-container">
            <p><strong><?php esc_html_e('Add bed types available in this room:', 'travel-agency-platform'); ?></strong></p>
            <table class="widefat" style="margin-bottom:12px;">
                <thead><tr><th><?php esc_html_e('Bed Type', 'travel-agency-platform'); ?></th><th style="width:80px;"><?php esc_html_e('Count', 'travel-agency-platform'); ?></th><th style="width:40px;"></th></tr></thead>
                <tbody id="tap-beds-tbody">
                    <?php if (empty($beds)): ?>
                    <tr class="tap-bed-row">
                        <td><select name="tap_bed_type[]" style="width:100%;"><?php self::bed_type_options(''); ?></select></td>
                        <td><input type="number" name="tap_bed_count[]" value="1" min="1" style="width:60px;"></td>
                        <td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($beds as $bed): ?>
                        <tr class="tap-bed-row">
                            <td><select name="tap_bed_type[]" style="width:100%;"><?php self::bed_type_options($bed['type'] ?? ''); ?></select></td>
                            <td><input type="number" name="tap_bed_count[]" value="<?php echo esc_attr($bed['count'] ?? 1); ?>" min="1" style="width:60px;"></td>
                            <td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" class="button" id="tap-bed-add"><?php esc_html_e('+ Add Bed Type', 'travel-agency-platform'); ?></button>
            <input type="hidden" name="_tap_room_beds" id="_tap_room_beds" value='<?php echo esc_attr(json_encode($beds)); ?>'>
        </div>
        <script>
        jQuery(document).ready(function($) {
            function rebuildBedsJson() {
                var data = [];
                $('#tap-beds-tbody tr').each(function() {
                    var type = $(this).find('select').val();
                    var count = $(this).find('input[type="number"]').val();
                    if (type) data.push({type: type, count: parseInt(count) || 1});
                });
                $('#_tap_room_beds').val(JSON.stringify(data));
            }
            $('#tap-bed-add').on('click', function() {
                var row = '<tr class="tap-bed-row"><td><select name="tap_bed_type[]" style="width:100%;"><?php echo str_replace("'", "\\'", str_replace("\n", "", self::bed_type_options_html())); ?></select></td><td><input type="number" name="tap_bed_count[]" value="1" min="1" style="width:60px;"></td><td><button type="button" class="button tap-bed-remove" style="background:#dc3545;color:#fff;border:none;cursor:pointer;">&times;</button></td></tr>';
                $('#tap-beds-tbody').append(row);
            });
            $(document).on('click', '.tap-bed-remove', function() { $(this).closest('tr').remove(); rebuildBedsJson(); });
            $(document).on('change', '#tap-beds-tbody select, #tap-beds-tbody input', rebuildBedsJson);
        });
        </script>
        <?php
    }

    private static function bed_type_options($selected) {
        $types = [
            '' => __('-- Select --', 'travel-agency-platform'),
            'king' => __('King', 'travel-agency-platform'),
            'queen' => __('Queen', 'travel-agency-platform'),
            'double' => __('Double / Full', 'travel-agency-platform'),
            'twin' => __('Twin / Single', 'travel-agency-platform'),
            'bunk' => __('Bunk Bed', 'travel-agency-platform'),
            'sofa' => __('Sofa Bed', 'travel-agency-platform'),
            'crib' => __('Crib', 'travel-agency-platform'),
            'murphy' => __('Murphy Bed', 'travel-agency-platform'),
            'futon' => __('Futon', 'travel-agency-platform'),
        ];
        foreach ($types as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($val, $selected, false) . '>' . esc_html($label) . '</option>';
        }
    }

    private static function bed_type_options_html() {
        ob_start();
        self::bed_type_options('');
        return ob_get_clean();
    }

    public static function render_room_gallery($post) {
        $gallery = get_post_meta($post->ID, '_tap_room_gallery', true);
        $ids = $gallery ? explode(',', $gallery) : [];
        ?>
        <div>
            <div id="tap-room-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <?php foreach ($ids as $id): $img = wp_get_attachment_image_src($id, 'thumbnail'); if ($img): ?>
                    <div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;">
                        <img src="<?php echo esc_url($img[0]); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <button type="button" class="tap-room-gallery-remove" data-id="<?php echo esc_attr($id); ?>" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button>
                    </div>
                <?php endif; endforeach; ?>
            </div>
            <input type="hidden" name="_tap_room_gallery" id="_tap_room_gallery" value="<?php echo esc_attr($gallery); ?>">
            <button type="button" class="button" id="tap-room-gallery-add"><?php esc_html_e('Add Photos', 'travel-agency-platform'); ?></button>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            $('#tap-room-gallery-add').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: '<?php echo esc_js(__('Select Photos', 'travel-agency-platform')); ?>', button: { text: '<?php echo esc_js(__('Add to Gallery', 'travel-agency-platform')); ?>' }, multiple: true });
                frame.on('select', function() {
                    var ids = $('#_tap_room_gallery').val() ? $('#_tap_room_gallery').val().split(',') : [];
                    var attachments = frame.state().get('selection').toJSON();
                    attachments.forEach(function(att) {
                        if (ids.indexOf(String(att.id)) === -1) ids.push(String(att.id));
                        var preview = $('#tap-room-gallery-preview');
                        if (!preview.find('[data-id="' + att.id + '"]').length) {
                            preview.append('<div style="position:relative;width:70px;height:70px;border-radius:6px;overflow:hidden;border:1px solid #ddd;"><img src="' + (att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;"><button type="button" class="tap-room-gallery-remove" data-id="' + att.id + '" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,0.6);color:#fff;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button></div>');
                        }
                    });
                    $('#_tap_room_gallery').val(ids.join(','));
                });
                frame.open();
            });
            $(document).on('click', '.tap-room-gallery-remove', function() {
                var id = $(this).data('id');
                $(this).parent().remove();
                var ids = $('#_tap_room_gallery').val().split(',').filter(function(v) { return v !== String(id); });
                $('#_tap_room_gallery').val(ids.join(','));
            });
        });
        </script>
        <?php
    }

    public static function render_booking($post) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE id = %d",
            $post->ID
        ));

        if (!$booking) {
            echo '<p>' . __('Booking details not found.', 'travel-agency-platform') . '</p>';
            return;
        }

        $service = get_post($booking->service_id);
        $agency = get_post($booking->agency_id);
        $client = get_userdata($booking->client_id);

        ?>
        <style>
            .tap-booking-details { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            .tap-booking-details .field { margin-bottom: 10px; }
            .tap-booking-details .label { font-weight: bold; display: block; color: #555; font-size: 12px; text-transform: uppercase; }
            .tap-booking-details .value { font-size: 14px; margin-top: 2px; }
            .tap-status { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
            .tap-status-pending { background: #fff3cd; color: #856404; }
            .tap-status-confirmed { background: #d4edda; color: #155724; }
            .tap-status-cancelled { background: #f8d7da; color: #721c24; }
            .tap-status-completed { background: #cce5ff; color: #004085; }
        </style>
        <div class="tap-booking-details">
            <div class="field"><span class="label"><?php esc_html_e('Booking Code', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->booking_code); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Status', 'travel-agency-platform'); ?></span><span class="value"><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Client', 'travel-agency-platform'); ?></span><span class="value"><?php echo $client ? esc_html($client->display_name) . ' (' . esc_html($client->user_email) . ')' : 'N/A'; ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Agency', 'travel-agency-platform'); ?></span><span class="value"><?php echo $agency ? esc_html($agency->post_title) : 'N/A'; ?></span></div>
            <?php if ($service): ?>
            <div class="field"><span class="label"><?php esc_html_e('Service', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($service->post_title) . ' (' . esc_html(str_replace('tap_', '', $booking->service_type)) . ')'; ?></span></div>
            <?php endif; ?>
            <?php if ($booking->check_in): ?>
            <div class="field"><span class="label"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->check_in); ?></span></div>
            <?php endif; ?>
            <?php if ($booking->check_out): ?>
            <div class="field"><span class="label"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->check_out); ?></span></div>
            <?php endif; ?>
            <div class="field"><span class="label"><?php esc_html_e('Adults', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->adults); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Children', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->children); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Total Amount', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Commission', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(TAP_Currency::fmt($booking->commission_amount)); ?> (<?php echo esc_html($booking->commission_percent); ?>%)</span></div>
            <div class="field"><span class="label"><?php esc_html_e('Payment Status', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html(ucfirst($booking->payment_status)); ?></span></div>
            <div class="field"><span class="label"><?php esc_html_e('Payment Method', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->payment_method ?: 'N/A'); ?></span></div>
            <?php if ($booking->notes): ?>
            <div class="field" style="grid-column: 1 / -1;"><span class="label"><?php esc_html_e('Notes', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->notes); ?></span></div>
            <?php endif; ?>
            <div class="field" style="grid-column: 1 / -1;"><span class="label"><?php esc_html_e('Created', 'travel-agency-platform'); ?></span><span class="value"><?php echo esc_html($booking->created_at); ?></span></div>
        </div>
        <?php
    }

    public static function get_meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value !== '' ? $value : $default;
    }
}
